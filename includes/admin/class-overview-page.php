<?php
/**
 * Overview screen. Until a connection exists it is the welcome screen.
 *
 * @package UptimeWire
 */

namespace UptimeWire\Admin;

use UptimeWire\Environment;
use UptimeWire\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the welcome screen with environment warnings and the website address.
 */
final class Overview_Page {

	/**
	 * Prints the screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! Plugin::current_user_can() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'uptimewire' ), '', array( 'response' => 403 ) );
		}

		$site_url = Plugin::site_url();
		$warnings = Environment::warnings( wp_get_environment_type(), $site_url );
		?>
		<div class="wrap uptimewire">
			<h1 class="uptimewire-title"><?php View::shield(); ?> <?php esc_html_e( 'UptimeWire', 'uptimewire' ); ?></h1>
			<hr class="wp-header-end">
			<?php self::render_result_notice(); ?>

			<div class="uptimewire-welcome">
				<h2><?php esc_html_e( 'Keep Your WordPress Website Online', 'uptimewire' ); ?></h2>
				<p class="uptimewire-lead"><?php esc_html_e( 'Get notified when your website goes down, your SSL certificate approaches expiration, or important website checks fail.', 'uptimewire' ); ?></p>

				<?php self::render_warnings( $warnings ); ?>

				<div class="uptimewire-connect">
					<fieldset disabled aria-describedby="uptimewire-connect-unavailable">
						<legend class="screen-reader-text"><?php esc_html_e( 'Connect this website to UptimeWire', 'uptimewire' ); ?></legend>
						<?php if ( in_array( Environment::WARNING_STAGING, $warnings, true ) ) : ?>
							<p>
								<label>
									<input type="checkbox" name="uptimewire_understand" value="1">
									<?php esc_html_e( 'I understand', 'uptimewire' ); ?>
								</label>
							</p>
						<?php endif; ?>
						<p class="uptimewire-actions">
							<button type="button" class="button button-primary button-hero"><?php esc_html_e( 'Start Free Monitoring', 'uptimewire' ); ?></button>
							<button type="button" class="button button-hero"><?php esc_html_e( 'Connect Existing Account', 'uptimewire' ); ?></button>
						</p>
					</fieldset>
					<p id="uptimewire-connect-unavailable" class="description uptimewire-unavailable">
						<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
						<?php esc_html_e( 'Connection is not available in this build.', 'uptimewire' ); ?>
					</p>
				</div>

				<ul class="uptimewire-benefits">
					<li><span class="dashicons dashicons-yes" aria-hidden="true"></span> <?php esc_html_e( 'Free monitoring for one website', 'uptimewire' ); ?></li>
					<li><span class="dashicons dashicons-yes" aria-hidden="true"></span> <?php esc_html_e( 'No credit card required', 'uptimewire' ); ?></li>
					<li><span class="dashicons dashicons-yes" aria-hidden="true"></span> <?php esc_html_e( 'Checks run from outside WordPress, so they keep working even when your site is offline', 'uptimewire' ); ?></li>
				</ul>

				<p><?php esc_html_e( 'This plugin connects to UptimeWire, an external service. Nothing is sent to UptimeWire until you click a button above.', 'uptimewire' ); ?></p>
				<p class="uptimewire-legal">
					<?php View::external_link( Plugin::website_url( 'terms/' ), __( 'Terms of Service', 'uptimewire' ) ); ?>
					<span aria-hidden="true">&middot;</span>
					<?php View::external_link( Plugin::website_url( 'privacy/' ), __( 'Privacy Policy', 'uptimewire' ) ); ?>
				</p>

				<?php self::render_site_url( $site_url ); ?>
			</div>

			<?php View::footnote(); ?>
		</div>
		<?php
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
	 * Prints the message left by the address form after its redirect.
	 *
	 * @return void
	 */
	private static function render_result_notice() {
		// Display only: the message argument changes nothing, so it carries no nonce.
		$code = isset( $_GET[ Admin::NOTICE_ARG ] ) ? sanitize_key( wp_unslash( $_GET[ Admin::NOTICE_ARG ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		$notices = array(
			'site_url_saved'   => array( 'success', __( 'Website address saved.', 'uptimewire' ) ),
			'site_url_reset'   => array( 'success', __( 'The WordPress Site Address is used again.', 'uptimewire' ) ),
			'site_url_invalid' => array( 'error', __( 'That is not a website address. Enter a full address such as https://example.com/ without a query string.', 'uptimewire' ) ),
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
