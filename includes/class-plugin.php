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
	 * Capability required to see the monitoring data: the Overview and Settings
	 * screens, the Dashboard widget, Site Health and the status refresh.
	 *
	 * @return string
	 */
	public static function capability() {
		/**
		 * Filters the capability required to see UptimeWire's monitoring data.
		 *
		 * Viewing only: connecting, disconnecting and changing the website need
		 * the capability of the "uptimewire_manage_capability" filter as well.
		 *
		 * @param string $capability Default 'manage_options'.
		 */
		return self::usable( apply_filters( 'uptimewire_capability', 'manage_options' ) );
	}

	/**
	 * Capability required to manage the connection: connect, reconnect,
	 * disconnect, remove a copied connection and change the website to monitor.
	 *
	 * @return string
	 */
	public static function manage_capability() {
		/**
		 * Filters the capability required to manage the UptimeWire connection.
		 *
		 * A user needs this capability and the viewing one ("uptimewire_capability").
		 *
		 * @param string $capability Default 'manage_options'.
		 */
		return self::usable( apply_filters( 'uptimewire_manage_capability', 'manage_options' ) );
	}

	/**
	 * Whether the current user may see the monitoring data.
	 *
	 * @return bool
	 */
	public static function can_view() {
		return current_user_can( self::capability() );
	}

	/**
	 * Whether the current user may manage the connection. Managing includes
	 * seeing, so a wider viewing capability never widens who manages.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return self::can_view() && current_user_can( self::manage_capability() );
	}

	/**
	 * A filtered capability, or 'manage_options' when the filter gave nothing usable.
	 *
	 * @param mixed $capability Filter result.
	 * @return string
	 */
	private static function usable( $capability ) {
		return ( is_string( $capability ) && '' !== $capability ) ? $capability : 'manage_options';
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
	 * Addresses of this website: the website to monitor and the WordPress Site Address.
	 *
	 * A website UptimeWire reports is this site only when it matches one of them.
	 *
	 * @return string[]
	 */
	public static function own_urls() {
		return array_values( array_unique( array( self::site_url(), home_url( '/' ) ) ) );
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
	 * URL of the Overview screen, optionally with a result message.
	 *
	 * @param string $notice      Message code for the next screen, or ''.
	 * @param int    $retry_after Seconds to wait, shown with some messages.
	 * @return string
	 */
	public static function overview_url( $notice = '', $retry_after = 0 ) {
		$url = admin_url( 'admin.php?page=uptimewire' );

		if ( '' !== $notice ) {
			$url = add_query_arg( 'uptimewire_notice', $notice, $url );
		}

		if ( $retry_after > 0 ) {
			$url = add_query_arg( 'uptimewire_wait', (int) $retry_after, $url );
		}

		return $url;
	}

	/**
	 * URL of the Settings screen.
	 *
	 * @param string $notice Message code for the screen, or ''.
	 * @return string
	 */
	public static function settings_url( $notice = '' ) {
		$url = admin_url( 'admin.php?page=uptimewire-settings' );

		return '' !== $notice ? add_query_arg( 'uptimewire_notice', $notice, $url ) : $url;
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
