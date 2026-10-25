<?php
/**
 * Página «Herramientas»
 *
 * Acciones manuales (sincronizar, diagnóstico, reparar, correo de prueba) y visor de campos de Streamit.
 *
 * @package YT_To_Streamit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function yts_page_tools() {
	if ( ! current_user_can( 'manage_options' ) ) return;

	yts_admin_header( 'yts-tools' );
	?>
	<h2><?php esc_html_e( 'Manual actions', 'yt-to-streamit' ); ?></h2>
	<table class="widefat striped yts-tools yts-stack">
		<tbody>
			<tr><td class="yts-tool-btn"><button type="submit" form="yts-actions" name="yts_do" value="run_now" class="button button-primary"><?php esc_html_e( 'Sync now', 'yt-to-streamit' ); ?></button></td>
				<td><?php echo wp_kses( __( 'Checks YouTube and creates the new episodes of all <strong>active</strong> programs, without waiting for the automatic task.', 'yt-to-streamit' ), [ 'strong' => [] ] ); ?></td></tr>
			<tr><td class="yts-tool-btn"><button type="submit" form="yts-actions" name="yts_do" value="diag" class="button"><?php esc_html_e( 'Diagnostic (creates nothing)', 'yt-to-streamit' ); ?></button></td>
				<td><?php esc_html_e( 'Shows what a sync would do (which videos it would import and which it skips, and why), without creating anything.', 'yt-to-streamit' ); ?></td></tr>
			<tr><td class="yts-tool-btn"><button type="submit" form="yts-actions" name="yts_do" value="repair" class="button"><?php esc_html_e( 'Repair links', 'yt-to-streamit' ); ?></button></td>
				<td><?php esc_html_e( 'Links to their program and season the imported episodes that do not appear inside the program.', 'yt-to-streamit' ); ?></td></tr>
			<tr><td class="yts-tool-btn"><button type="submit" form="yts-actions" name="yts_do" value="test_mail" class="button"><?php esc_html_e( 'Send test email', 'yt-to-streamit' ); ?></button></td>
				<td><?php esc_html_e( 'Sends an email to the notification address to confirm it arrives (check spam too).', 'yt-to-streamit' ); ?></td></tr>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'View Streamit fields', 'yt-to-streamit' ); ?></h2>
	<p class="description"><?php esc_html_e( 'Shows the saved fields of an episode or a program. Useful to check how Streamit stores its data.', 'yt-to-streamit' ); ?></p>
	<form method="get" action="" class="yts-toolbar">
		<input type="hidden" name="page" value="yts-tools">
		<label><?php esc_html_e( 'Episode (ID):', 'yt-to-streamit' ); ?> <input type="number" class="yts-num yts-num-wide" name="yts_debug"></label>
		<label><?php esc_html_e( 'or program (ID):', 'yt-to-streamit' ); ?> <input type="number" class="yts-num yts-num-wide" name="yts_debug_show"></label>
		<button class="button"><?php esc_html_e( 'View', 'yt-to-streamit' ); ?></button>
	</form>
	<?php
	if ( isset( $_GET['yts_debug'] ) && '' !== $_GET['yts_debug'] ) {
		echo '<pre class="yts-pre">' . esc_html( print_r( get_metadata( 'streamit_episode', absint( $_GET['yts_debug'] ) ), true ) ) . '</pre>';
	}
	if ( isset( $_GET['yts_debug_show'] ) && '' !== $_GET['yts_debug_show'] ) {
		echo '<pre class="yts-pre">' . esc_html( print_r( get_metadata( 'streamit_tvshow', absint( $_GET['yts_debug_show'] ) ), true ) ) . '</pre>';
	}

	yts_admin_footer();
}
