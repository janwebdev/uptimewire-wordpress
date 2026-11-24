<?php
/**
 * Tools › Site Health: UptimeWire tests and the Info section.
 *
 * @package UptimeWire
 */

namespace UptimeWire\Admin;

use UptimeWire\Connection;
use UptimeWire\Plugin;
use UptimeWire\State;
use UptimeWire\Status;
use UptimeWire\Status_Data;

defined( 'ABSPATH' ) || exit;

/**
 * Reports what the kept status says, through the official Site Health API.
 *
 * Every test is a direct test that only reads what WordPress already keeps: no
 * request to UptimeWire and no check of its own. Data that is merely missing or
 * old is reported as such, never as a problem of the website. Before the site
 * is connected, Site Health shows nothing of UptimeWire but the Info section.
 *
 * Tests and Info are added only for users with the plugin's capability, so the
 * scheduled Site Health check (it runs without a user) never includes them.
 */
final class Site_Health {

	/** Info section key. */
	const SECTION = 'uptimewire';

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'site_status_tests', array( __CLASS__, 'tests' ) );
		add_filter( 'debug_information', array( __CLASS__, 'info' ) );
	}

	/**
	 * Adds the UptimeWire tests that have something to report.
	 *
	 * @param array<string, mixed> $tests Site Health tests.
	 * @return array<string, mixed>
	 */
	public static function tests( $tests ) {
		if ( ! is_array( $tests ) || ! Plugin::can_view() ) {
			return $tests;
		}

		$add = array();

		if ( null !== Connection::unconfirmed() ) {
			$add['disconnect'] = __( 'UptimeWire disconnect', 'uptimewire' );
		}

		$state = null === Connection::get() ? State::NOT_CONNECTED : State::current();

		if ( State::CONNECTION_ESTABLISHED === $state ) {
			$add['connection'] = __( 'UptimeWire connection', 'uptimewire' );
			$add['monitoring'] = __( 'UptimeWire monitoring', 'uptimewire' );
			$data              = Status::data();

			if ( null !== $data ) {
				if ( in_array( $data['ssl']['state'], array( 'ok', 'problem' ), true ) ) {
					$add['ssl'] = __( 'SSL certificate (UptimeWire)', 'uptimewire' );
				}
				if ( null !== self::domain_verdict( $data['domain'] ) ) {
					// An unknown expiration date is neither a pass nor a problem: it is in Info, not here.
					$add['domain'] = __( 'Domain expiration (UptimeWire)', 'uptimewire' );
				}
				if ( null !== self::access_result() ) {
					$add['access'] = __( 'UptimeWire can reach the website', 'uptimewire' );
				}
				// Only from current data: an out-of-date status passes nothing. No email on the domain, a job not
				// connected and advice are not results: they are in Info.
				if ( ! Status::is_stale( Status::get() ) ) {
					if ( isset( $data['mail']['state'] ) && in_array( $data['mail']['state'], array( 'ok', 'problem' ), true ) ) {
						$add['mail'] = __( 'Email domain (UptimeWire)', 'uptimewire' );
					}
					if ( isset( $data['scheduled_job']['state'] ) && in_array( $data['scheduled_job']['state'], array( 'on_time', 'late', 'failed' ), true ) ) {
						$add['scheduled_job'] = __( 'Scheduled job (UptimeWire)', 'uptimewire' );
					}
				}
			}
		} elseif ( in_array( $state, array( State::RECONNECTION_REQUIRED, State::CONNECTION_ERROR, State::SITE_COPY ), true ) && null === \UptimeWire\Authorization::outcome() ) {
			// A connection that stopped working. An attempt in progress or just failed is not reported.
			$add['connection'] = __( 'UptimeWire connection', 'uptimewire' );
		}

		foreach ( $add as $name => $label ) {
			$tests['direct'][ 'uptimewire_' . $name ] = array(
				'label' => $label,
				'test'  => array( __CLASS__, 'test_' . $name ),
			);
		}

		return $tests;
	}

	/**
	 * Is WordPress connected to UptimeWire?
	 *
	 * @return array<string, mixed>
	 */
	public static function test_connection() {
		$state  = State::current();
		$record = Connection::get();
		$health = null === $record ? '' : Connection::health_state( $record );

		if ( State::CONNECTION_ESTABLISHED === $state ) {
			return self::result(
				'connection',
				'good',
				__( 'Your website is connected to UptimeWire', 'uptimewire' ),
				array(
					/* translators: %s: website address. */
					sprintf( __( 'WordPress shows UptimeWire’s monitoring results for %s.', 'uptimewire' ), self::monitored_url() ),
				)
			);
		}

		if ( State::RECONNECTION_REQUIRED === $state ) {
			return self::result(
				'connection',
				'recommended',
				__( 'The UptimeWire connection is no longer active', 'uptimewire' ),
				array(
					__( 'WordPress can’t show monitoring results until the site is reconnected. UptimeWire doesn’t tell WordPress why the connection ended. This is not a problem with your website.', 'uptimewire' ),
				),
				self::link( Plugin::overview_url(), __( 'Reconnect', 'uptimewire' ) )
			);
		}

		if ( State::SITE_COPY === $state ) {
			return self::result(
				'connection',
				'recommended',
				__( 'This site has a copied UptimeWire connection', 'uptimewire' ),
				array(
					__( 'The connection stored here was made on another WordPress address, so this site is a copy or moved. It doesn’t use the connection or show its monitoring data.', 'uptimewire' ),
				),
				self::link( Plugin::overview_url(), __( 'View Connection', 'uptimewire' ) )
			);
		}

		if ( Connection::HEALTH_MISMATCH === $health ) {
			return self::result(
				'connection',
				'recommended',
				__( 'The UptimeWire connection belongs to another website address', 'uptimewire' ),
				array(
					__( 'This site is probably a copy of the connected website, or the website moved. Its monitoring data isn’t shown here.', 'uptimewire' ),
				),
				self::link( Plugin::overview_url(), __( 'View Connection', 'uptimewire' ) )
			);
		}

		return self::result(
			'connection',
			'recommended',
			__( 'The UptimeWire connection doesn’t work', 'uptimewire' ),
			array(
				Connection::HEALTH_BAD_CONSTANT === $health
					? __( 'UPTIMEWIRE_TOKEN in wp-config.php is not a valid UptimeWire credential.', 'uptimewire' )
					: __( 'UPTIMEWIRE_TOKEN is set in wp-config.php, but UptimeWire hasn’t confirmed it yet.', 'uptimewire' ),
			),
			self::link( Plugin::overview_url(), __( 'View Connection', 'uptimewire' ) )
		);
	}

	/**
	 * Are the external checks active, and how is the website doing?
	 *
	 * @return array<string, mixed>
	 */
	public static function test_monitoring() {
		$status  = Status::get();
		$data    = Status::data();
		$details = self::link( Plugin::overview_url(), __( 'View Monitoring Details', 'uptimewire' ) );
		$not_you = __( 'This is about the connection between WordPress and UptimeWire, not about your website.', 'uptimewire' );

		if ( null === $data ) {
			$error = null === $status ? '' : $status['error'];

			return self::result(
				'monitoring',
				'recommended',
				'' === $error ? __( 'WordPress hasn’t received the monitoring status from UptimeWire yet', 'uptimewire' ) : __( 'The monitoring status from UptimeWire is unavailable', 'uptimewire' ),
				array( '' === $error ? __( 'Open the UptimeWire screen to get it.', 'uptimewire' ) : Dashboard::failure_text( $error ), $not_you ),
				$details
			);
		}

		$headline = Status_Data::headline( $data['site'] );
		$site     = $data['site'];

		if ( Status::is_stale( $status ) ) {
			return self::result(
				'monitoring',
				'recommended',
				__( 'The UptimeWire status in WordPress is out of date', 'uptimewire' ),
				array(
					/* translators: 1: the last status, such as "Your Website Is Online"; 2: date and time it was received. */
					sprintf( __( 'Last known status: %1$s, received on %2$s. Current monitoring status is unavailable.', 'uptimewire' ), Dashboard::title( $headline ), self::date_time( $status['fetched_at'] ) ),
					'' !== $status['error'] ? Dashboard::failure_text( $status['error'] ) : __( 'Open the UptimeWire screen to get the latest results.', 'uptimewire' ),
					$not_you,
				),
				$details
			);
		}

		$checked = null !== $site['last_checked_at']
			/* translators: %s: date and time of the last check. */
			? sprintf( __( 'Last checked on %s.', 'uptimewire' ), self::date_time( $site['last_checked_at'] ) )
			: '';
		$every = null !== $site['interval_minutes']
			/* translators: %d: minutes between checks. */
			? sprintf( _n( 'UptimeWire checks it every %d minute from outside WordPress.', 'UptimeWire checks it every %d minutes from outside WordPress.', $site['interval_minutes'], 'uptimewire' ), $site['interval_minutes'] )
			: '';
		$open = null !== $data['links']['site'] ? $data['links']['site'] : Overview_Page::DASHBOARD;

		switch ( $headline ) {
			case 'up':
				return self::result( 'monitoring', 'good', __( 'UptimeWire is monitoring your website, and it is online', 'uptimewire' ), array( $every, $checked ), $details );
			case 'planned':
				return self::result( 'monitoring', 'good', __( 'Your website is in planned maintenance', 'uptimewire' ), array( __( 'Planned maintenance is marked for this website in UptimeWire. Time in maintenance isn’t counted as downtime.', 'uptimewire' ), $every ), $details );
			case 'waiting':
				return self::result( 'monitoring', 'good', __( 'UptimeWire monitoring has started', 'uptimewire' ), array( __( 'The first check hasn’t finished yet.', 'uptimewire' ), $every ), $details );
			case 'checking':
				return self::result( 'monitoring', 'recommended', __( 'UptimeWire is double-checking your website', 'uptimewire' ), array( __( 'A check just failed. UptimeWire is checking again before it reports downtime. This is not confirmed downtime.', 'uptimewire' ), $checked ), $details );
			case 'down':
				$lines = array();
				if ( null !== $site['down_since'] ) {
					/* translators: %s: date and time the downtime started. */
					$lines[] = sprintf( __( 'Down since %s.', 'uptimewire' ), self::date_time( $site['down_since'] ) );
				}
				if ( null !== $site['problem'] ) {
					/* translators: %s: what failed, as reported by UptimeWire. */
					$lines[] = sprintf( __( 'Reason: %s', 'uptimewire' ), $site['problem'] );
				}
				$lines[] = $checked;

				// Checks turned away by the website: visitors may still get in, so this is a monitoring problem first (see the access test).
				if ( null !== $site['blocked'] ) {
					$lines[] = __( 'Your website turns UptimeWire’s checks away, so this may be a firewall or security plugin blocking them rather than real downtime. See the UptimeWire access item.', 'uptimewire' );

					return self::result( 'monitoring', 'recommended', __( 'UptimeWire reports your website as down, but its checks are being blocked', 'uptimewire' ), $lines, $details );
				}

				return self::result( 'monitoring', 'critical', __( 'UptimeWire reports that your website is down', 'uptimewire' ), $lines, $details );
			case 'paused':
				return self::result( 'monitoring', 'recommended', __( 'UptimeWire monitoring is paused', 'uptimewire' ), array( __( 'UptimeWire isn’t checking your website, so there are no new results and no alerts. You can resume monitoring in UptimeWire.', 'uptimewire' ) ), $details . self::link( $open, __( 'Resume in UptimeWire', 'uptimewire' ), true ) );
			case 'unverified':
				return self::result( 'monitoring', 'recommended', __( 'UptimeWire monitoring hasn’t started: the account email isn’t verified', 'uptimewire' ), array( __( 'Monitoring and notifications will start after the account email is verified. Open the email UptimeWire sent and confirm the address.', 'uptimewire' ) ), self::link( $open, __( 'Open Your UptimeWire Account', 'uptimewire' ), true ) );
			default:
				return self::result( 'monitoring', 'recommended', __( 'UptimeWire reported a status this plugin doesn’t recognize', 'uptimewire' ), array( __( 'Open UptimeWire to see it, or update the plugin.', 'uptimewire' ) ), $details );
		}
	}

	/**
	 * Is the SSL certificate valid, expiring or expired?
	 *
	 * @return array<string, mixed>
	 */
	public static function test_ssl() {
		$data = Status::data();
		$ssl  = null === $data ? array( 'state' => 'unknown' ) : $data['ssl'];
		$when = self::expiry_line( $ssl );

		if ( 'ok' === $ssl['state'] ) {
			return self::result( 'ssl', 'good', __( 'Your SSL certificate is valid', 'uptimewire' ), array( $when, self::as_of( $ssl ) ) );
		}

		$warning = isset( $ssl['warning'] ) ? $ssl['warning'] : null;

		if ( 'expired' === $warning ) {
			return self::result( 'ssl', 'critical', __( 'Your SSL certificate has expired', 'uptimewire' ), array( __( 'Visitors see a security warning instead of your website. Renew the certificate with your hosting provider or certificate issuer.', 'uptimewire' ), $when, self::as_of( $ssl ) ), self::details( $data ) );
		}

		if ( 'expires_soon' === $warning ) {
			return self::result( 'ssl', 'recommended', __( 'Your SSL certificate expires soon', 'uptimewire' ), array( $when, __( 'If it renews automatically, check that the renewal works.', 'uptimewire' ), self::as_of( $ssl ) ), self::details( $data ) );
		}

		return self::result( 'ssl', 'recommended', __( 'UptimeWire couldn’t check your SSL certificate', 'uptimewire' ), array( isset( $ssl['error'] ) && null !== $ssl['error'] ? $ssl['error'] : '', self::as_of( $ssl ) ), self::details( $data ) );
	}

	/**
	 * Is the domain expiration date known, and is it close?
	 *
	 * @return array<string, mixed>
	 */
	public static function test_domain() {
		$data   = Status::data();
		$domain = null === $data ? array( 'state' => 'unknown' ) : $data['domain'];

		switch ( self::domain_verdict( $domain ) ) {
			case 'expired':
				return self::result( 'domain', 'critical', __( 'Your domain registration has expired', 'uptimewire' ), array( __( 'Renew the domain with your registrar before someone else can register it.', 'uptimewire' ), self::expiry_line( $domain ), self::as_of( $domain ) ), self::details( $data ) );
			case 'expires_soon':
				return self::result( 'domain', 'recommended', __( 'Your domain expires soon', 'uptimewire' ), array( self::expiry_line( $domain ), __( 'Renew it with your registrar, or check that automatic renewal is on.', 'uptimewire' ), self::as_of( $domain ) ), self::details( $data ) );
			default:
				return self::result( 'domain', 'good', __( 'Your domain registration is current', 'uptimewire' ), array( self::expiry_line( $domain ), self::as_of( $domain ) ) );
		}
	}

	/**
	 * What UptimeWire verified about the domain expiration: 'current', 'expires_soon',
	 * 'expired', or null when the date is unknown (not published, or the lookup failed).
	 *
	 * @param array<string, mixed> $domain Domain check of the kept data.
	 * @return string|null
	 */
	private static function domain_verdict( array $domain ) {
		$warning = isset( $domain['warning'] ) ? $domain['warning'] : null;

		if ( in_array( $warning, array( 'expired', 'expires_soon' ), true ) ) {
			return $warning;
		}

		return 'ok' === $domain['state'] && ! empty( $domain['expiry_known'] ) ? 'current' : null;
	}

	/**
	 * Does the email setup of the domain work? A problem is a recommendation, never critical: only downtime is.
	 *
	 * @return array<string, mixed>
	 */
	public static function test_mail() {
		$data = Status::data();
		$mail = null !== $data && isset( $data['mail'] ) ? $data['mail'] : array(
			'state'      => 'unknown',
			'items'      => array(),
			'checked_at' => null,
		);

		if ( 'problem' !== $mail['state'] ) {
			return self::result( 'mail', 'good', __( 'Your domain’s email setup works', 'uptimewire' ), array( self::as_of( $mail ) ) );
		}

		$lines = array();

		foreach ( $mail['items'] as $key => $item ) {
			if ( 'problem' === $item['state'] ) {
				$lines[] = Dashboard::mail_line( $key, $item );
			}
		}

		$lines[] = __( 'Emails from your website may not arrive, or may land in spam. Fix the records with your domain or email provider.', 'uptimewire' );
		$lines[] = self::as_of( $mail );

		return self::result( 'mail', 'recommended', __( 'Your domain’s email setup has a problem', 'uptimewire' ), $lines, self::details( $data ) );
	}

	/**
	 * Does the scheduled job run on time? Late or failed is a recommendation, never critical.
	 *
	 * @return array<string, mixed>
	 */
	public static function test_scheduled_job() {
		$data = Status::data();
		$job  = null !== $data && isset( $data['scheduled_job'] ) ? $data['scheduled_job'] : array(
			'state'        => 'unknown',
			'last_call_at' => null,
		);
		$last = null !== $job['last_call_at']
			/* translators: %s: date and time of the last call. */
			? sprintf( __( 'Last call on %s.', 'uptimewire' ), self::date_time( $job['last_call_at'] ) )
			: '';
		$open  = null !== $data && isset( $data['links']['scheduled_job'] ) ? $data['links']['scheduled_job'] : ( null !== $data ? $data['links']['site'] : null );
		$where = null !== $open ? self::link( $open, __( 'Open the job in UptimeWire', 'uptimewire' ), true ) : '';

		switch ( $job['state'] ) {
			case 'late':
				return self::result( 'scheduled_job', 'recommended', __( 'Your scheduled job is late', 'uptimewire' ), array( __( 'It didn’t call UptimeWire in time. Check that it still runs.', 'uptimewire' ), $last ), $where );
			case 'failed':
				return self::result( 'scheduled_job', 'recommended', __( 'Your scheduled job reported a failure', 'uptimewire' ), array( __( 'Its last run failed. Check the job’s own log.', 'uptimewire' ), $last ), $where );
			default:
				return self::result( 'scheduled_job', 'good', __( 'Your scheduled job runs on time', 'uptimewire' ), array( $last ) );
		}
	}

	/**
	 * Did UptimeWire confirm the last disconnect?
	 *
	 * @return array<string, mixed>
	 */
	public static function test_disconnect() {
		$unconfirmed = Connection::unconfirmed();

		return self::result(
			'disconnect',
			'recommended',
			__( 'UptimeWire didn’t confirm the last disconnect', 'uptimewire' ),
			array(
				/* translators: %s: website address. */
				sprintf( __( 'WordPress no longer keeps the credential, but the connection of %s may still be active in UptimeWire. Remove it there, or it ends by itself after 90 days without use.', 'uptimewire' ), null === $unconfirmed ? '' : $unconfirmed['site_url'] ),
			),
			self::link( Overview_Page::CONNECTIONS, __( 'Open Connected WordPress Sites', 'uptimewire' ), true )
		);
	}

	/**
	 * Can UptimeWire reach the website, or does something turn its checks away?
	 *
	 * @return array<string, mixed>
	 */
	public static function test_access() {
		$result = self::access_result();

		return null !== $result ? $result : self::result( 'access', 'good', __( 'No access problem reported by UptimeWire', 'uptimewire' ), array() );
	}

	/**
	 * Result of the access test, or null when the kept data can't tell.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function access_result() {
		$data = Status::data();

		if ( null === $data ) {
			return null;
		}

		if ( null !== $data['site']['blocked'] ) {
			$guide = self::link( Plugin::website_url( Overview_Page::FIREWALL_GUIDE ), __( 'How to allow UptimeWire', 'uptimewire' ), true )
				. self::link( Overview_Page::IP_LIST, __( 'UptimeWire addresses', 'uptimewire' ), true );

			return self::result(
				'access',
				'recommended',
				__( 'A firewall or security plugin may be blocking UptimeWire’s checks', 'uptimewire' ),
				array(
					/* translators: %d: HTTP status code such as 403. */
					sprintf( __( 'Your website answered UptimeWire’s checks with HTTP %d. Visitors may still open the site, but UptimeWire can’t tell whether it works.', 'uptimewire' ), (int) $data['site']['blocked'] ),
					__( 'Allow UptimeWire’s addresses or the “UptimeWireBot” user agent in Cloudflare, your firewall or your security plugin. You don’t need to turn the protection off.', 'uptimewire' ),
				),
				$guide
			);
		}

		// Only a current, successful check proves that UptimeWire gets through.
		if ( ! Status::is_stale( Status::get() ) && 'up' === Status_Data::headline( $data['site'] ) ) {
			return self::result( 'access', 'good', __( 'UptimeWire can reach your website', 'uptimewire' ), array( __( 'UptimeWire’s latest check got an answer from your website, so no firewall or security plugin blocks it.', 'uptimewire' ) ) );
		}

		return null;
	}

	/**
	 * Adds the UptimeWire section to Tools › Site Health › Info.
	 *
	 * Administrators copy this to support, so it holds no credential, device or
	 * user code, request header, email address, billing detail, other website
	 * or text sent by the monitored server.
	 *
	 * @param array<string, mixed> $info Debug information.
	 * @return array<string, mixed>
	 */
	public static function info( $info ) {
		if ( ! is_array( $info ) || ! Plugin::can_view() ) {
			return $info;
		}

		$state  = State::current();
		$record = Connection::get();
		$status = Status::get();
		$data   = State::CONNECTION_ESTABLISHED === $state ? Status::data() : null;
		$none   = __( 'None', 'uptimewire' );
		$fields = array(
			'version'    => array(
				'label' => __( 'Plugin version', 'uptimewire' ),
				'value' => UPTIMEWIRE_VERSION,
			),
			'connection' => array(
				'label' => __( 'Connection', 'uptimewire' ),
				'value' => State::label( $state ),
				'debug' => $state,
			),
			'site'       => array(
				'label' => __( 'Monitored website', 'uptimewire' ),
				'value' => self::monitored_url(),
			),
		);

		if ( null !== $record && State::SITE_COPY !== $state ) {
			// A copy shows nothing of the connection it carries: not even the account number.
			$label     = Connection::account_label();
			$connected = ! empty( $record['connected_at'] ) ? strtotime( (string) $record['connected_at'] ) : false;

			$fields['account']    = array(
				'label' => __( 'UptimeWire account', 'uptimewire' ),
				'value' => '' !== $label ? $label : $none,
			);
			$fields['connected']  = array(
				'label' => __( 'Connected since', 'uptimewire' ),
				'value' => false !== $connected ? self::date_time( $connected ) : $none,
				'debug' => false !== $connected ? gmdate( 'c', $connected ) : 'none',
			);
			$fields['credential'] = array(
				'label' => __( 'Credential kept in', 'uptimewire' ),
				'value' => null !== Connection::configured_token() ? __( 'wp-config.php (UPTIMEWIRE_TOKEN)', 'uptimewire' ) : __( 'WordPress database', 'uptimewire' ),
			);
		}

		$fetched = null !== $status ? $status['fetched_at'] : 0;

		$fields['synced'] = array(
			'label' => __( 'Last status received', 'uptimewire' ),
			'value' => $fetched > 0 ? self::date_time( $fetched ) : __( 'Never', 'uptimewire' ),
			'debug' => $fetched > 0 ? gmdate( 'c', $fetched ) : 'never',
		);
		$fields['cache']  = array(
			'label' => __( 'Kept status', 'uptimewire' ),
			'value' => null === $data ? __( 'Empty', 'uptimewire' ) : ( Status::is_stale( $status ) ? __( 'Out of date (shown as the last known status)', 'uptimewire' ) : __( 'Current', 'uptimewire' ) ),
			'debug' => null === $data ? 'empty' : ( Status::is_stale( $status ) ? 'stale' : 'current' ),
		);
		$fields['error']  = array(
			'label' => __( 'Last connection error', 'uptimewire' ),
			'value' => self::error_category( $record, $status ),
		);

		if ( null !== $data ) {
			$site                 = $data['site'];
			$fields['monitoring'] = array(
				'label' => __( 'Monitoring status', 'uptimewire' ),
				/* translators: 1: status, such as "Your Website Is Online"; 2: minutes between checks, or "?"; 3: date and time of the last check, or "never". */
				'value' => sprintf( __( '%1$s; checked every %2$s min; last check: %3$s', 'uptimewire' ), Dashboard::title( Status_Data::headline( $site ) ), null !== $site['interval_minutes'] ? (string) $site['interval_minutes'] : '?', null !== $site['last_checked_at'] ? self::date_time( $site['last_checked_at'] ) : __( 'never', 'uptimewire' ) ),
				'debug' => sprintf( '%s; interval=%s; last_check=%s', Status_Data::headline( $site ), null !== $site['interval_minutes'] ? (string) $site['interval_minutes'] : '?', null !== $site['last_checked_at'] ? gmdate( 'c', $site['last_checked_at'] ) : 'never' ),
			);
			$fields['checks']     = array(
				'label' => __( 'Website checks', 'uptimewire' ),
				'value' => sprintf( 'SSL: %1$s; domain: %2$s; DNS: %3$s', $data['ssl']['state'], $data['domain']['state'], $data['dns']['state'] ),
			);
			$fields['domain']     = array(
				'label' => __( 'Domain expiration date', 'uptimewire' ),
				'value' => null === self::domain_verdict( $data['domain'] )
					? __( 'Unavailable — the registry does not provide a reliable expiration date', 'uptimewire' )
					: self::expiry_line( $data['domain'] ),
			);

			// Everything of the two checks of BE-5, tips included: the tests above report only what needs doing.
			if ( isset( $data['mail'] ) ) {
				$lines = array();
				$debug = array( $data['mail']['state'] );

				foreach ( $data['mail']['items'] as $key => $item ) {
					$lines[] = Dashboard::mail_line( $key, $item );
					$debug[] = $key . '=' . $item['state'];
				}

				$fields['mail'] = array(
					'label' => __( 'Email domain', 'uptimewire' ),
					'value' => 'none' === $data['mail']['state'] ? __( 'This domain does not receive email.', 'uptimewire' ) : ( array() !== $lines ? implode( '; ', $lines ) : __( 'Not checked yet', 'uptimewire' ) ),
					'debug' => implode( '; ', $debug ),
				);
			}

			if ( isset( $data['scheduled_job'] ) ) {
				$job   = $data['scheduled_job'];
				$words = array(
					'not_connected' => __( 'Not connected', 'uptimewire' ),
					'on_time'       => __( 'On time', 'uptimewire' ),
					'late'          => __( 'Late', 'uptimewire' ),
					'failed'        => __( 'Failed', 'uptimewire' ),
				);

				$fields['scheduled_job'] = array(
					'label' => __( 'Scheduled job', 'uptimewire' ),
					/* translators: 1: state, such as "On time"; 2: date and time of the last call, or "never"; 3: time between runs, such as "1 day", or "?". */
					'value' => sprintf( __( '%1$s; last call: %2$s; expected every %3$s', 'uptimewire' ), isset( $words[ $job['state'] ] ) ? $words[ $job['state'] ] : __( 'Unknown', 'uptimewire' ), null !== $job['last_call_at'] ? self::date_time( $job['last_call_at'] ) : __( 'never', 'uptimewire' ), null !== $job['interval_seconds'] ? Dashboard::duration( $job['interval_seconds'] ) : '?' ),
					'debug' => sprintf( '%s; last_call=%s; interval=%s; grace=%s', $job['state'], null !== $job['last_call_at'] ? gmdate( 'c', $job['last_call_at'] ) : 'never', null !== $job['interval_seconds'] ? (string) $job['interval_seconds'] : '?', null !== $job['grace_seconds'] ? (string) $job['grace_seconds'] : '?' ),
				);
			}
		}

		$info[ self::SECTION ] = array(
			'label'       => __( 'UptimeWire', 'uptimewire' ),
			'description' => __( 'The connection to UptimeWire and the latest monitoring status WordPress received. It contains the UptimeWire account number, but no credentials or email addresses.', 'uptimewire' ),
			'fields'      => $fields,
		);

		return $info;
	}

	/**
	 * Category of the last error between WordPress and UptimeWire.
	 *
	 * @param array<string, mixed>|null $record Connection record.
	 * @param array<string, mixed>|null $status Kept status.
	 * @return string
	 */
	private static function error_category( $record, $status ) {
		$health = null === $record ? '' : Connection::health_state( $record );
		$words  = array(
			Connection::HEALTH_ENDED        => __( 'Connection no longer active', 'uptimewire' ),
			Connection::HEALTH_MISMATCH     => __( 'Website address mismatch', 'uptimewire' ),
			Connection::HEALTH_COPY         => __( 'Connection made on another WordPress address (copied or moved site)', 'uptimewire' ),
			Connection::HEALTH_BAD_CONSTANT => __( 'Invalid UPTIMEWIRE_TOKEN', 'uptimewire' ),
			Connection::HEALTH_UNREACHABLE  => __( 'UptimeWire unreachable', 'uptimewire' ),
			Status::UNREACHABLE             => __( 'UptimeWire unreachable', 'uptimewire' ),
			Status::UNAVAILABLE             => __( 'UptimeWire temporarily unavailable', 'uptimewire' ),
			Status::RATE_LIMITED            => __( 'Rate limited by UptimeWire', 'uptimewire' ),
			Status::INVALID                 => __( 'Unreadable answer from UptimeWire', 'uptimewire' ),
		);

		if ( isset( $words[ $health ] ) && Connection::HEALTH_UNREACHABLE !== $health ) {
			return $words[ $health ];
		}

		if ( null !== $status && isset( $words[ $status['error'] ] ) ) {
			return $words[ $status['error'] ];
		}

		return isset( $words[ $health ] ) ? $words[ $health ] : __( 'None', 'uptimewire' );
	}

	/**
	 * A Site Health result.
	 *
	 * @param string   $name    Test name without the prefix.
	 * @param string   $status  good, recommended or critical.
	 * @param string   $label   Label.
	 * @param string[] $lines   Plain sentences; empty ones are skipped.
	 * @param string   $actions Escaped HTML of the actions.
	 * @return array<string, mixed>
	 */
	private static function result( $name, $status, $label, array $lines, $actions = '' ) {
		$description = '';

		foreach ( $lines as $line ) {
			if ( '' !== $line ) {
				$description .= '<p>' . esc_html( $line ) . '</p>';
			}
		}

		return array(
			'label'       => $label,
			'status'      => $status,
			'badge'       => array(
				'label' => __( 'UptimeWire', 'uptimewire' ),
				'color' => 'blue',
			),
			'description' => $description,
			'actions'     => '' !== $actions ? '<p>' . $actions . '</p>' : '',
			'test'        => 'uptimewire_' . $name,
		);
	}

	/**
	 * A link for the actions of a result.
	 *
	 * @param string $url      Address.
	 * @param string $text     Text.
	 * @param bool   $external Whether it opens UptimeWire in a new tab.
	 * @return string Escaped HTML.
	 */
	private static function link( $url, $text, $external = false ) {
		if ( ! $external ) {
			return sprintf( '<a href="%1$s">%2$s</a> ', esc_url( $url ), esc_html( $text ) );
		}

		return sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s</span><span aria-hidden="true" class="dashicons dashicons-external"></span></a> ',
			esc_url( $url ),
			esc_html( $text ),
			esc_html__( '(opens in a new tab)', 'uptimewire' )
		);
	}

	/**
	 * Link to the website's page in UptimeWire, when UptimeWire gave one.
	 *
	 * @param array<string, mixed>|null $data Kept data.
	 * @return string Escaped HTML.
	 */
	private static function details( $data ) {
		return null !== $data && null !== $data['links']['site'] ? self::link( $data['links']['site'], __( 'Details in UptimeWire', 'uptimewire' ), true ) : '';
	}

	/**
	 * When the certificate or the domain expires, in words.
	 *
	 * @param array<string, mixed> $check Kept check.
	 * @return string
	 */
	private static function expiry_line( array $check ) {
		if ( empty( $check['expires_at'] ) ) {
			return '';
		}

		$date = wp_date( get_option( 'date_format' ), $check['expires_at'] );

		if ( null === $check['days_left'] ) {
			/* translators: %s: date. */
			return sprintf( __( 'Expiry date: %s.', 'uptimewire' ), $date );
		}

		return $check['days_left'] < 0
			/* translators: 1: number of days; 2: date. */
			? sprintf( _n( 'Expired %1$d day ago, on %2$s.', 'Expired %1$d days ago, on %2$s.', -$check['days_left'], 'uptimewire' ), -$check['days_left'], $date )
			/* translators: 1: number of days; 2: date. */
			: sprintf( _n( 'Expires in %1$d day, on %2$s.', 'Expires in %1$d days, on %2$s.', $check['days_left'], 'uptimewire' ), $check['days_left'], $date );
	}

	/**
	 * When UptimeWire last ran a slow check, in words.
	 *
	 * @param array<string, mixed> $check Kept check.
	 * @return string
	 */
	private static function as_of( array $check ) {
		/* translators: %s: date and time of the check. */
		return empty( $check['checked_at'] ) ? '' : sprintf( __( 'UptimeWire checked it on %s.', 'uptimewire' ), self::date_time( $check['checked_at'] ) );
	}

	/**
	 * The website the connection monitors. A connection of another website
	 * address (a copy of the site) names this site, not the other one.
	 *
	 * @return string
	 */
	private static function monitored_url() {
		$record = Connection::get();

		if ( null === $record || ! Connection::is_own( $record ) || ! isset( $record['site_url'] ) || ! is_string( $record['site_url'] ) ) {
			return Plugin::site_url();
		}

		return $record['site_url'];
	}

	/**
	 * A date and time in the site's formats.
	 *
	 * @param int $time Unix time.
	 * @return string
	 */
	private static function date_time( $time ) {
		return (string) wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $time );
	}
}
