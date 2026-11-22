<?php
/**
 * Validation of the "Website to monitor" address typed by an administrator.
 *
 * Pure logic: no WordPress calls, so it is unit tested without WordPress.
 *
 * @package UptimeWire
 */

namespace UptimeWire;

defined( 'ABSPATH' ) || exit;

/**
 * Normalizes a website address or rejects it.
 */
final class Site_Url {

	/** Longest address accepted. */
	const MAX_LENGTH = 2048;

	/**
	 * Normalized address, or an empty string when the input is not a website address.
	 *
	 * Accepted: http or https, a host name or IP, an optional port and path.
	 * Rejected: other schemes, user names or passwords, query strings and fragments.
	 * The scheme and host are lower-cased; an empty path becomes "/".
	 *
	 * @param string $input Raw input.
	 * @return string
	 */
	public static function normalize( $input ) {
		$input = trim( (string) $input );

		if ( '' === $input || strlen( $input ) > self::MAX_LENGTH || preg_match( '/[\s\x00-\x1f\x7f<>"\'\\\\]/', $input ) ) {
			return '';
		}

		$parts = parse_url( $input ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure class, unit tested without WordPress.

		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( $parts['scheme'] );
		$host   = strtolower( $parts['host'] );

		if ( ( 'http' !== $scheme && 'https' !== $scheme )
			|| isset( $parts['user'] ) || isset( $parts['pass'] )
			|| isset( $parts['query'] ) || isset( $parts['fragment'] )
			|| ! self::is_valid_host( $host ) ) {
			return '';
		}

		$port = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';

		return $scheme . '://' . $host . $port . $path;
	}

	/**
	 * Whether a host is a DNS name or an IP address.
	 *
	 * @param string $host Lower-cased host; IPv6 in brackets.
	 * @return bool
	 */
	private static function is_valid_host( $host ) {
		if ( '[' === substr( $host, 0, 1 ) ) {
			return ']' === substr( $host, -1 ) && false !== filter_var( substr( $host, 1, -1 ), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 );
		}

		// Letters, digits and hyphens in dot-separated labels; bytes above 0x7F allow internationalized names.
		return 1 === preg_match( '/^(?=.{1,253}$)([a-z0-9\x80-\xff]([a-z0-9\x80-\xff-]{0,61}[a-z0-9\x80-\xff])?)(\.[a-z0-9\x80-\xff]([a-z0-9\x80-\xff-]{0,61}[a-z0-9\x80-\xff])?)*\.?$/', $host );
	}
}
