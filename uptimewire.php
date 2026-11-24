<?php
/**
 * Plugin Name:       UptimeWire – Website Uptime & SSL Monitoring
 * Plugin URI:        https://uptimewire.com/
 * Description:       Website monitoring by UptimeWire, inside WordPress: uptime, SSL, domain, DNS, email domain and scheduled jobs.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Author:            UptimeWire
 * Author URI:        https://uptimewire.com/
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       uptimewire
 * Domain Path:       /languages
 *
 * @package UptimeWire
 */

defined( 'ABSPATH' ) || exit;

define( 'UPTIMEWIRE_VERSION', '1.0.0' );
define( 'UPTIMEWIRE_FILE', __FILE__ );
define( 'UPTIMEWIRE_DIR', plugin_dir_path( __FILE__ ) );

require_once UPTIMEWIRE_DIR . 'includes/class-environment.php';
require_once UPTIMEWIRE_DIR . 'includes/class-site-url.php';
require_once UPTIMEWIRE_DIR . 'includes/class-site-match.php';
require_once UPTIMEWIRE_DIR . 'includes/class-status-data.php';
require_once UPTIMEWIRE_DIR . 'includes/class-plugin.php';
require_once UPTIMEWIRE_DIR . 'includes/class-api.php';
require_once UPTIMEWIRE_DIR . 'includes/class-lock.php';
require_once UPTIMEWIRE_DIR . 'includes/class-connection.php';
require_once UPTIMEWIRE_DIR . 'includes/class-status.php';
require_once UPTIMEWIRE_DIR . 'includes/class-authorization.php';
require_once UPTIMEWIRE_DIR . 'includes/class-state.php';
require_once UPTIMEWIRE_DIR . 'includes/class-proof.php';
require_once UPTIMEWIRE_DIR . 'includes/class-rest.php';

// No activation or deactivation hooks on purpose: the plugin schedules nothing, so deactivating only
// stops it. The connection and the kept status stay, and reactivating needs no new connection.
// Disconnecting is an explicit action in Settings; uninstall.php removes the data.
\UptimeWire\Proof::init();
\UptimeWire\Rest::init();

if ( is_admin() ) {
	require_once UPTIMEWIRE_DIR . 'includes/admin/class-view.php';
	require_once UPTIMEWIRE_DIR . 'includes/admin/class-dashboard.php';
	require_once UPTIMEWIRE_DIR . 'includes/admin/class-overview-page.php';
	require_once UPTIMEWIRE_DIR . 'includes/admin/class-settings-page.php';
	require_once UPTIMEWIRE_DIR . 'includes/admin/class-admin.php';
	require_once UPTIMEWIRE_DIR . 'includes/admin/class-widget.php';
	require_once UPTIMEWIRE_DIR . 'includes/admin/class-site-health.php';

	\UptimeWire\Admin\Admin::init();
	\UptimeWire\Admin\Widget::init();
	\UptimeWire\Admin\Site_Health::init();
}
