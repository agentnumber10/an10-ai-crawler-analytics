<?php
use PHPUnit\Framework\TestCase;

/**
 * The PHP evaluator must return the product's own verdict for every vector.
 */
final class RobotsTest extends TestCase {

	public function vectors(): array {
		$out = array();
		foreach ( an10_test_export()['robotsVectors'] as $i => $case ) {
			foreach ( $case['verdicts'] as $j => $v ) {
				$out[ "case $i query $j ({$v['token']} {$v['path']})" ] = array( $case['robots'], $v );
			}
		}
		return $out;
	}

	/** @dataProvider vectors */
	public function test_matches_the_product_verdict( string $robots, array $expected ): void {
		$verdict = AN10_Robots::evaluate( AN10_Robots::parse( $robots ), $expected['token'], $expected['path'] );
		$this->assertSame( $expected['allowed'], $verdict['allowed'] );
		$this->assertSame( $expected['via'], $verdict['via'] );
		$this->assertSame( $expected['rule'], $verdict['rule'] );
	}

	public function test_sitemaps_match_the_product(): void {
		foreach ( an10_test_export()['robotsVectors'] as $case ) {
			$this->assertSame( $case['sitemaps'], AN10_Robots::parse( $case['robots'] )['sitemaps'] );
		}
	}

	public function test_no_file_allows_everything(): void {
		$this->assertTrue( AN10_Robots::evaluate( null, 'GPTBot', '/x' )['allowed'] );
	}
}
