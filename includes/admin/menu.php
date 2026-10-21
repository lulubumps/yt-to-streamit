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
		'yts'          => [ 'Programas sincronizados', 'yts_page_programs' ],
		'yts-settings' => [ 'Ajustes', 'yts_page_settings' ],
		'yts-tools'    => [ 'Herramientas', 'yts_page_tools' ],
		'yts-imported' => [ 'Episodios importados', 'yts_page_imported' ],
		'yts-verify'   => [ 'Verificación del sistema', 'yts_page_verify' ],
	];
}

add_action( 'admin_menu', function () {
	add_menu_page( 'YouTube a Streamit', 'YouTube a Streamit', 'manage_options', 'yts', 'yts_page_programs', 'dashicons-video-alt3', 58 );
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

	// Lista de programas de Streamit para el selector con buscador
	if ( 'yts' === $page ) {
		$shows = [];
		foreach ( yts_shows() as $sh ) $shows[] = [ 'id' => (int) $sh->ID, 'title' => $sh->post_title ];
		wp_localize_script( 'yts-admin', 'YTS', [ 'shows' => $shows ] );
	}
} );

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

	echo '<div class="wrap yts-wrap"><h1>YouTube a Streamit</h1>';
	if ( ! yts_ready() ) echo '<div class="notice notice-error"><p><strong>El plugin Streamit no está activo</strong> (o su versión no tiene las funciones esperadas). Este plugin necesita Streamit 1.3.x.</p></div>';
	if ( $msg ) echo '<div class="notice notice-info is-dismissible"><p>' . esc_html( $msg ) . '</p></div>';
	if ( $err ) echo '<div class="notice notice-error"><p><strong>Último error de correo:</strong> ' . esc_html( $err ) . '</p></div>';

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
function yts_back( $msg, $page = 'yts' ) {
	if ( ! isset( yts_pages()[ $page ] ) ) $page = 'yts';
	wp_safe_redirect( add_query_arg( 'yts_msg', rawurlencode( $msg ), admin_url( 'admin.php?page=' . $page ) ) );
	exit;
}
