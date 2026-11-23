<?php
/**
 * Tests for UptimeWire\Status_Data.
 *
 * @package UptimeWire
 */

namespace UptimeWire\Tests;

use PHPUnit\Framework\TestCase;
use UptimeWire\Status_Data;

/**
 * Validation of the status answer: nothing unknown becomes healthy.
 */
final class StatusDataTest extends TestCase {

	/**
	 * The example answer of the BE-3 contract (23_BE3_API_CONTRACT.md, section 4).
	 *
	 * @return array<string, mixed>
	 */
	public static function answer() {
		return array(
			'data' => array(
				'connection' => array(
					'id'            => '0193a3b2-aaaa-7e3f-8a9b-0c1d2e3f4a5b',
					'state'         => 'active',
					'connected_at'  => '2026-11-23T14:00:00+00:00',
					'account_label' => 'ACC-00001000',
				),
				'site'       => array(
					'url'                         => 'https://client.example.org',
					'created_at'                  => '2026-10-14T09:12:00+00:00',
					'state'                       => 'up',
					'paused'                      => false,
					'awaiting_email_confirmation' => false,
					'interval_minutes'            => 5,
					'problem'                     => null,
					'down_since'                  => null,
					'last_checked_at'             => '2026-11-23T17:59:30+00:00',
					'last_success_at'             => '2026-11-23T17:59:30+00:00',
					'last_location'               => 'Germany',
					'average_response_ms_24h'     => 400,
					'blocked'                     => null,
				),
				'uptime'     => array(
					array( 'window' => '24h', 'from' => '2026-11-22T18:00:00+00:00', 'to' => '2026-11-23T18:00:00+00:00', 'measured_seconds' => 86400, 'downtime_seconds' => 0, 'percent' => 100.0, 'history_limited' => false ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					array( 'window' => '7d', 'from' => '2026-11-16T18:00:00+00:00', 'to' => '2026-11-23T18:00:00+00:00', 'measured_seconds' => 604800, 'downtime_seconds' => 600, 'percent' => 99.9, 'history_limited' => false ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					array( 'window' => '30d', 'from' => '2026-10-24T18:00:00+00:00', 'to' => '2026-11-23T18:00:00+00:00', 'measured_seconds' => 2592000, 'downtime_seconds' => 600, 'percent' => 99.97, 'history_limited' => false ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				),
				'checks'     => array(
					'ssl'    => array( 'state' => 'ok', 'checked_at' => '2026-11-23T12:00:00+00:00', 'expires_at' => '2027-02-11T10:00:00+00:00', 'days_left' => 79, 'warning' => null, 'error' => null ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					'domain' => array( 'state' => 'ok', 'checked_at' => '2026-11-23T12:00:00+00:00', 'expires_at' => null, 'days_left' => null, 'expiry_known' => false, 'warning' => null, 'error' => null ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
					'dns'    => array( 'state' => 'ok', 'checked_at' => '2026-11-23T12:00:00+00:00', 'changed' => false, 'open_changes' => 0, 'last_change_at' => null ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				),
				'events'     => array(
					array( 'type' => 'downtime', 'kind' => 'resolved', 'at' => '2026-11-20T18:00:00+00:00', 'ended_at' => '2026-11-20T18:10:00+00:00', 'duration_seconds' => 600, 'expires_at' => null ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
				),
				'alerts'     => array(
					'ready'         => true,
					'channels'      => array( 'email', 'telegram' ),
					'email_pending' => false,
				),
				'links'      => array(
					'site'   => 'https://app.uptimewire.com/monitors/0193/view',
					'alerts' => 'https://app.uptimewire.com/user/profile/notifications',
					'plans'  => 'https://app.uptimewire.com/subscription-plans',
				),
			),
			'meta' => array( 'server_time' => '2026-11-23T18:00:00+00:00' ),
		);
	}

	/**
	 * The contract example is read as it is.
	 *
	 * @return void
	 */
	public function test_contract_example() {
		$data = Status_Data::normalize( self::answer() );

		$this->assertSame( 'ACC-00001000', $data['account_label'] );
		$this->assertSame( 'https://client.example.org', $data['site']['url'] );
		$this->assertSame( 'up', $data['site']['state'] );
		$this->assertSame( 'up', Status_Data::headline( $data['site'] ) );
		$this->assertSame( 5, $data['site']['interval_minutes'] );
		$this->assertSame( strtotime( '2026-11-23T17:59:30+00:00' ), $data['site']['last_checked_at'] );
		$this->assertSame( 'Germany', $data['site']['last_location'] );
		$this->assertSame( 400, $data['site']['average_response_ms_24h'] );
		$this->assertSame( array( '24h', '7d', '30d' ), array_keys( $data['uptime'] ) );
		$this->assertSame( 99.97, $data['uptime']['30d']['percent'] );
		$this->assertSame( 600, $data['uptime']['7d']['downtime_seconds'] );
		$this->assertSame( 79, $data['ssl']['days_left'] );
		$this->assertFalse( $data['domain']['expiry_known'] );
		$this->assertSame( 'ok', $data['dns']['state'] );
		$this->assertCount( 1, $data['events'] );
		$this->assertSame( 600, $data['events'][0]['duration_seconds'] );
		$this->assertSame( array( 'email', 'telegram' ), $data['alerts']['channels'] );
		$this->assertTrue( $data['alerts']['ready'] );
		$this->assertSame( 'https://app.uptimewire.com/user/profile/notifications', $data['links']['alerts'] );
		$this->assertSame( strtotime( '2026-11-23T18:00:00+00:00' ), $data['server_time'] );
	}

	/**
	 * Data: bodies that are not a status of a website.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function not_a_status() {
		return array(
			'null'           => array( null ),
			'string'         => array( 'nope' ),
			'no data'        => array( array( 'meta' => array() ) ),
			'no site'        => array( array( 'data' => array( 'connection' => array() ) ) ),
			'site not array' => array( array( 'data' => array( 'site' => 'x' ) ) ),
			'no url'         => array( array( 'data' => array( 'site' => array( 'state' => 'up' ) ) ) ),
			'url not string' => array( array( 'data' => array( 'site' => array( 'url' => array( 'x' ) ) ) ) ),
			'empty url'      => array( array( 'data' => array( 'site' => array( 'url' => '  ' ) ) ) ),
			'error body'     => array( array( 'error' => array( 'code' => 'connection_ended' ) ) ),
		);
	}

	/**
	 * Without a website address nothing is shown.
	 *
	 * @dataProvider not_a_status
	 * @param mixed $body Body.
	 * @return void
	 */
	public function test_not_a_status( $body ) {
		$this->assertNull( Status_Data::normalize( $body ) );
	}

	/**
	 * A bare website address gives a record where nothing is healthy.
	 *
	 * @return void
	 */
	public function test_minimal_answer_is_nowhere_healthy() {
		$data = Status_Data::normalize( array( 'data' => array( 'site' => array( 'url' => 'https://a.example' ) ) ) );

		$this->assertSame( 'unknown', Status_Data::headline( $data['site'] ) );
		$this->assertNull( $data['account_label'] );
		foreach ( $data['uptime'] as $window ) {
			$this->assertNull( $window['percent'] );
		}
		$this->assertSame( 'unknown', $data['ssl']['state'] );
		$this->assertSame( 'unknown', $data['domain']['state'] );
		$this->assertSame( 'unknown', $data['dns']['state'] );
		$this->assertSame( array(), $data['events'] );
		$this->assertFalse( $data['alerts']['ready'] );
		$this->assertSame( array(), $data['alerts']['channels'] );
		$this->assertNull( $data['links']['site'] );
	}

	/**
	 * Unknown values become "unknown"; "true" as text is not true.
	 *
	 * @return void
	 */
	public function test_unknown_values() {
		$body                                     = self::answer();
		$body['data']['site']['state']            = 'degraded';
		$body['data']['checks']['ssl']['state']   = 'great';
		$body['data']['checks']['dns']['state']   = 'not_applicable';
		$body['data']['alerts']['ready']          = 'true';
		$body['data']['site']['extra_new_field']  = array( 'x' => 1 );
		$body['data']['brand_new_section']        = array( 'y' );
		$body['data']['checks']['ssl']['warning'] = 'expired';

		$data = Status_Data::normalize( $body );

		$this->assertSame( 'unknown', $data['site']['state'] );
		$this->assertSame( 'unknown', Status_Data::headline( $data['site'] ) );
		$this->assertSame( 'unknown', $data['ssl']['state'] );
		$this->assertNull( $data['ssl']['warning'], 'A warning belongs to a problem only.' );
		$this->assertSame( 'unknown', $data['dns']['state'], 'not_applicable is not a DNS state.' );
		$this->assertFalse( $data['alerts']['ready'] );
		$this->assertArrayNotHasKey( 'extra_new_field', $data['site'] );
		$this->assertArrayNotHasKey( 'brand_new_section', $data );
	}

	/**
	 * Email not confirmed wins over every state: monitoring has not started.
	 *
	 * @return void
	 */
	public function test_unverified_email() {
		$body                           = self::answer();
		$body['data']['site']['state']  = 'paused';
		$body['data']['site']['paused'] = false;
		$body['data']['site']['awaiting_email_confirmation'] = true;

		$this->assertSame( 'unverified', Status_Data::headline( Status_Data::normalize( $body )['site'] ) );

		$body['data']['site']['awaiting_email_confirmation'] = 1;
		$this->assertSame( 'paused', Status_Data::headline( Status_Data::normalize( $body )['site'] ), 'Only a real true counts.' );
	}

	/**
	 * Data: values that are not contract times.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function bad_times() {
		return array(
			'words'       => array( 'yesterday' ),
			'number'      => array( 1732370000 ),
			'no zone'     => array( '2026-11-23T14:00:00' ),
			'space'       => array( '2026-11-23 14:00:00+00:00' ),
			'month 13'    => array( '2026-13-01T00:00:00+00:00' ),
			'february 30' => array( '2026-02-30T00:00:00+00:00' ),
			'hour 24'     => array( '2026-11-23T24:00:00+00:00' ),
			'trailing'    => array( '2026-11-23T14:00:00+00:00x' ),
			'empty'       => array( '' ),
		);
	}

	/**
	 * Unreadable times become null.
	 *
	 * @dataProvider bad_times
	 * @param mixed $value Value.
	 * @return void
	 */
	public function test_bad_times( $value ) {
		$this->assertNull( Status_Data::time( $value ) );
	}

	/**
	 * Good times in the contract form and with Z are read.
	 *
	 * @return void
	 */
	public function test_good_times() {
		$this->assertSame( 1795442400, Status_Data::time( '2026-11-23T14:00:00+00:00' ) );
		$this->assertSame( 1795442400, Status_Data::time( '2026-11-23T14:00:00Z' ) );
		$this->assertSame( 1795442400, Status_Data::time( '2026-11-23T16:00:00+02:00' ) );
	}

	/**
	 * A percentage needs measured time; out-of-range values are dropped; history limits are kept.
	 *
	 * @return void
	 */
	public function test_uptime_never_invented() {
		$body                   = self::answer();
		$body['data']['uptime'] = array(
			array( 'window' => '24h', 'from' => null, 'to' => '2026-11-23T18:00:00+00:00', 'measured_seconds' => 0, 'downtime_seconds' => 0, 'percent' => 100.0, 'history_limited' => false ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array( 'window' => '30d', 'from' => '2026-11-16T18:00:00+00:00', 'to' => '2026-11-23T18:00:00+00:00', 'measured_seconds' => 604800, 'downtime_seconds' => 0, 'percent' => 150, 'history_limited' => true ), // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
			array(
		'window'  => '1y',
		'percent' => 99.0,
		),
			'garbage',
		);

		$data = Status_Data::normalize( $body );

		$this->assertNull( $data['uptime']['24h']['percent'], 'Nothing measured is not 100 %.' );
		$this->assertNull( $data['uptime']['7d']['percent'], 'A missing window is not measured.' );
		$this->assertNull( $data['uptime']['30d']['percent'], 'Out of range.' );
		$this->assertTrue( $data['uptime']['30d']['history_limited'] );
		$this->assertSame( 604800, $data['uptime']['30d']['measured_seconds'] );
		$this->assertArrayNotHasKey( '1y', $data['uptime'] );
	}

	/**
	 * Expiry checks: problems, expired and an unknown domain date.
	 *
	 * @return void
	 */
	public function test_expiry_checks() {
		$body                             = self::answer();
		$body['data']['checks']['ssl']    = array( 'state' => 'problem', 'checked_at' => '2026-11-23T12:00:00+00:00', 'expires_at' => '2026-11-20T00:00:00+00:00', 'days_left' => -3, 'warning' => 'expired', 'error' => null ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$body['data']['checks']['domain'] = array( 'state' => 'ok', 'checked_at' => null, 'expires_at' => '2027-01-01T00:00:00+00:00', 'days_left' => 39, 'expiry_known' => false, 'warning' => null, 'error' => null ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$data = Status_Data::normalize( $body );

		$this->assertSame( 'expired', $data['ssl']['warning'] );
		$this->assertSame( -3, $data['ssl']['days_left'] );
		$this->assertFalse( $data['domain']['expiry_known'] );
		$this->assertNull( $data['domain']['expires_at'], 'An unknown date is not shown.' );
		$this->assertNull( $data['domain']['days_left'] );
	}

	/**
	 * Events: newest first, at most ten, unknown kept as unknown, only outages have a duration.
	 *
	 * @return void
	 */
	public function test_events() {
		$events = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$events[] = array( 'type' => 'downtime', 'kind' => 'resolved', 'at' => sprintf( '2026-11-%02dT10:00:00+00:00', $i ), 'ended_at' => null, 'duration_seconds' => 60, 'expires_at' => null ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		}
		$events[] = array( 'type' => 'ssl', 'kind' => 'problem', 'at' => '2026-11-22T10:00:00+00:00', 'ended_at' => null, 'duration_seconds' => 999, 'expires_at' => '2026-12-01T00:00:00+00:00' ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$events[] = array(
			'type' => 'dns',
			'kind' => 'renewed',
			'at'   => '2026-11-21T10:00:00+00:00',
		);
		$events[] = array(
			'type' => 'malware',
			'kind' => 'found',
			'at'   => '2026-11-20T10:00:00+00:00',
		);
		$events[] = array(
			'type' => 'downtime',
			'kind' => 'ongoing',
			'at'   => 'not a time',
		);
		$events[] = 'garbage';

		$body                   = self::answer();
		$body['data']['events'] = $events;
		$data                   = Status_Data::normalize( $body );

		$this->assertCount( 10, $data['events'] );
		$this->assertSame( 'ssl', $data['events'][0]['type'] );
		$this->assertNull( $data['events'][0]['duration_seconds'], 'An SSL warning is never downtime.' );
		$this->assertSame( strtotime( '2026-12-01T00:00:00+00:00' ), $data['events'][0]['expires_at'] );
		$this->assertSame( 'unknown', $data['events'][1]['type'], 'A DNS event of an unknown kind is not shown as a change.' );
		$this->assertSame( 'unknown', $data['events'][2]['type'] );
		$this->assertSame( strtotime( '2026-11-12T10:00:00+00:00' ), $data['events'][3]['at'] );
	}

	/**
	 * Alerts: only known channel kinds, never a destination.
	 *
	 * @return void
	 */
	public function test_alert_channels() {
		$body                   = self::answer();
		$body['data']['alerts'] = array(
			'ready'         => false,
			'channels'      => array( 'email', 'email', 'pager', array( 'to' => 'owner@example.com' ), 'teams' ),
			'email_pending' => true,
		);

		$alerts = Status_Data::normalize( $body )['alerts'];

		$this->assertSame( array( 'email', 'teams' ), $alerts['channels'] );
		$this->assertTrue( $alerts['other_channels'] );
		$this->assertTrue( $alerts['email_pending'] );
		$this->assertStringNotContainsString( 'owner@example.com', (string) json_encode( $alerts ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	/**
	 * Data: links that must not be shown.
	 *
	 * @return array<string, array{mixed}>
	 */
	public function unsafe_links() {
		return array(
			'javascript'    => array( 'javascript:alert(1)' ),
			'data'          => array( 'data:text/html,<b>x</b>' ),
			'http'          => array( 'http://app.uptimewire.com/x' ),
			'other host'    => array( 'https://evil.example/x' ),
			'lookalike'     => array( 'https://app.uptimewire.com.evil.example/x' ),
			'userinfo'      => array( 'https://app.uptimewire.com@evil.example/x' ),
			'port'          => array( 'https://app.uptimewire.com:8443/x' ),
			'quote'         => array( 'https://app.uptimewire.com/x" onmouseover="alert(1)' ),
			'space'         => array( 'https://app.uptimewire.com/x y' ),
			'protocol-less' => array( '//app.uptimewire.com/x' ),
			'not a string'  => array( array( 'https://app.uptimewire.com/' ) ),
		);
	}

	/**
	 * Unsafe links are dropped.
	 *
	 * @dataProvider unsafe_links
	 * @param mixed $url Link.
	 * @return void
	 */
	public function test_unsafe_links( $url ) {
		$body                          = self::answer();
		$body['data']['links']['site'] = $url;

		$this->assertFalse( Status_Data::is_safe_link( $url ) );
		$this->assertNull( Status_Data::normalize( $body )['links']['site'] );
	}

	/**
	 * Untrusted text is kept as text, cut to the contract length, without control characters.
	 *
	 * @return void
	 */
	public function test_untrusted_text() {
		$body                                   = self::answer();
		$body['data']['site']['problem']        = "<script>alert(1)</script>\x07" . str_repeat( 'ü', 400 );
		$body['data']['checks']['ssl']['error'] = "bad \xC3\x28 utf-8";

		$data = Status_Data::normalize( $body );

		$this->assertStringStartsWith( '<script>alert(1)</script> ', $data['site']['problem'], 'Escaping is the job of the screen.' );
		$this->assertSame( 301, preg_match_all( '/./us', $data['site']['problem'] ) );
		$this->assertStringEndsWith( '…', $data['site']['problem'] );
		$this->assertNull( $data['ssl']['error'], 'Invalid UTF-8 is not shown.' );
	}

	/**
	 * The account number has one shape.
	 *
	 * @return void
	 */
	public function test_account_label() {
		$this->assertSame( 'ACC-00001000', Status_Data::account_label( 'ACC-00001000' ) );
		$this->assertNull( Status_Data::account_label( 'acc-1' ) );
		$this->assertNull( Status_Data::account_label( 'ACC-1<b>' ) );
		$this->assertNull( Status_Data::account_label( 'owner@example.com' ) );
		$this->assertNull( Status_Data::account_label( 1000 ) );
	}
}
