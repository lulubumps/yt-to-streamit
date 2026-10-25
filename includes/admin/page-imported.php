<?php
/**
 * Página «Episodios importados»
 *
 * Lista de episodios creados por este plugin, agrupados por programa, ordenados por temporada y capítulo, con paginación.
 *
 * @package YT_To_Streamit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function yts_page_imported() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	yts_admin_header( 'yts-imported' );
	echo '<h2>' . esc_html__( 'Imported episodes', 'yt-to-streamit' ) . '</h2>';
	yts_render_imported();
	yts_admin_footer();
}

function yts_render_imported() {
	global $wpdb;
	if ( ! yts_ready() ) return;
	$s = yts_settings();

	$rows = $wpdb->get_results(
		"SELECT e.ID, e.post_title, e.post_status, e.post_date, v.meta_value AS video_id,
		        (SELECT meta_value FROM {$wpdb->streamit_episodemeta} WHERE streamit_episode_id = e.ID AND meta_key = 'tvshow_id' LIMIT 1) AS show_id,
		        (SELECT meta_value FROM {$wpdb->streamit_episodemeta} WHERE streamit_episode_id = e.ID AND meta_key = '_episode_number' LIMIT 1) AS num
		   FROM {$wpdb->streamit_episode} e
		   JOIN {$wpdb->streamit_episodemeta} v ON v.streamit_episode_id = e.ID AND v.meta_key = '_yts_video_id'
		  WHERE v.meta_value NOT IN ( '__test__', '__prueba__' )
		  ORDER BY e.post_date ASC, e.ID ASC"
	);

	if ( ! $rows ) {
		echo '<p class="description">' . esc_html__( 'No episodes have been imported by this plugin yet.', 'yt-to-streamit' ) . '</p>';
		return;
	}

	// Agrupar por programa
	$groups = [];
	foreach ( $rows as $r ) $groups[ (int) $r->show_id ][] = $r;

	$names = [];
	foreach ( $s['programs'] as $p ) $names[ (int) $p['show'] ] = ! empty( $p['label'] ) ? $p['label'] : '#' . (int) $p['show'];

	echo '<p class="description">';
	/* translators: %d: number of episodes */
	echo esc_html( sprintf( __( '%d episode(s) imported by this plugin, sorted by program, season and episode.', 'yt-to-streamit' ), count( $rows ) ) );
	echo '</p>';

	$l = [
		'season'    => esc_attr__( 'Season', 'yt-to-streamit' ),
		'episode'   => esc_attr__( 'Ep.', 'yt-to-streamit' ),
		'title'     => esc_attr__( 'Title', 'yt-to-streamit' ),
		'published' => esc_attr__( 'Published on YouTube', 'yt-to-streamit' ),
		'status'    => esc_attr__( 'Status', 'yt-to-streamit' ),
		'actions'   => esc_attr__( 'Actions', 'yt-to-streamit' ),
	];

	$first = true;
	foreach ( $groups as $show_id => $eps ) {
		if ( ! isset( $names[ $show_id ] ) ) {
			$title = $show_id ? $wpdb->get_var( $wpdb->prepare( "SELECT post_title FROM {$wpdb->streamit_tvshow} WHERE ID = %d", $show_id ) ) : '';
			$names[ $show_id ] = $title ? $title : __( 'No program', 'yt-to-streamit' );
		}

		// Temporada de cada episodio según el programa
		$season_of = [];
		if ( $show_id ) {
			foreach ( yts_seasons( $show_id ) as $i => $season ) {
				foreach ( (array) ( isset( $season['episodes'] ) ? $season['episodes'] : [] ) as $eid ) $season_of[ (int) $eid ] = $i + 1;
			}
		}

		$unlinked = 0;
		foreach ( $eps as $e ) {
			$e->season = isset( $season_of[ (int) $e->ID ] ) ? $season_of[ (int) $e->ID ] : 0;
			if ( ! $e->season ) $unlinked++;
		}
		usort( $eps, function ( $a, $b ) {
			if ( $a->season !== $b->season ) return $a->season <=> $b->season;
			if ( (int) $a->num !== (int) $b->num ) return (int) $a->num <=> (int) $b->num;
			return (int) $a->ID <=> (int) $b->ID;
		} );

		echo '<details class="yts-group"' . ( $first ? ' open' : '' ) . '><summary><strong>' . esc_html( $names[ $show_id ] ) . '</strong> — ';
		/* translators: %d: number of episodes */
		echo esc_html( sprintf( __( '%d episode(s)', 'yt-to-streamit' ), count( $eps ) ) );
		if ( $unlinked ) {
			/* translators: %d: number of episodes that are not linked to a season */
			echo ' · <span class="yts-warn">⚠️ ' . esc_html( sprintf( __( '%d not linked (use “Repair links”)', 'yt-to-streamit' ), $unlinked ) ) . '</span>';
		}
		echo '</summary>';
		$first = false;

		echo '<table class="widefat striped yts-paginated yts-stack" data-per-page="20"><thead><tr><th>' . esc_html( $l['season'] ) . '</th><th>' . esc_html( $l['episode'] ) . '</th><th>' . esc_html( $l['title'] ) . '</th><th>' . esc_html( $l['published'] ) . '</th><th>' . esc_html( $l['status'] ) . '</th><th>' . esc_html( $l['actions'] ) . '</th></tr></thead><tbody>';
		foreach ( array_slice( $eps, 0, 500 ) as $e ) {
			$status = 'publish' === $e->post_status ? __( 'Published', 'yt-to-streamit' ) : ( 'draft' === $e->post_status ? __( 'Draft', 'yt-to-streamit' ) : $e->post_status );
			echo '<tr>';
			echo '<td data-label="' . $l['season'] . '">' . ( $e->season ? (int) $e->season : '⚠️' ) . '</td>';
			echo '<td data-label="' . $l['episode'] . '">' . ( '' !== (string) $e->num ? (int) $e->num : '—' ) . '</td>';
			echo '<td data-label="' . $l['title'] . '">' . esc_html( $e->post_title ) . '</td>';
			echo '<td data-label="' . $l['published'] . '">' . esc_html( $e->post_date ) . '</td>';
			echo '<td data-label="' . $l['status'] . '">' . esc_html( $status ) . '</td>';
			echo '<td data-label="' . $l['actions'] . '"><a href="' . esc_url( admin_url( 'admin.php?page=streamit-edit-tvshow-episode&id=' . (int) $e->ID ) ) . '">' . esc_html__( 'Edit', 'yt-to-streamit' ) . '</a> · <a href="' . esc_url( 'https://www.youtube.com/watch?v=' . $e->video_id ) . '" target="_blank" rel="noopener">YouTube</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		if ( count( $eps ) > 500 ) {
			/* translators: %d: total number of episodes */
			echo '<p class="description">' . esc_html( sprintf( __( 'Showing the first 500 of %d.', 'yt-to-streamit' ), count( $eps ) ) ) . '</p>';
		}
		echo '</details>';
	}
}
