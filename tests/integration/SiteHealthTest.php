<?php
/**
 * WP-4: Tools › Site Health tests and Info.
 *
 * @package UptimeWire
 */

namespace UptimeWire\Tests;

use UptimeWire\Admin\Site_Health;

/**
 * Runs the UptimeWire Site Health tests and the Info section from the kept status.
 */
final class SiteHealthTest extends Test_Case {

	/**
	 * UptimeWire's direct tests that Site Health would run now, with their results.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function results() {
		$tests   = Site_Health::tests( array( 'direct' => array(), 'async' => array() ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$results = array();

		foreach ( $tests['direct'] as $name => $test ) {
			$results[ $name ] = call_user_func( $test['test'] );
		}

		return $results;
	}

	/**
	 * The UptimeWire Info fields.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function info_fields() {
		$info = Site_Health::info( array() );

		return isset( $info['uptimewire']['fields'] ) ? $info['uptimewire']['fields'] : array();
	}

	/**
	 * Before connecting, Site Health shows nothing of UptimeWire but Info.
	 *
	 * @return void
	 */
	public function test_disconnected() {
		$this->assertSame( array(), self::results() );

		$fields = self::info_fields();
		$this->assertSame( 'Not Connected', $fields['connection']['value'] );
		$this->assertSame( 'https://www.customer-site.com/', $fields['site']['value'] );
		$this->assertSame( 'Never', $fields['synced']['value'] );
		$this->assertArrayNotHasKey( 'account', $fields );
		$this->assertSame( array(), Fake_Api::calls() );
	}

	/**
	 * Connected and online: every test passes.
	 *
	 * @return void
	 */
	public function test_connected_and_active() {
		self::connect_site();
		self::read_status( self::status_answer() );
		Fake_Api::reset();
		$results = self::results();

		$this->assertSame( array( 'uptimewire_connection', 'uptimewire_monitoring', 'uptimewire_ssl', 'uptimewire_access' ), array_keys( $results ), 'An unknown domain expiration date is not a passed test.' );
		foreach ( $results as $name => $result ) {
			$this->assertSame( 'good', $result['status'], $name );
			$this->assertSame( $name, $result['test'] );
			$this->assertSame( 'UptimeWire', $result['badge']['label'] );
		}
		$this->assertSame( 'UptimeWire is monitoring your website, and it is online', $results['uptimewire_monitoring']['label'] );
		$this->assertStringContainsString( 'every 5 minutes', $results['uptimewire_monitoring']['description'] );
		$this->assertSame( 'Your SSL certificate is valid', $results['uptimewire_ssl']['label'] );
		$this->assertStringContainsString( 'Expires in 79 days', $results['uptimewire_ssl']['description'] );
		$this->assertSame( 'Unavailable — the registry does not provide a reliable expiration date', self::info_fields()['domain']['value'], 'Domain expiry unknown is said in Info.' );
		$this->assertSame( 'UptimeWire can reach your website', $results['uptimewire_access']['label'] );
		$this->assertSame( array(), Fake_Api::calls(), 'Site Health never asks UptimeWire.' );
	}

	/**
	 * Data: website states, the monitoring result and its label.
	 *
	 * @return array<string, array{array<string, mixed>, string, string}>
	 */
	public function monitoring_states() {
		return array(
			'paused'     => array( array( 'state' => 'paused', 'paused' => true ), 'recommended', 'UptimeWire monitoring is paused' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'down'       => array( array( 'state' => 'down', 'down_since' => gmdate( 'Y-m-d\TH:i:s+00:00', time() - 600 ), 'problem' => 'HTTP 500' ), 'critical', 'UptimeWire reports that your website is down' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'checking'   => array( array( 'state' => 'checking' ), 'recommended', 'UptimeWire is double-checking your website' ),
			'waiting'    => array( array( 'state' => 'waiting', 'last_checked_at' => null ), 'good', 'UptimeWire monitoring has started' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'planned'    => array( array( 'state' => 'planned' ), 'good', 'Your website is in planned maintenance' ),
			'unverified' => array( array( 'state' => 'paused', 'awaiting_email_confirmation' => true ), 'recommended', 'UptimeWire monitoring hasn’t started: the account email isn’t verified' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'unknown'    => array( array( 'state' => 'degraded' ), 'recommended', 'UptimeWire reported a status this plugin doesn’t recognize' ),
		);
	}

	/**
	 * The monitoring test of every state.
	 *
	 * @dataProvider monitoring_states
	 * @param array<string, mixed> $site   Fields of data.site.
	 * @param string               $status Expected result status.
	 * @param string               $label  Expected label.
	 * @return void
	 */
	public function test_monitoring_states( array $site, $status, $label ) {
		self::connect_site();
		self::read_status( self::status_answer( $site ) );
		$results = self::results();

		$this->assertSame( $status, $results['uptimewire_monitoring']['status'] );
		$this->assertSame( $label, $results['uptimewire_monitoring']['label'] );
		if ( 'up' !== $site['state'] ) {
			$this->assertArrayNotHasKey( 'uptimewire_access', $results, 'Only a current successful check proves access.' );
		}
	}

	/**
	 * Certificate: expired, expiring, failed check, not applicable.
	 *
	 * @return void
	 */
	public function test_ssl() {
		self::connect_site();
		$cases  = array(
			'critical'    => array(
				'state'      => 'problem',
				'warning'    => 'expired',
				'days_left'  => -3,
				'expires_at' => gmdate( 'Y-m-d\TH:i:s+00:00', time() - 3 * 86400 ),
			),
			'recommended' => array(
				'state'      => 'problem',
				'warning'    => 'expires_soon',
				'days_left'  => 6,
				'expires_at' => gmdate( 'Y-m-d\TH:i:s+00:00', time() + 6 * 86400 ),
			),
		);
		$labels = array(
			'critical'    => array( 'Your SSL certificate has expired', 'Expired 3 days ago' ),
			'recommended' => array( 'Your SSL certificate expires soon', 'Expires in 6 days' ),
		);

		foreach ( $cases as $status => $ssl ) {
			\UptimeWire\Status::forget();
			$answer                          = self::status_answer();
			$answer['data']['checks']['ssl'] = $ssl + array(
				'checked_at' => null,
				'error'      => null,
			);
			self::read_status( $answer );
			$result = self::results()['uptimewire_ssl'];

			$this->assertSame( $status, $result['status'] );
			$this->assertSame( $labels[ $status ][0], $result['label'] );
			$this->assertStringContainsString( $labels[ $status ][1], $result['description'] );
		}

		\UptimeWire\Status::forget();
		$answer                          = self::status_answer();
		$answer['data']['checks']['ssl'] = array( 'state' => 'not_applicable', 'checked_at' => null, 'expires_at' => null, 'days_left' => null, 'warning' => null, 'error' => null ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::read_status( $answer );
		$this->assertArrayNotHasKey( 'uptimewire_ssl', self::results(), 'WordPress has its own HTTPS test; nothing to add.' );
	}

	/**
	 * Domain: known date, expiring, expired. An unknown date (not published, failed lookup, not checked) is no test.
	 *
	 * @return void
	 */
	public function test_domain() {
		self::connect_site();
		$cases = array(
			array( array( 'state' => 'ok', 'expiry_known' => true, 'days_left' => 200, 'expires_at' => gmdate( 'Y-m-d\TH:i:s+00:00', time() + 200 * 86400 ) ), 'good', 'Your domain registration is current' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( array( 'state' => 'ok', 'expiry_known' => false ), null, '' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( array( 'state' => 'problem', 'warning' => 'expires_soon', 'expiry_known' => true, 'days_left' => 9, 'expires_at' => gmdate( 'Y-m-d\TH:i:s+00:00', time() + 9 * 86400 ) ), 'recommended', 'Your domain expires soon' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( array( 'state' => 'problem', 'warning' => 'expired', 'expiry_known' => true, 'days_left' => -1, 'expires_at' => gmdate( 'Y-m-d\TH:i:s+00:00', time() - 86400 ) ), 'critical', 'Your domain registration has expired' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( array( 'state' => 'problem', 'warning' => 'check_failed', 'expiry_known' => false ), null, '' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( array( 'state' => 'unknown' ), null, '' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( array( 'state' => 'not_on_plan' ), null, '' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		);

		foreach ( $cases as $case ) {
			\UptimeWire\Status::forget();
			$answer                             = self::status_answer();
			$answer['data']['checks']['domain'] = $case[0] + array( 'checked_at' => null, 'expires_at' => null, 'days_left' => null, 'expiry_known' => false, 'warning' => null, 'error' => null ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			self::read_status( $answer );
			$results = self::results();

			if ( null === $case[1] ) {
				$this->assertArrayNotHasKey( 'uptimewire_domain', $results );
				continue;
			}
			$this->assertSame( $case[1], $results['uptimewire_domain']['status'], $case[2] );
			$this->assertSame( $case[2], $results['uptimewire_domain']['label'] );
		}
	}

	/**
	 * A firewall that turns the checks away: explained, with the guide.
	 *
	 * @return void
	 */
	public function test_firewall_blocking() {
		self::connect_site();
		self::read_status(
			self::status_answer(
				array(
					'state'   => 'down',
					'blocked' => array( 'status' => 403 ),
				)
			)
		);
		$results = self::results();
		$this->assertSame( 'recommended', $results['uptimewire_monitoring']['status'], 'Blocked checks are a monitoring problem first.' );
		$this->assertSame( 'UptimeWire reports your website as down, but its checks are being blocked', $results['uptimewire_monitoring']['label'] );
		$result = $results['uptimewire_access'];

		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'A firewall or security plugin may be blocking UptimeWire’s checks', $result['label'] );
		$this->assertStringContainsString( 'HTTP 403', $result['description'] );
		$this->assertStringContainsString( 'https://uptimewire.com/resources/guides/allow-uptimewire-checks-through-firewall/', $result['actions'] );
		$this->assertStringContainsString( 'https://app.uptimewire.com/ips.txt', $result['actions'] );
	}

	/**
	 * UptimeWire unavailable, or nothing kept yet: no website problem, no data tests.
	 *
	 * @return void
	 */
	public function test_api_and_cache_unavailable() {
		self::connect_site();

		$results = self::results();
		$this->assertSame( array( 'uptimewire_connection', 'uptimewire_monitoring' ), array_keys( $results ) );
		$this->assertSame( 'WordPress hasn’t received the monitoring status from UptimeWire yet', $results['uptimewire_monitoring']['label'] );
		$this->assertSame( 'recommended', $results['uptimewire_monitoring']['status'] );
		$this->assertStringContainsString( 'not about your website', $results['uptimewire_monitoring']['description'] );

		self::read_status( array(), 503 );
		$results = self::results();
		$this->assertSame( array( 'uptimewire_connection', 'uptimewire_monitoring' ), array_keys( $results ) );
		$this->assertSame( 'The monitoring status from UptimeWire is unavailable', $results['uptimewire_monitoring']['label'] );
		$this->assertStringContainsString( 'UptimeWire is temporarily unavailable.', $results['uptimewire_monitoring']['description'] );
		$this->assertSame( 'UptimeWire temporarily unavailable', self::info_fields()['error']['value'] );
	}

	/**
	 * Old data: the last known status, never reported as current.
	 *
	 * @return void
	 */
	public function test_stale() {
		self::connect_site();
		self::read_status( self::status_answer() );
		self::make_status_older( 3 * 3600 );
		$results = self::results();

		$this->assertSame( 'The UptimeWire status in WordPress is out of date', $results['uptimewire_monitoring']['label'] );
		$this->assertStringContainsString( 'Last known status: Your Website Is Online', $results['uptimewire_monitoring']['description'] );
		$this->assertArrayNotHasKey( 'uptimewire_access', $results );
		$this->assertSame( 'stale', self::info_fields()['cache']['debug'] );
	}

	/**
	 * An ended connection is reported once, without monitoring results.
	 *
	 * @return void
	 */
	public function test_revoked() {
		self::connect_site();
		self::read_status( array( 'error' => 'connection_ended' ), 401 );
		$results = self::results();

		$this->assertSame( array( 'uptimewire_connection' ), array_keys( $results ) );
		$this->assertSame( 'recommended', $results['uptimewire_connection']['status'] );
		$this->assertSame( 'The UptimeWire connection is no longer active', $results['uptimewire_connection']['label'] );
		$this->assertStringContainsString( 'Reconnect', $results['uptimewire_connection']['actions'] );
		$this->assertSame( 'Connection no longer active', self::info_fields()['error']['value'] );
	}

	/**
	 * A connection of another website: its address is not shown anywhere here.
	 *
	 * @return void
	 */
	public function test_mismatch() {
		self::connect_site();
		self::read_status( self::status_answer( array( 'url' => 'https://other-site.example' ) ) );
		$results = self::results();
		$fields  = self::info_fields();

		$this->assertSame( 'The UptimeWire connection belongs to another website address', $results['uptimewire_connection']['label'] );
		$this->assertStringNotContainsString( 'other-site.example', wp_json_encode( array( $results, $fields ) ) );
		$this->assertSame( 'https://www.customer-site.com/', $fields['site']['value'] );
	}

	/**
	 * Users without the capability get no UptimeWire tests and no Info.
	 *
	 * @return void
	 */
	public function test_unauthorized_user() {
		self::connect_site();
		self::read_status( self::status_answer() );
		self::sign_in_editor();

		$this->assertSame( array(), self::results() );
		$this->assertSame( array(), Site_Health::info( array() ) );

		wp_set_current_user( 0 );
		$this->assertSame( array(), self::results(), 'The scheduled check runs without a user.' );
	}

	/**
	 * Info: the support fields, and nothing secret.
	 *
	 * @return void
	 */
	public function test_info_is_safe_to_share() {
		self::connect_site();
		$evil                                     = '<script>alert(1)</script>';
		$answer                                   = self::status_answer(
			array(
				'problem'       => $evil,
				'last_location' => $evil,
			)
		);
		$answer['data']['alerts']['channels']     = array( 'email', 'owner@customer-site.com' );
		$answer['data']['checks']['ssl']['error'] = 'Certificate for owner@customer-site.com';
		self::read_status( $answer );

		$fields = self::info_fields();
		$this->assertSame( UPTIMEWIRE_VERSION, $fields['version']['value'] );
		$this->assertSame( 'Connection Established', $fields['connection']['value'] );
		$this->assertSame( 'https://www.customer-site.com', $fields['site']['value'] );
		$this->assertSame( 'ACC-00001000', $fields['account']['value'] );
		$this->assertSame( 'WordPress database', $fields['credential']['value'] );
		$this->assertSame( 'current', $fields['cache']['debug'] );
		$this->assertSame( 'None', $fields['error']['value'] );
		$this->assertStringStartsWith( 'Your Website Is Online; checked every 5 min; last check:', $fields['monitoring']['value'] );
		$this->assertSame( 'SSL: ok; domain: ok; DNS: ok', $fields['checks']['value'] );

		if ( ! class_exists( 'WP_Debug_Data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-debug-data.php';
		}
		$all = wp_json_encode( array( Site_Health::info( array() ), self::results() ) ) . \WP_Debug_Data::format( Site_Health::info( array() ), 'debug' );

		foreach ( array( self::TOKEN, 'uwp_', 'uwd_', 'WDJB-MJHT', 'Bearer', 'Authorization', '@', 'admin@example.com', '<script', '&lt;script', 'Germany', 'app.uptimewire.com/user', 'subscription' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $all, $secret );
		}
	}
}
