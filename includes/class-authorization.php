<?php
/**
 * Connecting by code (RFC 8628): ask for a code, wait for the person, collect the credential.
 *
 * @package UptimeWire
 */

namespace UptimeWire;

defined( 'ABSPATH' ) || exit;

/**
 * The pending authorization of this site and its outcome.
 *
 * One pending authorization per site. Its device code and proof stay on this
 * server in a transient; the browser gets only the short code and the page of
 * UptimeWire. The plugin asks UptimeWire only while an administrator keeps the
 * waiting screen open, never more often than the interval UptimeWire set, and
 * never from two requests at once.
 */
final class Authorization {

	/** Transient with the pending authorization. */
	const TRANSIENT = 'uptimewire_pending';

	/** Option with the result of the last attempt that ended without a connection. */
	const OUTCOME_OPTION = 'uptimewire_outcome';

	/** Transient naming an authorization the administrator cancelled. */
	const CANCELLED_TRANSIENT = 'uptimewire_cancelled';

	/** Transient naming an authorization whose proof UptimeWire fetched. */
	const PROOF_SEEN_TRANSIENT = 'uptimewire_proof_seen';

	/**
	 * Seconds the plugin keeps asking after the code's own life when UptimeWire
	 * cannot be reached: a code allowed in its last seconds still has two minutes.
	 */
	const POLL_GRACE = 150;

	/** Seconds the proof is still given after the code's life (the fetch itself takes up to 8). */
	const PROOF_GRACE = 15;

	/** A second click within this many seconds reuses the code just asked for. */
	const REUSE_WINDOW = 30;

	/** Longest wait between two questions, in seconds. */
	const MAX_INTERVAL = 60;

	/** Status of a pending authorization before UptimeWire answered a question about it. */
	const PENDING = 'pending';

	/** Status after UptimeWire said the person has not decided yet. */
	const WAITING = 'waiting';

	/** Outcome: the code expired. */
	const OUTCOME_EXPIRED = 'expired';

	/** Outcome: the person denied, or lost the right to connect. */
	const OUTCOME_DENIED = 'denied';

	/** Outcome: the connection could not be made. */
	const OUTCOME_ERROR = 'error';

	/**
	 * The pending authorization, or null.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function pending() {
		$pending = get_transient( self::TRANSIENT );

		if ( ! is_array( $pending ) || ! isset( $pending['device_code'], $pending['authorization_id'], $pending['proof'], $pending['expires_at'] ) ) {
			return null;
		}

		if ( time() > (int) $pending['expires_at'] + self::POLL_GRACE ) {
			// Nobody waited until the end (a closed tab): the code is dead, forget it now.
			$seen = get_transient( self::PROOF_SEEN_TRANSIENT ) === $pending['authorization_id'];
			delete_transient( self::TRANSIENT );
			delete_transient( self::PROOF_SEEN_TRANSIENT );
			update_option(
				self::OUTCOME_OPTION,
				array(
					'state'      => self::OUTCOME_EXPIRED,
					'reason'     => 'expired',
					'at'         => time(),
					'proof_seen' => $seen,
				),
				false
			);

			return null;
		}

		return $pending;
	}

	/**
	 * The pending authorization as another request may have just written it.
	 *
	 * WordPress keeps a copy of what it read for the rest of the request. That
	 * copy is dropped first; the stored value itself is never touched (with a
	 * persistent object cache the transient lives only in that cache).
	 *
	 * @return array<string, mixed>|null
	 */
	private static function fresh_pending() {
		if ( ! wp_using_ext_object_cache() ) {
			wp_cache_delete( '_transient_' . self::TRANSIENT, 'options' );
			wp_cache_delete( '_transient_timeout_' . self::TRANSIENT, 'options' );
			wp_cache_delete( '_transient_' . self::CANCELLED_TRANSIENT, 'options' );
			wp_cache_delete( 'notoptions', 'options' );
		} elseif ( function_exists( 'wp_cache_supports' ) && wp_cache_supports( 'flush_runtime' ) ) {
			wp_cache_flush_runtime();
		}

		return self::pending();
	}

	/**
	 * The outcome of the last attempt, or null.
	 *
	 * @return array<string, mixed>|null Keys: state, reason, at, and for a mismatch site_url and local_url.
	 */
	public static function outcome() {
		$outcome = get_option( self::OUTCOME_OPTION, null );

		return is_array( $outcome ) && isset( $outcome['state'], $outcome['reason'] ) ? $outcome : null;
	}

	/**
	 * Forgets the outcome of the last attempt.
	 *
	 * @return void
	 */
	public static function clear_outcome() {
		delete_option( self::OUTCOME_OPTION );
	}

	/**
	 * Asks UptimeWire for a code.
	 *
	 * @param int  $user_id    WordPress user who starts.
	 * @param bool $understood Whether the administrator confirmed a staging or development site.
	 * @return array{ok: bool, error: string, retry_after: int} error is '' on success.
	 */
	public static function start( $user_id, $understood ) {
		$refusal = self::refusal( $understood );

		if ( '' !== $refusal ) {
			return self::failed( $refusal );
		}

		if ( ! Lock::acquire( 'start', 15 ) ) {
			// A second click while the first is still asking: wait for the first one's code and use it.
			$waited = 0;
			while ( $waited < 20 && null === self::fresh_pending() ) {
				usleep( 500000 );
				++$waited;
			}

			return null !== self::pending() ? self::succeeded() : self::failed( 'busy' );
		}

		$pending = self::pending();

		if ( null !== $pending && isset( $pending['started_at'] ) && time() - (int) $pending['started_at'] < self::REUSE_WINDOW ) {
			Lock::release( 'start' );

			return self::succeeded();
		}

		$result = self::request_code( $user_id );

		Lock::release( 'start' );

		return $result;
	}

	/**
	 * Why connecting cannot start now, or ''.
	 *
	 * @param bool $understood Whether the administrator confirmed a staging or development site.
	 * @return string
	 */
	private static function refusal( $understood ) {
		if ( null !== Connection::configured_token() ) {
			return 'token_in_config';
		}

		if ( Connection::is_live() ) {
			return 'already_connected';
		}

		$warnings = Environment::warnings( wp_get_environment_type(), Plugin::site_url() );

		if ( in_array( Environment::WARNING_LOCAL, $warnings, true ) ) {
			return 'local_site';
		}

		if ( in_array( Environment::WARNING_STAGING, $warnings, true ) && ! $understood ) {
			return 'needs_confirmation';
		}

		return '';
	}

	/**
	 * Requests a device code and stores the pending authorization.
	 *
	 * @param int $user_id WordPress user who starts.
	 * @return array{ok: bool, error: string, retry_after: int}
	 */
	private static function request_code( $user_id ) {
		$proof    = self::base64url( random_bytes( 32 ) );
		$site_url = untrailingslashit( Plugin::site_url() );

		$answer = Api::post(
			'/wp/v1/device',
			array(
				'client_id'  => Api::CLIENT_ID,
				'site_url'   => $site_url,
				'proof_hash' => self::base64url( hash( 'sha256', $proof, true ) ),
				'wp'         => get_bloginfo( 'version' ),
				'plugin'     => UPTIMEWIRE_VERSION,
				'env'        => wp_get_environment_type(),
				'multisite'  => is_multisite() ? '1' : '0',
			)
		);

		if ( 200 !== $answer['status'] ) {
			$error = isset( $answer['body']['error'] ) && is_string( $answer['body']['error'] ) ? $answer['body']['error'] : '';

			if ( 429 === $answer['status'] ) {
				return self::failed( 'rate_limited', (int) $answer['retry_after'] );
			}

			if ( 400 === $answer['status'] && 'invalid_request' === $error ) {
				return self::failed( 'site_rejected' );
			}

			return self::failed( 0 === $answer['status'] || $answer['status'] >= 500 ? 'unreachable' : 'unexpected' );
		}

		$codes = self::valid_codes( $answer['body'] );

		if ( null === $codes ) {
			return self::failed( 'unexpected' );
		}

		$now     = time();
		$pending = $codes + array(
			'proof'        => $proof,
			'expires_at'   => $now + $codes['expires_in'],
			'next_poll_at' => $now + $codes['interval'],
			'started_at'   => $now,
			'started_by'   => (int) $user_id,
			'site_url'     => $site_url,
			'status'       => self::PENDING,
			'notice'       => '',
		);
		unset( $pending['expires_in'] );

		// A new request forgets the old one: its code dies at UptimeWire by itself.
		delete_transient( self::PROOF_SEEN_TRANSIENT );
		self::clear_outcome();
		set_transient( self::TRANSIENT, $pending, $codes['expires_in'] + self::POLL_GRACE + 60 );

		return self::succeeded();
	}

	/**
	 * The codes of a /wp/v1/device answer, checked against the contract, or null.
	 *
	 * @param array<string, mixed>|null $body Decoded answer.
	 * @return array<string, mixed>|null
	 */
	private static function valid_codes( $body ) {
		if ( ! is_array( $body ) ) {
			return null;
		}

		foreach ( array( 'device_code', 'user_code', 'verification_uri', 'authorization_id' ) as $key ) {
			if ( ! isset( $body[ $key ] ) || ! is_string( $body[ $key ] ) ) {
				return null;
			}
		}

		if ( 1 !== preg_match( '/^uwd_[0-9a-f]{64}$/D', $body['device_code'] )
			|| 1 !== preg_match( '/^[BCDFGHJKLMNPQRSTVWXZ]{4}-[BCDFGHJKLMNPQRSTVWXZ]{4}$/D', $body['user_code'] )
			|| 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $body['authorization_id'] )
			|| ! self::is_connect_page( $body['verification_uri'] ) ) {
			return null;
		}

		$complete = isset( $body['verification_uri_complete'] ) && is_string( $body['verification_uri_complete'] ) && self::is_connect_page( $body['verification_uri_complete'] )
			? $body['verification_uri_complete']
			: '';
		$expires  = isset( $body['expires_in'] ) && is_int( $body['expires_in'] ) && $body['expires_in'] >= 60 && $body['expires_in'] <= 1800 ? $body['expires_in'] : 600;
		$interval = isset( $body['interval'] ) && is_int( $body['interval'] ) && $body['interval'] >= 1 && $body['interval'] <= self::MAX_INTERVAL ? $body['interval'] : 5;

		return array(
			'device_code'               => $body['device_code'],
			'user_code'                 => $body['user_code'],
			'authorization_id'          => $body['authorization_id'],
			'verification_uri'          => $body['verification_uri'],
			'verification_uri_complete' => $complete,
			'expires_in'                => $expires,
			'interval'                  => $interval,
		);
	}

	/**
	 * Whether an address is a page of UptimeWire where a person approves a code.
	 *
	 * The browser is sent there, so nothing but https://app.uptimewire.com is accepted.
	 *
	 * @param string $url Address from the API.
	 * @return bool
	 */
	private static function is_connect_page( $url ) {
		$parts = wp_parse_url( $url );

		return is_array( $parts )
			&& isset( $parts['scheme'], $parts['host'] )
			&& 'https' === $parts['scheme']
			&& Api::CONNECT_HOST === $parts['host']
			&& isset( $parts['path'] ) && '/connect' === $parts['path']
			&& ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && ! isset( $parts['port'] )
			&& 1 !== preg_match( '/[\s"\'<>\\\\]/', $url );
	}

	/**
	 * Asks UptimeWire once whether the person decided, if the interval allows it.
	 *
	 * @return array<string, mixed> Public view: state, done, retry_after, seconds_left, notice.
	 */
	public static function poll() {
		$pending = self::pending();

		if ( null === $pending ) {
			return self::view();
		}

		if ( time() < (int) $pending['next_poll_at'] ) {
			return self::view();
		}

		if ( ! Lock::acquire( 'poll', 20 ) ) {
			// Another tab asks right now; this one waits for the next turn.
			return self::view();
		}

		$pending = self::fresh_pending();

		if ( null === $pending || time() < (int) $pending['next_poll_at'] ) {
			Lock::release( 'poll' );

			return self::view();
		}

		if ( ! Site_Match::matches_any( (string) $pending['site_url'], Plugin::own_urls() ) ) {
			// The code was asked for another address: a copy of the site made while connecting, or a changed address.
			self::finish( $pending, self::OUTCOME_ERROR, 'site_changed' );
			Lock::release( 'poll' );

			return self::view();
		}

		$answer = Api::post(
			'/wp/v1/token',
			array(
				'grant_type'  => Api::GRANT_TYPE,
				'device_code' => $pending['device_code'],
				'client_id'   => Api::CLIENT_ID,
			)
		);

		self::handle( $pending, $answer );
		Lock::release( 'poll' );

		return self::view();
	}

	/**
	 * Applies one answer of /wp/v1/token.
	 *
	 * @param array<string, mixed>                                                       $pending Pending authorization.
	 * @param array{status: int, body: array<string, mixed>|null, retry_after: int|null} $answer  Answer.
	 * @return void
	 */
	private static function handle( array $pending, array $answer ) {
		$error    = isset( $answer['body']['error'] ) && is_string( $answer['body']['error'] ) ? $answer['body']['error'] : '';
		$interval = (int) $pending['interval'];

		if ( 200 === $answer['status'] ) {
			self::collect( $pending, $answer['body'] );

			return;
		}

		if ( 400 === $answer['status'] && 'authorization_pending' === $error ) {
			self::keep( $pending, self::WAITING, $interval, '' );
		} elseif ( 400 === $answer['status'] && 'slow_down' === $error ) {
			$asked    = isset( $answer['body']['interval'] ) && is_int( $answer['body']['interval'] ) ? $answer['body']['interval'] : $interval + 5;
			$interval = min( self::MAX_INTERVAL, max( $interval + 1, $asked ) );
			self::keep( $pending, self::WAITING, $interval, '' );
		} elseif ( 429 === $answer['status'] ) {
			$wait = max( $interval, min( 300, (int) $answer['retry_after'] ) );
			self::keep( $pending, (string) $pending['status'], $interval, 'rate_limited', $wait );
		} elseif ( 400 === $answer['status'] && 'access_denied' === $error ) {
			self::finish( $pending, self::OUTCOME_DENIED, 'denied' );
		} elseif ( 400 === $answer['status'] && 'expired_token' === $error ) {
			self::finish( $pending, self::OUTCOME_EXPIRED, 'expired' );
		} elseif ( 400 === $answer['status'] && 'invalid_grant' === $error ) {
			// Unknown code, or one that already gave its credential (a lost answer): start again.
			self::finish( $pending, self::OUTCOME_ERROR, 'invalid_grant' );
		} elseif ( 0 === $answer['status'] || $answer['status'] >= 500 ) {
			// No answer: nothing is lost, the same code is asked again a little later.
			self::keep( $pending, (string) $pending['status'], $interval, 'unreachable', min( self::MAX_INTERVAL, $interval * 2 ) );
		} else {
			self::finish( $pending, self::OUTCOME_ERROR, 'unexpected' );
		}
	}

	/**
	 * Keeps waiting and sets when the next question may be asked.
	 *
	 * @param array<string, mixed> $pending  Pending authorization.
	 * @param string               $status   PENDING or WAITING.
	 * @param int                  $interval New interval.
	 * @param string               $notice   Temporary problem to show, or ''.
	 * @param int                  $wait     Seconds until the next question; the interval when 0.
	 * @return void
	 */
	private static function keep( array $pending, $status, $interval, $notice, $wait = 0 ) {
		if ( ! self::is_current( $pending ) ) {
			return;
		}

		$pending['status']       = $status;
		$pending['interval']     = $interval;
		$pending['notice']       = $notice;
		$pending['next_poll_at'] = time() + ( $wait > 0 ? $wait : $interval );

		set_transient( self::TRANSIENT, $pending, max( 60, (int) $pending['expires_at'] + self::POLL_GRACE + 60 - time() ) );
	}

	/**
	 * Takes the credential UptimeWire issued, or refuses it.
	 *
	 * @param array<string, mixed>      $pending Pending authorization.
	 * @param array<string, mixed>|null $body    Answer of /wp/v1/token.
	 * @return void
	 */
	private static function collect( array $pending, $body ) {
		$token = is_array( $body ) && isset( $body['access_token'] ) && is_string( $body['access_token'] ) ? $body['access_token'] : '';
		$link  = is_array( $body ) && isset( $body['connection'] ) && is_array( $body['connection'] ) ? $body['connection'] : array();
		$site  = isset( $link['site']['url'] ) && is_string( $link['site']['url'] ) ? $link['site']['url'] : '';

		if ( 1 !== preg_match( Connection::TOKEN_PATTERN, $token ) || ! isset( $link['id'] ) || ! is_string( $link['id'] ) || '' === $site
			|| ! isset( $body['token_type'] ) || ! is_string( $body['token_type'] ) || 'bearer' !== strtolower( $body['token_type'] ) ) {
			self::end_in_uptimewire( $token );
			self::finish( $pending, self::OUTCOME_ERROR, 'unexpected' );

			return;
		}

		if ( ! self::is_current( $pending ) ) {
			// Cancelled or replaced while UptimeWire was answering: the connection is not wanted.
			self::end_in_uptimewire( $token );

			return;
		}

		if ( ! Site_Match::matches_any( $site, Plugin::own_urls() ) ) {
			// The person chose another website in UptimeWire, or approved in another account.
			// Showing that website's results here would mislead, so the connection is ended and nothing is saved.
			self::end_in_uptimewire( $token );
			self::finish(
				$pending,
				self::OUTCOME_ERROR,
				'site_mismatch',
				array(
					'site_url'  => $site,
					'local_url' => (string) $pending['site_url'],
				)
			);

			return;
		}

		Connection::save(
			$token,
			array(
				'id'            => $link['id'],
				'connected_at'  => isset( $link['connected_at'] ) && is_string( $link['connected_at'] ) ? $link['connected_at'] : '',
				'site_url'      => $site,
				'local_url'     => (string) $pending['site_url'],
				'connected_by'  => (int) $pending['started_by'],
				'account_label' => (string) Status_Data::account_label( isset( $link['account_label'] ) ? $link['account_label'] : null ),
			)
		);
		self::forget();
	}

	/**
	 * Asks UptimeWire to end a connection this site does not keep (RFC 7009).
	 *
	 * @param string $token Credential, or '' when none was issued.
	 * @return void
	 */
	private static function end_in_uptimewire( $token ) {
		if ( 1 === preg_match( Connection::TOKEN_PATTERN, $token ) ) {
			Api::post( '/wp/v1/revoke', array( 'token' => $token ), '', 5 );
		}
	}

	/**
	 * Cancels the pending authorization.
	 *
	 * @return void
	 */
	public static function cancel() {
		$pending = self::pending();

		if ( null !== $pending ) {
			set_transient( self::CANCELLED_TRANSIENT, $pending['authorization_id'], 15 * MINUTE_IN_SECONDS );
			self::forget();
		}
	}

	/**
	 * Whether the authorization is still the pending one and was not cancelled.
	 *
	 * @param array<string, mixed> $pending Pending authorization as read before a request.
	 * @return bool
	 */
	private static function is_current( array $pending ) {
		$now = self::fresh_pending();

		return null !== $now
			&& hash_equals( (string) $now['authorization_id'], (string) $pending['authorization_id'] )
			&& get_transient( self::CANCELLED_TRANSIENT ) !== $pending['authorization_id'];
	}

	/**
	 * Ends the attempt without a connection and remembers why.
	 *
	 * @param array<string, mixed> $pending Pending authorization.
	 * @param string               $state   OUTCOME_* constant.
	 * @param string               $reason  Reason code shown to the administrator.
	 * @param array<string, mixed> $extra   Extra details.
	 * @return void
	 */
	private static function finish( array $pending, $state, $reason, array $extra = array() ) {
		if ( ! self::is_current( $pending ) ) {
			return;
		}

		$outcome = array(
			'state'      => $state,
			'reason'     => $reason,
			'at'         => time(),
			'proof_seen' => get_transient( self::PROOF_SEEN_TRANSIENT ) === $pending['authorization_id'],
		) + $extra;

		self::forget();
		update_option( self::OUTCOME_OPTION, $outcome, false );
	}

	/**
	 * Deletes the pending authorization and its device code.
	 *
	 * @return void
	 */
	private static function forget() {
		delete_transient( self::TRANSIENT );
		delete_transient( self::PROOF_SEEN_TRANSIENT );
	}

	/**
	 * Records that UptimeWire fetched the proof of an authorization.
	 *
	 * @param string $authorization_id Authorization.
	 * @return void
	 */
	public static function mark_proof_seen( $authorization_id ) {
		if ( get_transient( self::PROOF_SEEN_TRANSIENT ) !== $authorization_id ) {
			set_transient( self::PROOF_SEEN_TRANSIENT, $authorization_id, 15 * MINUTE_IN_SECONDS );
		}
	}

	/**
	 * What the browser may know about the current state. Never a device code or proof.
	 *
	 * @return array<string, mixed>
	 */
	public static function view() {
		$pending = self::pending();
		$state   = State::current();

		if ( null === $pending ) {
			return array(
				'state' => $state,
				'done'  => true,
			);
		}

		return array(
			'state'        => $state,
			'done'         => false,
			'retry_after'  => max( 1, (int) $pending['next_poll_at'] - time() ),
			'seconds_left' => max( 0, (int) $pending['expires_at'] - time() ),
			'notice'       => (string) $pending['notice'],
		);
	}

	/**
	 * Result of start().
	 *
	 * @return array{ok: bool, error: string, retry_after: int}
	 */
	private static function succeeded() {
		return array(
			'ok'          => true,
			'error'       => '',
			'retry_after' => 0,
		);
	}

	/**
	 * Result of start().
	 *
	 * @param string $error       Reason code.
	 * @param int    $retry_after Seconds to wait, for rate_limited.
	 * @return array{ok: bool, error: string, retry_after: int}
	 */
	private static function failed( $error, $retry_after = 0 ) {
		return array(
			'ok'          => false,
			'error'       => $error,
			'retry_after' => max( 0, $retry_after ),
		);
	}

	/**
	 * BASE64URL without padding (RFC 4648, section 5).
	 *
	 * @param string $bytes Raw bytes.
	 * @return string
	 */
	private static function base64url( $bytes ) {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- RFC 8628 proof hash, not obfuscation.
	}
}
