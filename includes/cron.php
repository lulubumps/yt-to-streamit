<?php
/**
 * Tarea automática (cron)
 *
 * Programa la sincronización con WP-Cron. La frecuencia se elige en el panel (por defecto, cada 12 horas).
 * WP-Cron solo se dispara cuando alguien visita el sitio.
 *
 * @package YT_To_Streamit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Frecuencias disponibles: clave de WP-Cron => texto del panel.
 * `hourly`, `twicedaily` (12 h) y `daily` ya existen en WordPress; las otras se registran abajo.
 */
function yts_frequencies() {
	return [
		'yts_30min'  => 'Cada 30 minutos',
		'hourly'     => 'Cada hora',
		'yts_6h'     => 'Cada 6 horas',
		'twicedaily' => 'Cada 12 horas',
		'daily'      => 'Cada 24 horas',
	];
}

/** Frecuencia elegida (si el valor guardado no es válido, 12 horas). */
function yts_frequency() {
	$s = yts_settings();
	return isset( yts_frequencies()[ $s['frequency'] ] ) ? $s['frequency'] : 'twicedaily';
}

add_filter( 'cron_schedules', function ( $s ) {
	$s['yts_30min'] = [ 'interval' => 1800,  'display' => 'Cada 30 minutos' ];
	$s['yts_6h']    = [ 'interval' => 21600, 'display' => 'Cada 6 horas' ];
	return $s;
} );

add_action( 'yts_sync', 'yts_run' );
add_action( 'yts_sync_followup', 'yts_run_followup' );

/** Borra la tarea programada y la crea de nuevo con la frecuencia actual. */
function yts_reschedule() {
	wp_clear_scheduled_hook( 'yts_sync' );
	wp_schedule_event( time() + 60, yts_frequency(), 'yts_sync' );
}

// Autocorrección: si la tarea no existe o quedó con otra frecuencia (por ejemplo, tras actualizar el plugin), se reprograma.
add_action( 'admin_init', function () {
	if ( ! wp_next_scheduled( 'yts_sync' ) || wp_get_schedule( 'yts_sync' ) !== yts_frequency() ) yts_reschedule();
} );

function yts_activate() {
	yts_reschedule();
}

function yts_deactivate() {
	wp_clear_scheduled_hook( 'yts_sync' );
	wp_clear_scheduled_hook( 'yts_sync_followup' );
}
