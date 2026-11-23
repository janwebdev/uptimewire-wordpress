<?php
/**
 * Settings screen: the state of the connection.
 *
 * @package UptimeWire
 */

namespace UptimeWire\Admin;

use UptimeWire\Connection;
use UptimeWire\Plugin;
use UptimeWire\State;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the connection summary. Connecting itself happens on Overview.
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

		Connection::check_health();
		$state  = State::current();
		$record = State::CONNECTION_ESTABLISHED === $state ? Connection::get() : null;
		?>
		<div class="wrap uptimewire">
			<h1><?php esc_html_e( 'UptimeWire Settings', 'uptimewire' ); ?></h1>
			<hr class="wp-header-end">

			<h2><?php esc_html_e( 'Connection', 'uptimewire' ); ?></h2>
			<table class="form-table uptimewire-summary" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'uptimewire' ); ?></th>
						<td><?php Overview_Page::state_badge( $state ); ?></td>
					</tr>
					<?php if ( null === $record ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Website', 'uptimewire' ); ?></th>
							<td><span class="uptimewire-site-address"><?php echo esc_html( Plugin::site_url() ); ?></span></td>
						</tr>
					<?php endif; ?>
				</tbody>
			</table>
			<?php if ( null !== $record ) : ?>
				<?php Overview_Page::render_connection_table( $record ); ?>
			<?php else : ?>
				<p>
					<?php
					printf(
						/* translators: %s: link to the UptimeWire Overview screen. */
						esc_html__( 'Connect this website from the %s screen.', 'uptimewire' ),
						'<a href="' . esc_url( Admin::overview_url() ) . '">' . esc_html__( 'Overview', 'uptimewire' ) . '</a>'
					);
					?>
				</p>
			<?php endif; ?>

			<?php View::footnote(); ?>
		</div>
		<?php
	}
}
