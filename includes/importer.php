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

/**
 * Las filas del registro de una sincronización son [ programa, video_id, título, fecha, texto, código ].
 * El texto está traducido; el código (created, pending, skipped, dry, error, info) no, y es lo que se usa para contar.
 */
function yts_count_code( $log, $code ) {
	$n = 0;
	foreach ( $log as $r ) if ( isset( $r[5] ) && $code === $r[5] ) $n++;
	return $n;
}

/** Cuántos episodios se crearon según el registro de una sincronización. */
function yts_count_created( $log ) {
	return yts_count_code( $log, 'created' );
}

/** Cuántos videos quedaron pendientes por el límite de la pasada. */
function yts_count_pending( $log ) {
	return yts_count_code( $log, 'pending' );
}

/** Texto resumen: «3 episodio(s) creado(s), 2 pendiente(s) (…)». */
function yts_summary( $log ) {
	$created = yts_count_created( $log );
	$pending = yts_count_pending( $log );
	if ( $pending ) {
		/* translators: 1: number of episodes created, 2: number of videos left pending */
		return sprintf( __( '%1$d episode(s) created, %2$d pending: they will be imported automatically in a few minutes (you can also sync again)', 'yt-to-streamit' ), $created, $pending );
	}
	/* translators: %d: number of episodes created */
	return sprintf( __( '%d episode(s) created', 'yt-to-streamit' ), $created );
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
		$log[] = [ '—', '', '', '', __( 'A sync is already running. Try again in a few minutes.', 'yt-to-streamit' ), 'info' ];
		update_option( 'yts_last_log', [ 'time' => current_time( 'mysql' ), 'dry' => false, 'rows' => $log ], false );
		return $log;
	}

	try {
		if ( function_exists( 'set_time_limit' ) ) @set_time_limit( 300 );

		if ( ! yts_ready() ) {
			/* translators: %s: error message */
			$log[] = [ '—', '', '', '', sprintf( __( 'ERROR: %s', 'yt-to-streamit' ), __( 'the Streamit plugin is not active or does not have the expected functions.', 'yt-to-streamit' ) ), 'error' ];
		} else {
			$s = yts_settings();
			if ( ! $s['programs'] ) {
				$log[] = [ '—', '', '', '', __( 'There are no programs configured. Add one and save.', 'yt-to-streamit' ), 'info' ];
			}
			foreach ( $s['programs'] as $i => $p ) {
				if ( null !== $only && (int) $only !== (int) $i ) continue;
				if ( empty( $p['playlist'] ) || empty( $p['show'] ) ) continue;
				$label = ! empty( $p['label'] ) ? $p['label'] : $p['playlist'];
				// Desactivado: se omite en la sincronización automática y en "Sincronizar ahora" (solo corre si lo pides a mano)
				if ( null === $only && isset( $p['active'] ) && ! $p['active'] ) {
					$log[] = [ $label, '', '', '', __( 'Program disabled: it is not synced.', 'yt-to-streamit' ), 'info' ];
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
		if ( false !== stripos( $feed['error'], 'quota' ) ) $hint .= ' ' . __( 'The daily YouTube API quota is exhausted (it resets at midnight, US Pacific time).', 'yt-to-streamit' );
		if ( false !== strpos( $feed['error'], 'HTTP 404' ) ) $hint .= ' ' . __( 'The YouTube RSS feed fails intermittently; it will be retried on the next run.', 'yt-to-streamit' );
		/* translators: %s: error detail */
		$rows[] = [ $label, '', '', '', sprintf( __( 'ERROR reading the playlist (%s).', 'yt-to-streamit' ), $feed['error'] ) . $hint, 'error' ];
		return $rows;
	}
	if ( ! $feed['entries'] ) {
		/* translators: %s: source used to read the playlist (API or RSS) */
		$rows[] = [ $label, '', '', '', sprintf( __( 'The playlist has no videos (%s).', 'yt-to-streamit' ), $feed['source'] ), 'info' ];
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

		// Qué hacer con el video: importarlo, o por qué se omite (ya existe en Streamit, no seleccionado, fecha de inicio…)
		list( $kind, $note ) = yts_classify_video( $p, $s, $e, $since );
		if ( 'import' !== $kind ) {
			/* translators: %s: reason why the video is skipped */
			$rows[] = array_merge( $row, [ sprintf( __( 'Skipped: %s', 'yt-to-streamit' ), $note ), 'skipped' ] );
			continue;
		}
		if ( $dry ) {
			$rows[] = array_merge( $row, [ __( 'Would be imported.', 'yt-to-streamit' ), 'dry' ] );
			continue;
		}

		if ( $created >= $max ) {
			/* translators: %d: maximum number of imports per run */
			$rows[] = array_merge( $row, [ sprintf( __( 'Pending: the limit of %d imports per run was reached; it will be imported on the next one.', 'yt-to-streamit' ), $max ), 'pending' ] );
			continue;
		}

		$r = yts_import_video( $p, $s, compact( 'video_id', 'title', 'published', 'desc' ) );
		if ( ! is_wp_error( $r ) ) $created++;
		if ( is_wp_error( $r ) ) {
			/* translators: %s: error message */
			$rows[] = array_merge( $row, [ sprintf( __( 'ERROR: %s', 'yt-to-streamit' ), $r->get_error_message() ), 'error' ] );
		} else {
			/* translators: %d: ID of the episode that was created */
			$rows[] = array_merge( $row, [ sprintf( __( 'Created (episode #%d).', 'yt-to-streamit' ), $r ), 'created' ] );
		}
	}
	return $rows;
}

function yts_import_video( $p, $s, $v ) {
	$show_id = (int) $p['show'];

	// --- Temporada destino (si no hay ninguna, crea "Temporada 1") ---
	$seasons = yts_seasons( $show_id );
	if ( ! $seasons ) {
		$seasons[] = [ 'name' => __( 'Season 1', 'yt-to-streamit' ), 'season_year' => gmdate( 'Y' ), 'season_description' => '', 'episodes' => [] ];
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
		$why = is_wp_error( $episode_id ) ? $episode_id->get_error_message() : __( 'Streamit did not return an ID', 'yt-to-streamit' );
		error_log( '[yts] Could not create the episode: ' . $why );
		/* translators: %s: reason */
		return new WP_Error( 'yts_create', sprintf( __( 'Could not create the episode: %s', 'yt-to-streamit' ), $why ) );
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
	if ( ! yts_ready() ) return [ 'total' => 0, 'msg' => __( 'Streamit is not active.', 'yt-to-streamit' ) ];
	$s     = yts_settings();
	$total = 0;
	$lines = [];

	foreach ( $s['programs'] as $p ) {
		$show_id = (int) $p['show'];
		$name    = ! empty( $p['label'] ) ? $p['label'] : "#$show_id";
		$missing = yts_unlinked_ids( $show_id );
		if ( ! $missing ) {
			/* translators: %s: program name */
			$lines[] = sprintf( __( '“%s”: nothing to repair.', 'yt-to-streamit' ), $name );
			continue;
		}

		$seasons = yts_seasons( $show_id );
		if ( ! $seasons ) {
			$seasons[] = [ 'name' => __( 'Season 1', 'yt-to-streamit' ), 'season_year' => gmdate( 'Y' ), 'season_description' => '', 'episodes' => [] ];
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
		/* translators: 1: program name, 2: number of episodes, 3: season number */
		$lines[]  = sprintf( __( '“%1$s”: %2$d episode(s) linked to season %3$d.', 'yt-to-streamit' ), $name, count( $missing ), $idx + 1 );
	}
	/* translators: %s: summary per program */
	return [ 'total' => $total, 'msg' => sprintf( __( 'Repair finished. %s', 'yt-to-streamit' ), implode( ' ', $lines ) ) ];
}
