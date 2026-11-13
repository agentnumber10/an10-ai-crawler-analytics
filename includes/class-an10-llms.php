<?php
/**
 * llms.txt, in the format the product generates (llmstxt.org).
 *
 *   # Site Title
 *   > Site description
 *
 *   - [Page Title](URL): Description
 *
 * Homepage first, then the order given. No major AI engine has confirmed it
 * reads llms.txt, so the plugin offers it as a convenience and never claims
 * it improves visibility.
 *
 * Pure: no WordPress functions.
 *
 * @package AiCrawlerAnalytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * llms.txt renderer.
 */
final class AN10_Llms {

	/**
	 * Render llms.txt.
	 *
	 * @param string $site_title Site title.
	 * @param string $summary    One-line description, may be empty.
	 * @param array  $entries    Rows of title, url, description, is_home.
	 */
	public static function render( string $site_title, string $summary, array $entries ): string {
		if ( ! $entries ) {
			return '';
		}
		$home = array();
		$rest = array();
		foreach ( $entries as $e ) {
			if ( ! empty( $e['is_home'] ) ) {
				$home[] = $e;
			} else {
				$rest[] = $e;
			}
		}

		$lines   = array( '# ' . self::line( $site_title ) );
		$summary = self::line( $summary );
		if ( '' !== $summary ) {
			$lines[] = '';
			$lines[] = '> ' . $summary;
		}
		$lines[] = '';
		foreach ( array_merge( $home, $rest ) as $e ) {
			$title   = self::line( (string) ( $e['title'] ?? '' ) );
			$url     = (string) ( $e['url'] ?? '' );
			$desc    = self::line( (string) ( $e['description'] ?? '' ) );
			$label   = '' === $title ? $url : str_replace( array( '[', ']' ), array( '(', ')' ), $title );
			$lines[] = '- [' . $label . '](' . $url . ')' . ( '' === $desc ? '' : ': ' . $desc );
		}
		$lines[] = '';
		return implode( "\n", $lines );
	}

	/**
	 * One line of plain text.
	 *
	 * @param string $s Text.
	 */
	private static function line( string $s ): string {
		return trim( (string) preg_replace( '/\s+/u', ' ', $s ) );
	}
}
