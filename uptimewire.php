<?php
/**
 * Plugin Name:       UptimeWire – Website Uptime & SSL Monitoring
 * Plugin URI:        https://uptimewire.com/
 * Description:       Monitor your WordPress website from outside: uptime, SSL certificate, domain and DNS checks by UptimeWire.
 * Version:           0.2.0
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

define( 'UPTIMEWIRE_VERSION', '0.2.0' );
define( 'UPTIMEWIRE_FILE', __FILE__ );
define( 'UPTIMEWIRE_DIR', plugin_dir_path( __FILE__ ) );

require_once UPTIMEWIRE_DIR . 'includes/class-environment.php';
require_once UPTIMEWIRE_DIR . 'includes/class-site-url.php';
require_once UPTIMEWIRE_DIR . 'includes/class-site-match.php';
require_once UPTIMEWIRE_DIR . 'includes/class-plugin.php';
require_once UPTIMEWIRE_DIR . 'includes/class-api.php';
require_once UPTIMEWIRE_DIR . 'includes/class-lock.php';
require_once UPTIMEWIRE_DIR . 'includes/class-connection.php';
require_once UPTIMEWIRE_DIR . 'includes/class-authorization.php';
require_once UPTIMEWIRE_DIR . 'includes/class-state.php';
require_once UPTIMEWIRE_DIR . 'includes/class-proof.php';
require_once UPTIMEWIRE_DIR . 'includes/class-rest.php';

\UptimeWire\Proof::init();
\UptimeWire\Rest::init();

if ( is_admin() ) {
	require_once UPTIMEWIRE_DIR . 'includes/admin/class-view.php';
	require_once UPTIMEWIRE_DIR . 'includes/admin/class-overview-page.php';
	require_once UPTIMEWIRE_DIR . 'includes/admin/class-settings-page.php';
	require_once UPTIMEWIRE_DIR . 'includes/admin/class-admin.php';

	\UptimeWire\Admin\Admin::init();
}
