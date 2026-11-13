<?php
/**
 * The readiness scan: facts about every published post and page.
 *
 * Reads from the database only; it never requests the site's own pages. Runs
 * on activation, again whenever a post is saved, and weekly in the background,
 * so the findings never go stale.
 *
 * Findings are only raised where the rule is a fact, not a judgement: no
 * structured data at all, a heading level skipped, an h1 inside the content,
 * noindex on a page linked from a menu, a canonical pointing elsewhere, and
 * thin content under the product's named threshold. Everything else, such as
 * how many words come before the first h2, is shown as a number with no
 * verdict attached.
 *
 * @package AiCrawlerAnalytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Readiness scan.
 */
final class AN10_Scanner {

	const META  = '_an10_scan';
	const FLAGS = '_an10_flags';
	const STATE = 'an10_scan_state';
	const BATCH = 50;

	const FLAG_LABELS = array(
		'no_schema'    => 'No structured data on the page',
		'skip'         => 'A heading level is skipped',
		'h1'           => 'An h1 inside the content (the title is usually the h1 already)',
		'noindex_menu' => 'Set to noindex but linked from a menu',
		'canonical'    => 'Canonical URL points to another page',
		'thin'         => 'Thin content',
	);

	/**
	 * Post types that have public pages.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		$types = get_post_types( array( 'public' => true ) );
		unset( $types['attachment'] );
		return array_values( $types );
	}

	/**
	 * Begin a full scan: a few seconds now, the rest in the background.
	 */
	public static function start_full_scan(): void {
		update_option(
			self::STATE,
			array(
				'started_at'  => time(),
				'offset'      => 0,
				'total'       => self::published_count(),
				'finished_at' => 0,
			),
			false
		);
		self::run_batch( 3.0 );
	}

	/**
	 * Continue the scan within a time budget, scheduling the next part.
	 *
	 * @param float $budget Seconds to spend.
	 */
	public static function run_batch( $budget = 20.0 ): void {
		$state = (array) get_option( self::STATE, array() );
		if ( empty( $state ) || ! empty( $state['finished_at'] ) ) {
			return;
		}
		$start = microtime( true );
		do {
			// get_posts() leaves other plugins' query filters off by default, so
			// a language or membership plugin cannot hide pages from the scan.
			$ids = get_posts(
				array(
					'post_type'      => self::post_types(),
					'post_status'    => 'publish',
					'fields'         => 'ids',
					'posts_per_page' => self::BATCH,
					'offset'         => (int) $state['offset'],
					'orderby'        => 'ID',
					'order'          => 'ASC',
					'no_found_rows'  => true,
				)
			);
			foreach ( $ids as $id ) {
				self::scan_post( (int) $id );
			}
			$state['offset'] = (int) $state['offset'] + count( $ids );
			if ( count( $ids ) < self::BATCH ) {
				$state['finished_at'] = time();
				break;
			}
		} while ( microtime( true ) - $start < (float) $budget );

		update_option( self::STATE, $state, false );
		if ( empty( $state['finished_at'] ) && ! wp_next_scheduled( 'an10_scan_batch' ) ) {
			wp_schedule_single_event( time() + 5, 'an10_scan_batch' );
		}
	}

	/**
	 * Rescan a post when it is saved.
	 *
	 * @param int     $post_id Post.
	 * @param WP_Post $post    Post object.
	 */
	public static function on_save( $post_id, $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! $post instanceof WP_Post ) {
			return;
		}
		if ( 'publish' !== $post->post_status || ! in_array( $post->post_type, self::post_types(), true ) ) {
			delete_post_meta( $post_id, self::META );
			delete_post_meta( $post_id, self::FLAGS );
			return;
		}
		self::scan_post( (int) $post_id );
	}

	/**
	 * Scan one post and store its facts and findings.
	 *
	 * @param int $post_id Post.
	 */
	public static function scan_post( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			return;
		}
		$raw   = (string) $post->post_content;
		$facts = AN10_Content::analyze( self::render( $post ), $raw );

		$noindex   = AN10_Site::noindex( $post );
		$canonical = AN10_Site::canonical_elsewhere( $post );
		$in_menu   = in_array( $post_id, AN10_Site::menu_post_ids(), true );
		$owner     = AN10_Site::schema_owner( $post->post_type, true );
		$own       = (bool) get_post_meta( $post_id, AN10_Changes::SCHEMA_META, true );
		$thin      = (int) AN10_Registry::thresholds()['thin_content_words'];

		$flags = array();
		if ( ! $facts['jsonld_types'] && null === $owner && ! $own ) {
			$flags[] = 'no_schema';
		}
		if ( $facts['skips'] ) {
			$flags[] = 'skip';
		}
		if ( $facts['h1_count'] > 0 ) {
			$flags[] = 'h1';
		}
		if ( $noindex && $in_menu ) {
			$flags[] = 'noindex_menu';
		}
		if ( null !== $canonical ) {
			$flags[] = 'canonical';
		}
		if ( $facts['word_count'] < $thin ) {
			$flags[] = 'thin';
		}

		$facts['noindex']      = $noindex;
		$facts['canonical']    = $canonical;
		$facts['in_menu']      = $in_menu;
		$facts['schema_owner'] = $owner;
		$facts['scanned_at']   = time();

		update_post_meta( $post_id, self::META, wp_slash( $facts ) );
		update_post_meta( $post_id, self::FLAGS, ',' . implode( ',', $flags ) . ',' );
	}

	/**
	 * Rendered content, as close to the page as possible without a request.
	 *
	 * @param WP_Post $post Post.
	 */
	private static function render( WP_Post $post ): string {
		$previous        = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		setup_postdata( $post );
		ob_start();
		$html = do_shortcode( do_blocks( (string) $post->post_content ) );
		ob_end_clean();
		$GLOBALS['post'] = $previous; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		if ( $previous instanceof WP_Post ) {
			setup_postdata( $previous );
		}
		return (string) $html;
	}

	/**
	 * Published posts of public types.
	 */
	public static function published_count(): int {
		$total = 0;
		foreach ( self::post_types() as $type ) {
			$total += (int) ( wp_count_posts( $type )->publish ?? 0 );
		}
		return $total;
	}

	/**
	 * How many published posts carry each finding.
	 *
	 * @return array<string, int>
	 */
	public static function counts(): array {
		global $wpdb;
		$out = array();
		foreach ( array_keys( self::FLAG_LABELS ) as $flag ) {
			$out[ $flag ] = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					WHERE pm.meta_key = %s AND pm.meta_value LIKE %s AND p.post_status = 'publish'",
					self::FLAGS,
					'%,' . $wpdb->esc_like( $flag ) . ',%'
				)
			);
		}
		return $out;
	}

	/**
	 * Published posts with a finding.
	 *
	 * @param string $flag  Finding key.
	 * @param int    $limit Maximum.
	 * @return int[]
	 */
	public static function posts_with( string $flag, int $limit = 200 ): array {
		global $wpdb;
		return array_map(
			'intval',
			(array) $wpdb->get_col( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					WHERE pm.meta_key = %s AND pm.meta_value LIKE %s AND p.post_status = 'publish' ORDER BY p.ID DESC LIMIT %d",
					self::FLAGS,
					'%,' . $wpdb->esc_like( $flag ) . ',%',
					$limit
				)
			)
		);
	}

	/**
	 * Published, scanned posts in display order.
	 *
	 * @param int $limit Maximum.
	 * @return int[]
	 */
	public static function scanned_posts( int $limit = 500 ): array {
		global $wpdb;
		return array_map(
			'intval',
			(array) $wpdb->get_col( // phpcs:ignore WordPress.DB
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->postmeta} pm JOIN {$wpdb->posts} p ON p.ID = pm.post_id
					WHERE pm.meta_key = %s AND p.post_status = 'publish' ORDER BY p.post_type = 'page' DESC, p.menu_order ASC, p.post_date DESC LIMIT %d",
					self::META,
					$limit
				)
			)
		);
	}

	/**
	 * Stored facts for one post.
	 *
	 * @param int $post_id Post.
	 * @return array|null
	 */
	public static function facts( int $post_id ): ?array {
		$facts = get_post_meta( $post_id, self::META, true );
		return is_array( $facts ) ? $facts : null;
	}
}
