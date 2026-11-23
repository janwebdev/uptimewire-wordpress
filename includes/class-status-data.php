<?php
/**
 * Validation of the UptimeWire status answer.
 *
 * Pure logic: no WordPress calls, so it is unit tested without WordPress.
 *
 * @package UptimeWire
 */

namespace UptimeWire;

defined( 'ABSPATH' ) || exit;

/**
 * Turns the body of GET /wp/v1/status into a record of known shape.
 *
 * Every field gets a safe value: unknown states become "unknown", wrong types
 * and unreadable times become null, links to anything but UptimeWire are
 * dropped. Nothing unknown ever becomes "healthy". Texts stay untrusted: they
 * are cut to the contract length here and must still be escaped on output.
 */
final class Status_Data {

	/** Value of any state the plugin does not know. */
	const UNKNOWN = 'unknown';

	/** Website states of the contract. */
	const SITE_STATES = array( 'waiting', 'up', 'checking', 'down', 'planned', 'paused' );

	/** States of the SSL and domain checks. */
	const SLOW_STATES = array( 'ok', 'problem', 'unknown', 'not_applicable', 'not_on_plan' );

	/** States of the DNS check. */
	const DNS_STATES = array( 'ok', 'problem', 'unknown', 'not_on_plan' );

	/** Warnings of the SSL and domain checks. */
	const WARNINGS = array( 'expired', 'expires_soon', 'check_failed' );

	/** Uptime windows, in the order shown, with their length in seconds. */
	const WINDOWS = array(
		'24h' => 86400,
		'7d'  => 604800,
		'30d' => 2592000,
	);

	/** Event types and the kinds each may have. */
	const EVENT_KINDS = array(
		'downtime' => array( 'ongoing', 'resolved' ),
		'ssl'      => array( 'problem', 'renewed' ),
		'domain'   => array( 'problem', 'renewed' ),
		'dns'      => array( 'changed' ),
	);

	/** Alert channel kinds of the contract. */
	const CHANNELS = array( 'email', 'telegram', 'sms', 'webhook', 'slack', 'discord', 'teams' );

	/** HTTP statuses with which a website may turn checks away. */
	const BLOCKED_STATUSES = array( 401, 403, 429 );

	/** Hosts the plugin links to. */
	const LINK_HOSTS = array( 'app.uptimewire.com', 'uptimewire.com' );

	/** Most events kept. */
	const MAX_EVENTS = 10;

	/** Longest untrusted text kept, in characters (the contract limit). */
	const MAX_TEXT = 300;

	/**
	 * The validated record, or null when the answer is not a status at all.
	 *
	 * @param mixed $body Decoded JSON body.
	 * @return array<string, mixed>|null
	 */
	public static function normalize( $body ) {
		$data = is_array( $body ) && isset( $body['data'] ) && is_array( $body['data'] ) ? $body['data'] : null;
		$site = null !== $data && isset( $data['site'] ) && is_array( $data['site'] ) ? $data['site'] : null;

		// Without the website address nothing can be shown: it decides whose data this is.
		if ( null === $site || ! isset( $site['url'] ) || ! is_string( $site['url'] ) || '' === trim( $site['url'] ) ) {
			return null;
		}

		$connection = self::arr( $data, 'connection' );
		$checks     = self::arr( $data, 'checks' );
		$meta       = is_array( $body ) ? self::arr( $body, 'meta' ) : array();

		return array(
			'account_label' => self::account_label( isset( $connection['account_label'] ) ? $connection['account_label'] : null ),
			'site'          => self::site( $site ),
			'uptime'        => self::uptime( isset( $data['uptime'] ) ? $data['uptime'] : null ),
			'ssl'           => self::slow_check( self::arr( $checks, 'ssl' ), false ),
			'domain'        => self::slow_check( self::arr( $checks, 'domain' ), true ),
			'dns'           => self::dns( self::arr( $checks, 'dns' ) ),
			'events'        => self::events( isset( $data['events'] ) ? $data['events'] : null ),
			'alerts'        => self::alerts( self::arr( $data, 'alerts' ) ),
			'links'         => self::links( self::arr( $data, 'links' ) ),
			'server_time'   => self::time( isset( $meta['server_time'] ) ? $meta['server_time'] : null ),
		);
	}

	/**
	 * The account number ACC-…, or null when the value has another shape.
	 *
	 * @param mixed $value Value.
	 * @return string|null
	 */
	public static function account_label( $value ) {
		return is_string( $value ) && 1 === preg_match( '/^ACC-[0-9]{1,20}$/D', $value ) ? $value : null;
	}

	/**
	 * What the main status of the website is, in one word.
	 *
	 * "unverified" is a paused website whose owner has not confirmed the email
	 * yet: monitoring has not started. Anything not in the contract is "unknown".
	 *
	 * @param array<string, mixed> $site Normalized site.
	 * @return string unverified, waiting, up, checking, down, planned, paused or unknown.
	 */
	public static function headline( array $site ) {
		if ( ! empty( $site['awaiting_email_confirmation'] ) ) {
			return 'unverified';
		}

		return in_array( $site['state'], self::SITE_STATES, true ) ? $site['state'] : self::UNKNOWN;
	}

	/**
	 * Whether a link may be shown: https on an UptimeWire host, nothing else.
	 *
	 * @param mixed $url Address.
	 * @return bool
	 */
	public static function is_safe_link( $url ) {
		if ( ! is_string( $url ) || strlen( $url ) > 2048 || 1 === preg_match( '/[\x00-\x20\x7f"\'<>\\\\]/', $url ) ) {
			return false;
		}

		$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Pure class, unit tested without WordPress.

		return is_array( $parts )
			&& isset( $parts['scheme'], $parts['host'] )
			&& 'https' === strtolower( $parts['scheme'] )
			&& ! isset( $parts['user'] )
			&& ! isset( $parts['pass'] )
			&& ! isset( $parts['port'] )
			&& in_array( strtolower( $parts['host'] ), self::LINK_HOSTS, true );
	}

	/**
	 * The website part.
	 *
	 * @param array<string, mixed> $site Raw site.
	 * @return array<string, mixed>
	 */
	private static function site( array $site ) {
		$state   = isset( $site['state'] ) && is_string( $site['state'] ) && in_array( $site['state'], self::SITE_STATES, true ) ? $site['state'] : self::UNKNOWN;
		$blocked = isset( $site['blocked']['status'] ) && is_int( $site['blocked']['status'] ) && in_array( $site['blocked']['status'], self::BLOCKED_STATUSES, true ) ? $site['blocked']['status'] : null;

		return array(
			'url'                         => trim( $site['url'] ),
			'state'                       => $state,
			'paused'                      => isset( $site['paused'] ) && true === $site['paused'],
			// Only a real "true" means the checks have not started; anything else is not proof of it.
			'awaiting_email_confirmation' => isset( $site['awaiting_email_confirmation'] ) && true === $site['awaiting_email_confirmation'],
			'interval_minutes'            => self::int( $site, 'interval_minutes', 1, 1440 ),
			'problem'                     => self::text( isset( $site['problem'] ) ? $site['problem'] : null ),
			'down_since'                  => self::time( isset( $site['down_since'] ) ? $site['down_since'] : null ),
			'last_checked_at'             => self::time( isset( $site['last_checked_at'] ) ? $site['last_checked_at'] : null ),
			'last_success_at'             => self::time( isset( $site['last_success_at'] ) ? $site['last_success_at'] : null ),
			'last_location'               => self::text( isset( $site['last_location'] ) ? $site['last_location'] : null, 64 ),
			'average_response_ms_24h'     => self::int( $site, 'average_response_ms_24h', 0, 600000 ),
			'blocked'                     => $blocked,
		);
	}

	/**
	 * The three uptime windows, always all three, in the order of WINDOWS.
	 *
	 * A window that is missing or malformed is "not measured": its percent is
	 * null, never 100.
	 *
	 * @param mixed $items Raw list.
	 * @return array<string, array<string, mixed>>
	 */
	private static function uptime( $items ) {
		$found = array();

		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( is_array( $item ) && isset( $item['window'] ) && is_string( $item['window'] ) && isset( self::WINDOWS[ $item['window'] ] ) && ! isset( $found[ $item['window'] ] ) ) {
					$found[ $item['window'] ] = $item;
				}
			}
		}

		$windows = array();

		foreach ( self::WINDOWS as $name => $length ) {
			$item     = isset( $found[ $name ] ) ? $found[ $name ] : array();
			$measured = self::int( $item, 'measured_seconds', 0, $length );
			$down     = self::int( $item, 'downtime_seconds', 0, $length );
			$percent  = isset( $item['percent'] ) && ( is_int( $item['percent'] ) || is_float( $item['percent'] ) ) && $item['percent'] >= 0 && $item['percent'] <= 100 ? (float) $item['percent'] : null;

			// A percentage needs measured time behind it.
			if ( null === $measured || 0 === $measured ) {
				$percent = null;
			}

			$windows[ $name ] = array(
				'from'             => self::time( isset( $item['from'] ) ? $item['from'] : null ),
				'to'               => self::time( isset( $item['to'] ) ? $item['to'] : null ),
				'measured_seconds' => null === $measured ? 0 : $measured,
				'downtime_seconds' => null === $down ? 0 : $down,
				'percent'          => $percent,
				'history_limited'  => isset( $item['history_limited'] ) && true === $item['history_limited'],
			);
		}

		return $windows;
	}

	/**
	 * The SSL or the domain check.
	 *
	 * @param array<string, mixed> $check  Raw check.
	 * @param bool                 $domain Whether this is the domain check (it has expiry_known).
	 * @return array<string, mixed>
	 */
	private static function slow_check( array $check, $domain ) {
		$state   = isset( $check['state'] ) && is_string( $check['state'] ) && in_array( $check['state'], self::SLOW_STATES, true ) ? $check['state'] : self::UNKNOWN;
		$warning = isset( $check['warning'] ) && is_string( $check['warning'] ) && in_array( $check['warning'], self::WARNINGS, true ) ? $check['warning'] : null;
		$expires = self::time( isset( $check['expires_at'] ) ? $check['expires_at'] : null );
		$days    = isset( $check['days_left'] ) && is_int( $check['days_left'] ) && abs( $check['days_left'] ) <= 36500 ? $check['days_left'] : null;
		$result  = array(
			'state'      => $state,
			'checked_at' => self::time( isset( $check['checked_at'] ) ? $check['checked_at'] : null ),
			'expires_at' => $expires,
			'days_left'  => $days,
			'warning'    => 'problem' === $state ? $warning : null,
			'error'      => self::text( isset( $check['error'] ) ? $check['error'] : null ),
		);

		if ( $domain ) {
			// A date is shown only when UptimeWire says it is known.
			$result['expiry_known'] = isset( $check['expiry_known'] ) && true === $check['expiry_known'] && null !== $expires;

			if ( ! $result['expiry_known'] ) {
				$result['expires_at'] = null;
				$result['days_left']  = null;
			}
		}

		return $result;
	}

	/**
	 * The DNS check.
	 *
	 * @param array<string, mixed> $check Raw check.
	 * @return array<string, mixed>
	 */
	private static function dns( array $check ) {
		$open = self::int( $check, 'open_changes', 0, 20 );

		return array(
			'state'          => isset( $check['state'] ) && is_string( $check['state'] ) && in_array( $check['state'], self::DNS_STATES, true ) ? $check['state'] : self::UNKNOWN,
			'checked_at'     => self::time( isset( $check['checked_at'] ) ? $check['checked_at'] : null ),
			'changed'        => isset( $check['changed'] ) && true === $check['changed'],
			'open_changes'   => null === $open ? 0 : $open,
			'last_change_at' => self::time( isset( $check['last_change_at'] ) ? $check['last_change_at'] : null ),
		);
	}

	/**
	 * The latest events, newest first, at most MAX_EVENTS.
	 *
	 * An event whose type or kind the plugin does not know is kept as type
	 * "unknown", so it is not silently lost; an event without a readable time
	 * is dropped.
	 *
	 * @param mixed $items Raw list.
	 * @return array<int, array<string, mixed>>
	 */
	private static function events( $items ) {
		$events = array();

		if ( ! is_array( $items ) ) {
			return $events;
		}

		foreach ( $items as $item ) {
			$at = is_array( $item ) ? self::time( isset( $item['at'] ) ? $item['at'] : null ) : null;

			if ( null === $at ) {
				continue;
			}

			$type = isset( $item['type'] ) && is_string( $item['type'] ) && isset( self::EVENT_KINDS[ $item['type'] ] ) ? $item['type'] : self::UNKNOWN;
			$kind = self::UNKNOWN !== $type && isset( $item['kind'] ) && is_string( $item['kind'] ) && in_array( $item['kind'], self::EVENT_KINDS[ $type ], true ) ? $item['kind'] : self::UNKNOWN;

			$events[] = array(
				'type'             => self::UNKNOWN === $kind ? self::UNKNOWN : $type,
				'kind'             => $kind,
				'at'               => $at,
				'ended_at'         => self::time( isset( $item['ended_at'] ) ? $item['ended_at'] : null ),
				// Only an outage has a duration: certificate, domain and DNS events are never downtime.
				'duration_seconds' => 'downtime' === $type ? self::int( $item, 'duration_seconds', 0, PHP_INT_MAX ) : null,
				'expires_at'       => in_array( $type, array( 'ssl', 'domain' ), true ) ? self::time( isset( $item['expires_at'] ) ? $item['expires_at'] : null ) : null,
			);
		}

		usort(
			$events,
			static function ( $a, $b ) {
				return $b['at'] - $a['at'];
			}
		);

		return array_slice( $events, 0, self::MAX_EVENTS );
	}

	/**
	 * Alert readiness: known channel kinds only, no destinations.
	 *
	 * @param array<string, mixed> $alerts Raw alerts.
	 * @return array<string, mixed>
	 */
	private static function alerts( array $alerts ) {
		$channels = array();
		$unknown  = false;

		if ( isset( $alerts['channels'] ) && is_array( $alerts['channels'] ) ) {
			foreach ( $alerts['channels'] as $channel ) {
				if ( is_string( $channel ) && in_array( $channel, self::CHANNELS, true ) ) {
					$channels[] = $channel;
				} else {
					$unknown = true;
				}
			}
		}

		return array(
			'ready'          => isset( $alerts['ready'] ) && true === $alerts['ready'],
			'channels'       => array_values( array_unique( $channels ) ),
			'other_channels' => $unknown,
			'email_pending'  => isset( $alerts['email_pending'] ) && true === $alerts['email_pending'],
		);
	}

	/**
	 * Links into UptimeWire, or null for any that is not a safe UptimeWire address.
	 *
	 * @param array<string, mixed> $links Raw links.
	 * @return array<string, string|null>
	 */
	private static function links( array $links ) {
		$result = array();

		foreach ( array( 'site', 'alerts', 'plans' ) as $name ) {
			$result[ $name ] = isset( $links[ $name ] ) && self::is_safe_link( $links[ $name ] ) ? $links[ $name ] : null;
		}

		return $result;
	}

	/**
	 * Unix time of a contract time (RFC 3339, whole seconds), or null.
	 *
	 * @param mixed $value Value.
	 * @return int|null
	 */
	public static function time( $value ) {
		if ( ! is_string( $value ) || 1 !== preg_match( '/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(Z|[+-]\d{2}:\d{2})$/D', $value, $m ) ) {
			return null;
		}

		if ( ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) || (int) $m[4] > 23 || (int) $m[5] > 59 || (int) $m[6] > 59 ) {
			return null;
		}

		$time = strtotime( $value );

		return false === $time ? null : $time;
	}

	/**
	 * Untrusted text cut to a length, or null. Control characters are removed.
	 *
	 * @param mixed $value Value.
	 * @param int   $max   Most characters.
	 * @return string|null
	 */
	private static function text( $value, $max = self::MAX_TEXT ) {
		if ( ! is_string( $value ) ) {
			return null;
		}

		// Invalid UTF-8 is not shown at all.
		if ( 1 !== preg_match( '//u', $value ) ) {
			return null;
		}

		$value = trim( (string) preg_replace( '/[\x00-\x1f\x7f]+/u', ' ', $value ) );

		if ( '' === $value ) {
			return null;
		}

		if ( preg_match_all( '/./us', $value ) > $max ) {
			preg_match( '/^.{' . (int) $max . '}/us', $value, $cut );
			$value = rtrim( $cut[0] ) . '…';
		}

		return $value;
	}

	/**
	 * Integer field within bounds, or null.
	 *
	 * @param array<string, mixed> $source Array.
	 * @param string               $key    Key.
	 * @param int                  $min    Lowest value.
	 * @param int                  $max    Highest value.
	 * @return int|null
	 */
	private static function int( array $source, $key, $min, $max ) {
		return isset( $source[ $key ] ) && is_int( $source[ $key ] ) && $source[ $key ] >= $min && $source[ $key ] <= $max ? $source[ $key ] : null;
	}

	/**
	 * Array field, or an empty array.
	 *
	 * @param array<string, mixed> $source Array.
	 * @param string               $key    Key.
	 * @return array<string, mixed>
	 */
	private static function arr( array $source, $key ) {
		return isset( $source[ $key ] ) && is_array( $source[ $key ] ) ? $source[ $key ] : array();
	}
}
