<?php
/**
 * WP-3: the monitoring dashboard, its kept status and its refresh.
 *
 * @package UptimeWire
 */

namespace UptimeWire\Tests;

use UptimeWire\Admin\Dashboard;
use UptimeWire\Admin\Overview_Page;
use UptimeWire\Connection;
use UptimeWire\Rest;
use UptimeWire\State;
use UptimeWire\Status;

/**
 * Reads GET /wp/v1/status through the fake API and renders the Overview screen.
 */
final class DashboardTest extends Test_Case {

	/**
	 * A working connection for every test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		self::connect_site();
	}

	/**
	 * Reads one queued status answer and returns the Overview screen.
	 *
	 * @param array<string, mixed>|string $body    Answer body.
	 * @param int                         $code    HTTP status.
	 * @param array<string, string>       $headers Response headers.
	 * @return string
	 */
	private function read_and_render( $body, $code = 200, array $headers = array() ) {
		Fake_Api::queue( '/wp/v1/status', $code, $body, $headers );
		Status::refresh( true );

		return self::render( array( Overview_Page::class, 'render' ) );
	}

	/**
	 * Makes the kept status older, as if time passed.
	 *
	 * @param int $seconds Seconds.
	 * @return void
	 */
	private static function age_status( $seconds ) {
		$kept                  = get_option( Status::OPTION );
		$kept['fetched_at']   -= $seconds;
		$kept['attempted_at'] -= $seconds;
		$kept['retry_at']      = 0;
		update_option( Status::OPTION, $kept, false );
	}

	/**
	 * The main status title of a rendered screen.
	 *
	 * @param string $html Screen.
	 * @return string
	 */
	private static function primary_title( $html ) {
		return preg_match( '#<h2 id="uptimewire-primary-title">(.*?)</h2>#s', $html, $m ) ? html_entity_decode( $m[1], ENT_QUOTES ) : '';
	}

	/**
	 * Online: the request, the kept copy and the screen.
	 *
	 * @return void
	 */
	public function test_online() {
		$html = $this->read_and_render( self::status_answer() );

		$call = Fake_Api::calls( '/wp/v1/status' )[0];
		$this->assertSame( 'Bearer ' . self::TOKEN, $call['args']['headers']['Authorization'] );
		$this->assertSame( 'https://api.uptimewire.com/wp/v1/status', $call['url'] );
		$this->assertSame( 0, $call['args']['redirection'] );
		$this->assertTrue( $call['args']['sslverify'] );

		$this->assertSame( 'Your Website Is Online', self::primary_title( $html ) );
		$this->assertStringContainsString( 'uptimewire-primary uptimewire-tone-ok', $html );
		$this->assertStringContainsString( 'ACC-00001000', $html );
		$this->assertStringContainsString( 'https://www.customer-site.com', $html );
		$this->assertMatchesRegularExpression( '/Last checked <time[^>]*>' . human_time_diff( time() - 120, time() ) . ' ago<\/time> from Germany\./', $html );
		$this->assertStringContainsString( 'Uptime, 30 days: 99.97%', $html );
		$this->assertStringContainsString( 'Average response, 24 hours: 412 ms', $html );
		$this->assertStringContainsString( '>100%<', str_replace( array( "\t", "\n" ), '', $html ) );
		$this->assertStringContainsString( 'Alerts Are Ready', $html );
		$this->assertStringContainsString( '<li>Email</li>', $html );
		$this->assertStringContainsString( '<li>Telegram</li>', $html );
		$this->assertStringContainsString( 'Website was down', $html );
		$this->assertStringContainsString( 'Lasted 10 minutes', $html );
		$this->assertStringContainsString( 'href="https://app.uptimewire.com/monitors/0193a3b2/view"', $html );
		$this->assertStringContainsString( 'Expiry date unknown', $html );
		$this->assertStringContainsString( 'Connection Established', $html );
		$this->assertSame( Connection::HEALTH_OK, Connection::health_state( Connection::get() ) );
		$this->assertSame( 'ACC-00001000', Connection::account_label() );
	}

	/**
	 * Data: website states, the title shown and words that must not appear.
	 *
	 * @return array<string, array{array<string, mixed>, string, string[]}>
	 */
	public function states() {
		$ago = gmdate( 'Y-m-d\TH:i:s+00:00', time() - 1500 );

		return array(
			'down'       => array(
				array(
					'state'      => 'down',
					'down_since' => $ago,
					'problem'    => 'Connection timed out after 30 seconds',
				),
				'Your Website Is Down',
				array( 'Your Website Is Online' ),
			),
			'checking'   => array(
				array(
					'state'   => 'checking',
					'problem' => 'HTTP 502',
				),
				'We’re Double-Checking Your Website',
				array( 'Your Website Is Down', 'Your Website Is Online' ),
			),
			'waiting'    => array(
				array(
					'state'           => 'waiting',
					'last_checked_at' => null,
					'last_success_at' => null,
				),
				'Monitoring Is Being Initialized',
				array( 'Your Website Is Online' ),
			),
			'paused'     => array(
				array(
					'state'  => 'paused',
					'paused' => true,
				),
				'Monitoring Is Paused',
				array( 'Your Website Is Online' ),
			),
			'planned'    => array( array( 'state' => 'planned' ), 'Planned Maintenance', array( 'Your Website Is Down', 'Your Website Is Online' ) ),
			'unverified' => array(
				array(
					'state'                       => 'paused',
					'awaiting_email_confirmation' => true,
					'last_checked_at'             => null,
				),
				'One More Step: Verify Your Email',
				array( 'Your Website Is Online', 'Monitoring Is Paused', 'Website Checks' ),
			),
			'unknown'    => array( array( 'state' => 'degraded' ), 'Status Unknown', array( 'Your Website Is Online', 'Degraded' ) ),
			'wrong type' => array( array( 'state' => array( 'up' ) ), 'Status Unknown', array( 'Your Website Is Online' ) ),
		);
	}

	/**
	 * Every state has its own title and is never shown as online.
	 *
	 * @dataProvider states
	 * @param array<string, mixed> $site   Fields of data.site.
	 * @param string               $title  Expected title.
	 * @param string[]             $absent Words that must not appear.
	 * @return void
	 */
	public function test_states( array $site, $title, array $absent ) {
		$html = $this->read_and_render( self::status_answer( $site ) );

		$this->assertSame( $title, self::primary_title( $html ) );
		$this->assertStringNotContainsString( 'uptimewire-primary uptimewire-tone-ok', $html, 'Only "up" is green.' );
		foreach ( $absent as $words ) {
			$this->assertStringNotContainsString( $words, $html );
		}
	}

	/**
	 * Down: since when, why, and a blocked check explained without claiming the site is down for visitors.
	 *
	 * @return void
	 */
	public function test_down_details() {
		$html = $this->read_and_render(
			self::status_answer(
				array(
					'state'      => 'down',
					'down_since' => gmdate( 'Y-m-d\TH:i:s+00:00', time() - 1500 ),
					'problem'    => 'HTTP 403 Forbidden',
					'blocked'    => array( 'status' => 403 ),
				)
			)
		);

		$this->assertStringContainsString( 'Down since <time', $html );
		$this->assertStringContainsString( '(25 minutes).', $html );
		$this->assertStringContainsString( 'HTTP 403 Forbidden', $html );
		$this->assertStringContainsString( 'answered UptimeWire’s checks with HTTP 403', $html );
		$this->assertStringContainsString( 'allow-uptimewire-checks-through-firewall', $html );
	}

	/**
	 * Waiting and email not confirmed: no uptime is invented.
	 *
	 * @return void
	 */
	public function test_nothing_measured_is_not_uptime() {
		$none   = array();
		$change = array();
		foreach ( array( '24h', '7d', '30d' ) as $window ) {
			$none[] = array( 'window' => $window, 'from' => null, 'to' => gmdate( 'Y-m-d\TH:i:s+00:00' ), 'measured_seconds' => 0, 'downtime_seconds' => 0, 'percent' => null, 'history_limited' => false ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		}
		$change['uptime'] = $none;
		$change['events'] = array();

		$html = $this->read_and_render( self::status_answer( array( 'state' => 'waiting', 'last_checked_at' => null ), $change ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertSame( 3, substr_count( $html, 'class="uptimewire-percent">' ) );
		$this->assertSame( 3, preg_match_all( '/uptimewire-percent">\s*Not measured yet/', $html ) );
		$this->assertStringContainsString( 'Uptime, 30 days: not measured yet', $html );
		$this->assertStringNotContainsString( '100%', $html );
		$this->assertStringContainsString( 'No events in the history', $html );
	}

	/**
	 * Partial history: the measured time and its start are said; the plan limit is explained.
	 *
	 * @return void
	 */
	public function test_partial_history() {
		$answer                      = self::status_answer();
		$answer['data']['uptime'][2] = array( 'window' => '30d', 'from' => gmdate( 'Y-m-d\TH:i:s+00:00', time() - 2 * 86400 ), 'to' => gmdate( 'Y-m-d\TH:i:s+00:00' ), 'measured_seconds' => 2 * 86400, 'downtime_seconds' => 0, 'percent' => 100.0, 'history_limited' => true ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$html = $this->read_and_render( $answer );

		$this->assertStringContainsString( '2 days (since ', $html );
		$this->assertStringContainsString( 'Uptime since ', $html, 'The card names the shorter history.' );
		$this->assertStringNotContainsString( 'Uptime, 30 days: 100%', $html );
		$this->assertStringContainsString( 'Your plan keeps less history', $html );
		$this->assertStringContainsString( 'counts only the time UptimeWire actually measured', $html );
	}

	/**
	 * Data: SSL and domain checks and the words they show.
	 *
	 * @return array<string, array{string, array<string, mixed>, string[]}>
	 */
	public function expiry_checks() {
		$soon = gmdate( 'Y-m-d\TH:i:s+00:00', time() + 5 * 86400 + 600 );
		$past = gmdate( 'Y-m-d\TH:i:s+00:00', time() - 3 * 86400 );

		return array(
			'ssl valid'          => array( 'ssl', array( 'state' => 'ok', 'checked_at' => null, 'expires_at' => $soon, 'days_left' => 79, 'warning' => null, 'error' => null ), array( 'Valid', 'Expires in 79 days' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'ssl expired'        => array( 'ssl', array( 'state' => 'problem', 'checked_at' => null, 'expires_at' => $past, 'days_left' => -3, 'warning' => 'expired', 'error' => null ), array( 'Expired', 'Expired 3 days ago', 'Needs attention: SSL certificate.' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'ssl expires soon'   => array( 'ssl', array( 'state' => 'problem', 'checked_at' => null, 'expires_at' => $soon, 'days_left' => 5, 'warning' => 'expires_soon', 'error' => null ), array( 'Expires soon', 'Expires in 5 days' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'ssl check failed'   => array( 'ssl', array( 'state' => 'problem', 'checked_at' => null, 'expires_at' => null, 'days_left' => null, 'warning' => 'check_failed', 'error' => 'TLS handshake failed' ), array( 'Check failed', 'TLS handshake failed' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'ssl not applicable' => array( 'ssl', array( 'state' => 'not_applicable', 'checked_at' => null, 'expires_at' => null, 'days_left' => null, 'warning' => null, 'error' => null ), array( 'No certificate', 'uses http://' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'ssl not on plan'    => array( 'ssl', array( 'state' => 'not_on_plan', 'checked_at' => null, 'expires_at' => null, 'days_left' => null, 'warning' => null, 'error' => null ), array( 'Not in your plan' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'domain known'       => array( 'domain', array( 'state' => 'ok', 'checked_at' => null, 'expires_at' => $soon, 'days_left' => 5, 'expiry_known' => true, 'warning' => null, 'error' => null ), array( 'Registered', 'Expires in 5 days' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'domain unknown'     => array( 'domain', array( 'state' => 'ok', 'checked_at' => null, 'expires_at' => null, 'days_left' => null, 'expiry_known' => false, 'warning' => null, 'error' => null ), array( 'Registered', 'Expiry date unknown' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'domain not checked' => array( 'domain', array( 'state' => 'unknown', 'checked_at' => null, 'expires_at' => null, 'days_left' => null, 'expiry_known' => false, 'warning' => null, 'error' => null ), array( 'Unknown', 'No result to show yet.' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		);
	}

	/**
	 * SSL and domain cards.
	 *
	 * @dataProvider expiry_checks
	 * @param string               $check Check name.
	 * @param array<string, mixed> $value Check answer.
	 * @param string[]             $words Words shown.
	 * @return void
	 */
	public function test_expiry_cards( $check, array $value, array $words ) {
		$answer                             = self::status_answer();
		$answer['data']['checks'][ $check ] = $value;

		$html = $this->read_and_render( $answer );

		foreach ( $words as $word ) {
			$this->assertStringContainsString( $word, $html );
		}
	}

	/**
	 * DNS: healthy and changed.
	 *
	 * @return void
	 */
	public function test_dns_cards() {
		$html = $this->read_and_render( self::status_answer() );
		$this->assertStringContainsString( 'No unreviewed changes', $html );
		$this->assertStringContainsString( 'No changes detected', $html );

		$answer                          = self::status_answer();
		$answer['data']['checks']['dns'] = array( 'state' => 'problem', 'checked_at' => null, 'changed' => true, 'open_changes' => 3, 'last_change_at' => gmdate( 'Y-m-d\TH:i:s+00:00', time() - 3600 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::age_status( 3600 );

		$html = $this->read_and_render( $answer );
		$this->assertStringContainsString( 'Changes detected', $html );
		$this->assertStringContainsString( '3 changes to review in UptimeWire', $html );
		$this->assertSame( 'Your Website Is Online', self::primary_title( $html ), 'A DNS change is not downtime.' );
	}

	/**
	 * Events of every type, with their own words; only outages are downtime.
	 *
	 * @return void
	 */
	public function test_event_types() {
		$at     = static function ( $days ) {
			return gmdate( 'Y-m-d\TH:i:s+00:00', time() - $days * 86400 );
		};
		$events = array(
			array( 'type' => 'downtime', 'kind' => 'ongoing', 'at' => $at( 0.01 ), 'ended_at' => null, 'duration_seconds' => 900, 'expires_at' => null ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'type' => 'ssl', 'kind' => 'problem', 'at' => $at( 1 ), 'ended_at' => null, 'duration_seconds' => null, 'expires_at' => $at( -10 ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'type' => 'ssl', 'kind' => 'renewed', 'at' => $at( 2 ), 'ended_at' => null, 'duration_seconds' => null, 'expires_at' => $at( -90 ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'type' => 'domain', 'kind' => 'problem', 'at' => $at( 3 ), 'ended_at' => null, 'duration_seconds' => null, 'expires_at' => $at( -20 ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'type' => 'domain', 'kind' => 'renewed', 'at' => $at( 4 ), 'ended_at' => null, 'duration_seconds' => null, 'expires_at' => $at( -365 ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'type' => 'dns', 'kind' => 'changed', 'at' => $at( 5 ), 'ended_at' => null, 'duration_seconds' => null, 'expires_at' => null ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'type' => 'malware', 'kind' => 'found', 'at' => $at( 6 ), 'ended_at' => null, 'duration_seconds' => null, 'expires_at' => null ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		);

		$html = $this->read_and_render( self::status_answer( array(), array( 'events' => $events ) ) );

		foreach ( array( 'Website is down', 'Ongoing, 15 minutes so far', 'SSL certificate warning', 'SSL certificate renewed', 'Domain expiration warning', 'Domain renewed', 'DNS records changed', 'Other event (see UptimeWire)', 'Full incident history in UptimeWire' ) as $words ) {
			$this->assertStringContainsString( $words, $html );
		}
		$this->assertSame( 1, substr_count( $html, 'Website is down' ), 'Only the outage is called down.' );
		$this->assertSame( 7, substr_count( $html, '<th scope="row" data-label="Event">' ) );
	}

	/**
	 * Data: alert answers and the words shown.
	 *
	 * @return array<string, array{array<string, mixed>, array<string, mixed>, string[], string[]}>
	 */
	public function alert_cases() {
		return array(
			'ready'            => array( array(), array( 'ready' => true, 'channels' => array( 'email' ), 'email_pending' => false ), array( 'Alerts Are Ready', '<li>Email</li>', 'Alert settings in UptimeWire' ), array( 'Configure Alerts in UptimeWire' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'no channels'      => array( array(), array( 'ready' => false, 'channels' => array(), 'email_pending' => false ), array( 'Alerts Can’t Reach You Right Now', 'No alert channel is set up', 'Configure Alerts in UptimeWire' ), array( 'Alerts Are Ready' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'not ready'        => array( array(), array( 'ready' => false, 'channels' => array( 'webhook' ), 'email_pending' => false ), array( 'None of the alert channels', '<li>Webhook</li>', 'Configure Alerts in UptimeWire' ), array( 'Alerts Are Ready' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'email pending'    => array( array(), array( 'ready' => true, 'channels' => array( 'email' ), 'email_pending' => true ), array( 'waiting for confirmation' ), array() ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'paused'           => array( array( 'state' => 'paused', 'paused' => true ), array( 'ready' => false, 'channels' => array( 'email' ), 'email_pending' => false ), array( 'Monitoring Is Paused', 'Your notification channels remain configured, but monitoring alerts are currently inactive.', 'Configured Notification Channels', '<li>Email</li>' ), array( 'Alerts Are Ready' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'every channel'    => array( array(), array( 'ready' => true, 'channels' => array( 'email', 'telegram', 'slack', 'discord', 'sms', 'webhook', 'teams', 'carrier-pigeon' ), 'email_pending' => false ), array( '<li>Slack</li>', '<li>Discord</li>', '<li>SMS</li>', '<li>Microsoft Teams</li>', '<li>Other</li>' ), array( 'carrier-pigeon' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			'unverified email' => array( array( 'state' => 'paused', 'awaiting_email_confirmation' => true ), array( 'ready' => false, 'channels' => array(), 'email_pending' => false ), array( 'Email Verification Required', 'Monitoring and notifications will start after the account email is verified.' ), array( 'Alerts Are Ready' ) ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		);
	}

	/**
	 * Alert readiness.
	 *
	 * @dataProvider alert_cases
	 * @param array<string, mixed> $site    Fields of data.site.
	 * @param array<string, mixed> $alerts  data.alerts.
	 * @param string[]             $present Words shown.
	 * @param string[]             $absent  Words not shown.
	 * @return void
	 */
	public function test_alerts( array $site, array $alerts, array $present, array $absent ) {
		$html = $this->read_and_render( self::status_answer( $site, array( 'alerts' => $alerts ) ) );

		foreach ( $present as $words ) {
			$this->assertStringContainsString( $words, $html );
		}
		foreach ( $absent as $words ) {
			$this->assertStringNotContainsString( $words, $html );
		}
	}

	/**
	 * 401: the connection ended, the credential and the kept status are gone.
	 *
	 * @return void
	 */
	public function test_401_ends_the_connection() {
		Fake_Api::queue( '/wp/v1/status', 200, self::status_answer() );
		Status::refresh( true );
		self::age_status( 3600 );
		Fake_Api::queue( '/wp/v1/status', 401, array( 'error' => array( 'code' => 'connection_ended', 'message' => 'x' ) ), array( 'www-authenticate' => 'Bearer' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertSame( Status::ENDED, Status::refresh() );

		$this->assertSame( State::RECONNECTION_REQUIRED, State::current() );
		$this->assertSame( '', Connection::token() );
		$this->assertNull( Status::get() );
		$this->assertSame( Status::NOT_CONNECTED, Status::refresh( true ) );
		$this->assertCount( 2, Fake_Api::calls( '/wp/v1/status' ), 'An ended connection is not asked again.' );
		$this->assertStringContainsString( 'Reconnection Required', self::render( array( Overview_Page::class, 'render' ) ) );
	}

	/**
	 * 429: Retry-After is respected, even by the Refresh button; the data stays.
	 *
	 * @return void
	 */
	public function test_429_waits() {
		Fake_Api::queue( '/wp/v1/status', 200, self::status_answer() );
		Status::refresh( true );
		self::age_status( 600 );
		Fake_Api::queue( '/wp/v1/status', 429, array( 'error' => array( 'code' => 'rate_limited', 'message' => 'x' ) ), array( 'retry-after' => '120' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertSame( Status::RATE_LIMITED, Status::refresh() );
		$kept = Status::get();
		$this->assertEqualsWithDelta( time() + 120, $kept['retry_at'], 2 );
		$this->assertSame( Status::WAIT, Status::refresh( true ) );
		$this->assertFalse( Status::is_due( $kept ) );
		$this->assertCount( 2, Fake_Api::calls( '/wp/v1/status' ) );
		$this->assertNotNull( Status::data() );
		$this->assertSame( State::CONNECTION_ESTABLISHED, State::current() );
	}

	/**
	 * 503: the last known status, never a fresh green check.
	 *
	 * @return void
	 */
	public function test_503_shows_last_known_status() {
		Fake_Api::queue( '/wp/v1/status', 200, self::status_answer() );
		Status::refresh( true );
		self::age_status( 600 );
		Fake_Api::queue( '/wp/v1/status', 503, array( 'error' => array( 'code' => 'temporarily_unavailable', 'message' => 'x' ) ), array( 'retry-after' => '60' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$this->assertSame( Status::UNAVAILABLE, Status::refresh() );
		$html = self::render( array( Overview_Page::class, 'render' ) );

		$this->assertSame( 'Last Known Status', self::primary_title( $html ) );
		$this->assertStringContainsString( 'Last reported: Your Website Is Online', $html );
		$this->assertStringContainsString( 'UptimeWire is temporarily unavailable.', $html );
		$this->assertStringContainsString( 'uptimewire-sections is-stale', $html );
		$this->assertStringNotContainsString( 'uptimewire-primary uptimewire-tone-ok', $html );
		$this->assertSame( State::CONNECTION_ESTABLISHED, State::current(), 'An unavailable API does not end the connection.' );
	}

	/**
	 * A short failure right after a good read keeps the status current, with a note.
	 *
	 * @return void
	 */
	public function test_fresh_data_with_a_failed_read() {
		Fake_Api::queue( '/wp/v1/status', 200, self::status_answer() );
		Status::refresh( true );
		self::age_status( 20 );
		Fake_Api::queue( '/wp/v1/status', 0 );

		$this->assertSame( Status::UNREACHABLE, Status::refresh( true ) );
		$html = self::render( array( Overview_Page::class, 'render' ) );

		$this->assertSame( 'Your Website Is Online', self::primary_title( $html ) );
		$this->assertStringContainsString( 'couldn’t reach UptimeWire. The status above is the latest one received.', $html );
	}

	/**
	 * Old data without any failure becomes "Last Known Status" after STALE_AFTER.
	 *
	 * @return void
	 */
	public function test_old_data_is_last_known() {
		Fake_Api::queue( '/wp/v1/status', 200, self::status_answer() );
		Status::refresh( true );
		self::age_status( Status::STALE_AFTER + 1 );

		$html = self::render( array( Overview_Page::class, 'render' ) );

		$this->assertSame( 'Last Known Status', self::primary_title( $html ) );
		$this->assertStringContainsString( 'hasn’t been updated for a while', $html );
		$this->assertStringContainsString( 'data-due="1"', $html );
		$this->assertStringContainsString( 'Current Alert Readiness Is Unknown', $html, 'Old data says nothing about alerts now (WP-4).' );
		$this->assertStringContainsString( 'Last known: Alerts Are Ready.', $html );
		$this->assertStringNotContainsString( 'Notifications are configured and monitoring is active.', $html );
	}

	/**
	 * No data and no answer: the API's problem is not the website's.
	 *
	 * @return void
	 */
	public function test_unavailable_without_data() {
		Fake_Api::queue( '/wp/v1/status', 0 );

		$this->assertSame( Status::UNREACHABLE, Status::refresh() );
		$html = self::render( array( Overview_Page::class, 'render' ) );

		$this->assertSame( 'Status Unavailable', self::primary_title( $html ) );
		$this->assertStringContainsString( 'not about your website', $html );
		$this->assertStringContainsString( 'The plugin tries again in', $html );
		$this->assertStringNotContainsString( 'Website Checks', $html );
	}

	/**
	 * Data: answers the plugin cannot use.
	 *
	 * @return array<string, array{int, array<string, mixed>|string}>
	 */
	public function invalid_answers() {
		return array(
			'not json'         => array( 200, '<html>Gateway</html>' ),
			'json, not status' => array( 200, array( 'hello' => 'world' ) ),
			'no site url'      => array( 200, array( 'data' => array( 'site' => array( 'state' => 'up' ) ) ) ),
			'500'              => array( 500, array( 'error' => array( 'code' => 'x' ) ) ),
			'404'              => array( 404, '' ),
		);
	}

	/**
	 * An unusable answer keeps the previous data and is never shown as healthy.
	 *
	 * @dataProvider invalid_answers
	 * @param int                         $code HTTP status.
	 * @param array<string, mixed>|string $body Body.
	 * @return void
	 */
	public function test_invalid_answer( $code, $body ) {
		Fake_Api::queue( '/wp/v1/status', $code, $body );
		$this->assertSame( Status::INVALID, Status::refresh( true ) );
		$this->assertSame( 'Status Unavailable', self::primary_title( self::render( array( Overview_Page::class, 'render' ) ) ) );

		self::age_status( 600 );
		Fake_Api::queue( '/wp/v1/status', 200, self::status_answer() );
		$this->assertSame( Status::UPDATED, Status::refresh( true ) );
		self::age_status( 600 );
		Fake_Api::queue( '/wp/v1/status', $code, $body );
		$this->assertSame( Status::INVALID, Status::refresh( true ) );
		$this->assertSame( 'https://www.customer-site.com', Status::data()['site']['url'], 'The previous data stays.' );
		$this->assertSame( State::CONNECTION_ESTABLISHED, State::current() );
	}

	/**
	 * Extra fields and sections from a newer UptimeWire are ignored.
	 *
	 * @return void
	 */
	public function test_extra_fields() {
		$answer                           = self::status_answer( array( 'new_field' => '<b>x</b>' ) );
		$answer['data']['future_section'] = array( 'a' => 'b' );
		$answer['meta']['request_id']     = 'abc';

		$html = $this->read_and_render( $answer );

		$this->assertSame( 'Your Website Is Online', self::primary_title( $html ) );
		$this->assertStringNotContainsString( '<b>x</b>', $html );
	}

	/**
	 * Another website's status is not shown and not kept; a safe way back is offered.
	 *
	 * @return void
	 */
	public function test_site_mismatch() {
		Fake_Api::queue( '/wp/v1/status', 200, self::status_answer() );
		Status::refresh( true );
		self::age_status( 600 );
		Fake_Api::queue( '/wp/v1/status', 200, self::status_answer( array( 'url' => 'https://shop.other-site.com' ) ) );

		$this->assertSame( Status::MISMATCH, Status::refresh() );

		$this->assertNull( Status::get(), 'Nothing of the other website is kept.' );
		$this->assertSame( State::CONNECTION_ERROR, State::current() );
		$html = self::render( array( Overview_Page::class, 'render' ) );
		$this->assertStringContainsString( 'Website Addresses Don’t Match', $html );
		$this->assertStringContainsString( 'Check Again', $html );
		$this->assertStringContainsString( 'value="uptimewire_refresh"', $html );
		$this->assertStringNotContainsString( '99.97', $html );
		$this->assertStringNotContainsString( 'uptimewire-dashboard', $html );

		// The address is corrected in UptimeWire: Check Again brings the dashboard back.
		Fake_Api::queue( '/wp/v1/status', 200, self::status_answer() );
		update_option( Status::OPTION, array( 'attempted_at' => time() - 60 ), false );
		$this->assertSame( Status::UPDATED, Status::refresh( true ) );
		$this->assertSame( State::CONNECTION_ESTABLISHED, State::current() );
	}

	/**
	 * Allowed differences of the address still match; www. and other paths do not.
	 *
	 * @return void
	 */
	public function test_equivalent_addresses() {
		foreach ( array( 'http://www.customer-site.com/', 'https://WWW.Customer-Site.com', 'https://www.customer-site.com:443', 'https://www.customer-site.com./' ) as $url ) {
			delete_option( Status::OPTION );
			self::connect_site();
			Fake_Api::queue( '/wp/v1/status', 200, self::status_answer( array( 'url' => $url ) ) );
			$this->assertSame( Status::UPDATED, Status::refresh( true ), $url );
		}

		foreach ( array( 'https://customer-site.com', 'https://www.customer-site.com/shop', 'https://www.customer-site.com.evil.example' ) as $url ) {
			delete_option( Status::OPTION );
			self::connect_site();
			Fake_Api::queue( '/wp/v1/status', 200, self::status_answer( array( 'url' => $url ) ) );
			$this->assertSame( Status::MISMATCH, Status::refresh( true ), $url );
		}
	}

	/**
	 * The cache: one read per period, Refresh merged when clicked twice, other tabs wait.
	 *
	 * @return void
	 */
	public function test_cache_and_lock() {
		Fake_Api::queue( '/wp/v1/status', 200, self::status_answer() );
		$this->assertSame( Status::UPDATED, Status::refresh() );
		$this->assertSame( Status::FRESH, Status::refresh() );
		$this->assertSame( Status::FRESH, Status::refresh( true ), 'A second click within seconds reuses the read.' );
		$this->assertCount( 1, Fake_Api::calls( '/wp/v1/status' ) );
		$this->assertSame( 300, Status::ttl( Status::data() ) );

		self::age_status( 299 );
		$this->assertFalse( Status::is_due( Status::get() ) );
		self::age_status( 1 );
		$this->assertTrue( Status::is_due( Status::get() ) );

		\UptimeWire\Lock::acquire( 'status', 15 );
		$this->assertSame( Status::BUSY, Status::refresh() );
		\UptimeWire\Lock::release( 'status' );
		$this->assertCount( 1, Fake_Api::calls( '/wp/v1/status' ), 'A held lock means no second request.' );

		Fake_Api::queue( '/wp/v1/status', 200, self::status_answer( array( 'interval_minutes' => 1 ) ) );
		$this->assertSame( Status::UPDATED, Status::refresh() );
		$this->assertSame( 60, Status::ttl( Status::data() ), 'A site checked every minute is read every minute.' );
	}

	/**
	 * Rendering never waits for UptimeWire.
	 *
	 * @return void
	 */
	public function test_render_makes_no_request() {
		$html = self::render( array( Overview_Page::class, 'render' ) );

		$this->assertSame( array(), Fake_Api::calls(), 'No request while the screen is rendered.' );
		$this->assertSame( 'Loading Your Website Status…', self::primary_title( $html ) );
		$this->assertStringContainsString( 'data-due="1"', $html );
		$this->assertStringContainsString( 'ACC-00001000', $html, 'The account number from connecting is shown before the first read.' );

		Fake_Api::queue( '/wp/v1/status', 200, self::status_answer() );
		Status::refresh();
		$html = self::render( array( Overview_Page::class, 'render' ) );
		$this->assertStringContainsString( 'data-due="0"', $html );
		$this->assertCount( 1, Fake_Api::calls() );
	}

	/**
	 * Hostile text from UptimeWire (or from the checked server) is only text; unsafe links are dropped.
	 *
	 * @return void
	 */
	public function test_hostile_answer() {
		$evil                            = '<script>alert(1)</script><img src=x onerror=alert(2)>"\'';
		$answer                          = self::status_answer(
			array(
				'url'           => 'https://www.customer-site.com',
				'state'         => 'down',
				'down_since'    => gmdate( 'Y-m-d\TH:i:s+00:00', time() - 60 ),
				'problem'       => $evil,
				'last_location' => $evil,
			)
		);
		$answer['data']['checks']['ssl'] = array( 'state' => 'problem', 'checked_at' => null, 'expires_at' => null, 'days_left' => null, 'warning' => 'check_failed', 'error' => $evil ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$answer['data']['connection']['account_label'] = 'ACC-1<script>alert(3)</script>';
		$answer['data']['links']                       = array(
			'site'   => 'javascript:alert(4)',
			'alerts' => 'https://evil.example/alerts',
			'plans'  => 'https://app.uptimewire.com.evil.example/',
		);
		$answer['data']['alerts']['channels']          = array( '<b>email</b>', 'owner@customer-site.com' );

		$html = $this->read_and_render( $answer );

		$this->assertStringNotContainsString( '<script>alert', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringNotContainsString( 'evil.example', $html );
		$this->assertStringNotContainsString( 'owner@customer-site.com', $html );
		$this->assertStringNotContainsString( 'ACC-1', $html, 'A malformed account number is not shown.' );
		$this->assertStringContainsString( 'ACC-00001000', $html, 'The number from connecting is shown instead.' );
		$this->assertStringContainsString( 'href="' . Overview_Page::DASHBOARD . '"', str_replace( '&#038;', '&', $html ) );
	}

	/**
	 * The credential never reaches a screen, the script data, the REST answer or the kept status.
	 *
	 * @return void
	 */
	public function test_no_credential_leaks() {
		Fake_Api::queue( '/wp/v1/status', 200, self::status_answer() );
		Status::refresh( true );
		self::age_status( 600 );
		Fake_Api::queue( '/wp/v1/status', 200, self::status_answer() );

		$html     = self::render( array( Overview_Page::class, 'render' ) );
		$response = rest_do_request( new \WP_REST_Request( 'POST', '/' . Rest::NAMESPACE_V1 . '/status-refresh' ) );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] );
		$this->assertStringNotContainsString( self::TOKEN, $html );
		$this->assertStringNotContainsString( self::TOKEN, (string) wp_json_encode( $response->get_data() ) );
		$this->assertStringNotContainsString( self::TOKEN, (string) wp_json_encode( get_option( Status::OPTION ) ) );
		$this->assertStringNotContainsString( 'uwp_', (string) wp_json_encode( $response->get_data() ) );
	}

	/**
	 * The REST route: the dashboard and an announcement; a redirect when the connection ended.
	 *
	 * @return void
	 */
	public function test_rest_refresh() {
		Fake_Api::queue( '/wp/v1/status', 200, self::status_answer() );
		$request = new \WP_REST_Request( 'POST', '/' . Rest::NAMESPACE_V1 . '/status-refresh' );
		$request->set_param( 'force', true );

		$data = rest_do_request( $request )->get_data();

		$this->assertSame( Status::UPDATED, $data['result'] );
		$this->assertSame( 'Your Website Is Online', $data['announce'] );
		$this->assertStringContainsString( 'Your Website Is Online', $data['html'] );
		$this->assertSame( 300, $data['ttl'] );
		$this->assertFalse( $data['due'] );

		self::age_status( 600 );
		Fake_Api::queue( '/wp/v1/status', 401, array( 'error' => array( 'code' => 'connection_ended', 'message' => 'x' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$data = rest_do_request( $request )->get_data();
		$this->assertSame( Status::ENDED, $data['result'] );
		$this->assertArrayNotHasKey( 'html', $data );
		$this->assertStringContainsString( 'page=uptimewire', $data['redirect'] );
	}

	/**
	 * Only users with the plugin's capability may refresh.
	 *
	 * @return void
	 */
	public function test_rest_refresh_needs_the_capability() {
		$editor = get_user_by( 'login', 'uw-test-editor' );
		$id     = $editor ? $editor->ID : wp_insert_user(
			array(
				'user_login' => 'uw-test-editor',
				'user_pass'  => wp_generate_password(),
				'role'       => 'editor',
			)
		);
		wp_set_current_user( $id );

		$response = rest_do_request( new \WP_REST_Request( 'POST', '/' . Rest::NAMESPACE_V1 . '/status-refresh' ) );
		$this->assertSame( 403, $response->get_status() );

		wp_set_current_user( 0 );
		$response = rest_do_request( new \WP_REST_Request( 'POST', '/' . Rest::NAMESPACE_V1 . '/status-refresh' ) );
		$this->assertSame( 401, $response->get_status() );
		$this->assertSame( array(), Fake_Api::calls() );

		wp_set_current_user( 1 );
		$response = rest_do_request( new \WP_REST_Request( 'GET', '/' . Rest::NAMESPACE_V1 . '/status-refresh' ) );
		$this->assertSame( 404, $response->get_status(), 'Only POST.' );
	}

	/**
	 * The announcement says the same as the title.
	 *
	 * @return void
	 */
	public function test_announcement() {
		$this->assertSame( 'Loading Your Website Status…', Dashboard::announcement() );
		Fake_Api::queue( '/wp/v1/status', 200, self::status_answer( array( 'state' => 'checking' ) ) );
		Status::refresh();
		$this->assertSame( 'We’re Double-Checking Your Website', Dashboard::announcement() );
	}

	/**
	 * A new connection does not show the data of the one before.
	 *
	 * @return void
	 */
	public function test_new_connection_forgets_old_status() {
		Fake_Api::queue( '/wp/v1/status', 200, self::status_answer() );
		Status::refresh();
		$this->assertNotNull( Status::get() );

		self::connect_site();

		$this->assertNull( Status::get() );
	}
}
