<?php
/**
 * Test stand only (copied to mu-plugins): logs every outgoing HTTP request and
 * marks the ones whose call stack passes through the UptimeWire plugin.
 *
 * @package UptimeWire
 */

add_filter(
	'pre_http_request',
	static function ( $pre, $args, $url ) {
		$from_plugin = false;
		foreach ( debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ) as $frame ) { // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			if ( isset( $frame['file'] ) && false !== strpos( $frame['file'], '/plugins/uptimewire/' ) ) {
				$from_plugin = true;
				break;
			}
		}
		file_put_contents( WP_CONTENT_DIR . '/http-log.txt', ( $from_plugin ? 'UPTIMEWIRE ' : 'core ' ) . $url . "\n", FILE_APPEND ); // phpcs:ignore
		return $pre;
	},
	1,
	3
);
