<?php
/**
 * A fake UptimeWire API for integration tests.
 *
 * @package UptimeWire
 */

namespace UptimeWire\Tests;

/**
 * Answers every request to https://api.uptimewire.com from a queue and records
 * it. A request with nothing queued fails as a network error and is recorded.
 * Any other outgoing request is refused, so a test can never reach the internet.
 */
final class Fake_Api {

	/**
	 * Queued answers per path.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	public static $queue = array();

	/**
	 * Recorded requests.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public static $calls = array();

	/**
	 * Hooks into the HTTP API. Called before WordPress loads, through the global filter array.
	 *
	 * @return void
	 */
	public static function install() {
		$GLOBALS['wp_filter']['pre_http_request'][1][] = array( // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Hooked before WordPress loads, the way WordPress itself supports it.
			'function'      => array( __CLASS__, 'answer' ),
			'accepted_args' => 3,
		);
	}

	/**
	 * Clears queue and records.
	 *
	 * @return void
	 */
	public static function reset() {
		self::$queue = array();
		self::$calls = array();
	}

	/**
	 * Queues one answer.
	 *
	 * @param string               $path    Path such as '/wp/v1/token'.
	 * @param int                  $status  HTTP status; 0 for a network error.
	 * @param array<string, mixed> $body    JSON body.
	 * @param array<string, mixed> $headers Response headers, lower-case names.
	 * @return void
	 */
	public static function queue( $path, $status, array $body = array(), array $headers = array() ) {
		self::$queue[ $path ][] = array(
			'status'  => $status,
			'body'    => $body,
			'headers' => $headers,
		);
	}

	/**
	 * Calls to one path.
	 *
	 * @param string $path Path.
	 * @return array<int, array<string, mixed>>
	 */
	public static function calls( $path = '' ) {
		return array_values(
			array_filter(
				self::$calls,
				static function ( $call ) use ( $path ) {
					return '' === $path || $call['path'] === $path;
				}
			)
		);
	}

	/**
	 * The pre_http_request filter.
	 *
	 * @param mixed                $pre  Earlier answer.
	 * @param array<string, mixed> $args Request arguments.
	 * @param string               $url  Address.
	 * @return mixed
	 */
	public static function answer( $pre, $args, $url ) {
		$prefix = 'https://api.uptimewire.com';

		if ( 0 !== strpos( $url, $prefix ) ) {
			return new \WP_Error( 'uptimewire_test', 'Tests make no other requests: ' . $url );
		}

		$path          = (string) wp_parse_url( $url, PHP_URL_PATH );
		self::$calls[] = array(
			'path' => $path,
			'url'  => $url,
			'args' => $args,
		);

		if ( empty( self::$queue[ $path ] ) ) {
			return new \WP_Error( 'http_request_failed', 'Nothing queued for ' . $path );
		}

		$next = array_shift( self::$queue[ $path ] );

		if ( 0 === $next['status'] ) {
			return new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );
		}

		return array(
			'headers'  => $next['headers'],
			'body'     => wp_json_encode( $next['body'] ),
			'response' => array(
				'code'    => $next['status'],
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}
}
