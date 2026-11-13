<?php
/**
 * robots.txt parsing and evaluation, per RFC 9309.
 *
 * It gives the same verdicts as the robots.txt check on agentnumber10.com:
 * its tests run against verdicts that check produced, so the two cannot
 * quietly disagree about whether a crawler is allowed.
 *
 * The rules a naive "is there a Disallow: /" scan gets wrong:
 *  - A crawler obeys the groups that name it, and only falls back to `*` when
 *    no group names it.
 *  - Several groups naming the same crawler are merged.
 *  - The longest matching path wins; on a tie, Allow wins.
 *  - `*` matches any run of characters, a trailing `$` anchors the end.
 *  - An empty Disallow blocks nothing; /robots.txt itself is always allowed.
 *
 * Pure: no WordPress functions.
 *
 * @package AiCrawlerAnalytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * robots.txt parser and evaluator.
 */
final class AN10_Robots {

	/**
	 * Parse a robots.txt file.
	 *
	 * @param string $text File contents.
	 * @return array{groups: array<int, array{agents: string[], rules: array<int, array{allow: bool, path: string, line: int}>}>, sitemaps: string[]}
	 */
	public static function parse( string $text ): array {
		$groups     = array();
		$sitemaps   = array();
		$current    = null;
		$collecting = false;

		if ( str_starts_with( $text, "\xEF\xBB\xBF" ) ) {
			$text = substr( $text, 3 );
		}
		$lines = preg_split( '/\r\n|\r|\n/', $text );

		foreach ( $lines as $i => $raw ) {
			$line = preg_replace( '/#.*$/', '', $raw );
			if ( ! preg_match( '/^\s*([A-Za-z-]+)\s*:\s*(.*?)\s*$/', $line, $m ) ) {
				continue;
			}
			$field = strtolower( $m[1] );
			$value = $m[2] ?? '';

			if ( 'user-agent' === $field ) {
				if ( null === $current || ! $collecting ) {
					$groups[] = array(
						'agents' => array(),
						'rules'  => array(),
					);
					$current  = count( $groups ) - 1;
				}
				$groups[ $current ]['agents'][] = strtolower( $value );
				$collecting                     = true;
				continue;
			}
			if ( 'sitemap' === $field ) {
				if ( '' !== $value ) {
					$sitemaps[] = $value;
				}
				continue;
			}
			if ( 'allow' === $field || 'disallow' === $field ) {
				$collecting = false;
				if ( null === $current || '' === $value ) {
					continue;
				}
				$groups[ $current ]['rules'][] = array(
					'allow' => 'allow' === $field,
					'path'  => $value,
					'line'  => $i + 1,
				);
				continue;
			}
			// Other fields (crawl-delay, host...) end the agent list but set no rule.
			$collecting = false;
		}

		return array(
			'groups'   => $groups,
			'sitemaps' => $sitemaps,
		);
	}

	/**
	 * Does a robots path pattern match a URL path? Supports `*` and a trailing `$`.
	 *
	 * @param string $pattern Rule path.
	 * @param string $path    URL path plus query, starting with "/".
	 */
	public static function path_matches( string $pattern, string $path ): bool {
		$anchored = str_ends_with( $pattern, '$' );
		$body     = $anchored ? substr( $pattern, 0, -1 ) : $pattern;
		$parts    = array_map(
			static function ( string $part ): string {
				return preg_quote( $part, '#' );
			},
			explode( '*', $body )
		);
		$regex    = '#^' . implode( '.*', $parts ) . ( $anchored ? '$' : '' ) . '#';
		return 1 === preg_match( $regex, $path );
	}

	/**
	 * Is `$path` allowed for the crawler whose product token is `$token`?
	 *
	 * @param array|null $robots Parsed file, or null when there is none (all allowed).
	 * @param string     $token  Product token, e.g. "GPTBot".
	 * @param string     $path   URL path plus query, starting with "/".
	 * @return array{allowed: bool, rule: array{allow: bool, path: string, line: int}|null, via: string}
	 */
	public static function evaluate( ?array $robots, string $token, string $path ): array {
		if ( null === $robots || '/robots.txt' === $path ) {
			return array(
				'allowed' => true,
				'rule'    => null,
				'via'     => 'none',
			);
		}

		$name   = strtolower( $token );
		$own    = array_values(
			array_filter(
				$robots['groups'],
				static function ( array $g ) use ( $name ): bool {
					return in_array( $name, $g['agents'], true );
				}
			)
		);
		$chosen = $own ? $own : array_values(
			array_filter(
				$robots['groups'],
				static function ( array $g ): bool {
					return in_array( '*', $g['agents'], true );
				}
			)
		);
		if ( ! $chosen ) {
			return array(
				'allowed' => true,
				'rule'    => null,
				'via'     => 'none',
			);
		}
		$via = $own ? 'own-group' : 'wildcard';

		$best = null;
		foreach ( $chosen as $group ) {
			foreach ( $group['rules'] as $rule ) {
				if ( ! self::path_matches( $rule['path'], $path ) ) {
					continue;
				}
				if (
					null === $best
					|| self::length( $rule['path'] ) > self::length( $best['path'] )
					|| ( self::length( $rule['path'] ) === self::length( $best['path'] ) && $rule['allow'] && ! $best['allow'] )
				) {
					$best = $rule;
				}
			}
		}

		return array(
			'allowed' => null === $best ? true : $best['allow'],
			'rule'    => $best,
			'via'     => $via,
		);
	}

	/**
	 * Character length, matching how the product measures rule paths.
	 *
	 * @param string $s Rule path.
	 */
	private static function length( string $s ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $s, 'UTF-8' ) : strlen( $s );
	}
}
