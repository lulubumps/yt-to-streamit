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
	yts_render_log();
	?>
	<h2>Acciones manuales</h2>
	<table class="widefat striped yts-tools">
		<tbody>
			<tr><td style="width:220px"><button type="submit" form="yts-actions" name="yts_do" value="run_now" class="button button-primary">Sincronizar ahora</button></td>
				<td>Revisa YouTube y crea los episodios nuevos de todos los programas <strong>activos</strong>, sin esperar a la tarea automática.</td></tr>
			<tr><td><button type="submit" form="yts-actions" name="yts_do" value="diag" class="button">Diagnóstico (no crea nada)</button></td>
				<td>Muestra qué haría una sincronización (qué videos importaría y cuáles omite y por qué), sin crear nada.</td></tr>
			<tr><td><button type="submit" form="yts-actions" name="yts_do" value="repair" class="button">Reparar enlaces</button></td>
				<td>Enlaza a su programa y temporada los episodios importados que no aparecen dentro del programa.</td></tr>
			<tr><td><button type="submit" form="yts-actions" name="yts_do" value="test_mail" class="button">Enviar correo de prueba</button></td>
				<td>Envía un correo al destino de los avisos para confirmar que llega (revisa también spam).</td></tr>
		</tbody>
	</table>

	<h2>Ver campos de Streamit</h2>
	<p class="description">Muestra los campos guardados de un episodio o de un programa. Útil para revisar cómo guarda Streamit sus datos.</p>
	<form method="get" action="">
		<input type="hidden" name="page" value="yts-tools">
		Episodio (ID): <input type="number" name="yts_debug" style="width:100px">
		&nbsp;o programa (ID): <input type="number" name="yts_debug_show" style="width:100px">
		<button class="button">Ver</button>
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
