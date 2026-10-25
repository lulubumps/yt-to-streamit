<?php
/**
 * Menú y estructura común del panel
 *
 * Registra el menú propio «YouTube a Streamit» con sus secciones, carga los archivos de /assets
 * y dibuja el encabezado con pestañas que comparten todas las páginas.
 *
 * @package YT_To_Streamit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Secciones del panel: slug => [ título, función que la dibuja ].
 * El orden es el del menú y las pestañas. Para agregar una sección nueva: ver MANTENIMIENTO.md.
 */
function yts_pages() {
	return [
		'yts'          => [ __( 'Synced programs', 'yt-to-streamit' ), 'yts_page_programs' ],
		'yts-videos'   => [ __( 'Playlist videos', 'yt-to-streamit' ), 'yts_page_videos' ],
		'yts-settings' => [ __( 'Settings', 'yt-to-streamit' ), 'yts_page_settings' ],
		'yts-tools'    => [ __( 'Tools', 'yt-to-streamit' ), 'yts_page_tools' ],
		'yts-imported' => [ __( 'Imported episodes', 'yt-to-streamit' ), 'yts_page_imported' ],
		'yts-log'      => [ __( 'Sync log', 'yt-to-streamit' ), 'yts_page_log' ],
		'yts-verify'   => [ __( 'System check', 'yt-to-streamit' ), 'yts_page_verify' ],
	];
}

add_action( 'admin_menu', function () {
	add_menu_page( __( 'YouTube to Streamit', 'yt-to-streamit' ), __( 'YouTube to Streamit', 'yt-to-streamit' ), 'manage_options', 'yts', 'yts_page_programs', 'dashicons-video-alt3', 58 );
	foreach ( yts_pages() as $slug => $page ) {
		add_submenu_page( 'yts', $page[0], $page[0], 'manage_options', $slug, $page[1] );
	}
} );

// CSS y JS solo en las páginas del plugin
add_action( 'admin_enqueue_scripts', function () {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( ! isset( yts_pages()[ $page ] ) ) return;

	wp_enqueue_style( 'yts-admin', YTS_URL . 'assets/admin.css', [], YTS_VERSION );
	wp_enqueue_script( 'yts-admin', YTS_URL . 'assets/admin.js', [], YTS_VERSION, true );

	// Datos para el JavaScript: textos traducidos y las listas del selector con buscador
	$data = [ 'i18n' => yts_js_strings() ];
	if ( 'yts' === $page ) {
		// Programas de Streamit (selector de «Programas sincronizados»)
		$data['shows'] = [];
		foreach ( yts_shows() as $sh ) $data['shows'][] = [ 'id' => (int) $sh->ID, 'title' => $sh->post_title ];
	} elseif ( 'yts-videos' === $page ) {
		// Programas ya sincronizados (selector de «Videos de la playlist»)
		$data['programs'] = [];
		foreach ( yts_settings()['programs'] as $k => $prog ) {
			$data['programs'][] = [ 'id' => (int) $k, 'title' => yts_program_label( $prog ), 'hint' => isset( $prog['playlist'] ) ? $prog['playlist'] : '' ];
		}
	}
	wp_localize_script( 'yts-admin', 'YTS', $data );
} );

/** Textos que usa assets/admin.js (ya traducidos). Las claves deben coincidir con las de `t( clave, … )` en el JavaScript. */
function yts_js_strings() {
	return [
		'noResults'  => __( 'No results', 'yt-to-streamit' ),
		'noPrograms' => __( 'There are no programs yet', 'yt-to-streamit' ),
		/* translators: 1: number of results shown, 2: total number of results */
		'showing'    => __( 'Showing %1$s of %2$s. Keep typing to narrow it down.', 'yt-to-streamit' ),
		'oneItem'    => __( '1 item', 'yt-to-streamit' ),
		/* translators: %s: number of items */
		'nItems'     => __( '%s items', 'yt-to-streamit' ),
		'firstPage'  => __( 'First page', 'yt-to-streamit' ),
		'prevPage'   => __( 'Previous', 'yt-to-streamit' ),
		'nextPage'   => __( 'Next', 'yt-to-streamit' ),
		'lastPage'   => __( 'Last page', 'yt-to-streamit' ),
		/* translators: 1: current page, 2: total pages */
		'pageOf'     => __( 'Page %1$s of %2$s', 'yt-to-streamit' ),
	];
}

/** Formulario único de acciones: los botones de cada página lo usan con form="yts-actions" (ver actions.php). */
function yts_actions_form( $current ) {
	?>
	<form id="yts-actions" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="yts_do">
		<input type="hidden" name="yts_return" value="<?php echo esc_attr( $current ); ?>">
		<?php wp_nonce_field( 'yts_do', 'yts_nonce' ); ?>
	</form>
	<?php
}

/** Abre la página: título, avisos y pestañas. Se cierra con yts_admin_footer(). */
function yts_admin_header( $current ) {
	$msg = isset( $_GET['yts_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['yts_msg'] ) ) : '';
	$err = get_option( 'yts_last_mail_error' );

	echo '<div class="wrap yts-wrap"><h1>' . esc_html__( 'YouTube to Streamit', 'yt-to-streamit' ) . '</h1>';
	if ( ! yts_ready() ) echo '<div class="notice notice-error"><p>' . wp_kses( __( '<strong>The Streamit plugin is not active</strong> (or its version does not have the expected functions). This plugin needs Streamit 1.3.x.', 'yt-to-streamit' ), [ 'strong' => [] ] ) . '</p></div>';
	if ( $msg ) {
		// Tras una sincronización o un diagnóstico (yts_log=1) el aviso trae un enlace al registro
		$link = isset( $_GET['yts_log'] ) ? ' <a href="' . esc_url( admin_url( 'admin.php?page=yts-log' ) ) . '">' . esc_html__( 'View the sync log', 'yt-to-streamit' ) . '</a>' : '';
		echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $msg ) . $link . '</p></div>';
	}
	if ( $err ) {
		/* translators: %s: error message */
		echo '<div class="notice notice-error"><p>' . wp_kses( sprintf( __( '<strong>Last email error:</strong> %s', 'yt-to-streamit' ), esc_html( $err ) ), [ 'strong' => [] ] ) . '</p></div>';
	}

	echo '<nav class="nav-tab-wrapper">';
	foreach ( yts_pages() as $slug => $page ) {
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . $slug ) ) . '" class="nav-tab' . ( $slug === $current ? ' nav-tab-active' : '' ) . '">' . esc_html( $page[0] ) . '</a>';
	}
	echo '</nav>';

	yts_actions_form( $current );
}

function yts_admin_footer() {
	echo '</div>';
}

/** Vuelve a una sección del panel con un mensaje (termina la petición). */
function yts_back( $msg, $page = 'yts', $args = [] ) {
	if ( ! isset( yts_pages()[ $page ] ) ) $page = 'yts';
	$url = admin_url( 'admin.php?page=' . $page );
	if ( $args ) $url = add_query_arg( $args, $url );
	wp_safe_redirect( add_query_arg( 'yts_msg', rawurlencode( $msg ), $url ) );
	exit;
}
