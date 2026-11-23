<?php
/**
 * The "UptimeWire — Website Status" widget on the WordPress Dashboard.
 *
 * @package UptimeWire
 */

namespace UptimeWire\Admin;

use UptimeWire\Connection;
use UptimeWire\Plugin;
use UptimeWire\Rest;
use UptimeWire\State;
use UptimeWire\Status;
use UptimeWire\Status_Data;

defined( 'ABSPATH' ) || exit;

/**
 * A compact summary of the kept status: is the website monitored, and is it
 * working. Rendering never asks UptimeWire anything; when the kept status is
 * due, the Overview script renews it after the Dashboard is shown, through the
 * same REST route and lock as the Overview screen.
 *
 * Only users with the plugin's capability get the widget, and WordPress' own
 * Screen Options hide it.
 */
final class Widget {

	/** Widget id. */
	const ID = 'uptimewire_status';

	/** Id of the element the script replaces after a refresh. */
	const CONTAINER_ID = 'uptimewire-widget';

	/** Less measured time than this is too short for an uptime figure, in seconds. */
	const RECENT = 86400;

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_dashboard_setup', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Adds the widget for users who may see monitoring data.
	 *
	 * @return void
	 */
	public static function register() {
		if ( ! Plugin::current_user_can() ) {
			return;
		}

		wp_add_dashboard_widget( self::ID, __( 'UptimeWire — Website Status', 'uptimewire' ), array( __CLASS__, 'render' ) );
	}

	/**
	 * Loads the widget style on the Dashboard, and the refresh script while connected.
	 *
	 * @param string $hook_suffix Current admin screen.
	 * @return void
	 */
	public static function enqueue( $hook_suffix ) {
		// The Network Admin and User dashboards have the same hook name, but not the widget.
		if ( 'index.php' !== $hook_suffix || is_network_admin() || is_user_admin() || ! Plugin::current_user_can() ) {
			return;
		}

		wp_enqueue_style( 'uptimewire-widget', plugins_url( 'assets/widget.css', UPTIMEWIRE_FILE ), array(), UPTIMEWIRE_VERSION );

		if ( State::CONNECTION_ESTABLISHED !== State::current() ) {
			return;
		}

		wp_enqueue_script(
			'uptimewire-dashboard',
			plugins_url( 'assets/dashboard.js', UPTIMEWIRE_FILE ),
			array(),
			UPTIMEWIRE_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_localize_script(
			'uptimewire-dashboard',
			'uptimewireDashboard',
			array(
				'url'       => rest_url( Rest::NAMESPACE_V1 . '/status-refresh' ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'container' => self::CONTAINER_ID,
				'view'      => 'widget',
				'text'      => array(
					'refreshing' => __( 'Refreshing…', 'uptimewire' ),
					'failed'     => __( 'Couldn’t refresh. Check your connection to this WordPress site and try again.', 'uptimewire' ),
				),
			)
		);
	}

	/**
	 * Prints the widget: the live region and the replaceable part.
	 *
	 * @return void
	 */
	public static function render() {
		$status    = Status::get();
		$connected = State::CONNECTION_ESTABLISHED === State::current();
		?>
		<p id="uptimewire-widget-live" class="screen-reader-text" role="status" aria-live="polite"></p>
		<div id="<?php echo esc_attr( self::CONTAINER_ID ); ?>" class="uptimewire-widget" data-due="<?php echo $connected && Status::is_due( $status ) ? '1' : '0'; ?>" data-ttl="<?php echo esc_attr( (string) Status::ttl( null === $status ? null : $status['data'] ) ); ?>">
			<?php self::render_inner(); ?>
		</div>
		<?php
	}

	/**
	 * Prints the replaceable part for the current connection and kept status.
	 *
	 * @return void
	 */
	public static function render_inner() {
		$state = State::current();

		if ( State::NOT_CONNECTED === $state ) {
			self::box(
				'off',
				'dashicons-shield',
				__( 'UptimeWire Is Not Connected', 'uptimewire' ),
				array( __( 'Start free monitoring for your WordPress website.', 'uptimewire' ) )
			);
			self::footer( '', __( 'Set Up Monitoring', 'uptimewire' ), true );

			return;
		}

		if ( State::RECONNECTION_REQUIRED === $state ) {
			self::box(
				'warn',
				'dashicons-warning',
				__( 'Connection Expired', 'uptimewire' ),
				array( __( 'The connection between WordPress and UptimeWire ended, so the monitoring status can’t be shown here. UptimeWire’s own monitoring isn’t affected by this.', 'uptimewire' ) )
			);
			self::footer( '', __( 'Reconnect', 'uptimewire' ), true );

			return;
		}

		if ( State::CONNECTION_ESTABLISHED !== $state ) {
			$record   = Connection::get();
			$mismatch = null !== $record && Connection::HEALTH_MISMATCH === Connection::health_state( $record );

			self::box(
				'unknown',
				'dashicons-info',
				$mismatch ? __( 'Website Addresses Don’t Match', 'uptimewire' ) : State::label( $state ),
				array( $mismatch ? __( 'This connection belongs to another website address, so its monitoring data isn’t shown here.', 'uptimewire' ) : __( 'The connection to UptimeWire isn’t finished. Open UptimeWire in WordPress to continue.', 'uptimewire' ) )
			);
			self::footer( '', __( 'Open UptimeWire Overview', 'uptimewire' ), false );

			return;
		}

		$status = Status::get();
		$data   = Status::data();
		$synced = null !== $status ? $status['fetched_at'] : 0;

		if ( null === $data ) {
			$error = null === $status ? '' : $status['error'];

			if ( '' === $error ) {
				self::box( 'info', 'dashicons-update', __( 'Loading Your Website Status…', 'uptimewire' ), array( __( 'Getting your website’s status from UptimeWire.', 'uptimewire' ) ) );
			} else {
				self::box(
					'unknown',
					'dashicons-cloud',
					__( 'Status Unavailable', 'uptimewire' ),
					array(
						Dashboard::failure_text( $error ),
						__( 'This is about the connection between WordPress and UptimeWire, not about your website.', 'uptimewire' ),
					)
				);
			}
			self::footer( $synced, __( 'View Monitoring Details', 'uptimewire' ), false );

			return;
		}

		$headline = Status_Data::headline( $data['site'] );

		if ( Status::is_stale( $status ) ) {
			self::box(
				'unknown',
				'dashicons-backup',
				/* translators: %s: the last status UptimeWire reported, such as "Online". */
				sprintf( __( 'Last Known Status: %s', 'uptimewire' ), self::short( $headline ) ),
				array(
					/* translators: %s: how long ago, such as "45 mins". */
					sprintf( __( 'Updated %s ago. Current monitoring status is unavailable.', 'uptimewire' ), human_time_diff( $status['fetched_at'], time() ) ),
				)
			);
			// The box already says when the status was received.
			self::footer( '', __( 'View Monitoring Details', 'uptimewire' ), false );

			return;
		}

		$look = Dashboard::look( $headline );

		self::box( $look[0], $look[1], self::title( $headline ), self::lead( $data, $headline ), self::facts( $data, $headline ) );

		if ( '' !== $status['error'] ) {
			echo '<p class="uptimewire-widget-note">' . esc_html( Dashboard::failure_text( $status['error'] ) ) . ' ' . esc_html__( 'The status above is the latest one received.', 'uptimewire' ) . '</p>';
		}

		self::footer( $synced, __( 'View Monitoring Details', 'uptimewire' ), false );
	}

	/**
	 * Words announced to screen readers when the widget's status changes.
	 *
	 * @return string
	 */
	public static function announcement() {
		$state = State::current();

		if ( State::CONNECTION_ESTABLISHED === $state ) {
			return Dashboard::announcement();
		}

		return State::RECONNECTION_REQUIRED === $state ? __( 'Connection Expired', 'uptimewire' ) : State::label( $state );
	}

	/**
	 * Title of a main status in the widget.
	 *
	 * @param string $headline Status_Data::headline().
	 * @return string
	 */
	private static function title( $headline ) {
		return 'unverified' === $headline ? __( 'Email Verification Required', 'uptimewire' ) : Dashboard::title( $headline );
	}

	/**
	 * One word for a main status, as used in "Last Known Status: …".
	 *
	 * @param string $headline Status_Data::headline().
	 * @return string
	 */
	private static function short( $headline ) {
		$words = array(
			'unverified' => __( 'Email Verification Required', 'uptimewire' ),
			'waiting'    => __( 'Waiting', 'uptimewire' ),
			'up'         => __( 'Online', 'uptimewire' ),
			'checking'   => __( 'Checking', 'uptimewire' ),
			'down'       => __( 'Down', 'uptimewire' ),
			'planned'    => __( 'Planned Maintenance', 'uptimewire' ),
			'paused'     => __( 'Paused', 'uptimewire' ),
		);

		return isset( $words[ $headline ] ) ? $words[ $headline ] : __( 'Unknown', 'uptimewire' );
	}

	/**
	 * Sentences under the title that explain the main status.
	 *
	 * @param array<string, mixed> $data     Kept data.
	 * @param string               $headline Status_Data::headline().
	 * @return string[]
	 */
	private static function lead( array $data, $headline ) {
		$site = $data['site'];

		switch ( $headline ) {
			case 'unverified':
				return array( __( 'Monitoring and notifications will start after the account email is verified.', 'uptimewire' ) );
			case 'waiting':
				return array( __( 'The first check hasn’t finished yet.', 'uptimewire' ) );
			case 'checking':
				return array( __( 'A check just failed and UptimeWire is checking again. This is not confirmed downtime.', 'uptimewire' ) );
			case 'down':
				return null === $site['down_since']
					? array()
					/* translators: 1: date and time the downtime started; 2: how long it has lasted, such as "25 minutes". */
					: array( sprintf( __( 'Down since %1$s (%2$s).', 'uptimewire' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $site['down_since'] ), Dashboard::duration( max( 0, time() - $site['down_since'] ) ) ) );
			case 'planned':
				return array( __( 'Time in planned maintenance isn’t counted as downtime.', 'uptimewire' ) );
			case 'paused':
				return array( __( 'UptimeWire isn’t checking your website right now, so there are no new results and no alerts.', 'uptimewire' ) );
			case 'up':
				return array();
			default:
				return array( __( 'UptimeWire reported a status this version of the plugin doesn’t recognize.', 'uptimewire' ) );
		}
	}

	/**
	 * The short facts: uptime, SSL, last check, and checks that need attention.
	 *
	 * @param array<string, mixed> $data     Kept data.
	 * @param string               $headline Status_Data::headline().
	 * @return array<int, array{0: string, 1: string}> Label and escaped HTML value.
	 */
	private static function facts( array $data, $headline ) {
		if ( in_array( $headline, array( 'unverified', 'waiting' ), true ) ) {
			return array();
		}

		$ssl = esc_html( self::ssl( $data['ssl'] ) );

		// A certificate problem is marked, so it is not read as part of a green "online".
		if ( 'problem' === $data['ssl']['state'] ) {
			$tone = 'expired' === $data['ssl']['warning'] ? 'bad' : 'warn';
			$ssl  = '<span class="uptimewire-widget-flag uptimewire-tone-' . $tone . '"><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . $ssl . '</span>';
		}

		$facts = array(
			array( __( 'Uptime', 'uptimewire' ), esc_html( self::uptime( $data ) ) ),
			array( __( 'SSL', 'uptimewire' ), $ssl ),
			array(
				__( 'Last checked', 'uptimewire' ),
				null !== $data['site']['last_checked_at'] ? Dashboard::ago_html( $data['site']['last_checked_at'] ) : esc_html__( 'Not yet', 'uptimewire' ),
			),
		);

		$attention = array();

		if ( 'problem' === $data['ssl']['state'] ) {
			$attention[] = __( 'SSL certificate', 'uptimewire' );
		}

		if ( 'problem' === $data['domain']['state'] ) {
			$attention[] = __( 'domain', 'uptimewire' );
		}

		if ( 'problem' === $data['dns']['state'] ) {
			$attention[] = __( 'DNS', 'uptimewire' );
		}

		if ( array() !== $attention ) {
			$facts[] = array( __( 'Needs attention', 'uptimewire' ), '<span class="uptimewire-widget-flag uptimewire-tone-warn"><span class="dashicons dashicons-warning" aria-hidden="true"></span> ' . esc_html( implode( wp_get_list_item_separator(), $attention ) ) . '</span>' );
		}

		if ( null !== $data['site']['blocked'] ) {
			/* translators: %d: HTTP status code such as 403. */
			$facts[] = array( __( 'Checks blocked', 'uptimewire' ), esc_html( sprintf( __( 'Your website answers UptimeWire with HTTP %d. A firewall or security plugin may be blocking the checks.', 'uptimewire' ), (int) $data['site']['blocked'] ) ) );
		}

		return $facts;
	}

	/**
	 * Uptime in words, never claiming more history than was measured.
	 *
	 * @param array<string, mixed> $data Kept data.
	 * @return string
	 */
	private static function uptime( array $data ) {
		$window = $data['uptime']['30d'];

		if ( null === $window['percent'] || $window['measured_seconds'] < self::RECENT ) {
			return null !== $data['site']['last_checked_at']
				? __( 'Monitoring started recently. Your first checks are complete. More meaningful uptime history will become available as monitoring continues.', 'uptimewire' )
				: __( 'Not measured yet', 'uptimewire' );
		}

		if ( $window['measured_seconds'] < Status_Data::WINDOWS['30d'] - 60 && null !== $window['from'] ) {
			/* translators: 1: uptime percentage such as "99.97%"; 2: date measuring started. */
			return sprintf( __( '%1$s since %2$s', 'uptimewire' ), Dashboard::percent( $window['percent'] ), wp_date( get_option( 'date_format' ), $window['from'] ) );
		}

		/* translators: %s: uptime percentage such as "99.97%". */
		return sprintf( __( '%s in the last 30 days', 'uptimewire' ), Dashboard::percent( $window['percent'] ) );
	}

	/**
	 * The SSL certificate in words.
	 *
	 * @param array<string, mixed> $ssl Kept SSL check.
	 * @return string
	 */
	private static function ssl( array $ssl ) {
		$days = $ssl['days_left'];

		switch ( $ssl['state'] ) {
			case 'ok':
				return null !== $days && $days >= 0
					/* translators: %d: days until the certificate expires. */
					? sprintf( _n( 'Valid — %d day remaining', 'Valid — %d days remaining', $days, 'uptimewire' ), $days )
					: __( 'Valid', 'uptimewire' );
			case 'problem':
				if ( 'expired' === $ssl['warning'] ) {
					return __( 'Expired', 'uptimewire' );
				}
				if ( 'expires_soon' === $ssl['warning'] ) {
					return null !== $days && $days >= 0
						/* translators: %d: days until the certificate expires. */
						? sprintf( _n( 'Expires soon — %d day remaining', 'Expires soon — %d days remaining', $days, 'uptimewire' ), $days )
						: __( 'Expires soon', 'uptimewire' );
				}

				return __( 'Check failed', 'uptimewire' );
			case 'not_applicable':
				return __( 'No certificate (the website uses http://)', 'uptimewire' );
			case 'not_on_plan':
				return __( 'Not in your plan', 'uptimewire' );
			default:
				return __( 'Not checked yet', 'uptimewire' );
		}
	}

	/**
	 * Prints the status box.
	 *
	 * @param string                                  $tone  ok, warn, bad, info, off or unknown.
	 * @param string                                  $icon  Dashicon class.
	 * @param string                                  $title Title.
	 * @param string[]                                $lines Plain sentences.
	 * @param array<int, array{0: string, 1: string}> $facts Label and escaped HTML value.
	 * @return void
	 */
	private static function box( $tone, $icon, $title, array $lines, array $facts = array() ) {
		?>
		<div class="uptimewire-widget-status uptimewire-tone-<?php echo esc_attr( $tone ); ?>">
			<span class="uptimewire-widget-icon dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
			<div class="uptimewire-widget-body">
				<h3 class="uptimewire-widget-title"><?php echo esc_html( $title ); ?></h3>
				<?php foreach ( $lines as $line ) : ?>
					<p><?php echo esc_html( $line ); ?></p>
				<?php endforeach; ?>
			</div>
		</div>
		<?php if ( array() !== $facts ) : ?>
			<dl class="uptimewire-widget-facts">
				<?php foreach ( $facts as $fact ) : ?>
					<div>
						<dt><?php echo esc_html( $fact[0] ); ?></dt>
						<dd><?php echo $fact[1]; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in facts(). ?></dd>
					</div>
				<?php endforeach; ?>
			</dl>
		<?php endif; ?>
		<?php
	}

	/**
	 * Prints when WordPress last received the status, and the link to the plugin screen.
	 *
	 * @param int|string $synced Time of the last successful read, 0 or '' for none.
	 * @param string     $action Link text.
	 * @param bool       $button Whether the link is the main action.
	 * @return void
	 */
	private static function footer( $synced, $action, $button ) {
		?>
		<div class="uptimewire-widget-footer">
			<?php if ( '' !== $synced ) : ?>
				<p class="uptimewire-widget-synced">
					<?php
					if ( $synced > 0 ) {
						/* translators: %s: when, such as "2 mins ago". */
						printf( esc_html__( 'Received from UptimeWire %s', 'uptimewire' ), Dashboard::ago_html( (int) $synced ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in ago_html().
					} else {
						esc_html_e( 'Not received from UptimeWire yet', 'uptimewire' );
					}
					?>
				</p>
			<?php endif; ?>
			<p class="uptimewire-widget-action">
				<a class="<?php echo $button ? 'button button-primary' : ''; ?>" href="<?php echo esc_url( Plugin::overview_url() ); ?>">
					<?php echo esc_html( $action ); ?><?php echo $button ? '' : ' <span aria-hidden="true">&rarr;</span>'; ?>
				</a>
			</p>
		</div>
		<?php
	}
}
