<?php
/**
 * Admin menu, Help tabs, styles and the form handler.
 *
 * @package UptimeWire
 */

namespace UptimeWire\Admin;

use UptimeWire\Plugin;
use UptimeWire\Site_Url;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the plugin into wp-admin. Nothing here runs outside wp-admin.
 */
final class Admin {

	/** Slug of the Overview screen (the top-level menu item). */
	const OVERVIEW_SLUG = 'uptimewire';

	/** Slug of the Settings screen. */
	const SETTINGS_SLUG = 'uptimewire-settings';

	/** Form action and nonce action for saving the website address. */
	const SAVE_SITE_URL_ACTION = 'uptimewire_save_site_url';

	/** Query argument carrying the result message after a redirect. */
	const NOTICE_ARG = 'uptimewire_notice';

	/**
	 * Hook suffixes of the plugin screens, filled when the menu is registered.
	 *
	 * @var string[]
	 */
	private static $screens = array();

	/**
	 * Registers the admin hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_styles' ) );
		add_action( 'admin_post_' . self::SAVE_SITE_URL_ACTION, array( __CLASS__, 'save_site_url' ) );
		add_filter( 'removable_query_args', array( __CLASS__, 'removable_query_args' ) );
	}

	/**
	 * Adds the UptimeWire menu with Overview and Settings.
	 *
	 * @return void
	 */
	public static function register_menu() {
		$capability = Plugin::capability();

		$overview = add_menu_page(
			__( 'UptimeWire', 'uptimewire' ),
			__( 'UptimeWire', 'uptimewire' ),
			$capability,
			self::OVERVIEW_SLUG,
			array( Overview_Page::class, 'render' ),
			View::MENU_ICON,
			81
		);

		// Same slug as the parent: renames the first submenu item to "Overview".
		add_submenu_page(
			self::OVERVIEW_SLUG,
			__( 'UptimeWire Overview', 'uptimewire' ),
			__( 'Overview', 'uptimewire' ),
			$capability,
			self::OVERVIEW_SLUG,
			array( Overview_Page::class, 'render' )
		);

		$settings = add_submenu_page(
			self::OVERVIEW_SLUG,
			__( 'UptimeWire Settings', 'uptimewire' ),
			__( 'Settings', 'uptimewire' ),
			$capability,
			self::SETTINGS_SLUG,
			array( Settings_Page::class, 'render' )
		);

		self::$screens = array_filter( array( $overview, $settings ) );

		foreach ( self::$screens as $hook ) {
			add_action( 'load-' . $hook, array( __CLASS__, 'add_help_tabs' ) );
		}
	}

	/**
	 * Loads the stylesheet on the plugin screens only.
	 *
	 * @param string $hook_suffix Current admin screen.
	 * @return void
	 */
	public static function enqueue_styles( $hook_suffix ) {
		if ( ! in_array( $hook_suffix, self::$screens, true ) ) {
			return;
		}

		wp_enqueue_style(
			'uptimewire-admin',
			plugins_url( 'assets/admin.css', UPTIMEWIRE_FILE ),
			array(),
			UPTIMEWIRE_VERSION
		);
	}

	/**
	 * Adds the Help tabs to the current plugin screen.
	 *
	 * @return void
	 */
	public static function add_help_tabs() {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$screen->add_help_tab(
			array(
				'id'      => 'uptimewire-overview',
				'title'   => __( 'Overview', 'uptimewire' ),
				'content' =>
					'<p>' . esc_html__( 'UptimeWire checks your website from its own servers on the internet: whether it is online, when the SSL certificate and the domain expire, and whether DNS works. Because the checks run outside WordPress, they keep working when your site is down, and they add no load to your server.', 'uptimewire' ) . '</p>' .
					'<p>' . esc_html__( 'Free monitoring covers one website and needs no credit card.', 'uptimewire' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'uptimewire-before-connecting',
				'title'   => __( 'Before you connect', 'uptimewire' ),
				'content' =>
					'<p>' . esc_html__( 'UptimeWire must reach your website from the internet. Local sites (localhost, .local, .test or private network addresses) cannot be monitored. Staging and development sites can, but usually only the live site is worth alerts.', 'uptimewire' ) . '</p>' .
					'<p>' . esc_html__( 'The "Website to monitor" address comes from Settings › General › Site Address. Change it on the Overview screen if visitors reach your site at a different address, for example behind a reverse proxy.', 'uptimewire' ) . '</p>',
			)
		);

		$screen->add_help_tab(
			array(
				'id'      => 'uptimewire-privacy',
				'title'   => __( 'Privacy', 'uptimewire' ),
				'content' =>
					'<p>' . esc_html__( 'This plugin connects to UptimeWire, an external service. Nothing is sent to UptimeWire until an administrator clicks "Start Free Monitoring" or "Connect Existing Account". No visitor data, user lists or content are ever sent.', 'uptimewire' ) . '</p>',
			)
		);

		$screen->set_help_sidebar(
			'<p><strong>' . esc_html__( 'For more information:', 'uptimewire' ) . '</strong></p>' .
			'<p><a href="' . esc_url( Plugin::website_url( 'help/' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'UptimeWire Help Center', 'uptimewire' ) . '</a></p>' .
			'<p><a href="' . esc_url( Plugin::website_url( 'terms/' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Terms of Service', 'uptimewire' ) . '</a></p>' .
			'<p><a href="' . esc_url( Plugin::website_url( 'privacy/' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Privacy Policy', 'uptimewire' ) . '</a></p>'
		);
	}

	/**
	 * Saves or resets the "Website to monitor" address, then returns to Overview.
	 *
	 * @return void
	 */
	public static function save_site_url() {
		if ( ! Plugin::current_user_can() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change this setting.', 'uptimewire' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::SAVE_SITE_URL_ACTION );

		$notice = 'site_url_reset';

		if ( isset( $_POST['uptimewire_reset'] ) ) {
			delete_option( Plugin::OPTION_SITE_URL );
		} else {
			// Site_Url::normalize() accepts only a well-formed http(s) address and returns '' for anything else.
			$raw = isset( $_POST['uptimewire_site_url'] ) ? wp_unslash( $_POST['uptimewire_site_url'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by Site_Url::normalize() below.
			$url = esc_url_raw( Site_Url::normalize( is_string( $raw ) ? $raw : '' ), array( 'http', 'https' ) );

			if ( '' === $url ) {
				$notice = 'site_url_invalid';
			} elseif ( untrailingslashit( home_url( '/' ) ) === untrailingslashit( $url ) ) {
				delete_option( Plugin::OPTION_SITE_URL );
			} else {
				update_option( Plugin::OPTION_SITE_URL, $url, false );
				$notice = 'site_url_saved';
			}
		}

		wp_safe_redirect( add_query_arg( self::NOTICE_ARG, $notice, self::overview_url() ) );
		exit;
	}

	/**
	 * Lets WordPress strip the result message argument from the address bar.
	 *
	 * @param string[] $args Removable query arguments.
	 * @return string[]
	 */
	public static function removable_query_args( $args ) {
		$args[] = self::NOTICE_ARG;

		return $args;
	}

	/**
	 * URL of the Overview screen.
	 *
	 * @return string
	 */
	public static function overview_url() {
		return admin_url( 'admin.php?page=' . self::OVERVIEW_SLUG );
	}
}
