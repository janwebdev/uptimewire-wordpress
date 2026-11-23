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
 * UPTIMEWIRE_TOKEN in wp-config.php. The plugin never prints, logs or sends it
 * to the browser (another plugin that logs every outgoing HTTP request would
 * see it in the request header), and it is not encrypted with a key from the
 * same server: that would look like protection without being one.
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
	 * The WordPress address is not the one the connection was made on: a copy of
	 * the site (staging, clone, backup restored elsewhere) or a moved site. Known
	 * locally, never stored: the credential is not used while it holds.
	 */
	const HEALTH_COPY = 'copy';

	/**
	 * Option with the fingerprint (SHA-256) of an UPTIMEWIRE_TOKEN that was
	 * disconnected but is still in wp-config.php: that value is not used again.
	 */
	const CONSTANT_OFF_OPTION = 'uptimewire_constant_off';

	/**
	 * Option remembering a disconnect UptimeWire did not confirm. No credential:
	 * only what the administrator needs to find the connection in UptimeWire.
	 */
	const UNCONFIRMED_OPTION = 'uptimewire_unconfirmed';

	/** Disconnect: UptimeWire confirmed that the credential no longer works. */
	const REVOKED = 'revoked';

	/** Disconnect: UptimeWire could not be asked or did not answer; the credential may still work there. */
	const UNCONFIRMED = 'unconfirmed';

	/** Disconnect: UptimeWire had already ended the connection. */
	const ALREADY_ENDED = 'already_ended';

	/** Disconnect: there was no usable credential to end. */
	const NOTHING = 'nothing';

	/** Disconnect: refused, the connection belongs to another website or WordPress address. */
	const REFUSED = 'refused';

	/**
	 * The connection record without the credential, or null when there is none.
	 *
	 * Keys: id, connected_at, site_url (what UptimeWire monitors), local_url (this
	 * site when it connected), connected_by (WordPress user ID), health.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get() {
		if ( null === self::raw_constant() && false !== get_option( self::CONSTANT_OFF_OPTION, false ) ) {
			// The disconnected UPTIMEWIRE_TOKEN was removed from wp-config.php.
			delete_option( self::CONSTANT_OFF_OPTION );
		}

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
	 * The value of UPTIMEWIRE_TOKEN, or null when the constant is not defined or
	 * holds a credential this site disconnected.
	 *
	 * @return string|null
	 */
	public static function configured_token() {
		$value = self::raw_constant();

		return null === $value || self::constant_left_behind() ? null : $value;
	}

	/**
	 * Whether UPTIMEWIRE_TOKEN still holds a credential this site disconnected.
	 * The plugin ignores it; only the administrator can remove it from wp-config.php.
	 *
	 * @return bool
	 */
	public static function constant_left_behind() {
		$value = self::raw_constant();
		$off   = get_option( self::CONSTANT_OFF_OPTION, '' );

		return null !== $value && is_string( $off ) && '' !== $off && hash_equals( $off, hash( 'sha256', $value ) );
	}

	/**
	 * The value of UPTIMEWIRE_TOKEN as this site sees it, or null.
	 *
	 * On a multisite network every site reads the same wp-config.php, but a
	 * credential belongs to one website: the constant counts for the main site
	 * only, and the other sites keep their own connections in their options.
	 *
	 * @return string|null
	 */
	private static function raw_constant() {
		if ( ! defined( self::TOKEN_CONSTANT ) || ( is_multisite() && ! is_main_site() ) ) {
			return null;
		}

		$value = constant( self::TOKEN_CONSTANT );

		return is_string( $value ) ? trim( $value ) : '';
	}

	/**
	 * Whether a record was made on another WordPress address than this one.
	 *
	 * The WordPress Site Address at the time of connecting is the identity of
	 * the installation: a copy keeps the database, and so the "Website to
	 * monitor" option, but not the address. Differences that mean the same
	 * address (http or https, a trailing slash, the case of the host) are
	 * allowed; "www." and the path are not.
	 *
	 * @param array<string, mixed> $record Record from get().
	 * @return bool
	 */
	public static function is_copy( array $record ) {
		if ( isset( $record['home'] ) && is_string( $record['home'] ) && '' !== $record['home'] ) {
			return ! Site_Match::matches_any( $record['home'], array( home_url( '/' ) ) );
		}

		// Connected before the WordPress address was recorded: the address chosen then must still be this site's.
		$local = isset( $record['local_url'] ) && is_string( $record['local_url'] ) ? $record['local_url'] : '';

		return '' !== $local && ! Site_Match::matches_any( $local, Plugin::own_urls() );
	}

	/**
	 * The WordPress address the record was made on, for messages.
	 *
	 * @param array<string, mixed> $record Record from get().
	 * @return string
	 */
	public static function recorded_home( array $record ) {
		foreach ( array( 'home', 'local_url' ) as $key ) {
			if ( isset( $record[ $key ] ) && is_string( $record[ $key ] ) && '' !== $record[ $key ] ) {
				return $record[ $key ];
			}
		}

		return '';
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
		// UptimeWire keeps one connection per website: a new one ends the one whose disconnect was not confirmed.
		delete_option( self::UNCONFIRMED_OPTION );
	}

	/**
	 * Health state of a record.
	 *
	 * @param array<string, mixed> $record Record from get().
	 * @return string One of the HEALTH_* constants, or '' when never checked.
	 */
	public static function health_state( array $record ) {
		if ( self::is_copy( $record ) ) {
			return self::HEALTH_COPY;
		}

		if ( null !== self::configured_token() && '' === self::token() ) {
			return self::HEALTH_BAD_CONSTANT;
		}

		if ( isset( $record['id'] ) && '' === self::token() ) {
			// The credential is gone (ended in UptimeWire, or UPTIMEWIRE_TOKEN removed from wp-config.php).
			return self::HEALTH_ENDED;
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

		if ( ! isset( $stored['id'] ) && null === self::configured_token() ) {
			// Disconnected while UptimeWire was answering: nothing is written back.
			return false;
		}

		$local = isset( $stored['local_url'] ) && is_string( $stored['local_url'] ) ? $stored['local_url'] : '';
		$same  = ! self::is_copy( $stored ) && Site_Match::matches_any( $site_url, Plugin::own_urls() ) && Site_Match::matches_any( $local, Plugin::own_urls() );

		if ( $same && empty( $stored['home'] ) ) {
			// A connection made before the WordPress address was recorded.
			$stored['home'] = home_url( '/' );
		}

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

		if ( null === $record || '' === $token || in_array( self::health_state( $record ), array( self::HEALTH_ENDED, self::HEALTH_COPY ), true ) ) {
			// A copy never sends the original site's credential.
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
				$stored['home']         = home_url( '/' );
				$stored['connected_by'] = 0;
			}

			$local = isset( $stored['local_url'] ) && is_string( $stored['local_url'] ) ? $stored['local_url'] : '';
			$same  = ! self::is_copy( $stored ) && Site_Match::matches_any( $data['site']['url'], Plugin::own_urls() ) && Site_Match::matches_any( $local, Plugin::own_urls() );

			if ( $same && empty( $stored['home'] ) ) {
				// A connection made before the WordPress address was recorded.
				$stored['home'] = home_url( '/' );
			}

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

	/**
	 * Whether this site may end its connection in UptimeWire.
	 *
	 * Only the installation the connection was made on, and only while
	 * UptimeWire names this website: a copy must never end the original's.
	 *
	 * @param array<string, mixed> $record Record from get().
	 * @return bool
	 */
	public static function may_end_remotely( array $record ) {
		return ! in_array( self::health_state( $record ), array( self::HEALTH_COPY, self::HEALTH_MISMATCH ), true );
	}

	/**
	 * Disconnects this site: ends the credential in UptimeWire (BE-1, RFC 7009),
	 * then forgets everything local, whatever UptimeWire answered.
	 *
	 * Only the credential's access from WordPress ends. The website, its
	 * monitoring, alerts and history stay in UptimeWire.
	 *
	 * @return array{remote: string, constant: bool} remote: REVOKED, UNCONFIRMED,
	 *         ALREADY_ENDED, NOTHING or REFUSED; constant: whether UPTIMEWIRE_TOKEN
	 *         stays in wp-config.php and must be removed by hand.
	 */
	public static function disconnect() {
		$record = self::get();

		if ( null === $record ) {
			// Already disconnected (a second click, another tab): only leftovers are cleared.
			self::forget_local();

			return array(
				'remote'   => self::NOTHING,
				'constant' => self::constant_left_behind(),
			);
		}

		if ( ! self::may_end_remotely( $record ) ) {
			return array(
				'remote'   => self::REFUSED,
				'constant' => false,
			);
		}

		$token  = self::token();
		$remote = self::NOTHING;

		if ( self::HEALTH_ENDED === self::health_state( $record ) ) {
			$remote = self::ALREADY_ENDED;
		} elseif ( '' !== $token ) {
			// RFC 7009: 200 for any credential, so a 200 means it no longer works, whether it did before or not.
			$answer = Api::post( '/wp/v1/revoke', array( 'token' => $token ), '', Api::TIMEOUT );
			$remote = 200 === $answer['status'] ? self::REVOKED : self::UNCONFIRMED;
		}

		$constant = null !== self::configured_token();

		self::forget_local();

		if ( self::UNCONFIRMED === $remote ) {
			update_option(
				self::UNCONFIRMED_OPTION,
				array(
					'site_url'      => isset( $record['site_url'] ) && is_string( $record['site_url'] ) ? $record['site_url'] : Plugin::site_url(),
					'account_label' => isset( $record['account_label'] ) ? (string) Status_Data::account_label( $record['account_label'] ) : '',
					'at'            => time(),
				),
				false
			);
		}

		return array(
			'remote'   => $remote,
			'constant' => $constant,
		);
	}

	/**
	 * Removes the connection from this installation only, without asking
	 * UptimeWire: for a copy, or a connection UptimeWire says is another website's.
	 * The original installation keeps its connection.
	 *
	 * @return bool Whether UPTIMEWIRE_TOKEN stays in wp-config.php and must be removed by hand.
	 */
	public static function remove_local() {
		$constant = null !== self::configured_token();

		self::forget_local();

		return $constant;
	}

	/**
	 * A disconnect UptimeWire did not confirm, or null.
	 *
	 * @return array{site_url: string, account_label: string, at: int}|null
	 */
	public static function unconfirmed() {
		$stored = get_option( self::UNCONFIRMED_OPTION, null );

		if ( ! is_array( $stored ) || ! isset( $stored['site_url'], $stored['at'] ) ) {
			return null;
		}

		return array(
			'site_url'      => (string) $stored['site_url'],
			'account_label' => isset( $stored['account_label'] ) ? (string) $stored['account_label'] : '',
			'at'            => (int) $stored['at'],
		);
	}

	/**
	 * Forgets the notice of an unconfirmed disconnect.
	 *
	 * @return void
	 */
	public static function dismiss_unconfirmed() {
		delete_option( self::UNCONFIRMED_OPTION );
	}

	/**
	 * Deletes the credential, the kept status and any authorization in progress.
	 * A credential in wp-config.php can't be deleted: its fingerprint is kept so
	 * that it is not used again until the administrator removes or changes it.
	 *
	 * @return void
	 */
	private static function forget_local() {
		$constant = self::configured_token();

		if ( null !== $constant ) {
			update_option( self::CONSTANT_OFF_OPTION, hash( 'sha256', $constant ), false );
		}

		delete_option( self::OPTION );
		Status::forget();
		Authorization::reset();
	}
}
