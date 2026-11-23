<?php
/**
 * Connecting by code against a fake UptimeWire: scenarios 1–12, 17, 18, 20, 22–24 of WP-2.
 *
 * @package UptimeWire
 */

namespace UptimeWire\Tests;

use UptimeWire\Admin\Overview_Page;
use UptimeWire\Admin\Settings_Page;
use UptimeWire\Authorization;
use UptimeWire\Connection;
use UptimeWire\Lock;
use UptimeWire\State;

/**
 * The plugin's side of RFC 8628 and the BE-2 contract.
 */
final class ConnectFlowTest extends Test_Case {

	/**
	 * Scenario 1: a new person connects. Start, wait, approve, connected.
	 *
	 * @return void
	 */
	public function test_01_new_user_connects() {
		$this->assertSame( State::NOT_CONNECTED, State::current() );
		$this->assertSame( array(), Fake_Api::calls(), 'Nothing is sent before a button is clicked.' );

		$result = $this->start_connecting();
		$this->assertTrue( $result['ok'] );

		$call = Fake_Api::calls( '/wp/v1/device' )[0];
		$body = $call['args']['body'];
		$this->assertSame( 'POST', $call['args']['method'] );
		$this->assertSame( 'wordpress', $body['client_id'] ); // phpcs:ignore WordPress.WP.CapitalPDangit -- The client ID of the contract.
		$this->assertSame( 'https://www.customer-site.com', $body['site_url'], 'The website to monitor, without the trailing slash.' );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{43}$/', $body['proof_hash'] );
		$this->assertSame( get_bloginfo( 'version' ), $body['wp'] );
		$this->assertSame( UPTIMEWIRE_VERSION, $body['plugin'] );
		$this->assertSame( wp_get_environment_type(), $body['env'] );
		$this->assertSame( '0', $body['multisite'] );
		$this->assertSame( array( 'client_id', 'site_url', 'proof_hash', 'wp', 'plugin', 'env', 'multisite' ), array_keys( $body ), 'Nothing else is sent: no e-mail, no user, no content.' );

		$pending = Authorization::pending();
		$this->assertSame( rtrim( strtr( base64_encode( hash( 'sha256', $pending['proof'], true ) ), '+/', '-_' ), '=' ), $body['proof_hash'], 'The hash is of the proof kept on this server.' ); // phpcs:ignore
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9_-]{16,256}$/', $pending['proof'] );
		$this->assertSame( State::AUTHORIZATION_PENDING, State::current() );

		Fake_Api::queue( '/wp/v1/token', 400, self::oauth_error( 'authorization_pending' ) );
		self::make_due();
		Authorization::poll();
		$this->assertSame( State::WAITING_FOR_APPROVAL, State::current() );

		$token = Fake_Api::calls( '/wp/v1/token' )[0]['args']['body'];
		$this->assertSame(
			array(
				'grant_type'  => 'urn:ietf:params:oauth:grant-type:device_code',
				'device_code' => self::DEVICE_CODE,
				'client_id'   => 'wordpress',
			),
			$token
		);

		Fake_Api::queue( '/wp/v1/token', 200, self::token_answer() );
		self::make_due();
		$view = Authorization::poll();

		$this->assertSame( State::CONNECTION_ESTABLISHED, $view['state'] );
		$this->assertTrue( $view['done'] );
		$this->assertSame( self::TOKEN, Connection::token() );
	}

	/**
	 * Scenario 2: an existing account. The plugin's path is the same; a
	 * connection UptimeWire ended is replaced by the new one.
	 *
	 * @return void
	 */
	public function test_02_existing_account_reconnects() {
		update_option(
			Connection::OPTION,
			array(
				'id'           => 'old',
				'connected_at' => '2026-11-01T00:00:00+00:00',
				'site_url'     => 'https://www.customer-site.com/',
				'local_url'    => 'https://www.customer-site.com',
				'connected_by' => 1,
				'health'       => array(
					'state'      => Connection::HEALTH_ENDED,
					'checked_at' => time(),
				),
			),
			false
		);
		$this->assertSame( State::RECONNECTION_REQUIRED, State::current() );

		$this->assertTrue( $this->start_connecting()['ok'] );
		Fake_Api::queue( '/wp/v1/token', 200, self::token_answer( 'https://www.customer-site.com/' ) );
		self::make_due();
		Authorization::poll();

		$record = Connection::get();
		$this->assertSame( State::CONNECTION_ESTABLISHED, State::current() );
		$this->assertSame( '0196a3b2-aaaa-7e3f-8a9b-0c1d2e3f4a5b', $record['id'] );
		$this->assertSame( Connection::HEALTH_OK, Connection::health_state( $record ) );
	}

	/**
	 * Scenario 2b: while a connection lives, no second one is started.
	 *
	 * @return void
	 */
	public function test_02b_a_live_connection_is_not_started_again() {
		Connection::save( self::TOKEN, array( 'id' => 'x', 'site_url' => self::SITE, 'local_url' => self::SITE, 'connected_by' => 1 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$result = Authorization::start( 1, false );

		$this->assertSame( 'already_connected', $result['error'] );
		$this->assertSame( array(), Fake_Api::calls() );
	}

	/**
	 * Scenario 3: the exchange stores the credential and cleans every temporary row.
	 *
	 * @return void
	 */
	public function test_03_successful_exchange_stores_and_cleans() {
		$this->start_connecting();
		Fake_Api::queue( '/wp/v1/token', 200, self::token_answer() );
		self::make_due();
		Authorization::poll();

		$stored = get_option( Connection::OPTION );
		$this->assertSame( self::TOKEN, $stored['token'] );
		$this->assertSame( 'https://www.customer-site.com', $stored['site_url'] );
		$this->assertSame( 'https://www.customer-site.com', $stored['local_url'] );
		$this->assertSame( 1, $stored['connected_by'] );
		$this->assertSame( '2026-11-23T08:37:39+00:00', $stored['connected_at'] );
		$this->assertNull( Authorization::pending() );
		$this->assertNull( Authorization::outcome() );
		$this->assertSame( array( Connection::OPTION, 'uptimewire_site_url' ), self::plugin_rows(), 'No pending code, lock or proof is left.' );
		$this->assertStringNotContainsString( self::DEVICE_CODE, self::plugin_rows_dump() );
		$this->assertArrayNotHasKey( 'token', Connection::get(), 'The record handed to screens has no credential.' );

		$html = self::render( array( Overview_Page::class, 'render' ) );
		$this->assertStringContainsString( 'Your WordPress website is connected to UptimeWire.', $html );
		$this->assertStringContainsString( 'Connection Established', $html );
		$this->assertStringContainsString( 'Connecting and monitoring are separate steps', $html, 'Monitoring is not claimed to have started.' );
		$this->assertStringNotContainsString( 'is now monitoring', $html );
	}

	/**
	 * Scenario 4: the code expired at UptimeWire, or nobody asked until it died.
	 *
	 * @return void
	 */
	public function test_04_expired_code() {
		$this->start_connecting();
		Fake_Api::queue( '/wp/v1/token', 400, self::oauth_error( 'expired_token' ) );
		self::make_due();
		Authorization::poll();

		$this->assertSame( State::AUTHORIZATION_EXPIRED, State::current() );
		$this->assertNull( Authorization::pending(), 'The device code is forgotten.' );

		$calls = count( Fake_Api::calls() );
		Authorization::poll();
		$this->assertCount( $calls, Fake_Api::calls(), 'Polling stops.' );

		$html = self::render( array( Overview_Page::class, 'render' ) );
		$this->assertStringContainsString( 'Authorization Expired', $html );
		$this->assertStringContainsString( 'The code expired. Start again.', $html );
		$this->assertStringContainsString( 'Start Again', $html );
	}

	/**
	 * Scenario 4b: without an answer from UptimeWire the plugin still stops, two and a half minutes after the code's life.
	 *
	 * @return void
	 */
	public function test_04b_expiry_without_uptimewire() {
		$this->start_connecting();
		self::age_pending( 600 + Authorization::POLL_GRACE + 1 );

		$view = Authorization::poll();

		$this->assertSame( State::AUTHORIZATION_EXPIRED, $view['state'] );
		$this->assertCount( 0, Fake_Api::calls( '/wp/v1/token' ) );
	}

	/**
	 * Scenario 5: the person clicked Deny (or lost the right to connect).
	 *
	 * @return void
	 */
	public function test_05_denied() {
		$this->start_connecting();
		Fake_Api::queue( '/wp/v1/token', 400, self::oauth_error( 'access_denied' ) );
		self::make_due();
		Authorization::poll();

		$this->assertSame( State::AUTHORIZATION_DENIED, State::current() );
		$this->assertNull( Authorization::pending() );
		$html = self::render( array( Overview_Page::class, 'render' ) );
		$this->assertStringContainsString( 'Connection cancelled. Nothing was changed.', $html );
		$this->assertNull( Connection::get() );
	}

	/**
	 * Scenario 6: invalid_grant (unknown code, or one that already gave its credential).
	 *
	 * @return void
	 */
	public function test_06_invalid_grant() {
		$this->start_connecting();
		Fake_Api::queue( '/wp/v1/token', 400, self::oauth_error( 'invalid_grant' ) );
		self::make_due();
		Authorization::poll();

		$this->assertSame( State::CONNECTION_ERROR, State::current() );
		$this->assertNull( Authorization::pending() );
		$html = self::render( array( Overview_Page::class, 'render' ) );
		$this->assertStringContainsString( 'The connection didn’t finish. Please try again.', $html );
	}

	/**
	 * Scenario 7: slow_down raises the interval; Retry-After is waited out; no early question.
	 *
	 * @return void
	 */
	public function test_07_slow_down_and_retry_after() {
		$this->start_connecting();

		Authorization::poll();
		$this->assertCount( 0, Fake_Api::calls( '/wp/v1/token' ), 'Not before the first interval.' );

		Fake_Api::queue( '/wp/v1/token', 400, self::oauth_error( 'slow_down', array( 'interval' => 10 ) ) );
		self::make_due();
		$view = Authorization::poll();

		$this->assertSame( 10, Authorization::pending()['interval'] );
		$this->assertGreaterThanOrEqual( 9, $view['retry_after'] );
		Authorization::poll();
		$this->assertCount( 1, Fake_Api::calls( '/wp/v1/token' ), 'The new interval is kept.' );

		Fake_Api::queue( '/wp/v1/token', 400, self::oauth_error( 'slow_down' ) );
		self::make_due();
		Authorization::poll();
		$this->assertSame( 15, Authorization::pending()['interval'], 'slow_down without a number adds five seconds (RFC 8628, 3.5).' );

		Fake_Api::queue( '/wp/v1/token', 429, self::oauth_error( 'slow_down' ), array( 'retry-after' => '40' ) );
		self::make_due();
		$view = Authorization::poll();
		$this->assertGreaterThanOrEqual( 39, $view['retry_after'] );
		$this->assertSame( 'rate_limited', $view['notice'] );
		$this->assertSame( State::WAITING_FOR_APPROVAL, State::current() );

		Fake_Api::queue( '/wp/v1/token', 400, self::oauth_error( 'slow_down', array( 'interval' => 600 ) ) );
		self::make_due();
		Authorization::poll();
		$this->assertSame( 60, Authorization::pending()['interval'], 'Never more than a minute between questions.' );
	}

	/**
	 * Scenario 8: while another request asks UptimeWire, this one does not.
	 *
	 * @return void
	 */
	public function test_08_no_two_polls_at_once() {
		$this->start_connecting();
		self::make_due();

		$this->assertTrue( Lock::acquire( 'poll', 20 ) );
		$view = Authorization::poll();
		$this->assertCount( 0, Fake_Api::calls( '/wp/v1/token' ) );
		$this->assertFalse( $view['done'] );
		Lock::release( 'poll' );

		Fake_Api::queue( '/wp/v1/token', 400, self::oauth_error( 'authorization_pending' ) );
		Authorization::poll();
		$this->assertCount( 1, Fake_Api::calls( '/wp/v1/token' ) );
		$this->assertFalse( Lock::acquire( 'poll', 20 ) === false, 'The lock was released after the question.' );
		Lock::release( 'poll' );
	}

	/**
	 * Scenario 8b: a lock left by a crashed request expires by itself.
	 *
	 * @return void
	 */
	public function test_08b_a_stale_lock_expires() {
		global $wpdb;

		$wpdb->insert( $wpdb->options, array( 'option_name' => 'uptimewire_lock_poll', 'option_value' => (string) ( time() - 1 ), 'autoload' => 'no' ) ); // phpcs:ignore

		$this->assertTrue( Lock::acquire( 'poll', 20 ) );
		$this->assertFalse( Lock::acquire( 'poll', 20 ) );
		Lock::release( 'poll' );

		// A request that outlived its lock does not release the lock another request took since.
		$this->assertTrue( Lock::acquire( 'poll', 20 ) );
		$wpdb->update( $wpdb->options, array( 'option_value' => ( time() + 20 ) . ':someone-else' ), array( 'option_name' => 'uptimewire_lock_poll' ) ); // phpcs:ignore
		Lock::release( 'poll' );
		$this->assertFalse( Lock::acquire( 'poll', 20 ), 'The other holder keeps its lock.' );
		$wpdb->delete( $wpdb->options, array( 'option_name' => 'uptimewire_lock_poll' ) ); // phpcs:ignore
	}

	/**
	 * Scenario 8c (security review L4): a copy of the site made while connecting does not poll with the original's code.
	 *
	 * @return void
	 */
	public function test_08c_a_copy_made_while_connecting_does_not_poll() {
		$this->start_connecting();
		update_option( 'uptimewire_site_url', 'https://staging.customer-site.com/', false );
		self::make_due();

		Authorization::poll();

		$this->assertCount( 0, Fake_Api::calls( '/wp/v1/token' ) );
		$this->assertNull( Authorization::pending() );
		$this->assertSame( 'site_changed', Authorization::outcome()['reason'] );
	}

	/**
	 * Scenario 9: a double click asks for one code.
	 *
	 * @return void
	 */
	public function test_09_double_click_asks_once() {
		$this->start_connecting();
		$first = Authorization::pending()['user_code'];

		$second = Authorization::start( 1, false );

		$this->assertTrue( $second['ok'] );
		$this->assertCount( 1, Fake_Api::calls( '/wp/v1/device' ) );
		$this->assertSame( $first, Authorization::pending()['user_code'] );

		// A click while the first request still waits for UptimeWire.
		$this->assertTrue( Lock::acquire( 'start', 15 ) );
		$third = Authorization::start( 1, false );
		Lock::release( 'start' );
		$this->assertTrue( $third['ok'] );
		$this->assertCount( 1, Fake_Api::calls( '/wp/v1/device' ) );
	}

	/**
	 * Scenario 9b: "Start Again" after the reuse window asks for a new code and forgets the old one.
	 *
	 * @return void
	 */
	public function test_09b_start_again_replaces_the_old_code() {
		$this->start_connecting();
		self::age_pending( Authorization::REUSE_WINDOW + 1 );

		Fake_Api::queue( '/wp/v1/device', 200, self::device_answer( array( 'user_code' => 'BCDF-GHJK', 'authorization_id' => '0196a3b2-1c2d-7e3f-8a9b-ffffffffffff' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		Authorization::start( 1, false );

		$this->assertCount( 2, Fake_Api::calls( '/wp/v1/device' ) );
		$this->assertSame( 'BCDF-GHJK', Authorization::pending()['user_code'] );
	}

	/**
	 * Scenario 10: a reload of the page shows the same waiting screen while the code lives.
	 *
	 * @return void
	 */
	public function test_10_reload_restores_waiting() {
		$this->start_connecting();
		self::age_pending( 120 );

		$html = self::render( array( Overview_Page::class, 'render' ) );

		$this->assertStringContainsString( 'Finish Connecting UptimeWire', $html );
		$this->assertStringContainsString( 'A new tab has opened. Sign in to UptimeWire and approve the connection for your website.', $html );
		$this->assertStringContainsString( 'WDJB-MJHT', $html );
		$this->assertStringContainsString( 'href="https://app.uptimewire.com/connect?code=WDJB-MJHT"', $html );
		$this->assertStringContainsString( 'Open UptimeWire', $html );
		$this->assertStringContainsString( 'Cancel', $html );
		$this->assertMatchesRegularExpression( '/data-seconds-left="(47\d|480)"/', $html, 'The time left is counted from when UptimeWire answered.' );
		$this->assertStringContainsString( 'role="status"', $html );
		$this->assertStringContainsString( 'Authorization Pending', $html );
	}

	/**
	 * Scenario 11: a closed tab leaves nothing behind once the code is dead.
	 *
	 * @return void
	 */
	public function test_11_closed_tab_leaves_no_pending_code() {
		$this->start_connecting();

		$timeout = (int) get_option( '_transient_timeout_' . Authorization::TRANSIENT );
		$this->assertLessThanOrEqual( time() + 600 + Authorization::POLL_GRACE + 60, $timeout, 'The row itself has an expiry: WordPress drops it even if nobody comes back.' );

		self::age_pending( 600 + Authorization::POLL_GRACE + 1 );
		$this->assertSame( State::AUTHORIZATION_EXPIRED, State::current() );
		$this->assertStringNotContainsString( self::DEVICE_CODE, self::plugin_rows_dump() );
	}

	/**
	 * Scenario 11b: Cancel forgets the code; a credential that arrives afterwards is ended, not kept.
	 *
	 * @return void
	 */
	public function test_11b_cancel() {
		$this->start_connecting();
		$pending = Authorization::pending();

		Authorization::cancel();

		$this->assertNull( Authorization::pending() );
		$this->assertSame( State::NOT_CONNECTED, State::current() );

		// The answer of a question asked just before Cancel arrives after it.
		set_transient( Authorization::TRANSIENT, $pending, 900 );
		set_transient( Authorization::CANCELLED_TRANSIENT, $pending['authorization_id'], 900 );
		Fake_Api::queue( '/wp/v1/token', 200, self::token_answer() );
		Fake_Api::queue( '/wp/v1/revoke', 200 );
		self::make_due();
		set_transient( Authorization::CANCELLED_TRANSIENT, $pending['authorization_id'], 900 );
		Authorization::poll();

		$this->assertNull( Connection::get() );
		$this->assertCount( 1, Fake_Api::calls( '/wp/v1/revoke' ) );
		$this->assertSame( array( 'token' => self::TOKEN ), Fake_Api::calls( '/wp/v1/revoke' )[0]['args']['body'] );
	}

	/**
	 * Scenario 12: without the capability, the REST routes refuse and nothing is asked.
	 *
	 * @return void
	 */
	public function test_12_no_capability() {
		$editor = wp_insert_user(
			array(
				'user_login' => 'uw_test_editor_' . wp_rand(),
				'user_pass'  => wp_generate_password(),
				'role'       => 'editor',
			)
		);
		wp_set_current_user( $editor );

		foreach ( array( '/uptimewire/v1/connect-start', '/uptimewire/v1/connect-status' ) as $route ) {
			$response = rest_do_request( new \WP_REST_Request( 'POST', $route ) );
			$this->assertSame( 403, $response->get_status(), $route );
		}

		wp_set_current_user( 0 );
		$response = rest_do_request( new \WP_REST_Request( 'POST', '/uptimewire/v1/connect-start' ) );
		$this->assertSame( 401, $response->get_status() );

		$this->assertSame( array(), Fake_Api::calls() );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $editor );
	}

	/**
	 * Scenario 12b: an administrator uses the REST routes; answers carry no secret and are not cached.
	 *
	 * @return void
	 */
	public function test_12b_rest_routes_for_an_administrator() {
		Fake_Api::queue( '/wp/v1/device', 200, self::device_answer() );
		$response = rest_do_request( new \WP_REST_Request( 'POST', '/uptimewire/v1/connect-start' ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $data['ok'] );
		$this->assertSame( 'https://app.uptimewire.com/connect?code=WDJB-MJHT', $data['open'] );
		$this->assertSame( 'no-store, private', $response->get_headers()['Cache-Control'] );

		$response = rest_do_request( new \WP_REST_Request( 'POST', '/uptimewire/v1/connect-status' ) );
		$data     = $response->get_data();
		$this->assertSame( State::AUTHORIZATION_PENDING, $data['state'] );
		$this->assertFalse( $data['done'] );
		$this->assertSame( array( 'state', 'done', 'retry_after', 'seconds_left', 'notice' ), array_keys( $data ) );
		$this->assertCount( 0, Fake_Api::calls( '/wp/v1/token' ), 'Asked too early: answered from the stored state.' );
	}

	/**
	 * Scenario 17: UptimeWire times out. Starting changes nothing; waiting goes on.
	 *
	 * @return void
	 */
	public function test_17_api_timeout() {
		Fake_Api::queue( '/wp/v1/device', 0 );
		$result = Authorization::start( 1, false );

		$this->assertFalse( $result['ok'] );
		$this->assertSame( 'unreachable', $result['error'] );
		$this->assertNull( Authorization::pending() );
		$args = Fake_Api::calls( '/wp/v1/device' )[0]['args'];
		$this->assertTrue( $args['sslverify'] );
		$this->assertSame( 0, $args['redirection'] );
		$this->assertTrue( $args['reject_unsafe_urls'] );
		$this->assertLessThanOrEqual( 10, $args['timeout'] );
		$this->assertStringStartsWith( 'https://api.uptimewire.com/', Fake_Api::calls()[0]['url'] );

		Fake_Api::queue( '/wp/v1/device', 503 );
		$this->assertSame( 'unreachable', Authorization::start( 1, false )['error'] );

		$this->start_connecting();
		Fake_Api::queue( '/wp/v1/token', 0 );
		self::make_due();
		$view = Authorization::poll();

		$this->assertSame( 'unreachable', $view['notice'] );
		$this->assertGreaterThanOrEqual( 9, $view['retry_after'], 'Asked again later, not at once.' );
		$this->assertNotNull( Authorization::pending(), 'The code is kept: a lost answer is asked again.' );

		Fake_Api::queue( '/wp/v1/token', 502 );
		self::make_due();
		Authorization::poll();
		$this->assertNotNull( Authorization::pending() );

		Fake_Api::queue( '/wp/v1/token', 200, self::token_answer() );
		self::make_due();
		$this->assertSame( State::CONNECTION_ESTABLISHED, Authorization::poll()['state'] );
	}

	/**
	 * Scenario 17b: answers that break the contract are not trusted.
	 *
	 * @return void
	 */
	public function test_17b_unexpected_answers() {
		$bad = array(
			'device code'      => array( 'device_code' => 'abc' ),
			'user code'        => array( 'user_code' => 'AAAA-AAAA' ),
			'foreign page'     => array( 'verification_uri' => 'https://evil.example/connect' ),
			'http page'        => array( 'verification_uri' => 'http://app.uptimewire.com/connect' ),
			'page with a port' => array( 'verification_uri' => 'https://app.uptimewire.com:8443/connect' ),
			'authorization id' => array( 'authorization_id' => '../../etc' ),
		);

		foreach ( $bad as $case => $override ) {
			Fake_Api::queue( '/wp/v1/device', 200, self::device_answer( $override ) );
			$this->assertSame( 'unexpected', Authorization::start( 1, false )['error'], $case );
			$this->assertNull( Authorization::pending(), $case );
		}

		Fake_Api::queue( '/wp/v1/device', 200, self::device_answer( array( 'verification_uri_complete' => 'https://evil.example/?code=WDJB-MJHT' ) ) );
		Authorization::start( 1, false );
		$this->assertSame( '', Authorization::pending()['verification_uri_complete'], 'A foreign "complete" address is dropped; the person types the code.' );
		Authorization::cancel();

		Fake_Api::queue( '/wp/v1/device', 400, self::oauth_error( 'invalid_request' ) );
		$this->assertSame( 'site_rejected', Authorization::start( 1, false )['error'] );

		Fake_Api::queue( '/wp/v1/device', 429, self::oauth_error( 'slow_down' ), array( 'retry-after' => '1200' ) );
		$result = Authorization::start( 1, false );
		$this->assertSame( 'rate_limited', $result['error'] );
		$this->assertSame( 1200, $result['retry_after'] );

		// A new code (the cancelled one above keeps its ID marked as cancelled).
		Fake_Api::queue( '/wp/v1/device', 200, self::device_answer( array( 'authorization_id' => '0196a3b2-1c2d-7e3f-8a9b-000000000017' ) ) );
		Authorization::start( 1, false );
		Fake_Api::queue( '/wp/v1/token', 200, array( 'access_token' => 'not-a-credential' ) );
		self::make_due();
		Authorization::poll();
		$this->assertSame( State::CONNECTION_ERROR, State::current() );
		$this->assertNull( Connection::get() );
	}

	/**
	 * Scenario 18: UptimeWire names another website. Nothing is saved, the
	 * credential is ended, the administrator sees both addresses.
	 *
	 * @return void
	 */
	public function test_18_site_mismatch_is_refused() {
		$this->start_connecting();
		Fake_Api::queue( '/wp/v1/token', 200, self::token_answer( 'https://shop.other-site.com/' ) );
		Fake_Api::queue( '/wp/v1/revoke', 200 );
		self::make_due();
		Authorization::poll();

		$this->assertNull( Connection::get() );
		$this->assertSame( '', Connection::token() );
		$this->assertSame( State::CONNECTION_ERROR, State::current() );
		$this->assertSame( 'site_mismatch', Authorization::outcome()['reason'] );
		$this->assertSame( array( 'token' => self::TOKEN ), Fake_Api::calls( '/wp/v1/revoke' )[0]['args']['body'] );
		$this->assertStringNotContainsString( self::TOKEN, self::plugin_rows_dump() );

		$html = self::render( array( Overview_Page::class, 'render' ) );
		$this->assertStringContainsString( 'Website Addresses Don’t Match', $html );
		$this->assertStringContainsString( 'UptimeWire shows https://shop.other-site.com/ for this site, but this WordPress site is https://www.customer-site.com.', $html );
		$this->assertStringContainsString( 'Start Again', $html );
		$this->assertStringNotContainsString( 'href="https://shop.other-site.com', $html, 'The other address is text, never a link.' );
	}

	/**
	 * Scenario 18b: the allowed differences are accepted, others are not.
	 *
	 * @return void
	 */
	public function test_18b_equivalent_addresses() {
		$same = array( 'http://www.customer-site.com', 'https://WWW.Customer-Site.com/', 'https://www.customer-site.com:443/', 'http://localhost:' . wp_parse_url( home_url(), PHP_URL_PORT ) . '/' );
		foreach ( $same as $site ) {
			self::wipe();
			update_option( 'uptimewire_site_url', self::SITE, false );
			$this->start_connecting();
			Fake_Api::queue( '/wp/v1/token', 200, self::token_answer( $site ) );
			self::make_due();
			Authorization::poll();
			$this->assertSame( State::CONNECTION_ESTABLISHED, State::current(), $site );
		}

		$other = array( 'https://customer-site.com', 'https://www.customer-site.com/shop', 'https://www.customer-site.com.evil.example', 'https://www.customer-site.com:8443/', 'https://www.customer-site.com/?x=1' );
		foreach ( $other as $site ) {
			self::wipe();
			update_option( 'uptimewire_site_url', self::SITE, false );
			$this->start_connecting();
			Fake_Api::queue( '/wp/v1/token', 200, self::token_answer( $site ) );
			Fake_Api::queue( '/wp/v1/revoke', 200 );
			self::make_due();
			Authorization::poll();
			$this->assertSame( State::CONNECTION_ERROR, State::current(), $site );
		}
	}

	/**
	 * Scenario 18c: a stored connection whose website no longer matches this site (a copy) shows no data and offers no reconnect.
	 *
	 * @return void
	 */
	public function test_18c_copy_of_a_connected_site() {
		Connection::save( self::TOKEN, array( 'id' => 'x', 'connected_at' => '2026-11-23T08:37:39+00:00', 'site_url' => 'https://www.customer-site.com', 'local_url' => 'https://www.customer-site.com', 'connected_by' => 1 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		update_option( 'uptimewire_site_url', 'https://staging.customer-site.com/', false );
		Fake_Api::queue(
			'/wp/v1/connection',
			200,
			array(
				'data' => array(
					'id'           => 'x',
					'connected_at' => '2026-11-23T08:37:39+00:00',
					'site'         => array( 'url' => 'https://www.customer-site.com' ),
				),
			)
		);

		Connection::check_health( true );

		$this->assertSame( State::CONNECTION_ERROR, State::current() );
		$html = self::render( array( Overview_Page::class, 'render' ) );
		$this->assertStringContainsString( 'This connection belongs to https://www.customer-site.com.', $html );
		$this->assertStringNotContainsString( 'Reconnect', $html );
		$this->assertSame( 'already_connected', Authorization::start( 1, false )['error'], 'A copy does not take the connection away from the original site.' );
	}

	/**
	 * Scenario 20: a local site cannot start, and nothing is sent.
	 *
	 * @return void
	 */
	public function test_20_local_site() {
		delete_option( 'uptimewire_site_url' );

		$result = Authorization::start( 1, false );

		$this->assertSame( 'local_site', $result['error'] );
		$this->assertSame( array(), Fake_Api::calls() );
		$html = self::render( array( Overview_Page::class, 'render' ) );
		$this->assertStringContainsString( 'This looks like a local site.', $html );
		$this->assertStringContainsString( '<fieldset disabled', $html );
	}

	/**
	 * Scenario 22: UptimeWire ended the connection (revoked, site deleted). Reconnection is offered.
	 *
	 * @return void
	 */
	public function test_22_revoked_credential() {
		Connection::save( self::TOKEN, array( 'id' => 'x', 'connected_at' => '2026-11-23T08:37:39+00:00', 'site_url' => 'https://www.customer-site.com', 'local_url' => 'https://www.customer-site.com', 'connected_by' => 1 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		Fake_Api::queue( '/wp/v1/connection', 401, array( 'error' => array( 'code' => 'connection_ended', 'message' => 'The connection ended.' ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		Connection::check_health( true );

		$this->assertSame( 'Bearer ' . self::TOKEN, Fake_Api::calls( '/wp/v1/connection' )[0]['args']['headers']['Authorization'] );
		$this->assertSame( State::RECONNECTION_REQUIRED, State::current() );
		$this->assertSame( '', Connection::token(), 'A dead credential is forgotten.' );
		$html = self::render( array( Overview_Page::class, 'render' ) );
		$this->assertStringContainsString( 'The UptimeWire connection ended.', $html );
		$this->assertStringContainsString( 'Reconnect', $html );

		Connection::check_health( true );
		$this->assertCount( 1, Fake_Api::calls( '/wp/v1/connection' ), 'An ended connection is not asked about again.' );
		$this->assertTrue( $this->start_connecting()['ok'] );
	}

	/**
	 * Scenario 22b: health is checked at most every five minutes; UptimeWire being down keeps the connection.
	 *
	 * @return void
	 */
	public function test_22b_health_check() {
		Connection::save( self::TOKEN, array( 'id' => 'x', 'connected_at' => '2026-11-23T08:37:39+00:00', 'site_url' => 'https://www.customer-site.com', 'local_url' => 'https://www.customer-site.com', 'connected_by' => 1 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		Connection::check_health();
		$this->assertCount( 0, Fake_Api::calls(), 'Fresh after connecting.' );

		Fake_Api::queue( '/wp/v1/connection', 0 );
		Connection::check_health( true );
		$this->assertSame( State::CONNECTION_ESTABLISHED, State::current() );
		$this->assertSame( Connection::HEALTH_UNREACHABLE, Connection::health_state( Connection::get() ) );
		$this->assertSame( self::TOKEN, Connection::token() );
		$html = self::render( array( Settings_Page::class, 'render' ) );
		$this->assertStringContainsString( 'Couldn’t reach UptimeWire just now. Last confirmed on', $html );
	}

	/**
	 * Scenario 23: no secret reaches a screen, a REST answer, the script data, Site Health or the log.
	 *
	 * @return void
	 */
	public function test_23_no_secret_leaks() {
		$seen = array();

		Fake_Api::queue( '/wp/v1/device', 200, self::device_answer() );
		$seen[] = wp_json_encode( rest_do_request( new \WP_REST_Request( 'POST', '/uptimewire/v1/connect-start' ) )->get_data() );
		$proof  = Authorization::pending()['proof'];
		$seen[] = self::render( array( Overview_Page::class, 'render' ) );
		$seen[] = self::render( array( Settings_Page::class, 'render' ) );
		self::make_due();
		Fake_Api::queue( '/wp/v1/token', 400, self::oauth_error( 'authorization_pending' ) );
		$seen[] = wp_json_encode( rest_do_request( new \WP_REST_Request( 'POST', '/uptimewire/v1/connect-status' ) )->get_data() );
		self::make_due();
		Fake_Api::queue( '/wp/v1/token', 200, self::token_answer() );
		$seen[] = wp_json_encode( rest_do_request( new \WP_REST_Request( 'POST', '/uptimewire/v1/connect-status' ) )->get_data() );
		$seen[] = self::render( array( Overview_Page::class, 'render' ) );
		$seen[] = self::render( array( Settings_Page::class, 'render' ) );

		\UptimeWire\Admin\Admin::register_menu();
		\UptimeWire\Admin\Admin::enqueue_styles( 'toplevel_page_uptimewire' );
		$seen[] = (string) wp_scripts()->get_data( 'uptimewire-connect', 'data' );

		if ( ! class_exists( 'WP_Debug_Data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-debug-data.php';
		}
		$seen[] = wp_json_encode( \WP_Debug_Data::debug_data() );

		$log = WP_CONTENT_DIR . '/debug.log';
		if ( file_exists( $log ) ) {
			$seen[] = (string) file_get_contents( $log ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$all = implode( "\n", $seen );
		$this->assertStringContainsString( 'WDJB-MJHT', $all, 'The test looked at real output.' );
		foreach ( array( self::DEVICE_CODE, self::TOKEN, $proof, 'uwd_', 'uwp_' ) as $secret ) {
			$this->assertStringNotContainsString( $secret, $all );
		}
		$this->assertStringNotContainsString( self::AUTHORIZATION_ID, $all, 'Not a secret, but the browser never needs it either.' );
	}

	/**
	 * Scenario 24: where the credential lives and what is cleaned when.
	 *
	 * @return void
	 */
	public function test_24_storage_and_cleanup() {
		global $wpdb;

		$this->start_connecting();
		$this->assertSame( array( '_transient_timeout_uptimewire_pending', '_transient_uptimewire_pending', 'uptimewire_site_url' ), self::plugin_rows() );
		$this->assertContains( self::autoload( '_transient_uptimewire_pending' ), array( 'no', 'off' ) );

		Fake_Api::queue( '/wp/v1/token', 200, self::token_answer() );
		self::make_due();
		Authorization::poll();

		$this->assertSame( array( Connection::OPTION, 'uptimewire_site_url' ), self::plugin_rows() );
		$this->assertContains( self::autoload( Connection::OPTION ), array( 'no', 'off' ), 'The credential is not loaded on every page.' );
		$this->assertSame( self::TOKEN, get_option( Connection::OPTION )['token'] );

		foreach ( array( 'access_denied', 'expired_token', 'invalid_grant' ) as $error ) {
			self::wipe();
			update_option( 'uptimewire_site_url', self::SITE, false );
			$this->start_connecting();
			Fake_Api::queue( '/wp/v1/token', 400, self::oauth_error( $error ) );
			self::make_due();
			Authorization::poll();
			$this->assertSame( array( 'uptimewire_outcome', 'uptimewire_site_url' ), self::plugin_rows(), $error );
			$this->assertStringNotContainsString( 'uwd_', self::plugin_rows_dump(), $error );
		}

		$this->assertSame( 0, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE 'uptimewire\\_lock\\_%'" ) ); // phpcs:ignore
	}

	/**
	 * Autoload value of an option row.
	 *
	 * @param string $name Option name.
	 * @return string
	 */
	private static function autoload( $name ) {
		global $wpdb;

		return (string) $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore
	}
}
