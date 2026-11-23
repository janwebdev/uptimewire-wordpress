<?php
/**
 * Tests for UptimeWire\Site_Match.
 *
 * @package UptimeWire
 */

namespace UptimeWire\Tests;

use PHPUnit\Framework\TestCase;
use UptimeWire\Site_Match;

/**
 * Which website addresses mean the same website.
 */
final class SiteMatchTest extends TestCase {

	/**
	 * Data: pairs that are the same website.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function same() {
		return array(
			'identical'          => array( 'https://example.com/', 'https://example.com/' ),
			'trailing slash'     => array( 'https://example.com', 'https://example.com/' ),
			'scheme'             => array( 'http://example.com/', 'https://example.com' ),
			'host case'          => array( 'https://Example.COM/', 'https://example.com/' ),
			'trailing dot'       => array( 'https://example.com./', 'https://example.com/' ),
			'default https port' => array( 'https://example.com:443/', 'https://example.com/' ),
			'default http port'  => array( 'http://example.com:80/blog', 'https://example.com/blog/' ),
			'folder'             => array( 'https://example.com/blog/', 'https://example.com/blog' ),
			'custom port'        => array( 'https://example.com:8443/', 'https://example.com:8443' ),
		);
	}

	/**
	 * Data: pairs that are different websites.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function different() {
		return array(
			'www'              => array( 'https://www.example.com/', 'https://example.com/' ),
			'other domain'     => array( 'https://example.org/', 'https://example.com/' ),
			'suffix attack'    => array( 'https://example.com.evil.test/', 'https://example.com/' ),
			'prefix attack'    => array( 'https://evilexample.com/', 'https://example.com/' ),
			'subdomain'        => array( 'https://shop.example.com/', 'https://example.com/' ),
			'other folder'     => array( 'https://example.com/shop', 'https://example.com/' ),
			'folder case'      => array( 'https://example.com/Blog', 'https://example.com/blog' ),
			'other port'       => array( 'https://example.com:8443/', 'https://example.com/' ),
			'query'            => array( 'https://example.com/?a=1', 'https://example.com/' ),
			'not http'         => array( 'ftp://example.com/', 'ftp://example.com/' ),
			'credentials'      => array( 'https://user:pass@example.com/', 'https://user:pass@example.com/' ),
			'empty'            => array( '', '' ),
			'garbage'          => array( 'example.com', 'example.com' ),
			'http default 443' => array( 'http://example.com:443/', 'http://example.com/' ),
		);
	}

	/**
	 * Same website.
	 *
	 * @dataProvider same
	 * @param string $a One address.
	 * @param string $b Other address.
	 * @return void
	 */
	public function test_same_website( $a, $b ) {
		$this->assertTrue( Site_Match::matches_any( $a, array( $b ) ) );
		$this->assertTrue( Site_Match::matches_any( $b, array( $a ) ) );
	}

	/**
	 * Different websites.
	 *
	 * @dataProvider different
	 * @param string $a One address.
	 * @param string $b Other address.
	 * @return void
	 */
	public function test_different_website( $a, $b ) {
		$this->assertFalse( Site_Match::matches_any( $a, array( $b ) ) );
	}

	/**
	 * An internationalized name matches its ASCII form when the intl extension is there.
	 *
	 * @return void
	 */
	public function test_idn() {
		if ( ! function_exists( 'idn_to_ascii' ) ) {
			$this->assertFalse( Site_Match::matches_any( 'https://bücher.example/', array( 'https://xn--bcher-kva.example/' ) ), 'Without intl the names stay different.' );

			return;
		}

		$this->assertTrue( Site_Match::matches_any( 'https://bücher.example/', array( 'https://xn--bcher-kva.example/' ) ) );
		$this->assertFalse( Site_Match::matches_any( 'https://bucher.example/', array( 'https://xn--bcher-kva.example/' ) ) );
	}

	/**
	 * Any of several candidates.
	 *
	 * @return void
	 */
	public function test_any_candidate() {
		$this->assertTrue( Site_Match::matches_any( 'https://shop.example.com', array( 'https://example.com/', 'http://shop.example.com/' ) ) );
		$this->assertFalse( Site_Match::matches_any( 'https://shop.example.com', array() ) );
	}
}
