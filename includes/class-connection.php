<?php
/**
 * The established connection: its credential and its health.
 *
 * @package UptimeWire
 */

namespace UptimeWire;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the connection that UptimeWire issued and checks that it still lives.
 *
 * The credential is kept in the option "uptimewire_connection" (not autoloaded)
 * or, for sites that keep secrets out of the database, in the constant
 * UPTIMEWIRE_TOKEN in wp-config.php. It is never printed, logged or sent to the
 * browser, and it is not encrypted with a key from the same server: that would
 * look like protection without being one.
 */
final class Connection {

	/** Option with the connection record. */
	const OPTION = 'uptimewire_connection';

	/** Optional constant in wp-config.php holding the credential. */
	const TOKEN_CONSTANT = 'UPTIMEWIRE_TOKEN';

	/** Shape of a credential issued by UptimeWire. */
	const TOKEN_PATTERN = '/^uwp_[0-9a-f]{64}$/D';

	/** How long a health check stays fresh, in seconds. */
	const HEALTH_TTL = 300;

	/** UptimeWire confirmed the connection. */
	const HEALTH_OK = 'ok';

	/** UptimeWire answered that the connection ended. */
	const HEALTH_ENDED = 'ended';

	/** UptimeWire could not be reached; the last confirmation stays. */
	const HEALTH_UNREACHABLE = 'unreachable';

	/** The connection belongs to another website address (a copy of the site, a moved site). */
	const HEALTH_MISMATCH = 'mismatch';

	/** UPTIMEWIRE_TOKEN is set but is not a credential. */
	const HEALTH_BAD_CONSTANT = 'bad_constant';

	/**
	 * The connection record without the credential, or null when there is none.
	 *
	 * Keys: id, connected_at, site_url (what UptimeWire monitors), local_url (this
	 * site when it connected), connected_by (WordPress user ID), health.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get() {
		$record = get_option( self::OPTION, null );

		if ( ! is_array( $record ) ) {
			$record = null;
		}

		if ( null !== self::configured_token() ) {
			// The constant wins; a copy of the credential left in the database is removed.
			if ( null !== $record && isset( $record['token'] ) ) {
				unset( $record['token'] );
				update_option( self::OPTION, $record, false );
			}

			if ( null === $record ) {
				$record = array( 'health' => array() );
			}
		} elseif ( null === $record || ! isset( $record['id'] ) ) {
			return null;
		}

		unset( $record['token'] );

		return $record;
	}

	/**
	 * Whether a credential exists that UptimeWire may still accept.
	 *
	 * @return bool
	 */
	public static function is_live() {
		$record = self::get();

		return null !== $record && '' !== self::token() && self::HEALTH_ENDED !== self::health_state( $record );
	}

	/**
	 * The credential: from wp-config.php if set there, otherwise from the database.
	 *
	 * @return string '' when there is none or it is malformed.
	 */
	public static function token() {
		$configured = self::configured_token();

		if ( null !== $configured ) {
			return 1 === preg_match( self::TOKEN_PATTERN, $configured ) ? $configured : '';
		}

		$record = get_option( self::OPTION, null );

		return is_array( $record ) && isset( $record['token'] ) && is_string( $record['token'] ) ? $record['token'] : '';
	}

	/**
	 * The value of UPTIMEWIRE_TOKEN, or null when the constant is not defined.
	 *
	 * On a multisite network every site reads the same wp-config.php, but a
	 * credential belongs to one website: the constant counts for the main site
	 * only, and the other sites keep their own connections in their options.
	 *
	 * @return string|null
	 */
	public static function configured_token() {
		if ( ! defined( self::TOKEN_CONSTANT ) || ( is_multisite() && ! is_main_site() ) ) {
			return null;
		}

		$value = constant( self::TOKEN_CONSTANT );

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Saves a new connection. Replaces whatever was stored before.
	 *
	 * @param string               $token  Credential.
	 * @param array<string, mixed> $record Record without the credential.
	 * @return void
	 */
	public static function save( $token, array $record ) {
		$record['token']  = $token;
		$record['health'] = array(
			'state'        => self::HEALTH_OK,
			'checked_at'   => time(),
			'confirmed_at' => time(),
		);

		update_option( self::OPTION, $record, false );
		// Data kept for an earlier connection is not this one's.
		Status::forget();
	}

	/**
	 * Health state of a record.
	 *
	 * @param array<string, mixed> $record Record from get().
	 * @return string One of the HEALTH_* constants, or '' when never checked.
	 */
	public static function health_state( array $record ) {
		if ( null !== self::configured_token() && '' === self::token() ) {
			return self::HEALTH_BAD_CONSTANT;
		}

		return isset( $record['health']['state'] ) && is_string( $record['health']['state'] ) ? $record['health']['state'] : '';
	}

	/**
	 * Records that UptimeWire answered for this connection with a website.
	 *
	 * Used by every read that names the website (the status answer). When the
	 * website is not this one, the connection is marked as a mismatch and the
	 * caller must not show the data.
	 *
	 * @param string      $site_url      Website UptimeWire monitors.
	 * @param string|null $account_label Account number ACC-…, or null when not given.
	 * @return bool Whether the website is this one.
	 */
	public static function confirm( $site_url, $account_label ) {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$local  = isset( $stored['local_url'] ) && is_string( $stored['local_url'] ) ? $stored['local_url'] : '';
		$same   = Site_Match::matches_any( $site_url, Plugin::own_urls() ) && Site_Match::matches_any( $local, Plugin::own_urls() );

		$stored['site_url'] = $site_url;
		$stored['health']   = array(
			'state'        => $same ? self::HEALTH_OK : self::HEALTH_MISMATCH,
			'checked_at'   => time(),
			'confirmed_at' => time(),
		);

		if ( null !== $account_label ) {
			$stored['account_label'] = $account_label;
		}

		update_option( self::OPTION, $stored, false );

		return $same;
	}

	/**
	 * Records that UptimeWire ended the connection: the credential is forgotten.
	 *
	 * @return void
	 */
	public static function end() {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$health = isset( $stored['health'] ) && is_array( $stored['health'] ) ? $stored['health'] : array();

		$health['state']      = self::HEALTH_ENDED;
		$health['checked_at'] = time();
		$stored['health']     = $health;
		unset( $stored['token'] );

		if ( isset( $stored['id'] ) || null !== self::configured_token() ) {
			update_option( self::OPTION, $stored, false );
		}
	}

	/**
	 * The account number ACC-… UptimeWire last named for this connection, or ''.
	 *
	 * @return string
	 */
	public static function account_label() {
		$record = self::get();

		return null !== $record && isset( $record['account_label'] ) ? (string) Status_Data::account_label( $record['account_label'] ) : '';
	}

	/**
	 * Asks UptimeWire whether the connection lives, at most once per HEALTH_TTL.
	 *
	 * Runs only when an administrator opens a plugin screen. The answer must name
	 * this website; otherwise the connection is reported as a mismatch and no
	 * data of another website is shown.
	 *
	 * @param bool $force Ignore the freshness of the last check.
	 * @return void
	 */
	public static function check_health( $force = false ) {
		$record = self::get();
		$token  = self::token();

		if ( null === $record || '' === $token || self::HEALTH_ENDED === self::health_state( $record ) ) {
			return;
		}

		$checked_at = isset( $record['health']['checked_at'] ) ? (int) $record['health']['checked_at'] : 0;

		if ( ! $force && time() - $checked_at < self::HEALTH_TTL ) {
			return;
		}

		if ( ! Lock::acquire( 'health', 15 ) ) {
			return;
		}

		$answer = Api::get( '/wp/v1/connection', $token );
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$health = isset( $stored['health'] ) && is_array( $stored['health'] ) ? $stored['health'] : array();

		$health['checked_at'] = time();
		$data                 = isset( $answer['body']['data'] ) && is_array( $answer['body']['data'] ) ? $answer['body']['data'] : null;

		if ( 200 === $answer['status'] && null !== $data && isset( $data['site']['url'] ) && is_string( $data['site']['url'] ) ) {
			$stored['site_url'] = $data['site']['url'];
			$label              = Status_Data::account_label( isset( $data['account_label'] ) ? $data['account_label'] : null );

			if ( null !== $label ) {
				$stored['account_label'] = $label;
			}

			if ( ! isset( $stored['id'] ) && isset( $data['id'] ) && is_string( $data['id'] ) ) {
				// A credential configured in wp-config.php before this site ever connected.
				$stored['id']           = $data['id'];
				$stored['connected_at'] = isset( $data['connected_at'] ) && is_string( $data['connected_at'] ) ? $data['connected_at'] : '';
				$stored['local_url']    = Plugin::site_url();
				$stored['connected_by'] = 0;
			}

			$local                  = isset( $stored['local_url'] ) && is_string( $stored['local_url'] ) ? $stored['local_url'] : '';
			$same                   = Site_Match::matches_any( $data['site']['url'], Plugin::own_urls() ) && Site_Match::matches_any( $local, Plugin::own_urls() );
			$health['state']        = $same ? self::HEALTH_OK : self::HEALTH_MISMATCH;
			$health['confirmed_at'] = time();
		} elseif ( 401 === $answer['status'] ) {
			// The connection ended in UptimeWire; the credential is useless and is forgotten.
			$health['state'] = self::HEALTH_ENDED;
			unset( $stored['token'] );
			Status::forget();
		} elseif ( 429 !== $answer['status'] ) {
			$health['state'] = self::HEALTH_UNREACHABLE;
		}

		$stored['health'] = $health;

		if ( isset( $stored['id'] ) || null !== self::configured_token() ) {
			update_option( self::OPTION, $stored, false );
		}

		Lock::release( 'health' );
	}
}
