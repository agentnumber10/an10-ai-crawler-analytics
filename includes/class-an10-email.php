<?php
/**
 * The optional weekly email. Off until the site owner switches it on, and
 * sent by the site itself through wp_mail to the admin address.
 *
 * @package AiCrawlerAnalytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Weekly summary email.
 */
final class AN10_Email {

	/**
	 * Build and send the week's summary.
	 */
	public static function send_weekly(): void {
		if ( ! AN10_Plugin::setting( 'weekly_email' ) ) {
			return;
		}
		$body = self::body( time() - WEEK_IN_SECONDS );
		if ( '' === $body ) {
			return;
		}
		wp_mail(
			(string) get_option( 'admin_email' ),
			/* translators: %s: site name. */
			sprintf( __( 'AI crawlers this week on %s', 'an10-ai-crawler-analytics' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			$body
		);
	}

	/**
	 * Plain-text summary since a time, or '' when there is nothing to report.
	 *
	 * @param int $since Unix time.
	 */
	public static function body( int $since ): string {
		$lines = array();
		foreach ( AN10_Store::crawler_summary( $since ) as $token => $row ) {
			if ( $row['verified'] > 0 ) {
				/* translators: 1: crawler, 2: number of pages. */
				$lines[] = sprintf( _n( '%1$s fetched %2$d page.', '%1$s fetched %2$d pages.', $row['pages'], 'an10-ai-crawler-analytics' ), $token, $row['pages'] );
			}
		}
		$never = count( AN10_Admin::never_crawled_ids() );
		if ( ! $lines && ! $never ) {
			return '';
		}
		if ( ! $lines ) {
			$lines[] = __( 'No verified AI crawler visits this week.', 'an10-ai-crawler-analytics' );
		}
		if ( $never ) {
			/* translators: %d: number of pages. */
			$lines[] = sprintf( _n( '%d of your pages has never been fetched by a verified AI crawler.', '%d of your pages have never been fetched by a verified AI crawler.', $never, 'an10-ai-crawler-analytics' ), $never );
		}
		$lines[] = '';
		$lines[] = AN10_Coverage::statement();
		$lines[] = '';
		$lines[] = admin_url( 'admin.php?page=' . AN10_SLUG );
		return implode( "\n", $lines );
	}
}
