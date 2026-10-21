<?php
/**
 * Página «Verificación del sistema»
 *
 * Botones para correr la verificación (includes/diagnostics.php) y mostrar el informe.
 *
 * @package YT_To_Streamit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function yts_page_verify() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	yts_admin_header( 'yts-verify' );
	?>
	<div class="yts-card">
		<h2 style="margin-top:0">🔎 Verificación del sistema</h2>
		<p>Revisa todo de una vez (Streamit, tablas, YouTube, miniaturas, cron, correo y cada programa) y deja un informe aquí abajo. Si algo falla, saca una captura del informe completo y envíala a quien te da soporte.</p>
		<p>
			<button type="submit" form="yts-actions" name="yts_do" value="verify" class="button button-primary">Verificar todo</button>
			<button type="submit" form="yts-actions" name="yts_do" value="verify_write" class="button">Verificar + prueba de creación</button>
		</p>
		<p class="description">La segunda opción crea un episodio temporal en borrador y lo borra enseguida, para confirmar que Streamit lo acepta.</p>
	</div>
	<?php
	yts_render_report();
	yts_admin_footer();
}
