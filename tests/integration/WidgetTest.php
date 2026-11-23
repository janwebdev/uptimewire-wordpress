<?php
/**
 * WP-4: the Dashboard widget.
 *
 * @package UptimeWire
 */

namespace UptimeWire\Tests;

use UptimeWire\Admin\Widget;
use UptimeWire\Rest;
use UptimeWire\State;
use UptimeWire\Status;

/**
 * Renders the widget from the kept status and checks who gets it.
 */
final class WidgetTest extends Test_Case {

	/**
	 * A working connection for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		self::connect_site();
		wp_dequeue_script( 'uptimewire-dashboard' );
		wp_deregister_script( 'uptimewire-dashboard' );
		wp_dequeue_style( 'uptimewire-widget' );
	}

	/**
	 * The widget as printed on the Dashboard.
	 *
	 * @return string
	 */
	private static function widget() {
		return self::render( array( Widget::class, 'render' ) );
	}

	/**
	 * The widget's title line.
	 *
	 * @param string $html Widget.
	 * @return string
	 */
	private static function title( $html ) {
		return preg_match( '#<h3 class="uptimewire-widget-title">(.*?)</h3>#s', $html, $m ) ? html_entity_decode( $m[1], ENT_QUOTES ) : '';
	}

	/**
	 * Data: website states, the title and words shown.
	 *
	 * @return array<string, array{array<string, mixed>, string, string[], string[]}>
	 */
	public function states() {
		$down = gmdate( 'Y-m-d\TH:i:s+00:00', time() - 1500 );

		return array(
			'online'     => array( array(), 'Your Website Is Online', array( 'uptimewire-tone-ok', '99.97% in the last 30 days', 'Valid — 79 days remaining', 'Last checked', 'View Monitoring Details' ), array( 'Needs attention' ) ),
			'down'       => array( array( 'state' => 'down', 'down_since' => $down, 'problem' => 'Connection timed out' ), 'Your Website Is Down', array( 'uptimewire-tone-bad', 'Down since', '25 minutes', '<dt>Uptime</dt>' ), array( 'uptimewire-tone-ok' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'checking'   => array( array( 'state' => 'checking' ), 'We’re Double-Checking Your Website', array( 'uptimewire-tone-warn', 'This is not confirmed downtime.' ), array( 'uptimewire-tone-ok' ) ),
			'waiting'    => array(
		array(
		'state'           => 'waiting',
		'last_checked_at' => null,
			),
			'Monitoring Is Being Initialized',
			array( 'The first check hasn’t finished yet.' ),
			array( '<dt>Uptime</dt>', 'uptimewire-tone-ok' ),
		),
			'paused'     => array( array( 'state' => 'paused', 'paused' => true ), 'Monitoring Is Paused', array( 'uptimewire-tone-off', 'no alerts' ), array( 'uptimewire-tone-ok' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'planned'    => array( array( 'state' => 'planned' ), 'Planned Maintenance', array( 'isn’t counted as downtime' ), array() ),
			'unverified' => array( array( 'state' => 'paused', 'awaiting_email_confirmation' => true ), 'Email Verification Required', array( 'Monitoring and notifications will start after the account email is verified.' ), array( '<dt>Uptime</dt>', 'uptimewire-tone-ok' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'unknown'    => array( array( 'state' => 'degraded' ), 'Status Unknown', array( 'uptimewire-tone-unknown', 'doesn’t recognize' ), array( 'uptimewire-tone-ok' ) ),
		);
	}

	/**
	 * Every website state of the contract, and an unknown one.
	 *
	 * @dataProvider states
	 * @param array<string, mixed> $site    Fields of data.site.
	 * @param string               $title   Title.
	 * @param string[]             $present Words shown.
	 * @param string[]             $absent  Words not shown.
	 * @return void
	 */
	public function test_states( array $site, $title, array $present, array $absent ) {
		self::read_status( self::status_answer( $site ) );
		$html = self::widget();

		$this->assertSame( $title, self::title( $html ) );
		foreach ( $present as $words ) {
			$this->assertStringContainsString( $words, $html );
		}
		foreach ( $absent as $words ) {
			$this->assertStringNotContainsString( $words, $html );
		}
		$this->assertStringContainsString( 'Received from UptimeWire', $html, 'The time of the last sync is a separate fact.' );
	}

	/**
	 * The last external check and the last sync are different events.
	 *
	 * @return void
	 */
	public function test_check_time_and_sync_time_are_different() {
		self::read_status( self::status_answer( array( 'last_checked_at' => gmdate( 'Y-m-d\TH:i:s+00:00', time() - 600 ) ) ) );
		$html = self::widget();

		$this->assertMatchesRegularExpression( '#<dt>Last checked</dt>\s*<dd><time datetime="[^"]+" title="[^"]+">10 min(s|utes) ago</time>#', $html );
		$this->assertMatchesRegularExpression( '#Received from UptimeWire <time datetime="[^"]+" title="[^"]+">just now</time>#', $html );
	}

	/**
	 * Old data is "Last Known Status", in neutral colors.
	 *
	 * @return void
	 */
	public function test_stale_data() {
		self::read_status( self::status_answer() );
		self::make_status_older( 45 * 60 );
		$html = self::widget();

		$this->assertSame( 'Last Known Status: Online', self::title( $html ) );
		$this->assertMatchesRegularExpression( '#Updated 45 min(s|utes) ago\. Current monitoring status is unavailable\.#', $html );
		$this->assertStringContainsString( 'uptimewire-tone-unknown', $html );
		$this->assertStringNotContainsString( 'uptimewire-tone-ok', $html );
		$this->assertStringNotContainsString( 'dashicons-yes-alt', $html );
		$this->assertStringNotContainsString( 'Your Website Is Online', $html );
	}

	/**
	 * A failed read after recent data: the data stays, with a note.
	 *
	 * @return void
	 */
	public function test_fresh_data_with_a_failed_read() {
		self::read_status( self::status_answer() );
		self::make_status_older( 120 );
		self::read_status( array(), 503 );
		$html = self::widget();

		$this->assertSame( 'Your Website Is Online', self::title( $html ) );
		$this->assertStringContainsString( 'UptimeWire is temporarily unavailable. The status above is the latest one received.', $html );
	}

	/**
	 * No data yet, and UptimeWire unavailable without data.
	 *
	 * @return void
	 */
	public function test_no_data_and_api_unavailable() {
		$html = self::widget();
		$this->assertSame( 'Loading Your Website Status…', self::title( $html ) );
		$this->assertStringContainsString( 'data-due="1"', $html );
		$this->assertStringContainsString( 'Not received from UptimeWire yet', $html );

		self::read_status( array(), 503 );
		$html = self::widget();
		$this->assertSame( 'Status Unavailable', self::title( $html ) );
		$this->assertStringContainsString( 'not about your website', $html );
		$this->assertStringNotContainsString( 'uptimewire-tone-ok', $html );
	}

	/**
	 * Less than a day measured: no uptime figure, honest words.
	 *
	 * @return void
	 */
	public function test_monitoring_started_recently() {
		$at     = gmdate( 'Y-m-d\TH:i:s+00:00', time() - 3600 );
		$uptime = array();
		foreach ( array( '24h', '7d', '30d' ) as $window ) {
			$uptime[] = array( 'window' => $window, 'from' => $at, 'to' => gmdate( 'Y-m-d\TH:i:s+00:00' ), 'measured_seconds' => 3600, 'downtime_seconds' => 0, 'percent' => 100.0, 'history_limited' => false ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		}
		self::read_status( self::status_answer( array(), array( 'uptime' => $uptime ) ) );
		$html = self::widget();

		$this->assertStringContainsString( 'Monitoring started recently. Your first checks are complete.', $html );
		$this->assertStringNotContainsString( '100%', $html );
	}

	/**
	 * A few days measured: the figure names its start.
	 *
	 * @return void
	 */
	public function test_partial_history() {
		$at     = time() - 5 * 86400;
		$uptime = array();
		foreach ( array(
			'24h' => 86400,
			'7d'  => 5 * 86400,
			'30d' => 5 * 86400,
		) as $window => $measured ) {
			$uptime[] = array( 'window' => $window, 'from' => gmdate( 'Y-m-d\TH:i:s+00:00', time() - $measured ), 'to' => gmdate( 'Y-m-d\TH:i:s+00:00' ), 'measured_seconds' => $measured, 'downtime_seconds' => 0, 'percent' => 99.5, 'history_limited' => false ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		}
		self::read_status( self::status_answer( array(), array( 'uptime' => $uptime ) ) );

		$this->assertStringContainsString( '99.50% since ' . wp_date( get_option( 'date_format' ), $at ), self::widget() );
	}

	/**
	 * Certificate words.
	 *
	 * @return void
	 */
	public function test_ssl_words() {
		$cases = array(
			'Expired'                         => array(
				'state'     => 'problem',
				'warning'   => 'expired',
				'days_left' => -2,
			),
			'Expires soon — 6 days remaining' => array(
				'state'     => 'problem',
				'warning'   => 'expires_soon',
				'days_left' => 6,
			),
			'Check failed'                    => array(
				'state'     => 'problem',
				'warning'   => 'check_failed',
				'days_left' => null,
			),
			'No certificate'                  => array(
				'state'     => 'not_applicable',
				'warning'   => null,
				'days_left' => null,
			),
			'Not in your plan'                => array(
				'state'     => 'not_on_plan',
				'warning'   => null,
				'days_left' => null,
			),
			'Not checked yet'                 => array(
				'state'     => 'unknown',
				'warning'   => null,
				'days_left' => null,
			),
		);

		foreach ( $cases as $words => $ssl ) {
			$ssl += array(
				'checked_at' => null,
				'expires_at' => null,
				'error'      => null,
			);
			Fake_Api::reset();
			Status::forget();
			$answer                          = self::status_answer();
			$answer['data']['checks']['ssl'] = $ssl;
			self::read_status( $answer );

			$this->assertStringContainsString( $words, self::widget(), $words );
		}
	}

	/**
	 * Domain and DNS problems are named, and blocked checks explained.
	 *
	 * @return void
	 */
	public function test_attention_and_blocked_checks() {
		$answer                             = self::status_answer(
			array(
				'state'   => 'down',
				'blocked' => array( 'status' => 403 ),
			)
		);
		$answer['data']['checks']['domain'] = array( 'state' => 'problem', 'checked_at' => null, 'expires_at' => null, 'days_left' => null, 'expiry_known' => false, 'warning' => 'check_failed', 'error' => null ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$answer['data']['checks']['dns']    = array( 'state' => 'problem', 'checked_at' => null, 'changed' => true, 'open_changes' => 2, 'last_change_at' => null ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::read_status( $answer );
		$html = self::widget();

		$this->assertStringContainsString( '<dt>Needs attention</dt>', $html );
		$this->assertStringContainsString( 'domain, DNS', $html );
		$this->assertStringContainsString( 'HTTP 403', $html );
	}

	/**
	 * Not connected: one neutral invitation, no prices.
	 *
	 * @return void
	 */
	public function test_not_connected() {
		self::wipe();
		$html = self::widget();

		$this->assertSame( 'UptimeWire Is Not Connected', self::title( $html ) );
		$this->assertStringContainsString( 'Start free monitoring for your WordPress website.', $html );
		$this->assertStringContainsString( 'Set Up Monitoring', $html );
		$this->assertStringContainsString( 'href="' . esc_url( admin_url( 'admin.php?page=uptimewire' ) ) . '"', $html );
		$this->assertStringContainsString( 'data-due="0"', $html );
		foreach ( array( 'Upgrade', 'Pro', '$', '€', 'pricing', 'plans' ) as $word ) {
			$this->assertStringNotContainsString( $word, $html );
		}
		$this->assertSame( array(), Fake_Api::calls() );
	}

	/**
	 * A revoked connection: "Connection Expired" with Reconnect.
	 *
	 * @return void
	 */
	public function test_revoked() {
		self::read_status( self::status_answer() );
		self::make_status_older( 600 );
		self::read_status( array( 'error' => 'connection_ended' ), 401 );
		$html = self::widget();

		$this->assertSame( State::RECONNECTION_REQUIRED, State::current() );
		$this->assertSame( 'Connection Expired', self::title( $html ) );
		$this->assertStringContainsString( 'Reconnect', $html );
		$this->assertStringNotContainsString( 'Your Website Is Online', $html );
	}

	/**
	 * A connection of another website address shows nothing of it.
	 *
	 * @return void
	 */
	public function test_mismatch() {
		self::read_status( self::status_answer( array( 'url' => 'https://other-site.example' ) ) );
		$html = self::widget();

		$this->assertSame( 'Website Addresses Don’t Match', self::title( $html ) );
		$this->assertStringNotContainsString( 'other-site.example', $html );
		$this->assertStringNotContainsString( 'Your Website Is Online', $html );
	}

	/**
	 * Only users with the capability get the widget, its style and its script.
	 *
	 * @return void
	 */
	public function test_unauthorized_user() {
		global $wp_meta_boxes;

		require_once ABSPATH . 'wp-admin/includes/dashboard.php';
		set_current_screen( 'dashboard' );

		$wp_meta_boxes = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test reset.
		Widget::register();
		$this->assertArrayHasKey( Widget::ID, $wp_meta_boxes['dashboard']['normal']['core'] );
		$this->assertSame( 'UptimeWire — Website Status', $wp_meta_boxes['dashboard']['normal']['core'][ Widget::ID ]['title'] );

		self::sign_in_editor();
		$wp_meta_boxes = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Test reset.
		Widget::register();
		$this->assertEmpty( $wp_meta_boxes );

		Widget::enqueue( 'index.php' );
		$this->assertFalse( wp_style_is( 'uptimewire-widget', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'uptimewire-dashboard', 'enqueued' ) );

		$request = new \WP_REST_Request( 'POST', '/' . Rest::NAMESPACE_V1 . '/status-refresh' );
		$request->set_param( 'view', 'widget' );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );
		$this->assertSame( array(), Fake_Api::calls() );
	}

	/**
	 * The assets load on the Dashboard only, the script only while connected, and carry no secret.
	 *
	 * @return void
	 */
	public function test_assets() {
		Widget::enqueue( 'edit.php' );
		$this->assertFalse( wp_style_is( 'uptimewire-widget', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'uptimewire-dashboard', 'enqueued' ) );

		Widget::enqueue( 'index.php' );
		$this->assertTrue( wp_style_is( 'uptimewire-widget', 'enqueued' ) );
		$this->assertTrue( wp_script_is( 'uptimewire-dashboard', 'enqueued' ) );
		$data = (string) wp_scripts()->get_data( 'uptimewire-dashboard', 'data' );
		$this->assertStringContainsString( '"container":"uptimewire-widget"', $data );
		$this->assertStringContainsString( '"view":"widget"', $data );
		$this->assertStringNotContainsString( self::TOKEN, $data );

		wp_dequeue_script( 'uptimewire-dashboard' );
		wp_deregister_script( 'uptimewire-dashboard' );
		wp_dequeue_style( 'uptimewire-widget' );
		self::wipe();
		Widget::enqueue( 'index.php' );
		$this->assertTrue( wp_style_is( 'uptimewire-widget', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'uptimewire-dashboard', 'enqueued' ), 'Nothing to refresh before connecting.' );
	}

	/**
	 * Rendering never asks UptimeWire, also when the kept status is due.
	 *
	 * @return void
	 */
	public function test_render_makes_no_request() {
		$this->assertStringContainsString( 'data-due="1"', self::widget() );

		self::read_status( self::status_answer() );
		self::make_status_older( 3 * 3600 );
		Fake_Api::reset();
		$html = self::widget();

		$this->assertStringContainsString( 'data-due="1"', $html );
		$this->assertSame( array(), Fake_Api::calls() );
	}

	/**
	 * The REST route answers the widget for view "widget", and never sends the Dashboard elsewhere.
	 *
	 * @return void
	 */
	public function test_rest_widget_view() {
		Fake_Api::queue( '/wp/v1/status', 200, self::status_answer() );
		$request = new \WP_REST_Request( 'POST', '/' . Rest::NAMESPACE_V1 . '/status-refresh' );
		$request->set_param( 'view', 'widget' );
		$data = rest_do_request( $request )->get_data();

		$this->assertSame( Status::UPDATED, $data['result'] );
		$this->assertStringContainsString( 'uptimewire-widget-title', $data['html'] );
		$this->assertStringNotContainsString( 'uptimewire-primary', $data['html'] );
		$this->assertSame( 'Your Website Is Online', $data['announce'] );
		$this->assertCount( 1, Fake_Api::calls( '/wp/v1/status' ) );

		// A second read within the period is not sent: one sync for the widget and the Overview screen.
		$data = rest_do_request( $request )->get_data();
		$this->assertSame( Status::FRESH, $data['result'] );
		$this->assertCount( 1, Fake_Api::calls( '/wp/v1/status' ) );

		self::make_status_older( 600 );
		Fake_Api::queue( '/wp/v1/status', 401, array( 'error' => 'connection_ended' ) );
		$data = rest_do_request( $request )->get_data();
		$this->assertArrayNotHasKey( 'redirect', $data );
		$this->assertStringContainsString( 'Connection Expired', $data['html'] );

		$bad = new \WP_REST_Request( 'POST', '/' . Rest::NAMESPACE_V1 . '/status-refresh' );
		$bad->set_param( 'view', '../../evil' );
		$this->assertSame( 400, rest_do_request( $bad )->get_status() );
	}

	/**
	 * Strings from UptimeWire are escaped; unsafe links are never used.
	 *
	 * @return void
	 */
	public function test_hostile_answer() {
		$evil                    = '<script>alert(1)</script><img src=x onerror=alert(2)>';
		$answer                  = self::status_answer(
			array(
				'state'         => 'degraded<script>',
				'problem'       => $evil,
				'last_location' => $evil,
			)
		);
		$answer['data']['links'] = array(
			'site'   => 'javascript:alert(4)',
			'alerts' => 'https://evil.example/alerts',
			'plans'  => 'https://evil.example/plans',
		);
		self::read_status( $answer );
		$html = self::widget();

		$this->assertSame( 'Status Unknown', self::title( $html ) );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringNotContainsString( 'evil.example', $html );
		$this->assertStringNotContainsString( self::TOKEN, $html );
	}
}
