<?php
/**
 * Whether two website addresses mean the same website.
 *
 * Pure logic: no WordPress calls, so it is unit tested without WordPress.
 *
 * @package UptimeWire
 */

namespace UptimeWire;

defined( 'ABSPATH' ) || exit;

/**
 * Compares the website UptimeWire reports with the address of this WordPress.
 *
 * Allowed differences: the scheme (http or https), a trailing slash, the case of
 * the host name, a trailing dot, the default port of the scheme and one leading
 * "www." of the host name. Everything else is a different website, other
 * subdomains included, as in UptimeWire itself.
 */
final class Site_Match {

	/**
	 * Comparison key of a website address, or '' when it is not an http(s) address.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	public static function key( $url ) {
		$parts = parse_url( trim( (string) $url ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure class, unit tested without WordPress.

		if ( ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
			return '';
		}

		$scheme = strtolower( $parts['scheme'] );
		$host   = rtrim( strtolower( $parts['host'] ), '.' );

		if ( ( 'http' !== $scheme && 'https' !== $scheme ) || '' === $host ) {
			return '';
		}

		// bücher.example and xn--bcher-kva.example are one name; without the intl extension they stay different (the safe side).
		if ( 1 === preg_match( '/[\x80-\xff]/', $host ) && function_exists( 'idn_to_ascii' ) ) {
			$ascii = idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
			$host  = is_string( $ascii ) ? strtolower( $ascii ) : $host;
		}

		// www.example.com and example.com are one website, as in UptimeWire; only one leading "www." is dropped.
		if ( 0 === strpos( $host, 'www.' ) && strlen( $host ) > 4 ) {
			$host = substr( $host, 4 );
		}

		$port = isset( $parts['port'] ) ? (int) $parts['port'] : 0;

		if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
			$port = 0;
		}

		$key = $host . ( $port ? ':' . $port : '' ) . ( isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '' );

		return isset( $parts['query'] ) ? $key . '?' . $parts['query'] : $key;
	}

	/**
	 * Whether an address is the same website as one of the candidates.
	 *
	 * @param string   $url        Address to check.
	 * @param string[] $candidates Addresses of this website.
	 * @return bool
	 */
	public static function matches_any( $url, array $candidates ) {
		$key = self::key( $url );

		if ( '' === $key ) {
			return false;
		}

		foreach ( $candidates as $candidate ) {
			if ( self::key( $candidate ) === $key ) {
				return true;
			}
		}

		return false;
	}
}
