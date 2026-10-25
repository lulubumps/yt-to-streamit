<?php
/**
 * Componentes del panel
 *
 * Piezas que se reutilizan en varias páginas: botón Cerrar e informe de verificación.
 *
 * @package YT_To_Streamit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/** Botón "✕ Cerrar": oculta la ventana al instante y avisa al servidor (POST) para que no reaparezca. */
function yts_close_btn( $what, $box_id ) {
	$js = "var f=document.getElementById('yts-actions');if(f){var d=new FormData(f);d.append('yts_do','close_" . $what . "');fetch(f.action,{method:'POST',body:d,credentials:'same-origin'});}document.getElementById('" . $box_id . "').remove();";
	return '<button type="button" class="button yts-close" onclick="' . esc_attr( $js ) . '">' . esc_html__( '✕ Close', 'yt-to-streamit' ) . '</button>';
}

function yts_render_report() {
	$rep = get_option( 'yts_last_report' );
	if ( ! $rep || empty( $rep['rows'] ) ) return;

	$icons = [ 'ok' => '✅', 'warn' => '⚠️', 'fail' => '❌', 'info' => 'ℹ️' ];
	$fails = 0;
	$warns = 0;
	/* translators: %s: date and time of the report */
	$text = sprintf( __( 'YouTube to Streamit REPORT — %s', 'yt-to-streamit' ), $rep['time'] ) . "\n";
	foreach ( $rep['rows'] as $row ) {
		if ( 'fail' === $row[0] ) $fails++;
		if ( 'warn' === $row[0] ) $warns++;
		$text .= $icons[ $row[0] ] . ' ' . $row[1] . ': ' . $row[2] . "\n";
	}

	if ( $fails ) {
		$cls = 'notice-error';
		/* translators: %d: number of problems */
		$head = '❌ ' . sprintf( __( '%d problem(s) found that need to be fixed.', 'yt-to-streamit' ), $fails );
	} elseif ( $warns ) {
		$cls = 'notice-warning';
		/* translators: %d: number of warnings */
		$head = '⚠️ ' . sprintf( __( 'Everything works, but there are %d warning(s) to review.', 'yt-to-streamit' ), $warns );
	} else {
		$cls  = 'notice-success';
		$head = '✅ ' . __( 'Everything is in order.', 'yt-to-streamit' );
	}

	$copied = wp_json_encode( __( 'Copied!', 'yt-to-streamit' ) );

	echo '<div id="yts-report-box">' . yts_close_btn( 'report', 'yts-report-box' );
	echo '<div class="notice ' . esc_attr( $cls ) . ' yts-report-head"><p><strong>' . esc_html( $head ) . '</strong></p>';
	echo '<p>📸 <strong>' . esc_html__( 'Take a screenshot of this whole report (or use “Copy report” and paste it) and send it to whoever supports you.', 'yt-to-streamit' ) . '</strong> ' . esc_html__( 'It contains no passwords or private data.', 'yt-to-streamit' ) . '</p></div>';

	echo '<table class="widefat striped yts-stack yts-report"><tbody>';
	foreach ( $rep['rows'] as $row ) {
		echo '<tr><td class="yts-report-icon">' . $icons[ $row[0] ] . '</td><td class="yts-report-title"><strong>' . esc_html( $row[1] ) . '</strong></td><td class="yts-report-detail">' . esc_html( $row[2] ) . '</td></tr>';
	}
	echo '</tbody></table>';
	echo '<textarea id="yts-report-text" class="yts-report-text" readonly rows="6">' . esc_textarea( $text ) . '</textarea>';
	$js = "var t=document.getElementById('yts-report-text');t.select();document.execCommand('copy');this.textContent=" . $copied . ';';
	echo '<p><button type="button" class="button" onclick="' . esc_attr( $js ) . '">' . esc_html__( 'Copy report', 'yt-to-streamit' ) . '</button></p>';
	echo '</div>';
}
