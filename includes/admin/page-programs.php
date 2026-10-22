<?php
/**
 * Página «Programas sincronizados» (la principal)
 *
 * Tabla de programas (playlist de YouTube ↔ programa de Streamit) con buscador, paginación,
 * activar/desactivar y sincronización individual, más las opciones de importación.
 * El comportamiento del selector con buscador y de la paginación está en assets/admin.js.
 *
 * @package YT_To_Streamit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Una fila de la tabla. Con $i = '__I__' se genera la plantilla para «Agregar programa». */
function yts_row( $i, $row ) {
	$sel    = (int) ( $row['show'] ?? 0 );
	$active = ! isset( $row['active'] ) || $row['active'];
	$title  = $sel ? yts_show_title( $sel ) : '';
	if ( ! $title && ! empty( $row['label'] ) ) $title = $row['label'];

	ob_start(); ?>
	<tr class="<?php echo $active ? '' : 'yts-off'; ?>">
		<td class="yts-col-active"><input type="checkbox" name="programs[<?php echo $i; ?>][active]" value="1" <?php checked( $active ); ?> title="Quita la palomita para dejar de sincronizar este programa"></td>
		<td><input type="text" class="yts-wide" name="programs[<?php echo $i; ?>][playlist]" value="<?php echo esc_attr( $row['playlist'] ?? '' ); ?>" placeholder="URL o ID de la playlist"></td>
		<td>
			<div class="yts-combo">
				<input type="text" class="yts-combo-input" value="<?php echo esc_attr( $title ); ?>" placeholder="Buscar programa de Streamit…" autocomplete="off" aria-label="Programa de Streamit">
				<input type="hidden" name="programs[<?php echo $i; ?>][show]" value="<?php echo $sel ? $sel : ''; ?>">
				<div class="yts-combo-list" hidden></div>
			</div>
		</td>
		<td><input type="number" min="1" name="programs[<?php echo $i; ?>][season]" value="<?php echo isset( $row['season'] ) && '' !== $row['season'] ? (int) $row['season'] + 1 : ''; ?>" placeholder="última" style="width:80px"></td>
		<td><input type="number" min="0" name="programs[<?php echo $i; ?>][notify_at]" value="<?php echo esc_attr( $row['notify_at'] ?? 13 ); ?>" style="width:70px"></td>
		<td><?php echo ( $sel && yts_ready() ) ? (int) yts_total_episodes( $sel ) : '—'; ?></td>
		<td class="yts-col-videos">
			<select name="programs[<?php echo $i; ?>][mode]" title="Todos: se importan todos los videos nuevos. Solo los que elija: se importan únicamente los que marques en «Videos de la playlist».">
				<option value="auto" <?php selected( yts_program_mode( $row ), 'auto' ); ?>>Todos los nuevos</option>
				<option value="manual" <?php selected( yts_program_mode( $row ), 'manual' ); ?>>Solo los que elija</option>
			</select>
			<?php if ( is_numeric( $i ) && ! empty( $row['playlist'] ) ) : ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=yts-videos&program=' . (int) $i ) ); ?>">Elegir videos</a>
			<?php endif; ?>
		</td>
		<td class="yts-col-actions">
			<?php if ( is_numeric( $i ) && ! empty( $row['playlist'] ) ) : ?>
				<button type="submit" form="yts-actions" name="yts_do" value="run_one:<?php echo (int) $i; ?>" class="button" title="Sincroniza solo este programa ahora (con lo último que guardaste)">Sincronizar</button>
			<?php endif; ?>
			<button type="button" class="button yts-del">Quitar</button>
		</td>
	</tr>
	<?php
	return ob_get_clean();
}

function yts_page_programs() {
	if ( ! current_user_can( 'manage_options' ) ) return;
	$s = yts_settings();
	$i = 0;

	yts_admin_header( 'yts' );
	yts_render_log();
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="yts_save">
		<input type="hidden" name="yts_section" value="programs">
		<?php wp_nonce_field( 'yts_save' ); ?>

		<h2>Programas sincronizados</h2>
		<p class="description">Cada programa de Streamit se alimenta de una playlist de YouTube: los videos nuevos de la playlist se importan como episodios del programa.</p>

		<div class="yts-toolbar">
			<input type="search" id="yts-list-filter" class="regular-text" placeholder="Filtrar la lista por nombre o playlist…">
			<button type="button" class="button button-secondary" id="yts-add">+ Agregar programa</button>
		</div>

		<table class="widefat striped yts-paginated" id="yts-table" data-per-page="10" data-filter="#yts-list-filter" data-next-index="<?php echo count( $s['programs'] ); ?>">
			<thead><tr>
				<th class="yts-col-active">Activo</th><th>Playlist de YouTube</th><th>Programa de Streamit</th><th>N° de temporada</th><th>Avisar en el cap.</th><th>Episodios hoy</th><th>Videos a importar</th><th></th>
			</tr></thead>
			<tbody>
				<?php foreach ( $s['programs'] as $row ) { echo yts_row( $i++, $row ); } ?>
			</tbody>
		</table>
		<?php if ( ! $s['programs'] ) : ?>
			<p class="description">Todavía no hay programas. Presiona <strong>+ Agregar programa</strong>, elige el programa de Streamit, pega su playlist y guarda.</p>
		<?php endif; ?>
		<p class="description">
			<strong>Programa de Streamit:</strong> escribe para buscar. <strong>Activo:</strong> quita la palomita para dejar de sincronizar un programa sin borrarlo.
			<strong>Temporada:</strong> vacío = siempre la última (si no hay, se crea «Temporada 1»).
			<strong>Videos a importar:</strong> «Todos los nuevos» o «Solo los que elija»; con <strong>Elegir videos</strong> ves la playlist y marcas cuáles. Los videos que ya están en Streamit se omiten solos.
			<strong>Sincronizar</strong> (cada fila) importa solo ese programa, incluso si está desactivado, con lo último que guardaste.
		</p>

		<h2>Importación</h2>
		<table class="form-table" role="presentation">
			<tr><th>Importar videos desde</th><td><input type="date" name="import_since" value="<?php echo esc_attr( substr( $s['import_since'], 0, 10 ) ); ?>">
				<p class="description">Solo se importan videos publicados en YouTube desde esta fecha (evita traer todo el historial).</p></td></tr>
			<tr><th>Estado al importar</th><td>
				<select name="post_status">
					<option value="draft" <?php selected( $s['post_status'], 'draft' ); ?>>Borrador (yo reviso)</option>
					<option value="publish" <?php selected( $s['post_status'], 'publish' ); ?>>Publicado automáticamente</option>
				</select></td></tr>
		</table>

		<p class="submit">
			<?php submit_button( 'Guardar cambios', 'primary', 'submit', false ); ?>
			<button type="submit" form="yts-actions" name="yts_do" value="run_now" class="button">Sincronizar ahora</button>
			<span class="description">&nbsp;Guarda antes de sincronizar.</span>
		</p>
	</form>

	<script type="text/template" id="yts-tpl"><?php echo yts_row( '__I__', [] ); ?></script>
	<?php
	yts_admin_footer();
}
