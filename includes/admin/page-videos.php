<?php
/**
 * Página «Videos de la playlist»
 *
 * Muestra los videos de la playlist de un programa y qué pasará con cada uno: si ya existe en Streamit
 * (aunque se haya cargado antes, con otro título), si se importará o si se omite. Permite elegir cuáles
 * importar y, si quieres, importar los elegidos al instante.
 * La lógica de decisión está en includes/videos.php.
 *
 * @package YT_To_Streamit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Lista de videos de una playlist con caché de 10 minutos (para no gastar cuota de la API en cada recarga). */
function yts_cached_videos( $playlist, $s, $refresh = false ) {
	$key = 'yts_videos_' . md5( $playlist . '|' . ( yts_api_key( $s ) ? 'api' : 'rss' ) );
	if ( ! $refresh ) {
		$hit = get_transient( $key );
		if ( is_array( $hit ) ) return $hit;
	}
	$r = yts_fetch_videos( $playlist, $s );
	if ( empty( $r['error'] ) ) set_transient( $key, $r, 600 );
	return $r;
}

function yts_page_videos() {
	if ( ! current_user_can( 'manage_options' ) ) return;
	$s    = yts_settings();
	$i    = isset( $_GET['program'] ) && '' !== $_GET['program'] ? absint( $_GET['program'] ) : -1;
	$kses = [ 'strong' => [], 'a' => [ 'href' => [] ] ];

	yts_admin_header( 'yts-videos' );
	echo '<h2>' . esc_html__( 'Playlist videos', 'yt-to-streamit' ) . '</h2>';

	if ( ! $s['programs'] ) {
		/* translators: %s: link to the “Synced programs” page */
		echo '<p class="description">' . wp_kses( sprintf( __( 'First add a program in %s.', 'yt-to-streamit' ), '<a href="' . esc_url( admin_url( 'admin.php?page=yts' ) ) . '">' . esc_html__( 'Synced programs', 'yt-to-streamit' ) . '</a>' ), $kses ) . '</p>';
		yts_admin_footer();
		return;
	}

	// Selector de programa con buscador (el mismo de «Programas sincronizados»; solo lectura)
	$has_sel   = isset( $s['programs'][ $i ] );
	$cur_label = $has_sel ? yts_program_label( $s['programs'][ $i ] ) : '';
	?>
	<form method="get" action="" class="yts-toolbar">
		<input type="hidden" name="page" value="yts-videos">
		<label for="yts-program-pick"><strong><?php esc_html_e( 'Program:', 'yt-to-streamit' ); ?></strong></label>
		<div class="yts-combo" data-source="programs" data-submit="1">
			<input type="text" id="yts-program-pick" class="yts-combo-input" value="<?php echo esc_attr( $cur_label ); ?>" placeholder="<?php esc_attr_e( 'Search synced program…', 'yt-to-streamit' ); ?>" autocomplete="off">
			<input type="hidden" name="program" value="<?php echo $has_sel ? (int) $i : ''; ?>">
			<div class="yts-combo-list" hidden></div>
		</div>
		<noscript>
			<select name="program">
				<?php foreach ( $s['programs'] as $k => $prog ) : ?>
					<option value="<?php echo (int) $k; ?>" <?php selected( $k, $i ); ?>><?php echo esc_html( yts_program_label( $prog ) ); ?></option>
				<?php endforeach; ?>
			</select>
		</noscript>
		<button class="button"><?php esc_html_e( 'View videos', 'yt-to-streamit' ); ?></button>
	</form>
	<?php

	if ( ! isset( $s['programs'][ $i ] ) ) {
		echo '<p class="description">' . wp_kses( __( 'Choose a program to see the videos of its playlist. You can also come in with the <strong>Choose videos</strong> button on each row in “Synced programs”.', 'yt-to-streamit' ), $kses ) . '</p>';
		yts_admin_footer();
		return;
	}

	$p       = $s['programs'][ $i ];
	$name    = yts_program_label( $p );
	$mode    = yts_program_mode( $p );
	$refresh = ! empty( $_GET['refresh'] );
	$feed    = yts_cached_videos( $p['playlist'], $s, $refresh );
	$self    = admin_url( 'admin.php?page=yts-videos&program=' . $i );

	$mode_label = ( 'manual' === $mode ) ? __( 'only the ones you choose', 'yt-to-streamit' ) : __( 'all new videos', 'yt-to-streamit' );
	$mode_text  = ( 'manual' === $mode )
		? __( 'Only the videos you check here are imported.', 'yt-to-streamit' )
		: __( 'All new videos are imported, except the ones you uncheck here.', 'yt-to-streamit' );
	$progs_link = '<a href="' . esc_url( admin_url( 'admin.php?page=yts' ) ) . '">' . esc_html__( 'Synced programs', 'yt-to-streamit' ) . '</a>';

	echo '<div class="yts-card"><p>';
	/* translators: 1: program name, 2: video mode */
	echo wp_kses( sprintf( __( '%1$s — video mode: %2$s.', 'yt-to-streamit' ), '<strong>' . esc_html( $name ) . '</strong>', '<strong>' . esc_html( $mode_label ) . '</strong>' ), $kses );
	echo ' ' . esc_html( $mode_text ) . ' ';
	/* translators: %s: link to the “Synced programs” page */
	echo wp_kses( sprintf( __( 'The mode is changed in %s (“Videos to import” column).', 'yt-to-streamit' ), $progs_link ), $kses );
	echo '</p><p class="description">' . wp_kses( __( 'Videos that are <strong>already loaded in Streamit</strong> are detected by their link or embed code (even if the title is different) and are skipped automatically.', 'yt-to-streamit' ), $kses ) . '</p></div>';

	if ( ! empty( $feed['error'] ) ) {
		/* translators: %s: error detail */
		echo '<div class="notice notice-warning inline"><p>' . wp_kses( sprintf( __( '<strong>The playlist could not be read:</strong> %s', 'yt-to-streamit' ), esc_html( $feed['error'] ) ), $kses ) . ' <a href="' . esc_url( add_query_arg( 'refresh', 1, $self ) ) . '">' . esc_html__( 'Retry', 'yt-to-streamit' ) . '</a></p></div>';
		yts_admin_footer();
		return;
	}
	if ( empty( $feed['entries'] ) ) {
		echo '<p class="description">' . esc_html__( 'The playlist has no videos.', 'yt-to-streamit' ) . '</p>';
		yts_admin_footer();
		return;
	}

	// Clasificar cada video
	$since = strtotime( $s['import_since'] );
	$rows  = [];
	$count = [ 'import' => 0, 'exists' => 0, 'skip' => 0, 'old' => 0 ];
	foreach ( $feed['entries'] as $e ) {
		list( $kind, $note, $ep ) = yts_classify_video( $p, $s, $e, $since );
		$count[ $kind ]++;
		$rows[] = [ $e, $kind, $note, $ep ];
	}

	echo '<p>';
	/* translators: 1: number of videos, 2: source (API or RSS), 3: to import, 4: already in Streamit, 5: not selected, 6: before the start date */
	echo wp_kses( sprintf( __( '<strong>%1$d videos</strong> (via %2$s): %3$d to import · %4$d already exist in Streamit · %5$d not selected · %6$d before the start date.', 'yt-to-streamit' ), count( $rows ), esc_html( $feed['source'] ), $count['import'], $count['exists'], $count['skip'], $count['old'] ), $kses );
	echo ' <a href="' . esc_url( add_query_arg( 'refresh', 1, $self ) ) . '">' . esc_html__( 'Refresh list', 'yt-to-streamit' ) . '</a>';
	if ( 'RSS' === $feed['source'] ) echo '<br><span class="description">' . esc_html__( 'Without an API key you only see the last 15 videos of the playlist. With an API key (Settings) you see up to 500.', 'yt-to-streamit' ) . '</span>';
	echo '</p>';

	$l = [
		'import'    => esc_attr_x( 'Import', 'column header: import this video', 'yt-to-streamit' ),
		'title'     => esc_attr__( 'Title on YouTube', 'yt-to-streamit' ),
		'published' => esc_attr__( 'Published', 'yt-to-streamit' ),
		'status'    => esc_attr__( 'Status', 'yt-to-streamit' ),
	];
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="yts_save">
		<input type="hidden" name="yts_section" value="videos">
		<input type="hidden" name="program" value="<?php echo (int) $i; ?>">
		<?php wp_nonce_field( 'yts_save' ); ?>

		<div class="yts-toolbar">
			<input type="search" id="yts-video-filter" class="regular-text" placeholder="<?php esc_attr_e( 'Filter videos by title…', 'yt-to-streamit' ); ?>">
			<button type="button" class="button" data-yts-check="all"><?php esc_html_e( 'Check all', 'yt-to-streamit' ); ?></button>
			<button type="button" class="button" data-yts-check="none"><?php esc_html_e( 'Uncheck all', 'yt-to-streamit' ); ?></button>
		</div>

		<table class="widefat striped yts-paginated yts-stack yts-videos" id="yts-videos-table" data-cards-below="760" data-per-page="25" data-filter="#yts-video-filter">
			<thead><tr><th class="yts-col-pick"><?php echo esc_html( $l['import'] ); ?></th><th class="yts-col-thumb"></th><th><?php echo esc_html( $l['title'] ); ?></th><th><?php echo esc_html( $l['published'] ); ?></th><th><?php echo esc_html( $l['status'] ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $r ) :
				list( $e, $kind, $note, $ep ) = $r;
				$locked = ( 'exists' === $kind );
				?>
				<tr>
					<td class="yts-col-pick" data-label="<?php echo $l['import']; ?>">
						<?php if ( $locked ) : ?>
							<input type="checkbox" disabled title="<?php esc_attr_e( 'Already exists in Streamit', 'yt-to-streamit' ); ?>">
						<?php else : ?>
							<input type="checkbox" name="pick[]" value="<?php echo esc_attr( $e['id'] ); ?>" <?php checked( 'import' === $kind ); ?>>
							<input type="hidden" name="listed[]" value="<?php echo esc_attr( $e['id'] ); ?>">
						<?php endif; ?>
					</td>
					<td class="yts-col-thumb"><img src="<?php echo esc_url( 'https://i.ytimg.com/vi/' . $e['id'] . '/default.jpg' ); ?>" width="60" alt="" loading="lazy"></td>
					<td data-label="<?php echo $l['title']; ?>"><a href="<?php echo esc_url( 'https://www.youtube.com/watch?v=' . $e['id'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $e['title'] ); ?></a></td>
					<td data-label="<?php echo $l['published']; ?>"><?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $e['published'] ), 'Y-m-d' ) ); ?></td>
					<td data-label="<?php echo $l['status']; ?>">
						<?php
						if ( $locked ) {
							echo '✅ ' . esc_html__( 'Already exists in Streamit', 'yt-to-streamit' );
							if ( $ep ) echo ': <a href="' . esc_url( admin_url( 'admin.php?page=streamit-edit-tvshow-episode&id=' . (int) $ep['id'] ) ) . '">' . esc_html( $ep['title'] ) . ' (#' . (int) $ep['id'] . ')</a>';
							else echo ' ' . esc_html__( '(imported by this plugin)', 'yt-to-streamit' );
						} elseif ( 'import' === $kind ) {
							echo '🟢 ' . esc_html__( 'Will be imported', 'yt-to-streamit' );
						} else {
							echo '⚪ ' . esc_html( ucfirst( $note ) );
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<p class="submit yts-submit">
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Save selection', 'yt-to-streamit' ); ?></button>
			<button type="submit" name="yts_after" value="sync" class="button"><?php esc_html_e( 'Save and import the selected ones now', 'yt-to-streamit' ); ?></button>
		</p>
		<p class="description"><?php esc_html_e( 'Save your choice before leaving the page. If you check a video published before “Import videos from”, it is imported anyway: your selection takes priority over the date.', 'yt-to-streamit' ); ?></p>
	</form>
	<?php
	yts_admin_footer();
}
