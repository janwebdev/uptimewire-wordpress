<?php
/**
 * BE-5: the email domain and the scheduled job on every screen of the plugin.
 *
 * @package UptimeWire
 */

namespace UptimeWire\Tests;

use UptimeWire\Admin\Overview_Page;
use UptimeWire\Admin\Settings_Page;
use UptimeWire\Admin\Site_Health;
use UptimeWire\Admin\Widget;
use UptimeWire\Status;

/**
 * Overview cards, the widget line, Site Health, Settings and the events of the two checks.
 */
final class MailCronTest extends Test_Case {

	/** Where the job is connected in UptimeWire. */
	const JOB_LINK = 'https://app.uptimewire.com/monitors/0193a3b2/view#scheduled-job';

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
	 * A status answer with both checks of BE-5.
	 *
	 * @param array<string, mixed> $mail Fields of checks.mail to change.
	 * @param array<string, mixed> $job  Fields of checks.scheduled_job to change.
	 * @return array<string, mixed>
	 */
	private static function answer( array $mail = array(), array $job = array() ) {
		$answer = self::status_answer();

		$answer['data']['checks']['mail']          = $mail + array(
			'state'      => 'ok',
			'items'      => array(
				array( 'key' => 'mx', 'state' => 'ok', 'reason' => 'mx.ok' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				array( 'key' => 'spf', 'state' => 'ok', 'reason' => 'spf.ok' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				array( 'key' => 'dmarc', 'state' => 'advice', 'reason' => 'dmarc.missing' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				array( 'key' => 'dkim', 'state' => 'unknown', 'reason' => 'dkim.not_found' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			),
			'checked_at' => gmdate( 'Y-m-d\TH:i:s+00:00', time() - 3600 ),
		);
		$answer['data']['checks']['scheduled_job'] = $job + array(
			'state'            => 'on_time',
			'last_call_at'     => gmdate( 'Y-m-d\TH:i:s+00:00', time() - 7200 ),
			'interval_seconds' => 86400,
			'grace_seconds'    => 3600,
		);
		$answer['data']['links']['scheduled_job']  = self::JOB_LINK;

		return $answer;
	}

	/**
	 * Reads an answer as a new one: several reads in one test are not merged by the gap between manual reads.
	 *
	 * @param array<string, mixed> $body Answer.
	 * @return string
	 */
	private static function read( array $body ) {
		Status::forget();

		return self::read_status( $body );
	}

	/**
	 * A broken email domain: SPF needs too many lookups.
	 *
	 * @return array<string, mixed>
	 */
	private static function broken_mail() {
		return array(
			'state' => 'problem',
			'items' => array(
				array( 'key' => 'mx', 'state' => 'ok', 'reason' => 'mx.ok' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				array( 'key' => 'spf', 'state' => 'problem', 'reason' => 'spf.too_many' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				array( 'key' => 'dmarc', 'state' => 'advice', 'reason' => 'dmarc.missing' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				array( 'key' => 'dkim', 'state' => 'unknown', 'reason' => 'dkim.not_found' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			),
		);
	}

	/**
	 * The Overview screen, with the typographic apostrophe made plain for matching.
	 *
	 * @return string
	 */
	private static function overview() {
		return str_replace( array( '&#8217;', '’' ), "'", self::render( array( Overview_Page::class, 'render' ) ) );
	}

	/**
	 * One card of the Overview, by its title.
	 *
	 * @param string $html  Screen.
	 * @param string $title Card title.
	 * @return string
	 */
	private static function card( $html, $title ) {
		return preg_match( '#<li class="uptimewire-card-item uptimewire-tone-[a-z]+">\s*<h3 class="uptimewire-card-title">[^<]*<span[^>]*></span> ' . preg_quote( $title, '#' ) . '</h3>.*?</li>#s', $html, $m ) ? $m[0] : '';
	}

	/**
	 * Site Health results of the plugin.
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
	 * Renders every screen and fails on any PHP notice or warning.
	 *
	 * @return string All screens.
	 */
	private function every_screen() {
		$problems = array();
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler -- Test: any notice of the plugin is a failure.
		set_error_handler(
			static function ( $errno, $errstr, $file ) use ( &$problems ) {
				$problems[] = $errstr . ' in ' . $file;

				return true;
			}
		);

		try {
			$html  = self::overview();
			$html .= self::render( array( Widget::class, 'render' ) );
			$html .= self::render( array( Settings_Page::class, 'render' ) );
			$html .= wp_json_encode( self::results() );
			$html .= wp_json_encode( self::info_fields() );
		} finally {
			restore_error_handler();
		}

		$this->assertSame( array(), $problems );

		return $html;
	}

	/**
	 * A server before BE-5: no new card, line, test or row, and no error.
	 *
	 * @return void
	 */
	public function test_server_without_be5() {
		self::read( self::status_answer() );
		$html = $this->every_screen();

		$this->assertStringNotContainsString( 'Email Domain', $html );
		$this->assertStringNotContainsString( 'Scheduled Job', $html );
		$this->assertStringNotContainsString( 'Email domain', $html );
		$this->assertStringNotContainsString( 'Scheduled job', $html );
		$this->assertArrayNotHasKey( 'uptimewire_mail', self::results() );
		$this->assertArrayNotHasKey( 'uptimewire_scheduled_job', self::results() );
		$this->assertArrayNotHasKey( 'mail', self::info_fields() );
	}

	/**
	 * A status kept by plugin 0.5.0 has neither key at all: still no error after the update.
	 *
	 * @return void
	 */
	public function test_status_kept_by_the_previous_version() {
		self::read( self::status_answer() );
		$kept = get_option( Status::OPTION );
		unset( $kept['data']['mail'], $kept['data']['scheduled_job'], $kept['data']['links']['scheduled_job'] );
		update_option( Status::OPTION, $kept, false );

		$html = $this->every_screen();

		$this->assertStringContainsString( 'Your Website Is Online', $html );
		$this->assertStringNotContainsString( 'Scheduled Job', $html );
	}

	/**
	 * Email domain: a problem, all fine, no email, not looked at yet.
	 *
	 * @return void
	 */
	public function test_mail_card() {
		self::read( self::answer( self::broken_mail() ) );
		$html = self::overview();
		$card = self::card( $html, 'Email Domain' );

		$this->assertStringContainsString( 'uptimewire-tone-warn', $card );
		$this->assertStringContainsString( 'Needs attention', $card );
		$this->assertStringContainsString( 'Mail servers (MX): found', $card );
		$this->assertStringContainsString( 'Sender list (SPF): too many lookups', $card );
		$this->assertStringContainsString( 'Spoofing protection (DMARC): not set up — tip:', $card, 'Missing DMARC is advice' );
		$this->assertStringContainsString( 'Email signature (DKIM): could not be checked', $card );
		$this->assertStringNotContainsString( 'broken', strtolower( $card ), 'DKIM not found is never broken' );
		$this->assertStringContainsString( 'Needs attention: email domain.', $html );

		self::read( self::answer() );
		$html = self::overview();
		$card = self::card( $html, 'Email Domain' );
		$this->assertStringContainsString( 'uptimewire-tone-ok', $card );
		$this->assertStringContainsString( 'Set up', $card );
		$this->assertStringContainsString( 'tip:', $card, 'Advice is shown on the card, without alarm' );
		$this->assertStringNotContainsString( 'Needs attention: email domain', $html );

		self::read(
			self::answer(
				array(
					'state' => 'none',
					'items' => array(
						array( 'key' => 'mx', 'state' => 'none', 'reason' => 'mx.none' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
						array( 'key' => 'spf', 'state' => 'none', 'reason' => 'spf.none' ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					),
				)
			)
		);
		$card = self::card( self::overview(), 'Email Domain' );
		$this->assertStringContainsString( 'uptimewire-tone-off', $card, 'Calm, not an alarm' );
		$this->assertStringContainsString( 'This domain does not receive email.', $card );
		$this->assertStringNotContainsString( 'Sender list', $card );

		self::read(
			self::answer(
				array(
					'state'      => 'unknown',
					'items'      => array(),
					'checked_at' => null,
				)
			)
		);
		$card = self::card( self::overview(), 'Email Domain' );
		$this->assertStringContainsString( 'uptimewire-tone-unknown', $card );
		$this->assertStringContainsString( 'No result to show yet.', $card );
	}

	/**
	 * Scheduled job: on time, late, failed, not connected, unknown.
	 *
	 * @return void
	 */
	public function test_job_card() {
		$cases = array(
			'on_time'       => array( 'ok', 'On time' ),
			'late'          => array( 'warn', 'Late' ),
			'failed'        => array( 'bad', 'Failed' ),
			'not_connected' => array( 'off', 'Not connected' ),
			'sleeping'      => array( 'unknown', 'Unknown' ),
		);

		foreach ( $cases as $state => $look ) {
			$job = array( 'state' => $state );
			if ( 'not_connected' === $state ) {
				$job['last_call_at'] = null;
			}
			self::read( self::answer( array(), $job ) );
			$html = self::overview();
			$card = self::card( $html, 'Scheduled Job' );

			$this->assertStringContainsString( 'uptimewire-tone-' . $look[0], $card, $state );
			$this->assertStringContainsString( '</span> ' . $look[1] . '</p>', $card, $state );

			if ( in_array( $state, array( 'on_time', 'late', 'failed' ), true ) ) {
				$this->assertStringContainsString( 'Last call 2 hours ago', $card, $state );
				$this->assertStringContainsString( 'Expected every 1 day, up to 1 hour late', $card, $state );
			}
			$this->assertSame( in_array( $state, array( 'late', 'failed' ), true ), false !== strpos( $html, 'Needs attention: scheduled job.' ), $state );
		}

		self::read(
			self::answer(
				array(),
				array(
					'state'        => 'not_connected',
					'last_call_at' => null,
				)
			)
		);
		$card = self::card( self::overview(), 'Scheduled Job' );
		$this->assertStringContainsString( 'href="' . self::JOB_LINK . '"', $card );
		$this->assertStringContainsString( 'Connect in UptimeWire', $card );
		$this->assertStringNotContainsString( '/hb/', $card );
	}

	/**
	 * The events of both checks have their own words; the job's are never downtime.
	 *
	 * @return void
	 */
	public function test_events() {
		$answer                   = self::answer();
		$answer['data']['events'] = array(
			array( 'type' => 'scheduled_job', 'kind' => 'failed', 'at' => gmdate( 'Y-m-d\TH:i:s+00:00', time() - 3600 ), 'ended_at' => null, 'duration_seconds' => null, 'expires_at' => null ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'type' => 'scheduled_job', 'kind' => 'recovered', 'at' => gmdate( 'Y-m-d\TH:i:s+00:00', time() - 7200 ), 'ended_at' => null, 'duration_seconds' => null, 'expires_at' => null ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'type' => 'scheduled_job', 'kind' => 'missed', 'at' => gmdate( 'Y-m-d\TH:i:s+00:00', time() - 9000 ), 'ended_at' => null, 'duration_seconds' => null, 'expires_at' => null ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'type' => 'mail', 'kind' => 'problem', 'at' => gmdate( 'Y-m-d\TH:i:s+00:00', time() - 86400 ), 'ended_at' => null, 'duration_seconds' => null, 'expires_at' => null ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		);
		self::read( $answer );
		$html = self::overview();

		$this->assertStringContainsString( 'Scheduled job failed', $html );
		$this->assertStringContainsString( 'Scheduled job back on time', $html );
		$this->assertStringContainsString( 'Scheduled job missed', $html );
		$this->assertStringContainsString( 'Email domain problem', $html );
		$this->assertStringNotContainsString( 'Other event (see UptimeWire)', $html );
		$this->assertStringNotContainsString( 'Lasted', $html );
	}

	/**
	 * The widget gets one line only when there is something to do.
	 *
	 * @return void
	 */
	public function test_widget_line() {
		self::read( self::answer() );
		$this->assertStringNotContainsString( 'Needs attention', self::render( array( Widget::class, 'render' ) ) );

		self::read( self::answer( array(), array( 'state' => 'on_time' ) + array( 'last_call_at' => null ) ) );
		$this->assertStringNotContainsString( 'Needs attention', self::render( array( Widget::class, 'render' ) ) );

		self::read( self::answer( self::broken_mail(), array( 'state' => 'late' ) ) );
		$html = self::render( array( Widget::class, 'render' ) );
		$this->assertSame( 1, substr_count( $html, '<dt>Needs attention</dt>' ) );
		$this->assertStringContainsString( 'email domain, scheduled job', $html );

		self::read( self::answer( array(), array( 'state' => 'failed' ) ) );
		$this->assertStringContainsString( 'scheduled job', self::render( array( Widget::class, 'render' ) ) );
	}

	/**
	 * Site Health: problems are recommended, never critical; good results only from current data; tips in Info.
	 *
	 * @return void
	 */
	public function test_site_health() {
		self::read( self::answer( self::broken_mail(), array( 'state' => 'late' ) ) );
		$results = self::results();

		$this->assertSame( 'recommended', $results['uptimewire_mail']['status'] );
		$this->assertStringContainsString( 'Sender list (SPF): too many lookups', $results['uptimewire_mail']['description'] );
		$this->assertStringNotContainsString( 'DMARC', $results['uptimewire_mail']['description'], 'Tips stay in Info' );
		$this->assertSame( 'recommended', $results['uptimewire_scheduled_job']['status'] );
		$this->assertStringContainsString( self::JOB_LINK, $results['uptimewire_scheduled_job']['actions'] );
		$this->assertStringContainsString( 'DMARC', self::info_fields()['mail']['value'], 'Tips are in Info' );
		$this->assertSame( 'late', explode( ';', self::info_fields()['scheduled_job']['debug'] )[0] );

		self::read( self::answer( array(), array( 'state' => 'failed' ) ) );
		$results = self::results();
		$this->assertSame( 'good', $results['uptimewire_mail']['status'] );
		$this->assertSame( 'recommended', $results['uptimewire_scheduled_job']['status'] );

		self::read( self::answer() );
		$this->assertSame( 'good', self::results()['uptimewire_scheduled_job']['status'] );

		foreach ( array( 'critical' ) as $never ) {
			foreach ( array( 'problem', 'ok' ) as $mail ) {
				foreach ( array( 'on_time', 'late', 'failed' ) as $job ) {
					self::read( self::answer( 'problem' === $mail ? self::broken_mail() : array(), array( 'state' => $job ) ) );
					$results = self::results();
					$this->assertNotSame( $never, $results['uptimewire_mail']['status'] );
					$this->assertNotSame( $never, $results['uptimewire_scheduled_job']['status'] );
				}
			}
		}

		self::read(
			self::answer(
				array(
					'state' => 'none',
					'items' => array(),
				),
				array(
					'state'        => 'not_connected',
					'last_call_at' => null,
				)
			)
		);
		$results = self::results();
		$this->assertArrayNotHasKey( 'uptimewire_mail', $results, 'No email on the domain: nothing to test' );
		$this->assertArrayNotHasKey( 'uptimewire_scheduled_job', $results, 'Not connected: said in Info only' );
		$this->assertSame( 'not_connected', explode( ';', self::info_fields()['scheduled_job']['debug'] )[0] );

		// Out-of-date data passes nothing.
		self::read( self::answer() );
		self::make_status_older( 3 * 3600 );
		$results = self::results();
		$this->assertArrayNotHasKey( 'uptimewire_mail', $results );
		$this->assertArrayNotHasKey( 'uptimewire_scheduled_job', $results );
	}

	/**
	 * Settings lists both checks.
	 *
	 * @return void
	 */
	public function test_settings_rows() {
		self::read(
			self::answer(
				array(
					'state' => 'unknown',
					'items' => array(),
				),
				array(
					'state'        => 'not_connected',
					'last_call_at' => null,
				)
			)
		);
		$html = self::render( array( Settings_Page::class, 'render' ) );

		$this->assertStringContainsString( '<strong>Email domain:</strong> Included, not checked yet', $html );
		$this->assertStringContainsString( '<strong>Scheduled job:</strong> Included, not connected', $html );

		self::read( self::answer() );
		$html = self::render( array( Settings_Page::class, 'render' ) );
		$this->assertStringContainsString( '<strong>Email domain:</strong> Included</li>', $html );
		$this->assertStringContainsString( '<strong>Scheduled job:</strong> Included</li>', $html );
	}

	/**
	 * Out-of-date data: the cards stay, inside the grey "last known" part.
	 *
	 * @return void
	 */
	public function test_stale_overview() {
		self::read( self::answer( self::broken_mail(), array( 'state' => 'late' ) ) );
		self::make_status_older( 3 * 3600 );
		$html = self::overview();

		$this->assertStringContainsString( 'Last Known Status', $html );
		$sections = substr( $html, (int) strpos( $html, 'uptimewire-sections is-stale' ) );
		$this->assertNotSame( '', self::card( $sections, 'Email Domain' ) );
		$this->assertNotSame( '', self::card( $sections, 'Scheduled Job' ) );
	}
}
