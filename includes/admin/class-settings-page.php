<?php
/**
 * Settings screen. In this version it shows the "Not connected" state only.
 *
 * @package UptimeWire
 */

namespace UptimeWire\Admin;

use UptimeWire\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the connection summary.
 */
final class Settings_Page {

	/**
	 * Prints the screen.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! Plugin::current_user_can() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'uptimewire' ), '', array( 'response' => 403 ) );
		}
		?>
		<div class="wrap uptimewire">
			<h1><?php esc_html_e( 'UptimeWire Settings', 'uptimewire' ); ?></h1>
			<hr class="wp-header-end">

			<h2><?php esc_html_e( 'Connection', 'uptimewire' ); ?></h2>
			<table class="form-table uptimewire-summary" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'uptimewire' ); ?></th>
						<td>
							<span class="uptimewire-state uptimewire-state-off">
								<span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
								<?php esc_html_e( 'Not connected', 'uptimewire' ); ?>
							</span>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Website', 'uptimewire' ); ?></th>
						<td><span class="uptimewire-site-address"><?php echo esc_html( Plugin::site_url() ); ?></span></td>
					</tr>
				</tbody>
			</table>
			<p>
				<?php
				printf(
					/* translators: %s: link to the UptimeWire Overview screen. */
					esc_html__( 'Connect this website from the %s screen.', 'uptimewire' ),
					'<a href="' . esc_url( Admin::overview_url() ) . '">' . esc_html__( 'Overview', 'uptimewire' ) . '</a>'
				);
				?>
				<?php esc_html_e( 'Connection is not available in this build.', 'uptimewire' ); ?>
			</p>

			<?php View::footnote(); ?>
		</div>
		<?php
	}
}
