<?php
/**
 * Plugin Name: YouTube a Streamit (episodios automáticos)
 * Description: Importa videos de playlists de YouTube como episodios de Streamit (en las tablas propias de Streamit) y avisa por correo al llegar a un capítulo. Se configura en Ajustes > YouTube a Streamit.
 * Version: 3.0.4
 * Plugin URI: https://lulubumps.cl
 * Author: Lulu Sánchez
 * Author URI: https://www.linkedin.com/in/lulu-sanchez-suazo/
 * Requires Plugins: streamit
 */

if ( ! defined( 'ABSPATH' ) ) exit;
 
/* ============================================================
 * AJUSTES
 * ============================================================ */
function yts_settings() {
	$defaults = [
		'programs'         => [], // cada uno: playlist, show, label, notify_at, season
		'notify_email'     => '',
		'import_since'     => gmdate( 'Y-m-d' ) . ' 00:00:00',
		'post_status'      => 'draft',
		'video_method'     => 'episode_embed', // episode_embed | episode_url
		'template_episode' => 0,               // ID de un episodio modelo del cual se copian los demás campos
		'author_id'        => 0,
		'thumb_webp'       => 1,               // convertir miniaturas a WebP
		'youtube_api_key'  => '',              // opcional: API de YouTube (más confiable que el feed RSS)
	];
	return wp_parse_args( get_option( 'yts_settings', [] ), $defaults );
}
 
function yts_parse_playlist( $input ) {
	$input = trim( $input );
	if ( preg_match( '/[?&]list=([A-Za-z0-9_-]+)/', $input, $m ) ) return $m[1];
	return preg_replace( '/[^A-Za-z0-9_-]/', '', $input );
}
 
function yts_ready() {
	return function_exists( 'streamit_add_episode' )
		&& function_exists( 'streamit_add_episode_meta' )
		&& function_exists( 'streamit_get_tvshow_meta' )
		&& function_exists( 'streamit_update_tvshow_meta' );
}
 
/* ============================================================
 * CRON: cada 30 minutos
 * ============================================================ */
add_filter( 'cron_schedules', function ( $s ) {
	$s['yts_30min'] = [ 'interval' => 1800, 'display' => 'Cada 30 minutos' ];
	return $s;
} );
register_activation_hook( __FILE__, function () {
	if ( ! wp_next_scheduled( 'yts_sync' ) ) wp_schedule_event( time() + 60, 'yts_30min', 'yts_sync' );
} );
register_deactivation_hook( __FILE__, function () {
	wp_clear_scheduled_hook( 'yts_sync' );
} );
add_action( 'yts_sync', 'yts_run' );
 
add_action( 'wp_mail_failed', function ( $err ) {
	update_option( 'yts_last_mail_error', $err->get_error_message() );
} );
 
/* ============================================================
 * AYUDANTES DE STREAMIT (tablas propias)
 * ============================================================ */
function yts_shows() {
	global $wpdb;
	if ( empty( $wpdb->streamit_tvshow ) ) return [];
	return $wpdb->get_results( "SELECT ID, post_title FROM {$wpdb->streamit_tvshow} WHERE post_status IN ('publish','draft','private','pending') ORDER BY post_title ASC" );
}
 
function yts_seasons( $show_id ) {
	$seasons = streamit_get_tvshow_meta( $show_id, '_seasons' );
	return is_array( $seasons ) ? $seasons : [];
}
 
function yts_total_episodes( $show_id ) {
	if ( ! yts_ready() ) return 0;
	$n = 0;
	foreach ( yts_seasons( $show_id ) as $season ) {
		$n += ! empty( $season['episodes'] ) ? count( (array) $season['episodes'] ) : 0;
	}
	return $n;
}
 
function yts_exists( $video_id ) {
	global $wpdb;
	return (bool) $wpdb->get_var( $wpdb->prepare(
		"SELECT streamit_episode_id FROM {$wpdb->streamit_episodemeta} WHERE meta_key = '_yts_video_id' AND meta_value = %s LIMIT 1",
		$video_id
	) );
}
 
function yts_author( $s ) {
	if ( $s['author_id'] ) return (int) $s['author_id'];
	$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
	return $admins ? (int) $admins[0] : 1;
}
 
function yts_sideload( $video_id, $title, $s = [] ) {
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';
 
	$want_webp = ! isset( $s['thumb_webp'] ) || $s['thumb_webp'];
	$can_webp  = $want_webp && wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] );
	if ( $want_webp && ! $can_webp ) {
		error_log( '[yts] Este servidor no soporta WebP (GD/Imagick); se usará JPG.' );
	}
 
	foreach ( [ 'maxresdefault', 'hqdefault' ] as $q ) {
		$tmp = download_url( "https://i.ytimg.com/vi/$video_id/$q.jpg", 30 );
		if ( is_wp_error( $tmp ) ) continue;
 
		$name = sanitize_file_name( 'yt-' . $video_id . '-' . $q );
		$file = [ 'name' => $name . '.jpg', 'tmp_name' => $tmp ];
 
		if ( $can_webp ) {
			$editor = wp_get_image_editor( $tmp );
			if ( ! is_wp_error( $editor ) ) {
				$editor->set_quality( 82 );
				$webp_tmp = wp_tempnam( $name . '.webp' );
				$saved    = $editor->save( $webp_tmp, 'image/webp' );
				if ( ! is_wp_error( $saved ) && ! empty( $saved['path'] ) && file_exists( $saved['path'] ) ) {
					@unlink( $tmp );
					$file = [ 'name' => $name . '.webp', 'tmp_name' => $saved['path'] ];
				}
			}
		}
 
		$id = media_handle_sideload( $file, 0, $title );
		if ( is_wp_error( $id ) ) {
			@unlink( $file['tmp_name'] );
			continue;
		}
		return (int) $id;
	}
	return 0;
}
 
/* ============================================================
 * SINCRONIZACIÓN
 * ============================================================ */
function yts_run( $dry = false, $only = null ) {
	$log = [];
	if ( ! yts_ready() ) {
		$log[] = [ '—', '', '', '', 'ERROR: el plugin Streamit no está activo o no tiene las funciones esperadas.' ];
	} else {
		$s = yts_settings();
		if ( ! $s['programs'] ) {
			$log[] = [ '—', '', '', '', 'No hay programas configurados. Agrega uno y guarda.' ];
		}
		foreach ( $s['programs'] as $i => $p ) {
			if ( null !== $only && (int) $only !== (int) $i ) continue;
			if ( empty( $p['playlist'] ) || empty( $p['show'] ) ) continue;
			$label = ! empty( $p['label'] ) ? $p['label'] : $p['playlist'];
			// Desactivado: se omite en la sincronización automática y en "Sincronizar ahora" (solo corre si lo pides a mano)
			if ( null === $only && isset( $p['active'] ) && ! $p['active'] ) {
				$log[] = [ $label, '', '', '', 'Programa desactivado: no se sincroniza.' ];
				continue;
			}
			$log = array_merge( $log, yts_sync_program( $p, $s, $dry ) );
			if ( ! $dry ) yts_check_milestone( $p, $s );
		}
	}
	update_option( 'yts_last_log', [ 'time' => current_time( 'mysql' ), 'dry' => (bool) $dry, 'rows' => array_slice( $log, 0, 300 ) ], false );
	return $log;
}
 
function yts_sync_program( $p, $s, $dry = false ) {
	$rows  = [];
	$label = ! empty( $p['label'] ) ? $p['label'] : $p['playlist'];
 
	$feed = yts_fetch_videos( $p['playlist'], $s );
	if ( $feed['error'] ) {
		$hint = '';
		if ( false !== stripos( $feed['error'], 'quota' ) ) $hint .= ' La cuota diaria de la API de YouTube está agotada (se reinicia a medianoche, hora del Pacífico de EE. UU.).';
		if ( false !== strpos( $feed['error'], 'HTTP 404' ) ) $hint .= ' El feed RSS de YouTube falla de forma intermitente; se reintenta solo en la próxima pasada.';
		$rows[] = [ $label, '', '', '', 'ERROR al leer la playlist (' . $feed['error'] . ').' . $hint ];
		return $rows;
	}
	if ( ! $feed['entries'] ) {
		$rows[] = [ $label, '', '', '', 'La playlist no trae videos (' . $feed['source'] . ').' ];
		return $rows;
	}
 
	$since   = strtotime( $s['import_since'] );
	$entries = array_reverse( $feed['entries'] ); // del más antiguo al más nuevo
 
	foreach ( $entries as $e ) {
		$video_id  = $e['id'];
		$title     = $e['title'];
		$published = $e['published'];
		$desc      = $e['desc'];
		if ( ! $video_id ) continue;
 
		$row = [ $label, $video_id, $title, get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $published ) ) ];
 
		if ( yts_exists( $video_id ) ) {
			$rows[] = array_merge( $row, [ 'Omitido: ya estaba importado.' ] );
			continue;
		}
		if ( $published < $since ) {
			$rows[] = array_merge( $row, [ 'Omitido: el video se publicó en YouTube antes de la "fecha de inicio" (' . substr( $s['import_since'], 0, 10 ) . ').' ] );
			continue;
		}
		if ( $dry ) {
			$rows[] = array_merge( $row, [ 'Se importaría.' ] );
			continue;
		}
 
		$r = yts_import_video( $p, $s, compact( 'video_id', 'title', 'published', 'desc' ) );
		$rows[] = array_merge( $row, [ is_wp_error( $r ) ? 'ERROR: ' . $r->get_error_message() : "Creado (episodio #$r)." ] );
	}
	return $rows;
}
 
function yts_import_video( $p, $s, $v ) {
	$show_id = (int) $p['show'];
 
	// --- Temporada destino (si no hay ninguna, crea "Temporada 1") ---
	$seasons = yts_seasons( $show_id );
	if ( ! $seasons ) {
		$seasons[] = [ 'name' => 'Temporada 1', 'season_year' => gmdate( 'Y' ), 'season_description' => '', 'episodes' => [] ];
	}
	$idx = ( isset( $p['season'] ) && '' !== $p['season'] && isset( $seasons[ (int) $p['season'] ] ) )
		? (int) $p['season']
		: count( $seasons ) - 1; // por defecto, la última
	$season_eps = ! empty( $seasons[ $idx ]['episodes'] ) ? (array) $seasons[ $idx ]['episodes'] : [];
 
	// --- Crear el episodio con la función oficial de Streamit ---
	$episode_id = streamit_add_episode( [
		'post_author'       => yts_author( $s ),
		'post_title'        => wp_strip_all_tags( $v['title'] ),
		'post_content'      => wp_kses_post( nl2br( esc_html( $v['desc'] ) ) ),
		'post_status'       => $s['post_status'],
		'post_name'         => sanitize_title( $v['title'] ) . '-' . strtolower( $v['video_id'] ), // único
		'post_date'         => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $v['published'] ) ),
		'post_date_gmt'     => gmdate( 'Y-m-d H:i:s', $v['published'] ),
	] );
 
	if ( is_wp_error( $episode_id ) || ! $episode_id ) {
		$why = is_wp_error( $episode_id ) ? $episode_id->get_error_message() : 'Streamit no devolvió un ID';
		error_log( '[yts] No se pudo crear el episodio: ' . $why );
		return new WP_Error( 'yts_create', 'No se pudo crear el episodio: ' . $why );
	}
 
	// --- Meta: primero lo copiado del episodio modelo, luego lo propio ---
	$meta = [];
	if ( $s['template_episode'] ) {
		$skip = [ 'thumbnail_id', '_episode_number', '_episode_url_link', '_episode_embed_content', '_episode_attachment_id',
			'_episode_release_date', 'tvshow_id', 'post_views_count', '_yts_video_id' ];
		foreach ( (array) get_metadata( 'streamit_episode', (int) $s['template_episode'] ) as $k => $vals ) {
			if ( in_array( $k, $skip, true ) ) continue;
			$meta[ $k ] = maybe_unserialize( is_array( $vals ) ? $vals[0] : $vals );
		}
	}
 
	$watch = 'https://www.youtube.com/watch?v=' . $v['video_id'];
	$meta['_episode_choice']       = $s['video_method'];
	$meta['_episode_release_date'] = gmdate( 'Y-m-d', $v['published'] );
	$meta['_episode_number']       = count( $season_eps ) + 1;
	$meta['tvshow_id']             = $show_id;
	$meta['_yts_video_id']         = $v['video_id'];
	$meta['thumbnail_id']          = yts_sideload( $v['video_id'], $v['title'], $s );
 
	if ( 'episode_url' === $s['video_method'] ) {
		$meta['_episode_url_link'] = $watch;
	} else {
		$meta['_episode_embed_content'] = '<iframe width="100%" height="100%" src="https://www.youtube.com/embed/' . esc_attr( $v['video_id'] ) . '" title="YouTube video player" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe>';
	}
 
	foreach ( $meta as $k => $val ) {
		streamit_add_episode_meta( $episode_id, $k, $val );
	}
	if ( class_exists( 'Streamit_Episode' ) ) {
		( new Streamit_Episode( $episode_id ) )->invalidate_meta_cache();
	}
 
	// --- Enlazar el episodio a la temporada del programa ---
	$season_eps[]                = $episode_id;
	$seasons[ $idx ]['episodes'] = $season_eps;
	// update_metadata crea la fila si no existe y, si Streamit ya dejó una fila `_seasons` vacía al crear el programa, la actualiza
	// (con add_metadata se agregaba una segunda fila y Streamit seguía leyendo la vacía).
	streamit_update_tvshow_meta( $show_id, '_seasons', $seasons );
	yts_flush_show_cache( $show_id );
 
	return (int) $episode_id;
}
 
/* ============================================================
 * AVISO POR CORREO AL LLEGAR AL CAPÍTULO N
 * ============================================================ */
function yts_mail_to( $s ) {
	return $s['notify_email'] ? $s['notify_email'] : get_option( 'admin_email' );
}
 
function yts_check_milestone( $p, $s ) {
	$target = (int) ( $p['notify_at'] ?? 0 );
	if ( $target < 1 ) return;
 
	$show_id = (int) $p['show'];
	$flag    = "yts_notified_{$show_id}_{$target}";
	if ( get_option( $flag ) ) return;
 
	$count = yts_total_episodes( $show_id );
	if ( $count < $target ) return;
 
	$label = ! empty( $p['label'] ) ? $p['label'] : "Programa #$show_id";
	$sent  = wp_mail(
		yts_mail_to( $s ),
		"El programa \"$label\" llegó al capítulo $target",
		"El programa \"$label\" ya tiene $count episodios en el sitio."
	);
 
	// Solo se marca como avisado si el correo realmente salió; si falla, reintenta en la próxima pasada
	if ( $sent ) {
		update_option( $flag, 1 );
		delete_option( 'yts_last_mail_error' );
	} else {
		error_log( "[yts] No se pudo enviar el aviso del programa $label" );
	}
}
 
/* ============================================================
 * PANEL DE AJUSTES
 * ============================================================ */
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
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=yts_run_one&i=' . (int) $i ), 'yts_run_one' ) ); ?>" title="Sincroniza solo este programa ahora">Sincronizar</a>
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
 
		<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #2271b1;padding:12px 16px;margin:12px 0">
			<h2 style="margin-top:0">🔎 Verificación del sistema</h2>
			<p>Revisa todo de una vez (Streamit, tablas, YouTube, miniaturas, cron, correo y cada programa) y deja un informe aquí abajo. <strong>Luego saca una captura y envíamela.</strong></p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=yts_verify' ), 'yts_verify' ) ); ?>">Verificar todo</a>
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=yts_verify&write=1' ), 'yts_verify' ) ); ?>">Verificar + prueba de creación</a>
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
				<tr><th>Miniaturas en WebP</th><td><label><input type="checkbox" name="thumb_webp" value="1" <?php checked( $s['thumb_webp'] ); ?>> Convertir las miniaturas de YouTube a WebP (más livianas)</label>
					<p class="description">Si el servidor no soporta WebP, se guarda el JPG original y queda un aviso en el registro de errores.</p></td></tr>
				<tr><th>API key de YouTube (opcional)</th><td><input type="password" name="youtube_api_key" value="<?php echo esc_attr( $s['youtube_api_key'] ); ?>" class="regular-text" autocomplete="off">
					<p class="description">Recomendada: la API de YouTube es más confiable que el feed RSS, que a veces responde 404. Es gratis (Google Cloud → YouTube Data API v3).</p></td></tr>
				<tr><th>ID del usuario autor</th><td><input type="number" name="author_id" value="<?php echo (int) $s['author_id'] ?: ''; ?>" style="width:120px"> <span class="description">Vacío = primer administrador.</span></td></tr>
			</table>
			<?php submit_button( 'Guardar cambios' ); ?>
		</form>
 
		<h2>Herramientas</h2>
		<p>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=yts_diag' ), 'yts_diag' ) ); ?>">Diagnóstico (no crea nada)</a>
			<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=yts_run_now' ), 'yts_run_now' ) ); ?>">Sincronizar ahora</a>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=yts_repair' ), 'yts_repair' ) ); ?>">Reparar enlaces</a>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=yts_test_mail' ), 'yts_test_mail' ) ); ?>">Enviar correo de prueba</a>
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
 
/* ============================================================
 * ENLACE EPISODIO <-> PROGRAMA y REPARACIÓN
 * ============================================================ */
function yts_flush_show_cache( $show_id ) {
	try {
		if ( class_exists( 'Streamit_Tvshow' ) ) {
			$t = new Streamit_Tvshow( $show_id );
			if ( method_exists( $t, 'invalidate_meta_cache' ) ) $t->invalidate_meta_cache();
		}
	} catch ( \Throwable $e ) {
		error_log( '[yts] No se pudo limpiar la caché del programa: ' . $e->getMessage() );
	}
}
 
// Episodios creados por este plugin para el programa, pero que no aparecen en ninguna temporada
function yts_unlinked_ids( $show_id ) {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT DISTINCT e.ID, e.post_date
		   FROM {$wpdb->streamit_episode} e
		   JOIN {$wpdb->streamit_episodemeta} a ON a.streamit_episode_id = e.ID AND a.meta_key = '_yts_video_id'
		   JOIN {$wpdb->streamit_episodemeta} b ON b.streamit_episode_id = e.ID AND b.meta_key = 'tvshow_id' AND b.meta_value = %s
		  ORDER BY e.post_date ASC, e.ID ASC",
		(string) (int) $show_id
	) );
	$ids = [];
	foreach ( (array) $rows as $row ) $ids[] = (int) $row->ID;
 
	$linked = [];
	foreach ( yts_seasons( $show_id ) as $season ) {
		foreach ( (array) ( isset( $season['episodes'] ) ? $season['episodes'] : [] ) as $eid ) $linked[] = (int) $eid;
	}
	return array_values( array_diff( $ids, $linked ) );
}
 
function yts_repair() {
	if ( ! yts_ready() ) return [ 'total' => 0, 'msg' => 'Streamit no está activo.' ];
	$s     = yts_settings();
	$total = 0;
	$lines = [];
 
	foreach ( $s['programs'] as $p ) {
		$show_id = (int) $p['show'];
		$name    = ! empty( $p['label'] ) ? $p['label'] : "#$show_id";
		$missing = yts_unlinked_ids( $show_id );
		if ( ! $missing ) { $lines[] = "«{$name}»: nada que reparar."; continue; }
 
		$seasons = yts_seasons( $show_id );
		if ( ! $seasons ) {
			$seasons[] = [ 'name' => 'Temporada 1', 'season_year' => gmdate( 'Y' ), 'season_description' => '', 'episodes' => [] ];
		}
		$idx = ( isset( $p['season'] ) && '' !== $p['season'] && isset( $seasons[ (int) $p['season'] ] ) ) ? (int) $p['season'] : count( $seasons ) - 1;
		$eps = ! empty( $seasons[ $idx ]['episodes'] ) ? array_map( 'intval', (array) $seasons[ $idx ]['episodes'] ) : [];
 
		foreach ( $missing as $eid ) {
			$eps[] = $eid;
			streamit_update_episode_meta( $eid, '_episode_number', count( $eps ) );
			streamit_update_episode_meta( $eid, 'tvshow_id', $show_id );
		}
		$seasons[ $idx ]['episodes'] = $eps;
		streamit_update_tvshow_meta( $show_id, '_seasons', $seasons );
		yts_flush_show_cache( $show_id );
 
		$total   += count( $missing );
		$lines[]  = "«{$name}»: " . count( $missing ) . ' episodio(s) enlazado(s) a la temporada ' . ( $idx + 1 ) . '.';
	}
	return [ 'total' => $total, 'msg' => 'Reparación lista. ' . implode( ' ', $lines ) ];
}
 
add_action( 'admin_post_yts_repair', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permiso' );
	check_admin_referer( 'yts_repair' );
	$res = yts_repair();
	yts_back( $res['msg'] );
} );
 
/* ============================================================
 * VERIFICACIÓN DEL SISTEMA (informe para capturar y enviar)
 * ============================================================ */
 
// Reprograma el cron si por alguna razón quedó sin programar
add_action( 'admin_init', function () {
	if ( ! wp_next_scheduled( 'yts_sync' ) ) wp_schedule_event( time() + 60, 'yts_30min', 'yts_sync' );
} );
 
function yts_mask_email( $email ) {
	$parts = explode( '@', $email );
	if ( 2 !== count( $parts ) ) return $email;
	return substr( $parts[0], 0, 2 ) . '***@' . $parts[1];
}
 
function yts_http( $url ) {
	return wp_remote_get( $url, [
		'timeout'     => 20,
		'redirection' => 3,
		'headers'     => [
			'User-Agent'      => 'Mozilla/5.0 (compatible; WordPress; +' . home_url() . ')',
			'Accept'          => 'application/atom+xml, application/xml;q=0.9, */*;q=0.8',
			'Accept-Language' => 'es-CL,es;q=0.9,en;q=0.8',
		],
	] );
}
 
// Feed RSS de YouTube, con 3 intentos (YouTube devuelve 404 de forma intermitente)
function yts_fetch_rss( $playlist ) {
	$last = '';
	for ( $i = 1; $i <= 3; $i++ ) {
		$res = yts_http( 'https://www.youtube.com/feeds/videos.xml?playlist_id=' . rawurlencode( $playlist ) );
		if ( is_wp_error( $res ) ) {
			$last = $res->get_error_message();
		} else {
			$code = wp_remote_retrieve_response_code( $res );
			if ( 200 === $code ) {
				libxml_use_internal_errors( true );
				$xml = simplexml_load_string( wp_remote_retrieve_body( $res ) );
				if ( $xml ) {
					$out = [];
					foreach ( $xml->entry as $e ) {
						$yt    = $e->children( 'yt', true );
						$media = $e->children( 'media', true );
						$out[] = [
							'id'        => (string) $yt->videoId,
							'title'     => (string) $e->title,
							'published' => strtotime( (string) $e->published ),
							'desc'      => (string) $media->group->description,
						];
					}
					return [ 'error' => '', 'entries' => $out ]; // más nuevo primero
				}
				$last = 'respuesta no interpretable';
			} else {
				$last = "HTTP $code";
			}
		}
		if ( $i < 3 ) sleep( (int) apply_filters( 'yts_retry_delay', 2 ) );
	}
	return [ 'error' => "$last tras 3 intentos", 'entries' => [] ];
}
 
// API oficial de YouTube (requiere API key gratuita). Lee hasta 500 videos de la playlist.
function yts_fetch_api( $playlist, $key ) {
	$out   = [];
	$token = '';
	for ( $page = 0; $page < 10; $page++ ) {
		$args = [ 'part' => 'snippet,contentDetails', 'maxResults' => 50, 'playlistId' => $playlist, 'key' => $key ];
		if ( $token ) $args['pageToken'] = $token;
		$res = wp_remote_get( add_query_arg( $args, 'https://www.googleapis.com/youtube/v3/playlistItems' ), [ 'timeout' => 20 ] );
		if ( is_wp_error( $res ) ) return [ 'error' => 'API: ' . $res->get_error_message(), 'entries' => [] ];
 
		$code = wp_remote_retrieve_response_code( $res );
		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( 200 !== $code ) {
			$msg = wp_strip_all_tags( isset( $body['error']['message'] ) ? $body['error']['message'] : "HTTP $code" );
			return [ 'error' => 'API: ' . $msg, 'entries' => [] ];
		}
		foreach ( isset( $body['items'] ) ? $body['items'] : [] as $it ) {
			$vid   = isset( $it['contentDetails']['videoId'] ) ? $it['contentDetails']['videoId'] : '';
			$title = isset( $it['snippet']['title'] ) ? $it['snippet']['title'] : '';
			$pub   = isset( $it['contentDetails']['videoPublishedAt'] ) ? $it['contentDetails']['videoPublishedAt'] : '';
			if ( ! $vid || ! $pub || in_array( $title, [ 'Private video', 'Deleted video' ], true ) ) continue; // privados o borrados
			$out[] = [
				'id'        => $vid,
				'title'     => $title,
				'published' => strtotime( $pub ),
				'desc'      => isset( $it['snippet']['description'] ) ? $it['snippet']['description'] : '',
			];
		}
		$token = isset( $body['nextPageToken'] ) ? $body['nextPageToken'] : '';
		if ( ! $token ) break;
	}
	usort( $out, function ( $a, $b ) { return $b['published'] <=> $a['published']; } ); // más nuevo primero
	return [ 'error' => '', 'entries' => $out ];
}
 
// Intenta la API (si hay key) y, si falla, el feed RSS
function yts_fetch_videos( $playlist, $s ) {
	$errors = [];
	if ( ! empty( $s['youtube_api_key'] ) ) {
		$r = yts_fetch_api( $playlist, $s['youtube_api_key'] );
		if ( ! $r['error'] ) { $r['source'] = 'API'; return $r; }
		$errors[] = $r['error'];
	}
	$r = yts_fetch_rss( $playlist );
	if ( ! $r['error'] ) { $r['source'] = 'RSS'; return $r; }
	$errors[] = 'RSS: ' . $r['error'];
	return [ 'error' => implode( ' | ', $errors ), 'entries' => [], 'source' => '' ];
}
 
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
	$add( 'info', 'Entorno', 'WordPress ' . get_bloginfo( 'version' ) . ' · PHP ' . PHP_VERSION . ' · zona horaria ' . wp_timezone_string() . ' · plugin YouTube a Streamit 3.0.0' );
 
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
		$add( 'ok', 'Tarea automática (cron)', 'Programada cada 30 min. Próxima ejecución: ' . get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ) ) . '. (Solo corre cuando alguien visita el sitio.)' );
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
	$add( empty( $s['youtube_api_key'] ) ? 'info' : 'ok', 'API key de YouTube', empty( $s['youtube_api_key'] ) ? 'No configurada. Es opcional, pero recomendada: el feed RSS de YouTube falla de forma intermitente.' : 'Configurada (se usa primero; el RSS queda de respaldo).' );
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
 
function yts_close_btn( $what, $box_id ) {
	$url = wp_nonce_url( admin_url( 'admin-post.php?action=yts_close&what=' . $what ), 'yts_close' );
	$js  = "document.getElementById('" . $box_id . "').remove();fetch(" . wp_json_encode( $url ) . ",{credentials:'same-origin'});";
	return '<button type="button" class="button" style="float:right;margin:0 0 8px 8px" onclick="' . esc_attr( $js ) . '">✕ Cerrar</button>';
}
 
add_action( 'admin_post_yts_close', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permiso' );
	check_admin_referer( 'yts_close' );
	$what = isset( $_GET['what'] ) ? sanitize_key( $_GET['what'] ) : '';
	if ( 'report' === $what ) delete_option( 'yts_last_report' );
	if ( 'log' === $what )    delete_option( 'yts_last_log' );
	status_header( 204 );
	exit;
} );
 
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
 
add_action( 'admin_post_yts_verify', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permiso' );
	check_admin_referer( 'yts_verify' );
	$write = ! empty( $_GET['write'] );
	update_option( 'yts_last_report', [ 'time' => current_time( 'mysql' ), 'write' => $write, 'rows' => yts_checks( $write ) ], false );
	yts_back( 'Verificación lista. Revisa el informe y mándame una captura.' );
} );
 
/* ============================================================
 * EPISODIOS IMPORTADOS (ordenados por programa y capítulo)
 * ============================================================ */
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
 
add_action( 'admin_post_yts_save', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permiso' );
	check_admin_referer( 'yts_save' );
 
	global $wpdb;
	$programs = [];
	foreach ( (array) ( $_POST['programs'] ?? [] ) as $row ) {
		$pl = yts_parse_playlist( $row['playlist'] ?? '' );
		$sh = absint( $row['show'] ?? 0 );
		if ( ! $pl || ! $sh ) continue;
		$season = isset( $row['season'] ) && '' !== $row['season'] ? max( 0, absint( $row['season'] ) - 1 ) : '';
		$programs[] = [
			'playlist'  => $pl,
			'show'      => $sh,
			'label'     => (string) $wpdb->get_var( $wpdb->prepare( "SELECT post_title FROM {$wpdb->streamit_tvshow} WHERE ID = %d", $sh ) ),
			'notify_at' => absint( $row['notify_at'] ?? 0 ),
			'season'    => $season,
			'active'    => empty( $row['active'] ) ? 0 : 1,
		];
	}
 
	update_option( 'yts_settings', [
		'programs'         => $programs,
		'notify_email'     => sanitize_email( wp_unslash( $_POST['notify_email'] ?? '' ) ),
		'import_since'     => sanitize_text_field( wp_unslash( $_POST['import_since'] ?? gmdate( 'Y-m-d' ) ) ) . ' 00:00:00',
		'post_status'      => in_array( $_POST['post_status'] ?? '', [ 'draft', 'publish' ], true ) ? $_POST['post_status'] : 'draft',
		'video_method'     => in_array( $_POST['video_method'] ?? '', [ 'episode_embed', 'episode_url' ], true ) ? $_POST['video_method'] : 'episode_embed',
		'template_episode' => absint( $_POST['template_episode'] ?? 0 ),
		'author_id'        => absint( $_POST['author_id'] ?? 0 ),
		'thumb_webp'       => empty( $_POST['thumb_webp'] ) ? 0 : 1,
		'youtube_api_key'  => sanitize_text_field( wp_unslash( $_POST['youtube_api_key'] ?? '' ) ),
	] );
	yts_back( 'Ajustes guardados.' );
} );
 
add_action( 'admin_post_yts_run_now', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permiso' );
	check_admin_referer( 'yts_run_now' );
	$log     = yts_run();
	$created = 0;
	foreach ( $log as $r ) if ( isset( $r[4] ) && 0 === strpos( $r[4], 'Creado' ) ) $created++;
	yts_back( "Sincronización ejecutada: $created episodio(s) creado(s). Mira el detalle abajo." );
} );
 
add_action( 'admin_post_yts_run_one', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permiso' );
	check_admin_referer( 'yts_run_one' );
	$i = isset( $_GET['i'] ) ? absint( $_GET['i'] ) : -1;
	$s = yts_settings();
	if ( ! isset( $s['programs'][ $i ] ) ) yts_back( 'No encontré ese programa. Guarda los cambios y vuelve a intentarlo.' );
	$p     = $s['programs'][ $i ];
	$name  = ! empty( $p['label'] ) ? $p['label'] : $p['playlist'];
	$log   = yts_run( false, $i );
	$n     = 0;
	foreach ( $log as $r ) if ( isset( $r[4] ) && 0 === strpos( $r[4], 'Creado' ) ) $n++;
	yts_back( "Programa «{$name}» sincronizado: $n episodio(s) creado(s). Mira el detalle abajo." );
} );
 
add_action( 'admin_post_yts_diag', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permiso' );
	check_admin_referer( 'yts_diag' );
	yts_run( true );
	yts_back( 'Diagnóstico listo. Mira el detalle abajo.' );
} );
 
add_action( 'admin_post_yts_test_mail', function () {
	if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Sin permiso' );
	check_admin_referer( 'yts_test_mail' );
	$to = yts_mail_to( yts_settings() );
	delete_option( 'yts_last_mail_error' );
	$ok = wp_mail( $to, 'Prueba: YouTube a Streamit', 'Si lees esto, los avisos por correo funcionan.' );
	yts_back( $ok ? "Correo de prueba enviado a $to. Revisa también la carpeta de spam." : 'WordPress no pudo enviar el correo. Instala un plugin SMTP (ej. WP Mail SMTP).' );
} );
 