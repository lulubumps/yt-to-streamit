<?php
/**
 * Lectura de YouTube
 *
 * Obtiene los videos de una playlist: API oficial (si hay API key) y feed RSS con reintentos como respaldo.
 *
 * @package YT_To_Streamit
 */

if ( ! defined( 'ABSPATH' ) ) exit;

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
						if ( ! yts_valid_video_id( (string) $yt->videoId ) ) continue; // ID raro: se ignora
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
			if ( ! yts_valid_video_id( $vid ) || ! $pub || in_array( $title, [ 'Private video', 'Deleted video' ], true ) ) continue; // privados o borrados
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
	$key = yts_api_key( $s );
	if ( $key ) {
		$r = yts_fetch_api( $playlist, $key );
		if ( ! $r['error'] ) { $r['source'] = 'API'; return $r; }
		$errors[] = $r['error'];
	}
	$r = yts_fetch_rss( $playlist );
	if ( ! $r['error'] ) { $r['source'] = 'RSS'; return $r; }
	$errors[] = 'RSS: ' . $r['error'];
	return [ 'error' => implode( ' | ', $errors ), 'entries' => [], 'source' => '' ];
}
