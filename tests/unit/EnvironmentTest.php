<?php
/**
 * Tests for UptimeWire\Environment.
 *
 * @package UptimeWire
 */

namespace UptimeWire\Tests;

use PHPUnit\Framework\TestCase;
use UptimeWire\Environment;

/**
 * Environment warnings shown on the welcome screen.
 */
final class EnvironmentTest extends TestCase {

	/**
	 * Data: hosts that cannot be reached from the internet.
	 *
	 * @return array<string, array{string}>
	 */
	public function private_hosts() {
		return array(
			'localhost'           => array( 'localhost' ),
			'localhost subdomain' => array( 'shop.localhost' ),
			'.local'              => array( 'mysite.local' ),
			'.test'               => array( 'mysite.test' ),
			'.internal'           => array( 'wp.corp.internal' ),
			'.home.arpa'          => array( 'nas.home.arpa' ),
			'trailing dot'        => array( 'mysite.test.' ),
			'upper case'          => array( 'MySite.LOCAL' ),
			'single label'        => array( 'wordpress' ),
			'loopback v4'         => array( '127.0.0.1' ),
			'private 10/8'        => array( '10.1.2.3' ),
			'private 172.16/12'   => array( '172.20.0.5' ),
			'private 192.168/16'  => array( '192.168.28.219' ),
			'link-local'          => array( '169.254.10.10' ),
			'carrier-grade NAT'   => array( '100.64.1.1' ),
			'unspecified'         => array( '0.0.0.0' ),
			'loopback v6'         => array( '[::1]' ),
			'unique local v6'     => array( '[fd12:3456::1]' ),
			'empty'               => array( '' ),
		);
	}

	/**
	 * Data: public hosts.
	 *
	 * @return array<string, array{string}>
	 */
	public function public_hosts() {
		return array(
			'domain'            => array( 'example.com' ),
			'subdomain'         => array( 'shop.example.co.uk' ),
			'local in the name' => array( 'local.example.com' ),
			'test in the name'  => array( 'test.example.org' ),
			'public v4'         => array( '93.184.216.34' ),
			'near CGNAT'        => array( '100.128.0.1' ),
			'public v6'         => array( '[2606:4700::1111]' ),
		);
	}

	/**
	 * Private hosts are detected.
	 *
	 * @dataProvider private_hosts
	 *
	 * @param string $host Host.
	 */
	public function test_private_hosts_are_detected( $host ) {
		$this->assertTrue( Environment::is_private_host( $host ) );
	}

	/**
	 * Public hosts are not reported as private.
	 *
	 * @dataProvider public_hosts
	 *
	 * @param string $host Host.
	 */
	public function test_public_hosts_are_not_private( $host ) {
		$this->assertFalse( Environment::is_private_host( $host ) );
	}

	/**
	 * A normal live site gets no warning.
	 */
	public function test_public_https_production_site_has_no_warnings() {
		$this->assertSame( array(), Environment::warnings( 'production', 'https://example.com/' ) );
	}

	/**
	 * The "local" environment type wins over a public address.
	 */
	public function test_local_environment_type_blocks_even_a_public_address() {
		$this->assertSame( array( Environment::WARNING_LOCAL ), Environment::warnings( 'local', 'https://example.com/' ) );
	}

	/**
	 * A local address shows only the local warning.
	 */
	public function test_local_address_hides_the_other_warnings() {
		$this->assertSame( array( Environment::WARNING_LOCAL ), Environment::warnings( 'staging', 'http://localhost:8080/' ) );
	}

	/**
	 * An address without a host cannot be monitored.
	 */
	public function test_address_without_host_is_local() {
		$this->assertSame( array( Environment::WARNING_LOCAL ), Environment::warnings( 'production', 'not a url' ) );
	}

	/**
	 * Staging and development need the "I understand" confirmation.
	 */
	public function test_staging_and_development_need_confirmation() {
		$this->assertSame( array( Environment::WARNING_STAGING ), Environment::warnings( 'staging', 'https://staging.example.com/' ) );
		$this->assertSame( array( Environment::WARNING_STAGING ), Environment::warnings( 'development', 'https://dev.example.com/' ) );
	}

	/**
	 * A site without TLS gets the "no certificate" notice.
	 */
	public function test_http_site_gets_the_no_certificate_notice() {
		$this->assertSame( array( Environment::WARNING_NO_HTTPS ), Environment::warnings( 'production', 'http://example.com/' ) );
	}

	/**
	 * The scheme is compared without regard to case.
	 */
	public function test_scheme_is_compared_case_insensitively() {
		$this->assertSame( array(), Environment::warnings( 'production', 'HTTPS://Example.com/' ) );
	}

	/**
	 * Staging over http gets both warnings, staging first.
	 */
	public function test_staging_over_http_gets_both_warnings_in_order() {
		$this->assertSame(
			array( Environment::WARNING_STAGING, Environment::WARNING_NO_HTTPS ),
			Environment::warnings( 'staging', 'http://staging.example.com/' )
		);
	}
}
