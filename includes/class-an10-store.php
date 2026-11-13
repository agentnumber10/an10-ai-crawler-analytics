<?php
/**
 * The one table: crawler visits and AI referrals.
 *
 * Each row is an event with no personal data: when, which crawler or engine,
 * whether the crawler's identity was verified, which post and path (no query
 * string), and the response status. No IP address, no full user agent, no
 * cookie, no identifier of a person is ever written.
 *
 * @package AiCrawlerAnalytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Event storage and queries.
 */
final class AN10_Store {

	const KIND_CRAWLER  = 1;
	const KIND_REFERRAL = 2;

	const NOT_APPLICABLE = 0;
	const VERIFIED       = 1;
	const SPOOFED        = 2;
	const UNVERIFIED     = 3;

	const DB_VERSION = '1';

	/**
	 * Table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'an10_events';
	}

	/**
	 * Create or upgrade the table.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				kind tinyint(3) unsigned NOT NULL,
				agent varchar(40) NOT NULL DEFAULT '',
				verification tinyint(3) unsigned NOT NULL DEFAULT 0,
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				path varchar(191) NOT NULL DEFAULT '',
				status smallint(5) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY kind_time (kind,created_at),
				KEY post_kind (post_id,kind)
			) {$charset};"
		);
		update_option( 'an10_db_version', self::DB_VERSION, false );
	}

	/**
	 * Record one event.
	 *
	 * @param int    $kind         KIND_* constant.
	 * @param string $agent        Crawler token or engine name.
	 * @param int    $verification Verification state.
	 * @param int    $post_id      Post the request resolved to, or 0.
	 * @param string $path         URL path, no query string.
	 * @param int    $status       HTTP status.
	 */
	public static function insert( int $kind, string $agent, int $verification, int $post_id, string $path, int $status ): void {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table(),
			array(
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
				'kind'         => $kind,
				'agent'        => substr( $agent, 0, 40 ),
				'verification' => $verification,
				'post_id'      => max( 0, $post_id ),
				'path'         => substr( $path, 0, 191 ),
				'status'       => max( 0, min( 999, $status ) ),
			),
			array( '%s', '%d', '%s', '%d', '%d', '%s', '%d' )
		);
	}

	/**
	 * Delete events older than the retention window.
	 *
	 * @param int $days Days to keep.
	 * @return int Rows deleted.
	 */
	public static function prune( int $days ): int {
		global $wpdb;
		$days = max( 1, $days );
		return (int) $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', self::table(), self::utc( time() - $days * DAY_IN_SECONDS ) )
		);
	}

	/**
	 * Drop the table (uninstall, only when the user opted in).
	 */
	public static function drop(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}

	/**
	 * Crawler visits since a time, per crawler and verification state.
	 *
	 * @param int $since Unix time.
	 * @return array<string, array{verified: int, spoofed: int, unverified: int, pages: int, last_seen: string|null}>
	 */
	public static function crawler_summary( int $since ): array {
		global $wpdb;
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT agent, verification, COUNT(*) AS n, COUNT(DISTINCT NULLIF(post_id, 0)) AS pages, MAX(created_at) AS last_seen
				FROM %i WHERE kind = %d AND created_at >= %s GROUP BY agent, verification',
				self::table(),
				self::KIND_CRAWLER,
				self::utc( $since )
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$agent = (string) $r['agent'];
			if ( ! isset( $out[ $agent ] ) ) {
				$out[ $agent ] = array(
					'verified'   => 0,
					'spoofed'    => 0,
					'unverified' => 0,
					'pages'      => 0,
					'last_seen'  => null,
				);
			}
			$state = (int) $r['verification'];
			if ( self::VERIFIED === $state ) {
				$out[ $agent ]['verified']  = (int) $r['n'];
				$out[ $agent ]['pages']     = (int) $r['pages'];
				$out[ $agent ]['last_seen'] = (string) $r['last_seen'];
			} elseif ( self::SPOOFED === $state ) {
				$out[ $agent ]['spoofed'] = (int) $r['n'];
			} else {
				$out[ $agent ]['unverified'] = (int) $r['n'];
			}
		}
		return $out;
	}

	/**
	 * Posts fetched by any verified crawler since a time.
	 *
	 * @param int      $since   Unix time.
	 * @param string[] $exclude Crawler tokens to leave out.
	 * @return int[]
	 */
	public static function verified_post_ids( int $since, array $exclude = array() ): array {
		global $wpdb;
		$exclude = self::exclusion( $exclude );
		return array_map(
			'intval',
			(array) $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'SELECT DISTINCT post_id FROM %i WHERE kind = %d AND verification = %d AND post_id > 0 AND created_at >= %s AND agent NOT IN (' . implode( ',', array_fill( 0, count( $exclude ), '%s' ) ) . ')',
					array_merge( array( self::table(), self::KIND_CRAWLER, self::VERIFIED, self::utc( $since ) ), $exclude )
				)
			)
		);
	}

	/**
	 * Verified crawler tokens that fetched any of these posts in a window.
	 *
	 * @param int[] $post_ids Posts.
	 * @param int   $from     Unix time, inclusive.
	 * @return array<string, int> Token to distinct posts fetched.
	 */
	public static function verified_tokens_for( array $post_ids, int $from ): array {
		global $wpdb;
		$post_ids = array_map( 'absint', $post_ids );
		if ( ! $post_ids ) {
			return array();
		}
		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT agent, COUNT(DISTINCT post_id) AS pages FROM %i WHERE kind = %d AND verification = %d AND created_at >= %s AND post_id IN (' . implode( ',', array_fill( 0, count( $post_ids ), '%d' ) ) . ') GROUP BY agent ORDER BY pages DESC',
				array_merge( array( self::table(), self::KIND_CRAWLER, self::VERIFIED, self::utc( $from ) ), $post_ids )
			),
			ARRAY_A
		);
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[ (string) $r['agent'] ] = (int) $r['pages'];
		}
		return $out;
	}

	/**
	 * AI referrals per engine in a window, optionally for some posts only.
	 *
	 * @param int        $from     Unix time, inclusive.
	 * @param int        $to       Unix time, exclusive.
	 * @param int[]|null $post_ids Restrict to these posts.
	 * @return array<string, int>
	 */
	public static function referrals( int $from, int $to, ?array $post_ids = null ): array {
		global $wpdb;
		if ( null === $post_ids ) {
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'SELECT agent, COUNT(*) AS n FROM %i WHERE kind = %d AND created_at >= %s AND created_at < %s GROUP BY agent ORDER BY n DESC',
					self::table(),
					self::KIND_REFERRAL,
					self::utc( $from ),
					self::utc( $to )
				),
				ARRAY_A
			);
		} else {
			$post_ids = array_map( 'absint', $post_ids );
			if ( ! $post_ids ) {
				return array();
			}
			$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					'SELECT agent, COUNT(*) AS n FROM %i WHERE kind = %d AND created_at >= %s AND created_at < %s AND post_id IN (' . implode( ',', array_fill( 0, count( $post_ids ), '%d' ) ) . ') GROUP BY agent ORDER BY n DESC',
					array_merge( array( self::table(), self::KIND_REFERRAL, self::utc( $from ), self::utc( $to ) ), $post_ids )
				),
				ARRAY_A
			);
		}
		$out = array();
		foreach ( (array) $rows as $r ) {
			$out[ (string) $r['agent'] ] = (int) $r['n'];
		}
		return $out;
	}

	/**
	 * Verified crawler fetches per day, in the site's time zone, for the last
	 * $days days including today. Days with none are present with 0, oldest
	 * first, so the result can be drawn as it is.
	 *
	 * The day boundary uses the site's current UTC offset; a daylight saving
	 * change inside the window moves at most an hour of visits by a day.
	 *
	 * @param int      $days    Days, including today.
	 * @param string[] $exclude Crawler tokens to leave out.
	 * @return array<string, int> Y-m-d to fetches.
	 */
	public static function daily_verified( int $days, array $exclude = array() ): array {
		global $wpdb;
		$tz      = wp_timezone();
		$today   = new DateTimeImmutable( 'today', $tz );
		$first   = $today->modify( '-' . ( $days - 1 ) . ' days' );
		$offset  = (int) round( $tz->getOffset( $today ) / 60 );
		$exclude = self::exclusion( $exclude );
		$rows    = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				'SELECT DATE(DATE_ADD(created_at, INTERVAL %d MINUTE)) AS d, COUNT(*) AS n FROM %i WHERE kind = %d AND verification = %d AND created_at >= %s AND agent NOT IN (' . implode( ',', array_fill( 0, count( $exclude ), '%s' ) ) . ') GROUP BY d',
				array_merge( array( $offset, self::table(), self::KIND_CRAWLER, self::VERIFIED, self::utc( $first->getTimestamp() ) ), $exclude )
			),
			ARRAY_A
		);
		$out     = array();
		for ( $day = $first; $day <= $today; $day = $day->modify( '+1 day' ) ) {
			$out[ $day->format( 'Y-m-d' ) ] = 0;
		}
		foreach ( (array) $rows as $r ) {
			if ( isset( $out[ (string) $r['d'] ] ) ) {
				$out[ (string) $r['d'] ] = (int) $r['n'];
			}
		}
		return $out;
	}

	/**
	 * Has any verified crawler visit ever been recorded?
	 */
	public static function has_verified(): bool {
		global $wpdb;
		return (bool) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT 1 FROM %i WHERE kind = %d AND verification = %d LIMIT 1', self::table(), self::KIND_CRAWLER, self::VERIFIED )
		);
	}

	/**
	 * Crawler tokens for a NOT IN list, never empty: an empty name stands in
	 * when nothing is excluded, and no crawler row has an empty name.
	 *
	 * @param string[] $tokens Tokens to leave out.
	 * @return string[]
	 */
	private static function exclusion( array $tokens ): array {
		return $tokens ? array_values( array_map( 'strval', $tokens ) ) : array( '' );
	}

	/**
	 * Unix time as a UTC datetime string.
	 *
	 * @param int $ts Unix time.
	 */
	public static function utc( int $ts ): string {
		return gmdate( 'Y-m-d H:i:s', $ts );
	}
}
