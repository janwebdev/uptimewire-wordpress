<?php
/**
 * Overview screen: the welcome screen, the waiting screen and the connection.
 *
 * @package UptimeWire
 */

namespace UptimeWire\Admin;

use UptimeWire\Authorization;
use UptimeWire\Connection;
use UptimeWire\Environment;
use UptimeWire\Plugin;
use UptimeWire\State;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the screen of the current connection state.
 */
final class Overview_Page {

	/** Guide on letting UptimeWire's checks through a firewall. */
	const FIREWALL_GUIDE = 'resources/guides/allow-uptimewire-checks-through-firewall/';

	/** List of the addresses UptimeWire checks from. */
	const IP_LIST = 'https://app.uptimewire.com/ips.txt';

	/** The UptimeWire dashboard, with the source mark of the plugin. */
	const DASHBOARD = 'https://app.uptimewire.com/?src=wp';

	/** The list of WordPress sites connected to the UptimeWire account, where a connection can be removed. */
	const CONNECTIONS = 'https://app.uptimewire.com/user/profile/wordpress';

	/**
	 * Prints the screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! Plugin::can_view() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'uptimewire' ), '', array( 'response' => 403 ) );
		}

		$record = Connection::get();

		// A working connection is confirmed by every status read (see Status), which the screen's
		// script makes after the page is shown; only other connections are checked before rendering.
		if ( null === $record || Connection::HEALTH_OK !== Connection::health_state( $record ) ) {
			Connection::check_health();
		}

		$state = State::current();
		?>
		<div class="wrap uptimewire">
			<h1 class="uptimewire-title"><?php View::shield(); ?> <?php esc_html_e( 'UptimeWire', 'uptimewire' ); ?></h1>
			<hr class="wp-header-end">
			<?php
			self::render_result_notice();
			self::render_lifecycle_notices();

			if ( ! Plugin::can_manage() && State::CONNECTION_ESTABLISHED !== $state ) {
				// Viewing only: the state is shown, the actions are not.
				self::render_view_only( $state );
				$state = '';
			}

			switch ( $state ) {
				case '':
					break;
				case State::SITE_COPY:
					self::render_copy();
					break;
				case State::AUTHORIZATION_PENDING:
				case State::WAITING_FOR_APPROVAL:
					self::render_waiting( $state );
					break;
				case State::AUTHORIZATION_EXPIRED:
				case State::AUTHORIZATION_DENIED:
					self::render_outcome( $state );
					break;
				case State::CONNECTION_ERROR:
					if ( null !== Authorization::outcome() ) {
						self::render_outcome( $state );
					} else {
						self::render_connection_problem();
					}
					break;
				case State::CONNECTION_ESTABLISHED:
					self::render_connected();
					break;
				case State::RECONNECTION_REQUIRED:
					self::render_reconnect();
					break;
				default:
					self::render_welcome();
			}

			View::footnote();
			?>
		</div>
		<?php
	}

	/**
	 * Prints the welcome screen with environment warnings and the website address.
	 *
	 * @return void
	 */
	private static function render_welcome() {
		$site_url = Plugin::site_url();
		$warnings = Environment::warnings( wp_get_environment_type(), $site_url );
		?>
		<div class="uptimewire-welcome">
			<h2><?php esc_html_e( 'Keep Your WordPress Website Online', 'uptimewire' ); ?></h2>
			<p class="uptimewire-lead"><?php esc_html_e( 'Get notified when your website goes down, your SSL certificate approaches expiration, or important website checks fail.', 'uptimewire' ); ?></p>

			<?php self::render_warnings( $warnings ); ?>
			<?php self::render_connect_form( $warnings, __( 'Start Free Monitoring', 'uptimewire' ), __( 'Connect Existing Account', 'uptimewire' ) ); ?>

			<ul class="uptimewire-benefits">
				<li><span class="dashicons dashicons-yes" aria-hidden="true"></span> <?php esc_html_e( 'Free monitoring for one website', 'uptimewire' ); ?></li>
				<li><span class="dashicons dashicons-yes" aria-hidden="true"></span> <?php esc_html_e( 'No credit card required', 'uptimewire' ); ?></li>
				<li><span class="dashicons dashicons-yes" aria-hidden="true"></span> <?php esc_html_e( 'Checks run from outside WordPress, so they keep working even when your site is offline', 'uptimewire' ); ?></li>
			</ul>

			<p><?php esc_html_e( 'UptimeWire is an external service: the checks run on UptimeWire servers, not in WordPress. Nothing is sent to UptimeWire until you click a button above. Both buttons open UptimeWire in a new tab, where you sign up or sign in and approve this website.', 'uptimewire' ); ?></p>
			<p class="uptimewire-legal">
				<?php View::external_link( Plugin::website_url( 'terms/' ), __( 'Terms of Service', 'uptimewire' ) ); ?>
				<span aria-hidden="true">&middot;</span>
				<?php View::external_link( Plugin::website_url( 'privacy/' ), __( 'Privacy Policy', 'uptimewire' ) ); ?>
			</p>

			<?php self::render_site_url( $site_url ); ?>
		</div>
		<?php
	}

	/**
	 * Prints the connect buttons. The same form starts, starts again and reconnects.
	 *
	 * @param string[]    $warnings  Environment::WARNING_* constants.
	 * @param string      $primary   Label of the main button.
	 * @param string|null $secondary Label of the second button, or null.
	 * @return void
	 */
	private static function render_connect_form( array $warnings, $primary, $secondary = null ) {
		$local = in_array( Environment::WARNING_LOCAL, $warnings, true );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uptimewire-connect" id="uptimewire-connect-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( Admin::CONNECT_ACTION ); ?>">
			<?php wp_nonce_field( Admin::CONNECT_ACTION ); ?>
			<fieldset<?php echo $local ? ' disabled aria-describedby="uptimewire-connect-unavailable"' : ''; ?>>
				<legend class="screen-reader-text"><?php esc_html_e( 'Connect this website to UptimeWire', 'uptimewire' ); ?></legend>
				<?php if ( in_array( Environment::WARNING_STAGING, $warnings, true ) ) : ?>
					<p>
						<label>
							<input type="checkbox" name="uptimewire_understand" value="1" required>
							<?php esc_html_e( 'I understand', 'uptimewire' ); ?>
						</label>
					</p>
				<?php endif; ?>
				<p class="uptimewire-actions">
					<button type="submit" class="button button-primary button-hero"><?php echo esc_html( $primary ); ?></button>
					<?php if ( null !== $secondary ) : ?>
						<button type="submit" class="button button-hero"><?php echo esc_html( $secondary ); ?></button>
					<?php endif; ?>
				</p>
			</fieldset>
			<?php if ( $local ) : ?>
				<p id="uptimewire-connect-unavailable" class="description uptimewire-unavailable">
					<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
					<?php esc_html_e( 'A local site cannot be connected.', 'uptimewire' ); ?>
				</p>
			<?php endif; ?>
		</form>
		<?php
	}

	/**
	 * Prints the waiting screen while the person approves in UptimeWire.
	 *
	 * @param string $state AUTHORIZATION_PENDING or WAITING_FOR_APPROVAL.
	 * @return void
	 */
	private static function render_waiting( $state ) {
		$pending = Authorization::pending();

		if ( null === $pending ) {
			return;
		}

		$view    = Authorization::view();
		$open    = '' !== $pending['verification_uri_complete'] ? $pending['verification_uri_complete'] : $pending['verification_uri'];
		$expires = (int) $pending['expires_at'];
		$status  = array(
			'unreachable'  => __( 'UptimeWire is temporarily unreachable. Still waiting — nothing is lost.', 'uptimewire' ),
			'rate_limited' => __( 'UptimeWire asked us to wait a little before checking again. Still waiting.', 'uptimewire' ),
		);
		?>
		<div class="uptimewire-card uptimewire-waiting" id="uptimewire-waiting" data-seconds-left="<?php echo esc_attr( (string) $view['seconds_left'] ); ?>" data-retry-after="<?php echo esc_attr( (string) $view['retry_after'] ); ?>">
			<p class="uptimewire-state-line"><?php self::state_badge( $state ); ?></p>
			<h2><?php esc_html_e( 'Finish Connecting UptimeWire', 'uptimewire' ); ?></h2>
			<?php if ( 'tab_blocked' === ( isset( $_GET[ Admin::NOTICE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ Admin::NOTICE_ARG ] ) ) : '' ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only. ?>
				<p><?php esc_html_e( 'Open UptimeWire, sign in and approve the connection for your website.', 'uptimewire' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'A new tab has opened. Sign in to UptimeWire and approve the connection for your website.', 'uptimewire' ); ?></p>
			<?php endif; ?>

			<p class="uptimewire-code-label" id="uptimewire-code-label"><?php esc_html_e( 'Your code', 'uptimewire' ); ?></p>
			<p class="uptimewire-code" aria-labelledby="uptimewire-code-label"><?php echo esc_html( $pending['user_code'] ); ?></p>
			<p class="description"><?php esc_html_e( 'Check that UptimeWire shows the same code before you approve. You don’t need to type it.', 'uptimewire' ); ?></p>

			<div class="uptimewire-actions">
				<a class="button button-primary button-hero" href="<?php echo esc_url( $open ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open UptimeWire', 'uptimewire' ); ?><span class="screen-reader-text"> <?php esc_html_e( '(opens UptimeWire in a new tab)', 'uptimewire' ); ?></span></a>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( Admin::CANCEL_ACTION ); ?>">
					<?php wp_nonce_field( Admin::CANCEL_ACTION ); ?>
					<button type="submit" class="button button-hero"><?php esc_html_e( 'Cancel', 'uptimewire' ); ?></button>
				</form>
			</div>
			<p class="description">
				<?php
				printf(
					/* translators: %s: address of the UptimeWire connect page. */
					esc_html__( 'The tab didn’t open? Go to %s and enter the code.', 'uptimewire' ),
					'<a href="' . esc_url( $pending['verification_uri'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( preg_replace( '#^https://#', '', $pending['verification_uri'] ) ) . '</a>'
				);
				?>
			</p>

			<p class="uptimewire-status" role="status" aria-live="polite">
				<span class="spinner is-active" aria-hidden="true"></span>
				<span id="uptimewire-status-text"><?php echo esc_html( isset( $status[ $view['notice'] ] ) ? $status[ $view['notice'] ] : __( 'Waiting for your approval in UptimeWire…', 'uptimewire' ) ); ?></span>
			</p>
			<p>
				<?php
				printf(
					/* translators: 1: minutes and seconds left, such as 9:58; 2: time of day the code expires. */
					esc_html__( 'This code expires in %1$s (at %2$s).', 'uptimewire' ),
					'<span id="uptimewire-countdown">' . esc_html( sprintf( '%d:%02d', intdiv( (int) $view['seconds_left'], 60 ), (int) $view['seconds_left'] % 60 ) ) . '</span>',
					'<time datetime="' . esc_attr( gmdate( 'c', $expires ) ) . '">' . esc_html( wp_date( get_option( 'time_format' ), $expires ) ) . '</time>'
				);
				?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="uptimewire-check-form" class="uptimewire-check">
				<input type="hidden" name="action" value="<?php echo esc_attr( Admin::CHECK_ACTION ); ?>">
				<?php wp_nonce_field( Admin::CHECK_ACTION ); ?>
				<p>
					<button type="submit" class="button"><?php esc_html_e( 'Check Status', 'uptimewire' ); ?></button>
					<span class="description"><?php esc_html_e( 'After you approve in UptimeWire.', 'uptimewire' ); ?></span>
				</p>
			</form>

			<?php self::render_proof_help( false ); ?>
		</div>
		<?php
	}

	/**
	 * Prints the result of an attempt that ended without a connection.
	 *
	 * @param string $state AUTHORIZATION_EXPIRED, AUTHORIZATION_DENIED or CONNECTION_ERROR.
	 * @return void
	 */
	private static function render_outcome( $state ) {
		$outcome = Authorization::outcome();
		$reason  = null === $outcome ? '' : (string) $outcome['reason'];
		$title   = State::label( $state );
		$texts   = array();
		$help    = false;

		if ( State::AUTHORIZATION_EXPIRED === $state ) {
			$texts[] = __( 'The code expired. Start again.', 'uptimewire' );
			$help    = empty( $outcome['proof_seen'] );
		} elseif ( State::AUTHORIZATION_DENIED === $state ) {
			$texts[] = __( 'Connection cancelled. Nothing was changed.', 'uptimewire' );
			$texts[] = __( 'If you didn’t click Deny, your role in the UptimeWire account may not allow connecting websites, or the account is not active. Check in UptimeWire, then start again.', 'uptimewire' );
		} elseif ( 'site_mismatch' === $reason ) {
			$title = __( 'Website Addresses Don’t Match', 'uptimewire' );
			/* translators: 1: website address in UptimeWire; 2: address of this WordPress site. */
			$texts[] = sprintf( __( 'UptimeWire shows %1$s for this site, but this WordPress site is %2$s.', 'uptimewire' ), (string) $outcome['site_url'], (string) $outcome['local_url'] );
			$texts[] = __( 'The connection was not saved, so this site never shows the results of another website.', 'uptimewire' );
			/* translators: %s: address of this WordPress site. */
			$texts[] = sprintf( __( 'To connect, the website in UptimeWire must have the address %s. Start again and choose to monitor it as a new website. If your plan has no room for another website, first change the address of the website you already monitor in UptimeWire.', 'uptimewire' ), (string) $outcome['local_url'] );
			$texts[] = __( 'If visitors reach this site at the address UptimeWire shows, go back to the welcome screen and set it as the website to monitor.', 'uptimewire' );
		} elseif ( 'site_changed' === $reason ) {
			$texts[] = __( 'This site’s address is not the one the connection was started for, for example because the site was copied while connecting. Nothing was saved. Start again from the site you want to connect.', 'uptimewire' );
		} elseif ( 'invalid_grant' === $reason ) {
			$texts[] = __( 'The connection didn’t finish. Please try again.', 'uptimewire' );
		} else {
			$texts[] = __( 'Something went wrong while connecting. Nothing was saved. Please try again.', 'uptimewire' );
		}

		$warnings = Environment::warnings( wp_get_environment_type(), Plugin::site_url() );
		?>
		<div class="uptimewire-card uptimewire-outcome">
			<p class="uptimewire-state-line"><?php self::state_badge( $state ); ?></p>
			<h2><?php echo esc_html( $title ); ?></h2>
			<?php foreach ( $texts as $text ) : ?>
				<p><?php echo esc_html( $text ); ?></p>
			<?php endforeach; ?>

			<?php self::render_connect_form( $warnings, __( 'Start Again', 'uptimewire' ) ); ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Admin::DISMISS_ACTION ); ?>">
				<?php wp_nonce_field( Admin::DISMISS_ACTION ); ?>
				<p><button type="submit" class="button-link"><?php esc_html_e( 'Back to the welcome screen', 'uptimewire' ); ?></button></p>
			</form>

			<?php
			if ( $help ) {
				self::render_proof_help( true );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Prints the established connection: the monitoring dashboard and the connection details.
	 *
	 * @return void
	 */
	private static function render_connected() {
		$record = Connection::get();

		if ( null === $record ) {
			return;
		}

		Dashboard::render();
		?>
		<details class="uptimewire-connection-details">
			<summary><?php esc_html_e( 'Connection Details', 'uptimewire' ); ?></summary>
			<p class="uptimewire-state-line"><?php self::state_badge( State::CONNECTION_ESTABLISHED ); ?> <?php echo esc_html( self::health_text( $record ) ); ?></p>
			<?php self::render_connection_table( $record ); ?>
			<?php if ( Plugin::can_manage() ) : ?>
				<p><a href="<?php echo esc_url( Plugin::settings_url() ); ?>"><?php esc_html_e( 'Manage or disconnect in Settings', 'uptimewire' ); ?></a></p>
			<?php endif; ?>
		</details>
		<?php
	}

	/**
	 * Prints the connection details: website, account, date, where the credential is kept.
	 *
	 * @param array<string, mixed> $record Connection record.
	 * @return void
	 */
	public static function render_connection_table( array $record ) {
		$user      = ! empty( $record['connected_by'] ) ? get_userdata( (int) $record['connected_by'] ) : false;
		$connected = ! empty( $record['connected_at'] ) ? strtotime( (string) $record['connected_at'] ) : false;
		$label     = Connection::account_label();
		?>
		<table class="form-table uptimewire-summary" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Website', 'uptimewire' ); ?></th>
					<td><span class="uptimewire-site-address"><?php echo esc_html( isset( $record['site_url'] ) ? (string) $record['site_url'] : Plugin::site_url() ); ?></span></td>
				</tr>
				<?php if ( '' !== $label ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'UptimeWire account', 'uptimewire' ); ?></th>
						<td><span class="uptimewire-account"><?php echo esc_html( $label ); ?></span></td>
					</tr>
				<?php endif; ?>
				<?php if ( false !== $connected ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Connected', 'uptimewire' ); ?></th>
						<td>
							<time datetime="<?php echo esc_attr( gmdate( 'c', $connected ) ); ?>"><?php echo esc_html( wp_date( get_option( 'date_format' ), $connected ) ); ?></time>
							<?php
							if ( $user ) {
								/* translators: %s: WordPress user name. */
								echo esc_html( sprintf( __( 'by %s', 'uptimewire' ), $user->display_name ) );
							}
							?>
						</td>
					</tr>
				<?php endif; ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Credential', 'uptimewire' ); ?></th>
					<td>
						<?php
						if ( null !== Connection::configured_token() ) {
							esc_html_e( 'Kept in wp-config.php (UPTIMEWIRE_TOKEN).', 'uptimewire' );
						} else {
							esc_html_e( 'Kept in the WordPress database. It is never shown.', 'uptimewire' );
						}
						?>
					</td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Health of the connection in words.
	 *
	 * @param array<string, mixed> $record Connection record.
	 * @return string
	 */
	public static function health_text( array $record ) {
		$confirmed = isset( $record['health']['confirmed_at'] ) ? (int) $record['health']['confirmed_at'] : 0;
		$when      = $confirmed ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $confirmed ) : '';

		switch ( Connection::health_state( $record ) ) {
			case Connection::HEALTH_OK:
				/* translators: %s: date and time UptimeWire last confirmed the connection. */
				return sprintf( __( 'Working. UptimeWire confirmed it on %s.', 'uptimewire' ), $when );
			case Connection::HEALTH_UNREACHABLE:
				return $confirmed
					/* translators: %s: date and time UptimeWire last confirmed the connection. */
					? sprintf( __( 'Couldn’t reach UptimeWire just now. Last confirmed on %s.', 'uptimewire' ), $when )
					: __( 'Couldn’t reach UptimeWire just now.', 'uptimewire' );
			default:
				return __( 'Not checked yet.', 'uptimewire' );
		}
	}

	/**
	 * Prints the screen of a connection UptimeWire ended.
	 *
	 * @return void
	 */
	private static function render_reconnect() {
		$label = Connection::account_label();
		?>
		<div class="uptimewire-card uptimewire-outcome">
			<p class="uptimewire-state-line"><?php self::state_badge( State::RECONNECTION_REQUIRED ); ?></p>
			<h2><?php esc_html_e( 'Reconnect to UptimeWire', 'uptimewire' ); ?></h2>
			<p><?php esc_html_e( 'This site’s connection to UptimeWire is no longer active, so its monitoring results can’t be shown here. UptimeWire doesn’t tell WordPress why. For example, the connection may have been removed in UptimeWire, the website deleted there, or the connection unused for 90 days. Monitoring and alerts in UptimeWire don’t depend on this connection and keep running.', 'uptimewire' ); ?></p>
			<?php if ( null !== Connection::configured_token() ) : ?>
				<p><?php esc_html_e( 'Remove UPTIMEWIRE_TOKEN from wp-config.php, then reconnect from this screen.', 'uptimewire' ); ?></p>
			<?php else : ?>
				<?php if ( '' !== $label ) : ?>
					<p>
						<?php
						/* translators: %s: UptimeWire account number, such as ACC-00001000. */
						echo esc_html( sprintf( __( 'This site was connected to UptimeWire account %s. Approve the new connection in the same account to keep its monitoring history.', 'uptimewire' ), $label ) );
						?>
					</p>
				<?php endif; ?>
				<?php self::render_connect_form( Environment::warnings( wp_get_environment_type(), Plugin::site_url() ), __( 'Reconnect', 'uptimewire' ) ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Prints the screen of a connection made on another WordPress address: a
	 * copy of the site, or a site that moved. Nothing of the connection is used.
	 *
	 * @return void
	 */
	private static function render_copy() {
		$record = Connection::get();

		if ( null === $record ) {
			return;
		}
		?>
		<div class="uptimewire-card uptimewire-outcome uptimewire-copy">
			<p class="uptimewire-state-line"><?php self::state_badge( State::SITE_COPY ); ?></p>
			<h2><?php esc_html_e( 'This WordPress Site Is a Copy', 'uptimewire' ); ?></h2>
			<p><?php esc_html_e( 'The existing UptimeWire connection belongs to another website.', 'uptimewire' ); ?></p>
			<p>
				<?php
				/* translators: 1: WordPress address the connection was made on; 2: address of this WordPress site. */
				echo esc_html( sprintf( __( 'It was created for the WordPress site at %1$s, but this WordPress site is at %2$s. To protect the original website, this copy doesn’t show its monitoring data and doesn’t use its credential.', 'uptimewire' ), Connection::recorded_home( $record ), home_url( '/' ) ) );
				?>
			</p>
			<p><?php esc_html_e( 'Remove the copied connection from this installation to set up monitoring for this website. Only this WordPress site forgets it: the original website stays connected, and UptimeWire keeps monitoring it.', 'uptimewire' ); ?></p>
			<?php self::render_remove_local_form( __( 'Remove Connection From This Copy', 'uptimewire' ) ); ?>
			<h3><?php esc_html_e( 'Did This Site Move Here?', 'uptimewire' ); ?></h3>
			<p><?php esc_html_e( 'A site that moved to a new address looks the same to WordPress. Use the button above to remove the connection here too, then connect again from the Overview screen. Connecting again replaces the old connection in UptimeWire, so do it only if the old site no longer uses it.', 'uptimewire' ); ?></p>
			<p><?php esc_html_e( 'If UptimeWire still monitors the old address, change the website address in UptimeWire first, or choose to monitor this address as a new website while connecting.', 'uptimewire' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Prints the form that removes the connection from this installation only.
	 *
	 * @param string $label   Button label.
	 * @param bool   $primary Whether it is the main action of the screen.
	 * @return void
	 */
	private static function render_remove_local_form( $label, $primary = true ) {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Admin::REMOVE_LOCAL_ACTION ); ?>">
			<?php wp_nonce_field( Admin::REMOVE_LOCAL_ACTION ); ?>
			<p><button type="submit" class="button<?php echo $primary ? ' button-primary' : ''; ?>"><?php echo esc_html( $label ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Prints the state for a user who may see the plugin but not manage the connection.
	 *
	 * @param string $state State constant.
	 * @return void
	 */
	private static function render_view_only( $state ) {
		$texts = array(
			State::NOT_CONNECTED         => __( 'This website isn’t connected to UptimeWire yet.', 'uptimewire' ),
			State::RECONNECTION_REQUIRED => __( 'This site’s connection to UptimeWire is no longer active, so its monitoring results can’t be shown here.', 'uptimewire' ),
			State::SITE_COPY             => __( 'This WordPress site is a copy, or it moved: its UptimeWire connection was made on another address, so no monitoring results are shown here.', 'uptimewire' ),
		);
		?>
		<div class="uptimewire-card uptimewire-outcome">
			<p class="uptimewire-state-line"><?php self::state_badge( $state ); ?></p>
			<p><?php echo esc_html( isset( $texts[ $state ] ) ? $texts[ $state ] : __( 'The connection to UptimeWire isn’t working right now, so no monitoring results are shown here.', 'uptimewire' ) ); ?></p>
			<p><?php esc_html_e( 'Only an administrator of this site can connect it to UptimeWire or change the connection.', 'uptimewire' ); ?></p>
		</div>
		<?php
	}

	/**
	 * Prints the notices that outlive one screen: an unconfirmed disconnect, a
	 * disconnected UPTIMEWIRE_TOKEN still in wp-config.php, a changed account.
	 *
	 * @return void
	 */
	public static function render_lifecycle_notices() {
		$change = Plugin::can_manage() ? Authorization::take_account_change() : null;

		if ( null !== $change ) {
			wp_admin_notice(
				/* translators: 1: UptimeWire account number of the new connection; 2: account number of the earlier one. */
				esc_html( sprintf( __( 'This site is now connected to UptimeWire account %1$s. Before, it was connected to account %2$s. If that wasn’t intended, disconnect in Settings and connect again in the right account.', 'uptimewire' ), $change['to'], $change['from'] ) ),
				array(
					'type'               => 'warning',
					'dismissible'        => true,
					'additional_classes' => array( 'inline' ),
				)
			);
		}

		if ( Connection::constant_left_behind() ) {
			wp_admin_notice(
				esc_html__( 'UPTIMEWIRE_TOKEN is still in wp-config.php. This site no longer uses it, but the file still holds the old credential: remove the line from wp-config.php. The plugin can’t edit that file.', 'uptimewire' ),
				array(
					'type'               => 'warning',
					'additional_classes' => array( 'inline' ),
				)
			);
		}

		$unconfirmed = Connection::unconfirmed();

		if ( null === $unconfirmed || ! Plugin::can_manage() ) {
			return;
		}
		?>
		<div class="notice notice-warning inline uptimewire-unconfirmed">
			<p><strong><?php esc_html_e( 'UptimeWire didn’t confirm the disconnect', 'uptimewire' ); ?></strong></p>
			<p>
				<?php
				/* translators: %s: date and time of the disconnect. */
				echo esc_html( sprintf( __( 'This site was disconnected on %s, but UptimeWire couldn’t be reached to end the connection there. WordPress no longer keeps or uses the credential.', 'uptimewire' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $unconfirmed['at'] ) ) );
				?>
			</p>
			<p>
				<?php
				echo esc_html(
					'' !== $unconfirmed['account_label']
						/* translators: 1: website address; 2: UptimeWire account number. */
						? sprintf( __( 'To end it for sure, remove the WordPress connection of %1$s in UptimeWire account %2$s, under My Profile › WordPress. Otherwise UptimeWire ends it by itself after 90 days without use. Connecting this website again in the same account also replaces the old connection.', 'uptimewire' ), $unconfirmed['site_url'], $unconfirmed['account_label'] )
						/* translators: %s: website address. */
						: sprintf( __( 'To end it for sure, remove the WordPress connection of %s in UptimeWire, under My Profile › WordPress. Otherwise UptimeWire ends it by itself after 90 days without use. Connecting this website again in the same account also replaces the old connection.', 'uptimewire' ), $unconfirmed['site_url'] )
				);
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uptimewire-actions">
				<?php View::external_link( self::CONNECTIONS, __( 'Open Connected WordPress Sites', 'uptimewire' ) ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( Admin::DISMISS_UNCONFIRMED_ACTION ); ?>">
				<?php wp_nonce_field( Admin::DISMISS_UNCONFIRMED_ACTION ); ?>
				<button type="submit" class="button"><?php esc_html_e( 'I Removed It in UptimeWire', 'uptimewire' ); ?></button>
			</form>
		</div>
		<?php
	}

	/**
	 * Prints a stored connection that cannot be used as it is.
	 *
	 * @return void
	 */
	private static function render_connection_problem() {
		$record = Connection::get();
		$health = null === $record ? '' : Connection::health_state( $record );
		?>
		<div class="uptimewire-card uptimewire-outcome">
			<p class="uptimewire-state-line"><?php self::state_badge( State::CONNECTION_ERROR ); ?></p>
			<?php if ( Connection::HEALTH_MISMATCH === $health ) : ?>
				<h2><?php esc_html_e( 'Website Addresses Don’t Match', 'uptimewire' ); ?></h2>
				<p>
					<?php
					/* translators: 1: website address in UptimeWire; 2: address of this WordPress site. */
					echo esc_html( sprintf( __( 'UptimeWire says this connection belongs to %1$s, but this site is %2$s. The website address may have been changed in UptimeWire. Its monitoring data is not shown here.', 'uptimewire' ), (string) $record['site_url'], Plugin::site_url() ) );
					?>
				</p>
				<p><?php esc_html_e( 'If this site’s address is the right one, change the website address in UptimeWire, then check again.', 'uptimewire' ); ?></p>
				<div class="uptimewire-actions">
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( Admin::REFRESH_ACTION ); ?>">
						<?php wp_nonce_field( Admin::REFRESH_ACTION ); ?>
						<button type="submit" class="button button-primary"><?php esc_html_e( 'Check Again', 'uptimewire' ); ?></button>
					</form>
					<?php View::external_link( self::DASHBOARD, __( 'Open UptimeWire', 'uptimewire' ) ); ?>
				</div>
				<p><?php esc_html_e( 'To stop using this connection here instead, remove it from this site. That doesn’t end it in UptimeWire: remove it there as well, under My Profile › WordPress.', 'uptimewire' ); ?></p>
				<?php self::render_remove_local_form( __( 'Remove Connection From This Site', 'uptimewire' ), false ); ?>
			<?php elseif ( Connection::HEALTH_BAD_CONSTANT === $health ) : ?>
				<h2><?php esc_html_e( 'Connection Error', 'uptimewire' ); ?></h2>
				<p><?php esc_html_e( 'UPTIMEWIRE_TOKEN in wp-config.php is not a valid UptimeWire credential. Correct it, or remove it and connect from this screen.', 'uptimewire' ); ?></p>
			<?php else : ?>
				<h2><?php esc_html_e( 'Connection Error', 'uptimewire' ); ?></h2>
				<p><?php esc_html_e( 'UPTIMEWIRE_TOKEN is set in wp-config.php, but UptimeWire hasn’t confirmed it yet because it couldn’t be reached. This screen tries again in a few minutes.', 'uptimewire' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Prints what to do when UptimeWire cannot confirm the website.
	 *
	 * @param bool $open Whether the help starts expanded.
	 * @return void
	 */
	private static function render_proof_help( $open ) {
		?>
		<details class="uptimewire-help"<?php echo $open ? ' open' : ''; ?>>
			<summary><?php esc_html_e( 'UptimeWire couldn’t confirm your website?', 'uptimewire' ); ?></summary>
			<p><?php esc_html_e( 'After you approve, UptimeWire opens one address on your website to make sure it is this WordPress. If UptimeWire says it couldn’t:', 'uptimewire' ); ?></p>
			<ul>
				<li>
					<?php esc_html_e( 'Cloudflare or another firewall may block the check. Allow UptimeWire’s checks there — you don’t need to turn the protection off.', 'uptimewire' ); ?>
					<?php View::external_link( Plugin::website_url( self::FIREWALL_GUIDE ), __( 'How to allow UptimeWire', 'uptimewire' ) ); ?>
					<span aria-hidden="true">&middot;</span>
					<?php View::external_link( self::IP_LIST, __( 'UptimeWire addresses', 'uptimewire' ) ); ?>
				</li>
				<li><?php esc_html_e( 'A security plugin may block it. Allow the UptimeWire addresses or the “UptimeWireBot” user agent in its settings.', 'uptimewire' ); ?></li>
				<li><?php esc_html_e( 'A page cache or CDN that ignores query strings may answer with a stored page. Exclude addresses with “uptimewire_proof” from the cache.', 'uptimewire' ); ?></li>
				<li><?php esc_html_e( 'Maintenance mode, a “coming soon” page or a password on the whole site keeps UptimeWire out while it is on.', 'uptimewire' ); ?></li>
				<li><?php esc_html_e( 'The website to monitor must be the address visitors use.', 'uptimewire' ); ?></li>
			</ul>
			<p><?php esc_html_e( 'Then click Allow in UptimeWire again while the code is valid.', 'uptimewire' ); ?></p>
		</details>
		<?php
	}

	/**
	 * Prints the state with an icon. The words carry the meaning, not the color.
	 *
	 * @param string $state State constant.
	 * @return void
	 */
	public static function state_badge( $state ) {
		$icons = array(
			State::NOT_CONNECTED          => array( 'dashicons-dismiss', 'off' ),
			State::AUTHORIZATION_PENDING  => array( 'dashicons-clock', 'wait' ),
			State::WAITING_FOR_APPROVAL   => array( 'dashicons-clock', 'wait' ),
			State::AUTHORIZATION_EXPIRED  => array( 'dashicons-warning', 'warn' ),
			State::AUTHORIZATION_DENIED   => array( 'dashicons-dismiss', 'off' ),
			State::CONNECTION_ESTABLISHED => array( 'dashicons-yes-alt', 'ok' ),
			State::CONNECTION_ERROR       => array( 'dashicons-warning', 'error' ),
			State::RECONNECTION_REQUIRED  => array( 'dashicons-warning', 'warn' ),
			State::SITE_COPY              => array( 'dashicons-info', 'warn' ),
		);
		$icon  = isset( $icons[ $state ] ) ? $icons[ $state ] : $icons[ State::NOT_CONNECTED ];

		printf(
			'<span class="uptimewire-state uptimewire-state-%1$s"><span class="dashicons %2$s" aria-hidden="true"></span> %3$s</span>',
			esc_attr( $icon[1] ),
			esc_attr( $icon[0] ),
			esc_html( State::label( $state ) )
		);
	}

	/**
	 * Prints one warning per environment problem.
	 *
	 * @param string[] $warnings Environment::WARNING_* constants.
	 * @return void
	 */
	private static function render_warnings( array $warnings ) {
		$messages = array(
			Environment::WARNING_LOCAL    => array(
				'type' => 'error',
				'text' => __( "This looks like a local site. UptimeWire checks websites from the internet, so it can't reach this one. Install the plugin on your live site instead.", 'uptimewire' ),
			),
			Environment::WARNING_STAGING  => array(
				'type' => 'warning',
				'text' => __( 'This looks like a staging or development site. Usually you only monitor the live site. Continue only if this site is public and you want alerts for it.', 'uptimewire' ),
			),
			Environment::WARNING_NO_HTTPS => array(
				'type' => 'info',
				'text' => __( "Your site doesn't use HTTPS. Uptime, domain and DNS will be checked, but there's no certificate to check.", 'uptimewire' ),
			),
		);

		foreach ( $warnings as $warning ) {
			if ( ! isset( $messages[ $warning ] ) ) {
				continue;
			}

			printf(
				'<div class="notice notice-%1$s inline uptimewire-warning"><p>%2$s</p></div>',
				esc_attr( $messages[ $warning ]['type'] ),
				esc_html( $messages[ $warning ]['text'] )
			);
		}
	}

	/**
	 * Prints the "Website to monitor" row with its Change form.
	 *
	 * @param string $site_url Current address.
	 * @return void
	 */
	private static function render_site_url( $site_url ) {
		?>
		<div class="uptimewire-site">
			<p class="uptimewire-site-current">
				<?php esc_html_e( 'Website to monitor:', 'uptimewire' ); ?>
				<strong class="uptimewire-site-address"><?php echo esc_html( $site_url ); ?></strong>
			</p>
			<details class="uptimewire-site-change">
				<summary><?php esc_html_e( 'Change', 'uptimewire' ); ?></summary>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( Admin::SAVE_SITE_URL_ACTION ); ?>">
					<?php wp_nonce_field( Admin::SAVE_SITE_URL_ACTION ); ?>
					<p>
						<label for="uptimewire-site-url"><?php esc_html_e( 'Website address', 'uptimewire' ); ?></label>
						<input type="url" id="uptimewire-site-url" name="uptimewire_site_url" class="regular-text code" value="<?php echo esc_attr( $site_url ); ?>" required aria-describedby="uptimewire-site-url-help">
					</p>
					<p id="uptimewire-site-url-help" class="description">
						<?php esc_html_e( 'The address visitors use, starting with https:// or http://. By default it is the Site Address from Settings › General.', 'uptimewire' ); ?>
					</p>
					<p class="uptimewire-site-buttons">
						<?php submit_button( __( 'Save address', 'uptimewire' ), 'secondary', 'uptimewire_save', false ); ?>
						<?php if ( Plugin::site_url_is_custom() ) : ?>
							<button type="submit" name="uptimewire_reset" value="1" class="button-link" formnovalidate><?php esc_html_e( 'Use the WordPress Site Address', 'uptimewire' ); ?></button>
						<?php endif; ?>
					</p>
				</form>
			</details>
		</div>
		<?php
	}

	/**
	 * Prints the message left by a form or the script after its redirect.
	 *
	 * @return void
	 */
	private static function render_result_notice() {
		// Display only: the message arguments change nothing, so they carry no nonce.
		$code = isset( $_GET[ Admin::NOTICE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ Admin::NOTICE_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$wait = isset( $_GET[ Admin::WAIT_ARG ] ) ? absint( $_GET[ Admin::WAIT_ARG ] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$notices = array(
			'site_url_saved'     => array( 'success', __( 'Website address saved.', 'uptimewire' ) ),
			'site_url_reset'     => array( 'success', __( 'The WordPress Site Address is used again.', 'uptimewire' ) ),
			'site_url_invalid'   => array( 'error', __( 'That is not a website address. Enter a full address such as https://example.com/ without a query string.', 'uptimewire' ) ),
			'connected'          => array( 'success', __( 'Your WordPress website is connected to UptimeWire.', 'uptimewire' ) ),
			'cancelled'          => array( 'info', __( 'Connection cancelled. Nothing was changed.', 'uptimewire' ) ),
			'tab_blocked'        => array( 'warning', __( 'Your browser didn’t open the new tab. Click “Open UptimeWire” to continue.', 'uptimewire' ) ),
			'already_connected'  => array( 'info', __( 'This website is already connected to UptimeWire.', 'uptimewire' ) ),
			'token_in_config'    => array( 'error', __( 'UPTIMEWIRE_TOKEN is set in wp-config.php. Remove it before connecting this website again.', 'uptimewire' ) ),
			'local_site'         => array( 'error', __( 'A local site cannot be connected: UptimeWire checks websites from the internet.', 'uptimewire' ) ),
			'needs_confirmation' => array( 'error', __( 'Check “I understand” to confirm that this staging or development site is public, then try again.', 'uptimewire' ) ),
			'busy'               => array( 'warning', __( 'A connection request is already in progress. Wait a few seconds and try again.', 'uptimewire' ) ),
			'rate_limited'       => array(
				'error',
				$wait > 0
					/* translators: %s: how long to wait, such as "5 mins". */
					? sprintf( __( 'UptimeWire received too many connection requests from this server. Try again in %s.', 'uptimewire' ), human_time_diff( time(), time() + $wait ) )
					: __( 'UptimeWire received too many connection requests from this server. Try again later.', 'uptimewire' ),
			),
			'site_rejected'      => array( 'error', __( 'UptimeWire can’t monitor this website address. It must be a public address starting with https:// or http://, without a query string. Check the website to monitor below.', 'uptimewire' ) ),
			'unreachable'        => array( 'error', __( 'Couldn’t reach UptimeWire. Nothing was changed. Check that this server can make outgoing HTTPS connections, then try again.', 'uptimewire' ) ),
			'unexpected'         => array( 'error', __( 'UptimeWire sent an answer the plugin doesn’t understand. Nothing was changed. Please try again later.', 'uptimewire' ) ),
			'status_refreshed'   => array( 'success', __( 'Showing the latest results from UptimeWire. Refreshing doesn’t run a new check.', 'uptimewire' ) ),
			'status_busy'        => array( 'info', __( 'The status is already being updated. Reload this page in a few seconds.', 'uptimewire' ) ),
			'status_wait'        => array(
				'warning',
				$wait > 0
					/* translators: %s: how long to wait, such as "1 min". */
					? sprintf( __( 'UptimeWire asked to wait before the next update. Try again in %s.', 'uptimewire' ), human_time_diff( time(), time() + $wait ) )
					: __( 'UptimeWire asked to wait before the next update. Try again later.', 'uptimewire' ),
			),
			'status_failed'      => array( 'warning', __( 'Couldn’t get the latest status from UptimeWire. The screen shows the last status received, if any.', 'uptimewire' ) ),
			'disconnected'       => array( 'success', __( 'WordPress is disconnected from UptimeWire. UptimeWire keeps monitoring your website and sending the alerts you set up. Your account, monitoring history and website settings are unchanged.', 'uptimewire' ) ),
			'disconnect_refused' => array( 'error', __( 'This connection wasn’t made on this WordPress site, so it can’t be ended from here. Nothing was changed.', 'uptimewire' ) ),
			'copy_removed'       => array( 'success', __( 'The connection was removed from this WordPress site only. The website it belongs to stays connected. You can now set up monitoring for this site.', 'uptimewire' ) ),
			'site_url_locked'    => array( 'error', __( 'The website to monitor can’t be changed while this site has an UptimeWire connection. Nothing was changed.', 'uptimewire' ) ),
		);

		if ( ! isset( $notices[ $code ] ) ) {
			return;
		}

		wp_admin_notice(
			$notices[ $code ][1],
			array(
				'type'        => $notices[ $code ][0],
				'dismissible' => true,
			)
		);
	}
}
