<?php
/**
 * The plugin's own REST routes for the waiting screen.
 *
 * @package UptimeWire
 */

namespace UptimeWire;

use WP_REST_Request;
use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Routes under /wp-json/uptimewire/v1. Only for users with the plugin's
 * capability; WordPress requires the wp_rest nonce for requests signed in with
 * a cookie, which protects them from cross-site requests. Answers are never
 * cached and never contain the device code, the proof or the credential.
 */
final class Rest {

	/** REST namespace. */
	const NAMESPACE_V1 = 'uptimewire/v1';

	/**
	 * Registers the hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_V1,
			'/connect-start',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'start' ),
				'permission_callback' => array( Plugin::class, 'can_manage' ),
				'args'                => array(
					'understand' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/connect-status',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => array( Plugin::class, 'can_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_V1,
			'/status-refresh',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'refresh' ),
				'permission_callback' => array( Plugin::class, 'can_view' ),
				'args'                => array(
					'force' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'view'  => array(
						'type'    => 'string',
						'enum'    => array( 'overview', 'widget' ),
						'default' => 'overview',
					),
				),
			)
		);
	}

	/**
	 * Reads the monitoring status when due (or now, for the Refresh button) and
	 * answers the markup to show: the Overview dashboard, or the Dashboard widget
	 * for view "widget". When the connection ended or belongs to another website,
	 * the Overview is reloaded, which explains it; the widget explains it itself.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function refresh( WP_REST_Request $request ) {
		$result = Status::refresh( (bool) $request->get_param( 'force' ) );
		$widget = 'widget' === $request->get_param( 'view' );

		if ( ! $widget && State::CONNECTION_ESTABLISHED !== State::current() ) {
			return self::answer(
				array(
					'result'   => $result,
					'redirect' => Plugin::overview_url(),
				)
			);
		}

		// REST requests don't load the admin screens (uptimewire.php loads them in wp-admin only).
		foreach ( array( 'class-view.php', 'class-dashboard.php', 'class-overview-page.php', 'class-settings-page.php', 'class-widget.php', 'class-admin.php' ) as $file ) {
			require_once UPTIMEWIRE_DIR . 'includes/admin/' . $file;
		}

		ob_start();
		if ( $widget ) {
			Admin\Widget::render_inner();
		} else {
			Admin\Dashboard::render_inner();
		}
		$html   = (string) ob_get_clean();
		$status = Status::get();

		return self::answer(
			array(
				'result'   => $result,
				'html'     => $html,
				'announce' => $widget ? Admin\Widget::announcement() : Admin\Dashboard::announcement(),
				'ttl'      => Status::ttl( null === $status ? null : $status['data'] ),
				'due'      => Status::is_due( $status ),
			)
		);
	}

	/**
	 * Starts connecting. Answers where the browser goes next.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function start( WP_REST_Request $request ) {
		$result = Authorization::start( get_current_user_id(), (bool) $request->get_param( 'understand' ) );
		$open   = '';

		if ( $result['ok'] ) {
			$pending = Authorization::pending();
			$open    = null === $pending ? '' : ( '' !== $pending['verification_uri_complete'] ? $pending['verification_uri_complete'] : $pending['verification_uri'] );
		}

		return self::answer(
			array(
				'ok'       => $result['ok'],
				'open'     => $open,
				'redirect' => Plugin::overview_url( $result['ok'] ? '' : $result['error'], $result['retry_after'] ),
			)
		);
	}

	/**
	 * Asks UptimeWire once, if it is time, and answers the state.
	 *
	 * @return WP_REST_Response
	 */
	public static function status() {
		$view = Authorization::poll();

		if ( $view['done'] ) {
			$view['redirect'] = Plugin::overview_url( State::CONNECTION_ESTABLISHED === $view['state'] ? 'connected' : '' );
		}

		return self::answer( $view );
	}

	/**
	 * A response that no cache keeps.
	 *
	 * @param array<string, mixed> $data Body.
	 * @return WP_REST_Response
	 */
	private static function answer( array $data ) {
		$response = new WP_REST_Response( $data, 200 );
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}
}
