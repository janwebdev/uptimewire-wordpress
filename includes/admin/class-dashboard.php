<?php
/**
 * The monitoring dashboard of the Overview screen.
 *
 * @package UptimeWire
 */

namespace UptimeWire\Admin;

use UptimeWire\Connection;
use UptimeWire\Plugin;
use UptimeWire\Status;
use UptimeWire\Status_Data;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the kept status of the connected website: the main status, four
 * check cards, uptime, recent events and alert readiness.
 *
 * Rendering never asks UptimeWire anything; it shows the kept copy (see
 * Status). Everything from UptimeWire is escaped here, and words, not colors,
 * carry every state.
 */
final class Dashboard {

	/** Id of the element the script replaces after a refresh. */
	const CONTAINER_ID = 'uptimewire-dashboard';

	/** Guide on letting UptimeWire's checks through a firewall. */
	const FIREWALL_GUIDE = 'resources/guides/allow-uptimewire-checks-through-firewall/';

	/**
	 * Prints the whole dashboard: the live region and the replaceable part.
	 *
	 * @return void
	 */
	public static function render() {
		$status = Status::get();
		?>
		<p id="uptimewire-live" class="screen-reader-text" role="status" aria-live="polite"></p>
		<div id="<?php echo esc_attr( self::CONTAINER_ID ); ?>" class="uptimewire-dashboard" data-due="<?php echo Status::is_due( $status ) ? '1' : '0'; ?>" data-ttl="<?php echo esc_attr( (string) Status::ttl( null === $status ? null : $status['data'] ) ); ?>">
			<?php self::render_inner(); ?>
		</div>
		<?php
	}

	/**
	 * Prints the replaceable part from the kept status.
	 *
	 * @return void
	 */
	public static function render_inner() {
		$status = Status::get();
		$data   = Status::data();
		$stale  = null !== $data && Status::is_stale( $status );

		self::render_header( $status, $data );

		if ( null === $data ) {
			self::render_no_data( $status );

			return;
		}

		$headline = Status_Data::headline( $data['site'] );

		if ( $stale ) {
			self::render_stale( $status, $headline );
		} else {
			self::render_primary( $data, $headline, $status );
		}
		?>
		<div class="uptimewire-sections<?php echo $stale ? ' is-stale' : ''; ?>">
			<?php
			if ( 'unverified' !== $headline ) {
				self::render_cards( $data, $headline );
				self::render_uptime( $data );
				self::render_events( $data );
			}

			self::render_alerts( $data, $headline, $stale );
			?>
		</div>
		<?php
	}

	/**
	 * Words announced to screen readers after a refresh.
	 *
	 * @return string
	 */
	public static function announcement() {
		$status = Status::get();
		$data   = Status::data();

		if ( null === $data ) {
			return self::no_data_title( $status );
		}

		if ( Status::is_stale( $status ) ) {
			/* translators: %s: the last status UptimeWire reported, such as "Your Website Is Online". */
			return sprintf( __( 'Last known status: %s', 'uptimewire' ), self::title( Status_Data::headline( $data['site'] ) ) );
		}

		return self::title( Status_Data::headline( $data['site'] ) );
	}

	/**
	 * Prints the header: website, account, last update, Refresh, link to UptimeWire.
	 *
	 * @param array<string, mixed>|null $status Kept status.
	 * @param array<string, mixed>|null $data   Kept data of this website.
	 * @return void
	 */
	private static function render_header( $status, $data ) {
		$record = Connection::get();
		$site   = null !== $data ? (string) $data['site']['url'] : ( null !== $record && isset( $record['site_url'] ) ? (string) $record['site_url'] : Plugin::site_url() );
		$label  = null !== $data && null !== $data['account_label'] ? $data['account_label'] : Connection::account_label();
		$open   = null !== $data && null !== $data['links']['site'] ? $data['links']['site'] : Overview_Page::DASHBOARD;
		?>
		<div class="uptimewire-header">
			<dl class="uptimewire-header-facts">
				<div>
					<dt><?php esc_html_e( 'Website', 'uptimewire' ); ?></dt>
					<dd class="uptimewire-site-address"><?php echo esc_html( $site ); ?></dd>
				</div>
				<?php if ( '' !== $label ) : ?>
					<div>
						<dt><?php esc_html_e( 'UptimeWire account', 'uptimewire' ); ?></dt>
						<dd class="uptimewire-account"><?php echo esc_html( $label ); ?></dd>
					</div>
				<?php endif; ?>
				<div>
					<dt><?php esc_html_e( 'Last updated', 'uptimewire' ); ?></dt>
					<dd class="uptimewire-synced">
						<?php
						if ( null !== $status && $status['fetched_at'] > 0 ) {
							self::ago( $status['fetched_at'] );
						} else {
							esc_html_e( 'Not yet', 'uptimewire' );
						}
						?>
					</dd>
				</div>
			</dl>
			<div class="uptimewire-header-actions">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="uptimewire-refresh-form">
					<input type="hidden" name="action" value="<?php echo esc_attr( Admin::REFRESH_ACTION ); ?>">
					<?php wp_nonce_field( Admin::REFRESH_ACTION, '_wpnonce', false ); ?>
					<button type="submit" class="button uptimewire-refresh" aria-describedby="uptimewire-refresh-help">
						<span class="dashicons dashicons-update" aria-hidden="true"></span>
						<span class="uptimewire-refresh-label"><?php esc_html_e( 'Refresh', 'uptimewire' ); ?></span>
					</button>
					<span id="uptimewire-refresh-help" class="screen-reader-text"><?php esc_html_e( 'Gets the latest results from UptimeWire. It doesn’t run a new check.', 'uptimewire' ); ?></span>
				</form>
				<?php self::link_button( $open, __( 'Open in UptimeWire', 'uptimewire' ), false ); ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Prints the main status when there is no data of this website to show.
	 *
	 * @param array<string, mixed>|null $status Kept status.
	 * @return void
	 */
	private static function render_no_data( $status ) {
		$error = null === $status ? '' : $status['error'];
		?>
		<section class="uptimewire-primary uptimewire-tone-<?php echo '' === $error ? 'info' : 'unknown'; ?>" aria-labelledby="uptimewire-primary-title">
			<span class="uptimewire-primary-icon dashicons <?php echo '' === $error ? 'dashicons-update' : 'dashicons-cloud'; ?>" aria-hidden="true"></span>
			<div class="uptimewire-primary-body">
				<h2 id="uptimewire-primary-title"><?php echo esc_html( self::no_data_title( $status ) ); ?></h2>
				<?php if ( '' === $error ) : ?>
					<p><?php esc_html_e( 'Getting your website’s status from UptimeWire. This usually takes a few seconds. If nothing changes, click Refresh.', 'uptimewire' ); ?></p>
				<?php else : ?>
					<p><?php echo esc_html( self::failure_text( $error ) ); ?></p>
					<p><?php esc_html_e( 'This is about the connection between WordPress and UptimeWire, not about your website. UptimeWire keeps monitoring your website and sends alerts as usual.', 'uptimewire' ); ?></p>
					<?php self::render_retry( $status ); ?>
				<?php endif; ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Title of the main status without data.
	 *
	 * @param array<string, mixed>|null $status Kept status.
	 * @return string
	 */
	private static function no_data_title( $status ) {
		return null === $status || '' === $status['error'] ? __( 'Loading Your Website Status…', 'uptimewire' ) : __( 'Status Unavailable', 'uptimewire' );
	}

	/**
	 * Prints the last known status when fresh data could not be read.
	 *
	 * @param array<string, mixed> $status   Kept status.
	 * @param string               $headline Status_Data::headline().
	 * @return void
	 */
	private static function render_stale( array $status, $headline ) {
		?>
		<section class="uptimewire-primary uptimewire-tone-unknown" aria-labelledby="uptimewire-primary-title">
			<span class="uptimewire-primary-icon dashicons dashicons-backup" aria-hidden="true"></span>
			<div class="uptimewire-primary-body">
				<h2 id="uptimewire-primary-title"><?php esc_html_e( 'Last Known Status', 'uptimewire' ); ?></h2>
				<p class="uptimewire-primary-lead">
					<?php
					/* translators: %s: the last status UptimeWire reported, such as "Your Website Is Online". */
					echo esc_html( sprintf( __( 'Last reported: %s', 'uptimewire' ), self::title( $headline ) ) );
					?>
				</p>
				<p>
					<?php
					printf(
						/* translators: %s: date and time the data was received from UptimeWire. */
						esc_html__( 'Received from UptimeWire %s. It may have changed since.', 'uptimewire' ),
						self::ago_html( $status['fetched_at'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in ago_html().
					);
					?>
				</p>
				<p><?php echo esc_html( '' !== $status['error'] ? self::failure_text( $status['error'] ) : __( 'The status hasn’t been updated for a while. Click Refresh to get the latest results.', 'uptimewire' ) ); ?></p>
				<?php self::render_retry( $status ); ?>
			</div>
		</section>
		<?php
	}

	/**
	 * Prints when the next attempt is possible, after a failure.
	 *
	 * @param array<string, mixed>|null $status Kept status.
	 * @return void
	 */
	private static function render_retry( $status ) {
		if ( null === $status || $status['retry_at'] <= time() ) {
			return;
		}
		?>
		<p class="description">
			<?php
			/* translators: %s: how long to wait, such as "1 min". */
			echo esc_html( sprintf( __( 'The plugin tries again in %s.', 'uptimewire' ), human_time_diff( time(), $status['retry_at'] ) ) );
			?>
		</p>
		<?php
	}

	/**
	 * Why the last read failed, in words.
	 *
	 * @param string $error Status result constant.
	 * @return string
	 */
	public static function failure_text( $error ) {
		switch ( $error ) {
			case Status::UNREACHABLE:
				return __( 'This WordPress server couldn’t reach UptimeWire.', 'uptimewire' );
			case Status::UNAVAILABLE:
				return __( 'UptimeWire is temporarily unavailable.', 'uptimewire' );
			case Status::RATE_LIMITED:
				return __( 'UptimeWire asked the plugin to wait before the next update.', 'uptimewire' );
			default:
				return __( 'UptimeWire sent an answer this plugin couldn’t read.', 'uptimewire' );
		}
	}

	/**
	 * Prints the main status of the website.
	 *
	 * @param array<string, mixed>      $data     Kept data.
	 * @param string                    $headline Status_Data::headline().
	 * @param array<string, mixed>|null $status   Kept status.
	 * @return void
	 */
	private static function render_primary( array $data, $headline, $status ) {
		$site  = $data['site'];
		$look  = self::look( $headline );
		$links = $data['links'];
		?>
		<section class="uptimewire-primary uptimewire-tone-<?php echo esc_attr( $look[0] ); ?>" aria-labelledby="uptimewire-primary-title">
			<span class="uptimewire-primary-icon dashicons <?php echo esc_attr( $look[1] ); ?>" aria-hidden="true"></span>
			<div class="uptimewire-primary-body">
				<h2 id="uptimewire-primary-title"><?php echo esc_html( self::title( $headline ) ); ?></h2>
				<?php
				switch ( $headline ) {
					case 'unverified':
						echo '<p>' . esc_html__( 'Monitoring hasn’t started yet. Open the email UptimeWire sent you and confirm your address. The checks start right after that.', 'uptimewire' ) . '</p>';
						echo '<p>' . esc_html__( 'Until then, nothing is checked and no alerts are sent.', 'uptimewire' ) . '</p>';
						self::link_button( null !== $links['site'] ? $links['site'] : Overview_Page::DASHBOARD, __( 'Open Your UptimeWire Account', 'uptimewire' ), true );
						break;

					case 'waiting':
						echo '<p>' . esc_html__( 'The first check hasn’t finished yet, so there are no results to show.', 'uptimewire' ) . '</p>';
						if ( null !== $site['interval_minutes'] ) {
							/* translators: %d: minutes between checks. */
							echo '<p>' . esc_html( sprintf( _n( 'Results appear here after it does. Your website is checked every %d minute.', 'Results appear here after it does. Your website is checked every %d minutes.', $site['interval_minutes'], 'uptimewire' ), $site['interval_minutes'] ) ) . '</p>';
						}
						break;

					case 'up':
						self::render_last_check( $site );
						self::render_attention( $data );
						break;

					case 'checking':
						echo '<p>' . esc_html__( 'A check just failed. UptimeWire is checking again before it reports downtime. This is not confirmed downtime.', 'uptimewire' ) . '</p>';
						self::render_problem( $site );
						self::render_last_check( $site );
						break;

					case 'down':
						if ( null !== $site['down_since'] ) {
							echo '<p class="uptimewire-primary-lead">';
							printf(
								/* translators: 1: date and time the downtime started; 2: how long it has lasted, such as "25 mins". */
								esc_html__( 'Down since %1$s (%2$s).', 'uptimewire' ),
								self::time_html( $site['down_since'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in time_html().
								esc_html( self::duration( max( 0, time() - $site['down_since'] ) ) )
							);
							echo '</p>';
						}
						self::render_problem( $site );
						self::render_last_check( $site );
						break;

					case 'planned':
						echo '<p>' . esc_html__( 'Planned maintenance is marked for this website in UptimeWire. Time in maintenance isn’t counted as downtime.', 'uptimewire' ) . '</p>';
						break;

					case 'paused':
						echo '<p>' . esc_html__( 'UptimeWire isn’t checking your website right now, so there are no new results and no alerts. You can resume monitoring in UptimeWire.', 'uptimewire' ) . '</p>';
						self::link_button( null !== $links['site'] ? $links['site'] : Overview_Page::DASHBOARD, __( 'Resume in UptimeWire', 'uptimewire' ), true );
						break;

					default:
						echo '<p>' . esc_html__( 'UptimeWire reported a status this version of the plugin doesn’t recognize. Open UptimeWire to see it, or update the plugin.', 'uptimewire' ) . '</p>';
				}

				if ( null !== $status && '' !== $status['error'] ) {
					echo '<p class="description">' . esc_html( self::failure_text( $status['error'] ) ) . ' ' . esc_html__( 'The status above is the latest one received.', 'uptimewire' ) . '</p>';
				}
				?>
			</div>
		</section>
		<?php
	}

	/**
	 * Prints the problem UptimeWire reports and a blocked-check explanation.
	 *
	 * @param array<string, mixed> $site Kept site.
	 * @return void
	 */
	private static function render_problem( array $site ) {
		if ( null !== $site['problem'] ) {
			echo '<p><strong>' . esc_html__( 'Reason:', 'uptimewire' ) . '</strong> <span class="uptimewire-untrusted">' . esc_html( $site['problem'] ) . '</span></p>';
		}

		if ( null !== $site['blocked'] ) {
			echo '<p>';
			echo esc_html(
				sprintf(
					/* translators: %d: HTTP status code such as 403. */
					__( 'Your website answered UptimeWire’s checks with HTTP %d. A firewall, a security plugin or bot protection may be blocking the checks, while visitors can still open the site.', 'uptimewire' ),
					(int) $site['blocked']
				)
			);
			echo '</p><p>';
			View::external_link( Plugin::website_url( self::FIREWALL_GUIDE ), __( 'How to allow UptimeWire', 'uptimewire' ) );
			echo '</p>';
		}
	}

	/**
	 * Prints which checks need attention while the website itself is online,
	 * so an expired certificate is not hidden behind "online".
	 *
	 * @param array<string, mixed> $data Kept data.
	 * @return void
	 */
	private static function render_attention( array $data ) {
		$names = array(
			'ssl'    => __( 'SSL certificate', 'uptimewire' ),
			'domain' => __( 'domain', 'uptimewire' ),
			'dns'    => __( 'DNS', 'uptimewire' ),
		);
		$found = array();

		foreach ( $names as $check => $name ) {
			if ( 'problem' === $data[ $check ]['state'] ) {
				$found[] = $name;
			}
		}

		$found = array_merge( $found, self::attention_be5( $data ) );

		if ( array() === $found ) {
			return;
		}

		echo '<p class="uptimewire-attention"><span class="dashicons dashicons-warning" aria-hidden="true"></span> ';
		echo esc_html(
			sprintf(
				/* translators: %s: list of checks, such as "SSL certificate, DNS". */
				__( 'Needs attention: %s. See the checks below.', 'uptimewire' ),
				implode( wp_get_list_item_separator(), $found )
			)
		);
		echo '</p>';
	}

	/**
	 * The checks of BE-5 that need attention, in words: a broken email domain, a late or failed job.
	 * Advice alone and "no email on this domain" need none.
	 *
	 * @param array<string, mixed> $data Kept data.
	 * @return string[]
	 */
	public static function attention_be5( array $data ) {
		$found = array();

		if ( isset( $data['mail']['state'] ) && 'problem' === $data['mail']['state'] ) {
			$found[] = __( 'email domain', 'uptimewire' );
		}

		if ( isset( $data['scheduled_job']['state'] ) && in_array( $data['scheduled_job']['state'], array( 'late', 'failed' ), true ) ) {
			$found[] = __( 'scheduled job', 'uptimewire' );
		}

		return $found;
	}

	/**
	 * Prints when and from where the website was last checked.
	 *
	 * @param array<string, mixed> $site Kept site.
	 * @return void
	 */
	private static function render_last_check( array $site ) {
		if ( null === $site['last_checked_at'] ) {
			return;
		}

		echo '<p>';
		if ( null !== $site['last_location'] ) {
			printf(
				/* translators: 1: when, such as "3 mins ago"; 2: country the check ran from, such as "Germany". */
				esc_html__( 'Last checked %1$s from %2$s.', 'uptimewire' ),
				self::ago_html( $site['last_checked_at'] ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in ago_html().
				esc_html( $site['last_location'] )
			);
		} else {
			printf(
				/* translators: %s: when, such as "3 mins ago". */
				esc_html__( 'Last checked %s.', 'uptimewire' ),
				self::ago_html( $site['last_checked_at'] ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in ago_html().
			);
		}
		echo '</p>';
	}

	/**
	 * Title of a main status.
	 *
	 * @param string $headline Status_Data::headline().
	 * @return string
	 */
	public static function title( $headline ) {
		$titles = array(
			'unverified' => __( 'One More Step: Verify Your Email', 'uptimewire' ),
			'waiting'    => __( 'Monitoring Is Being Initialized', 'uptimewire' ),
			'up'         => __( 'Your Website Is Online', 'uptimewire' ),
			'checking'   => __( 'We’re Double-Checking Your Website', 'uptimewire' ),
			'down'       => __( 'Your Website Is Down', 'uptimewire' ),
			'planned'    => __( 'Planned Maintenance', 'uptimewire' ),
			'paused'     => __( 'Monitoring Is Paused', 'uptimewire' ),
		);

		return isset( $titles[ $headline ] ) ? $titles[ $headline ] : __( 'Status Unknown', 'uptimewire' );
	}

	/**
	 * Tone and icon of a main status.
	 *
	 * @param string $headline Status_Data::headline().
	 * @return array{0: string, 1: string}
	 */
	public static function look( $headline ) {
		$looks = array(
			'unverified' => array( 'info', 'dashicons-email-alt' ),
			'waiting'    => array( 'info', 'dashicons-clock' ),
			'up'         => array( 'ok', 'dashicons-yes-alt' ),
			'checking'   => array( 'warn', 'dashicons-search' ),
			'down'       => array( 'bad', 'dashicons-dismiss' ),
			'planned'    => array( 'info', 'dashicons-admin-tools' ),
			'paused'     => array( 'off', 'dashicons-controls-pause' ),
		);

		return isset( $looks[ $headline ] ) ? $looks[ $headline ] : array( 'unknown', 'dashicons-editor-help' );
	}

	/**
	 * Prints the four check cards.
	 *
	 * @param array<string, mixed> $data     Kept data.
	 * @param string               $headline Status_Data::headline().
	 * @return void
	 */
	private static function render_cards( array $data, $headline ) {
		$site   = $data['site'];
		$detail = $data['links']['site'];
		$window = $data['uptime']['30d'];
		$words  = array(
			'waiting'  => __( 'Waiting for the first check', 'uptimewire' ),
			'up'       => __( 'Online', 'uptimewire' ),
			'checking' => __( 'Checking again', 'uptimewire' ),
			'down'     => __( 'Down', 'uptimewire' ),
			'planned'  => __( 'Planned maintenance', 'uptimewire' ),
			'paused'   => __( 'Paused', 'uptimewire' ),
		);

		$uptime_lines = array();

		if ( null === $window['percent'] ) {
			$uptime_lines[] = __( 'Uptime, 30 days: not measured yet', 'uptimewire' );
		} elseif ( $window['measured_seconds'] < Status_Data::WINDOWS['30d'] - 60 && null !== $window['from'] ) {
			// A shorter history is named, so the number is not read as 30 days.
			/* translators: 1: date measuring started; 2: uptime percentage such as "99.97%". */
			$uptime_lines[] = sprintf( __( 'Uptime since %1$s: %2$s', 'uptimewire' ), wp_date( get_option( 'date_format' ), $window['from'] ), self::percent( $window['percent'] ) );
		} else {
			/* translators: %s: uptime percentage such as "99.97%". */
			$uptime_lines[] = sprintf( __( 'Uptime, 30 days: %s', 'uptimewire' ), self::percent( $window['percent'] ) );
		}

		if ( null !== $site['average_response_ms_24h'] ) {
			/* translators: %s: average response time in milliseconds, such as "400". */
			$uptime_lines[] = sprintf( __( 'Average response, 24 hours: %s ms', 'uptimewire' ), number_format_i18n( $site['average_response_ms_24h'] ) );
		}

		if ( null !== $site['interval_minutes'] ) {
			/* translators: %d: minutes between checks. */
			$uptime_lines[] = sprintf( _n( 'Checked every %d minute', 'Checked every %d minutes', $site['interval_minutes'], 'uptimewire' ), $site['interval_minutes'] );
		}
		?>
		<section class="uptimewire-block" aria-labelledby="uptimewire-checks-title">
			<h2 id="uptimewire-checks-title" class="uptimewire-block-title"><?php esc_html_e( 'Website Checks', 'uptimewire' ); ?></h2>
			<ul class="uptimewire-cards">
				<?php
				$look = self::look( $headline );
				self::card(
					__( 'Website Uptime', 'uptimewire' ),
					'dashicons-chart-line',
					'unknown' === $look[0] ? 'unknown' : $look[0],
					isset( $words[ $headline ] ) ? $words[ $headline ] : __( 'Unknown', 'uptimewire' ),
					$uptime_lines,
					$site['last_checked_at'],
					$detail
				);
				self::expiry_card( __( 'SSL Certificate', 'uptimewire' ), 'dashicons-lock', $data['ssl'], false, $detail );
				self::expiry_card( __( 'Domain Expiration', 'uptimewire' ), 'dashicons-admin-site-alt3', $data['domain'], true, $detail );
				self::dns_card( $data['dns'], $detail );

				// A server before BE-5 sends neither check: then there is no card at all.
				if ( isset( $data['mail'] ) ) {
					self::mail_card( $data['mail'], $detail );
				}
				if ( isset( $data['scheduled_job'] ) ) {
					self::job_card( $data['scheduled_job'], isset( $data['links']['scheduled_job'] ) ? $data['links']['scheduled_job'] : $detail );
				}
				?>
			</ul>
		</section>
		<?php
	}

	/**
	 * Prints the SSL or the domain card.
	 *
	 * @param string               $name   Card title.
	 * @param string               $icon   Dashicon class.
	 * @param array<string, mixed> $check  Kept check.
	 * @param bool                 $domain Whether this is the domain check.
	 * @param string|null          $detail Link to details.
	 * @return void
	 */
	private static function expiry_card( $name, $icon, array $check, $domain, $detail ) {
		$lines = array();
		$date  = null !== $check['expires_at'] ? wp_date( get_option( 'date_format' ), $check['expires_at'] ) : '';

		switch ( $check['state'] ) {
			case 'ok':
				$tone  = 'ok';
				$value = $domain ? __( 'Registered', 'uptimewire' ) : __( 'Valid', 'uptimewire' );
				break;
			case 'problem':
				$tone  = 'expired' === $check['warning'] ? 'bad' : 'warn';
				$value = array(
					'expired'      => __( 'Expired', 'uptimewire' ),
					'expires_soon' => __( 'Expires soon', 'uptimewire' ),
					'check_failed' => __( 'Check failed', 'uptimewire' ),
				);
				$value = isset( $value[ $check['warning'] ] ) ? $value[ $check['warning'] ] : __( 'Needs attention', 'uptimewire' );
				break;
			case 'not_applicable':
				$tone    = 'off';
				$value   = __( 'No certificate', 'uptimewire' );
				$lines[] = __( 'Your website uses http://, so there is no certificate to check.', 'uptimewire' );
				break;
			case 'not_on_plan':
				$tone    = 'off';
				$value   = __( 'Not in your plan', 'uptimewire' );
				$lines[] = __( 'Your UptimeWire plan doesn’t include this check.', 'uptimewire' );
				break;
			default:
				$tone    = 'unknown';
				$value   = __( 'Unknown', 'uptimewire' );
				$lines[] = __( 'No result to show yet.', 'uptimewire' );
		}

		if ( in_array( $check['state'], array( 'ok', 'problem' ), true ) ) {
			if ( null !== $check['days_left'] && '' !== $date ) {
				$lines[] = $check['days_left'] < 0
					/* translators: 1: number of days; 2: date. */
					? sprintf( _n( 'Expired %1$d day ago, on %2$s', 'Expired %1$d days ago, on %2$s', -$check['days_left'], 'uptimewire' ), -$check['days_left'], $date )
					/* translators: 1: number of days; 2: date. */
					: sprintf( _n( 'Expires in %1$d day, on %2$s', 'Expires in %1$d days, on %2$s', $check['days_left'], 'uptimewire' ), $check['days_left'], $date );
			} elseif ( '' !== $date ) {
				/* translators: %s: date. */
				$lines[] = sprintf( __( 'Expires on %s', 'uptimewire' ), $date );
			} elseif ( $domain ) {
				$lines[] = __( 'Expiry date unknown: the domain registry doesn’t publish it.', 'uptimewire' );
			}
		}

		if ( null !== $check['error'] && in_array( $check['state'], array( 'problem', 'unknown' ), true ) ) {
			$lines[] = array( 'untrusted' => $check['error'] );
		}

		self::card( $name, $icon, $tone, $value, $lines, $check['checked_at'], $detail );
	}

	/**
	 * Prints the DNS card.
	 *
	 * @param array<string, mixed> $dns    Kept check.
	 * @param string|null          $detail Link to details.
	 * @return void
	 */
	private static function dns_card( array $dns, $detail ) {
		$lines = array();

		switch ( $dns['state'] ) {
			case 'ok':
				$tone    = 'ok';
				$value   = __( 'No unreviewed changes', 'uptimewire' );
				$lines[] = null !== $dns['last_change_at']
					/* translators: %s: date. */
					? sprintf( __( 'Last change on %s', 'uptimewire' ), wp_date( get_option( 'date_format' ), $dns['last_change_at'] ) )
					: __( 'No changes detected', 'uptimewire' );
				break;
			case 'problem':
				$tone  = 'warn';
				$value = __( 'Changes detected', 'uptimewire' );
				if ( $dns['open_changes'] > 0 ) {
					/* translators: %d: number of DNS changes nobody reviewed yet. */
					$lines[] = sprintf( _n( '%d change to review in UptimeWire', '%d changes to review in UptimeWire', $dns['open_changes'], 'uptimewire' ), $dns['open_changes'] );
				} else {
					$lines[] = __( 'Review the change in UptimeWire.', 'uptimewire' );
				}
				if ( null !== $dns['last_change_at'] ) {
					/* translators: %s: date. */
					$lines[] = sprintf( __( 'Last change on %s', 'uptimewire' ), wp_date( get_option( 'date_format' ), $dns['last_change_at'] ) );
				}
				break;
			case 'not_on_plan':
				$tone    = 'off';
				$value   = __( 'Not in your plan', 'uptimewire' );
				$lines[] = __( 'Your UptimeWire plan doesn’t include this check.', 'uptimewire' );
				break;
			default:
				$tone    = 'unknown';
				$value   = __( 'Unknown', 'uptimewire' );
				$lines[] = __( 'No result to show yet.', 'uptimewire' );
		}

		self::card( __( 'DNS Monitoring', 'uptimewire' ), 'dashicons-networking', $tone, $value, $lines, $dns['checked_at'], $detail );
	}

	/**
	 * Prints the email domain card.
	 *
	 * @param array<string, mixed> $mail   Kept check.
	 * @param string|null          $detail Link to details.
	 * @return void
	 */
	private static function mail_card( array $mail, $detail ) {
		$lines = array();

		switch ( $mail['state'] ) {
			case 'ok':
				$tone  = 'ok';
				$value = __( 'Set up', 'uptimewire' );
				break;
			case 'problem':
				$tone  = 'warn';
				$value = __( 'Needs attention', 'uptimewire' );
				break;
			case 'none':
				// Calm: many website domains never receive email.
				$tone    = 'off';
				$value   = __( 'No email', 'uptimewire' );
				$lines[] = __( 'This domain does not receive email.', 'uptimewire' );
				break;
			default:
				$tone  = 'unknown';
				$value = __( 'Unknown', 'uptimewire' );
		}

		if ( 'none' !== $mail['state'] ) {
			foreach ( $mail['items'] as $key => $item ) {
				$lines[] = self::mail_line( $key, $item );
			}
		}

		if ( array() === $lines ) {
			$lines[] = __( 'No result to show yet.', 'uptimewire' );
		}

		self::card( __( 'Email Domain', 'uptimewire' ), 'dashicons-email', $tone, $value, $lines, $mail['checked_at'], $detail );
	}

	/**
	 * One line of the email domain check in words, such as "Sender list (SPF): valid".
	 *
	 * @param string               $key  mx, spf, dmarc or dkim.
	 * @param array<string, mixed> $item Kept line: state and reason.
	 * @return string
	 */
	public static function mail_line( $key, array $item ) {
		$names   = array(
			'mx'    => __( 'Mail servers (MX)', 'uptimewire' ),
			'spf'   => __( 'Sender list (SPF)', 'uptimewire' ),
			'dmarc' => __( 'Spoofing protection (DMARC)', 'uptimewire' ),
			'dkim'  => __( 'Email signature (DKIM)', 'uptimewire' ),
		);
		$reasons = array(
			'mx.ok'               => __( 'found', 'uptimewire' ),
			'mx.none'             => __( 'none, the domain does not receive email', 'uptimewire' ),
			'mx.missing'          => __( 'missing — email to this domain can’t be delivered', 'uptimewire' ),
			'spf.ok'              => __( 'valid', 'uptimewire' ),
			'spf.none'            => __( 'none', 'uptimewire' ),
			'spf.missing'         => __( 'missing — emails from your domain may be marked as spam', 'uptimewire' ),
			'spf.multiple'        => __( 'more than one record — receivers may reject your emails', 'uptimewire' ),
			'spf.invalid'         => __( 'has an error — receivers may reject your emails', 'uptimewire' ),
			'spf.invalid_include' => __( 'has an error — receivers may reject your emails', 'uptimewire' ),
			'spf.too_many'        => __( 'too many lookups — receivers may ignore it', 'uptimewire' ),
			'dmarc.reject'        => __( 'on', 'uptimewire' ),
			'dmarc.quarantine'    => __( 'on', 'uptimewire' ),
			'dmarc.ok'            => __( 'on', 'uptimewire' ),
			'dmarc.none'          => __( 'only watching — tip: a stricter policy protects better', 'uptimewire' ),
			'dmarc.missing'       => __( 'not set up — tip: add it to protect your domain from fake emails', 'uptimewire' ),
			'dmarc.multiple'      => __( 'more than one record — tip: keep only one', 'uptimewire' ),
			'dkim.found'          => __( 'found', 'uptimewire' ),
			// Looked for under the names of the big mail services only: not found is never "broken".
			'dkim.not_found'      => __( 'could not be checked', 'uptimewire' ),
			'unanswered'          => __( 'could not be checked', 'uptimewire' ),
		);
		$states  = array(
			'ok'      => __( 'OK', 'uptimewire' ),
			'problem' => __( 'needs attention', 'uptimewire' ),
			'advice'  => __( 'see the tip in UptimeWire', 'uptimewire' ),
			'none'    => __( 'none', 'uptimewire' ),
		);
		$words   = null !== $item['reason'] && isset( $reasons[ $item['reason'] ] )
			? $reasons[ $item['reason'] ]
			: ( isset( $states[ $item['state'] ] ) ? $states[ $item['state'] ] : __( 'could not be checked', 'uptimewire' ) );

		/* translators: 1: part of the email setup, such as "Sender list (SPF)"; 2: its state in words, such as "valid". */
		return sprintf( __( '%1$s: %2$s', 'uptimewire' ), isset( $names[ $key ] ) ? $names[ $key ] : $key, $words );
	}

	/**
	 * Prints the scheduled job card.
	 *
	 * @param array<string, mixed> $job    Kept check.
	 * @param string|null          $detail Link to the place in UptimeWire where the job is connected.
	 * @return void
	 */
	private static function job_card( array $job, $detail ) {
		$lines = array();
		$looks = array(
			'on_time'       => array( 'ok', __( 'On time', 'uptimewire' ) ),
			'late'          => array( 'warn', __( 'Late', 'uptimewire' ) ),
			'failed'        => array( 'bad', __( 'Failed', 'uptimewire' ) ),
			'not_connected' => array( 'off', __( 'Not connected', 'uptimewire' ) ),
		);
		$look  = isset( $looks[ $job['state'] ] ) ? $looks[ $job['state'] ] : array( 'unknown', __( 'Unknown', 'uptimewire' ) );

		if ( 'late' === $job['state'] ) {
			$lines[] = __( 'The job didn’t call UptimeWire in time.', 'uptimewire' );
		} elseif ( 'failed' === $job['state'] ) {
			$lines[] = __( 'The job reported that its last run failed.', 'uptimewire' );
		}

		if ( 'not_connected' === $job['state'] ) {
			$lines[] = __( 'Connect a scheduled job of this website, such as a backup, in UptimeWire. You’ll be alerted when it stops running.', 'uptimewire' );
		} elseif ( isset( $looks[ $job['state'] ] ) ) {
			$lines[] = null !== $job['last_call_at']
				/* translators: %s: time span such as "2 hours". */
				? sprintf( __( 'Last call %s ago', 'uptimewire' ), human_time_diff( $job['last_call_at'], time() ) )
				: __( 'No call yet', 'uptimewire' );

			if ( null !== $job['interval_seconds'] && null !== $job['grace_seconds'] ) {
				/* translators: 1: time between runs, such as "1 day"; 2: allowed delay, such as "1 hour". */
				$lines[] = sprintf( __( 'Expected every %1$s, up to %2$s late', 'uptimewire' ), self::duration( $job['interval_seconds'] ), self::duration( $job['grace_seconds'] ) );
			}
		} else {
			$lines[] = __( 'No result to show yet.', 'uptimewire' );
		}

		self::card( __( 'Scheduled Job', 'uptimewire' ), 'dashicons-clock', $look[0], $look[1], $lines, null, $detail, 'not_connected' === $job['state'] ? __( 'Connect in UptimeWire', 'uptimewire' ) : null );
	}

	/**
	 * Prints one card.
	 *
	 * @param string                      $name    Title.
	 * @param string                      $icon    Dashicon class.
	 * @param string                      $tone    ok, warn, bad, info, off or unknown.
	 * @param string                      $value   Main value in words.
	 * @param array<int, string|string[]> $lines   Plain lines, or array( 'untrusted' => text ) for text from UptimeWire.
	 * @param int|null                    $checked Last check time.
	 * @param string|null                 $detail  Link to details in UptimeWire.
	 * @param string|null                 $action  Text of that link when it is an action, instead of "Details".
	 * @return void
	 */
	private static function card( $name, $icon, $tone, $value, array $lines, $checked, $detail, $action = null ) {
		$marks = array(
			'ok'      => 'dashicons-yes-alt',
			'warn'    => 'dashicons-warning',
			'bad'     => 'dashicons-dismiss',
			'info'    => 'dashicons-info',
			'off'     => 'dashicons-minus',
			'unknown' => 'dashicons-editor-help',
		);
		?>
		<li class="uptimewire-card-item uptimewire-tone-<?php echo esc_attr( $tone ); ?>">
			<h3 class="uptimewire-card-title"><span class="dashicons <?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span> <?php echo esc_html( $name ); ?></h3>
			<p class="uptimewire-card-value"><span class="dashicons <?php echo esc_attr( isset( $marks[ $tone ] ) ? $marks[ $tone ] : $marks['unknown'] ); ?>" aria-hidden="true"></span> <?php echo esc_html( $value ); ?></p>
			<?php foreach ( $lines as $line ) : ?>
				<?php if ( is_array( $line ) ) : ?>
					<p class="uptimewire-card-line uptimewire-untrusted"><?php echo esc_html( $line['untrusted'] ); ?></p>
				<?php else : ?>
					<p class="uptimewire-card-line"><?php echo esc_html( $line ); ?></p>
				<?php endif; ?>
			<?php endforeach; ?>
			<?php if ( null !== $checked ) : ?>
				<p class="uptimewire-card-line uptimewire-muted">
					<?php
					/* translators: %s: when, such as "3 mins ago". */
					printf( esc_html__( 'Checked %s', 'uptimewire' ), self::ago_html( $checked ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in ago_html().
					?>
				</p>
			<?php endif; ?>
			<?php if ( null !== $detail ) : ?>
				<p class="uptimewire-card-link">
					<?php
					/* translators: %s: card title, such as "SSL Certificate". Read by screen readers only, after "Details". */
					self::external( $detail, null !== $action ? $action : __( 'Details', 'uptimewire' ), sprintf( __( 'about %s', 'uptimewire' ), $name ) );
					?>
				</p>
			<?php endif; ?>
		</li>
		<?php
	}

	/**
	 * Prints the uptime of the three windows.
	 *
	 * @param array<string, mixed> $data Kept data.
	 * @return void
	 */
	private static function render_uptime( array $data ) {
		$names = array(
			'24h' => __( 'Last 24 hours', 'uptimewire' ),
			'7d'  => __( 'Last 7 days', 'uptimewire' ),
			'30d' => __( 'Last 30 days', 'uptimewire' ),
		);
		$notes = array();
		?>
		<section class="uptimewire-block" aria-labelledby="uptimewire-uptime-title">
			<h2 id="uptimewire-uptime-title" class="uptimewire-block-title"><?php esc_html_e( 'Uptime', 'uptimewire' ); ?></h2>
			<table class="uptimewire-table uptimewire-uptime">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Period', 'uptimewire' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Uptime', 'uptimewire' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Downtime', 'uptimewire' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Measured', 'uptimewire' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( Status_Data::WINDOWS as $window => $length ) :
						$item  = $data['uptime'][ $window ];
						$short = null !== $item['percent'] && $item['measured_seconds'] < $length - 60;

						if ( $item['history_limited'] ) {
							$notes['limited'] = __( 'Your plan keeps less history than some of these periods, so they cover only the history kept.', 'uptimewire' );
						}
						if ( $short ) {
							$notes['short'] = __( 'Uptime counts only the time UptimeWire actually measured, for example since the website was added. Planned maintenance isn’t counted.', 'uptimewire' );
						}
						?>
						<tr>
							<th scope="row" data-label="<?php esc_attr_e( 'Period', 'uptimewire' ); ?>"><?php echo esc_html( $names[ $window ] ); ?></th>
							<td data-label="<?php esc_attr_e( 'Uptime', 'uptimewire' ); ?>" class="uptimewire-percent">
								<?php echo null !== $item['percent'] ? esc_html( self::percent( $item['percent'] ) ) : esc_html__( 'Not measured yet', 'uptimewire' ); ?>
							</td>
							<td data-label="<?php esc_attr_e( 'Downtime', 'uptimewire' ); ?>">
								<?php
								if ( null === $item['percent'] ) {
									echo '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Not measured yet', 'uptimewire' ) . '</span>';
								} elseif ( 0 === $item['downtime_seconds'] ) {
									esc_html_e( 'None', 'uptimewire' );
								} else {
									echo esc_html( self::duration( $item['downtime_seconds'] ) );
								}
								?>
							</td>
							<td data-label="<?php esc_attr_e( 'Measured', 'uptimewire' ); ?>">
								<?php
								if ( null === $item['percent'] ) {
									echo '<span aria-hidden="true">—</span><span class="screen-reader-text">' . esc_html__( 'Nothing measured', 'uptimewire' ) . '</span>';
								} elseif ( $short && null !== $item['from'] ) {
									/* translators: 1: measured time, such as "2 days"; 2: date measuring started. */
									echo esc_html( sprintf( __( '%1$s (since %2$s)', 'uptimewire' ), self::duration( $item['measured_seconds'] ), wp_date( get_option( 'date_format' ), $item['from'] ) ) );
								} elseif ( $short ) {
									echo esc_html( self::duration( $item['measured_seconds'] ) );
								} else {
									esc_html_e( 'Whole period', 'uptimewire' );
								}
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php foreach ( $notes as $note ) : ?>
				<p class="description"><?php echo esc_html( $note ); ?></p>
			<?php endforeach; ?>
		</section>
		<?php
	}

	/**
	 * Prints the recent events.
	 *
	 * @param array<string, mixed> $data Kept data.
	 * @return void
	 */
	private static function render_events( array $data ) {
		?>
		<section class="uptimewire-block" aria-labelledby="uptimewire-events-title">
			<h2 id="uptimewire-events-title" class="uptimewire-block-title"><?php esc_html_e( 'Recent Events', 'uptimewire' ); ?></h2>
			<?php if ( array() === $data['events'] ) : ?>
				<p class="uptimewire-empty"><?php esc_html_e( 'No events in the history UptimeWire keeps for your plan.', 'uptimewire' ); ?></p>
			<?php else : ?>
				<table class="uptimewire-table uptimewire-events">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Event', 'uptimewire' ); ?></th>
							<th scope="col"><?php esc_html_e( 'When', 'uptimewire' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Details', 'uptimewire' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $data['events'] as $event ) : ?>
							<?php $label = self::event_label( $event ); ?>
							<tr class="uptimewire-tone-<?php echo esc_attr( $label[1] ); ?>">
								<th scope="row" data-label="<?php esc_attr_e( 'Event', 'uptimewire' ); ?>"><span class="dashicons <?php echo esc_attr( $label[2] ); ?>" aria-hidden="true"></span> <?php echo esc_html( $label[0] ); ?></th>
								<td data-label="<?php esc_attr_e( 'When', 'uptimewire' ); ?>"><?php echo self::time_html( $event['at'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in time_html(). ?></td>
								<td data-label="<?php esc_attr_e( 'Details', 'uptimewire' ); ?>"><?php echo esc_html( self::event_details( $event ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
			<?php if ( null !== $data['links']['site'] ) : ?>
				<p><?php self::external( $data['links']['site'], __( 'Full incident history in UptimeWire', 'uptimewire' ) ); ?></p>
			<?php endif; ?>
		</section>
		<?php
	}

	/**
	 * Words, tone and icon of an event. Only outages are called downtime.
	 *
	 * @param array<string, mixed> $event Kept event.
	 * @return array{0: string, 1: string, 2: string}
	 */
	private static function event_label( array $event ) {
		$labels = array(
			'downtime:ongoing'        => array( __( 'Website is down', 'uptimewire' ), 'bad', 'dashicons-dismiss' ),
			'downtime:resolved'       => array( __( 'Website was down', 'uptimewire' ), 'off', 'dashicons-backup' ),
			'ssl:problem'             => array( __( 'SSL certificate warning', 'uptimewire' ), 'warn', 'dashicons-lock' ),
			'ssl:renewed'             => array( __( 'SSL certificate renewed', 'uptimewire' ), 'ok', 'dashicons-lock' ),
			'domain:problem'          => array( __( 'Domain expiration warning', 'uptimewire' ), 'warn', 'dashicons-admin-site-alt3' ),
			'domain:renewed'          => array( __( 'Domain renewed', 'uptimewire' ), 'ok', 'dashicons-admin-site-alt3' ),
			'dns:changed'             => array( __( 'DNS records changed', 'uptimewire' ), 'warn', 'dashicons-networking' ),
			'mail:problem'            => array( __( 'Email domain problem', 'uptimewire' ), 'warn', 'dashicons-email' ),
			'scheduled_job:missed'    => array( __( 'Scheduled job missed', 'uptimewire' ), 'warn', 'dashicons-clock' ),
			'scheduled_job:failed'    => array( __( 'Scheduled job failed', 'uptimewire' ), 'bad', 'dashicons-clock' ),
			'scheduled_job:recovered' => array( __( 'Scheduled job back on time', 'uptimewire' ), 'ok', 'dashicons-clock' ),
		);
		$key    = $event['type'] . ':' . $event['kind'];

		return isset( $labels[ $key ] ) ? $labels[ $key ] : array( __( 'Other event (see UptimeWire)', 'uptimewire' ), 'unknown', 'dashicons-editor-help' );
	}

	/**
	 * Details of an event in words.
	 *
	 * @param array<string, mixed> $event Kept event.
	 * @return string
	 */
	private static function event_details( array $event ) {
		if ( 'downtime' === $event['type'] ) {
			if ( null === $event['duration_seconds'] ) {
				return '';
			}

			return 'ongoing' === $event['kind']
				/* translators: %s: duration such as "25 mins". */
				? sprintf( __( 'Ongoing, %s so far', 'uptimewire' ), self::duration( $event['duration_seconds'] ) )
				/* translators: %s: duration such as "25 mins". */
				: sprintf( __( 'Lasted %s', 'uptimewire' ), self::duration( $event['duration_seconds'] ) );
		}

		if ( null !== $event['expires_at'] ) {
			/* translators: %s: date. */
			return sprintf( __( 'Expiry date: %s', 'uptimewire' ), wp_date( get_option( 'date_format' ), $event['expires_at'] ) );
		}

		return '';
	}

	/**
	 * Prints whether alerts can reach the owner.
	 *
	 * @param array<string, mixed> $data     Kept data.
	 * @param string               $headline Status_Data::headline().
	 * @param bool                 $stale    Whether the data is only the last known status.
	 * @return void
	 */
	private static function render_alerts( array $data, $headline, $stale ) {
		?>
		<section class="uptimewire-block uptimewire-alerts uptimewire-tone-<?php echo esc_attr( self::alert_availability( $data['alerts'], $headline, $stale )[0] ); ?>" aria-labelledby="uptimewire-alerts-title">
			<h2 id="uptimewire-alerts-title" class="uptimewire-block-title"><?php esc_html_e( 'Alert Readiness', 'uptimewire' ); ?></h2>
			<?php self::render_alert_readiness( $data, $headline, $stale ); ?>
		</section>
		<?php
	}

	/**
	 * Prints whether monitoring alerts can be sent now, then the channels that
	 * are configured. The two are different facts: a configured channel carries
	 * nothing while monitoring is paused or has not started.
	 *
	 * Used by the Overview and the Settings screens.
	 *
	 * @param array<string, mixed> $data     Kept data.
	 * @param string               $headline Status_Data::headline().
	 * @param bool                 $stale    Whether the data is only the last known status.
	 * @return void
	 */
	public static function render_alert_readiness( array $data, $headline, $stale = false ) {
		$alerts       = $data['alerts'];
		$availability = self::alert_availability( $alerts, $headline, $stale );
		$settings     = null !== $data['links']['alerts'] ? $data['links']['alerts'] : Overview_Page::DASHBOARD;
		?>
		<p class="uptimewire-card-value"><span class="dashicons <?php echo esc_attr( $availability[1] ); ?>" aria-hidden="true"></span> <?php echo esc_html( $availability[2] ); ?></p>
		<p><?php echo esc_html( $availability[3] ); ?></p>
		<h3 class="uptimewire-subtitle"><?php esc_html_e( 'Configured Notification Channels', 'uptimewire' ); ?></h3>
		<?php if ( array() !== $alerts['channels'] || $alerts['other_channels'] ) : ?>
			<ul class="uptimewire-channels">
				<?php foreach ( $alerts['channels'] as $channel ) : ?>
					<li><?php echo esc_html( self::channel_name( $channel ) ); ?></li>
				<?php endforeach; ?>
				<?php if ( $alerts['other_channels'] ) : ?>
					<li><?php esc_html_e( 'Other', 'uptimewire' ); ?></li>
				<?php endif; ?>
			</ul>
			<p class="description"><?php esc_html_e( 'Only the kinds of channels are shown here, never their addresses.', 'uptimewire' ); ?></p>
		<?php else : ?>
			<p><?php esc_html_e( 'None', 'uptimewire' ); ?></p>
		<?php endif; ?>
		<?php if ( $alerts['email_pending'] ) : ?>
			<p class="description"><?php esc_html_e( 'A new alert email address is waiting for confirmation. Until it is confirmed, email alerts go to the previous address.', 'uptimewire' ); ?></p>
		<?php endif; ?>
		<p>
			<?php
			if ( 'ok' === $availability[0] ) {
				self::external( $settings, __( 'Alert settings in UptimeWire', 'uptimewire' ) );
			} else {
				self::link_button( $settings, __( 'Configure Alerts in UptimeWire', 'uptimewire' ), 'warn' === $availability[0] );
			}
			?>
		</p>
		<?php
	}

	/**
	 * Whether monitoring alerts can be sent now, from UptimeWire's own answer.
	 *
	 * "ready" means UptimeWire checks the website and at least one channel would
	 * carry an alert now. It says nothing about delivery, so neither do the words.
	 *
	 * @param array<string, mixed> $alerts   Kept alerts.
	 * @param string               $headline Status_Data::headline().
	 * @param bool                 $stale    Whether the data is only the last known status.
	 * @return array{0: string, 1: string, 2: string, 3: string} Tone, icon, title, text.
	 */
	public static function alert_availability( array $alerts, $headline, $stale = false ) {
		$configured = array() !== $alerts['channels'] || $alerts['other_channels'];

		// Old data says nothing about now: the last known answer is named as such.
		if ( $stale ) {
			$last = self::alert_availability( $alerts, $headline );

			return array(
				'unknown',
				'dashicons-backup',
				__( 'Current Alert Readiness Is Unknown', 'uptimewire' ),
				/* translators: %s: the last known readiness, such as "Alerts Are Ready". */
				sprintf( __( 'Current monitoring status is unavailable. Last known: %s.', 'uptimewire' ), $last[2] ),
			);
		}

		if ( 'unverified' === $headline ) {
			return array( 'info', 'dashicons-email-alt', __( 'Email Verification Required', 'uptimewire' ), __( 'Monitoring and notifications will start after the account email is verified.', 'uptimewire' ) );
		}

		if ( 'paused' === $headline ) {
			return array(
				'off',
				'dashicons-controls-pause',
				__( 'Monitoring Is Paused', 'uptimewire' ),
				$configured
					? __( 'Your notification channels remain configured, but monitoring alerts are currently inactive.', 'uptimewire' )
					: __( 'Monitoring alerts are currently inactive, and no notification channel is set up for this website.', 'uptimewire' ),
			);
		}

		if ( $alerts['ready'] && 'waiting' === $headline ) {
			return array( 'info', 'dashicons-clock', __( 'Monitoring Is Starting', 'uptimewire' ), __( 'Notification channels are configured. Monitoring has not completed its first check yet.', 'uptimewire' ) );
		}

		if ( $alerts['ready'] ) {
			return array( 'ok', 'dashicons-yes-alt', __( 'Alerts Are Ready', 'uptimewire' ), __( 'Notifications are configured and monitoring is active.', 'uptimewire' ) );
		}

		return array(
			'warn',
			'dashicons-warning',
			__( 'Alerts Can’t Reach You Right Now', 'uptimewire' ),
			$configured
				? __( 'None of the alert channels set up for this website can deliver alerts right now.', 'uptimewire' )
				: __( 'No alert channel is set up for this website.', 'uptimewire' ),
		);
	}

	/**
	 * Name of an alert channel kind.
	 *
	 * @param string $channel Status_Data::CHANNELS value.
	 * @return string
	 */
	public static function channel_name( $channel ) {
		$names = array(
			'email'    => __( 'Email', 'uptimewire' ),
			'telegram' => __( 'Telegram', 'uptimewire' ),
			'slack'    => __( 'Slack', 'uptimewire' ),
			'discord'  => __( 'Discord', 'uptimewire' ),
			'sms'      => __( 'SMS', 'uptimewire' ),
			'webhook'  => __( 'Webhook', 'uptimewire' ),
			'teams'    => __( 'Microsoft Teams', 'uptimewire' ),
		);

		return isset( $names[ $channel ] ) ? $names[ $channel ] : __( 'Other', 'uptimewire' );
	}

	/**
	 * Uptime percentage in words: two decimals, as UptimeWire cut it.
	 *
	 * @param float $percent Percentage.
	 * @return string
	 */
	public static function percent( $percent ) {
		/* translators: %s: number such as 99.97. */
		return sprintf( __( '%s%%', 'uptimewire' ), number_format_i18n( $percent, 100.0 === $percent ? 0 : 2 ) );
	}

	/**
	 * A length of time in words, such as "25 mins".
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	public static function duration( $seconds ) {
		if ( $seconds < 60 ) {
			/* translators: %d: seconds. */
			return sprintf( _n( '%d second', '%d seconds', $seconds, 'uptimewire' ), $seconds );
		}

		$minutes = (int) round( $seconds / 60 );

		if ( $minutes < 60 ) {
			/* translators: %d: minutes. */
			return sprintf( _n( '%d minute', '%d minutes', $minutes, 'uptimewire' ), $minutes );
		}

		if ( $seconds < 86400 ) {
			$hours = intdiv( $minutes, 60 );
			/* translators: %d: hours. */
			$text = sprintf( _n( '%d hour', '%d hours', $hours, 'uptimewire' ), $hours );

			return 0 === $minutes % 60
				? $text
				/* translators: 1: hours, such as "2 hours"; 2: minutes, such as "5 minutes". */
				: sprintf( __( '%1$s %2$s', 'uptimewire' ), $text, sprintf( _n( '%d minute', '%d minutes', $minutes % 60, 'uptimewire' ), $minutes % 60 ) );
		}

		// Whole days, never rounded up: "9 days" is not "1 week".
		$days = intdiv( $seconds, 86400 );

		/* translators: %d: days. */
		return sprintf( _n( '%d day', '%d days', $days, 'uptimewire' ), $days );
	}

	/**
	 * Prints how long ago a time was, with the exact time for machines and on hover.
	 *
	 * @param int $time Unix time.
	 * @return void
	 */
	private static function ago( $time ) {
		echo self::ago_html( $time ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in ago_html().
	}

	/**
	 * "3 mins ago" as a time element.
	 *
	 * @param int $time Unix time.
	 * @return string Escaped HTML.
	 */
	public static function ago_html( $time ) {
		$words = $time > time() - 30
			? __( 'just now', 'uptimewire' )
			/* translators: %s: time span such as "3 mins". */
			: sprintf( __( '%s ago', 'uptimewire' ), human_time_diff( $time, time() ) );

		return sprintf(
			'<time datetime="%1$s" title="%2$s">%3$s</time>',
			esc_attr( gmdate( 'c', $time ) ),
			esc_attr( self::date_time( $time ) ),
			esc_html( $words )
		);
	}

	/**
	 * A date and time as a time element.
	 *
	 * @param int $time Unix time.
	 * @return string Escaped HTML.
	 */
	public static function time_html( $time ) {
		return sprintf( '<time datetime="%1$s">%2$s</time>', esc_attr( gmdate( 'c', $time ) ), esc_html( self::date_time( $time ) ) );
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

	/**
	 * Prints a link into UptimeWire that opens in a new tab.
	 *
	 * @param string $url    Address, already validated as an UptimeWire link.
	 * @param string $text   Visible text.
	 * @param string $hidden Words added for screen readers after the text, or ''.
	 * @return void
	 */
	public static function external( $url, $text, $hidden = '' ) {
		printf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s</span><span aria-hidden="true" class="uptimewire-external">&#8599;</span></a>',
			esc_url( $url ),
			esc_html( $text ),
			esc_html( ( '' !== $hidden ? $hidden . ' ' : '' ) . __( '(opens UptimeWire in a new tab)', 'uptimewire' ) )
		);
	}

	/**
	 * Prints a button-styled link into UptimeWire that opens in a new tab.
	 *
	 * @param string $url     Address, already validated as an UptimeWire link.
	 * @param string $text    Visible text.
	 * @param bool   $primary Whether it is the main action.
	 * @return void
	 */
	public static function link_button( $url, $text, $primary ) {
		printf(
			'<a class="button%1$s" href="%2$s" target="_blank" rel="noopener noreferrer">%3$s<span class="screen-reader-text"> %4$s</span><span aria-hidden="true" class="uptimewire-external">&#8599;</span></a>',
			$primary ? ' button-primary' : '',
			esc_url( $url ),
			esc_html( $text ),
			esc_html__( '(opens UptimeWire in a new tab)', 'uptimewire' )
		);
	}
}
