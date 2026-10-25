<?php
/**
 * Plugin Name:       YouTube to Streamit
 * Plugin URI:        https://github.com/lulubumps/yt-to-streamit
 * Description:       Automatically imports the videos of YouTube playlists as Streamit episodes and sends an email notice when a program reaches an episode number. Independent project, not affiliated with Iqonic or Streamit.
 * Version:           3.6.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Lulubumps
 * Author URI:        https://github.com/lulubumps
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       yt-to-streamit
 * Domain Path:       /languages
 *
 * Este archivo solo arranca el plugin. La lógica vive en /includes (ver MANTENIMIENTO.md).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'YTS_VERSION', '3.6.0' );
define( 'YTS_FILE', __FILE__ );
define( 'YTS_DIR', plugin_dir_path( __FILE__ ) );
define( 'YTS_URL', plugin_dir_url( __FILE__ ) );

// Núcleo (se usa también cuando corre el cron, sin panel)
require_once YTS_DIR . 'includes/settings.php';
require_once YTS_DIR . 'includes/streamit.php';
require_once YTS_DIR . 'includes/videos.php';
require_once YTS_DIR . 'includes/youtube.php';
require_once YTS_DIR . 'includes/thumbnails.php';
require_once YTS_DIR . 'includes/notifications.php';
require_once YTS_DIR . 'includes/importer.php';
require_once YTS_DIR . 'includes/cron.php';

// Panel de administración (solo se carga en el escritorio de WordPress)
if ( is_admin() ) {
	require_once YTS_DIR . 'includes/diagnostics.php';
	require_once YTS_DIR . 'includes/admin/menu.php';
	require_once YTS_DIR . 'includes/admin/components.php';
	require_once YTS_DIR . 'includes/admin/page-programs.php';
	require_once YTS_DIR . 'includes/admin/page-videos.php';
	require_once YTS_DIR . 'includes/admin/page-settings.php';
	require_once YTS_DIR . 'includes/admin/page-tools.php';
	require_once YTS_DIR . 'includes/admin/page-imported.php';
	require_once YTS_DIR . 'includes/admin/page-verify.php';
	require_once YTS_DIR . 'includes/admin/actions.php';
}

// Traducciones (carpeta /languages): inglés es el idioma base; hay español y portugués
add_action( 'init', function () {
	load_plugin_textdomain( 'yt-to-streamit', false, dirname( plugin_basename( YTS_FILE ) ) . '/languages' );
} );

register_activation_hook( __FILE__, 'yts_activate' );
register_deactivation_hook( __FILE__, 'yts_deactivate' );
