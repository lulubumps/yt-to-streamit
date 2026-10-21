<?php
/**
 * Ajustes y funciones de apoyo
 *
 * Lee los ajustes guardados (opción `yts_settings`) y reúne utilidades pequeñas: ID de playlist, ID de video, API key.
 *
 * @package YT_To_Streamit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function yts_settings() {
	$defaults = [
		'programs'         => [], // cada uno: playlist, show, label, notify_at, season
		'notify_email'     => '',
		'import_since'     => gmdate( 'Y-m-d' ) . ' 00:00:00',
		'post_status'      => 'draft',
		'video_method'     => 'episode_embed', // episode_embed | episode_url
		'template_episode' => 0,               // ID de un episodio modelo del cual se copian los demás campos
		'author_id'        => 0,
		'thumb_webp'       => 1,               // convertir miniaturas a WebP
		'youtube_api_key'  => '',              // opcional: API de YouTube (más confiable que el feed RSS)
		'max_per_run'      => 10,              // máximo de episodios por programa en cada pasada
		'frequency'        => 'twicedaily',    // cada cuánto sincroniza solo (ver yts_frequencies() en cron.php)
	];
	return wp_parse_args( get_option( 'yts_settings', [] ), $defaults );
}

function yts_parse_playlist( $input ) {
	$input = trim( $input );
	if ( preg_match( '/[?&]list=([A-Za-z0-9_-]+)/', $input, $m ) ) return $m[1];
	return preg_replace( '/[^A-Za-z0-9_-]/', '', $input );
}

/** Usuarios administradores (para elegir el autor de los episodios). Devuelve objetos con ID, display_name y user_login. */
function yts_admins() {
	return get_users( [ 'role' => 'administrator', 'orderby' => 'display_name', 'order' => 'ASC', 'fields' => [ 'ID', 'display_name', 'user_login' ] ] );
}

/** Un ID de video de YouTube son exactamente 11 caracteres [A-Za-z0-9_-]. Se valida porque el dato viene de fuera. */
function yts_valid_video_id( $id ) {
	return (bool) preg_match( '/^[A-Za-z0-9_-]{11}$/', (string) $id );
}

/**
 * API key de YouTube. Tiene prioridad la constante YTS_API_KEY definida en wp-config.php
 * (así la clave no queda guardada en la base de datos).
 */
function yts_api_key( $s = null ) {
	if ( defined( 'YTS_API_KEY' ) && YTS_API_KEY ) return trim( YTS_API_KEY );
	if ( null === $s ) $s = yts_settings();
	return isset( $s['youtube_api_key'] ) ? trim( $s['youtube_api_key'] ) : '';
}

function yts_mask_email( $email ) {
	$parts = explode( '@', $email );
	if ( 2 !== count( $parts ) ) return $email;
	return substr( $parts[0], 0, 2 ) . '***@' . $parts[1];
}
