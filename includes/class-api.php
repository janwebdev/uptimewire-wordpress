<?php
/**
 * Requests to the UptimeWire API.
 *
 * @package UptimeWire
 */

namespace UptimeWire;

defined( 'ABSPATH' ) || exit;

/**
 * The only place that talks to UptimeWire. The address is fixed in code, HTTPS
 * only, the certificate is verified, redirects are not followed. Bodies of
 * requests and answers are never logged.
 */
final class Api {

	/** The UptimeWire API. Not configurable: a changed address would receive the credential. */
	const BASE_URL = 'https://api.uptimewire.com';

	/** Host of the page where a person approves a connection. */
	const CONNECT_HOST = 'app.uptimewire.com';

	/** OAuth client of every WordPress installation (BE-2 contract). */
	const CLIENT_ID = 'wordpress';

	/** RFC 8628 grant type. */
	const GRANT_TYPE = 'urn:ietf:params:oauth:grant-type:device_code';

	/** Seconds to wait for an answer while a person is connecting. */
	const TIMEOUT = 8;

	/**
	 * Sends a form to the API.
	 *
	 * @param string               $path    Path such as '/wp/v1/device'.
	 * @param array<string, mixed> $fields  Form fields.
	 * @param string               $token   Credential for the Authorization header, or ''.
	 * @param int                  $timeout Seconds.
	 * @return array{status: int, body: array<string, mixed>|null, retry_after: int|null}
	 */
	public static function post( $path, array $fields, $token = '', $timeout = self::TIMEOUT ) {
		return self::request( 'POST', $path, $fields, $token, $timeout );
	}

	/**
	 * Reads from the API.
	 *
	 * @param string $path    Path.
	 * @param string $token   Credential.
	 * @param int    $timeout Seconds.
	 * @return array{status: int, body: array<string, mixed>|null, retry_after: int|null}
	 */
	public static function get( $path, $token, $timeout = 5 ) {
		return self::request( 'GET', $path, null, $token, $timeout );
	}

	/**
	 * Performs a request. Status 0 means no answer at all (network, TLS, timeout).
	 *
	 * @param string                    $method  HTTP method.
	 * @param string                    $path    Path.
	 * @param array<string, mixed>|null $fields  Form fields.
	 * @param string                    $token   Credential or ''.
	 * @param int                       $timeout Seconds.
	 * @return array{status: int, body: array<string, mixed>|null, retry_after: int|null}
	 */
	private static function request( $method, $path, $fields, $token, $timeout ) {
		$headers = array( 'Accept' => 'application/json' );

		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$args = array(
			'method'              => $method,
			'timeout'             => $timeout,
			'redirection'         => 0,
			'sslverify'           => true,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => 65536,
			'user-agent'          => 'UptimeWire-WordPress/' . UPTIMEWIRE_VERSION . '; WordPress/' . get_bloginfo( 'version' ),
			'headers'             => $headers,
		);

		if ( null !== $fields ) {
			$args['body'] = $fields;
		}

		$response = wp_remote_request( self::BASE_URL . $path, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'status'      => 0,
				'body'        => null,
				'retry_after' => null,
			);
		}

		$body        = json_decode( wp_remote_retrieve_body( $response ), true );
		$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );

		return array(
			'status'      => (int) wp_remote_retrieve_response_code( $response ),
			'body'        => is_array( $body ) ? $body : null,
			'retry_after' => is_string( $retry_after ) && ctype_digit( $retry_after ) ? (int) $retry_after : null,
		);
	}
}
