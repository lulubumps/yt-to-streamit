<?php
/**
 * Miniaturas
 *
 * Descarga la miniatura del video, la convierte a WebP (si el servidor puede) y la sube a la biblioteca de medios.
 *
 * @package YT_To_Streamit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

function yts_sideload( $video_id, $title, $s = [] ) {
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$want_webp = ! isset( $s['thumb_webp'] ) || $s['thumb_webp'];
	$can_webp  = $want_webp && wp_image_editor_supports( [ 'mime_type' => 'image/webp' ] );
	if ( $want_webp && ! $can_webp ) {
		error_log( '[yts] This server does not support WebP (GD/Imagick); JPG will be used.' );
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
