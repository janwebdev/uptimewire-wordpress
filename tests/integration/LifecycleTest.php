<?php
/**
 * WP-5: disconnect, reconnect, copied sites and the two capabilities.
 *
 * @package UptimeWire
 */

namespace UptimeWire\Tests;

use UptimeWire\Admin\Admin;
use UptimeWire\Admin\Overview_Page;
use UptimeWire\Admin\Settings_Page;
use UptimeWire\Admin\Site_Health;
use UptimeWire\Admin\Widget;
use UptimeWire\Authorization;
use UptimeWire\Connection;
use UptimeWire\Plugin;
use UptimeWire\Rest;
use UptimeWire\State;
use UptimeWire\Status;

/**
 * The connection lifecycle of WP-5.
 */
final class LifecycleTest extends Test_Case {

	/**
	 * Restores the WordPress address after the tests that change it.
	 *
	 * @var string
	 */
	private $home = '';

	/**
	 * Restores the WordPress Address after the tests that change it.
	 *
	 * @var string
	 */
	private $siteurl = '';

	/**
	 * Remembers the WordPress address.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->home    = (string) get_option( 'home' );
		$this->siteurl = (string) get_option( 'siteurl' );
	}

	/**
	 * Restores the WordPress address and the capabilities.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		update_option( 'home', $this->home );
		update_option( 'siteurl', $this->siteurl );
		remove_all_filters( 'uptimewire_capability' );
		remove_all_filters( 'uptimewire_manage_capability' );
		$_GET = array();
		parent::tearDown();
	}

	/**
	 * Runs a form handler of Admin as a POST with the given fields.
	 *
	 * @param string               $method Handler of Admin.
	 * @param string               $action Nonce action; '' sends no nonce, 'bad' a wrong one.
	 * @param array<string, mixed> $fields Other fields.
	 * @return string "redirect <url>" or "die <status>".
	 */
	private static function post( $method, $action, array $fields = array() ) {
		if ( '' !== $action ) {
			$fields['_wpnonce'] = 'bad' === $action ? 'badbadbad0' : wp_create_nonce( $action );
		}

		$_POST    = $fields;
		$_REQUEST = $fields;

		$redirect = static function ( $location ) {
			throw new \LogicException( $location ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test only.
		};
		$die      = static function () {
			return static function ( $message, $title, $args ) {
				throw new \RuntimeException( 'died', isset( $args['response'] ) ? (int) $args['response'] : 500 ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Test only.
			};
		};

		add_filter( 'wp_redirect', $redirect, 1 );
		add_filter( 'wp_die_handler', $die, 1 );

		try {
			call_user_func( array( Admin::class, $method ) );
			$result = 'returned';
		} catch ( \LogicException $e ) {
			$result = 'redirect ' . $e->getMessage();
		} catch ( \RuntimeException $e ) {
			$result = 'die ' . $e->getCode();
		} finally {
			remove_filter( 'wp_redirect', $redirect, 1 );
			remove_filter( 'wp_die_handler', $die, 1 );
			$_POST    = array();
			$_REQUEST = array();
		}

		return $result;
	}

	/**
	 * Stores a connection made on a given WordPress address.
	 *
	 * @param string $home WordPress address at connecting.
	 * @return void
	 */
	private static function connect_on( $home ) {
		Connection::save(
			self::TOKEN,
			array(
				'id'            => '0196a3b2-aaaa-7e3f-8a9b-0c1d2e3f4a5b',
				'connected_at'  => '2026-11-23T08:37:39+00:00',
				'site_url'      => 'https://www.customer-site.com',
				'local_url'     => 'https://www.customer-site.com',
				'home'          => $home,
				'home_key'      => hash( 'sha256', \UptimeWire\Site_Match::key( $home ) ),
				'connected_by'  => 1,
				'account_label' => 'ACC-00001000',
			)
		);
	}

	/**
	 * A migration tool replaced the old address with the new one everywhere in the
	 * database (wp search-replace, Duplicator, WP Migrate): the plain addresses of
	 * the record now name the copy, the fingerprint still names the original.
	 *
	 * @return void
	 */
	public function test_copy_after_search_replace() {
		update_option( 'home', 'https://staging.customer-site.com' );
		self::connect_on( 'https://www.customer-site.com/' );
		$record              = get_option( Connection::OPTION );
		$record['home']      = 'https://staging.customer-site.com/';
		$record['local_url'] = 'https://staging.customer-site.com';
		$record['site_url']  = 'https://staging.customer-site.com';
		update_option( Connection::OPTION, $record, false );
		// The "Website to monitor" option of a site behind a proxy is not touched by the replacement.
		update_option( 'uptimewire_site_url', 'https://www.customer-site.com/', false );

		$this->assertSame( State::SITE_COPY, State::current() );
		$this->assertSame( Status::NOT_CONNECTED, Status::refresh( true ) );
		$this->assertSame( array(), Fake_Api::calls() );
	}

	/**
	 * The Site Health results.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function health_results() {
		$tests   = Site_Health::tests( array( 'direct' => array(), 'async' => array() ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$results = array();

		foreach ( $tests['direct'] as $name => $test ) {
			$results[ $name ] = call_user_func( $test['test'] );
		}

		return $results;
	}

	/**
	 * Signs in an editor allowed to view (the "uptimewire_capability" filter widened).
	 *
	 * @return int User ID.
	 */
	private static function sign_in_viewer() {
		add_filter(
			'uptimewire_capability',
			static function () {
				return 'edit_posts';
			}
		);

		return self::sign_in_editor();
	}

	/**
	 * Disconnect: UptimeWire confirms, everything local is forgotten, the welcome screen comes back.
	 *
	 * @return void
	 */
	public function test_disconnect_revokes_and_forgets() {
		self::connect_on( home_url( '/' ) );
		self::read_status( self::status_answer() );
		Fake_Api::reset();
		Fake_Api::queue( '/wp/v1/revoke', 200, array() );

		$result = self::post( 'disconnect', Admin::DISCONNECT_ACTION );

		$this->assertSame( 'redirect ' . Plugin::overview_url( 'disconnected' ), $result );
		$calls = Fake_Api::calls();
		$this->assertCount( 1, $calls, 'One request: the revocation.' );
		$this->assertSame( '/wp/v1/revoke', $calls[0]['path'] );
		$this->assertSame( array( 'token' => self::TOKEN ), $calls[0]['args']['body'] );
		$this->assertSame( array( 'uptimewire_site_url' ), self::plugin_rows(), 'The credential, the kept status and the account number are gone.' );
		$this->assertSame( State::NOT_CONNECTED, State::current() );

		$_GET[ Admin::NOTICE_ARG ] = 'disconnected';
		$html                      = self::render( array( Overview_Page::class, 'render' ) );
		$this->assertStringContainsString( 'Keep Your WordPress Website Online', $html, 'The disconnected onboarding screen.' );
		$this->assertStringContainsString( 'UptimeWire keeps monitoring your website and sending the alerts you set up.', $html );
		$this->assertStringNotContainsString( 'ACC-00001000', $html );
		$this->assertStringNotContainsString( self::TOKEN, $html );
		$this->assertSame( array(), self::health_results(), 'Site Health is silent again.' );
	}

	/**
	 * Disconnect while UptimeWire can't be reached (no answer, 503, 429, 500): the credential is
	 * forgotten all the same, nothing claims it ended, and the way to end it is shown.
	 *
	 * @return void
	 */
	public function test_disconnect_api_unavailable() {
		foreach ( array( 0, 503, 429, 500 ) as $code ) {
			self::wipe();
			Fake_Api::reset();
			update_option( 'uptimewire_site_url', self::SITE, false );
			self::connect_on( home_url( '/' ) );
			Fake_Api::queue( '/wp/v1/revoke', $code, array(), array( 'retry-after' => '30' ) );

			$result = self::post( 'disconnect', Admin::DISCONNECT_ACTION );

			$this->assertSame( 'redirect ' . Plugin::overview_url( 'disconnect_unsure' ), $result, (string) $code );
			$this->assertCount( 1, Fake_Api::calls( '/wp/v1/revoke' ), 'No retry in the same request.' );
			$this->assertNull( Connection::get(), (string) $code );
			$this->assertSame( '', Connection::token() );
			$this->assertStringNotContainsString( self::TOKEN, self::plugin_rows_dump(), 'The credential is not kept for a retry.' );
			$this->assertSame( array( 'uptimewire_site_url', 'uptimewire_unconfirmed' ), self::plugin_rows() );
		}

		$_GET[ Admin::NOTICE_ARG ] = 'disconnect_unsure';
		$html                      = self::render( array( Overview_Page::class, 'render' ) );
		$this->assertStringContainsString( 'couldn’t be reached to confirm the end of the connection', $html );
		$this->assertStringContainsString( 'UptimeWire didn’t confirm the disconnect', $html );
		$this->assertStringContainsString( 'remove the WordPress connection of https://www.customer-site.com in UptimeWire account ACC-00001000, under My Profile › WordPress', $html );
		$this->assertStringContainsString( Overview_Page::CONNECTIONS, $html );
		$this->assertStringNotContainsString( 'was revoked', $html );
		$this->assertStringNotContainsString( self::TOKEN, $html );

		$settings = self::render( array( Settings_Page::class, 'render' ) );
		$this->assertStringContainsString( 'UptimeWire didn’t confirm the disconnect', $settings );

		$results = self::health_results();
		$this->assertSame( array( 'uptimewire_disconnect' ), array_keys( $results ) );
		$this->assertSame( 'recommended', $results['uptimewire_disconnect']['status'] );

		$this->assertSame( 'redirect ' . Plugin::overview_url(), self::post( 'dismiss_unconfirmed', Admin::DISMISS_UNCONFIRMED_ACTION ) );
		$this->assertNull( Connection::unconfirmed() );
		$this->assertSame( array(), self::health_results() );
	}

	/**
	 * Disconnect without a valid nonce, or by a user who may not manage: nothing changes, nothing is sent.
	 *
	 * @return void
	 */
	public function test_disconnect_needs_nonce_and_capability() {
		self::connect_on( home_url( '/' ) );

		$this->assertSame( 'die 403', self::post( 'disconnect', 'bad' ), 'Wrong nonce.' );
		$this->assertSame( 'die 403', self::post( 'disconnect', '' ), 'No nonce.' );
		$this->assertSame( 'die 403', self::post( 'disconnect', Admin::CONNECT_ACTION ), 'Nonce of another action.' );

		self::sign_in_editor();
		$this->assertSame( 'die 403', self::post( 'disconnect', Admin::DISCONNECT_ACTION ), 'Editor.' );

		self::sign_in_viewer();
		$this->assertSame( 'die 403', self::post( 'disconnect', Admin::DISCONNECT_ACTION ), 'Editor allowed to view only.' );

		wp_set_current_user( 0 );
		$this->assertSame( 'die 403', self::post( 'disconnect', Admin::DISCONNECT_ACTION ), 'Signed out.' );

		$this->assertSame( array(), Fake_Api::calls() );
		$this->assertSame( self::TOKEN, Connection::token() );
		$this->assertNotNull( Connection::get() );
	}

	/**
	 * A second disconnect (another tab, a double click) sends nothing and says the same.
	 *
	 * @return void
	 */
	public function test_duplicate_disconnect() {
		self::connect_on( home_url( '/' ) );
		Fake_Api::queue( '/wp/v1/revoke', 200, array() );

		self::post( 'disconnect', Admin::DISCONNECT_ACTION );
		$second = self::post( 'disconnect', Admin::DISCONNECT_ACTION );

		$this->assertSame( 'redirect ' . Plugin::overview_url( 'disconnected' ), $second );
		$this->assertCount( 1, Fake_Api::calls( '/wp/v1/revoke' ) );
		$this->assertSame( array( 'uptimewire_site_url' ), self::plugin_rows() );
	}

	/**
	 * A credential UptimeWire already ended: nothing to revoke, the local data is cleared.
	 *
	 * @return void
	 */
	public function test_disconnect_already_revoked() {
		self::connect_on( home_url( '/' ) );
		self::read_status( array( 'error' => array( 'code' => 'connection_ended' ) ), 401 );
		$this->assertSame( State::RECONNECTION_REQUIRED, State::current() );
		Fake_Api::reset();

		$html = self::render( array( Settings_Page::class, 'render' ) );
		$this->assertStringContainsString( 'uptimewire_confirm=disconnect', $html, 'Disconnect is offered for an ended connection too.' );

		$this->assertSame( 'redirect ' . Plugin::overview_url( 'disconnected' ), self::post( 'disconnect', Admin::DISCONNECT_ACTION ) );
		$this->assertSame( array(), Fake_Api::calls(), 'No credential is left to end.' );
		$this->assertSame( array( 'uptimewire_site_url' ), self::plugin_rows() );
	}

	/**
	 * UptimeWire answers 200 for any credential (RFC 7009): a credential it no longer knows counts as ended.
	 *
	 * @return void
	 */
	public function test_disconnect_unknown_credential_is_ended() {
		self::connect_on( home_url( '/' ) );
		Fake_Api::queue( '/wp/v1/revoke', 200, '{}' );

		$result = Connection::disconnect();

		$this->assertSame( Connection::REVOKED, $result['remote'] );
		$this->assertFalse( $result['constant'] );
	}

	/**
	 * Disconnect forgets an authorization in progress; a code approved later is ended, not saved.
	 *
	 * @return void
	 */
	public function test_disconnect_clears_pending_authorization() {
		self::connect_on( home_url( '/' ) );
		self::read_status( array( 'error' => array( 'code' => 'connection_ended' ) ), 401 );
		$this->assertTrue( $this->start_connecting()['ok'] );
		$pending = Authorization::pending();

		self::post( 'disconnect', Admin::DISCONNECT_ACTION );

		$this->assertNull( Authorization::pending() );
		$this->assertNull( Authorization::outcome() );
		$this->assertSame( State::NOT_CONNECTED, State::current() );

		// The approval UptimeWire answers for that code later belongs to nobody: it is ended at once.
		set_transient( Authorization::TRANSIENT, $pending, 900 );
		self::make_due();
		set_transient( Authorization::CANCELLED_TRANSIENT, $pending['authorization_id'], 900 );
		Fake_Api::queue( '/wp/v1/token', 200, self::token_answer() );
		Fake_Api::queue( '/wp/v1/revoke', 200, array() );
		Authorization::poll();
		$this->assertCount( 1, Fake_Api::calls( '/wp/v1/revoke' ) );
		$this->assertSame( '', Connection::token() );
	}

	/**
	 * The confirmation: the words of the plan, Cancel and Disconnect; only for those who manage.
	 *
	 * @return void
	 */
	public function test_confirmation_screen() {
		self::connect_on( home_url( '/' ) );
		self::read_status( self::status_answer() );

		$settings = self::render( array( Settings_Page::class, 'render' ) );
		$this->assertStringContainsString( 'uptimewire_confirm=disconnect', $settings );
		$this->assertStringContainsString( 'Monitoring and alerts in UptimeWire continue.', $settings );
		$this->assertStringNotContainsString( 'Stop Monitoring', $settings );

		$_GET[ Admin::CONFIRM_ARG ] = 'disconnect';
		$html                       = self::render( array( Settings_Page::class, 'render' ) );
		$this->assertStringContainsString( 'Disconnect UptimeWire?', $html );
		$this->assertStringContainsString( 'This WordPress website will stop displaying monitoring results.', $html );
		$this->assertStringContainsString( 'UptimeWire will continue monitoring your website and sending configured alerts.', $html );
		$this->assertStringContainsString( 'Your UptimeWire account, monitoring history, and website settings will remain unchanged.', $html );
		$this->assertMatchesRegularExpression( '/>Cancel<\/a>\s*<button type="submit"[^>]*>Disconnect<\/button>/', $html );
		$this->assertStringContainsString( 'value="' . Admin::DISCONNECT_ACTION . '"', $html );
		$this->assertStringNotContainsString( 'subscription', $html );
		$this->assertStringNotContainsString( 'Stop Monitoring', $html );
		$this->assertSame( array( '/wp/v1/status' ), array_column( Fake_Api::calls(), 'path' ), 'Showing the confirmation sends nothing.' );

		self::sign_in_viewer();
		$viewer = self::render( array( Settings_Page::class, 'render' ) );
		$this->assertStringNotContainsString( 'Disconnect UptimeWire?', $viewer );
		$this->assertStringNotContainsString( 'uptimewire_confirm=disconnect', $viewer );
		$this->assertStringContainsString( '<h2>Monitoring</h2>', $viewer, 'A viewer sees the settings, without the actions.' );
	}

	/**
	 * Reconnecting after the connection ended: same account, a fresh credential, the old notice gone.
	 *
	 * @return void
	 */
	public function test_reconnect_after_ended_connection() {
		self::connect_on( home_url( '/' ) );
		self::read_status( array( 'error' => array( 'code' => 'connection_ended' ) ), 401 );
		update_option( Connection::UNCONFIRMED_OPTION, array( 'site_url' => 'https://www.customer-site.com', 'account_label' => 'ACC-00001000', 'at' => time() ), false ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		$html = self::render( array( Overview_Page::class, 'render' ) );
		$this->assertStringContainsString( 'Reconnect to UptimeWire', $html );
		$this->assertStringContainsString( 'This site was connected to UptimeWire account ACC-00001000.', $html );
		$this->assertStringNotContainsString( 'expired', strtolower( wp_strip_all_tags( $html ) ), 'The cause is not known, so it is not called an expiry.' );

		$this->assertTrue( $this->start_connecting()['ok'] );
		$answer                                = self::token_answer();
		$answer['connection']['account_label'] = 'ACC-00001000';
		$answer['access_token']                = 'uwp_' . str_repeat( 'ab', 32 );
		Fake_Api::queue( '/wp/v1/token', 200, $answer );
		self::make_due();
		Authorization::poll();

		$this->assertSame( State::CONNECTION_ESTABLISHED, State::current() );
		$this->assertSame( 'uwp_' . str_repeat( 'ab', 32 ), Connection::token(), 'The new credential, never the revoked one.' );
		$this->assertSame( home_url( '/' ), Connection::get()['home'], 'The WordPress address is recorded.' );
		$this->assertSame( Connection::home_key(), Connection::get()['home_key'] );
		$this->assertNull( Connection::unconfirmed(), 'A new connection replaces the old one in UptimeWire.' );
		$this->assertNull( Authorization::take_account_change() );
	}

	/**
	 * Reconnecting in another UptimeWire account is never silent.
	 *
	 * @return void
	 */
	public function test_reconnect_in_another_account_is_said() {
		self::connect_on( home_url( '/' ) );
		self::read_status( array( 'error' => array( 'code' => 'connection_ended' ) ), 401 );
		$this->start_connecting();
		$answer                                = self::token_answer();
		$answer['connection']['account_label'] = 'ACC-00002000';
		Fake_Api::queue( '/wp/v1/token', 200, $answer );
		self::make_due();
		Authorization::poll();

		$this->assertSame( State::CONNECTION_ESTABLISHED, State::current() );
		Fake_Api::queue( '/wp/v1/status', 0 );
		$html = self::render( array( Overview_Page::class, 'render' ) );
		$this->assertStringContainsString( 'This site is now connected to UptimeWire account ACC-00002000. Before, it was connected to account ACC-00001000.', $html );
		$this->assertStringNotContainsString( 'Before, it was connected', self::render( array( Overview_Page::class, 'render' ) ), 'Said once.' );
	}

	/**
	 * A reconnection whose code expires keeps nothing of the old credential.
	 *
	 * @return void
	 */
	public function test_reconnect_with_expired_code() {
		self::connect_on( home_url( '/' ) );
		self::read_status( array( 'error' => array( 'code' => 'connection_ended' ) ), 401 );
		$this->start_connecting();
		Fake_Api::queue( '/wp/v1/token', 400, self::oauth_error( 'expired_token' ) );
		self::make_due();
		Authorization::poll();

		$this->assertSame( State::AUTHORIZATION_EXPIRED, State::current() );
		$this->assertSame( '', Connection::token() );
		Authorization::clear_outcome();
		$this->assertSame( State::RECONNECTION_REQUIRED, State::current(), 'Back to reconnecting, not to a connection.' );
	}

	/**
	 * The WordPress address decides whether this is the installation the connection was made on.
	 *
	 * @return void
	 */
	public function test_identity_rules() {
		$cases = array(
			// Recorded at connecting, address now, is it a copy, why.
			array( 'https://www.customer-site.com/', 'https://staging.customer-site.com', true, 'production to staging' ),
			array( 'https://staging.customer-site.com/', 'https://www.customer-site.com', true, 'staging to production' ),
			array( 'https://www.customer-site.com/', 'https://www.other-site.com', true, 'another domain' ),
			array( 'https://www.customer-site.com/', 'https://customer-site.com', true, 'www to non-www is another website' ),
			array( 'https://www.customer-site.com/', 'https://www.customer-site.com/blog', true, 'a subdirectory is another website' ),
			array( 'https://www.customer-site.com/shop/', 'https://www.customer-site.com/shop', false, 'the same subdirectory' ),
			array( 'https://www.customer-site.com/', 'http://www.customer-site.com', false, 'http and https are the same website' ),
			array( 'http://www.customer-site.com/', 'https://www.customer-site.com', false, 'moving to https is not a copy' ),
			array( 'https://WWW.Customer-Site.com/', 'https://www.customer-site.com', false, 'upper case in the host' ),
			array( 'https://www.customer-site.com/', 'https://www.customer-site.com', false, 'a backup restored on the same address' ),
		);

		foreach ( $cases as $case ) {
			update_option( 'home', $case[1] );
			self::connect_on( $case[0] );

			$this->assertSame( $case[2], Connection::is_copy( Connection::get() ), $case[3] );
			$this->assertSame( $case[2] ? State::SITE_COPY : State::CONNECTION_ESTABLISHED, State::current(), $case[3] );
		}
	}

	/**
	 * A copy (production database on staging, with the "Website to monitor" option copied too):
	 * no data, no request with the credential, no account number; local-only removal.
	 *
	 * @return void
	 */
	public function test_copy_shows_nothing_and_removes_locally() {
		self::connect_on( home_url( '/' ) );
		self::read_status( self::status_answer() );
		$this->assertSame( State::CONNECTION_ESTABLISHED, State::current() );
		// The database moves to a site with another address.
		update_option( 'home', 'https://staging.customer-site.com' );
		update_option( 'siteurl', 'https://staging.customer-site.com' );
		Fake_Api::reset();

		$this->assertSame( State::SITE_COPY, State::current() );
		$this->assertSame( Status::NOT_CONNECTED, Status::refresh( true ) );
		Connection::check_health( true );
		$this->assertSame( array(), Fake_Api::calls(), 'The copy never sends the original site’s credential.' );

		$overview = self::render( array( Overview_Page::class, 'render' ) );
		$this->assertStringContainsString( 'This WordPress Site Is a Copy', $overview );
		$this->assertStringContainsString( 'The existing UptimeWire connection belongs to another website.', $overview );
		$this->assertStringContainsString( 'Remove Connection From This Copy', $overview );
		$this->assertStringNotContainsString( 'ACC-00001000', $overview );
		$this->assertStringNotContainsString( 'Your Website Is Online', $overview );
		$this->assertStringNotContainsString( 'value="' . Admin::CONNECT_ACTION . '"', $overview, 'No automatic or offered reconnect from the copy screen.' );

		$widget = self::render( array( Widget::class, 'render_inner' ) );
		$this->assertStringContainsString( 'This WordPress Site Is a Copy', $widget );
		$this->assertStringNotContainsString( 'Online', $widget );

		$settings = self::render( array( Settings_Page::class, 'render' ) );
		$this->assertStringNotContainsString( 'uptimewire_confirm=disconnect', $settings, 'A copy can’t disconnect the original.' );
		$this->assertStringNotContainsString( 'ACC-00001000', $settings );

		$results = self::health_results();
		$this->assertSame( array( 'uptimewire_connection' ), array_keys( $results ) );
		$this->assertSame( 'This site has a copied UptimeWire connection', $results['uptimewire_connection']['label'] );
		$info = Site_Health::info( array() );
		$this->assertArrayNotHasKey( 'account', $info['uptimewire']['fields'] );
		$this->assertStringNotContainsString( 'ACC-', wp_json_encode( $info ) );

		// Disconnect is refused even when asked for directly.
		$this->assertSame( 'redirect ' . Plugin::overview_url( 'disconnect_refused' ), self::post( 'disconnect', Admin::DISCONNECT_ACTION ) );
		$this->assertSame( array(), Fake_Api::calls() );

		$this->assertSame( 'redirect ' . Plugin::overview_url( 'copy_removed' ), self::post( 'remove_local', Admin::REMOVE_LOCAL_ACTION ) );
		$this->assertSame( array(), Fake_Api::calls(), 'The original connection stays active: nothing is revoked.' );
		$this->assertSame( array( 'uptimewire_site_url' ), self::plugin_rows() );
		$this->assertSame( State::NOT_CONNECTED, State::current() );
	}

	/**
	 * Local removal is refused for the installation's own connection: that one is disconnected, so it also ends in UptimeWire.
	 *
	 * @return void
	 */
	public function test_remove_local_only_for_copies() {
		self::connect_on( home_url( '/' ) );

		$this->assertSame( 'redirect ' . Plugin::settings_url(), self::post( 'remove_local', Admin::REMOVE_LOCAL_ACTION ) );
		$this->assertSame( self::TOKEN, Connection::token() );

		$this->assertSame( 'die 403', self::post( 'remove_local', 'bad' ) );
		self::sign_in_viewer();
		$this->assertSame( 'die 403', self::post( 'remove_local', Admin::REMOVE_LOCAL_ACTION ) );
	}

	/**
	 * A connection UptimeWire says is another website's (the address changed in UptimeWire):
	 * removable here only, never ended from here.
	 *
	 * @return void
	 */
	public function test_mismatch_is_removed_locally() {
		self::connect_on( home_url( '/' ) );
		self::read_status( self::status_answer( array( 'url' => 'https://www.other-site.com' ) ) );
		$this->assertSame( State::CONNECTION_ERROR, State::current() );
		Fake_Api::reset();

		$html = self::render( array( Overview_Page::class, 'render' ) );
		$this->assertStringContainsString( 'Remove Connection From This Site', $html );
		$this->assertSame( 'redirect ' . Plugin::overview_url( 'disconnect_refused' ), self::post( 'disconnect', Admin::DISCONNECT_ACTION ) );
		$this->assertSame( 'redirect ' . Plugin::overview_url( 'copy_removed' ), self::post( 'remove_local', Admin::REMOVE_LOCAL_ACTION ) );
		$this->assertSame( array(), Fake_Api::calls() );
		$this->assertNull( Connection::get() );
	}

	/**
	 * A database restored on the same address with a credential UptimeWire revoked since: not reused.
	 *
	 * @return void
	 */
	public function test_restored_backup_with_revoked_credential() {
		self::connect_on( home_url( '/' ) );
		$this->assertSame( State::CONNECTION_ESTABLISHED, State::current(), 'The same address: the same site.' );

		Fake_Api::queue( '/wp/v1/connection', 401, array( 'error' => array( 'code' => 'connection_ended' ) ) );
		Connection::check_health( true );

		$this->assertSame( State::RECONNECTION_REQUIRED, State::current() );
		$this->assertSame( '', Connection::token() );
	}

	/**
	 * A connection made before WP-5 (no WordPress address recorded) gets it on the first confirmed read.
	 *
	 * @return void
	 */
	public function test_older_connection_records_its_address() {
		// Monitored at the WordPress address itself: UptimeWire's answer proves the address.
		update_option( 'uptimewire_site_url', home_url( '/' ), false );
		Connection::save( self::TOKEN, array( 'id' => 'x', 'site_url' => home_url( '/' ), 'local_url' => home_url( '/' ), 'connected_by' => 1 ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertArrayNotHasKey( 'home_key', Connection::get() );

		self::read_status( self::status_answer( array( 'url' => untrailingslashit( home_url( '/' ) ) ) ) );

		$this->assertSame( home_url( '/' ), Connection::get()['home'] );
		$this->assertTrue( Connection::is_verified( Connection::get() ) );
	}

	/**
	 * An older connection monitored at another address ("Website to monitor") is never proven by
	 * UptimeWire's answer: a copy keeps that option. It is only forgotten here, never ended there.
	 *
	 * @return void
	 */
	public function test_older_connection_on_a_custom_address_stays_unproven() {
		self::connect_site();
		self::read_status( self::status_answer() );

		$this->assertSame( State::CONNECTION_ESTABLISHED, State::current() );
		$this->assertFalse( Connection::is_verified( Connection::get() ) );
		Fake_Api::reset();

		$_GET[ Admin::CONFIRM_ARG ] = 'disconnect';
		$this->assertStringContainsString( 'UptimeWire hasn’t confirmed yet that this connection belongs to this WordPress address', self::render( array( Settings_Page::class, 'render' ) ) );
		$this->assertSame( 'redirect ' . Plugin::overview_url( 'disconnect_unsure' ), self::post( 'disconnect', Admin::DISCONNECT_ACTION ) );
		$this->assertSame( array(), Fake_Api::calls(), 'Nothing is ended in UptimeWire.' );
		$this->assertNull( Connection::get() );
		$this->assertNotNull( Connection::unconfirmed() );
	}

	/**
	 * A failed check never turns "another website" back into a connection that could be ended.
	 *
	 * @return void
	 */
	public function test_mismatch_survives_a_failed_check() {
		self::connect_on( home_url( '/' ) );
		self::read_status( self::status_answer( array( 'url' => 'https://www.other-site.com' ) ) );
		Fake_Api::queue( '/wp/v1/connection', 0 );
		Fake_Api::queue( '/wp/v1/connection', 503, array() );

		Connection::check_health( true );
		Connection::check_health( true );

		$this->assertSame( Connection::HEALTH_MISMATCH, Connection::health_state( Connection::get() ) );
		$this->assertSame( 'redirect ' . Plugin::overview_url( 'disconnect_refused' ), self::post( 'disconnect', Admin::DISCONNECT_ACTION ) );
		$this->assertCount( 0, Fake_Api::calls( '/wp/v1/revoke' ) );
	}

	/**
	 * A disconnect made by another request while a health check waits for UptimeWire is not undone.
	 *
	 * @return void
	 */
	public function test_health_check_does_not_bring_back_a_disconnected_connection() {
		self::connect_on( home_url( '/' ) );
		Connection::get();
		$disconnect_meanwhile = static function ( $pre, $args, $url ) {
			global $wpdb;
			if ( false !== strpos( $url, '/wp/v1/connection' ) ) {
				// Another request: the row goes, this request's cached copy stays.
				$wpdb->delete( $wpdb->options, array( 'option_name' => Connection::OPTION ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}

			return $pre;
		};
		add_filter( 'pre_http_request', $disconnect_meanwhile, 0, 3 );
		Fake_Api::queue( '/wp/v1/connection', 200, array( 'data' => array( 'id' => 'x', 'site' => array( 'url' => 'https://www.customer-site.com' ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound

		Connection::check_health( true );
		remove_filter( 'pre_http_request', $disconnect_meanwhile, 0 );

		Connection::forget_cached();
		$this->assertFalse( get_option( Connection::OPTION ), 'The credential is not written back.' );
	}

	/**
	 * The credential is gone from here (UPTIMEWIRE_TOKEN removed) though UptimeWire never ended it:
	 * the disconnect says it is not confirmed, never "already ended".
	 *
	 * @return void
	 */
	public function test_missing_credential_is_not_called_ended() {
		self::connect_on( home_url( '/' ) );
		$record = get_option( Connection::OPTION );
		unset( $record['token'] );
		update_option( Connection::OPTION, $record, false );

		$result = Connection::disconnect();

		$this->assertSame( Connection::UNCONFIRMED, $result['remote'] );
		$this->assertSame( array(), Fake_Api::calls() );
	}

	/**
	 * A copy made while connecting (the pending authorization copied, its address rewritten by the
	 * migration tool) neither asks for the original's code nor answers its proof.
	 *
	 * @return void
	 */
	public function test_copy_made_while_connecting() {
		$this->start_connecting();
		$pending = Authorization::pending();
		update_option( 'home', 'https://staging.customer-site.com' );
		self::make_due();
		Fake_Api::reset();

		Authorization::poll();

		$this->assertSame( array(), Fake_Api::calls(), 'The original\'s device code is not used.' );
		$this->assertSame( 'site_changed', Authorization::outcome()['reason'] );
		$this->assertFalse( Authorization::is_here( $pending ) );
	}

	/**
	 * A new connection to another account leaves the unconfirmed disconnect notice: nothing replaced that credential.
	 *
	 * @return void
	 */
	public function test_unconfirmed_notice_stays_for_another_account() {
		update_option( Connection::UNCONFIRMED_OPTION, array( 'site_url' => 'https://www.customer-site.com', 'account_label' => 'ACC-00001000', 'at' => time() ), false ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		Connection::save( self::TOKEN, Connection::identity() + array( 'id' => 'x', 'site_url' => 'https://www.customer-site.com', 'local_url' => self::SITE, 'account_label' => 'ACC-00002000' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertNotNull( Connection::unconfirmed() );

		Connection::save( self::TOKEN, Connection::identity() + array( 'id' => 'x', 'site_url' => 'https://www.customer-site.com', 'local_url' => self::SITE, 'account_label' => 'ACC-00001000' ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound
		$this->assertNull( Connection::unconfirmed(), 'The same website and account: replaced in UptimeWire.' );
	}

	/**
	 * The website to monitor can't change while a connection is stored.
	 *
	 * @return void
	 */
	public function test_site_url_locked_while_connected() {
		self::connect_on( home_url( '/' ) );

		$result = self::post( 'save_site_url', Admin::SAVE_SITE_URL_ACTION, array( 'uptimewire_site_url' => 'https://www.other-site.com/' ) );

		$this->assertSame( 'redirect ' . add_query_arg( Admin::NOTICE_ARG, 'site_url_locked', Plugin::overview_url() ), $result );
		$this->assertSame( self::SITE, get_option( 'uptimewire_site_url' ) );
		$this->assertSame( 'redirect ' . add_query_arg( Admin::NOTICE_ARG, 'site_url_locked', Plugin::overview_url() ), self::post( 'save_site_url', Admin::SAVE_SITE_URL_ACTION, array( 'uptimewire_reset' => '1' ) ) );
		$this->assertSame( self::SITE, get_option( 'uptimewire_site_url' ) );
	}

	/**
	 * Default: administrators view and manage; editors neither.
	 *
	 * @return void
	 */
	public function test_default_capabilities() {
		$this->assertSame( 'manage_options', Plugin::capability() );
		$this->assertSame( 'manage_options', Plugin::manage_capability() );
		$this->assertTrue( Plugin::can_view() );
		$this->assertTrue( Plugin::can_manage() );

		self::connect_on( home_url( '/' ) );
		self::read_status( self::status_answer() );
		self::sign_in_editor();
		$this->assertFalse( Plugin::can_view() );
		$this->assertFalse( Plugin::can_manage() );
		Fake_Api::reset();

		$this->assertSame( 403, rest_do_request( new \WP_REST_Request( 'POST', '/' . Rest::NAMESPACE_V1 . '/status-refresh' ) )->get_status() );
		$this->assertSame( array( 'direct' => array() ), Site_Health::tests( array( 'direct' => array() ) ), 'No cached data in Site Health.' );
		$this->assertSame( array(), Site_Health::info( array() ) );
		$this->assertSame( 'die 403', self::post( 'refresh', Admin::REFRESH_ACTION ) );
		$this->assertSame( array(), Fake_Api::calls() );
	}

	/**
	 * A role allowed to view sees the data and nothing to manage; it can't connect, disconnect or change the website.
	 *
	 * @return void
	 */
	public function test_viewer_can_view_but_not_manage() {
		self::connect_on( home_url( '/' ) );
		self::read_status( self::status_answer() );
		self::sign_in_viewer();

		$this->assertTrue( Plugin::can_view() );
		$this->assertFalse( Plugin::can_manage(), 'Viewing never grants managing.' );

		Fake_Api::reset();
		$this->assertSame( 200, rest_do_request( new \WP_REST_Request( 'POST', '/' . Rest::NAMESPACE_V1 . '/status-refresh' ) )->get_status() );
		$this->assertStringContainsString( 'Your Website Is Online', self::render( array( Overview_Page::class, 'render' ) ) );
		$this->assertStringContainsString( 'Your Website Is Online', self::render( array( Widget::class, 'render_inner' ) ) );
		$this->assertArrayHasKey( 'uptimewire_connection', self::health_results() );
		$this->assertStringNotContainsString( 'Manage or disconnect', self::render( array( Overview_Page::class, 'render' ) ) );

		foreach ( array( '/connect-start', '/connect-status' ) as $route ) {
			$this->assertSame( 403, rest_do_request( new \WP_REST_Request( 'POST', '/' . Rest::NAMESPACE_V1 . $route ) )->get_status(), $route );
		}

		$forms = array(
			'connect'             => Admin::CONNECT_ACTION,
			'cancel'              => Admin::CANCEL_ACTION,
			'check'               => Admin::CHECK_ACTION,
			'dismiss'             => Admin::DISMISS_ACTION,
			'disconnect'          => Admin::DISCONNECT_ACTION,
			'remove_local'        => Admin::REMOVE_LOCAL_ACTION,
			'dismiss_unconfirmed' => Admin::DISMISS_UNCONFIRMED_ACTION,
			'save_site_url'       => Admin::SAVE_SITE_URL_ACTION,
		);

		foreach ( $forms as $method => $action ) {
			$this->assertSame( 'die 403', self::post( $method, $action, array( 'uptimewire_site_url' => 'https://www.other-site.com/' ) ), $method );
		}

		$this->assertSame( array(), Fake_Api::calls(), 'The kept status was fresh; nothing else may be asked.' );
		$this->assertSame( self::TOKEN, Connection::token() );
		$this->assertSame( self::SITE, get_option( 'uptimewire_site_url' ) );
		$this->assertStringStartsWith( 'redirect ', self::post( 'refresh', Admin::REFRESH_ACTION ), 'Reading again is viewing.' );
	}

	/**
	 * A viewer on a site that isn't connected sees the state, no connect buttons, no address form, no connect script.
	 *
	 * @return void
	 */
	public function test_viewer_cannot_connect() {
		self::sign_in_viewer();

		$html = self::render( array( Overview_Page::class, 'render' ) );
		$this->assertStringContainsString( 'Only an administrator of this site can connect it to UptimeWire', $html );
		$this->assertStringNotContainsString( 'value="' . Admin::CONNECT_ACTION . '"', $html );
		$this->assertStringNotContainsString( 'value="' . Admin::SAVE_SITE_URL_ACTION . '"', $html );

		wp_dequeue_script( 'uptimewire-connect' );
		Admin::register_menu();
		$hooks = (array) get_plugin_page_hookname( 'uptimewire', '' );
		Admin::enqueue_styles( $hooks[0] );
		$this->assertFalse( wp_script_is( 'uptimewire-connect', 'enqueued' ) );

		$widget = self::render( array( Widget::class, 'render_inner' ) );
		$this->assertStringNotContainsString( 'Set Up Monitoring', $widget );
		$this->assertSame( array(), Fake_Api::calls() );
	}

	/**
	 * A narrower management capability: administrators still view, but can't manage.
	 *
	 * @return void
	 */
	public function test_management_capability_can_be_narrowed() {
		add_filter(
			'uptimewire_manage_capability',
			static function () {
				return 'uptimewire_manage_connection';
			}
		);
		self::connect_on( home_url( '/' ) );

		$this->assertTrue( Plugin::can_view() );
		$this->assertFalse( Plugin::can_manage() );
		$this->assertSame( 'die 403', self::post( 'disconnect', Admin::DISCONNECT_ACTION ) );
		$this->assertStringNotContainsString( 'uptimewire_confirm=disconnect', self::render( array( Settings_Page::class, 'render' ) ) );
	}

	/**
	 * A wider management capability never lets someone manage who may not view.
	 *
	 * @return void
	 */
	public function test_management_capability_needs_viewing() {
		add_filter(
			'uptimewire_manage_capability',
			static function () {
				return 'edit_posts';
			}
		);
		self::sign_in_editor();

		$this->assertFalse( Plugin::can_view() );
		$this->assertFalse( Plugin::can_manage() );
	}
}
