<?php
/**
 * Adaptador de Streamit
 *
 * ÚNICO lugar que conoce las funciones y tablas internas de Streamit 1.3.x. Si Streamit cambia en una versión futura, se corrige aquí (ver MANTENIMIENTO.md).
 *
 * @package YT_To_Streamit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function yts_ready() {
	return function_exists( 'streamit_add_episode' )
		&& function_exists( 'streamit_add_episode_meta' )
		&& function_exists( 'streamit_get_tvshow_meta' )
		&& function_exists( 'streamit_update_tvshow_meta' );
}

function yts_shows() {
	global $wpdb;
	if ( empty( $wpdb->streamit_tvshow ) ) return [];
	return $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->streamit_tvshow} WHERE post_status IN ('publish','draft','private','pending') ORDER BY post_title ASC" );
}

function yts_seasons( $show_id ) {
	$seasons = streamit_get_tvshow_meta( $show_id, '_seasons' );
	return is_array( $seasons ) ? $seasons : [];
}

function yts_total_episodes( $show_id ) {
	if ( ! yts_ready() ) return 0;
	$n = 0;
	foreach ( yts_seasons( $show_id ) as $season ) {
		$n += ! empty( $season['episodes'] ) ? count( (array) $season['episodes'] ) : 0;
	}
	return $n;
}

function yts_exists( $video_id ) {
	global $wpdb;
	return (bool) $wpdb->get_var( $wpdb->prepare(
		"SELECT streamit_episode_id FROM {$wpdb->streamit_episodemeta} WHERE meta_key = '_yts_video_id' AND meta_value = %s LIMIT 1",
		$video_id
	) );
}

function yts_author( $s ) {
	if ( $s['author_id'] ) return (int) $s['author_id'];
	$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
	return $admins ? (int) $admins[0] : 1;
}

function yts_flush_show_cache( $show_id ) {
	try {
		if ( class_exists( 'Streamit_Tvshow' ) ) {
			$t = new Streamit_Tvshow( $show_id );
			if ( method_exists( $t, 'invalidate_meta_cache' ) ) $t->invalidate_meta_cache();
		}
	} catch ( \Throwable $e ) {
		error_log( '[yts] No se pudo limpiar la caché del programa: ' . $e->getMessage() );
	}
}

// Episodios creados por este plugin para el programa, pero que no aparecen en ninguna temporada

function yts_unlinked_ids( $show_id ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT DISTINCT e.ID, e.post_date
		   FROM {$wpdb->streamit_episode} e
		   JOIN {$wpdb->streamit_episodemeta} a ON a.streamit_episode_id = e.ID AND a.meta_key = '_yts_video_id'
		   JOIN {$wpdb->streamit_episodemeta} b ON b.streamit_episode_id = e.ID AND b.meta_key = 'tvshow_id' AND b.meta_value = %s
		  ORDER BY e.post_date ASC, e.ID ASC",
		(string) (int) $show_id
	) );
	$ids = [];
	foreach ( (array) $rows as $row ) $ids[] = (int) $row->ID;

	$linked = [];
	foreach ( yts_seasons( $show_id ) as $season ) {
		foreach ( (array) ( isset( $season['episodes'] ) ? $season['episodes'] : [] ) as $eid ) $linked[] = (int) $eid;
	}
	return array_values( array_diff( $ids, $linked ) );
}
