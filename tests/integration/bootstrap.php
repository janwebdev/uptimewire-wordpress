<?php
/**
 * Bootstrap for integration tests: loads the WordPress of a stand container
 * with the built plugin active. Outgoing requests to UptimeWire are answered by
 * Fake_Api; nothing leaves the container.
 *
 * Run:
 *   docker exec uw-wp2-php83 sh -c 'cd /src && vendor/bin/phpunit -c phpunit-integration.xml.dist'
 *
 * @package UptimeWire
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- Test bootstrap.

$_SERVER['HTTP_HOST']   = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
define( 'WP_USE_THEMES', false );
define( 'WP_ADMIN', true ); // The admin screens and their handlers load as in wp-admin.

$wp_path = getenv( 'UPTIMEWIRE_WP' ) ? getenv( 'UPTIMEWIRE_WP' ) : '/var/www/html';

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';
require_once __DIR__ . '/class-fake-api.php';

\UptimeWire\Tests\Fake_Api::install();

require_once $wp_path . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
require_once __DIR__ . '/class-test-case.php';

if ( ! class_exists( '\UptimeWire\Authorization' ) ) {
	fwrite( STDERR, "The UptimeWire plugin is not active in $wp_path.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	exit( 1 );
}
