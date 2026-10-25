<?php
/**
 * Página «Registro de sincronización»
 *
 * El resultado de la última sincronización o diagnóstico, con filtro por tipo, búsqueda y paginación.
 * Antes aparecía arriba en todas las páginas; ahora tiene su propio lugar y el resto del panel solo muestra
 * un aviso breve con un enlace aquí. El registro lo guarda includes/importer.php en la opción `yts_last_log`.
 *
 * @package YT_To_Streamit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Tipos de resultado de una fila del registro (código interno => texto). */
function yts_log_kinds() {
	return [
		'created' => __( 'Created', 'yt-to-streamit' ),
		'skipped' => __( 'Skipped', 'yt-to-streamit' ),
		'pending' => __( 'Pending', 'yt-to-streamit' ),
		'dry'     => __( 'Would import', 'yt-to-streamit' ),
		'error'   => __( 'Errors', 'yt-to-streamit' ),
		'info'    => __( 'Notes', 'yt-to-streamit' ),
	];
}

function yts_page_log() {
	if ( ! current_user_can( 'manage_options' ) ) return;
	$log = get_option( 'yts_last_log' );

	yts_admin_header( 'yts-log' );
	echo '<h2>' . esc_html__( 'Sync log', 'yt-to-streamit' ) . '</h2>';

	if ( ! $log || empty( $log['rows'] ) ) {
		echo '<p class="description">' . esc_html__( 'There is no sync result yet. Run a sync or a diagnostic and the result will appear here.', 'yt-to-streamit' ) . '</p>';
		yts_admin_footer();
		return;
	}

	$kinds = yts_log_kinds();
	$rows  = $log['rows'];
	$count = array_fill_keys( array_keys( $kinds ), 0 );
	foreach ( $rows as $r ) {
		$code = ( isset( $r[5] ) && isset( $kinds[ $r[5] ] ) ) ? $r[5] : 'info';
		$count[ $code ]++;
	}

	if ( $log['dry'] ) {
		/* translators: %s: date and time */
		$title = sprintf( __( 'Last result (diagnostic, nothing was created) — %s', 'yt-to-streamit' ), $log['time'] );
	} else {
		/* translators: %s: date and time */
		$title = sprintf( __( 'Last result — %s', 'yt-to-streamit' ), $log['time'] );
	}

	echo '<div class="yts-card"><p><strong>' . esc_html( $title ) . '</strong></p><p class="yts-log-summary">';
	$parts = [];
	foreach ( $count as $code => $n ) if ( $n ) $parts[] = '<span class="yts-kind yts-kind-' . esc_attr( $code ) . '">' . esc_html( $kinds[ $code ] ) . ': <strong>' . (int) $n . '</strong></span>';
	echo implode( ' · ', $parts ); // cada pieza ya está escapada arriba
	echo '</p><p class="description">' . esc_html__( 'Only the last result is kept: every sync, including the automatic ones, replaces it.', 'yt-to-streamit' ) . '</p></div>';

	$l = [
		'program'   => esc_attr__( 'Program', 'yt-to-streamit' ),
		'video'     => esc_attr__( 'Video', 'yt-to-streamit' ),
		'title'     => esc_attr__( 'Title', 'yt-to-streamit' ),
		'published' => esc_attr__( 'Published on YouTube', 'yt-to-streamit' ),
		'result'    => esc_attr__( 'Result', 'yt-to-streamit' ),
	];
	?>
	<div class="yts-toolbar">
		<input type="search" id="yts-log-filter" class="regular-text" placeholder="<?php esc_attr_e( 'Filter the log…', 'yt-to-streamit' ); ?>">
		<select id="yts-log-kind" aria-label="<?php esc_attr_e( 'Result type', 'yt-to-streamit' ); ?>">
			<option value=""><?php esc_html_e( 'All results', 'yt-to-streamit' ); ?></option>
			<?php foreach ( $kinds as $code => $label ) : if ( ! $count[ $code ] ) continue; ?>
				<option value="<?php echo esc_attr( $code ); ?>"><?php echo esc_html( $label ); ?></option>
			<?php endforeach; ?>
		</select>
		<button type="submit" form="yts-actions" name="yts_do" value="clear_log" class="button"><?php esc_html_e( 'Clear log', 'yt-to-streamit' ); ?></button>
	</div>

	<table class="widefat striped yts-paginated yts-stack" id="yts-log-table" data-per-page="25" data-filter="#yts-log-filter" data-filter-select="#yts-log-kind">
		<thead><tr><th><?php echo esc_html( $l['program'] ); ?></th><th><?php echo esc_html( $l['video'] ); ?></th><th><?php echo esc_html( $l['title'] ); ?></th><th><?php echo esc_html( $l['published'] ); ?></th><th><?php echo esc_html( $l['result'] ); ?></th></tr></thead>
		<tbody>
		<?php foreach ( $rows as $r ) :
			$r    = array_pad( $r, 6, '' );
			$code = isset( $kinds[ $r[5] ] ) ? $r[5] : 'info';
			?>
			<tr data-code="<?php echo esc_attr( $code ); ?>">
				<td data-label="<?php echo $l['program']; ?>"><?php echo esc_html( $r[0] ); ?></td>
				<td data-label="<?php echo $l['video']; ?>"><?php echo esc_html( $r[1] ); ?></td>
				<td data-label="<?php echo $l['title']; ?>"><?php echo esc_html( $r[2] ); ?></td>
				<td data-label="<?php echo $l['published']; ?>"><?php echo esc_html( $r[3] ); ?></td>
				<td data-label="<?php echo $l['result']; ?>"><?php echo esc_html( $r[4] ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
	yts_admin_footer();
}
