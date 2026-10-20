<?php
/**
 * Verificación del sistema
 *
 * Corre todas las comprobaciones (Streamit, tablas, YouTube, miniaturas, cron, correo, programas) y devuelve filas [nivel, título, detalle].
 *
 * @package YT_To_Streamit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Corre todas las comprobaciones. Devuelve filas [nivel, título, detalle]; nivel: ok | warn | fail | info
 * Con $write = true además crea y borra un episodio temporal para probar la escritura en Streamit.
 */
function yts_checks( $write = false ) {
	global $wpdb;
	$r   = [];
	$add = function ( $level, $title, $detail = '' ) use ( &$r ) { $r[] = [ $level, $title, $detail ]; };
	$s   = yts_settings();

	// --- Entorno ---
	$add( 'info', 'Entorno', 'WordPress ' . get_bloginfo( 'version' ) . ' · PHP ' . PHP_VERSION . ' · zona horaria ' . wp_timezone_string() . ' · plugin YouTube a Streamit ' . YTS_VERSION . '' );

	// --- Streamit ---
	if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$found = false;
	foreach ( get_plugins() as $file => $data ) {
		if ( 'streamit-core' === ( $data['TextDomain'] ?? '' ) ) {
			$found = true;
			if ( is_plugin_active( $file ) ) $add( 'ok', 'Plugin Streamit', 'Activo, versión ' . $data['Version'] );
			else $add( 'fail', 'Plugin Streamit', 'Está instalado (v' . $data['Version'] . ') pero INACTIVO. Actívalo.' );
			break;
		}
	}
	if ( ! $found ) $add( 'fail', 'Plugin Streamit', 'No lo encuentro entre los plugins instalados.' );

	$fns     = [ 'streamit_add_episode', 'streamit_add_episode_meta', 'streamit_delete_episode', 'streamit_get_tvshow_meta', 'streamit_add_tvshow_meta', 'streamit_update_tvshow_meta' ];
	$missing = [];
	foreach ( $fns as $f ) if ( ! function_exists( $f ) ) $missing[] = $f;
	if ( $missing ) $add( 'fail', 'Funciones de Streamit', 'Faltan: ' . implode( ', ', $missing ) . '. Probablemente otra versión de Streamit.' );
	else $add( 'ok', 'Funciones de Streamit', 'Todas las funciones necesarias existen.' );

	$tables_ok = true;
	foreach ( [ 'streamit_episode', 'streamit_episodemeta', 'streamit_tvshow', 'streamit_tvshowmeta' ] as $t ) {
		$name = isset( $wpdb->$t ) ? $wpdb->$t : '';
		if ( ! $name || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) !== $name ) {
			$tables_ok = false;
			$add( 'fail', "Tabla $t", 'No existe en la base de datos.' );
		}
	}
	if ( $tables_ok ) {
		$ne = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->streamit_episode}" );
		$nt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->streamit_tvshow}" );
		$add( 'ok', 'Tablas de Streamit', "Existen. Hay $nt programa(s) y $ne episodio(s) en total." );
	}

	// --- Requisitos técnicos ---
	if ( function_exists( 'simplexml_load_string' ) ) $add( 'ok', 'Lector XML (SimpleXML)', 'Disponible.' );
	else $add( 'fail', 'Lector XML (SimpleXML)', 'No está instalado en PHP; sin esto no se puede leer YouTube. Pide al hosting activar la extensión php-xml.' );

	if ( wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] ) ) $add( 'ok', 'Soporte WebP', 'El servidor puede convertir imágenes a WebP.' );
	else $add( $s['thumb_webp'] ? 'warn' : 'info', 'Soporte WebP', 'El servidor NO soporta WebP (GD/Imagick). Las miniaturas se guardarán en JPG.' );

	// --- Cron ---
	$next = wp_next_scheduled( 'yts_sync' );
	if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
		$add( 'warn', 'Tarea automática (cron)', 'WP-Cron está desactivado en wp-config.php. Solo funciona si el hosting ejecuta wp-cron.php con un cron real; si no, usa "Sincronizar ahora".' );
	} elseif ( $next ) {
		$add( 'ok', 'Tarea automática (cron)', 'Programada: ' . strtolower( yts_frequencies()[ yts_frequency() ] ) . '. Próxima ejecución: ' . get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ) ) . '. (Solo corre cuando alguien visita el sitio.)' );
	} else {
		$add( 'fail', 'Tarea automática (cron)', 'No está programada. Desactiva y vuelve a activar el plugin.' );
	}

	// --- Correo ---
	$to = yts_mail_to( $s );
	if ( ! is_email( $to ) ) $add( 'fail', 'Correo de aviso', 'La dirección configurada no es válida.' );
	else {
		$err = get_option( 'yts_last_mail_error' );
		$add( $err ? 'fail' : 'ok', 'Correo de aviso', 'Destino: ' . yts_mask_email( $to ) . ( $err ? '. Último error de envío: ' . $err : '. Usa "Enviar correo de prueba" para confirmar que llega.' ) );
	}

	// --- Ajustes ---
	$add( ! yts_api_key( $s ) ? 'info' : 'ok', 'API key de YouTube', ! yts_api_key( $s ) ? 'No configurada. Es opcional, pero recomendada: el feed RSS de YouTube falla de forma intermitente.' : 'Configurada (se usa primero; el RSS queda de respaldo).' );
	$add( 'info', 'Ajustes', 'Importar desde ' . substr( $s['import_since'], 0, 10 ) . ' · estado: ' . $s['post_status'] . ' · video: ' . $s['video_method'] . ' · miniaturas WebP: ' . ( $s['thumb_webp'] ? 'sí' : 'no' ) );

	if ( $s['template_episode'] ) {
		$m = get_metadata( 'streamit_episode', (int) $s['template_episode'] );
		if ( $m ) $add( 'ok', 'Episodio modelo', 'El episodio #' . (int) $s['template_episode'] . ' existe y tiene ' . count( $m ) . ' campos para copiar.' );
		else $add( 'warn', 'Episodio modelo', 'El episodio #' . (int) $s['template_episode'] . ' no existe o no tiene campos.' );
	} else {
		$add( 'warn', 'Episodio modelo', 'No hay uno configurado. Es opcional, pero recomendado para copiar los demás campos de Streamit.' );
	}

	$aid = yts_author( $s );
	$add( get_userdata( $aid ) ? 'ok' : 'fail', 'Usuario autor', get_userdata( $aid ) ? "Se usará el usuario #$aid." : "El usuario #$aid no existe." );

	// --- Programas ---
	if ( ! $s['programs'] ) {
		$add( 'fail', 'Programas', 'No hay ningún programa configurado. Agrega uno y guarda.' );
	}
	$since = strtotime( $s['import_since'] );
	$n_active = 0;
	foreach ( $s['programs'] as $p ) if ( ! isset( $p['active'] ) || $p['active'] ) $n_active++;
	if ( $s['programs'] && ! $n_active ) $add( 'warn', 'Programas', 'Todos los programas están desactivados: no se sincroniza nada de forma automática.' );

	foreach ( $s['programs'] as $p ) {
		$name = ! empty( $p['label'] ) ? $p['label'] : $p['playlist'];
		if ( isset( $p['active'] ) && ! $p['active'] ) {
			$add( 'info', "Programa «{$name}»", 'Desactivado: no se sincroniza ni se consulta YouTube.' );
			continue;
		}

		$show = $tables_ok ? $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_title FROM {$wpdb->streamit_tvshow} WHERE ID = %d", (int) $p['show'] ) ) : null;
		if ( ! $show ) {
			$add( 'fail', "Programa «{$name}»", 'El TV Show #' . (int) $p['show'] . ' no existe en Streamit.' );
		} elseif ( yts_ready() ) {
			$seasons = yts_seasons( $show->ID );
			$total   = yts_total_episodes( $show->ID );
			$goal    = (int) ( $p['notify_at'] ?? 0 );
			$extra   = $goal ? ( $total >= $goal ? ". Ya alcanzó el capítulo $goal." : '. Aviso al llegar al capítulo ' . $goal . ' (faltan ' . ( $goal - $total ) . ').' ) : '. Sin aviso configurado.';
			$add( 'ok', "Programa «{$show->post_title}»", count( $seasons ) . ' temporada(s), ' . $total . ' episodio(s)' . $extra );
			$un = yts_unlinked_ids( $show->ID );
			if ( $un ) $add( 'fail', "Enlace episodios ↔ programa «{$show->post_title}»", count( $un ) . ' episodio(s) importado(s) no aparecen dentro del programa. Presiona «Reparar enlaces».' );
		}

		$feed = yts_fetch_videos( $p['playlist'], $s );
		if ( $feed['error'] ) {
			$is404 = false !== strpos( $feed['error'], 'HTTP 404' );
			$isq   = false !== stripos( $feed['error'], 'quota' );
			$extra = '';
			if ( $isq )   $extra .= ' La cuota diaria de la API de YouTube está agotada: se reinicia a medianoche (hora del Pacífico de EE. UU.). Revisa en Google Cloud → APIs y servicios → YouTube Data API v3 → Cuotas qué la está consumiendo.';
			if ( $is404 ) $extra .= ' El feed RSS de YouTube falla de forma intermitente (a veces por horas); no es un problema de tu playlist. Se reintenta solo cada 30 min.';
			$add( ( $is404 || $isq ) ? 'warn' : 'fail', "Playlist de «{$name}»", 'No se pudo leer: ' . $feed['error'] . '.' . $extra );
			continue;
		}
		$n = count( $feed['entries'] ); $new = 0; $old = 0; $done = 0;
		foreach ( $feed['entries'] as $e ) {
			if ( $tables_ok && yts_exists( $e['id'] ) ) $done++;
			elseif ( $e['published'] < $since ) $old++;
			else $new++;
		}
		$detail = "YouTube entrega $n video(s) (vía " . $feed['source'] . "): $new por importar, $done ya importado(s), $old publicado(s) antes de la fecha de inicio.";
		if ( ! $n ) $add( 'warn', "Playlist de «{$name}»", 'Se leyó bien pero no trae videos.' );
		elseif ( 0 === $new && $old > 0 && 0 === $done ) $add( 'warn', "Playlist de «{$name}»", $detail . ' Si esperabas importar alguno, cambia "Importar videos desde" a una fecha anterior.' );
		else $add( 'ok', "Playlist de «{$name}»", $detail );

		if ( $n ) {
			$head = wp_remote_head( 'https://i.ytimg.com/vi/' . $feed['entries'][0]['id'] . '/hqdefault.jpg', [ 'timeout' => 15 ] );
			$ok   = ! is_wp_error( $head ) && 200 === wp_remote_retrieve_response_code( $head );
			$add( $ok ? 'ok' : 'warn', "Miniaturas de «{$name}»", $ok ? 'Se pueden descargar desde YouTube.' : 'No pude descargar una miniatura de prueba desde el servidor.' );
		}
	}

	// --- Prueba de escritura real (opcional) ---
	if ( $write ) {
		if ( ! yts_ready() || ! $tables_ok ) {
			$add( 'fail', 'Prueba de creación', 'Omitida: Streamit no está listo (ver errores de arriba).' );
		} else {
			$tmp = streamit_add_episode( [
				'post_author' => $aid,
				'post_title'  => '[yts prueba] borrar',
				'post_status' => 'draft',
				'post_name'   => 'yts-prueba-' . strtolower( wp_generate_password( 8, false, false ) ),
			] );
			if ( is_wp_error( $tmp ) || ! $tmp ) {
				$add( 'fail', 'Prueba de creación', 'Streamit rechazó el episodio de prueba: ' . ( is_wp_error( $tmp ) ? $tmp->get_error_message() : 'sin ID' ) );
			} else {
				streamit_add_episode_meta( $tmp, '_yts_video_id', '__prueba__' );
				$stored = get_metadata( 'streamit_episode', $tmp, '_yts_video_id', true );
				delete_metadata( 'streamit_episode', $tmp, '_yts_video_id' );
				streamit_delete_episode( $tmp );
				$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->streamit_episode} WHERE ID = %d", $tmp ) );
				if ( '__prueba__' === $stored && 0 === $left ) $add( 'ok', 'Prueba de creación', "Se creó y se borró un episodio temporal (#$tmp). Streamit acepta los episodios que crea este plugin." );
				else $add( 'warn', 'Prueba de creación', "El episodio #$tmp se creó pero no quedó todo en orden (meta guardada: " . ( '__prueba__' === $stored ? 'sí' : 'no' ) . ', borrado: ' . ( $left ? 'no, bórralo a mano desde Streamit' : 'sí' ) . ').' );
			}
		}
	}

	return $r;
}
