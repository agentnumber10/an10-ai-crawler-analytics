<?php
use PHPUnit\Framework\TestCase;

final class IpTest extends TestCase {

	public function test_ipv4_ranges(): void {
		$this->assertTrue( AN10_Ip::in_range( '20.171.206.7', '20.171.206.0/24' ) );
		$this->assertFalse( AN10_Ip::in_range( '20.171.207.7', '20.171.206.0/24' ) );
		$this->assertTrue( AN10_Ip::in_range( '216.73.219.255', '216.73.216.0/22' ) );
		$this->assertFalse( AN10_Ip::in_range( '216.73.220.0', '216.73.216.0/22' ) );
		$this->assertTrue( AN10_Ip::in_range( '1.2.3.4', '0.0.0.0/0' ) );
		$this->assertTrue( AN10_Ip::in_range( '1.2.3.4', '1.2.3.4/32' ) );
		$this->assertFalse( AN10_Ip::in_range( '1.2.3.5', '1.2.3.4/32' ) );
	}

	public function test_ipv6_ranges_and_family_mismatch(): void {
		$this->assertTrue( AN10_Ip::in_range( '2a03:2880:f00d::1', '2a03:2880::/32' ) );
		$this->assertFalse( AN10_Ip::in_range( '2a03:2881::1', '2a03:2880::/32' ) );
		$this->assertTrue( AN10_Ip::in_range( '2001:db8::7f', '2001:db8::/121' ) );
		$this->assertFalse( AN10_Ip::in_range( '2001:db8::80', '2001:db8::/121' ) );
		$this->assertFalse( AN10_Ip::in_range( '1.2.3.4', '2001:db8::/32' ) );
		$this->assertFalse( AN10_Ip::in_range( 'not-an-ip', '1.2.3.0/24' ) );
		$this->assertFalse( AN10_Ip::in_range( '1.2.3.4', '1.2.3.0/33' ) );
	}

	public function test_parses_operator_json_and_cloudflare_text(): void {
		$json = '{"creationTime":"2026-10-20T00:00:00Z","prefixes":[{"ipv4Prefix":"216.73.216.0/22"},{"ipv6Prefix":"2607:6bc0::/48"},{"ipv4Prefix":"junk"}]}';
		$this->assertSame( array( '216.73.216.0/22', '2607:6bc0::/48' ), AN10_Ip::parse_list( $json ) );
		$this->assertSame( array( '173.245.48.0/20', '103.21.244.0/22' ), AN10_Ip::parse_list( "173.245.48.0/20\n103.21.244.0/22\n" ) );
		$this->assertSame( array(), AN10_Ip::parse_list( '<html>blocked</html>' ) );
	}

	public function test_parses_cloudflare_api_json(): void {
		$json = '{"result":{"ipv4_cidrs":["173.245.48.0/20","junk"],"ipv6_cidrs":["2400:cb00::/32"],"etag":"x"},"success":true}';
		$this->assertSame( array( '173.245.48.0/20', '2400:cb00::/32' ), AN10_Ip::parse_list( $json ) );
	}

	public function test_public_addresses(): void {
		$this->assertTrue( AN10_Ip::is_public( '20.171.206.7' ) );
		$this->assertFalse( AN10_Ip::is_public( '10.0.0.4' ) );
		$this->assertFalse( AN10_Ip::is_public( '127.0.0.1' ) );
		$this->assertFalse( AN10_Ip::is_public( '172.18.0.1' ) );
	}
}
