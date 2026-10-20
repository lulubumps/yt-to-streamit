<?php
/**
 * Avisos por correo
 *
 * Correo cuando un programa llega al capítulo configurado, y registro del último error de envío.
 *
 * @package YT_To_Streamit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'wp_mail_failed', function ( $err ) {
	update_option( 'yts_last_mail_error', $err->get_error_message() );
} );

function yts_mail_to( $s ) {
	return $s['notify_email'] ? $s['notify_email'] : get_option( 'admin_email' );
}

function yts_check_milestone( $p, $s ) {
	$target = (int) ( $p['notify_at'] ?? 0 );
	if ( $target < 1 ) return;

	$show_id = (int) $p['show'];
	$flag    = "yts_notified_{$show_id}_{$target}";
	if ( get_option( $flag ) ) return;

	$count = yts_total_episodes( $show_id );
	if ( $count < $target ) return;

	$label = ! empty( $p['label'] ) ? $p['label'] : "Programa #$show_id";
	$sent  = wp_mail(
		yts_mail_to( $s ),
		"El programa \"$label\" llegó al capítulo $target",
		"El programa \"$label\" ya tiene $count episodios en el sitio."
	);

	// Solo se marca como avisado si el correo realmente salió; si falla, reintenta en la próxima pasada
	if ( $sent ) {
		update_option( $flag, 1 );
		delete_option( 'yts_last_mail_error' );
	} else {
		error_log( "[yts] No se pudo enviar el aviso del programa $label" );
	}
}
