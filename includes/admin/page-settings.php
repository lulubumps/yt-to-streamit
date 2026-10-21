<?php
/**
 * Página «Ajustes»
 *
 * Opciones generales del plugin: frecuencia, correo de aviso, cómo se cargan los episodios y API key de YouTube.
 * (Las opciones de importación —fecha de inicio y estado— están en «Programas sincronizados».)
 *
 * @package YT_To_Streamit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function yts_page_settings() {
	if ( ! current_user_can( 'manage_options' ) ) return;
	$s = yts_settings();

	yts_admin_header( 'yts-settings' );
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="yts_save">
		<input type="hidden" name="yts_section" value="settings">
		<?php wp_nonce_field( 'yts_save' ); ?>

		<h2>Sincronización automática</h2>
		<table class="form-table" role="presentation">
			<tr><th>Frecuencia</th><td>
				<select name="frequency">
					<?php foreach ( yts_frequencies() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( yts_frequency(), $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description">Cada cuánto revisa YouTube sola. Para importar al instante usa «Sincronizar ahora». WP-Cron solo corre cuando alguien visita el sitio.</p></td></tr>
			<tr><th>Máximo por pasada</th><td><input type="number" min="1" max="100" name="max_per_run" value="<?php echo (int) $s['max_per_run']; ?>" style="width:80px"> episodios por programa en cada sincronización
				<p class="description">Límite para no agotar el tiempo del servidor al convertir miniaturas. Si quedan videos pendientes, el plugin continúa solo a los pocos minutos.</p></td></tr>
		</table>

		<h2>Avisos por correo</h2>
		<table class="form-table" role="presentation">
			<tr><th>Correo de aviso</th><td><input type="email" name="notify_email" value="<?php echo esc_attr( $s['notify_email'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
				<p class="description">Recibe el aviso cuando un programa llega al capítulo configurado. Vacío = correo del administrador.</p></td></tr>
		</table>

		<h2>Episodios</h2>
		<table class="form-table" role="presentation">
			<tr><th>Cómo se carga el video</th><td>
				<select name="video_method">
					<option value="episode_embed" <?php selected( $s['video_method'], 'episode_embed' ); ?>>Código embed (iframe de YouTube)</option>
					<option value="episode_url" <?php selected( $s['video_method'], 'episode_url' ); ?>>URL del video</option>
				</select>
				<p class="description">Usa la misma opción con la que ya cargas tus episodios hoy.</p></td></tr>
			<tr><th>ID de un episodio modelo</th><td><input type="number" name="template_episode" value="<?php echo (int) $s['template_episode'] ? (int) $s['template_episode'] : ''; ?>" style="width:120px">
				<p class="description">Opcional, pero recomendado: de este episodio se copian los demás campos de Streamit (calificación, visibilidad, etc.).</p></td></tr>
			<tr><th>Autor de los episodios</th><td>
				<select name="author_id">
					<option value="0">Automático (primer administrador)</option>
					<?php foreach ( yts_admins() as $u ) : ?>
						<option value="<?php echo (int) $u->ID; ?>" <?php selected( (int) $s['author_id'], (int) $u->ID ); ?>><?php echo esc_html( $u->display_name . ' (' . $u->user_login . ')' ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description">Usuario administrador al que se asignan los episodios importados.</p></td></tr>
			<tr><th>Miniaturas en WebP</th><td><label><input type="checkbox" name="thumb_webp" value="1" <?php checked( $s['thumb_webp'] ); ?>> Convertir las miniaturas de YouTube a WebP (más livianas)</label>
				<p class="description">Si el servidor no soporta WebP, se guarda el JPG original y queda un aviso en el registro de errores.</p></td></tr>
		</table>

		<h2>YouTube</h2>
		<table class="form-table" role="presentation">
			<tr><th>API key (opcional)</th><td>
				<?php if ( defined( 'YTS_API_KEY' ) && YTS_API_KEY ) : ?>
					<em>Definida en wp-config.php (constante YTS_API_KEY).</em>
				<?php else : ?>
					<input type="password" name="youtube_api_key" value="" class="regular-text" autocomplete="off" placeholder="<?php echo $s['youtube_api_key'] ? '•••••••• (guardada; escribe otra para reemplazarla)' : 'Pega aquí tu API key'; ?>">
					<?php if ( $s['youtube_api_key'] ) : ?><label>&nbsp;<input type="checkbox" name="clear_api_key" value="1"> Borrar la clave guardada</label><?php endif; ?>
				<?php endif; ?>
				<p class="description">Recomendada: la API de YouTube es más confiable que el feed RSS, que a veces responde 404. Es gratis (Google Cloud → YouTube Data API v3). También puedes definirla en <code>wp-config.php</code> con <code>define( 'YTS_API_KEY', 'tu-clave' );</code> para que no quede en la base de datos.</p></td></tr>
		</table>

		<?php submit_button( 'Guardar cambios' ); ?>
	</form>
	<?php
	yts_admin_footer();
}
