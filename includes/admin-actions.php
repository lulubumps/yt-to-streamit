<?php
/**
 * Acciones del panel
 *
 * Recibe los formularios del panel: guardar ajustes (yts_save) y el despachador de botones (yts_do).
 *
 * @package YT_To_Streamit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_post_yts_save', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permiso' );
	check_admin_referer( 'yts_save' );

	global $wpdb;
	$programs = [];
	foreach ( (array) ( $_POST['programs'] ?? [] ) as $row ) {
		$pl = yts_parse_playlist( $row['playlist'] ?? '' );
		$sh = absint( $row['show'] ?? 0 );
		if ( ! $pl || ! $sh ) continue;
		$season = isset( $row['season'] ) && '' !== $row['season'] ? max( 0, absint( $row['season'] ) - 1 ) : '';
		$programs[] = [
			'playlist'  => $pl,
			'show'      => $sh,
			'label'     => (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_title FROM {$wpdb->streamit_tvshow} WHERE ID = %d", $sh ) ),
			'notify_at' => absint( $row['notify_at'] ?? 0 ),
			'season'    => $season,
			'active'    => empty( $row['active'] ) ? 0 : 1,
		];
	}

	// API key: vacío = conservar la actual; la casilla "Borrar" la elimina
	$old     = yts_settings();
	$api_key = isset( $_POST['youtube_api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['youtube_api_key'] ) ) ) : '';
	if ( ! empty( $_POST['clear_api_key'] ) ) $api_key = '';
	elseif ( '' === $api_key ) $api_key = $old['youtube_api_key'];

	// Fecha de inicio: solo formato AAAA-MM-DD; si no, hoy
	$since = isset( $_POST['import_since'] ) ? sanitize_text_field( wp_unslash( $_POST['import_since'] ) ) : '';
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) ) $since = gmdate( 'Y-m-d' );

	// Frecuencia: solo valores de la lista; si no, 12 horas
	$freq = isset( $_POST['frequency'] ) ? sanitize_text_field( wp_unslash( $_POST['frequency'] ) ) : '';
	if ( ! isset( yts_frequencies()[ $freq ] ) ) $freq = 'twicedaily';

	update_option( 'yts_settings', [
		'programs'         => $programs,
		'notify_email'     => sanitize_email( wp_unslash( $_POST['notify_email'] ?? '' ) ),
		'import_since'     => $since . ' 00:00:00',
		'post_status'      => in_array( $_POST['post_status'] ?? '', [ 'draft', 'publish' ], true ) ? $_POST['post_status'] : 'draft',
		'video_method'     => in_array( $_POST['video_method'] ?? '', [ 'episode_embed', 'episode_url' ], true ) ? $_POST['video_method'] : 'episode_embed',
		'template_episode' => absint( $_POST['template_episode'] ?? 0 ),
		'author_id'        => absint( $_POST['author_id'] ?? 0 ),
		'thumb_webp'       => empty( $_POST['thumb_webp'] ) ? 0 : 1,
		'frequency'        => $freq,
		'youtube_api_key'  => $api_key,
	], false ); // autoload = false: no se carga en cada visita
	if ( yts_frequency() !== wp_get_schedule( 'yts_sync' ) ) yts_reschedule(); // aplica la frecuencia nueva
	yts_back( 'Ajustes guardados.' );
} );

/**
 * Un solo punto de entrada para TODOS los botones del panel (excepto "Guardar", que va en yts_save).
 * Los botones son <button form="yts-actions" name="yts_do" value="..."> y se envían por POST con nonce.
 * Para agregar una acción nueva: ver MANTENIMIENTO.md → "Cómo agregar un botón".
 */
add_action( 'admin_post_yts_do', 'yts_handle_action' );

function yts_count_created( $log ) {
	$n = 0;
	foreach ( $log as $r ) if ( isset( $r[4] ) && 0 === strpos( $r[4], 'Creado' ) ) $n++;
	return $n;
}

function yts_handle_action() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permiso' );
	check_admin_referer( 'yts_do', 'yts_nonce' );

	$do = isset( $_POST['yts_do'] ) ? sanitize_text_field( wp_unslash( $_POST['yts_do'] ) ) : '';

	// Cerrar ventanas (lo llama JavaScript; responde sin contenido)
	if ( 'close_report' === $do || 'close_log' === $do ) {
		delete_option( 'close_report' === $do ? 'yts_last_report' : 'yts_last_log' );
		status_header( 204 );
		exit;
	}

	// Sincronizar un solo programa: "run_one:3"
	if ( 0 === strpos( $do, 'run_one:' ) ) {
		$i = absint( substr( $do, 8 ) );
		$s = yts_settings();
		if ( ! isset( $s['programs'][ $i ] ) ) yts_back( 'No encontré ese programa. Guarda los cambios y vuelve a intentarlo.' );
		$p    = $s['programs'][ $i ];
		$name = ! empty( $p['label'] ) ? $p['label'] : $p['playlist'];
		$n    = yts_count_created( yts_run( false, $i ) );
		yts_back( "Programa «{$name}» sincronizado: $n episodio(s) creado(s). Mira el detalle abajo." );
	}

	switch ( $do ) {
		case 'run_now':
			$n = yts_count_created( yts_run() );
			yts_back( "Sincronización ejecutada: $n episodio(s) creado(s). Mira el detalle abajo." );

		case 'diag':
			yts_run( true );
			yts_back( 'Diagnóstico listo. Mira el detalle abajo.' );

		case 'repair':
			$res = yts_repair();
			yts_back( $res['msg'] );

		case 'test_mail':
			$to = yts_mail_to( yts_settings() );
			delete_option( 'yts_last_mail_error' );
			$ok = wp_mail( $to, 'Prueba: YouTube a Streamit', 'Si lees esto, los avisos por correo funcionan.' );
			yts_back( $ok ? "Correo de prueba enviado a $to. Revisa también la carpeta de spam." : 'WordPress no pudo enviar el correo. Instala un plugin SMTP (ej. WP Mail SMTP).' );

		case 'verify':
		case 'verify_write':
			$write = ( 'verify_write' === $do );
			update_option( 'yts_last_report', [ 'time' => current_time( 'mysql' ), 'write' => $write, 'rows' => yts_checks( $write ) ], false );
			yts_back( 'Verificación lista. Revisa el informe abajo.' );

		default:
			yts_back( 'Acción desconocida.' );
	}
}
