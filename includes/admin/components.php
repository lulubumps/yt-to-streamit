<?php
/**
 * Componentes del panel
 *
 * Piezas que se reutilizan en varias páginas: botón Cerrar, informe de verificación y registro de sincronización.
 *
 * @package YT_To_Streamit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

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
