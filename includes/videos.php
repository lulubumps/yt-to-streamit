<?php
/**
 * Decisión por video
 *
 * Decide qué hacer con cada video de una playlist: importarlo, omitirlo porque YA EXISTE en Streamit
 * (aunque se haya cargado a mano y con otro título), o respetar lo que el usuario eligió.
 * Es la única fuente de esta lógica: la usan la sincronización, la verificación y la pantalla «Videos de la playlist».
 *
 * @package YT_To_Streamit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Decisiones guardadas del usuario por playlist: [ playlist_id => [ video_id => 1 (importar) | 0 (no importar) ] ] */
function yts_get_choices( $playlist ) {
	$all = get_option( 'yts_choices', [] );
	return ( is_array( $all ) && isset( $all[ $playlist ] ) && is_array( $all[ $playlist ] ) ) ? $all[ $playlist ] : [];
}

function yts_set_choices( $playlist, array $choices ) {
	$all = get_option( 'yts_choices', [] );
	if ( ! is_array( $all ) ) $all = [];
	$all[ $playlist ] = $choices;
	update_option( 'yts_choices', $all, false );
}

/** 1 = importar, 0 = no importar, null = sin decisión del usuario. */
function yts_video_choice( $p, $video_id ) {
	$c = yts_get_choices( isset( $p['playlist'] ) ? $p['playlist'] : '' );
	return isset( $c[ $video_id ] ) ? (int) $c[ $video_id ] : null;
}

/** Modo de videos del programa: `auto` (todos los nuevos) o `manual` (solo los que el usuario elija). */
function yts_program_mode( $p ) {
	return ( isset( $p['mode'] ) && 'manual' === $p['mode'] ) ? 'manual' : 'auto';
}

/**
 * Mapa de videos de YouTube que YA están cargados en algún episodio de Streamit: [ video_id => [ id, title ] ].
 * Se arma leyendo el enlace o el código embed de cada episodio, así que funciona aunque el título sea distinto al de YouTube.
 * Una sola consulta por petición (con caché); `$reset = true` la rehace.
 */
function yts_existing_video_map( $reset = false ) {
	static $map = null;
	if ( $reset ) $map = null;
	if ( null !== $map ) return $map;

	global $wpdb;
	$map = [];
	if ( empty( $wpdb->streamit_episodemeta ) || empty( $wpdb->streamit_episode ) ) return $map;

	$rows = $wpdb->get_results(
		"SELECT m.streamit_episode_id AS ep_id, e.post_title, m.meta_value
		   FROM {$wpdb->streamit_episodemeta} m
		   JOIN {$wpdb->streamit_episode} e ON e.ID = m.streamit_episode_id
		  WHERE m.meta_key IN ( '_episode_url_link', '_episode_embed_content' )
		  ORDER BY e.ID ASC"
	);
	foreach ( (array) $rows as $r ) {
		// watch?v=ID · youtu.be/ID · /embed/ID · /shorts/ID · /live/ID
		if ( preg_match_all( '~(?:[?&]v=|youtu\.be/|/embed/|/shorts/|/live/|/v/)([A-Za-z0-9_-]{11})~', (string) $r->meta_value, $m ) ) {
			foreach ( $m[1] as $vid ) {
				if ( ! isset( $map[ $vid ] ) ) $map[ $vid ] = [ 'id' => (int) $r->ep_id, 'title' => (string) $r->post_title ];
			}
		}
	}
	return $map;
}

/** Episodio de Streamit que ya tiene cargado este video de YouTube, o null. */
function yts_existing_episode( $video_id ) {
	$m = yts_existing_video_map();
	return isset( $m[ $video_id ] ) ? $m[ $video_id ] : null;
}

/**
 * Qué hacer con un video. Devuelve [ tipo, nota, episodio_existente|null ]. Tipos:
 *   exists → ya está en Streamit (importado por el plugin o cargado antes a mano): se omite siempre
 *   skip   → el usuario lo marcó «no importar», o el programa es manual y no lo eligió
 *   old    → modo automático y publicado antes de «Importar videos desde»
 *   import → se importará
 * Una selección explícita del usuario gana sobre la fecha de inicio.
 * $e = [ 'id', 'title', 'published' ] (como lo devuelve yts_fetch_videos).
 */
function yts_classify_video( $p, $s, $e, $since = null ) {
	if ( null === $since ) $since = strtotime( $s['import_since'] );
	$id = $e['id'];

	if ( yts_exists( $id ) ) return [ 'exists', __( 'already imported.', 'yt-to-streamit' ), null ];

	$ep = yts_existing_episode( $id );
	if ( $ep ) {
		/* translators: 1: title of the existing episode, 2: its ID */
		return [ 'exists', sprintf( __( 'it already exists in Streamit as “%1$s” (#%2$d).', 'yt-to-streamit' ), $ep['title'], $ep['id'] ), $ep ];
	}

	$choice = yts_video_choice( $p, $id );
	if ( null !== $choice ) {
		return $choice
			? [ 'import', __( 'selected: it will be imported.', 'yt-to-streamit' ), null ]
			: [ 'skip', __( 'marked as “do not import”.', 'yt-to-streamit' ), null ];
	}

	if ( 'manual' === yts_program_mode( $p ) ) return [ 'skip', __( 'manual mode: you did not select it.', 'yt-to-streamit' ), null ];

	if ( $e['published'] < $since ) {
		/* translators: %s: start date (YYYY-MM-DD) */
		return [ 'old', sprintf( __( 'the video was published on YouTube before the “start date” (%s).', 'yt-to-streamit' ), substr( $s['import_since'], 0, 10 ) ), null ];
	}
	return [ 'import', __( 'it will be imported.', 'yt-to-streamit' ), null ];
}

/** Limpia una lista de IDs de video recibida de un formulario: solo IDs válidos, sin repetidos. */
function yts_clean_video_ids( $list ) {
	$out = [];
	foreach ( (array) $list as $id ) {
		$id = sanitize_text_field( wp_unslash( $id ) );
		if ( yts_valid_video_id( $id ) ) $out[ $id ] = $id;
	}
	return array_values( $out );
}

/**
 * Opciones por video que el usuario eligió en «Videos de la playlist»: la temporada de destino y un título propio.
 * Se guardan en la opción `yts_video_opts`: [ playlist_id => [ video_id => [ 'season' => índice|'', 'title' => texto ] ] ].
 * Solo se guarda lo que se aparta del valor por defecto (si no hay nada especial, no hay entrada).
 */
function yts_get_video_opts( $playlist ) {
	$all = get_option( 'yts_video_opts', [] );
	return ( is_array( $all ) && isset( $all[ $playlist ] ) && is_array( $all[ $playlist ] ) ) ? $all[ $playlist ] : [];
}

function yts_set_video_opts( $playlist, array $opts ) {
	$all = get_option( 'yts_video_opts', [] );
	if ( ! is_array( $all ) ) $all = [];
	if ( $opts ) $all[ $playlist ] = $opts; else unset( $all[ $playlist ] );
	update_option( 'yts_video_opts', $all, false );
}

/** Opciones de un video listas para usar: [ 'season' => int|null (null = la del programa), 'title' => texto propio o '' ]. */
function yts_video_opts( $p, $video_id ) {
	$o = yts_get_video_opts( isset( $p['playlist'] ) ? $p['playlist'] : '' );
	$e = ( isset( $o[ $video_id ] ) && is_array( $o[ $video_id ] ) ) ? $o[ $video_id ] : [];
	return [
		'season' => ( isset( $e['season'] ) && '' !== $e['season'] ) ? (int) $e['season'] : null,
		'title'  => isset( $e['title'] ) ? (string) $e['title'] : '',
	];
}
