<?php
/**
 * Guideline 7: every request to another site is opt-in and documented. These
 * tests stop a new outbound request from slipping in unnoticed.
 *
 * @package AiCrawlerAnalytics
 */

use PHPUnit\Framework\TestCase;

/**
 * External services stay documented and contained.
 */
final class ExternalServicesTest extends TestCase {

	/**
	 * Every list the plugin can download is named in readme.txt.
	 */
	public function test_every_downloaded_list_is_documented(): void {
		$readme = (string) file_get_contents( AN10_ROOT . '/readme.txt' );
		$start  = strpos( $readme, '== External services ==' );
		$this->assertNotFalse( $start, 'readme.txt has an External services section' );
		$section = substr( $readme, (int) $start, (int) strpos( $readme, '== Frequently Asked Questions ==' ) - (int) $start );
		foreach ( AN10_Verifier::list_urls() as $url ) {
			$this->assertStringContainsString( $url, $section, "$url is downloaded but not listed under External services" );
		}
	}

	/**
	 * Only the verifier (the opt-in list downloads) and the site class (requests
	 * to the site's own pages) make HTTP requests. Anything new must be opt-in,
	 * documented in readme.txt, and added here on purpose.
	 */
	public function test_only_known_classes_make_requests(): void {
		$allowed = array( 'class-an10-verifier.php', 'class-an10-site.php' );
		$pattern = '/\b(wp_remote_(get|post|request|head)|wp_safe_remote_\w+|download_url|curl_init|fsockopen|stream_socket_client)\s*\(/';
		$files   = array_merge(
			glob( AN10_ROOT . '/includes/*.php' ),
			glob( AN10_ROOT . '/includes/*/*.php' ),
			array( AN10_ROOT . '/an10-ai-crawler-analytics.php', AN10_ROOT . '/uninstall.php' )
		);
		foreach ( $files as $file ) {
			if ( in_array( basename( $file ), $allowed, true ) ) {
				continue;
			}
			$this->assertDoesNotMatchRegularExpression( $pattern, (string) file_get_contents( $file ), basename( $file ) . ' makes an HTTP request' );
		}
	}
}
