<?php
/**
 * Plugin-wide helpers: capability, stored site address, public URLs.
 *
 * @package UptimeWire
 */

namespace UptimeWire;

defined( 'ABSPATH' ) || exit;

/**
 * Small static helpers shared by the admin screens and uninstall.
 */
final class Plugin {

	/**
	 * Option holding the website address the administrator chose to monitor.
	 * Absent when the WordPress "Site Address" is used as is.
	 */
	const OPTION_SITE_URL = 'uptimewire_site_url';

	/**
	 * Public UptimeWire website. Links only; the plugin never requests it.
	 */
	const WEBSITE_URL = 'https://uptimewire.com/';

	/**
	 * Capability required for every UptimeWire screen and action.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Filters the capability required to see and use UptimeWire screens.
		 *
		 * @param string $capability Default 'manage_options'.
		 */
		$capability = apply_filters( 'uptimewire_capability', 'manage_options' );

		return ( is_string( $capability ) && '' !== $capability ) ? $capability : 'manage_options';
	}

	/**
	 * Whether the current user may use the plugin.
	 *
	 * @return bool
	 */
	public static function current_user_can() {
		return current_user_can( self::capability() );
	}

	/**
	 * The website address to monitor: the saved one, or the WordPress Site Address.
	 *
	 * @return string
	 */
	public static function site_url() {
		$saved = get_option( self::OPTION_SITE_URL, '' );

		if ( is_string( $saved ) && '' !== $saved ) {
			return $saved;
		}

		return home_url( '/' );
	}

	/**
	 * Whether the administrator replaced the WordPress Site Address.
	 *
	 * @return bool
	 */
	public static function site_url_is_custom() {
		$saved = get_option( self::OPTION_SITE_URL, '' );

		return is_string( $saved ) && '' !== $saved;
	}

	/**
	 * Absolute URL of a page on the public UptimeWire website.
	 *
	 * @param string $path Path without a leading slash, e.g. 'terms/'.
	 * @return string
	 */
	public static function website_url( $path = '' ) {
		return self::WEBSITE_URL . ltrim( $path, '/' );
	}
}
