<?php
/**
 * Página «Programas sincronizados» (la principal)
 *
 * Tabla de programas (playlist de YouTube ↔ programa de Streamit) con buscador, paginación,
 * activar/desactivar y sincronización individual, más las opciones de importación.
 * El comportamiento del selector con buscador y de la paginación está en assets/admin.js.
 * En pantallas chicas la tabla se muestra como tarjetas (assets/admin.css, clase yts-stack + data-label).
 *
 * @package YT_To_Streamit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Títulos de las columnas de la tabla de programas (se usan en el encabezado y como data-label de cada celda). */
function yts_program_columns() {
	return [
		'active'   => __( 'Active', 'yt-to-streamit' ),
		'playlist' => __( 'YouTube playlist', 'yt-to-streamit' ),
		'show'     => __( 'Streamit program', 'yt-to-streamit' ),
		'season'   => __( 'Season no.', 'yt-to-streamit' ),
		'notify'   => __( 'Notify at episode', 'yt-to-streamit' ),
		'episodes' => __( 'Episodes today', 'yt-to-streamit' ),
		'videos'   => __( 'Videos to import', 'yt-to-streamit' ),
		'actions'  => __( 'Actions', 'yt-to-streamit' ),
	];
}

/** Una fila de la tabla. Con $i = '__I__' se genera la plantilla para «+ Agregar programa». */
function yts_row( $i, $row ) {
	$sel    = (int) ( $row['show'] ?? 0 );
	$active = ! isset( $row['active'] ) || $row['active'];
	$title  = $sel ? yts_show_title( $sel ) : '';
	if ( ! $title && ! empty( $row['label'] ) ) $title = $row['label'];
	$c      = array_map( 'esc_attr', yts_program_columns() );
	$saved  = is_numeric( $i ) && ! empty( $row['playlist'] );

	ob_start(); ?>
	<tr class="<?php echo $active ? '' : 'yts-off'; ?>">
		<td class="yts-col-active" data-label="<?php echo $c['active']; ?>"><input type="checkbox" name="programs[<?php echo $i; ?>][active]" value="1" <?php checked( $active ); ?> title="<?php esc_attr_e( 'Turn the checkmark off to stop syncing this program', 'yt-to-streamit' ); ?>"></td>
		<td data-label="<?php echo $c['playlist']; ?>"><input type="text" class="yts-wide" name="programs[<?php echo $i; ?>][playlist]" value="<?php echo esc_attr( $row['playlist'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'URL or playlist ID', 'yt-to-streamit' ); ?>"></td>
		<td data-label="<?php echo $c['show']; ?>">
			<div class="yts-combo" data-source="shows">
				<input type="text" class="yts-combo-input" value="<?php echo esc_attr( $title ); ?>" placeholder="<?php esc_attr_e( 'Search Streamit program…', 'yt-to-streamit' ); ?>" autocomplete="off" aria-label="<?php echo $c['show']; ?>">
				<input type="hidden" name="programs[<?php echo $i; ?>][show]" value="<?php echo $sel ? $sel : ''; ?>">
				<div class="yts-combo-list" hidden></div>
			</div>
		</td>
		<td data-label="<?php echo $c['season']; ?>"><input type="number" min="1" class="yts-num" name="programs[<?php echo $i; ?>][season]" value="<?php echo isset( $row['season'] ) && '' !== $row['season'] ? (int) $row['season'] + 1 : ''; ?>" placeholder="<?php esc_attr_e( 'last', 'yt-to-streamit' ); ?>"></td>
		<td data-label="<?php echo $c['notify']; ?>"><input type="number" min="0" class="yts-num" name="programs[<?php echo $i; ?>][notify_at]" value="<?php echo esc_attr( $row['notify_at'] ?? 13 ); ?>"></td>
		<td data-label="<?php echo $c['episodes']; ?>"><?php echo ( $sel && yts_ready() ) ? (int) yts_total_episodes( $sel ) : '—'; ?></td>
		<td class="yts-col-videos" data-label="<?php echo $c['videos']; ?>">
			<div class="yts-cell-multi">
			<select name="programs[<?php echo $i; ?>][mode]" title="<?php esc_attr_e( 'All: every new video is imported. Only the ones I choose: only the videos you check in “Playlist videos” are imported.', 'yt-to-streamit' ); ?>">
				<option value="auto" <?php selected( yts_program_mode( $row ), 'auto' ); ?>><?php esc_html_e( 'All new videos', 'yt-to-streamit' ); ?></option>
				<option value="manual" <?php selected( yts_program_mode( $row ), 'manual' ); ?>><?php esc_html_e( 'Only the ones I choose', 'yt-to-streamit' ); ?></option>
			</select>
			<?php if ( $saved ) : ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=yts-videos&program=' . (int) $i ) ); ?>"><?php esc_html_e( 'Choose videos', 'yt-to-streamit' ); ?></a>
			<?php endif; ?>
			</div>
		</td>
		<td class="yts-col-actions" data-label="<?php echo $c['actions']; ?>">
			<div class="yts-cell-multi">
			<?php if ( $saved ) : ?>
				<button type="submit" form="yts-actions" name="yts_do" value="run_one:<?php echo (int) $i; ?>" class="button" title="<?php esc_attr_e( 'Sync only this program now (with what you last saved)', 'yt-to-streamit' ); ?>"><?php esc_html_e( 'Sync', 'yt-to-streamit' ); ?></button>
			<?php endif; ?>
			<button type="button" class="button yts-del"><?php esc_html_e( 'Remove', 'yt-to-streamit' ); ?></button>
			</div>
		</td>
	</tr>
	<?php
	return ob_get_clean();
}

function yts_page_programs() {
	if ( ! current_user_can( 'manage_options' ) ) return;
	$s    = yts_settings();
	$i    = 0;
	$cols = yts_program_columns();
	$kses = [ 'strong' => [] ];

	yts_admin_header( 'yts' );
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="yts_save">
		<input type="hidden" name="yts_section" value="programs">
		<?php wp_nonce_field( 'yts_save' ); ?>

		<h2><?php esc_html_e( 'Synced programs', 'yt-to-streamit' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Each Streamit program is fed by a YouTube playlist: the new videos of the playlist are imported as episodes of the program.', 'yt-to-streamit' ); ?></p>

		<div class="yts-toolbar">
			<input type="search" id="yts-list-filter" class="regular-text" placeholder="<?php esc_attr_e( 'Filter the list by name or playlist…', 'yt-to-streamit' ); ?>">
			<button type="button" class="button button-secondary" id="yts-add"><?php esc_html_e( '+ Add program', 'yt-to-streamit' ); ?></button>
		</div>

		<table class="widefat striped yts-paginated yts-stack" id="yts-table" data-cards-below="1180" data-per-page="10" data-filter="#yts-list-filter" data-next-index="<?php echo count( $s['programs'] ); ?>">
			<thead><tr>
				<th class="yts-col-active"><?php echo esc_html( $cols['active'] ); ?></th>
				<th><?php echo esc_html( $cols['playlist'] ); ?></th>
				<th><?php echo esc_html( $cols['show'] ); ?></th>
				<th><?php echo esc_html( $cols['season'] ); ?></th>
				<th><?php echo esc_html( $cols['notify'] ); ?></th>
				<th><?php echo esc_html( $cols['episodes'] ); ?></th>
				<th><?php echo esc_html( $cols['videos'] ); ?></th>
				<th><?php echo esc_html( $cols['actions'] ); ?></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $s['programs'] as $row ) { echo yts_row( $i++, $row ); } ?>
			</tbody>
		</table>
		<?php if ( ! $s['programs'] ) : ?>
			<p class="description">
				<?php
				/* translators: %s: the “+ Add program” button label */
				echo wp_kses_post( sprintf( esc_html__( 'There are no programs yet. Press %s, choose the Streamit program, paste its playlist and save.', 'yt-to-streamit' ), '<strong>' . esc_html__( '+ Add program', 'yt-to-streamit' ) . '</strong>' ) );
				?>
			</p>
		<?php endif; ?>
		<div class="description yts-help">
			<p><?php echo wp_kses( __( '<strong>Streamit program:</strong> type to search.', 'yt-to-streamit' ), $kses ); ?></p>
			<p><?php echo wp_kses( __( '<strong>Active:</strong> turn the checkmark off to stop syncing a program without deleting it.', 'yt-to-streamit' ), $kses ); ?></p>
			<p><?php echo wp_kses( __( '<strong>Season:</strong> empty = always the last one (if there is none, “Season 1” is created).', 'yt-to-streamit' ), $kses ); ?></p>
			<p><?php echo wp_kses( __( '<strong>Videos to import:</strong> “All new videos” or “Only the ones I choose”; with <strong>Choose videos</strong> you see the playlist and check which ones. Videos that are already in Streamit are skipped automatically.', 'yt-to-streamit' ), $kses ); ?></p>
			<p><?php echo wp_kses( __( '<strong>Sync</strong> (on each row) imports only that program, even if it is disabled, with what you last saved.', 'yt-to-streamit' ), $kses ); ?></p>
		</div>

		<h2><?php esc_html_e( 'Import', 'yt-to-streamit' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'Import videos from', 'yt-to-streamit' ); ?></th><td><input type="date" name="import_since" value="<?php echo esc_attr( substr( $s['import_since'], 0, 10 ) ); ?>">
				<p class="description"><?php esc_html_e( 'Only videos published on YouTube from this date on are imported (avoids bringing in the whole history).', 'yt-to-streamit' ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'Status when importing', 'yt-to-streamit' ); ?></th><td>
				<select name="post_status">
					<option value="draft" <?php selected( $s['post_status'], 'draft' ); ?>><?php esc_html_e( 'Draft (I review)', 'yt-to-streamit' ); ?></option>
					<option value="publish" <?php selected( $s['post_status'], 'publish' ); ?>><?php esc_html_e( 'Published automatically', 'yt-to-streamit' ); ?></option>
				</select></td></tr>
		</table>

		<p class="submit yts-submit">
			<?php submit_button( __( 'Save changes', 'yt-to-streamit' ), 'primary', 'submit', false ); ?>
			<button type="submit" form="yts-actions" name="yts_do" value="run_now" class="button"><?php esc_html_e( 'Sync now', 'yt-to-streamit' ); ?></button>
			<span class="description yts-save-note"><?php esc_html_e( 'Save before syncing.', 'yt-to-streamit' ); ?></span>
		</p>
	</form>

	<script type="text/template" id="yts-tpl"><?php echo yts_row( '__I__', [] ); ?></script>
	<?php
	yts_admin_footer();
}
