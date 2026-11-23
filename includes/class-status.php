<?php
/**
 * Monitoring status of this website, read from UptimeWire and kept locally.
 *
 * @package UptimeWire
 */

namespace UptimeWire;

defined( 'ABSPATH' ) || exit;

/**
 * Reads GET /wp/v1/status and keeps the last good answer in the option
 * "uptimewire_status" (not autoloaded). Screens render from this copy and never
 * wait for UptimeWire; the copy is renewed when it is due, by the screen's
 * script or by the Refresh button. No WordPress cron: monitoring runs in
 * UptimeWire and does not depend on WordPress.
 *
 * The copy holds only what the status answer says about this one website. A
 * failed request keeps the last good data and records why it failed, so the
 * screens can say the data is old instead of showing it as current.
 */
final class Status {

	/** Option with the kept status. */
	const OPTION = 'uptimewire_status';

	/** Longest time the kept status counts as current, in seconds (the BE-3 recommendation). */
	const TTL = 300;

	/** Shortest time between two automatic reads, in seconds. */
	const MIN_TTL = 60;

	/** After this many seconds without a successful read, data is shown as "Last Known Status". */
	const STALE_AFTER = 1800;

	/** A manual refresh within this many seconds of the last read reuses that read. */
	const MANUAL_GAP = 10;

	/** Seconds to wait after a failure when UptimeWire gave no Retry-After. */
	const DEFAULT_RETRY = 60;

	/** Longest wait accepted from Retry-After, in seconds. */
	const MAX_RETRY = 3600;

	/** The kept status was renewed. */
	const UPDATED = 'updated';

	/** The kept status is current; nothing was asked. */
	const FRESH = 'fresh';

	/** Another request is reading right now (another tab). */
	const BUSY = 'busy';

	/** UptimeWire asked to wait, or the last failure is too recent. */
	const WAIT = 'wait';

	/** No answer at all: network, TLS or timeout. */
	const UNREACHABLE = 'unreachable';

	/** UptimeWire answered 503. */
	const UNAVAILABLE = 'unavailable';

	/** UptimeWire answered 429. */
	const RATE_LIMITED = 'rate_limited';

	/** An answer the plugin cannot use (not JSON, not a status, an unexpected code). */
	const INVALID = 'invalid';

	/** UptimeWire ended the connection (401). */
	const ENDED = 'ended';

	/** The status is about another website; nothing of it is kept. */
	const MISMATCH = 'mismatch';

	/** There is no usable connection to read with. */
	const NOT_CONNECTED = 'not_connected';

	/**
	 * The kept status, or null when there is none.
	 *
	 * Keys: data (Status_Data::normalize() record or null), fetched_at (time of
	 * the last good read, 0 when none), attempted_at, error ('' or one of the
	 * failure results), retry_at (no request before this time).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function get() {
		$stored = get_option( self::OPTION, null );

		if ( ! is_array( $stored ) ) {
			return null;
		}

		return array(
			'data'         => isset( $stored['data'] ) && is_array( $stored['data'] ) ? $stored['data'] : null,
			'fetched_at'   => isset( $stored['fetched_at'] ) ? (int) $stored['fetched_at'] : 0,
			'attempted_at' => isset( $stored['attempted_at'] ) ? (int) $stored['attempted_at'] : 0,
			'error'        => isset( $stored['error'] ) && is_string( $stored['error'] ) ? $stored['error'] : '',
			'retry_at'     => isset( $stored['retry_at'] ) ? (int) $stored['retry_at'] : 0,
		);
	}

	/**
	 * The kept data, but only while it belongs to this website.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function data() {
		$status = self::get();

		if ( null === $status || null === $status['data'] || ! Site_Match::matches_any( (string) $status['data']['site']['url'], Plugin::own_urls() ) ) {
			return null;
		}

		return $status['data'];
	}

	/**
	 * How long a read stays current: the check interval of the website, between
	 * MIN_TTL and TTL. A website checked every minute is read every minute while
	 * someone looks at it; reading more often could not show anything newer.
	 *
	 * @param array<string, mixed>|null $data Kept data.
	 * @return int Seconds.
	 */
	public static function ttl( $data ) {
		$minutes = is_array( $data ) && isset( $data['site']['interval_minutes'] ) ? (int) $data['site']['interval_minutes'] : 0;

		return $minutes > 0 ? max( self::MIN_TTL, min( self::TTL, $minutes * 60 ) ) : self::TTL;
	}

	/**
	 * Whether it is time to read again.
	 *
	 * @param array<string, mixed>|null $status Kept status from get().
	 * @return bool
	 */
	public static function is_due( $status ) {
		if ( null === $status ) {
			return true;
		}

		if ( time() < $status['retry_at'] ) {
			return false;
		}

		return time() - max( $status['fetched_at'], $status['attempted_at'] ) >= self::ttl( $status['data'] );
	}

	/**
	 * Whether the kept data must be shown as "Last Known Status", not as current.
	 *
	 * It is when the last read failed and the data is older than one read
	 * period, or when nothing was read for STALE_AFTER seconds.
	 *
	 * @param array<string, mixed>|null $status Kept status from get().
	 * @return bool
	 */
	public static function is_stale( $status ) {
		if ( null === $status || null === $status['data'] ) {
			return false;
		}

		$age = time() - $status['fetched_at'];

		return $age >= self::STALE_AFTER || ( '' !== $status['error'] && $age >= self::ttl( $status['data'] ) );
	}

	/**
	 * Reads the status from UptimeWire when it is due, or now when forced.
	 *
	 * Forced reads (the Refresh button) still respect Retry-After and are
	 * merged when clicked twice: UptimeWire's data changes no faster than its
	 * checks. Two tabs never read at the same time.
	 *
	 * @param bool $force Read even when the kept status is current.
	 * @return string One of the result constants.
	 */
	public static function refresh( $force = false ) {
		$record = Connection::get();
		$token  = Connection::token();

		if ( null === $record || ! isset( $record['id'] ) || '' === $token || in_array( Connection::health_state( $record ), array( Connection::HEALTH_ENDED, Connection::HEALTH_COPY ), true ) ) {
			// A copy never reads with the original site's credential.
			return self::NOT_CONNECTED;
		}

		$status = self::get();

		if ( null !== $status && time() < $status['retry_at'] ) {
			return self::WAIT;
		}

		if ( null !== $status && ( $force ? time() - $status['attempted_at'] < self::MANUAL_GAP : ! self::is_due( $status ) ) ) {
			return self::FRESH;
		}

		if ( ! Lock::acquire( 'status', 15 ) ) {
			return self::BUSY;
		}

		$answer = Api::get( '/wp/v1/status', $token );
		$result = self::store( $answer );

		Lock::release( 'status' );

		return $result;
	}

	/**
	 * Deletes the kept status (the connection ended or changed).
	 *
	 * @return void
	 */
	public static function forget() {
		delete_option( self::OPTION );
	}

	/**
	 * Keeps what an answer says and returns the result.
	 *
	 * @param array{status: int, body: array<string, mixed>|null, retry_after: int|null} $answer Answer from Api::get().
	 * @return string
	 */
	private static function store( array $answer ) {
		// The copy read at the start of this request may be older than a disconnect in another one.
		wp_cache_delete( Connection::OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		if ( null === Connection::get() ) {
			// Disconnected while UptimeWire was answering: nothing is kept.
			return self::NOT_CONNECTED;
		}

		$status = self::get();
		$now    = time();
		$keep   = array(
			'data'         => null === $status ? null : $status['data'],
			'fetched_at'   => null === $status ? 0 : $status['fetched_at'],
			'attempted_at' => $now,
			'error'        => '',
			'retry_at'     => 0,
		);

		switch ( $answer['status'] ) {
			case 200:
				$data = Status_Data::normalize( $answer['body'] );

				if ( null === $data ) {
					$result = self::INVALID;
					break;
				}

				if ( ! Connection::confirm( $data['site']['url'], $data['account_label'] ) ) {
					// Another website: nothing of it is kept, the connection screen explains.
					self::forget();

					return self::MISMATCH;
				}

				$keep['data']       = $data;
				$keep['fetched_at'] = $now;
				update_option( self::OPTION, $keep, false );

				return self::UPDATED;

			case 401:
				Connection::end();
				self::forget();

				return self::ENDED;

			case 429:
				$result = self::RATE_LIMITED;
				break;

			case 503:
				$result = self::UNAVAILABLE;
				break;

			case 0:
				$result = self::UNREACHABLE;
				break;

			default:
				$result = self::INVALID;
		}

		$wait             = null === $answer['retry_after'] ? self::DEFAULT_RETRY : max( 1, min( self::MAX_RETRY, (int) $answer['retry_after'] ) );
		$keep['error']    = $result;
		$keep['retry_at'] = $now + $wait;
		update_option( self::OPTION, $keep, false );

		return $result;
	}
}
