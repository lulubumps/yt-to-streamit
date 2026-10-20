<?php
/**
 * Plugin Name:       YouTube a Streamit
 * Plugin URI:        https://github.com/lulubumps/yt-to-streamit
 * Description:       Importa automáticamente los videos de playlists de YouTube como episodios de Streamit y avisa por correo al llegar a un capítulo. Proyecto independiente, no afiliado a Iqonic ni a Streamit.
 * Version:           3.2.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Lulubumps
 * Author URI:        https://github.com/lulubumps
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       yt-to-streamit
 *
 * Este archivo solo arranca el plugin. La lógica vive en /includes (ver MANTENIMIENTO.md).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'YTS_VERSION', '3.2.0' );
define( 'YTS_FILE', __FILE__ );
define( 'YTS_DIR', plugin_dir_path( __FILE__ ) );

// Núcleo (se usa también cuando corre el cron, sin panel)
require_once YTS_DIR . 'includes/settings.php';
require_once YTS_DIR . 'includes/streamit.php';
require_once YTS_DIR . 'includes/youtube.php';
require_once YTS_DIR . 'includes/thumbnails.php';
require_once YTS_DIR . 'includes/notifications.php';
require_once YTS_DIR . 'includes/importer.php';
require_once YTS_DIR . 'includes/cron.php';

// Panel de administración (solo se carga en el escritorio de WordPress)
if ( is_admin() ) {
	require_once YTS_DIR . 'includes/diagnostics.php';
	require_once YTS_DIR . 'includes/admin-page.php';
	require_once YTS_DIR . 'includes/admin-actions.php';
}

register_activation_hook( __FILE__, 'yts_activate' );
register_deactivation_hook( __FILE__, 'yts_deactivate' );
