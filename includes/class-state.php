<?php
/**
 * The connection states, in one list.
 *
 * @package UptimeWire
 */

namespace UptimeWire;

defined( 'ABSPATH' ) || exit;

/**
 * State of the connection between this site and UptimeWire.
 *
 * This is the connection only. Whether monitoring has started and how the
 * website is doing is not known to the plugin yet: UptimeWire does not report
 * it to the plugin in this version.
 */
final class State {

	const NOT_CONNECTED          = 'not_connected';
	const AUTHORIZATION_PENDING  = 'authorization_pending';
	const WAITING_FOR_APPROVAL   = 'waiting_for_approval';
	const AUTHORIZATION_EXPIRED  = 'authorization_expired';
	const AUTHORIZATION_DENIED   = 'authorization_denied';
	const CONNECTION_ESTABLISHED = 'connection_established';
	const CONNECTION_ERROR       = 'connection_error';
	const RECONNECTION_REQUIRED  = 'reconnection_required';
	const SITE_COPY              = 'site_copy';

	/**
	 * The current state.
	 *
	 * A pending authorization comes first (a reconnection in progress), then the
	 * outcome of the last attempt, then the stored connection.
	 *
	 * @return string
	 */
	public static function current() {
		$pending = Authorization::pending();

		if ( null !== $pending ) {
			return Authorization::WAITING === $pending['status'] ? self::WAITING_FOR_APPROVAL : self::AUTHORIZATION_PENDING;
		}

		$outcome = Authorization::outcome();

		if ( null !== $outcome ) {
			$states = array(
				Authorization::OUTCOME_EXPIRED => self::AUTHORIZATION_EXPIRED,
				Authorization::OUTCOME_DENIED  => self::AUTHORIZATION_DENIED,
			);

			return isset( $states[ $outcome['state'] ] ) ? $states[ $outcome['state'] ] : self::CONNECTION_ERROR;
		}

		$record = Connection::get();

		if ( null === $record ) {
			return self::NOT_CONNECTED;
		}

		switch ( Connection::health_state( $record ) ) {
			case Connection::HEALTH_COPY:
				return self::SITE_COPY;
			case Connection::HEALTH_ENDED:
				return self::RECONNECTION_REQUIRED;
			case Connection::HEALTH_MISMATCH:
			case Connection::HEALTH_BAD_CONSTANT:
				return self::CONNECTION_ERROR;
		}

		// A credential from wp-config.php that UptimeWire has not confirmed yet is not a connection yet.
		return isset( $record['id'] ) ? self::CONNECTION_ESTABLISHED : self::CONNECTION_ERROR;
	}

	/**
	 * Human name of a state.
	 *
	 * @param string $state State constant.
	 * @return string
	 */
	public static function label( $state ) {
		$labels = array(
			self::NOT_CONNECTED          => __( 'Not Connected', 'uptimewire' ),
			self::AUTHORIZATION_PENDING  => __( 'Authorization Pending', 'uptimewire' ),
			self::WAITING_FOR_APPROVAL   => __( 'Waiting for User Approval', 'uptimewire' ),
			self::AUTHORIZATION_EXPIRED  => __( 'Authorization Expired', 'uptimewire' ),
			self::AUTHORIZATION_DENIED   => __( 'Authorization Denied', 'uptimewire' ),
			self::CONNECTION_ESTABLISHED => __( 'Connection Established', 'uptimewire' ),
			self::CONNECTION_ERROR       => __( 'Connection Error', 'uptimewire' ),
			self::RECONNECTION_REQUIRED  => __( 'Connection No Longer Active', 'uptimewire' ),
			self::SITE_COPY              => __( 'Copied or Moved Site', 'uptimewire' ),
		);

		return isset( $labels[ $state ] ) ? $labels[ $state ] : $labels[ self::NOT_CONNECTED ];
	}
}
