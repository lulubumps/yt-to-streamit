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
		  WHERE v.meta_value <> '__prueba__'
		  ORDER BY e.post_date ASC, e.ID ASC"
	);

	
	if ( ! $rows ) {
		echo '<p class="description">Todavía no hay episodios importados por este plugin.</p>';
		return;
	}

	// Agrupar por programa
	$groups = [];
	foreach ( $rows as $r ) $groups[ (int) $r->show_id ][] = $r;

	$names = [];
	foreach ( $s['programs'] as $p ) $names[ (int) $p['show'] ] = ! empty( $p['label'] ) ? $p['label'] : '#' . (int) $p['show'];

	echo '<p class="description">' . count( $rows ) . ' episodio(s) importado(s) por este plugin, ordenados por programa, temporada y capítulo.</p>';

	$first = true;
	foreach ( $groups as $show_id => $eps ) {
		if ( ! isset( $names[ $show_id ] ) ) {
			$title = $show_id ? $wpdb->get_var( $wpdb->prepare( "SELECT post_title FROM {$wpdb->streamit_tvshow} WHERE ID = %d", $show_id ) ) : '';
			$names[ $show_id ] = $title ? $title : 'Sin programa';
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

		echo '<details style="margin:10px 0"' . ( $first ? ' open' : '' ) . '><summary style="cursor:pointer;font-size:14px;padding:6px 0"><strong>' . esc_html( $names[ $show_id ] ) . '</strong> — ' . count( $eps ) . ' episodio(s)';
		if ( $unlinked ) echo ' · <span style="color:#b32d2e">⚠️ ' . (int) $unlinked . ' sin enlazar (usa «Reparar enlaces»)</span>';
		echo '</summary>';
		$first = false;

		echo '<table class="widefat striped yts-paginated" data-per-page="20"><thead><tr><th>Temp.</th><th>Cap.</th><th>Título</th><th>Publicado en YouTube</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>';
		foreach ( array_slice( $eps, 0, 500 ) as $e ) {
			$status = 'publish' === $e->post_status ? 'Publicado' : ( 'draft' === $e->post_status ? 'Borrador' : $e->post_status );
			echo '<tr>';
			echo '<td>' . ( $e->season ? (int) $e->season : '⚠️' ) . '</td>';
			echo '<td>' . ( '' !== (string) $e->num ? (int) $e->num : '—' ) . '</td>';
			echo '<td>' . esc_html( $e->post_title ) . '</td>';
			echo '<td>' . esc_html( $e->post_date ) . '</td>';
			echo '<td>' . esc_html( $status ) . '</td>';
			echo '<td><a href="' . esc_url( admin_url( 'admin.php?page=streamit-edit-tvshow-episode&id=' . (int) $e->ID ) ) . '">Editar</a> · <a href="' . esc_url( 'https://www.youtube.com/watch?v=' . $e->video_id ) . '" target="_blank" rel="noopener">YouTube</a></td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		if ( count( $eps ) > 500 ) echo '<p class="description">Mostrando los primeros 500 de ' . count( $eps ) . '.</p>';
		echo '</details>';
	}
}
