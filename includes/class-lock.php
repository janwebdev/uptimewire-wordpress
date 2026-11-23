<?php
/**
 * A short lock shared by all PHP processes of the site.
 *
 * @package UptimeWire
 */

namespace UptimeWire;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps two requests from asking UptimeWire the same thing at once (two tabs,
 * a double click). The lock is a row of the options table inserted only if it
 * is absent: the database decides who wins, and a lock left by a crashed
 * request expires by itself. Only the request that took a lock releases it.
 */
final class Lock {

	/**
	 * Values of the locks this request holds, by name.
	 *
	 * @var array<string, string>
	 */
	private static $held = array();

	/**
	 * Takes the lock, or returns false when another request holds it.
	 *
	 * @param string $name    Lock name.
	 * @param int    $seconds How long the lock lives at most.
	 * @return bool
	 */
	public static function acquire( $name, $seconds ) {
		global $wpdb;

		$option = self::option( $name );
		$now    = time();
		// The expiry comes first, so the database compares it as a number; the random part names the holder.
		$value = ( $now + $seconds ) . ':' . wp_generate_password( 16, false );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- An atomic lock: the options API has no "insert if absent". The row is never read through the options API or its cache.
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND CAST( SUBSTRING_INDEX( option_value, %s, 1 ) AS UNSIGNED ) < %d', $wpdb->options, $option, ':', $now ) );
		$inserted = $wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (option_name, option_value, autoload) VALUES (%s, %s, %s)', $wpdb->options, $option, $value, 'no' ) );
		// phpcs:enable

		if ( 1 !== $inserted ) {
			return false;
		}

		self::$held[ $name ] = $value;

		return true;
	}

	/**
	 * Releases the lock if this request still holds it.
	 *
	 * @param string $name Lock name.
	 * @return void
	 */
	public static function release( $name ) {
		global $wpdb;

		if ( ! isset( self::$held[ $name ] ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- See acquire().
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name = %s AND option_value = %s', $wpdb->options, self::option( $name ), self::$held[ $name ] ) );
		unset( self::$held[ $name ] );
	}

	/**
	 * Row name of a lock. Starts with "uptimewire_", so uninstall removes it.
	 *
	 * @param string $name Lock name.
	 * @return string
	 */
	private static function option( $name ) {
		return 'uptimewire_lock_' . $name;
	}
}
