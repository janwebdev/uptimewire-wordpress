<?php
/**
 * Detects sites UptimeWire cannot or should not monitor.
 *
 * Pure logic: no WordPress calls, so it is unit tested without WordPress.
 *
 * @package UptimeWire
 */

namespace UptimeWire;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the environment type and the website address into warnings.
 */
final class Environment {

	/** The site is not reachable from the internet; connecting is blocked. */
	const WARNING_LOCAL = 'local';

	/** The site looks like staging or development; connecting needs a confirmation. */
	const WARNING_STAGING = 'staging';

	/** The site has no TLS, so there is no certificate to check. */
	const WARNING_NO_HTTPS = 'no_https';

	/**
	 * Host suffixes that never resolve on the public internet.
	 *
	 * @var string[]
	 */
	const PRIVATE_SUFFIXES = array( '.localhost', '.local', '.test', '.internal', '.home.arpa', '.invalid', '.example' );

	/**
	 * Warnings for a website, most important first.
	 *
	 * A local site gets only the local warning: the others do not matter
	 * when the site cannot be monitored at all.
	 *
	 * @param string $environment_type Value of wp_get_environment_type().
	 * @param string $url              Website address to monitor.
	 * @return string[] List of WARNING_* constants.
	 */
	public static function warnings( $environment_type, $url ) {
		$parts  = self::parse( $url );
		$scheme = isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		$host   = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';

		if ( 'local' === $environment_type || '' === $host || self::is_private_host( $host ) ) {
			return array( self::WARNING_LOCAL );
		}

		$warnings = array();

		if ( 'development' === $environment_type || 'staging' === $environment_type ) {
			$warnings[] = self::WARNING_STAGING;
		}

		if ( 'https' !== $scheme ) {
			$warnings[] = self::WARNING_NO_HTTPS;
		}

		return $warnings;
	}

	/**
	 * Whether a host name or IP address cannot be reached from the internet.
	 *
	 * @param string $host Host without port; IPv6 may be in brackets.
	 * @return bool
	 */
	public static function is_private_host( $host ) {
		$host = strtolower( rtrim( trim( $host, '[]' ), '.' ) );

		if ( '' === $host || 'localhost' === $host ) {
			return true;
		}

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return self::is_private_ip( $host );
		}

		foreach ( self::PRIVATE_SUFFIXES as $suffix ) {
			if ( substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		// A single-label name such as "mysite" exists only inside a local network.
		return false === strpos( $host, '.' );
	}

	/**
	 * Whether an IP address is private, loopback, link-local or otherwise reserved.
	 *
	 * @param string $ip Valid IPv4 or IPv6 address.
	 * @return bool
	 */
	private static function is_private_ip( $ip ) {
		if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
			return true;
		}

		// Carrier-grade NAT (100.64.0.0/10) is not covered by the PHP flags.
		$long = ip2long( $ip );

		return false !== $long && ( $long & 0xFFC00000 ) === ( ip2long( '100.64.0.0' ) & 0xFFC00000 );
	}

	/**
	 * Components of a URL, or an empty array when it cannot be parsed.
	 *
	 * @param string $url URL.
	 * @return array<string, mixed>
	 */
	private static function parse( $url ) {
		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure class, unit tested without WordPress.

		return is_array( $parts ) ? $parts : array();
	}
}
