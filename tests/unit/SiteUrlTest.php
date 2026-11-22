<?php
/**
 * Tests for UptimeWire\Site_Url.
 *
 * @package UptimeWire
 */

namespace UptimeWire\Tests;

use PHPUnit\Framework\TestCase;
use UptimeWire\Site_Url;

/**
 * Validation of the "Website to monitor" address.
 */
final class SiteUrlTest extends TestCase {

	/**
	 * Data: accepted input and its normalized form.
	 *
	 * @return array<string, array{string, string}>
	 */
	public function accepted() {
		return array(
			'plain'              => array( 'https://example.com/', 'https://example.com/' ),
			'no path'            => array( 'https://example.com', 'https://example.com/' ),
			'http'               => array( 'http://example.com/', 'http://example.com/' ),
			'subdirectory'       => array( 'https://example.com/blog/', 'https://example.com/blog/' ),
			'port'               => array( 'https://example.com:8443/', 'https://example.com:8443/' ),
			'case'               => array( 'HTTPS://Shop.Example.COM/Path', 'https://shop.example.com/Path' ),
			'surrounding spaces' => array( "  https://example.com/  \n", 'https://example.com/' ),
			'ipv4'               => array( 'http://93.184.216.34/', 'http://93.184.216.34/' ),
			'ipv6'               => array( 'https://[2606:4700::1111]/', 'https://[2606:4700::1111]/' ),
			'percent encoded'    => array( 'https://example.com/my%20site/', 'https://example.com/my%20site/' ),
			'idn'                => array( 'https://bücher.example/', 'https://bücher.example/' ),
		);
	}

	/**
	 * Data: rejected input.
	 *
	 * @return array<string, array{string}>
	 */
	public function rejected() {
		return array(
			'empty'           => array( '' ),
			'no scheme'       => array( 'example.com' ),
			'ftp'             => array( 'ftp://example.com/' ),
			'javascript'      => array( 'javascript:alert(1)' ),
			'data'            => array( 'data:text/html,hi' ),
			'credentials'     => array( 'https://user:pass@example.com/' ),
			'user only'       => array( 'https://user@example.com/' ),
			'query'           => array( 'https://example.com/?p=1' ),
			'fragment'        => array( 'https://example.com/#top' ),
			'inner space'     => array( 'https://exa mple.com/' ),
			'quote'           => array( 'https://example.com/"onmouseover="x' ),
			'angle bracket'   => array( 'https://example.com/<script>' ),
			'backslash'       => array( 'https://example.com\\@evil.com/' ),
			'bad host chars'  => array( 'https://exa_mple.com/' ),
			'label hyphen'    => array( 'https://-example.com/' ),
			'bad ipv6'        => array( 'https://[not:an:ip]/' ),
			'scheme-relative' => array( '//example.com/' ),
			'null byte'       => array( "https://example.com/a\0b" ),
			'too long'        => array( 'https://example.com/' . str_repeat( 'a', 2048 ) ),
		);
	}

	/**
	 * Valid addresses come back normalized.
	 *
	 * @dataProvider accepted
	 *
	 * @param string $input    Input.
	 * @param string $expected Normalized address.
	 */
	public function test_valid_addresses_are_normalized( $input, $expected ) {
		$this->assertSame( $expected, Site_Url::normalize( $input ) );
	}

	/**
	 * Anything else is rejected.
	 *
	 * @dataProvider rejected
	 *
	 * @param string $input Input.
	 */
	public function test_invalid_addresses_are_rejected( $input ) {
		$this->assertSame( '', Site_Url::normalize( $input ) );
	}
}
