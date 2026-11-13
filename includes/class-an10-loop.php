<?php
/**
 * Did anything happen after a fix?
 *
 * For each change applied to pages, two facts from the same site: which
 * verified AI crawlers have fetched those pages since, and how many visitors
 * AI answers sent to them before and after. "Before" is a window the same
 * length as the time since the change, so the two numbers are comparable.
 *
 * This is correlation, not proof. The window is often short, other things
 * change at the same time, and crawler counts are a floor on cached sites.
 * Every statement says so.
 *
 * @package AiCrawlerAnalytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fix outcome correlation.
 */
final class AN10_Loop {

	/**
	 * Outcomes for the most recent page changes, grouped by batch.
	 *
	 * @param int $limit Batches.
	 * @return array<int, array{summary: string, type: string, pages: int, applied_at: int, crawlers: array<string, int>, before: int, after: int, by_engine: array<string, int>}>
	 */
	public static function outcomes( int $limit = 10 ): array {
		$batches = array();
		foreach ( AN10_Changes::log() as $record ) {
			if ( 'applied' !== $record['status'] || empty( $record['post_id'] ) ) {
				continue;
			}
			$key = $record['batch'];
			if ( ! isset( $batches[ $key ] ) ) {
				$batches[ $key ] = array(
					'type'       => $record['type'],
					'summary'    => $record['summary'],
					'post_ids'   => array(),
					'applied_at' => (int) $record['applied_at'],
				);
			}
			$batches[ $key ]['post_ids'][] = (int) $record['post_id'];
			$batches[ $key ]['applied_at'] = min( $batches[ $key ]['applied_at'], (int) $record['applied_at'] );
		}

		$out = array();
		foreach ( array_slice( $batches, 0, $limit ) as $b ) {
			$since  = $b['applied_at'];
			$window = max( 1, time() - $since );
			$after  = AN10_Store::referrals( $since, time() + 1, $b['post_ids'] );
			$before = AN10_Store::referrals( $since - $window, $since, $b['post_ids'] );
			$out[]  = array(
				'summary'    => $b['summary'],
				'type'       => $b['type'],
				'pages'      => count( array_unique( $b['post_ids'] ) ),
				'applied_at' => $since,
				'crawlers'   => AN10_Store::verified_tokens_for( $b['post_ids'], $since ),
				'before'     => array_sum( $before ),
				'after'      => array_sum( $after ),
				'by_engine'  => $after,
			);
		}
		return $out;
	}
}
