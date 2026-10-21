<?php
/**
 * Acciones del panel
 *
 * Recibe los formularios: guardar (yts_save, por secciones) y el despachador de botones (yts_do).
 *
 * @package YT_To_Streamit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/* ------------------------------------------------------------------ *
 * Guardar. Cada formulario envía `yts_section` y solo cambia lo suyo:
 *   programs → lista de programas + importar desde + estado al importar
 *   settings → frecuencia, correo, cómo se carga el video, modelo, autor, WebP, API key
 * ------------------------------------------------------------------ */
add_action( 'admin_post_yts_save', 'yts_handle_save' );

function yts_handle_save() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permiso' );
	check_admin_referer( 'yts_save' );

	global $wpdb;
	$s       = yts_settings();
	$section = isset( $_POST['yts_section'] ) ? sanitize_key( wp_unslash( $_POST['yts_section'] ) ) : '';

	if ( 'programs' === $section ) {
		$programs = [];
		foreach ( (array) ( isset( $_POST['programs'] ) ? $_POST['programs'] : [] ) as $row ) {
			$pl = yts_parse_playlist( isset( $row['playlist'] ) ? $row['playlist'] : '' );
			$sh = absint( isset( $row['show'] ) ? $row['show'] : 0 );
			if ( ! $pl || ! $sh ) continue;
			$season = isset( $row['season'] ) && '' !== $row['season'] ? max( 0, absint( $row['season'] ) - 1 ) : '';
			$programs[] = [
				'playlist'  => $pl,
				'show'      => $sh,
				'label'     => (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_title FROM {$wpdb->streamit_tvshow} WHERE ID = %d", $sh ) ),
				'notify_at' => absint( isset( $row['notify_at'] ) ? $row['notify_at'] : 0 ),
				'season'    => $season,
				'active'    => empty( $row['active'] ) ? 0 : 1,
			];
		}

		// Fecha de inicio: solo formato AAAA-MM-DD; si no, hoy
		$since = isset( $_POST['import_since'] ) ? sanitize_text_field( wp_unslash( $_POST['import_since'] ) ) : '';
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) ) $since = gmdate( 'Y-m-d' );

		$s['programs']     = $programs;
		$s['import_since'] = $since . ' 00:00:00';
		$s['post_status']  = ( isset( $_POST['post_status'] ) && 'publish' === $_POST['post_status'] ) ? 'publish' : 'draft';
		$back = 'yts';

	} elseif ( 'settings' === $section ) {
		// API key: vacío = conservar la actual; la casilla "Borrar" la elimina
		$api_key = isset( $_POST['youtube_api_key'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['youtube_api_key'] ) ) ) : '';
		if ( ! empty( $_POST['clear_api_key'] ) ) $api_key = '';
		elseif ( '' === $api_key ) $api_key = $s['youtube_api_key'];

		// Frecuencia: solo valores de la lista; si no, 12 horas
		$freq = isset( $_POST['frequency'] ) ? sanitize_text_field( wp_unslash( $_POST['frequency'] ) ) : '';
		if ( ! isset( yts_frequencies()[ $freq ] ) ) $freq = 'twicedaily';

		$s['notify_email']     = sanitize_email( wp_unslash( isset( $_POST['notify_email'] ) ? $_POST['notify_email'] : '' ) );
		$s['video_method']     = ( isset( $_POST['video_method'] ) && 'episode_url' === $_POST['video_method'] ) ? 'episode_url' : 'episode_embed';
		$s['template_episode'] = absint( isset( $_POST['template_episode'] ) ? $_POST['template_episode'] : 0 );
		$s['author_id']        = absint( isset( $_POST['author_id'] ) ? $_POST['author_id'] : 0 );
		$s['thumb_webp']       = empty( $_POST['thumb_webp'] ) ? 0 : 1;
		$s['frequency']        = $freq;
		$s['youtube_api_key']  = $api_key;
		$back = 'yts-settings';

	} else {
		yts_back( 'No entendí qué guardar. Recarga la página e inténtalo de nuevo.' );
	}

	update_option( 'yts_settings', $s, false ); // autoload = false: no se carga en cada visita
	if ( 'yts-settings' === $back && yts_frequency() !== wp_get_schedule( 'yts_sync' ) ) yts_reschedule(); // aplica la frecuencia nueva
	yts_back( 'Ajustes guardados.', $back );
}

/* ------------------------------------------------------------------ *
 * Botones. Un solo punto de entrada para TODOS los botones (excepto "Guardar").
 * Son <button form="yts-actions" name="yts_do" value="..."> y se envían por POST con nonce.
 * Tras la acción se vuelve a la página de origen (campo `yts_return`).
 * Para agregar una acción nueva: ver MANTENIMIENTO.md → "Agregar un botón".
 * ------------------------------------------------------------------ */
add_action( 'admin_post_yts_do', 'yts_handle_action' );

function yts_count_created( $log ) {
	$n = 0;
	foreach ( $log as $r ) if ( isset( $r[4] ) && 0 === strpos( $r[4], 'Creado' ) ) $n++;
	return $n;
}

function yts_handle_action() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permiso' );
	check_admin_referer( 'yts_do', 'yts_nonce' );

	$do  = isset( $_POST['yts_do'] ) ? sanitize_text_field( wp_unslash( $_POST['yts_do'] ) ) : '';
	$ret = isset( $_POST['yts_return'] ) ? sanitize_key( wp_unslash( $_POST['yts_return'] ) ) : 'yts';

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
		if ( ! isset( $s['programs'][ $i ] ) ) yts_back( 'No encontré ese programa. Guarda los cambios y vuelve a intentarlo.', $ret );
		$p    = $s['programs'][ $i ];
		$name = ! empty( $p['label'] ) ? $p['label'] : $p['playlist'];
		$n    = yts_count_created( yts_run( false, $i ) );
		yts_back( "Programa «{$name}» sincronizado: $n episodio(s) creado(s). Mira el detalle abajo.", $ret );
	}

	switch ( $do ) {
		case 'run_now':
			$n = yts_count_created( yts_run() );
			yts_back( "Sincronización ejecutada: $n episodio(s) creado(s). Mira el detalle abajo.", $ret );

		case 'diag':
			yts_run( true );
			yts_back( 'Diagnóstico listo. Mira el detalle abajo.', $ret );

		case 'repair':
			$res = yts_repair();
			yts_back( $res['msg'], $ret );

		case 'test_mail':
			$to = yts_mail_to( yts_settings() );
			delete_option( 'yts_last_mail_error' );
			$ok = wp_mail( $to, 'Prueba: YouTube a Streamit', 'Si lees esto, los avisos por correo funcionan.' );
			yts_back( $ok ? "Correo de prueba enviado a $to. Revisa también la carpeta de spam." : 'WordPress no pudo enviar el correo. Instala un plugin SMTP (ej. WP Mail SMTP).', $ret );

		case 'verify':
		case 'verify_write':
			$write = ( 'verify_write' === $do );
			update_option( 'yts_last_report', [ 'time' => current_time( 'mysql' ), 'write' => $write, 'rows' => yts_checks( $write ) ], false );
			yts_back( 'Verificación lista. Revisa el informe abajo.', 'yts-verify' );

		default:
			yts_back( 'Acción desconocida.', $ret );
	}
}
