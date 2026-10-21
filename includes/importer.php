<?php
/**
 * Importador
 *
 * Corazón del plugin: recorre los programas, crea los episodios en Streamit, los enlaza a su temporada y repara enlaces perdidos.
 *
 * @package YT_To_Streamit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Bloqueo simple para que no corran dos sincronizaciones a la vez (cron + botón).
 * Si un bloqueo tiene más de 10 minutos se asume que una corrida se cortó y se retoma.
 */
function yts_lock_acquire() {
	if ( add_option( 'yts_lock', time(), '', 'no' ) ) return true;
	$since = (int) get_option( 'yts_lock' );
	if ( $since && ( time() - $since ) > 600 ) {
		update_option( 'yts_lock', time(), false );
		return true;
	}
	return false;
}

function yts_lock_release() {
	delete_option( 'yts_lock' );
}

/** Cuántos episodios se crearon según el registro de una sincronización. */
function yts_count_created( $log ) {
	$n = 0;
	foreach ( $log as $r ) if ( isset( $r[4] ) && 0 === strpos( $r[4], 'Creado' ) ) $n++;
	return $n;
}

/** Cuántos videos quedaron pendientes por el límite de la pasada. */
function yts_count_pending( $log ) {
	$n = 0;
	foreach ( $log as $r ) if ( isset( $r[4] ) && 0 === strpos( $r[4], 'Pendiente' ) ) $n++;
	return $n;
}

/** Texto resumen: «3 episodio(s) creado(s), 2 pendiente(s) (…)». */
function yts_summary( $log ) {
	$txt = yts_count_created( $log ) . ' episodio(s) creado(s)';
	$p   = yts_count_pending( $log );
	if ( $p ) $txt .= ", $p pendiente(s): se importarán solos en unos minutos (también puedes volver a sincronizar)";
	return $txt;
}

/** Continuación automática tras una pasada con videos pendientes (evento único de WP-Cron). */
function yts_run_followup( $only = null ) {
	yts_run( false, $only );
}

/**
 * Sincroniza los programas activos (o solo el índice $only, aunque esté desactivado).
 * Con $dry = true no crea nada: solo informa qué haría.
 * Devuelve las filas del registro y las guarda en la opción `yts_last_log`.
 */
function yts_run( $dry = false, $only = null ) {
	$log = [];

	if ( ! $dry && ! yts_lock_acquire() ) {
		$log[] = [ '—', '', '', '', 'Ya hay una sincronización en curso. Intenta de nuevo en unos minutos.' ];
		update_option( 'yts_last_log', [ 'time' => current_time( 'mysql' ), 'dry' => false, 'rows' => $log ], false );
		return $log;
	}

	try {
		if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 300 );

		if ( ! yts_ready() ) {
			$log[] = [ '—', '', '', '', 'ERROR: el plugin Streamit no está activo o no tiene las funciones esperadas.' ];
		} else {
			$s = yts_settings();
			if ( ! $s['programs'] ) {
				$log[] = [ '—', '', '', '', 'No hay programas configurados. Agrega uno y guarda.' ];
			}
			foreach ( $s['programs'] as $i => $p ) {
				if ( null !== $only && (int) $only !== (int) $i ) continue;
				if ( empty( $p['playlist'] ) || empty( $p['show'] ) ) continue;
				$label = ! empty( $p['label'] ) ? $p['label'] : $p['playlist'];
				// Desactivado: se omite en la sincronización automática y en "Sincronizar ahora" (solo corre si lo pides a mano)
				if ( null === $only && isset( $p['active'] ) && ! $p['active'] ) {
					$log[] = [ $label, '', '', '', 'Programa desactivado: no se sincroniza.' ];
					continue;
				}
				$log = array_merge( $log, yts_sync_program( $p, $s, $dry ) );
				if ( ! $dry ) yts_check_milestone( $p, $s );
			}
		}
	} finally {
		if ( ! $dry ) yts_lock_release();
	}

	// Si quedaron videos pendientes por el límite de la pasada, se programa una continuación en 2 minutos
	// (así no hay que esperar a la próxima sincronización, que puede ser en horas).
	if ( ! $dry && yts_count_pending( $log ) > 0 && ! wp_next_scheduled( 'yts_sync_followup', [ $only ] ) ) {
		wp_schedule_single_event( time() + 120, 'yts_sync_followup', [ $only ] );
	}

	update_option( 'yts_last_log', [ 'time' => current_time( 'mysql' ), 'dry' => (bool) $dry, 'rows' => array_slice( $log, 0, 300 ) ], false );
	return $log;
}

function yts_sync_program( $p, $s, $dry = false ) {
	$rows  = [];
	$label = ! empty( $p['label'] ) ? $p['label'] : $p['playlist'];

	$feed = yts_fetch_videos( $p['playlist'], $s );
	if ( $feed['error'] ) {
		$hint = '';
		if ( false !== stripos( $feed['error'], 'quota' ) ) $hint .= ' La cuota diaria de la API de YouTube está agotada (se reinicia a medianoche, hora del Pacífico de EE. UU.).';
		if ( false !== strpos( $feed['error'], 'HTTP 404' ) ) $hint .= ' El feed RSS de YouTube falla de forma intermitente; se reintenta solo en la próxima pasada.';
		$rows[] = [ $label, '', '', '', 'ERROR al leer la playlist (' . $feed['error'] . ').' . $hint ];
		return $rows;
	}
	if ( ! $feed['entries'] ) {
		$rows[] = [ $label, '', '', '', 'La playlist no trae videos (' . $feed['source'] . ').' ];
		return $rows;
	}

	$since   = strtotime( $s['import_since'] );
	$entries = array_reverse( $feed['entries'] ); // del más antiguo al más nuevo
	$created = 0;
	$max     = max( 1, (int) apply_filters( 'yts_max_imports_per_run', (int) $s['max_per_run'] ) ); // límite por pasada (evita agotar el tiempo del servidor)

	foreach ( $entries as $e ) {
		$video_id  = $e['id'];
		$title     = $e['title'];
		$published = $e['published'];
		$desc      = $e['desc'];
		if ( ! $video_id ) continue;

		$row = [ $label, $video_id, $title, get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $published ) ) ];

		if ( yts_exists( $video_id ) ) {
			$rows[] = array_merge( $row, [ 'Omitido: ya estaba importado.' ] );
			continue;
		}
		if ( $published < $since ) {
			$rows[] = array_merge( $row, [ 'Omitido: el video se publicó en YouTube antes de la "fecha de inicio" (' . substr( $s['import_since'], 0, 10 ) . ').' ] );
			continue;
		}
		if ( $dry ) {
			$rows[] = array_merge( $row, [ 'Se importaría.' ] );
			continue;
		}

		if ( $created >= $max ) {
			$rows[] = array_merge( $row, [ "Pendiente: se alcanzó el límite de $max importaciones por pasada; se importará en la próxima." ] );
			continue;
		}

		$r = yts_import_video( $p, $s, compact( 'video_id', 'title', 'published', 'desc' ) );
		if ( ! is_wp_error( $r ) ) $created++;
		$rows[] = array_merge( $row, [ is_wp_error( $r ) ? 'ERROR: ' . $r->get_error_message() : "Creado (episodio #$r)." ] );
	}
	return $rows;
}

function yts_import_video( $p, $s, $v ) {
	$show_id = (int) $p['show'];

	// --- Temporada destino (si no hay ninguna, crea "Temporada 1") ---
	$seasons = yts_seasons( $show_id );
	if ( ! $seasons ) {
		$seasons[] = [ 'name' => 'Temporada 1', 'season_year' => gmdate( 'Y' ), 'season_description' => '', 'episodes' => [] ];
	}
	$idx = ( isset( $p['season'] ) && '' !== $p['season'] && isset( $seasons[ (int) $p['season'] ] ) )
		? (int) $p['season']
		: count( $seasons ) - 1; // por defecto, la última
	$season_eps = ! empty( $seasons[ $idx ]['episodes'] ) ? (array) $seasons[ $idx ]['episodes'] : [];

	// --- Crear el episodio con la función oficial de Streamit ---
	$episode_id = streamit_add_episode( [
		'post_author'       => yts_author( $s ),
		'post_title'        => wp_strip_all_tags( $v['title'] ),
		'post_content'      => wp_kses_post( nl2br( esc_html( $v['desc'] ) ) ),
		'post_status'       => $s['post_status'],
		'post_name'         => sanitize_title( $v['title'] ) . '-' . strtolower( $v['video_id'] ), // único
		'post_date'         => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $v['published'] ) ),
		'post_date_gmt'     => gmdate( 'Y-m-d H:i:s', $v['published'] ),
	] );

	if ( is_wp_error( $episode_id ) || ! $episode_id ) {
		$why = is_wp_error( $episode_id ) ? $episode_id->get_error_message() : 'Streamit no devolvió un ID';
		error_log( '[yts] No se pudo crear el episodio: ' . $why );
		return new WP_Error( 'yts_create', 'No se pudo crear el episodio: ' . $why );
	}

	// --- Meta: primero lo copiado del episodio modelo, luego lo propio ---
	$meta = [];
	if ( $s['template_episode'] ) {
		$skip = [ 'thumbnail_id', '_episode_number', '_episode_url_link', '_episode_embed_content', '_episode_attachment_id',
			'_episode_release_date', 'tvshow_id', 'post_views_count', '_yts_video_id' ];
		foreach ( (array) get_metadata( 'streamit_episode', (int) $s['template_episode'] ) as $k => $vals ) {
			if ( in_array( $k, $skip, true ) ) continue;
			$meta[ $k ] = maybe_unserialize( is_array( $vals ) ? $vals[0] : $vals );
		}
	}

	$watch = 'https://www.youtube.com/watch?v=' . $v['video_id'];
	$meta['_episode_choice']       = $s['video_method'];
	$meta['_episode_release_date'] = gmdate( 'Y-m-d', $v['published'] );
	$meta['_episode_number']       = count( $season_eps ) + 1;
	$meta['tvshow_id']             = $show_id;
	$meta['_yts_video_id']         = $v['video_id'];
	$meta['thumbnail_id']          = yts_sideload( $v['video_id'], $v['title'], $s );

	if ( 'episode_url' === $s['video_method'] ) {
		$meta['_episode_url_link'] = $watch;
	} else {
		$meta['_episode_embed_content'] = '<iframe width="100%" height="100%" src="https://www.youtube.com/embed/' . esc_attr( $v['video_id'] ) . '" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
	}

	foreach ( $meta as $k => $val ) {
		streamit_add_episode_meta( $episode_id, $k, $val );
	}
	if ( class_exists( 'Streamit_Episode' ) ) {
		( new Streamit_Episode( $episode_id ) )->invalidate_meta_cache();
	}

	// --- Enlazar el episodio a la temporada del programa ---
	$season_eps[]                = $episode_id;
	$seasons[ $idx ]['episodes'] = $season_eps;
	// update_metadata crea la fila si no existe y, si Streamit ya dejó una fila `_seasons` vacía al crear el programa, la actualiza
	// (con add_metadata se agregaba una segunda fila y Streamit seguía leyendo la vacía).
	streamit_update_tvshow_meta( $show_id, '_seasons', $seasons );
	yts_flush_show_cache( $show_id );

	return (int) $episode_id;
}

function yts_repair() {
	if ( ! yts_ready() ) return [ 'total' => 0, 'msg' => 'Streamit no está activo.' ];
	$s     = yts_settings();
	$total = 0;
	$lines = [];

	foreach ( $s['programs'] as $p ) {
		$show_id = (int) $p['show'];
		$name    = ! empty( $p['label'] ) ? $p['label'] : "#$show_id";
		$missing = yts_unlinked_ids( $show_id );
		if ( ! $missing ) { $lines[] = "«{$name}»: nada que reparar."; continue; }

		$seasons = yts_seasons( $show_id );
		if ( ! $seasons ) {
			$seasons[] = [ 'name' => 'Temporada 1', 'season_year' => gmdate( 'Y' ), 'season_description' => '', 'episodes' => [] ];
		}
		$idx = ( isset( $p['season'] ) && '' !== $p['season'] && isset( $seasons[ (int) $p['season'] ] ) ) ? (int) $p['season'] : count( $seasons ) - 1;
		$eps = ! empty( $seasons[ $idx ]['episodes'] ) ? array_map( 'intval', (array) $seasons[ $idx ]['episodes'] ) : [];

		foreach ( $missing as $eid ) {
			$eps[] = $eid;
			streamit_update_episode_meta( $eid, '_episode_number', count( $eps ) );
			streamit_update_episode_meta( $eid, 'tvshow_id', $show_id );
		}
		$seasons[ $idx ]['episodes'] = $eps;
		streamit_update_tvshow_meta( $show_id, '_seasons', $seasons );
		yts_flush_show_cache( $show_id );

		$total   += count( $missing );
		$lines[]  = "«{$name}»: " . count( $missing ) . ' episodio(s) enlazado(s) a la temporada ' . ( $idx + 1 ) . '.';
	}
	return [ 'total' => $total, 'msg' => 'Reparación lista. ' . implode( ' ', $lines ) ];
}
