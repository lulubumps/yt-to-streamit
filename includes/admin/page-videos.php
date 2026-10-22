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
	$s = yts_settings();
	$i = isset( $_GET['program'] ) && '' !== $_GET['program'] ? absint( $_GET['program'] ) : -1;

	yts_admin_header( 'yts-videos' );
	echo '<h2>Videos de la playlist</h2>';

	if ( ! $s['programs'] ) {
		echo '<p class="description">Primero agrega un programa en <a href="' . esc_url( admin_url( 'admin.php?page=yts' ) ) . '">Programas sincronizados</a>.</p>';
		yts_admin_footer();
		return;
	}

	// Selector de programa (solo lectura)
	echo '<form method="get" action="" class="yts-toolbar"><input type="hidden" name="page" value="yts-videos">';
	echo '<label for="yts-program-pick"><strong>Programa:</strong></label> <select name="program" id="yts-program-pick"><option value="">— elige un programa —</option>';
	foreach ( $s['programs'] as $k => $prog ) {
		$name = ! empty( $prog['label'] ) ? $prog['label'] : $prog['playlist'];
		echo '<option value="' . (int) $k . '"' . ( $k === $i ? ' selected' : '' ) . '>' . esc_html( $name ) . '</option>';
	}
	echo '</select> <button class="button">Ver videos</button></form>';

	if ( ! isset( $s['programs'][ $i ] ) ) {
		echo '<p class="description">Elige un programa para ver los videos de su playlist. También puedes entrar con el botón <strong>Elegir videos</strong> de cada fila en «Programas sincronizados».</p>';
		yts_admin_footer();
		return;
	}

	$p       = $s['programs'][ $i ];
	$name    = ! empty( $p['label'] ) ? $p['label'] : $p['playlist'];
	$mode    = yts_program_mode( $p );
	$refresh = ! empty( $_GET['refresh'] );
	$feed    = yts_cached_videos( $p['playlist'], $s, $refresh );
	$self    = admin_url( 'admin.php?page=yts-videos&program=' . $i );

	echo '<div class="yts-card"><p style="margin-top:0"><strong>' . esc_html( $name ) . '</strong> — modo de videos: <strong>' . ( 'manual' === $mode ? 'solo los que elijas' : 'todos los nuevos' ) . '</strong>. ';
	echo ( 'manual' === $mode )
		? 'Solo se importan los videos que marques aquí.'
		: 'Se importan todos los videos nuevos, salvo los que desmarques aquí.';
	echo ' El modo se cambia en <a href="' . esc_url( admin_url( 'admin.php?page=yts' ) ) . '">Programas sincronizados</a> (columna «Videos»).</p>';
	echo '<p style="margin-bottom:0" class="description">Los videos que <strong>ya están cargados en Streamit</strong> se detectan por su enlace o código embed (aunque el título sea distinto) y se omiten solos.</p></div>';

	if ( ! empty( $feed['error'] ) ) {
		echo '<div class="notice notice-warning inline"><p><strong>No se pudo leer la playlist:</strong> ' . esc_html( $feed['error'] ) . ' <a href="' . esc_url( add_query_arg( 'refresh', 1, $self ) ) . '">Reintentar</a></p></div>';
		yts_admin_footer();
		return;
	}
	if ( empty( $feed['entries'] ) ) {
		echo '<p class="description">La playlist no trae videos.</p>';
		yts_admin_footer();
		return;
	}

	// Clasificar cada video
	$since  = strtotime( $s['import_since'] );
	$rows   = [];
	$count  = [ 'import' => 0, 'exists' => 0, 'skip' => 0, 'old' => 0 ];
	foreach ( $feed['entries'] as $e ) {
		list( $kind, $note, $ep ) = yts_classify_video( $p, $s, $e, $since );
		$count[ $kind ]++;
		$rows[] = [ $e, $kind, $note, $ep ];
	}

	echo '<p><strong>' . count( $rows ) . ' videos</strong> (vía ' . esc_html( $feed['source'] ) . '): '
		. (int) $count['import'] . ' por importar · ' . (int) $count['exists'] . ' ya existen en Streamit · '
		. (int) $count['skip'] . ' sin seleccionar · ' . (int) $count['old'] . ' anteriores a la fecha de inicio. '
		. '<a href="' . esc_url( add_query_arg( 'refresh', 1, $self ) ) . '">Actualizar lista</a>';
	if ( 'RSS' === $feed['source'] ) echo '<br><span class="description">Sin API key solo se ven los últimos 15 videos de la playlist. Con una API key (Ajustes) se ven hasta 500.</span>';
	echo '</p>';

	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="yts_save">
		<input type="hidden" name="yts_section" value="videos">
		<input type="hidden" name="program" value="<?php echo (int) $i; ?>">
		<?php wp_nonce_field( 'yts_save' ); ?>

		<div class="yts-toolbar">
			<input type="search" id="yts-video-filter" class="regular-text" placeholder="Filtrar videos por título…">
			<button type="button" class="button" data-yts-check="all">Marcar todos</button>
			<button type="button" class="button" data-yts-check="none">Desmarcar todos</button>
		</div>

		<table class="widefat striped yts-paginated" id="yts-videos-table" data-per-page="25" data-filter="#yts-video-filter">
			<thead><tr><th style="width:60px">Importar</th><th style="width:70px"></th><th>Título en YouTube</th><th>Publicado</th><th>Estado</th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $r ) :
				list( $e, $kind, $note, $ep ) = $r;
				$locked = ( 'exists' === $kind );
				?>
				<tr>
					<td>
						<?php if ( $locked ) : ?>
							<input type="checkbox" disabled title="Ya existe en Streamit">
						<?php else : ?>
							<input type="checkbox" name="pick[]" value="<?php echo esc_attr( $e['id'] ); ?>" <?php checked( 'import' === $kind ); ?>>
							<input type="hidden" name="listed[]" value="<?php echo esc_attr( $e['id'] ); ?>">
						<?php endif; ?>
					</td>
					<td><img src="<?php echo esc_url( 'https://i.ytimg.com/vi/' . $e['id'] . '/default.jpg' ); ?>" width="60" alt="" loading="lazy"></td>
					<td><a href="<?php echo esc_url( 'https://www.youtube.com/watch?v=' . $e['id'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $e['title'] ); ?></a></td>
					<td><?php echo esc_html( get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $e['published'] ), 'Y-m-d' ) ); ?></td>
					<td>
						<?php
						if ( $locked ) {
							echo '✅ Ya existe en Streamit';
							if ( $ep ) echo ': <a href="' . esc_url( admin_url( 'admin.php?page=streamit-edit-tvshow-episode&id=' . (int) $ep['id'] ) ) . '">' . esc_html( $ep['title'] ) . ' (#' . (int) $ep['id'] . ')</a>';
							else echo ' (importado por este plugin)';
						} elseif ( 'import' === $kind ) {
							echo '🟢 Se importará';
						} else {
							echo '⚪ ' . esc_html( ucfirst( $note ) );
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<p class="submit">
			<button type="submit" class="button button-primary">Guardar selección</button>
			<button type="submit" name="yts_after" value="sync" class="button">Guardar e importar los seleccionados ahora</button>
		</p>
		<p class="description">Guarda tu elección antes de salir de la página. Si marcas un video publicado antes de «Importar videos desde», se importa igual: tu selección manda sobre la fecha.</p>
	</form>
	<?php
	yts_admin_footer();
}
