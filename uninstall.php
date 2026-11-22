<?php
/**
 * Removes everything the plugin stored when it is deleted.
 *
 * Deletes every option and transient whose name starts with "uptimewire_".
 * On a multisite network it visits only the sites that have such options.
 *
 * @package UptimeWire
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Names of the plugin's rows in an options table.
 *
 * @param string $table Options table name.
 * @return string[]
 */
function uptimewire_uninstall_option_names( $table ) {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time cleanup on uninstall; no API lists options by prefix.
	$names = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT option_name FROM %i WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s',
			$table,
			$wpdb->esc_like( 'uptimewire_' ) . '%',
			$wpdb->esc_like( '_transient_uptimewire_' ) . '%',
			$wpdb->esc_like( '_transient_timeout_uptimewire_' ) . '%'
		)
	);

	return is_array( $names ) ? $names : array();
}

/**
 * Deletes the plugin's options and transients of the current site.
 *
 * Goes through the options API so that a persistent object cache is cleared too.
 *
 * @param string[] $names Row names from uptimewire_uninstall_option_names().
 * @return void
 */
function uptimewire_uninstall_delete( array $names ) {
	foreach ( $names as $name ) {
		if ( 0 === strpos( $name, '_transient_timeout_' ) ) {
			continue;
		}

		if ( 0 === strpos( $name, '_transient_' ) ) {
			delete_transient( substr( $name, strlen( '_transient_' ) ) );
		} else {
			delete_option( $name );
		}
	}

	// Timeout rows left without their transient, if any.
	foreach ( $names as $name ) {
		if ( 0 === strpos( $name, '_transient_timeout_' ) ) {
			delete_option( $name );
		}
	}
}

/**
 * Deletes the network-wide options and site transients of a multisite network.
 *
 * @return void
 */
function uptimewire_uninstall_network() {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time cleanup on uninstall; no API lists network options by prefix.
	$keys = $wpdb->get_col(
		$wpdb->prepare(
			'SELECT meta_key FROM %i WHERE site_id = %d AND ( meta_key LIKE %s OR meta_key LIKE %s )',
			$wpdb->sitemeta,
			get_current_network_id(),
			$wpdb->esc_like( 'uptimewire_' ) . '%',
			$wpdb->esc_like( '_site_transient_uptimewire_' ) . '%'
		)
	);

	foreach ( (array) $keys as $key ) {
		if ( 0 === strpos( $key, '_site_transient_' ) ) {
			delete_site_transient( substr( $key, strlen( '_site_transient_' ) ) );
		} else {
			delete_site_option( $key );
		}
	}
}

// WordPress includes this file from inside a function.
global $wpdb;

if ( is_multisite() ) {
	$uptimewire_site_ids = get_sites(
		array(
			'fields'                 => 'ids',
			'number'                 => 0,
			'update_site_meta_cache' => false,
		)
	);

	foreach ( $uptimewire_site_ids as $uptimewire_site_id ) {
		$uptimewire_names = uptimewire_uninstall_option_names( $wpdb->get_blog_prefix( $uptimewire_site_id ) . 'options' );

		if ( array() === $uptimewire_names ) {
			continue;
		}

		switch_to_blog( $uptimewire_site_id );
		uptimewire_uninstall_delete( $uptimewire_names );
		restore_current_blog();
	}

	uptimewire_uninstall_network();
} else {
	uptimewire_uninstall_delete( uptimewire_uninstall_option_names( $wpdb->options ) );
}
