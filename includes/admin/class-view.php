<?php
/**
 * Markup shared by the UptimeWire admin screens.
 *
 * @package UptimeWire
 */

namespace UptimeWire\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Small rendering helpers. Every method escapes its own output.
 */
final class View {

	/**
	 * Monochrome shield with a check mark, 20×20. Used for the menu icon and the logo.
	 */
	const SHIELD_PATHS = '<path fill="currentColor" fill-rule="evenodd" d="M10 1l7 3v5c0 4.5-3 8-7 10-4-2-7-5.5-7-10V4l7-3zm0 2.2L5 5.3V9c0 3.4 2.1 6.2 5 7.8 2.9-1.6 5-4.4 5-7.8V5.3l-5-2.1z"/><path fill="currentColor" d="M8.9 13.7l-3.2-3.1 1.1-1.1 2.1 2 4.3-4.3 1.1 1.1z"/>';

	/**
	 * Menu icon as a data URI. Same shapes as SHIELD_PATHS, filled black so the
	 * admin color scheme can repaint it.
	 */
	const MENU_ICON = 'data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyMCAyMCI+PHBhdGggZmlsbD0iYmxhY2siIGZpbGwtcnVsZT0iZXZlbm9kZCIgZD0iTTEwIDFsNyAzdjVjMCA0LjUtMyA4LTcgMTAtNC0yLTctNS41LTctMTBWNGw3LTN6bTAgMi4yTDUgNS4zVjljMCAzLjQgMi4xIDYuMiA1IDcuOCAyLjktMS42IDUtNC40IDUtNy44VjUuM2wtNS0yLjF6Ii8+PHBhdGggZmlsbD0iYmxhY2siIGQ9Ik04LjkgMTMuN2wtMy4yLTMuMSAxLjEtMS4xIDIuMSAyIDQuMy00LjMgMS4xIDEuMXoiLz48L3N2Zz4=';

	/**
	 * Prints the decorative shield logo.
	 *
	 * @return void
	 */
	public static function shield() {
		echo '<svg class="uptimewire-shield" viewBox="0 0 20 20" width="32" height="32" aria-hidden="true" focusable="false">';
		echo wp_kses(
			self::SHIELD_PATHS,
			array(
				'path' => array(
					'fill'      => true,
					'fill-rule' => true,
					'd'         => true,
				),
			)
		);
		echo '</svg>';
	}

	/**
	 * Prints a link to the public UptimeWire website that opens in a new tab.
	 *
	 * @param string $url  Absolute URL.
	 * @param string $text Visible link text, not escaped yet.
	 * @return void
	 */
	public static function external_link( $url, $text ) {
		printf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s<span class="screen-reader-text"> %3$s</span><span aria-hidden="true" class="uptimewire-external">&#8599;</span></a>',
			esc_url( $url ),
			esc_html( $text ),
			/* translators: Hidden text after a link to the UptimeWire website, read by screen readers. */
			esc_html__( '(opens UptimeWire in a new tab)', 'uptimewire' )
		);
	}

	/**
	 * Prints the line shown at the bottom of every UptimeWire screen.
	 *
	 * @return void
	 */
	public static function footnote() {
		echo '<p class="uptimewire-footnote">' . esc_html__( 'Monitoring runs on UptimeWire servers, outside WordPress.', 'uptimewire' ) . '</p>';
	}
}
