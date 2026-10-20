<?php
/**
 * Panel de ajustes
 *
 * Todo lo que se ve en Ajustes > YouTube a Streamit: tabla de programas, opciones, informe, registro y lista de episodios importados. Los botones envían a includes/admin-actions.php.
 *
 * @package YT_To_Streamit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action( 'admin_menu', function () {
	add_options_page( 'YouTube a Streamit', 'YouTube a Streamit', 'manage_options', 'yts', 'yts_page' );
} );

function yts_row( $i, $row, $shows ) {
	$sel = (int) ( $row['show'] ?? 0 );
	$active = ! isset( $row['active'] ) || $row['active'];
	ob_start(); ?>
	<tr<?php echo $active ? '' : ' style="opacity:.6"'; ?>>
		<td style="text-align:center"><input type="checkbox" name="programs[<?php echo $i; ?>][active]" value="1" <?php checked( $active ); ?> title="Quita la palomita para dejar de sincronizar este programa"></td>
		<td><input type="text" name="programs[<?php echo $i; ?>][playlist]" value="<?php echo esc_attr( $row['playlist'] ?? '' ); ?>" placeholder="URL o ID de la playlist" style="width:100%"></td>
		<td>
			<select name="programs[<?php echo $i; ?>][show]" style="width:100%">
				<option value="">— elegir programa —</option>
				<?php foreach ( $shows as $sh ) : ?>
					<option value="<?php echo (int) $sh->ID; ?>" <?php selected( $sel, $sh->ID ); ?>><?php echo esc_html( $sh->post_title ); ?></option>
				<?php endforeach; ?>
			</select>
		</td>
		<td><input type="number" min="1" name="programs[<?php echo $i; ?>][season]" value="<?php echo isset( $row['season'] ) && '' !== $row['season'] ? (int) $row['season'] + 1 : ''; ?>" placeholder="última" style="width:80px"></td>
		<td><input type="number" min="0" name="programs[<?php echo $i; ?>][notify_at]" value="<?php echo esc_attr( $row['notify_at'] ?? 13 ); ?>" style="width:70px"></td>
		<td><?php echo ( $sel && yts_ready() ) ? (int) yts_total_episodes( $sel ) : '—'; ?></td>
		<td style="white-space:nowrap">
			<?php if ( is_numeric( $i ) && ! empty( $row['playlist'] ) ) : ?>
				<button type="submit" form="yts-actions" name="yts_do" value="run_one:<?php echo (int) $i; ?>" class="button" title="Sincroniza solo este programa ahora">Sincronizar</button>
			<?php endif; ?>
			<button type="button" class="button yts-del">Quitar</button>
		</td>
	</tr>
	<?php
	return ob_get_clean();
}

function yts_page() {
	if ( ! current_user_can( 'manage_options' ) ) return;
	$s     = yts_settings();
	$shows = yts_shows();
	$err   = get_option( 'yts_last_mail_error' );
	$msg   = isset( $_GET['yts_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['yts_msg'] ) ) : '';
	$i     = 0;
	?>
	<div class="wrap">
		<h1>YouTube a Streamit</h1>
		<?php if ( ! yts_ready() ) echo '<div class="notice notice-error"><p><strong>El plugin Streamit no está activo</strong> (o su versión no tiene las funciones esperadas). Este plugin necesita Streamit 1.3.x.</p></div>'; ?>
		<?php if ( $msg ) echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $msg ) . '</p></div>'; ?>
		<?php if ( $err ) echo '<div class="notice notice-error"><p><strong>Último error de correo:</strong> ' . esc_html( $err ) . '</p></div>'; ?>

		<?php /* Formulario único de acciones: los botones de abajo lo usan con form="yts-actions" (ver includes/admin-actions.php) */ ?>
		<form id="yts-actions" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="yts_do">
			<?php wp_nonce_field( 'yts_do', 'yts_nonce' ); ?>
		</form>

		<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;padding:12px 16px;margin:12px 0">
			<h2 style="margin-top:0">🔎 Verificación del sistema</h2>
			<p>Revisa todo de una vez (Streamit, tablas, YouTube, miniaturas, cron, correo y cada programa) y deja un informe aquí abajo. <strong>Luego saca una captura y envíamela.</strong></p>
			<p>
				<button type="submit" form="yts-actions" name="yts_do" value="verify" class="button button-primary">Verificar todo</button>
				<button type="submit" form="yts-actions" name="yts_do" value="verify_write" class="button">Verificar + prueba de creación</button>
			</p>
			<p class="description">La segunda opción crea un episodio temporal en borrador y lo borra enseguida, para confirmar que Streamit lo acepta.</p>
			<?php yts_render_report(); ?>
		</div>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="yts_save">
			<?php wp_nonce_field( 'yts_save' ); ?>

			<h2>Programas</h2>
			<table class="widefat striped" id="yts-table">
				<thead><tr><th style="width:60px">Activo</th><th>Playlist de YouTube</th><th>Programa en Streamit</th><th>N° de temporada</th><th>Avisar en el cap.</th><th>Episodios hoy</th><th></th></tr></thead>
				<tbody>
					<?php foreach ( $s['programs'] as $row ) { echo yts_row( $i++, $row, $shows ); } ?>
				</tbody>
			</table>
			<p><button type="button" class="button" id="yts-add">+ Agregar programa</button></p>
			<p class="description"><strong>Activo:</strong> quita la palomita para dejar de sincronizar un programa sin borrarlo. <strong>Sincronizar</strong> (botón de cada fila) importa solo ese programa, incluso si está desactivado; aparece una vez que guardaste los cambios.</p>
			<p class="description">N° de temporada: deja vacío para usar siempre la última temporada del programa (si no tiene, se crea "Temporada 1").</p>

			<h2>Correo de aviso</h2>
			<p><input type="email" name="notify_email" value="<?php echo esc_attr( $s['notify_email'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>"> <span class="description">Si lo dejas vacío usa el correo del administrador.</span></p>

			<h2>Opciones</h2>
			<table class="form-table">
				<tr><th>Importar videos desde</th><td><input type="date" name="import_since" value="<?php echo esc_attr( substr( $s['import_since'], 0, 10 ) ); ?>"></td></tr>
				<tr><th>Estado al importar</th><td>
					<select name="post_status">
						<option value="draft" <?php selected( $s['post_status'], 'draft' ); ?>>Borrador (yo reviso)</option>
						<option value="publish" <?php selected( $s['post_status'], 'publish' ); ?>>Publicado automáticamente</option>
					</select></td></tr>
				<tr><th>Cómo se carga el video</th><td>
					<select name="video_method">
						<option value="episode_embed" <?php selected( $s['video_method'], 'episode_embed' ); ?>>Código embed (iframe de YouTube)</option>
						<option value="episode_url" <?php selected( $s['video_method'], 'episode_url' ); ?>>URL del video</option>
					</select>
					<p class="description">Usa la misma opción con la que ya cargas tus episodios hoy.</p></td></tr>
				<tr><th>ID de un episodio modelo</th><td><input type="number" name="template_episode" value="<?php echo (int) $s['template_episode'] ?: ''; ?>" style="width:120px"> <span class="description">Opcional pero recomendado: se copian de él los demás campos (calificación, visibilidad, etc.).</span></td></tr>
				<tr><th>Frecuencia de sincronización</th><td>
					<select name="frequency">
						<?php foreach ( yts_frequencies() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( yts_frequency(), $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description">Cada cuánto revisa YouTube sola. Si quieres importar al instante, usa «Sincronizar ahora». Recuerda que WP-Cron solo corre cuando alguien visita el sitio.</p></td></tr>
				<tr><th>Miniaturas en WebP</th><td><label><input type="checkbox" name="thumb_webp" value="1" <?php checked( $s['thumb_webp'] ); ?>> Convertir las miniaturas de YouTube a WebP (más livianas)</label>
					<p class="description">Si el servidor no soporta WebP, se guarda el JPG original y queda un aviso en el registro de errores.</p></td></tr>
				<tr><th>API key de YouTube (opcional)</th><td><?php if ( defined( 'YTS_API_KEY' ) && YTS_API_KEY ) : ?>
						<em>Definida en wp-config.php (constante YTS_API_KEY).</em>
					<?php else : ?>
						<input type="password" name="youtube_api_key" value="" class="regular-text" autocomplete="off" placeholder="<?php echo $s['youtube_api_key'] ? '•••••••• (guardada; escribe otra para reemplazarla)' : 'Pega aquí tu API key'; ?>">
						<?php if ( $s['youtube_api_key'] ) : ?><label>&nbsp;<input type="checkbox" name="clear_api_key" value="1"> Borrar la clave guardada</label><?php endif; ?>
					<?php endif; ?>
					<p class="description">Recomendada: la API de YouTube es más confiable que el feed RSS, que a veces responde 404. Es gratis (Google Cloud → YouTube Data API v3). También puedes definirla en <code>wp-config.php</code> con <code>define( 'YTS_API_KEY', 'tu-clave' );</code> para que no quede en la base de datos.</p></td></tr>
				<tr><th>ID del usuario autor</th><td><input type="number" name="author_id" value="<?php echo (int) $s['author_id'] ?: ''; ?>" style="width:120px"> <span class="description">Vacío = primer administrador.</span></td></tr>
			</table>
			<?php submit_button( 'Guardar cambios' ); ?>
		</form>

		<h2>Herramientas</h2>
		<p>
			<button type="submit" form="yts-actions" name="yts_do" value="diag" class="button">Diagnóstico (no crea nada)</button>
			<button type="submit" form="yts-actions" name="yts_do" value="run_now" class="button button-primary">Sincronizar ahora</button>
			<button type="submit" form="yts-actions" name="yts_do" value="repair" class="button">Reparar enlaces</button>
			<button type="submit" form="yts-actions" name="yts_do" value="test_mail" class="button">Enviar correo de prueba</button>
		</p>
		<form method="get" action="">
			<input type="hidden" name="page" value="yts">
			Ver campos de un episodio (ID): <input type="number" name="yts_debug" style="width:100px">
			&nbsp;o de un programa (ID): <input type="number" name="yts_debug_show" style="width:100px">
			<button class="button">Ver</button>
		</form>
		<?php
		if ( isset( $_GET['yts_debug'] ) && $_GET['yts_debug'] !== '' ) {
			echo '<pre style="background:#fff;padding:12px;overflow:auto">' . esc_html( print_r( get_metadata( 'streamit_episode', absint( $_GET['yts_debug'] ) ), true ) ) . '</pre>';
		}
		if ( isset( $_GET['yts_debug_show'] ) && $_GET['yts_debug_show'] !== '' ) {
			echo '<pre style="background:#fff;padding:12px;overflow:auto">' . esc_html( print_r( get_metadata( 'streamit_tvshow', absint( $_GET['yts_debug_show'] ) ), true ) ) . '</pre>';
		}
		?>
		<?php yts_render_log(); yts_render_imported(); ?>
	</div>

	<script type="text/template" id="yts-tpl"><?php echo yts_row( '__I__', [], $shows ); ?></script>
	<script>
	(function(){
		var n = <?php echo (int) $i; ?>, body = document.querySelector('#yts-table tbody');
		document.getElementById('yts-add').onclick = function(){
			body.insertAdjacentHTML('beforeend', document.getElementById('yts-tpl').innerHTML.replace(/__I__/g, n++));
		};
		body.addEventListener('click', function(e){
			if (e.target.classList.contains('yts-del')) e.target.closest('tr').remove();
		});
	})();
	</script>
	<?php
}

/** Botón "✕ Cerrar": oculta la ventana al instante y avisa al servidor (POST) para que no reaparezca. */
function yts_close_btn( $what, $box_id ) {
	$js = "var f=document.getElementById('yts-actions');if(f){var d=new FormData(f);d.append('yts_do','close_" . $what . "');fetch(f.action,{method:'POST',body:d,credentials:'same-origin'});}document.getElementById('" . $box_id . "').remove();";
	return '<button type="button" class="button" style="float:right;margin:0 0 8px 8px" onclick="' . esc_attr( $js ) . '">✕ Cerrar</button>';
}

function yts_render_report() {
	$rep = get_option( 'yts_last_report' );
	if ( ! $rep || empty( $rep['rows'] ) ) return;

	$icons = [ 'ok' => '✅', 'warn' => '⚠️', 'fail' => '❌', 'info' => 'ℹ️' ];
	$fails = 0; $warns = 0; $text = "INFORME YouTube a Streamit — " . $rep['time'] . "\n";
	foreach ( $rep['rows'] as $row ) {
		if ( 'fail' === $row[0] ) $fails++;
		if ( 'warn' === $row[0] ) $warns++;
		$text .= $icons[ $row[0] ] . ' ' . $row[1] . ': ' . $row[2] . "\n";
	}

	if ( $fails ) { $cls = 'notice-error';   $head = "❌ Encontré $fails problema(s) que hay que resolver."; }
	elseif ( $warns ) { $cls = 'notice-warning'; $head = "⚠️ Todo funciona, pero hay $warns advertencia(s) para revisar."; }
	else { $cls = 'notice-success'; $head = '✅ Todo en orden.'; }

	echo '<div id="yts-report-box">' . yts_close_btn( 'report', 'yts-report-box' );
	echo '<div class="notice ' . esc_attr( $cls ) . '" style="padding:12px 16px"><p style="font-size:15px"><strong>' . esc_html( $head ) . '</strong></p>';
	echo '<p style="font-size:14px">📸 <strong>Saca una captura de pantalla de este informe completo y envíamela por el chat.</strong> También puedes usar «Copiar informe» y pegarlo. No incluye contraseñas ni datos privados.</p></div>';

	echo '<table class="widefat striped" style="margin:10px 0"><tbody>';
	foreach ( $rep['rows'] as $row ) {
		echo '<tr><td style="width:34px;font-size:16px">' . $icons[ $row[0] ] . '</td><td style="width:230px"><strong>' . esc_html( $row[1] ) . '</strong></td><td>' . esc_html( $row[2] ) . '</td></tr>';
	}
	echo '</tbody></table>';
	echo '<textarea id="yts-report-text" readonly rows="6" style="width:100%;font-family:monospace;font-size:12px">' . esc_textarea( $text ) . '</textarea>';
	echo '<p><button type="button" class="button" onclick="var t=document.getElementById(\'yts-report-text\');t.select();document.execCommand(\'copy\');this.textContent=\'¡Copiado!\';">Copiar informe</button></p>';
	echo '</div>';
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

	echo '<h2 style="margin-top:28px">📚 Episodios importados</h2>';
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

		echo '<table class="widefat striped"><thead><tr><th>Temp.</th><th>Cap.</th><th>Título</th><th>Publicado en YouTube</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>';
		foreach ( array_slice( $eps, 0, 100 ) as $e ) {
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
		if ( count( $eps ) > 100 ) echo '<p class="description">Mostrando los primeros 100 de ' . count( $eps ) . '.</p>';
		echo '</details>';
	}
}

function yts_render_log() {
	$log = get_option( 'yts_last_log' );
	if ( ! $log || empty( $log['rows'] ) ) return;
	echo '<div id="yts-log-box">' . yts_close_btn( 'log', 'yts-log-box' );
	echo '<h2>Último resultado' . ( $log['dry'] ? ' (diagnóstico, no se creó nada)' : '' ) . ' — ' . esc_html( $log['time'] ) . '</h2>';
	echo '<table class="widefat striped"><thead><tr><th>Programa</th><th>Video</th><th>Título</th><th>Publicado en YouTube</th><th>Resultado</th></tr></thead><tbody>';
	foreach ( $log['rows'] as $r ) {
		echo '<tr>';
		foreach ( array_pad( $r, 5, '' ) as $cell ) echo '<td>' . esc_html( $cell ) . '</td>';
		echo '</tr>';
	}
	echo '</tbody></table>';
	echo '</div>';
}

function yts_back( $msg ) {
	wp_safe_redirect( add_query_arg( 'yts_msg', rawurlencode( $msg ), admin_url( 'options-general.php?page=yts' ) ) );
	exit;
}
