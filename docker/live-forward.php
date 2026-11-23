<?php
/**
 * Test stand only (copied to mu-plugins by docker/live.sh): sends the plugin's
 * requests for https://api.uptimewire.com to a local copy of UptimeWire at
 * http://11.77.42.90 with the API host name, and returns its real answers.
 * The plugin itself is unchanged: its address stays fixed in code.
 *
 * @package UptimeWire
 */

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( false !== $pre || 0 !== strpos( $url, 'https://api.uptimewire.com/' ) || ! empty( $args['uwtest_forwarded'] ) ) {
			return $pre;
		}

		$args['uwtest_forwarded']   = true;
		$args['reject_unsafe_urls'] = false;
		$args['headers']['Host']    = 'api.uptimewire.com';
		$response                   = wp_remote_request( 'http://11.77.42.90' . substr( $url, strlen( 'https://api.uptimewire.com' ) ), $args );

		file_put_contents( WP_CONTENT_DIR . '/live-log.txt', gmdate( 'H:i:s ' ) . wp_parse_url( $url, PHP_URL_PATH ) . ' -> ' . ( is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response ) ) . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore

		return $response;
	},
	1,
	3
);
