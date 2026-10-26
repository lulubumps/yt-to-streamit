<?php
/**
 * Duración de los videos
 *
 * Lee la duración desde la API de YouTube (el feed RSS no la trae) y la guarda en Streamit como el campo
 * «Episode Time Duration»: un texto «horas:minutos» (por ejemplo 1:05 o 0:45), sin segundos.
 * También trae la herramienta que completa la duración de episodios que ya existen.
 *
 * @package YT_To_Streamit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Convierte una duración ISO 8601 de YouTube (PT1H5M32S, P1DT2H, P0D…) en segundos. Devuelve null si no se entiende. */
function yts_parse_iso_duration( $iso ) {
	if ( ! preg_match( '/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/', (string) $iso, $m ) ) return null;
	return (int) ( isset( $m[1] ) ? $m[1] : 0 ) * 86400 + (int) ( isset( $m[2] ) ? $m[2] : 0 ) * 3600 + (int) ( isset( $m[3] ) ? $m[3] : 0 ) * 60 + (int) ( isset( $m[4] ) ? $m[4] : 0 );
}

/** Segundos → «horas:minutos» como lo guarda Streamit (redondea al minuto más cercano; mínimo 1 minuto). Vacío si no hay duración. */
function yts_format_runtime( $seconds ) {
	$seconds = (int) $seconds;
	if ( $seconds <= 0 ) return '';
	$minutes = max( 1, (int) round( $seconds / 60 ) );
	return sprintf( '%d:%02d', intdiv( $minutes, 60 ), $minutes % 60 );
}

/** Segundos → «h:mm:ss» o «m:ss», para mostrar en pantalla. */
function yts_format_clock( $seconds ) {
	$seconds = (int) $seconds;
	if ( $seconds <= 0 ) return '—';
	$h = intdiv( $seconds, 3600 );
	$m = intdiv( $seconds % 3600, 60 );
	$s = $seconds % 60;
	return $h ? sprintf( '%d:%02d:%02d', $h, $m, $s ) : sprintf( '%d:%02d', $m, $s );
}

/**
 * Duración (en segundos) de varios videos con la API de YouTube: una consulta por cada 50 videos.
 * Devuelve [ video_id => segundos ]. Los videos sin duración útil (transmisiones en vivo, estrenos) no aparecen.
 * Si una consulta falla se sigue con lo que haya: la duración es un dato extra y nunca debe impedir importar.
 */
function yts_fetch_durations( array $ids, $key ) {
	$out = [];
	$ids = array_values( array_unique( array_filter( $ids, 'yts_valid_video_id' ) ) );
	foreach ( array_chunk( $ids, 50 ) as $chunk ) {
		$res = wp_remote_get( add_query_arg( [ 'part' => 'contentDetails', 'id' => implode( ',', $chunk ), 'key' => $key ], 'https://www.googleapis.com/youtube/v3/videos' ), [ 'timeout' => 20 ] );
		if ( is_wp_error( $res ) || 200 !== wp_remote_retrieve_response_code( $res ) ) {
			error_log( '[yts] Could not read the video durations from the YouTube API' );
			continue;
		}
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		foreach ( isset( $body['items'] ) ? $body['items'] : [] as $it ) {
			$sec = yts_parse_iso_duration( isset( $it['contentDetails']['duration'] ) ? $it['contentDetails']['duration'] : '' );
			if ( $sec && isset( $it['id'] ) ) $out[ $it['id'] ] = $sec;
		}
	}
	return $out;
}

/** ¿El campo de duración de Streamit ya tiene algo útil? («», «0», «0:00» cuentan como vacío) */
function yts_runtime_filled( $value ) {
	return (bool) preg_match( '/[1-9]/', (string) $value );
}

/**
 * Completa la duración de los episodios que la tienen vacía.
 * Siempre incluye los episodios que importó este plugin; con $include_old también los que se cargaron antes
 * (se reconocen por su video de YouTube, igual que al detectar duplicados). Nunca pisa una duración que ya exista.
 * Máximo 500 por pasada. Devuelve [ 'filled' => n, 'msg' => texto ].
 */
function yts_fill_durations( $include_old = false ) {
	global $wpdb;
	$key = yts_api_key();
	if ( ! $key ) return [ 'filled' => 0, 'msg' => __( 'A YouTube API key is required to read the durations (Settings).', 'yt-to-streamit' ) ];
	if ( ! yts_ready() ) return [ 'filled' => 0, 'msg' => __( 'Streamit is not active.', 'yt-to-streamit' ) ];

	// 1) episodio → video
	$map  = [];
	$rows = $wpdb->get_results( "SELECT streamit_episode_id AS episode_id, meta_value AS vid FROM {$wpdb->streamit_episodemeta} WHERE meta_key = '_yts_video_id' AND meta_value NOT IN ( '__test__', '__prueba__' )" );
	foreach ( (array) $rows as $r ) {
		if ( yts_valid_video_id( $r->vid ) ) $map[ (int) $r->episode_id ] = $r->vid;
	}
	if ( $include_old ) {
		foreach ( yts_existing_video_map( true ) as $vid => $ep ) {
			if ( ! isset( $map[ (int) $ep['id'] ] ) ) $map[ (int) $ep['id'] ] = $vid;
		}
	}

	// 2) cuáles ya tienen duración
	$have = [];
	foreach ( array_chunk( array_keys( $map ), 500 ) as $chunk ) {
		$rows = $wpdb->get_results( "SELECT streamit_episode_id AS episode_id, meta_value FROM {$wpdb->streamit_episodemeta} WHERE meta_key = '_episode_run_time' AND streamit_episode_id IN ( " . implode( ',', array_map( 'intval', $chunk ) ) . ' )' );
		foreach ( (array) $rows as $r ) {
			if ( yts_runtime_filled( $r->meta_value ) ) $have[ (int) $r->episode_id ] = true;
		}
	}
	$todo = array_diff_key( $map, $have );
	if ( ! $todo ) return [ 'filled' => 0, 'msg' => __( 'Durations: there is nothing to fill.', 'yt-to-streamit' ) ];

	$left = max( 0, count( $todo ) - 500 );
	$todo = array_slice( $todo, 0, 500, true );

	// 3) leer y guardar
	$dur    = yts_fetch_durations( array_values( $todo ), $key );
	$filled = 0;
	$nodur  = 0;
	foreach ( $todo as $ep => $vid ) {
		if ( empty( $dur[ $vid ] ) ) { $nodur++; continue; }
		streamit_update_episode_meta( $ep, '_episode_run_time', yts_format_runtime( $dur[ $vid ] ) );
		yts_flush_episode_cache( $ep );
		$filled++;
	}

	/* translators: 1: episodes updated, 2: episodes whose video has no duration available, 3: episodes that already had a duration */
	$msg = sprintf( __( 'Durations: %1$d filled, %2$d without available duration, %3$d already had one.', 'yt-to-streamit' ), $filled, $nodur, count( $have ) );
	if ( $left ) {
		/* translators: %d: number of episodes still pending */
		$msg .= ' ' . sprintf( __( '%d more are left: press the button again.', 'yt-to-streamit' ), $left );
	}
	return [ 'filled' => $filled, 'msg' => $msg ];
}
