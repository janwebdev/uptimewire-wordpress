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
				'permission_callback' => array( Plugin::class, 'current_user_can' ),
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
				'permission_callback' => array( Plugin::class, 'current_user_can' ),
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
