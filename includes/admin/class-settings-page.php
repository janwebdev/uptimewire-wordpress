<?php
/**
 * Settings screen: connection, monitoring, alerts, plans and troubleshooting.
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
 * Renders what the plugin knows about the connection and the monitoring, from
 * the kept status. Monitoring and alerts are configured in UptimeWire; this
 * screen links there instead of copying those settings. Connecting itself
 * happens on Overview.
 */
final class Settings_Page {

	/**
	 * Prints the screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! Plugin::can_view() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'uptimewire' ), '', array( 'response' => 403 ) );
		}

		Connection::check_health();
		$state     = State::current();
		$connected = State::CONNECTION_ESTABLISHED === $state;
		$record    = $connected ? Connection::get() : null;
		$status    = Status::get();
		$data      = $connected ? Status::data() : null;
		$stale     = null !== $data && Status::is_stale( $status );
		$confirm   = isset( $_GET[ Admin::CONFIRM_ARG ] ) && 'disconnect' === $_GET[ Admin::CONFIRM_ARG ]; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Display only: the Disconnect form itself carries the nonce.

		if ( $confirm && self::may_disconnect() ) {
			self::render_confirm_disconnect();

			return;
		}
		?>
		<div class="wrap uptimewire uptimewire-settings">
			<h1><?php esc_html_e( 'UptimeWire Settings', 'uptimewire' ); ?></h1>
			<hr class="wp-header-end">

			<?php Overview_Page::render_lifecycle_notices(); ?>

			<?php if ( $stale ) : ?>
				<div class="notice notice-info inline">
					<p>
						<?php
						/* translators: %s: date and time the data was received from UptimeWire. */
						echo esc_html( sprintf( __( 'The monitoring details below were received from UptimeWire on %s. Current monitoring status is unavailable.', 'uptimewire' ), self::date_time( $status['fetched_at'] ) ) );
						?>
					</p>
				</div>
			<?php endif; ?>

			<?php self::render_connection( $state, $record, $status, $data ); ?>

			<?php if ( $connected ) : ?>
				<?php self::render_monitoring( $data, $stale ); ?>
				<?php self::render_alerts( $data, $stale ); ?>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Plans', 'uptimewire' ); ?></h2>
			<p><?php View::external_link( null !== $data && null !== $data['links']['plans'] ? $data['links']['plans'] : Plugin::website_url( 'pricing/' ), __( 'View Plans & Features', 'uptimewire' ) ); ?></p>

			<?php self::render_troubleshooting(); ?>

			<?php View::footnote(); ?>
		</div>
		<?php
	}

	/**
	 * Prints the connection: status, website, account, dates and the actions.
	 *
	 * @param string                    $state  State constant.
	 * @param array<string, mixed>|null $record Connection record, when established.
	 * @param array<string, mixed>|null $status Kept status.
	 * @param array<string, mixed>|null $data   Kept data of this website.
	 * @return void
	 */
	private static function render_connection( $state, $record, $status, $data ) {
		$manage = Plugin::can_manage();
		?>
		<h2><?php esc_html_e( 'Connection', 'uptimewire' ); ?></h2>
		<table class="form-table uptimewire-summary" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'uptimewire' ); ?></th>
					<td>
						<?php Overview_Page::state_badge( $state ); ?>
						<?php if ( null !== $record ) : ?>
							<span class="description"><?php echo esc_html( Overview_Page::health_text( $record ) ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
				<?php if ( null === $record ) : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Website', 'uptimewire' ); ?></th>
						<td><span class="uptimewire-site-address"><?php echo esc_html( Plugin::site_url() ); ?></span></td>
					</tr>
				<?php else : ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Last status received', 'uptimewire' ); ?></th>
						<td>
							<?php
							if ( null !== $status && $status['fetched_at'] > 0 ) {
								echo Dashboard::ago_html( $status['fetched_at'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in ago_html().
							} else {
								esc_html_e( 'Not yet', 'uptimewire' );
							}
							?>
						</td>
					</tr>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
		if ( null !== $record ) {
			Overview_Page::render_connection_table( $record );
		}
		?>
		<p class="uptimewire-actions">
			<?php
			switch ( $state ) {
				case State::CONNECTION_ESTABLISHED:
					Dashboard::link_button( null !== $data && null !== $data['links']['site'] ? $data['links']['site'] : Overview_Page::DASHBOARD, __( 'Open UptimeWire', 'uptimewire' ), false );
					break;
				case State::NOT_CONNECTED:
					if ( $manage ) {
						echo '<a class="button button-primary" href="' . esc_url( Plugin::overview_url() ) . '">' . esc_html__( 'Set Up Monitoring', 'uptimewire' ) . '</a>';
					}
					break;
				case State::RECONNECTION_REQUIRED:
					if ( $manage ) {
						echo '<a class="button button-primary" href="' . esc_url( Plugin::overview_url() ) . '">' . esc_html__( 'Reconnect', 'uptimewire' ) . '</a>';
					}
					break;
				default:
					// Approval in progress, a failed attempt or a connection of another address: the Overview screen explains and offers the next step.
					echo '<a class="button" href="' . esc_url( Plugin::overview_url() ) . '">' . esc_html__( 'Continue on the Overview Screen', 'uptimewire' ) . '</a>';
			}
			?>
		</p>
		<?php if ( self::may_disconnect() ) : ?>
			<p class="uptimewire-disconnect">
				<a class="button uptimewire-button-danger" href="<?php echo esc_url( add_query_arg( Admin::CONFIRM_ARG, 'disconnect', Plugin::settings_url() ) ); ?>"><?php esc_html_e( 'Disconnect', 'uptimewire' ); ?></a>
				<span class="description"><?php esc_html_e( 'WordPress stops showing monitoring results. Monitoring and alerts in UptimeWire continue.', 'uptimewire' ); ?></span>
			</p>
		<?php elseif ( ! $manage && in_array( $state, array( State::NOT_CONNECTED, State::RECONNECTION_REQUIRED ), true ) ) : ?>
			<p class="description"><?php esc_html_e( 'Only an administrator of this site can connect it to UptimeWire or change the connection.', 'uptimewire' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Whether the current user may disconnect this site: a manager, and a
	 * connection made on this WordPress site (a copy only removes its own).
	 *
	 * @return bool
	 */
	private static function may_disconnect() {
		$record = Connection::get();

		return Plugin::can_manage() && null !== $record && Connection::is_own( $record ) && Connection::may_touch_constant();
	}

	/**
	 * Prints the confirmation of a disconnect. Nothing changes until the form is sent.
	 *
	 * @return void
	 */
	private static function render_confirm_disconnect() {
		?>
		<div class="wrap uptimewire uptimewire-settings">
			<h1><?php esc_html_e( 'UptimeWire Settings', 'uptimewire' ); ?></h1>
			<hr class="wp-header-end">
			<div class="uptimewire-card uptimewire-confirm" role="group" aria-labelledby="uptimewire-confirm-title">
				<h2 id="uptimewire-confirm-title"><?php esc_html_e( 'Disconnect UptimeWire?', 'uptimewire' ); ?></h2>
				<?php
				$about = Connection::get();
				$site  = null !== $about && isset( $about['site_url'] ) && is_string( $about['site_url'] ) ? $about['site_url'] : Plugin::site_url();
				$label = Connection::account_label();
				?>
				<p class="description">
					<?php
					echo esc_html(
						'' !== $label
							/* translators: 1: website address; 2: UptimeWire account number. */
							? sprintf( __( 'Website: %1$s · UptimeWire account %2$s', 'uptimewire' ), $site, $label )
							/* translators: %s: website address. */
							: sprintf( __( 'Website: %s', 'uptimewire' ), $site )
					);
					?>
				</p>
				<p><?php esc_html_e( 'This WordPress website will stop displaying monitoring results.', 'uptimewire' ); ?></p>
				<p><?php esc_html_e( 'UptimeWire will continue monitoring your website and sending configured alerts.', 'uptimewire' ); ?></p>
				<p><?php esc_html_e( 'Your UptimeWire account, monitoring history, and website settings will remain unchanged.', 'uptimewire' ); ?></p>
				<?php $record = Connection::get(); ?>
			<?php if ( null !== $record && ! Connection::may_end_remotely( $record ) && Connection::HEALTH_ENDED !== Connection::health_state( $record ) ) : ?>
				<p><?php esc_html_e( 'UptimeWire hasn’t confirmed yet that this connection belongs to this WordPress address, so WordPress only forgets it here and doesn’t end it in UptimeWire. Remove it there as well.', 'uptimewire' ); ?></p>
			<?php endif; ?>
			<?php if ( null !== Connection::configured_token() ) : ?>
					<p><strong><?php esc_html_e( 'The credential is in wp-config.php (UPTIMEWIRE_TOKEN). WordPress can’t edit that file: after disconnecting, remove that line yourself.', 'uptimewire' ); ?></strong></p>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uptimewire-actions">
					<input type="hidden" name="action" value="<?php echo esc_attr( Admin::DISCONNECT_ACTION ); ?>">
					<?php wp_nonce_field( Admin::DISCONNECT_ACTION ); ?>
					<a class="button" href="<?php echo esc_url( Plugin::settings_url() ); ?>"><?php esc_html_e( 'Cancel', 'uptimewire' ); ?></a>
					<button type="submit" class="button button-primary uptimewire-button-danger"><?php esc_html_e( 'Disconnect', 'uptimewire' ); ?></button>
				</form>
			</div>
		</div>
		<?php
	}

	/**
	 * Prints the monitoring: status, interval and the checks of the plan.
	 *
	 * @param array<string, mixed>|null $data  Kept data of this website.
	 * @param bool                      $stale Whether the data is only the last known status.
	 * @return void
	 */
	private static function render_monitoring( $data, $stale ) {
		?>
		<h2><?php esc_html_e( 'Monitoring', 'uptimewire' ); ?></h2>
		<?php if ( null === $data ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: link to the UptimeWire Overview screen. */
					esc_html__( 'Monitoring details appear here after WordPress receives the status from UptimeWire. Open the %s screen to get it.', 'uptimewire' ),
					'<a href="' . esc_url( Plugin::overview_url() ) . '">' . esc_html__( 'Overview', 'uptimewire' ) . '</a>'
				);
				?>
			</p>
			<?php
			return;
		endif;

		$site     = $data['site'];
		$headline = Status_Data::headline( $site );
		$checks   = array(
			__( 'Uptime', 'uptimewire' )            => __( 'Included', 'uptimewire' ),
			__( 'SSL certificate', 'uptimewire' )   => self::check_words( $data['ssl']['state'] ),
			__( 'Domain expiration', 'uptimewire' ) => self::check_words( $data['domain']['state'] ),
			__( 'DNS monitoring', 'uptimewire' )    => self::check_words( $data['dns']['state'] ),
		);
		?>
		<table class="form-table uptimewire-summary" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><?php esc_html_e( 'External monitoring', 'uptimewire' ); ?></th>
					<td>
						<?php
						$look = $stale ? array( 'unknown', 'dashicons-backup' ) : Dashboard::look( $headline );
						printf(
							'<span class="uptimewire-state uptimewire-tone-%1$s"><span class="dashicons %2$s" aria-hidden="true"></span> %3$s</span>',
							esc_attr( $look[0] ),
							esc_attr( $look[1] ),
							/* translators: %s: the last status UptimeWire reported, such as "Your Website Is Online". */
							esc_html( $stale ? sprintf( __( 'Last known: %s', 'uptimewire' ), Dashboard::title( $headline ) ) : Dashboard::title( $headline ) )
						);
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Check interval', 'uptimewire' ); ?></th>
					<td>
						<?php
						echo null !== $site['interval_minutes']
							/* translators: %d: minutes between checks. */
							? esc_html( sprintf( _n( 'Every %d minute', 'Every %d minutes', $site['interval_minutes'], 'uptimewire' ), $site['interval_minutes'] ) )
							: esc_html__( 'Unknown', 'uptimewire' );
						?>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Checks', 'uptimewire' ); ?></th>
					<td>
						<ul class="uptimewire-check-list">
							<?php foreach ( $checks as $name => $words ) : ?>
								<li><strong><?php echo esc_html( $name ); ?>:</strong> <?php echo esc_html( $words ); ?></li>
							<?php endforeach; ?>
						</ul>
					</td>
				</tr>
			</tbody>
		</table>
		<p>
			<?php
			Dashboard::external( null !== $data['links']['site'] ? $data['links']['site'] : Overview_Page::DASHBOARD, __( 'Change monitoring settings in UptimeWire', 'uptimewire' ) );
			?>
		</p>
		<?php
	}

	/**
	 * Whether a check is part of the monitoring, in words.
	 *
	 * @param string $state Check state.
	 * @return string
	 */
	private static function check_words( $state ) {
		switch ( $state ) {
			case 'not_on_plan':
				return __( 'Not in your plan', 'uptimewire' );
			case 'not_applicable':
				return __( 'Not applicable: the website uses http://', 'uptimewire' );
			case 'unknown':
				return __( 'Included, not checked yet', 'uptimewire' );
			default:
				return __( 'Included', 'uptimewire' );
		}
	}

	/**
	 * Prints the alert readiness.
	 *
	 * @param array<string, mixed>|null $data  Kept data of this website.
	 * @param bool                      $stale Whether the data is only the last known status.
	 * @return void
	 */
	private static function render_alerts( $data, $stale ) {
		if ( null === $data ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Alerts', 'uptimewire' ); ?></h2>
		<div class="uptimewire-settings-alerts">
			<?php Dashboard::render_alert_readiness( $data, Status_Data::headline( $data['site'] ), $stale ); ?>
		</div>
		<?php
	}

	/**
	 * Prints the troubleshooting help.
	 *
	 * @return void
	 */
	private static function render_troubleshooting() {
		?>
		<h2><?php esc_html_e( 'Troubleshooting', 'uptimewire' ); ?></h2>
		<ul class="uptimewire-troubleshooting">
			<li>
				<strong><?php esc_html_e( 'Connection:', 'uptimewire' ); ?></strong>
				<?php esc_html_e( 'If the connection ended or stopped working, an administrator can reconnect it from the Overview screen. Monitoring in UptimeWire keeps running meanwhile.', 'uptimewire' ); ?>
				<a href="<?php echo esc_url( Plugin::overview_url() ); ?>"><?php esc_html_e( 'Overview', 'uptimewire' ); ?></a>
			</li>
			<li>
				<strong><?php esc_html_e( 'Website verification:', 'uptimewire' ); ?></strong>
				<?php esc_html_e( 'While connecting, UptimeWire opens one address on your website to make sure it is this WordPress. A page cache, maintenance mode or a password on the whole site can get in the way.', 'uptimewire' ); ?>
			</li>
			<li>
				<strong><?php esc_html_e( 'Firewall and Cloudflare:', 'uptimewire' ); ?></strong>
				<?php esc_html_e( 'If a firewall, Cloudflare or a security plugin blocks UptimeWire’s checks, allow UptimeWire’s addresses or the “UptimeWireBot” user agent. You don’t need to turn the protection off.', 'uptimewire' ); ?>
				<?php View::external_link( Plugin::website_url( Overview_Page::FIREWALL_GUIDE ), __( 'How to allow UptimeWire', 'uptimewire' ) ); ?>
				<span aria-hidden="true">&middot;</span>
				<?php View::external_link( Overview_Page::IP_LIST, __( 'UptimeWire addresses', 'uptimewire' ) ); ?>
			</li>
			<?php if ( current_user_can( 'view_site_health_checks' ) ) : ?>
				<li>
					<strong><?php esc_html_e( 'Support:', 'uptimewire' ); ?></strong>
					<?php esc_html_e( 'When you contact support, copy the UptimeWire section of Site Health Info. It contains no credentials or email addresses.', 'uptimewire' ); ?>
					<a href="<?php echo esc_url( admin_url( 'site-health.php?tab=debug' ) ); ?>"><?php esc_html_e( 'Site Health Info', 'uptimewire' ); ?></a>
				</li>
			<?php endif; ?>
			<li>
				<strong><?php esc_html_e( 'Documentation:', 'uptimewire' ); ?></strong>
				<?php View::external_link( Plugin::website_url( 'help/' ), __( 'UptimeWire Help Center', 'uptimewire' ) ); ?>
			</li>
		</ul>
		<?php
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
