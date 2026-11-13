<?php
/**
 * Who is asking: AI crawlers by user agent, AI engines by referrer.
 *
 * A user agent only says who a request CLAIMS to be. Matching here produces
 * a claim; AN10_Verifier decides whether the claim holds.
 *
 * Pure: no WordPress functions.
 *
 * @package AiCrawlerAnalytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * User agent and referrer classification.
 */
final class AN10_Agents {

	/**
	 * Search engine crawlers. Their pages feed AI answers too (Google AI
	 * Overviews and AI Mode, Copilot), so robots.txt checks treat them as AI
	 * search, but most of what they fetch is ordinary search indexing. They are
	 * listed with the AI crawlers and never added to an AI total, where they
	 * would usually be most of the number.
	 */
	const SEARCH_ENGINES = array( 'Googlebot', 'Bingbot' );

	/**
	 * The crawler a user agent claims to be, or null.
	 *
	 * Tokens are matched case-insensitively, longest first, so a token that
	 * contains another never loses to it. Robots-only tokens (no user agent of
	 * their own, such as Google-Extended) never match a request.
	 *
	 * @param string $user_agent Request user agent.
	 * @param array  $crawlers   Registry rows (AN10_Registry::crawlers()).
	 * @return array|null The registry row.
	 */
	public static function crawler_for( string $user_agent, array $crawlers ): ?array {
		if ( '' === $user_agent ) {
			return null;
		}
		$candidates = array_values(
			array_filter(
				$crawlers,
				static function ( array $c ): bool {
					return null !== $c['user_agent'];
				}
			)
		);
		usort(
			$candidates,
			static function ( array $a, array $b ): int {
				return strlen( $b['token'] ) <=> strlen( $a['token'] );
			}
		);
		foreach ( $candidates as $c ) {
			if ( false !== stripos( $user_agent, $c['token'] ) ) {
				return $c;
			}
		}
		return null;
	}

	/**
	 * Lowercase host without scheme, `www.`, path, query or fragment.
	 *
	 * Mirrors the product's normaliser for AI traffic sources.
	 *
	 * @param string $source Host, URL or utm_source value.
	 */
	public static function bare_host( string $source ): string {
		$s = strtolower( trim( $source ) );
		$s = (string) preg_replace( '#^https?://#', '', $s );
		$s = (string) preg_replace( '#^www\.#', '', $s );
		return (string) preg_replace( '#[/?\#].*$#', '', $s );
	}

	/**
	 * The AI engine a referrer host or utm_source belongs to, or null.
	 *
	 * Matched on the host or any subdomain of it, as the product does.
	 *
	 * @param string $source Referrer host or utm_source.
	 * @param array  $hosts  Host to engine map (AN10_Registry::ai_source_hosts()).
	 */
	public static function engine_for( string $source, array $hosts ): ?string {
		$host = self::bare_host( $source );
		if ( '' === $host ) {
			return null;
		}
		foreach ( $hosts as $h => $engine ) {
			if ( $host === $h || str_ends_with( $host, '.' . $h ) ) {
				return (string) $engine;
			}
		}
		return null;
	}
}
