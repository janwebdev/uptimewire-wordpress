<?php
/**
 * Bootstrap for unit tests of the pure classes. WordPress is not loaded.
 *
 * @package UptimeWire
 */

define( 'ABSPATH', __DIR__ . '/' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The plugin files exit without it. // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The plugin files exit without it.

require_once dirname( __DIR__ ) . '/vendor/autoload.php';
require_once dirname( __DIR__ ) . '/includes/class-environment.php';
require_once dirname( __DIR__ ) . '/includes/class-site-url.php';
require_once dirname( __DIR__ ) . '/includes/class-site-match.php';
