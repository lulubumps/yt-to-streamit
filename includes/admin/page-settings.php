<?php
/**
 * Página «Ajustes»
 *
 * Opciones generales del plugin: frecuencia, correo de aviso, cómo se cargan los episodios y API key de YouTube.
 * (Las opciones de importación —fecha de inicio y estado— están en «Programas sincronizados».)
 *
 * @package YT_To_Streamit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function yts_page_settings() {
	if ( ! current_user_can( 'manage_options' ) ) return;
	$s = yts_settings();

	yts_admin_header( 'yts-settings' );
	?>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="yts_save">
		<input type="hidden" name="yts_section" value="settings">
		<?php wp_nonce_field( 'yts_save' ); ?>

		<h2><?php esc_html_e( 'Automatic sync', 'yt-to-streamit' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'Frequency', 'yt-to-streamit' ); ?></th><td>
				<select name="frequency">
					<?php foreach ( yts_frequencies() as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( yts_frequency(), $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'How often it checks YouTube on its own. To import right away use “Sync now”. WP-Cron only runs when someone visits the site.', 'yt-to-streamit' ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'Maximum per run', 'yt-to-streamit' ); ?></th><td><input type="number" min="1" max="100" class="yts-num" name="max_per_run" value="<?php echo (int) $s['max_per_run']; ?>"> <?php esc_html_e( 'episodes per program in each sync', 'yt-to-streamit' ); ?>
				<p class="description"><?php esc_html_e( 'A limit to avoid exhausting the server time when converting thumbnails. If videos are left pending, the plugin continues on its own a few minutes later.', 'yt-to-streamit' ); ?></p></td></tr>
		</table>

		<h2><?php esc_html_e( 'Email notices', 'yt-to-streamit' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'Notification email', 'yt-to-streamit' ); ?></th><td><input type="email" name="notify_email" value="<?php echo esc_attr( $s['notify_email'] ); ?>" class="regular-text" placeholder="<?php echo esc_attr( get_option( 'admin_email' ) ); ?>">
				<p class="description"><?php esc_html_e( 'You receive the notice when a program reaches the configured episode. Empty = administrator email.', 'yt-to-streamit' ); ?></p></td></tr>
		</table>

		<h2><?php esc_html_e( 'Episodes', 'yt-to-streamit' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'How the video is loaded', 'yt-to-streamit' ); ?></th><td>
				<select name="video_method">
					<option value="episode_embed" <?php selected( $s['video_method'], 'episode_embed' ); ?>><?php esc_html_e( 'Embed code (YouTube iframe)', 'yt-to-streamit' ); ?></option>
					<option value="episode_url" <?php selected( $s['video_method'], 'episode_url' ); ?>><?php esc_html_e( 'Video URL', 'yt-to-streamit' ); ?></option>
				</select>
				<p class="description"><?php esc_html_e( 'Use the same option you already use to load your episodes today.', 'yt-to-streamit' ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'Model episode ID', 'yt-to-streamit' ); ?></th><td><input type="number" class="yts-num yts-num-wide" name="template_episode" value="<?php echo (int) $s['template_episode'] ? (int) $s['template_episode'] : ''; ?>">
				<p class="description"><?php esc_html_e( 'Optional but recommended: the other Streamit fields (rating, visibility, etc.) are copied from this episode.', 'yt-to-streamit' ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'Author of the episodes', 'yt-to-streamit' ); ?></th><td>
				<select name="author_id">
					<option value="0"><?php esc_html_e( 'Automatic (first administrator)', 'yt-to-streamit' ); ?></option>
					<?php foreach ( yts_admins() as $u ) : ?>
						<option value="<?php echo (int) $u->ID; ?>" <?php selected( (int) $s['author_id'], (int) $u->ID ); ?>><?php echo esc_html( $u->display_name . ' (' . $u->user_login . ')' ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Administrator user the imported episodes are assigned to.', 'yt-to-streamit' ); ?></p></td></tr>
			<tr><th><?php esc_html_e( 'WebP thumbnails', 'yt-to-streamit' ); ?></th><td><label><input type="checkbox" name="thumb_webp" value="1" <?php checked( $s['thumb_webp'] ); ?>> <?php esc_html_e( 'Convert YouTube thumbnails to WebP (lighter)', 'yt-to-streamit' ); ?></label>
				<p class="description"><?php esc_html_e( 'If the server does not support WebP, the original JPG is saved and a notice is left in the error log.', 'yt-to-streamit' ); ?></p></td></tr>
		</table>

		<h2>YouTube</h2>
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'API key (optional)', 'yt-to-streamit' ); ?></th><td>
				<?php if ( defined( 'YTS_API_KEY' ) && YTS_API_KEY ) : ?>
					<em><?php esc_html_e( 'Defined in wp-config.php (YTS_API_KEY constant).', 'yt-to-streamit' ); ?></em>
				<?php else : ?>
					<input type="password" name="youtube_api_key" value="" class="regular-text" autocomplete="off" placeholder="<?php echo $s['youtube_api_key'] ? esc_attr__( '•••••••• (saved; type another one to replace it)', 'yt-to-streamit' ) : esc_attr__( 'Paste your API key here', 'yt-to-streamit' ); ?>">
					<?php if ( $s['youtube_api_key'] ) : ?><label>&nbsp;<input type="checkbox" name="clear_api_key" value="1"> <?php esc_html_e( 'Delete the saved key', 'yt-to-streamit' ); ?></label><?php endif; ?>
				<?php endif; ?>
				<p class="description"><?php echo wp_kses( __( 'Recommended: the YouTube API is more reliable than the RSS feed, which sometimes answers 404. It is free (Google Cloud → YouTube Data API v3). You can also define it in <code>wp-config.php</code> with <code>define( \'YTS_API_KEY\', \'your-key\' );</code> so it is not stored in the database.', 'yt-to-streamit' ), [ 'code' => [] ] ); ?></p></td></tr>
		</table>

		<?php submit_button( __( 'Save changes', 'yt-to-streamit' ) ); ?>
	</form>
	<?php
	yts_admin_footer();
}
