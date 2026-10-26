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
	$r     = [];
	$add   = function ( $level, $title, $detail = '' ) use ( &$r ) { $r[] = [ $level, $title, $detail ]; };
	$s     = yts_settings();
	$yes   = __( 'yes', 'yt-to-streamit' );
	$no    = __( 'no', 'yt-to-streamit' );

	// --- Entorno ---
	/* translators: 1: WordPress version, 2: PHP version, 3: time zone, 4: plugin version */
	$add( 'info', __( 'Environment', 'yt-to-streamit' ), sprintf( __( 'WordPress %1$s · PHP %2$s · time zone %3$s · YouTube to Streamit plugin %4$s', 'yt-to-streamit' ), get_bloginfo( 'version' ), PHP_VERSION, wp_timezone_string(), YTS_VERSION ) );

	// --- Streamit ---
	$t_plugin = __( 'Streamit plugin', 'yt-to-streamit' );
	if ( ! function_exists( 'get_plugins' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
	$found = false;
	foreach ( get_plugins() as $file => $data ) {
		if ( 'streamit-core' === ( $data['TextDomain'] ?? '' ) ) {
			$found = true;
			if ( is_plugin_active( $file ) ) {
				/* translators: %s: version number */
				$add( 'ok', $t_plugin, sprintf( __( 'Active, version %s', 'yt-to-streamit' ), $data['Version'] ) );
			} else {
				/* translators: %s: version number */
				$add( 'fail', $t_plugin, sprintf( __( 'It is installed (v%s) but INACTIVE. Activate it.', 'yt-to-streamit' ), $data['Version'] ) );
			}
			break;
		}
	}
	if ( ! $found ) $add( 'fail', $t_plugin, __( 'I cannot find it among the installed plugins.', 'yt-to-streamit' ) );

	$t_fn    = __( 'Streamit functions', 'yt-to-streamit' );
	$fns     = [ 'streamit_add_episode', 'streamit_add_episode_meta', 'streamit_delete_episode', 'streamit_get_tvshow_meta', 'streamit_add_tvshow_meta', 'streamit_update_tvshow_meta' ];
	$missing = [];
	foreach ( $fns as $f ) if ( ! function_exists( $f ) ) $missing[] = $f;
	if ( $missing ) {
		/* translators: %s: list of function names */
		$add( 'fail', $t_fn, sprintf( __( 'Missing: %s. Probably a different Streamit version.', 'yt-to-streamit' ), implode( ', ', $missing ) ) );
	} else {
		$add( 'ok', $t_fn, __( 'All the required functions exist.', 'yt-to-streamit' ) );
	}

	$tables_ok = true;
	foreach ( [ 'streamit_episode', 'streamit_episodemeta', 'streamit_tvshow', 'streamit_tvshowmeta' ] as $t ) {
		$name = isset( $wpdb->$t ) ? $wpdb->$t : '';
		if ( ! $name || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) ) !== $name ) {
			$tables_ok = false;
			/* translators: %s: table name */
			$add( 'fail', sprintf( __( 'Table %s', 'yt-to-streamit' ), $t ), __( 'It does not exist in the database.', 'yt-to-streamit' ) );
		}
	}
	if ( $tables_ok ) {
		$ne = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->streamit_episode}" );
		$nt = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->streamit_tvshow}" );
		/* translators: 1: number of programs, 2: number of episodes */
		$add( 'ok', __( 'Streamit tables', 'yt-to-streamit' ), sprintf( __( 'They exist. There are %1$d program(s) and %2$d episode(s) in total.', 'yt-to-streamit' ), $nt, $ne ) );
	}

	// --- Requisitos técnicos ---
	$t_xml = __( 'XML reader (SimpleXML)', 'yt-to-streamit' );
	if ( function_exists( 'simplexml_load_string' ) ) $add( 'ok', $t_xml, __( 'Available.', 'yt-to-streamit' ) );
	else $add( 'fail', $t_xml, __( 'It is not installed in PHP; without it YouTube cannot be read. Ask your host to enable the php-xml extension.', 'yt-to-streamit' ) );

	$t_webp = __( 'WebP support', 'yt-to-streamit' );
	if ( wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] ) ) $add( 'ok', $t_webp, __( 'The server can convert images to WebP.', 'yt-to-streamit' ) );
	else $add( $s['thumb_webp'] ? 'warn' : 'info', $t_webp, __( 'The server does NOT support WebP (GD/Imagick). Thumbnails will be saved as JPG.', 'yt-to-streamit' ) );

	// --- Cron ---
	$t_cron = __( 'Automatic task (cron)', 'yt-to-streamit' );
	$next   = wp_next_scheduled( 'yts_sync' );
	if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
		$add( 'warn', $t_cron, __( 'WP-Cron is disabled in wp-config.php. It only works if the host runs wp-cron.php with a real cron; otherwise use “Sync now”.', 'yt-to-streamit' ) );
	} elseif ( $next ) {
		/* translators: 1: sync frequency (for example "Every 12 hours"), 2: date and time of the next run */
		$add( 'ok', $t_cron, sprintf( __( 'Scheduled: %1$s. Next run: %2$s. (It only runs when someone visits the site.)', 'yt-to-streamit' ), yts_frequencies()[ yts_frequency() ], get_date_from_gmt( gmdate( 'Y-m-d H:i:s', $next ) ) ) );
	} else {
		$add( 'fail', $t_cron, __( 'It is not scheduled. Deactivate and reactivate the plugin.', 'yt-to-streamit' ) );
	}

	// --- Correo ---
	$t_mail = __( 'Notification email', 'yt-to-streamit' );
	$to     = yts_mail_to( $s );
	if ( ! is_email( $to ) ) {
		$add( 'fail', $t_mail, __( 'The configured address is not valid.', 'yt-to-streamit' ) );
	} else {
		$err = get_option( 'yts_last_mail_error' );
		if ( $err ) {
			/* translators: 1: masked email address, 2: error message */
			$add( 'fail', $t_mail, sprintf( __( 'Destination: %1$s. Last sending error: %2$s', 'yt-to-streamit' ), yts_mask_email( $to ), $err ) );
		} else {
			/* translators: %s: masked email address */
			$add( 'ok', $t_mail, sprintf( __( 'Destination: %s. Use “Send test email” to confirm it arrives.', 'yt-to-streamit' ), yts_mask_email( $to ) ) );
		}
	}

	// --- Ajustes ---
	$t_key = __( 'YouTube API key', 'yt-to-streamit' );
	if ( ! yts_api_key( $s ) ) $add( 'info', $t_key, __( 'Not set. It is optional but recommended: the YouTube RSS feed fails intermittently.', 'yt-to-streamit' ) );
	else $add( 'ok', $t_key, __( 'Set (it is used first; RSS stays as a fallback).', 'yt-to-streamit' ) );

	$t_dur = __( 'Video duration', 'yt-to-streamit' );
	if ( yts_api_key( $s ) ) $add( 'ok', $t_dur, __( 'It is read from the YouTube API and saved in Streamit as hours:minutes.', 'yt-to-streamit' ) );
	else $add( 'info', $t_dur, __( 'Without an API key it cannot be read (the RSS feed does not include it): the field stays empty.', 'yt-to-streamit' ) );

	/* translators: 1: start date, 2: import status, 3: video method, 4: yes/no */
	$add( 'info', __( 'Settings', 'yt-to-streamit' ), sprintf( __( 'Import from %1$s · status: %2$s · video: %3$s · WebP thumbnails: %4$s', 'yt-to-streamit' ), substr( $s['import_since'], 0, 10 ), $s['post_status'], $s['video_method'], $s['thumb_webp'] ? $yes : $no ) );

	$t_model = __( 'Model episode', 'yt-to-streamit' );
	if ( $s['template_episode'] ) {
		$m = get_metadata( 'streamit_episode', (int) $s['template_episode'] );
		if ( $m ) {
			/* translators: 1: episode ID, 2: number of fields */
			$add( 'ok', $t_model, sprintf( __( 'Episode #%1$d exists and has %2$d fields to copy.', 'yt-to-streamit' ), (int) $s['template_episode'], count( $m ) ) );
		} else {
			/* translators: %d: episode ID */
			$add( 'warn', $t_model, sprintf( __( 'Episode #%d does not exist or has no fields.', 'yt-to-streamit' ), (int) $s['template_episode'] ) );
		}
	} else {
		$add( 'warn', $t_model, __( 'None is set. It is optional but recommended, to copy the other Streamit fields.', 'yt-to-streamit' ) );
	}

	$t_author = __( 'Author of the episodes', 'yt-to-streamit' );
	$aid      = yts_author( $s );
	$au       = get_userdata( $aid );
	if ( $au ) {
		/* translators: 1: user display name, 2: user ID */
		$txt = sprintf( __( 'They will be assigned to %1$s (#%2$d).', 'yt-to-streamit' ), $au->display_name, $aid );
		if ( empty( $s['author_id'] ) ) $txt .= ' ' . __( '(automatic: first administrator)', 'yt-to-streamit' );
		$add( 'ok', $t_author, $txt );
	} else {
		/* translators: %d: user ID */
		$add( 'fail', $t_author, sprintf( __( 'User #%d does not exist.', 'yt-to-streamit' ), $aid ) );
	}

	// --- Programas ---
	$t_programs = __( 'Programs', 'yt-to-streamit' );
	if ( ! $s['programs'] ) {
		$add( 'fail', $t_programs, __( 'No program is configured. Add one and save.', 'yt-to-streamit' ) );
	}
	$since    = strtotime( $s['import_since'] );
	$n_active = 0;
	foreach ( $s['programs'] as $p ) if ( ! isset( $p['active'] ) || $p['active'] ) $n_active++;
	if ( $s['programs'] && ! $n_active ) $add( 'warn', $t_programs, __( 'All programs are disabled: nothing is synced automatically.', 'yt-to-streamit' ) );

	foreach ( $s['programs'] as $p ) {
		$name = yts_program_label( $p );
		/* translators: %s: program name */
		$t_prog = sprintf( __( 'Program “%s”', 'yt-to-streamit' ), $name );
		if ( isset( $p['active'] ) && ! $p['active'] ) {
			$add( 'info', $t_prog, __( 'Disabled: it is not synced and YouTube is not queried.', 'yt-to-streamit' ) );
			continue;
		}

		$show = $tables_ok ? $wpdb->get_row( $wpdb->prepare( "SELECT ID, post_title FROM {$wpdb->streamit_tvshow} WHERE ID = %d", (int) $p['show'] ) ) : null;
		if ( ! $show ) {
			/* translators: %d: ID of the TV Show */
			$add( 'fail', $t_prog, sprintf( __( 'TV Show #%d does not exist in Streamit.', 'yt-to-streamit' ), (int) $p['show'] ) );
		} elseif ( yts_ready() ) {
			$seasons = yts_seasons( $show->ID );
			$total   = yts_total_episodes( $show->ID );
			$goal    = (int) ( $p['notify_at'] ?? 0 );
			if ( ! $goal ) {
				$extra = '. ' . __( 'No notice configured.', 'yt-to-streamit' );
			} elseif ( $total >= $goal ) {
				/* translators: %d: episode number */
				$extra = '. ' . sprintf( __( 'It already reached episode %d.', 'yt-to-streamit' ), $goal );
			} else {
				/* translators: 1: episode number that triggers the notice, 2: how many episodes are still missing */
				$extra = '. ' . sprintf( __( 'Notice when reaching episode %1$d (%2$d to go).', 'yt-to-streamit' ), $goal, $goal - $total );
			}
			/* translators: %s: program name */
			$add( 'ok', sprintf( __( 'Program “%s”', 'yt-to-streamit' ), $show->post_title ),
				/* translators: 1: number of seasons, 2: number of episodes */
				sprintf( __( '%1$d season(s), %2$d episode(s)', 'yt-to-streamit' ), count( $seasons ), $total ) . $extra );
			$un = yts_unlinked_ids( $show->ID );
			if ( $un ) {
				/* translators: 1: program name; 2: number of episodes */
				$add( 'fail', sprintf( __( 'Episode ↔ program link “%s”', 'yt-to-streamit' ), $show->post_title ),
					sprintf( __( '%d imported episode(s) do not appear inside the program. Press “Repair links”.', 'yt-to-streamit' ), count( $un ) ) );
			}
		}

		/* translators: %s: program name */
		$t_pl = sprintf( __( 'Playlist of “%s”', 'yt-to-streamit' ), $name );
		$feed = yts_fetch_videos( $p['playlist'], $s );
		if ( $feed['error'] ) {
			$is404 = false !== strpos( $feed['error'], 'HTTP 404' );
			$isq   = false !== stripos( $feed['error'], 'quota' );
			$extra = '';
			if ( $isq )   $extra .= ' ' . __( 'The daily YouTube API quota is exhausted: it resets at midnight (US Pacific time). Check in Google Cloud → APIs & Services → YouTube Data API v3 → Quotas what is consuming it.', 'yt-to-streamit' );
			if ( $is404 ) $extra .= ' ' . __( 'The YouTube RSS feed fails intermittently (sometimes for hours); it is not a problem with your playlist. It is retried automatically on every scheduled sync.', 'yt-to-streamit' );
			/* translators: %s: error detail */
			$add( ( $is404 || $isq ) ? 'warn' : 'fail', $t_pl, sprintf( __( 'Could not be read: %s.', 'yt-to-streamit' ), $feed['error'] ) . $extra );
			continue;
		}
		$n = count( $feed['entries'] ); $new = 0; $old = 0; $done = 0; $skipped = 0;
		foreach ( $feed['entries'] as $e ) {
			if ( ! $tables_ok ) { $new++; continue; }
			list( $kind ) = yts_classify_video( $p, $s, $e, $since );
			if ( 'exists' === $kind ) $done++;
			elseif ( 'old' === $kind ) $old++;
			elseif ( 'skip' === $kind ) $skipped++;
			else $new++;
		}
		/* translators: 1: number of videos, 2: source (API or RSS), 3: to import, 4: already in Streamit, 5: before the start date */
		$detail = sprintf( __( 'YouTube returns %1$d video(s) (via %2$s): %3$d to import, %4$d already exist in Streamit, %5$d before the start date', 'yt-to-streamit' ), $n, $feed['source'], $new, $done, $old );
		/* translators: %d: number of videos not selected */
		if ( $skipped ) $detail .= sprintf( __( ', %d not selected', 'yt-to-streamit' ), $skipped );
		$detail .= '.';
		if ( ! $n ) {
			$add( 'warn', $t_pl, __( 'It was read correctly but has no videos.', 'yt-to-streamit' ) );
		} elseif ( 0 === $new && $old > 0 && 0 === $done && 0 === $skipped ) {
			$add( 'warn', $t_pl, $detail . ' ' . __( 'If you expected to import any, change “Import videos from” to an earlier date.', 'yt-to-streamit' ) );
		} else {
			$add( 'ok', $t_pl, $detail );
		}

		if ( $n ) {
			$head = wp_remote_head( 'https://i.ytimg.com/vi/' . $feed['entries'][0]['id'] . '/hqdefault.jpg', [ 'timeout' => 15 ] );
			$ok   = ! is_wp_error( $head ) && 200 === wp_remote_retrieve_response_code( $head );
			/* translators: %s: program name */
			$add( $ok ? 'ok' : 'warn', sprintf( __( 'Thumbnails of “%s”', 'yt-to-streamit' ), $name ),
				$ok ? __( 'They can be downloaded from YouTube.', 'yt-to-streamit' ) : __( 'I could not download a test thumbnail from the server.', 'yt-to-streamit' ) );
		}
	}

	// --- Prueba de escritura real (opcional) ---
	if ( $write ) {
		$t_test = __( 'Creation test', 'yt-to-streamit' );
		if ( ! yts_ready() || ! $tables_ok ) {
			$add( 'fail', $t_test, __( 'Skipped: Streamit is not ready (see the errors above).', 'yt-to-streamit' ) );
		} else {
			$tmp = streamit_add_episode( [
				'post_author' => $aid,
				'post_title'  => '[yts test] delete',
				'post_status' => 'draft',
				'post_name'   => 'yts-test-' . strtolower( wp_generate_password( 8, false, false ) ),
			] );
			if ( is_wp_error( $tmp ) || ! $tmp ) {
				/* translators: %s: error message */
				$add( 'fail', $t_test, sprintf( __( 'Streamit rejected the test episode: %s', 'yt-to-streamit' ), is_wp_error( $tmp ) ? $tmp->get_error_message() : __( 'no ID', 'yt-to-streamit' ) ) );
			} else {
				streamit_add_episode_meta( $tmp, '_yts_video_id', '__test__' );
				$stored = get_metadata( 'streamit_episode', $tmp, '_yts_video_id', true );
				delete_metadata( 'streamit_episode', $tmp, '_yts_video_id' );
				streamit_delete_episode( $tmp );
				$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->streamit_episode} WHERE ID = %d", $tmp ) );
				if ( '__test__' === $stored && 0 === $left ) {
					/* translators: %d: ID of the temporary episode */
					$add( 'ok', $t_test, sprintf( __( 'A temporary episode (#%d) was created and deleted. Streamit accepts the episodes this plugin creates.', 'yt-to-streamit' ), $tmp ) );
				} else {
					/* translators: 1: episode ID, 2: yes/no (meta saved), 3: yes/no (deleted) */
					$add( 'warn', $t_test, sprintf( __( 'Episode #%1$d was created but not everything is in order (meta saved: %2$s, deleted: %3$s).', 'yt-to-streamit' ),
						$tmp, '__test__' === $stored ? $yes : $no, $left ? __( 'no, delete it manually from Streamit', 'yt-to-streamit' ) : $yes ) );
				}
			}
		}
	}

	return $r;
}
