<?php
/**
 * Test stand only (copied to mu-plugins by docker/smoke-wp2.sh): answers the
 * plugin's requests to https://api.uptimewire.com from the option
 * "uwtest_fake_api" and logs every call to wp-content/fake-api-log.txt.
 *
 * Option shape: array( 'delay' => seconds, '/wp/v1/device' => array( answer, ... ), ... ).
 * An answer is array( status, body array, headers array ). The last answer of a
 * path is repeated, so parallel requests all get one.
 *
 * @package UptimeWire
 */

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		if ( 0 !== strpos( $url, 'https://api.uptimewire.com/' ) ) {
			return $pre;
		}

		$fake = get_option( 'uwtest_fake_api' );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );

		file_put_contents( WP_CONTENT_DIR . '/fake-api-log.txt', $path . ' ' . wp_json_encode( $args['body'] ) . "\n", FILE_APPEND | LOCK_EX ); // phpcs:ignore

		if ( ! is_array( $fake ) || empty( $fake[ $path ] ) ) {
			return new WP_Error( 'http_request_failed', 'stand: nothing queued' );
		}

		if ( ! empty( $fake['delay'] ) ) {
			sleep( (int) $fake['delay'] );
		}

		$answer = count( $fake[ $path ] ) > 1 ? array_shift( $fake[ $path ] ) : $fake[ $path ][0];
		update_option( 'uwtest_fake_api', $fake, false );

		return array(
			'headers'  => isset( $answer[2] ) ? $answer[2] : array(),
			'body'     => wp_json_encode( $answer[1] ),
			'response' => array(
				'code'    => $answer[0],
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	},
	1,
	3
);
