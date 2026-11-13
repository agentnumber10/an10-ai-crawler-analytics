<?php
use PHPUnit\Framework\TestCase;

/**
 * The daily column chart: clean axis steps, honest heights, inert labels.
 */
final class ChartTest extends TestCase {

	/**
	 * @dataProvider axes
	 */
	public function test_axis_top_is_a_clean_step_covering_the_largest_value( int $max, int $top, int $step ): void {
		$this->assertSame( array( $top, $step ), AN10_Chart::axis( $max ) );
	}

	public static function axes(): array {
		return array(
			'nothing yet' => array( 0, 4, 1 ),
			'one'         => array( 1, 1, 1 ),
			'four'        => array( 4, 4, 1 ),
			'seven'       => array( 7, 8, 2 ),
			'thirteen'    => array( 13, 15, 5 ),
			'thirty'      => array( 37, 40, 10 ),
			'hundreds'    => array( 250, 300, 100 ),
			'thousands'   => array( 4100, 6000, 2000 ),
		);
	}

	private function points( array $values ): array {
		$out = array();
		foreach ( $values as $i => $v ) {
			$out[] = array(
				'label' => "d$i",
				'title' => "Day $i: $v",
				'value' => $v,
			);
		}
		return $out;
	}

	public function test_a_bar_height_is_its_share_of_the_axis_top(): void {
		$html = AN10_Chart::columns( $this->points( array( 0, 2, 4 ) ), 'Fetches', 'None yet' );
		$this->assertStringContainsString( 'style="height:50%"', $html );
		$this->assertStringContainsString( 'style="height:100%"', $html );
		$this->assertSame( 2, substr_count( $html, 'an10-chart__bar' ) );
	}

	public function test_a_day_with_nothing_draws_no_bar_and_takes_no_tab_stop(): void {
		$html = AN10_Chart::columns( $this->points( array( 0, 3 ) ), 'Fetches', 'None yet' );
		$this->assertSame( 1, substr_count( $html, 'tabindex="0"' ) );
		$this->assertStringNotContainsString( 'None yet', $html );
	}

	public function test_all_zero_says_so_over_the_plot(): void {
		$html = AN10_Chart::columns( $this->points( array( 0, 0, 0 ) ), 'Fetches', 'None yet' );
		$this->assertStringContainsString( 'an10-chart__empty">None yet<', $html );
		$this->assertStringNotContainsString( 'an10-chart__bar', $html );
	}

	public function test_labels_are_selective_and_anchored_on_the_last_day(): void {
		$html = AN10_Chart::columns( $this->points( array_fill( 0, 15, 1 ) ), 'Fetches', 'None yet' );
		preg_match( '/<ol class="an10-chart__x"[^>]*>(.*)<\/ol>/', $html, $m );
		preg_match_all( '/<li>(d\d+)?<\/li>/', $m[1], $labels );
		$this->assertSame( array( 'd0', 'd7', 'd14' ), array_values( array_filter( $labels[1] ) ) );
	}

	public function test_titles_and_labels_cannot_become_markup(): void {
		$points   = $this->points( array( 1 ) );
		$points[0]['title'] = '"><script>alert(1)</script>';
		$points[0]['label'] = '<b>x</b>';
		$html = AN10_Chart::columns( $points, '<i>s</i>', 'None yet', 1 );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<b>', $html );
		$this->assertStringNotContainsString( '<i>', $html );
		$this->assertStringContainsString( 'data-tip="&quot;&gt;&lt;script&gt;', $html );
	}
}
