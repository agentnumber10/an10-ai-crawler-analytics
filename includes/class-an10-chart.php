<?php
/**
 * A column chart in plain HTML: one series, one value per day.
 *
 * Plain HTML and CSS rather than SVG or a chart library, so the text stays at
 * the page's own size on any screen width, nothing is loaded from another
 * site, and no script is needed. The tooltip is CSS, read from a data
 * attribute, so a label can never become markup. The same numbers are in a
 * table under the chart for anyone who cannot see it.
 *
 * Pure: it builds a string and touches no WordPress state, so it is unit
 * tested on its own.
 *
 * @package AiCrawlerAnalytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Column chart markup.
 */
final class AN10_Chart {

	/**
	 * The smallest clean axis top at or above a value, in at most four steps.
	 *
	 * @param int $max Largest value.
	 * @return array{0: int, 1: int} Axis top and step.
	 */
	public static function axis( int $max ): array {
		if ( $max <= 0 ) {
			return array( 4, 1 );
		}
		// Steps 1, 2, 5, 10, 20, 50 and so on: the first that covers the
		// largest value in four steps or fewer.
		for ( $power = 1; ; $power *= 10 ) {
			foreach ( array( 1, 2, 5 ) as $m ) {
				$step  = $m * $power;
				$steps = (int) ceil( $max / $step );
				if ( $steps <= 4 ) {
					return array( $step * $steps, $step );
				}
			}
		}
	}

	/**
	 * The chart.
	 *
	 * @param array  $points  Each: label (short, under the axis), title (the
	 *                        tooltip), value (int). Oldest first.
	 * @param string $summary What the chart shows, for screen readers.
	 * @param string $nothing Shown over the plot when every value is 0.
	 * @param int    $every   Label every nth column, counted back from the last.
	 * @return string HTML.
	 */
	public static function columns( array $points, string $summary, string $nothing, int $every = 7 ): string {
		$values             = array_map( 'intval', array_column( $points, 'value' ) );
		$max                = $values ? max( $values ) : 0;
		list( $top, $step ) = self::axis( $max );
		$last               = count( $points ) - 1;
		// Each column's tooltip sits on its bar, so nothing extra is laid out
		// until a reader points at one.

		$grid = '';
		for ( $tick = 0; $tick <= $top; $tick += $step ) {
			$grid .= sprintf(
				'<span class="an10-chart__tick" style="bottom:%s%%"><span>%s</span></span>',
				self::pct( $tick, $top ),
				self::esc( number_format( $tick ) )
			);
		}

		$cols = '';
		foreach ( array_values( $points ) as $i => $p ) {
			$value = (int) $p['value'];
			$bar   = $value > 0 ? sprintf( '<span class="an10-chart__bar" style="height:%s%%" data-tip="%s"></span>', self::pct( $value, $top ), self::esc( (string) $p['title'] ) ) : '';
			$cols .= sprintf(
				'<li class="an10-chart__col"%s%s>%s</li>',
				$value > 0 ? ' tabindex="0" aria-label="' . self::esc( (string) $p['title'] ) . '"' : '',
				$i === $last ? ' aria-current="date"' : '',
				$bar
			);
		}

		$labels = '';
		foreach ( array_values( $points ) as $i => $p ) {
			$show    = 0 === ( $last - $i ) % max( 1, $every );
			$labels .= '<li>' . ( $show ? self::esc( (string) $p['label'] ) : '' ) . '</li>';
		}

		return '<figure class="an10-chart" role="group" aria-label="' . self::esc( $summary ) . '">'
			. '<div class="an10-chart__plot">' . $grid
			. '<ol class="an10-chart__cols">' . $cols . '</ol>'
			. ( 0 === $max ? '<p class="an10-chart__empty">' . self::esc( $nothing ) . '</p>' : '' )
			. '</div><ol class="an10-chart__x" aria-hidden="true">' . $labels . '</ol></figure>';
	}

	/**
	 * A share as a CSS percentage, two decimals at most.
	 *
	 * @param int $value Value.
	 * @param int $top   Axis top.
	 */
	private static function pct( int $value, int $top ): string {
		return rtrim( rtrim( number_format( $top > 0 ? 100 * $value / $top : 0, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * Escape for HTML text and attributes.
	 *
	 * @param string $text Text.
	 */
	private static function esc( string $text ): string {
		return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}
