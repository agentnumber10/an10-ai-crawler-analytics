<?php
/**
 * Facts about a page's content, and the one structural edit we propose.
 *
 * Everything here is a count or a position, never a grade. Where the product
 * has a named threshold (thin content) it is used; where it has none (how many
 * words before the first h2 is "too many"), the number is reported and no
 * verdict is attached. The product removed its answer-directness heuristics
 * because a regex guess is not a measurement; this class keeps that rule.
 *
 * Pure: no WordPress functions.
 *
 * @package AiCrawlerAnalytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Content analysis and heading proposals.
 */
final class AN10_Content {

	const HEADING_RE = '#<h([1-6])\b([^>]*)>(.*?)</h\1\s*>#is';

	const QUESTION_START = '/^(who|what|when|where|why|how|which|can|could|does|do|did|is|are|was|were|should|will|would)\b/i';

	/**
	 * Headings in document order.
	 *
	 * @param string $html Content HTML.
	 * @return array<int, array{level: int, text: string, offset: int}>
	 */
	public static function headings( string $html ): array {
		$out = array();
		if ( preg_match_all( self::HEADING_RE, $html, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE ) ) {
			foreach ( $m as $match ) {
				$out[] = array(
					'level'  => (int) $match[1][0],
					'text'   => self::plain( $match[3][0] ),
					'offset' => (int) $match[0][1],
				);
			}
		}
		return $out;
	}

	/**
	 * Measured facts about rendered content.
	 *
	 * @param string $rendered Rendered content HTML (shortcodes and blocks expanded).
	 * @param string $raw      Stored post content, where the editable headings live.
	 * @return array{word_count: int, words_before_first_h2: int|null, h1_count: int, headings: array, skips: array, question_headings: int, jsonld_types: string[], jsonld_invalid: int}
	 */
	public static function analyze( string $rendered, string $raw ): array {
		list( $types, $invalid ) = self::jsonld( $rendered );
		$text_html               = self::without_scripts( $rendered );

		$headings = self::headings( $raw );
		$levels   = array_column( $headings, 'level' );

		$before = null;
		foreach ( self::headings( $text_html ) as $h ) {
			if ( 2 === $h['level'] ) {
				$before = self::word_count( self::plain( substr( $text_html, 0, $h['offset'] ) ) );
				break;
			}
		}

		$questions = 0;
		foreach ( $headings as $h ) {
			if ( str_ends_with( $h['text'], '?' ) || preg_match( self::QUESTION_START, $h['text'] ) ) {
				++$questions;
			}
		}

		return array(
			'word_count'            => self::word_count( self::plain( $text_html ) ),
			'words_before_first_h2' => $before,
			'h1_count'              => count( array_keys( $levels, 1, true ) ),
			'headings'              => array_map(
				static function ( array $h ): array {
					return array(
						'level' => $h['level'],
						'text'  => $h['text'],
					);
				},
				$headings
			),
			'skips'                 => self::skips( $levels ),
			'question_headings'     => $questions,
			'jsonld_types'          => $types,
			'jsonld_invalid'        => $invalid,
		);
	}

	/**
	 * Places where the heading level jumps by more than one.
	 *
	 * The theme prints the title as the page's h1, so the content starts
	 * below it: a first heading of h3 skips h2.
	 *
	 * @param int[] $levels Heading levels in order.
	 * @return array<int, array{index: int, from: int, to: int}>
	 */
	public static function skips( array $levels ): array {
		$out  = array();
		$prev = 1;
		foreach ( array_values( $levels ) as $i => $level ) {
			if ( $level > $prev + 1 ) {
				$out[] = array(
					'index' => $i,
					'from'  => $prev,
					'to'    => $level,
				);
			}
			$prev = $level;
		}
		return $out;
	}

	/**
	 * The heading levels we would propose: no skipped levels, and no h1 in the
	 * content because the title already is the h1. Relative nesting is kept.
	 *
	 * This is a proposal for a person to review, never an automatic edit:
	 * which level a heading belongs at is an editorial decision.
	 *
	 * @param int[] $levels Heading levels in order.
	 * @return int[]
	 */
	public static function propose_levels( array $levels ): array {
		$out   = array();
		$stack = array(); // Open sections: [original level, proposed level].
		foreach ( array_values( $levels ) as $level ) {
			$level = max( 2, (int) $level );
			while ( $stack && end( $stack )[0] > $level ) {
				array_pop( $stack );
			}
			if ( $stack && end( $stack )[0] === $level ) {
				// A sibling of the open section keeps that section's level.
				$new = end( $stack )[1];
				array_pop( $stack );
			} else {
				// A child sits one level below its parent, or at h2 under the title.
				$new = $stack ? end( $stack )[1] + 1 : 2;
			}
			$stack[] = array( $level, $new );
			$out[]   = $new;
		}
		return $out;
	}

	/**
	 * Rewrite heading levels in stored content, keeping everything else.
	 *
	 * Handles classic HTML and block markup: a core/heading block stores its
	 * level in the block comment as well as the tag, and both must change or
	 * the editor reports the block as invalid.
	 *
	 * @param string $content Stored post content.
	 * @param int[]  $levels  New level for each heading, in document order.
	 * @return string|null New content, or null when the heading count no longer matches.
	 */
	public static function rewrite_headings( string $content, array $levels ): ?string {
		$levels = array_values( $levels );
		preg_match_all( self::HEADING_RE, $content, $m, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );
		if ( count( $m ) !== count( $levels ) ) {
			return null;
		}

		$out    = '';
		$cursor = 0;
		foreach ( $m as $i => $match ) {
			$start = (int) $match[0][1];
			$old   = (int) $match[1][0];
			$new   = (int) $levels[ $i ];
			$chunk = substr( $content, $cursor, $start - $cursor );
			if ( $new !== $old ) {
				$chunk = self::retarget_block_comment( $chunk, $new );
				$tag   = '<h' . $new . $match[2][0] . '>' . $match[3][0] . '</h' . $new . '>';
			} else {
				$tag = $match[0][0];
			}
			$out   .= $chunk . $tag;
			$cursor = $start + strlen( $match[0][0] );
		}
		return $out . substr( $content, $cursor );
	}

	/**
	 * If the text before a heading ends with its core/heading block comment,
	 * set that comment's level attribute.
	 *
	 * @param string $chunk Content between the previous heading and this one.
	 * @param int    $level New level.
	 */
	private static function retarget_block_comment( string $chunk, int $level ): string {
		if ( ! preg_match( '#<!--\s+wp:heading(?:\s+(\{.*?\}))?\s+-->(\s*)$#s', $chunk, $c, PREG_OFFSET_CAPTURE ) ) {
			return $chunk;
		}
		$attrs = array();
		if ( isset( $c[1] ) && $c[1][1] >= 0 && '' !== $c[1][0] ) {
			$decoded = json_decode( $c[1][0], true );
			if ( ! is_array( $decoded ) ) {
				return $chunk;
			}
			$attrs = $decoded;
		}
		if ( 2 === $level ) {
			unset( $attrs['level'] );
		} else {
			$attrs['level'] = $level;
		}
		$comment = '<!-- wp:heading ' . ( $attrs ? self::serialize_block_attributes( $attrs ) . ' ' : '' ) . '-->';
		return substr( $chunk, 0, (int) $c[0][1] ) . $comment . $c[2][0];
	}

	/**
	 * Encode block attributes the way WordPress core does (serialize_block_attributes).
	 *
	 * @param array $attrs Attributes.
	 */
	private static function serialize_block_attributes( array $attrs ): string {
		$encoded = (string) json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		$encoded = str_replace( '--', '\\u002d\\u002d', $encoded );
		$encoded = str_replace( '<', '\\u003c', $encoded );
		$encoded = str_replace( '>', '\\u003e', $encoded );
		$encoded = str_replace( '&', '\\u0026', $encoded );
		return str_replace( '\\"', '\\u0022', $encoded );
	}

	/**
	 * JSON-LD @types found in the HTML, and how many blocks failed to parse.
	 *
	 * @param string $html HTML.
	 * @return array{0: string[], 1: int}
	 */
	public static function jsonld( string $html ): array {
		$types   = array();
		$invalid = 0;
		if ( preg_match_all( '#<script\b[^>]*type\s*=\s*["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m ) ) {
			foreach ( $m[1] as $json ) {
				$data = json_decode( trim( $json ), true );
				if ( ! is_array( $data ) ) {
					++$invalid;
					continue;
				}
				self::collect_types( $data, $types );
			}
		}
		return array( array_values( array_unique( $types ) ), $invalid );
	}

	/**
	 * Walk decoded JSON-LD collecting @type values, including inside @graph.
	 *
	 * @param array    $node  Decoded node.
	 * @param string[] $types Accumulator.
	 */
	private static function collect_types( array $node, array &$types ): void {
		if ( isset( $node['@type'] ) ) {
			foreach ( (array) $node['@type'] as $t ) {
				if ( is_string( $t ) ) {
					$types[] = $t;
				}
			}
		}
		foreach ( $node as $value ) {
			if ( is_array( $value ) ) {
				self::collect_types( $value, $types );
			}
		}
	}

	/**
	 * Count words: runs of letters or digits, allowing inner apostrophes and hyphens.
	 *
	 * @param string $text Plain text.
	 */
	public static function word_count( string $text ): int {
		return (int) preg_match_all( "/[\p{L}\p{N}]+(?:['’\-][\p{L}\p{N}]+)*/u", $text );
	}

	/**
	 * HTML to plain text.
	 *
	 * @param string $html HTML fragment.
	 */
	public static function plain( string $html ): string {
		$text = html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Drop script, style and noscript blocks, which carry no readable text.
	 *
	 * @param string $html HTML.
	 */
	private static function without_scripts( string $html ): string {
		return (string) preg_replace( '#<(script|style|noscript)\b[^>]*>.*?</\1\s*>#is', ' ', $html );
	}
}
