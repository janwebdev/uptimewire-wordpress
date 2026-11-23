<?php
/**
 * Base class of the integration tests.
 *
 * @package UptimeWire
 */

namespace UptimeWire\Tests;

use PHPUnit\Framework\TestCase;
use UptimeWire\Authorization;

/**
 * A clean plugin state for every test, an administrator, a public website to
 * monitor and helpers to play UptimeWire's side.
 */
abstract class Test_Case extends TestCase {

	/** The website to monitor in the tests (the stand itself is on localhost, a local site). */
	const SITE = 'https://www.customer-site.com/';

	/** A credential of the right shape. */
	const TOKEN = 'uwp_0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef';

	/** A device code of the right shape. */
	const DEVICE_CODE = 'uwd_fedcba9876543210fedcba9876543210fedcba9876543210fedcba9876543210';

	/** An authorization ID. */
	const AUTHORIZATION_ID = '0196a3b2-1c2d-7e3f-8a9b-0c1d2e3f4a5b';

	/**
	 * Cleans the plugin's rows and signs in the administrator.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		self::wipe();
		Fake_Api::reset();
		update_option( 'uptimewire_site_url', self::SITE, false );
		wp_set_current_user( 1 );
	}

	/**
	 * Cleans up after the test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		self::wipe();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * Deletes every row of the plugin, as uninstall does.
	 *
	 * @return void
	 */
	protected static function wipe() {
		global $wpdb;

		foreach ( self::plugin_rows() as $name ) {
			if ( 0 === strpos( $name, '_transient_timeout_' ) ) {
				continue;
			}
			if ( 0 === strpos( $name, '_transient_' ) ) {
				delete_transient( substr( $name, strlen( '_transient_' ) ) );
			} else {
				delete_option( $name );
			}
		}
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_timeout\\_uptimewire\\_%'" ); // phpcs:ignore
		wp_cache_flush();
	}

	/**
	 * Names of the plugin's rows in the options table.
	 *
	 * @return string[]
	 */
	protected static function plugin_rows() {
		global $wpdb;

		return $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE '%uptimewire\\_%' ORDER BY option_name" ); // phpcs:ignore
	}

	/**
	 * Everything the plugin keeps in the database, as one string.
	 *
	 * @return string
	 */
	protected static function plugin_rows_dump() {
		global $wpdb;

		return implode( "\n", $wpdb->get_col( "SELECT CONCAT(option_name, '=', option_value) FROM {$wpdb->options} WHERE option_name LIKE '%uptimewire%'" ) ); // phpcs:ignore
	}

	/**
	 * A /wp/v1/device answer as UptimeWire gives it.
	 *
	 * @param array<string, mixed> $override Fields to change.
	 * @return array<string, mixed>
	 */
	protected static function device_answer( array $override = array() ) {
		return $override + array(
			'device_code'               => self::DEVICE_CODE,
			'user_code'                 => 'WDJB-MJHT',
			'verification_uri'          => 'https://app.uptimewire.com/connect',
			'verification_uri_complete' => 'https://app.uptimewire.com/connect?code=WDJB-MJHT',
			'expires_in'                => 600,
			'interval'                  => 5,
			'authorization_id'          => self::AUTHORIZATION_ID,
		);
	}

	/**
	 * A /wp/v1/token answer with the credential.
	 *
	 * @param string $site Website UptimeWire monitors.
	 * @return array<string, mixed>
	 */
	protected static function token_answer( $site = 'https://www.customer-site.com' ) {
		return array(
			'access_token' => self::TOKEN,
			'token_type'   => 'Bearer',
			'connection'   => array(
				'id'           => '0196a3b2-aaaa-7e3f-8a9b-0c1d2e3f4a5b',
				'connected_at' => '2026-11-23T08:37:39+00:00',
				'site'         => array( 'url' => $site ),
			),
		);
	}

	/**
	 * An OAuth error answer.
	 *
	 * @param string               $error Error code.
	 * @param array<string, mixed> $extra Extra fields.
	 * @return array<string, mixed>
	 */
	protected static function oauth_error( $error, array $extra = array() ) {
		return array(
			'error'             => $error,
			'error_description' => 'Description.',
		) + $extra;
	}

	/**
	 * Starts connecting with a queued /device answer.
	 *
	 * @return array<string, mixed> Result of Authorization::start().
	 */
	protected function start_connecting() {
		Fake_Api::queue( '/wp/v1/device', 200, self::device_answer() );

		return Authorization::start( 1, false );
	}

	/**
	 * Makes the next question to UptimeWire due now, as if the interval passed.
	 *
	 * @return void
	 */
	protected static function make_due() {
		$pending                 = Authorization::pending();
		$pending['next_poll_at'] = time() - 1;
		set_transient( Authorization::TRANSIENT, $pending, 900 );
	}

	/**
	 * Moves the pending authorization's clock: positive seconds make it older.
	 *
	 * @param int $seconds Seconds.
	 * @return void
	 */
	protected static function age_pending( $seconds ) {
		$pending                  = Authorization::pending();
		$pending['expires_at']   -= $seconds;
		$pending['started_at']   -= $seconds;
		$pending['next_poll_at'] -= $seconds;
		set_transient( Authorization::TRANSIENT, $pending, 900 );
	}

	/**
	 * Stores a working connection of the test website, as after connecting.
	 *
	 * @return void
	 */
	protected static function connect_site() {
		\UptimeWire\Connection::save(
			self::TOKEN,
			array(
				'id'            => '0196a3b2-aaaa-7e3f-8a9b-0c1d2e3f4a5b',
				'connected_at'  => '2026-11-23T08:37:39+00:00',
				'site_url'      => 'https://www.customer-site.com',
				'local_url'     => 'https://www.customer-site.com',
				'connected_by'  => 1,
				'account_label' => 'ACC-00001000',
			)
		);
	}

	/**
	 * A /wp/v1/status answer of the test website, as in the BE-3 contract example.
	 *
	 * Times are relative to now, so "ago" texts stay stable.
	 *
	 * @param array<string, mixed> $site   Fields of data.site to change.
	 * @param array<string, mixed> $change Top-level parts of data to replace whole (uptime, checks, events, alerts, links).
	 * @return array<string, mixed>
	 */
	protected static function status_answer( array $site = array(), array $change = array() ) {
		$at = static function ( $seconds_ago ) {
			return gmdate( 'Y-m-d\TH:i:s+00:00', time() - $seconds_ago );
		};

		$data = array(
			'connection' => array(
				'id'            => '0196a3b2-aaaa-7e3f-8a9b-0c1d2e3f4a5b',
				'state'         => 'active',
				'connected_at'  => '2026-11-23T08:37:39+00:00',
				'account_label' => 'ACC-00001000',
			),
			'site'       => $site + array(
				'url'                         => 'https://www.customer-site.com',
				'created_at'                  => $at( 90 * 86400 ),
				'state'                       => 'up',
				'paused'                      => false,
				'awaiting_email_confirmation' => false,
				'interval_minutes'            => 5,
				'problem'                     => null,
				'down_since'                  => null,
				'last_checked_at'             => $at( 120 ),
				'last_success_at'             => $at( 120 ),
				'last_location'               => 'Germany',
				'average_response_ms_24h'     => 412,
				'blocked'                     => null,
			),
			'uptime'     => array(
				array( 'window' => '24h', 'from' => $at( 86400 ), 'to' => $at( 0 ), 'measured_seconds' => 86400, 'downtime_seconds' => 0, 'percent' => 100.0, 'history_limited' => false ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				array( 'window' => '7d', 'from' => $at( 604800 ), 'to' => $at( 0 ), 'measured_seconds' => 604800, 'downtime_seconds' => 600, 'percent' => 99.9, 'history_limited' => false ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				array( 'window' => '30d', 'from' => $at( 2592000 ), 'to' => $at( 0 ), 'measured_seconds' => 2592000, 'downtime_seconds' => 600, 'percent' => 99.97, 'history_limited' => false ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			),
			'checks'     => array(
				'ssl'    => array( 'state' => 'ok', 'checked_at' => $at( 3600 ), 'expires_at' => $at( -79 * 86400 ), 'days_left' => 79, 'warning' => null, 'error' => null ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				'domain' => array( 'state' => 'ok', 'checked_at' => $at( 3600 ), 'expires_at' => null, 'days_left' => null, 'expiry_known' => false, 'warning' => null, 'error' => null ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				'dns'    => array( 'state' => 'ok', 'checked_at' => $at( 3600 ), 'changed' => false, 'open_changes' => 0, 'last_change_at' => null ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			),
			'events'     => array(
				array( 'type' => 'downtime', 'kind' => 'resolved', 'at' => $at( 3 * 86400 ), 'ended_at' => $at( 3 * 86400 - 600 ), 'duration_seconds' => 600, 'expires_at' => null ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			),
			'alerts'     => array(
				'ready'         => true,
				'channels'      => array( 'email', 'telegram' ),
				'email_pending' => false,
			),
			'links'      => array(
				'site'   => 'https://app.uptimewire.com/monitors/0193a3b2/view',
				'alerts' => 'https://app.uptimewire.com/user/profile/notifications',
				'plans'  => 'https://app.uptimewire.com/subscription-plans',
			),
		);

		return array(
			'data' => $change + $data,
			'meta' => array( 'server_time' => $at( 0 ) ),
		);
	}

	/**
	 * Output of a callable.
	 *
	 * @param callable $render Renderer.
	 * @return string
	 */
	protected static function render( callable $render ) {
		ob_start();
		$render();

		return (string) ob_get_clean();
	}
}
