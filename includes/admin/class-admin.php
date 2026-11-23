<?php
/**
 * Admin menu, Help tabs, styles and the form handler.
 *
 * @package UptimeWire
 */

namespace UptimeWire\Admin;

use UptimeWire\Authorization;
use UptimeWire\Plugin;
use UptimeWire\Rest;
use UptimeWire\Site_Url;
use UptimeWire\State;

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

	/** Form action and nonce action for starting a connection. */
	const CONNECT_ACTION = 'uptimewire_connect';

	/** Form action and nonce action for cancelling the pending connection. */
	const CANCEL_ACTION = 'uptimewire_connect_cancel';

	/** Form action and nonce action for asking once whether the person approved (without JavaScript). */
	const CHECK_ACTION = 'uptimewire_connect_check';

	/** Form action and nonce action for closing the result of a failed attempt. */
	const DISMISS_ACTION = 'uptimewire_connect_dismiss';

	/** Query argument carrying the result message after a redirect. */
	const NOTICE_ARG = 'uptimewire_notice';

	/** Query argument carrying the seconds to wait, with some messages. */
	const WAIT_ARG = 'uptimewire_wait';

	/** States whose Overview screen has connect buttons or the waiting screen, and so loads the script. */
	const SCRIPT_STATES = array(
		State::NOT_CONNECTED,
		State::AUTHORIZATION_PENDING,
		State::WAITING_FOR_APPROVAL,
		State::AUTHORIZATION_EXPIRED,
		State::AUTHORIZATION_DENIED,
		State::CONNECTION_ERROR,
		State::RECONNECTION_REQUIRED,
	);

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
		add_action( 'admin_post_' . self::CONNECT_ACTION, array( __CLASS__, 'connect' ) );
		add_action( 'admin_post_' . self::CANCEL_ACTION, array( __CLASS__, 'cancel' ) );
		add_action( 'admin_post_' . self::CHECK_ACTION, array( __CLASS__, 'check' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( __CLASS__, 'dismiss' ) );
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

		// The script only connects; the screens work without it.
		if ( self::$screens[0] !== $hook_suffix || ! in_array( State::current(), self::SCRIPT_STATES, true ) ) {
			return;
		}

		wp_enqueue_script(
			'uptimewire-connect',
			plugins_url( 'assets/connect.js', UPTIMEWIRE_FILE ),
			array(),
			UPTIMEWIRE_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_localize_script(
			'uptimewire-connect',
			'uptimewireConnect',
			array(
				'startUrl'  => rest_url( Rest::NAMESPACE_V1 . '/connect-start' ),
				'statusUrl' => rest_url( Rest::NAMESPACE_V1 . '/connect-status' ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'text'      => array(
					'waiting'     => __( 'Waiting for your approval in UptimeWire…', 'uptimewire' ),
					'unreachable' => __( 'UptimeWire is temporarily unreachable. Still waiting — nothing is lost.', 'uptimewire' ),
					'rateLimited' => __( 'UptimeWire asked us to wait a little before checking again. Still waiting.', 'uptimewire' ),
					'offline'     => __( 'The connection to your WordPress site was interrupted. Trying again…', 'uptimewire' ),
					'blocked'     => __( 'Your site blocked the status check. Use “Check Status” after you approve in UptimeWire.', 'uptimewire' ),
					'expired'     => __( 'The code expired.', 'uptimewire' ),
				),
			)
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
	 * Starts connecting without JavaScript (and as the fallback of the script).
	 *
	 * @return void
	 */
	public static function connect() {
		self::check_request( self::CONNECT_ACTION );

		$result = Authorization::start( get_current_user_id(), ! empty( $_POST['uptimewire_understand'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in check_request().

		wp_safe_redirect( Plugin::overview_url( $result['ok'] ? '' : $result['error'], $result['retry_after'] ) );
		exit;
	}

	/**
	 * Cancels the pending connection.
	 *
	 * @return void
	 */
	public static function cancel() {
		self::check_request( self::CANCEL_ACTION );
		Authorization::cancel();

		wp_safe_redirect( Plugin::overview_url( 'cancelled' ) );
		exit;
	}

	/**
	 * Asks UptimeWire once whether the person approved, for browsers without JavaScript.
	 *
	 * @return void
	 */
	public static function check() {
		self::check_request( self::CHECK_ACTION );
		$view = Authorization::poll();

		wp_safe_redirect( Plugin::overview_url( State::CONNECTION_ESTABLISHED === $view['state'] ? 'connected' : '' ) );
		exit;
	}

	/**
	 * Closes the result of a failed attempt and shows the welcome screen again.
	 *
	 * @return void
	 */
	public static function dismiss() {
		self::check_request( self::DISMISS_ACTION );
		Authorization::clear_outcome();

		wp_safe_redirect( Plugin::overview_url() );
		exit;
	}

	/**
	 * Stops a form request without the capability or a valid nonce.
	 *
	 * @param string $action Nonce action.
	 * @return void
	 */
	private static function check_request( $action ) {
		if ( ! Plugin::current_user_can() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change this setting.', 'uptimewire' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( $action );
	}

	/**
	 * Lets WordPress strip the result message arguments from the address bar.
	 *
	 * @param string[] $args Removable query arguments.
	 * @return string[]
	 */
	public static function removable_query_args( $args ) {
		$args[] = self::NOTICE_ARG;
		$args[] = self::WAIT_ARG;

		return $args;
	}

	/**
	 * URL of the Overview screen.
	 *
	 * @return string
	 */
	public static function overview_url() {
		return Plugin::overview_url();
	}
}
