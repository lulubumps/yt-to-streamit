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
	if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You do not have permission to do this.', 'yt-to-streamit' ) );
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
				'mode'      => ( isset( $row['mode'] ) && 'manual' === $row['mode'] ) ? 'manual' : 'auto',
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
		// Autor: solo un administrador de la lista (0 = automático)
		$author = absint( isset( $_POST['author_id'] ) ? $_POST['author_id'] : 0 );
		$admin_ids = [];
		foreach ( yts_admins() as $u ) $admin_ids[] = (int) $u->ID;
		if ( ! in_array( $author, $admin_ids, true ) ) $author = 0;

		$s['author_id']        = $author;
		$s['max_per_run']      = min( 100, max( 1, absint( isset( $_POST['max_per_run'] ) ? $_POST['max_per_run'] : 10 ) ) );
		$s['thumb_webp']       = empty( $_POST['thumb_webp'] ) ? 0 : 1;
		$s['frequency']        = $freq;
		$s['youtube_api_key']  = $api_key;
		$back = 'yts-settings';

	} elseif ( 'videos' === $section ) {
		// Selección de videos de una playlist. Solo cambia las decisiones de ESA playlist.
		$i = isset( $_POST['program'] ) ? absint( $_POST['program'] ) : -1;
		if ( ! isset( $s['programs'][ $i ] ) ) yts_back( __( 'I could not find that program. Choose it again.', 'yt-to-streamit' ), 'yts-videos' );
		$playlist = $s['programs'][ $i ]['playlist'];

		$listed  = yts_clean_video_ids( isset( $_POST['listed'] ) ? $_POST['listed'] : [] );
		$pick    = yts_clean_video_ids( isset( $_POST['pick'] ) ? $_POST['pick'] : [] );
		$choices = yts_get_choices( $playlist );
		foreach ( $listed as $id ) $choices[ $id ] = in_array( $id, $pick, true ) ? 1 : 0;
		yts_set_choices( $playlist, $choices );

		$msg = __( 'Selection saved.', 'yt-to-streamit' );
		if ( isset( $_POST['yts_after'] ) && 'sync' === $_POST['yts_after'] ) {
			/* translators: %s: summary of the sync (for example "3 episode(s) created") */
			$msg = sprintf( __( 'Selection saved. Sync: %s.', 'yt-to-streamit' ), yts_summary( yts_run( false, $i ) ) );
		}
		yts_back( $msg, 'yts-videos', [ 'program' => $i ] + ( isset( $_POST['yts_after'] ) && 'sync' === $_POST['yts_after'] ? [ 'yts_log' => 1 ] : [] ) );

	} else {
		yts_back( __( 'I did not understand what to save. Reload the page and try again.', 'yt-to-streamit' ) );
	}

	update_option( 'yts_settings', $s, false ); // autoload = false: no se carga en cada visita
	if ( 'yts-settings' === $back && yts_frequency() !== wp_get_schedule( 'yts_sync' ) ) yts_reschedule(); // aplica la frecuencia nueva
	yts_back( __( 'Settings saved.', 'yt-to-streamit' ), $back );
}

/* ------------------------------------------------------------------ *
 * Botones. Un solo punto de entrada para TODOS los botones (excepto "Guardar").
 * Son <button form="yts-actions" name="yts_do" value="..."> y se envían por POST con nonce.
 * Tras la acción se vuelve a la página de origen (campo `yts_return`).
 * Para agregar una acción nueva: ver MANTENIMIENTO.md → "Agregar un botón".
 * ------------------------------------------------------------------ */
add_action( 'admin_post_yts_do', 'yts_handle_action' );

function yts_handle_action() {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( esc_html__( 'You do not have permission to do this.', 'yt-to-streamit' ) );
	check_admin_referer( 'yts_do', 'yts_nonce' );

	$do  = isset( $_POST['yts_do'] ) ? sanitize_text_field( wp_unslash( $_POST['yts_do'] ) ) : '';
	$ret = isset( $_POST['yts_return'] ) ? sanitize_key( wp_unslash( $_POST['yts_return'] ) ) : 'yts';

	// Cerrar el informe de verificación (lo llama JavaScript; responde sin contenido)
	if ( 'close_report' === $do ) {
		delete_option( 'yts_last_report' );
		status_header( 204 );
		exit;
	}

	// Sincronizar un solo programa: "run_one:3"
	if ( 0 === strpos( $do, 'run_one:' ) ) {
		$i = absint( substr( $do, 8 ) );
		$s = yts_settings();
		if ( ! isset( $s['programs'][ $i ] ) ) yts_back( __( 'I could not find that program. Save your changes and try again.', 'yt-to-streamit' ), $ret );
		$p    = $s['programs'][ $i ];
		$name = ! empty( $p['label'] ) ? $p['label'] : $p['playlist'];
		/* translators: 1: program name, 2: summary of the sync */
		yts_back( sprintf( __( 'Program “%1$s” synced: %2$s.', 'yt-to-streamit' ), $name, yts_summary( yts_run( false, $i ) ) ), $ret, [ 'yts_log' => 1 ] );
	}

	switch ( $do ) {
		case 'run_now':
			/* translators: %s: summary of the sync (for example "3 episode(s) created") */
			yts_back( sprintf( __( 'Sync finished: %s.', 'yt-to-streamit' ), yts_summary( yts_run() ) ), $ret, [ 'yts_log' => 1 ] );

		case 'diag':
			yts_run( true );
			yts_back( __( 'Diagnostic ready.', 'yt-to-streamit' ), $ret, [ 'yts_log' => 1 ] );

		case 'fill_durations':
			$res = yts_fill_durations( ! empty( $_POST['yts_fill_all'] ) );
			yts_back( $res['msg'], $ret );

		case 'clear_log':
			delete_option( 'yts_last_log' );
			yts_back( __( 'Log cleared.', 'yt-to-streamit' ), 'yts-log' );

		case 'repair':
			$res = yts_repair();
			yts_back( $res['msg'], $ret );

		case 'test_mail':
			$to = yts_mail_to( yts_settings() );
			delete_option( 'yts_last_mail_error' );
			$ok = wp_mail( $to, __( 'Test: YouTube to Streamit', 'yt-to-streamit' ), __( 'If you are reading this, the email notices work.', 'yt-to-streamit' ) );
			/* translators: %s: email address */
			yts_back( $ok ? sprintf( __( 'Test email sent to %s. Also check the spam folder.', 'yt-to-streamit' ), $to ) : __( 'WordPress could not send the email. Install an SMTP plugin (e.g. WP Mail SMTP).', 'yt-to-streamit' ), $ret );

		case 'verify':
		case 'verify_write':
			$write = ( 'verify_write' === $do );
			update_option( 'yts_last_report', [ 'time' => current_time( 'mysql' ), 'write' => $write, 'rows' => yts_checks( $write ) ], false );
			yts_back( __( 'Check finished. See the report below.', 'yt-to-streamit' ), 'yts-verify' );

		default:
			yts_back( __( 'Unknown action.', 'yt-to-streamit' ), $ret );
	}
}
