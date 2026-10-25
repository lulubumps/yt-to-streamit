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
		<h2>🔎 <?php esc_html_e( 'System check', 'yt-to-streamit' ); ?></h2>
		<p><?php esc_html_e( 'Checks everything at once (Streamit, tables, YouTube, thumbnails, cron, email and each program) and leaves a report below. If something fails, take a screenshot of the whole report and send it to whoever supports you.', 'yt-to-streamit' ); ?></p>
		<p>
			<button type="submit" form="yts-actions" name="yts_do" value="verify" class="button button-primary"><?php esc_html_e( 'Check everything', 'yt-to-streamit' ); ?></button>
			<button type="submit" form="yts-actions" name="yts_do" value="verify_write" class="button"><?php esc_html_e( 'Check + creation test', 'yt-to-streamit' ); ?></button>
		</p>
		<p class="description"><?php esc_html_e( 'The second option creates a temporary draft episode and deletes it right away, to confirm that Streamit accepts it.', 'yt-to-streamit' ); ?></p>
	</div>
	<?php
	yts_render_report();
	yts_admin_footer();
}
