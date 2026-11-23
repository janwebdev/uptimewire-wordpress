<?php
/**
 * The answer to UptimeWire's check that this WordPress asked for the code.
 *
 * @package UptimeWire
 */

namespace UptimeWire;

defined( 'ABSPATH' ) || exit;

/**
 * After a person clicks "Allow", UptimeWire requests
 * GET {site}/?uptimewire_proof={authorization_id} and compares the proof with
 * the hash this site sent with its code request (BE-2 contract, section 4).
 *
 * The plugin answers only while its own authorization is pending and only for
 * its own authorization ID. Any other request with the parameter gets the
 * normal page: the parameter itself never reveals a pending connection.
 */
final class Proof {

	/** Query parameter UptimeWire uses. */
	const PARAM = 'uptimewire_proof';

	/**
	 * Registers the early handler: before the theme, redirects and page caches built into WordPress.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_answer' ), 0 );
	}

	/**
	 * Answers the proof request when it is for the pending authorization.
	 *
	 * @return void
	 */
	public static function maybe_answer() {
		// A request from UptimeWire's servers, not from a signed-in person: there is no nonce, the authorization ID is the label.
		if ( ! isset( $_GET[ self::PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$label  = wp_unslash( $_GET[ self::PARAM ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Compared with hash_equals() only, never stored or printed.

		if ( ( 'GET' !== $method && 'HEAD' !== $method ) || ! is_string( $label ) || strlen( $label ) > 64 ) {
			return;
		}

		$pending = Authorization::pending();

		// A copy made while connecting holds the same authorization: only the site that started it proves it.
		if ( null === $pending || time() > (int) $pending['expires_at'] + Authorization::PROOF_GRACE || ! hash_equals( (string) $pending['authorization_id'], $label ) || ! Authorization::is_here( $pending ) ) {
			return;
		}

		Authorization::mark_proof_seen( $pending['authorization_id'] );

		// Page caches must not keep this answer, nor serve it to anyone else.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- The constant page caches agree on.
		}

		nocache_headers();
		// WordPress before 6.8 sends no "no-store"; the contract and shared caches need it.
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private' );
		// Caches in front of the site (CDNs, proxies) that ignore Cache-Control may still obey these.
		header( 'CDN-Cache-Control: no-store' );
		header( 'Cloudflare-CDN-Cache-Control: no-store' );
		header( 'Surrogate-Control: no-store' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		wp_send_json( array( 'proof' => $pending['proof'] ), 200 );
	}
}
