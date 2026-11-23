<?php
/**
 * Removes everything the plugin stored when it is deleted.
 *
 * First asks UptimeWire, once and briefly, to end the connection of each site
 * that made its own (best effort: deleting the plugin never waits long or fails
 * because UptimeWire can't be reached, and a copied site never ends the
 * original's connection). Then deletes every option and transient whose name
 * starts with "uptimewire_". On a multisite network it visits only the sites
 * that have such options. The UptimeWire account, the monitored website and its
 * history are not touched: they live in UptimeWire.
 *
 * @package UptimeWire
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-site-match.php';

/**
 * Asks UptimeWire to end the current site's connection (BE-1 revoke, RFC 7009),
 * if this installation made it and still holds its credential.
 *
 * @param float $budget Seconds left for all requests of this uninstall; reduced here.
 * @return void
 */
function uptimewire_uninstall_revoke( &$budget ) {
	$record = get_option( 'uptimewire_connection' );

	if ( $budget < 1 || ! is_array( $record ) || ! isset( $record['id'] ) ) {
		return;
	}

	// The WordPress address the connection was made on must be this one: a copy keeps the database, not the address.
	// Compared by fingerprint, as UptimeWire\Connection::is_copy() does: migration tools rewrite plain addresses.
	if ( isset( $record['home_key'] ) && is_string( $record['home_key'] ) && '' !== $record['home_key'] ) {
		$own = hash_equals( $record['home_key'], hash( 'sha256', \UptimeWire\Site_Match::key( home_url( '/' ) ) ) );
	} else {
		$saved = get_option( 'uptimewire_site_url', '' );
		$own   = isset( $record['local_url'] ) && is_string( $record['local_url'] )
			&& \UptimeWire\Site_Match::matches_any( $record['local_url'], array( is_string( $saved ) && '' !== $saved ? $saved : home_url( '/' ), home_url( '/' ) ) );
	}

	$health = isset( $record['health']['state'] ) ? $record['health']['state'] : '';

	if ( ! $own || 'ended' === $health || 'mismatch' === $health ) {
		return;
	}

	$token = isset( $record['token'] ) && is_string( $record['token'] ) ? $record['token'] : '';

	if ( defined( 'UPTIMEWIRE_TOKEN' ) && ( ! is_multisite() || is_main_site() ) ) {
		// The constant counts for the main site only, unless this site disconnected it already.
		$value = is_string( UPTIMEWIRE_TOKEN ) ? trim( UPTIMEWIRE_TOKEN ) : '';
		$off   = get_option( 'uptimewire_constant_off', '' );
		$token = is_string( $off ) && '' !== $off && hash_equals( $off, hash( 'sha256', $value ) ) ? '' : $value;
	}

	if ( 1 !== preg_match( '/^uwp_[0-9a-f]{64}$/D', $token ) ) {
		return;
	}

	$started = microtime( true );

	// The address of UptimeWire\Api::BASE_URL; the answer is not needed: local data is deleted whatever it is.
	wp_remote_post(
		'https://api.uptimewire.com/wp/v1/revoke',
		array(
			'timeout'             => min( 3, (int) $budget ),
			'redirection'         => 0,
			'sslverify'           => true,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => 4096,
			'user-agent'          => 'UptimeWire-WordPress/uninstall; WordPress/' . get_bloginfo( 'version' ),
			'headers'             => array( 'Accept' => 'application/json' ),
			'body'                => array( 'token' => $token ),
		)
	);

	$budget -= microtime( true ) - $started;
}

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
	// With a persistent object cache these live only in that cache, not in the table.
	foreach ( array( 'uptimewire_pending', 'uptimewire_cancelled', 'uptimewire_proof_seen' ) as $transient ) {
		delete_transient( $transient );
	}

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

// At most ten seconds of requests in all, so that deleting the plugin on a large network still finishes.
$uptimewire_budget = 10.0;

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
		uptimewire_uninstall_revoke( $uptimewire_budget );
		uptimewire_uninstall_delete( $uptimewire_names );
		restore_current_blog();
	}

	uptimewire_uninstall_network();
} else {
	uptimewire_uninstall_revoke( $uptimewire_budget );
	uptimewire_uninstall_delete( uptimewire_uninstall_option_names( $wpdb->options ) );
}
