<?php
/**
 * WP-4: the Settings screen.
 *
 * @package UptimeWire
 */

namespace UptimeWire\Tests;

use UptimeWire\Admin\Settings_Page;

/**
 * Renders Settings from the connection and the kept status.
 */
final class SettingsTest extends Test_Case {

	/**
	 * The screen.
	 *
	 * @return string
	 */
	private static function screen() {
		return str_replace( '&#038;', '&', self::render( array( Settings_Page::class, 'render' ) ) );
	}

	/**
	 * Connected: the website, the connection facts, monitoring, alerts, plans and help.
	 *
	 * @return void
	 */
	public function test_connected() {
		self::connect_site();
		self::read_status( self::status_answer() );
		Fake_Api::reset();
		$html = self::screen();

		foreach ( array(
			'<h2>Connection</h2>',
			'Connection Established',
			'https://www.customer-site.com',
			'<span class="uptimewire-account">ACC-00001000</span>',
			'Last status received',
			'<th scope="row">Connected</th>',
			'by admin',
			'Kept in the WordPress database. It is never shown.',
			'href="https://app.uptimewire.com/monitors/0193a3b2/view"',
			'Open UptimeWire',
			'<h2>Monitoring</h2>',
			'Your Website Is Online',
			'Every 5 minutes',
			'<strong>SSL certificate:</strong> Included',
			'<strong>Domain expiration:</strong> Included',
			'<strong>DNS monitoring:</strong> Included',
			'Change monitoring settings in UptimeWire',
			'<h2>Alerts</h2>',
			'Alerts Are Ready',
			'Notifications are configured and monitoring is active.',
			'Configured Notification Channels',
			'<li>Email</li>',
			'<li>Telegram</li>',
			'href="https://app.uptimewire.com/user/profile/notifications"',
			'<h2>Plans</h2>',
			'href="https://app.uptimewire.com/subscription-plans"',
			'View Plans &amp; Features',
			'<h2>Troubleshooting</h2>',
			'https://uptimewire.com/resources/guides/allow-uptimewire-checks-through-firewall/',
			'https://app.uptimewire.com/ips.txt',
			'site-health.php?tab=debug',
			'https://uptimewire.com/help/',
		) as $words ) {
			$this->assertStringContainsString( $words, $html, $words );
		}
		$this->assertSame( 1, substr_count( $html, 'View Plans &amp; Features' ), 'One unobtrusive plans link.' );
		$this->assertSame( 0, substr_count( $html, 'Upgrade' ) );
	}

	/**
	 * Checks the plan doesn't include, and an http:// website.
	 *
	 * @return void
	 */
	public function test_checks_of_the_plan() {
		self::connect_site();
		$answer                             = self::status_answer( array( 'interval_minutes' => 1 ) );
		$answer['data']['checks']['ssl']    = array( 'state' => 'not_applicable', 'checked_at' => null, 'expires_at' => null, 'days_left' => null, 'warning' => null, 'error' => null ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$answer['data']['checks']['domain'] = array( 'state' => 'not_on_plan', 'checked_at' => null, 'expires_at' => null, 'days_left' => null, 'expiry_known' => false, 'warning' => null, 'error' => null ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$answer['data']['checks']['dns']    = array( 'state' => 'unknown', 'checked_at' => null, 'changed' => false, 'open_changes' => 0, 'last_change_at' => null ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		self::read_status( $answer );
		$html = self::screen();

		$this->assertStringContainsString( 'Every 1 minute', $html );
		$this->assertStringContainsString( '<strong>SSL certificate:</strong> Not applicable: the website uses http://', $html );
		$this->assertStringContainsString( '<strong>Domain expiration:</strong> Not in your plan', $html );
		$this->assertStringContainsString( '<strong>DNS monitoring:</strong> Included, not checked yet', $html );
	}

	/**
	 * Notification readiness: paused, starting, email not verified, no channel.
	 *
	 * @return void
	 */
	public function test_notification_readiness() {
		self::connect_site();
		$cases = array(
			array( array( 'state' => 'paused', 'paused' => true ), array( 'ready' => false, 'channels' => array( 'email', 'slack' ), 'email_pending' => false ), 'Monitoring Is Paused', 'Your notification channels remain configured, but monitoring alerts are currently inactive.' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( array( 'state' => 'waiting', 'last_checked_at' => null ), array( 'ready' => true, 'channels' => array( 'email' ), 'email_pending' => false ), 'Monitoring Is Starting', 'Notification channels are configured. Monitoring has not completed its first check yet.' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( array( 'state' => 'paused', 'awaiting_email_confirmation' => true ), array( 'ready' => false, 'channels' => array( 'email' ), 'email_pending' => false ), 'Email Verification Required', 'Monitoring and notifications will start after the account email is verified.' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( array(), array( 'ready' => false, 'channels' => array(), 'email_pending' => false ), 'Alerts Can’t Reach You Right Now', 'No alert channel is set up for this website.' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		);

		foreach ( $cases as $case ) {
			\UptimeWire\Status::forget();
			self::read_status( self::status_answer( $case[0], array( 'alerts' => $case[1] ) ) );
			$html = self::screen();

			$this->assertStringContainsString( $case[2], $html );
			$this->assertStringContainsString( $case[3], $html );
			$this->assertStringNotContainsString( 'Alerts Are Ready', $html, $case[2] );
		}
	}

	/**
	 * Nothing of the account but its number: no emails, channel destinations or billing.
	 *
	 * @return void
	 */
	public function test_restricted_account_info() {
		self::connect_site();
		$answer                                = self::status_answer();
		$answer['data']['alerts']['channels']  = array( 'email', 'owner@customer-site.com', 'https://hooks.slack.com/services/T0/B0/x' );
		$answer['data']['connection']['email'] = 'owner@customer-site.com';
		$answer['data']['billing']             = array(
			'plan' => 'Business',
			'card' => '4242',
		);
		self::read_status( $answer );
		$html = self::screen();

		foreach ( array( 'owner@customer-site.com', 'hooks.slack.com', 'Business', '4242', self::TOKEN, 'uwp_' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $html, $secret );
		}
		$this->assertStringContainsString( '<li>Other</li>', $html );
	}

	/**
	 * Before connecting: one action, no monitoring sections, the public plans page.
	 *
	 * @return void
	 */
	public function test_not_connected() {
		$html = self::screen();

		$this->assertStringContainsString( 'Not Connected', $html );
		$this->assertStringContainsString( 'Set Up Monitoring', $html );
		$this->assertStringNotContainsString( '<h2>Monitoring</h2>', $html );
		$this->assertStringNotContainsString( '<h2>Alerts</h2>', $html );
		$this->assertStringContainsString( 'href="https://uptimewire.com/pricing/"', $html );
		$this->assertSame( array(), Fake_Api::calls() );
	}

	/**
	 * An ended connection offers Reconnect.
	 *
	 * @return void
	 */
	public function test_reconnect() {
		self::connect_site();
		self::read_status( array( 'error' => 'connection_ended' ), 401 );
		$html = self::screen();

		$this->assertStringContainsString( 'Reconnection Required', $html );
		$this->assertStringContainsString( '>Reconnect</a>', $html );
		$this->assertStringNotContainsString( '<h2>Monitoring</h2>', $html );
	}

	/**
	 * Unsafe links from UptimeWire are replaced by fixed UptimeWire addresses.
	 *
	 * @return void
	 */
	public function test_unsafe_links() {
		self::connect_site();
		self::read_status(
			self::status_answer(
				array(),
				array(
					'links' => array(
						'site'   => 'javascript:alert(1)',
						'alerts' => 'https://app.uptimewire.com.evil.example/alerts',
						'plans'  => 'http://app.uptimewire.com/plans',
					),
				)
			)
		);
		$html = self::screen();

		$this->assertStringNotContainsString( 'javascript:', $html );
		$this->assertStringNotContainsString( 'evil.example', $html );
		$this->assertStringNotContainsString( 'http://app.uptimewire.com', $html );
		$this->assertStringContainsString( 'href="https://uptimewire.com/pricing/"', $html );
		$this->assertStringContainsString( 'href="https://app.uptimewire.com/?src=wp"', $html );
	}

	/**
	 * Old data is labelled with its date.
	 *
	 * @return void
	 */
	public function test_stale() {
		self::connect_site();
		self::read_status( self::status_answer() );
		self::make_status_older( 2 * 3600 );

		$this->assertStringContainsString( 'Current monitoring status is unavailable.', self::screen() );
	}

	/**
	 * Without Site Health access, no link to it.
	 *
	 * @return void
	 */
	public function test_site_health_link_needs_access() {
		$filter = static function ( $caps, $cap ) {
			return 'view_site_health_checks' === $cap ? array( 'do_not_allow' ) : $caps;
		};
		add_filter( 'map_meta_cap', $filter, 10, 2 );
		$html = self::screen();
		remove_filter( 'map_meta_cap', $filter, 10 );

		$this->assertStringNotContainsString( 'site-health.php', $html );
	}
}
