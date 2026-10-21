<?php
/**
 * Se ejecuta al BORRAR el plugin desde WordPress (no al desactivarlo).
 * Elimina los ajustes y registros del plugin. NO borra los episodios ya importados en Streamit.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

wp_clear_scheduled_hook( 'yts_sync' );
wp_clear_scheduled_hook( 'yts_sync_followup' );

foreach ( [ 'yts_settings', 'yts_last_log', 'yts_last_report', 'yts_last_mail_error', 'yts_lock' ] as $option ) {
	delete_option( $option );
}

// Avisos de "programa llegó al capítulo N" (uno por programa)
global $wpdb;
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( 'yts_notified_' ) . '%' ) );
