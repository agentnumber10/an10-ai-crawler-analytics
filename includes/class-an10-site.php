<?php
/**
 * Site-wide facts, and coexistence with the SEO plugin already installed.
 *
 * Yoast SEO, Rank Math, SEOPress and All in One SEO own titles, meta
 * descriptions, canonicals, Open Graph and sitemaps. This plugin never writes
 * any of those. It reads their noindex and canonical settings so its findings
 * agree with what the site actually outputs, and it adds structured data only
 * where no installed plugin already provides it.
 *
 * @package AiCrawlerAnalytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Site facts and SEO plugin coexistence.
 */
final class AN10_Site {

	/**
	 * SEO plugins this plugin knows by name: main file, name, whether it
	 * prints page-level structured data.
	 */
	const KNOWN_SEO = array(
		'wordpress-seo/wp-seo.php'                        => array( 'Yoast SEO', true ),
		'seo-by-rank-math/rank-math.php'                  => array( 'Rank Math', true ),
		'all-in-one-seo-pack/all_in_one_seo_pack.php'     => array( 'All in One SEO', true ),
		'all-in-one-seo-pack-pro/all_in_one_seo_pack.php' => array( 'All in One SEO', true ),
		// SEOPress prints page-level schema only with its Pro add-on.
		'wp-seopress/seopress.php'                        => array( 'SEOPress', false ),
		'wp-seopress-pro/seopress-pro.php'                => array( 'SEOPress Pro', true ),
		'squirrly-seo/squirrly.php'                       => array( 'Squirrly SEO', true ),
		'native-aeo-pack/native-aeo-pack.php'             => array( 'Visibility', true ),
		'autodescription/autodescription.php'             => array( 'The SEO Framework', true ),
		'slim-seo/slim-seo.php'                           => array( 'Slim SEO', true ),
		'smartcrawl-seo/wpmu-dev-seo.php'                 => array( 'SmartCrawl', true ),
	);

	/**
	 * Active SEO plugins.
	 *
	 * @return array<int, array{name: string, schema: bool}>
	 */
	public static function seo_plugins(): array {
		$out    = array();
		$active = (array) get_option( 'active_plugins', array() );
		foreach ( self::KNOWN_SEO as $file => $info ) {
			if ( in_array( $file, $active, true ) ) {
				$out[ $info[0] ] = array(
					'name'   => $info[0],
					'schema' => $info[1],
				);
			}
		}
		// Installs that load an SEO plugin some other way (must-use, network).
		$constants = array(
			'WPSEO_VERSION'     => 'Yoast SEO',
			'RANK_MATH_VERSION' => 'Rank Math',
			'AIOSEO_VERSION'    => 'All in One SEO',
		);
		foreach ( $constants as $constant => $name ) {
			if ( defined( $constant ) && ! isset( $out[ $name ] ) ) {
				$out[ $name ] = array(
					'name'   => $name,
					'schema' => true,
				);
			}
		}
		return array_values( $out );
	}

	/**
	 * What already prints structured data for this post type, or null.
	 *
	 * Known plugins first. Then the evidence: one published page of this type
	 * is fetched from the site and its head is read, so a theme or a plugin
	 * this list does not know is caught too. The answer is kept for twelve
	 * hours. When the site cannot fetch its own pages, only the known list is
	 * used.
	 *
	 * @param string $post_type Post type.
	 * @param bool   $may_fetch Allowed to fetch a page now. Never true while
	 *                          rendering a public page, which would make the
	 *                          site request itself from inside a request.
	 */
	public static function schema_owner( string $post_type, bool $may_fetch = false ): ?string {
		foreach ( self::seo_plugins() as $p ) {
			if ( $p['schema'] ) {
				return $p['name'];
			}
		}
		if ( 'product' === $post_type && class_exists( 'WooCommerce' ) ) {
			return 'WooCommerce';
		}

		$key    = 'an10_head_schema_' . $post_type;
		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached['owner'];
		}
		if ( ! $may_fetch ) {
			return null;
		}
		$sample = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => AN10_Changes::SCHEMA_META,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);
		$owner  = null;
		if ( $sample ) {
			$head = self::head_schema_types( (int) $sample[0] );
			if ( $head ) {
				$owner = __( 'your theme or another plugin', 'an10-ai-crawler-analytics' );
			}
		}
		set_transient( $key, array( 'owner' => $owner ), 12 * HOUR_IN_SECONDS );
		return $owner;
	}

	/**
	 * Structured data types the live page prints outside its own content,
	 * or null when the page could not be fetched.
	 *
	 * Fetched from this site with the plugin's own user agent, which the
	 * crawler log never records.
	 *
	 * @param int $post_id Post.
	 * @return string[]|null
	 */
	public static function head_schema_types( int $post_id ): ?array {
		$url = get_permalink( $post_id );
		if ( ! $url ) {
			return null;
		}
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 8,
				'redirection' => 2,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
				'user-agent'  => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . AN10_SLUG . '/' . AN10_VERSION,
				'headers'     => array( 'Cache-Control' => 'no-cache' ),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return null;
		}
		$page    = AN10_Content::jsonld( (string) wp_remote_retrieve_body( $response ) )[0];
		$post    = get_post( $post_id );
		$content = $post ? AN10_Content::jsonld( (string) $post->post_content )[0] : array();
		$own     = get_post_meta( $post_id, AN10_Changes::SCHEMA_META, true );
		$ours    = is_array( $own ) ? AN10_Content::jsonld( '<script type="application/ld+json">' . wp_json_encode( $own ) . '</script>' )[0] : array();
		return array_values( array_diff( $page, $content, $ours ) );
	}

	/**
	 * Does this site sit behind Cloudflare? Seen from the admin's own request,
	 * or from Cloudflare's plugin being active.
	 */
	public static function behind_cloudflare(): bool {
		return ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) || in_array( 'cloudflare/cloudflare.php', (array) get_option( 'active_plugins', array() ), true );
	}

	/**
	 * robots.txt as this site serves it: the file on disk if there is one,
	 * otherwise what WordPress generates, including every plugin's filter.
	 *
	 * @return array{source: string, text: string}
	 */
	public static function robots_txt(): array {
		$file = self::home_path() . 'robots.txt';
		if ( is_readable( $file ) ) {
			return array(
				'source' => 'file',
				'text'   => (string) file_get_contents( $file ), // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			);
		}
		// The same output do_robots() builds, without sending headers.
		$public = (string) get_option( 'blog_public' );
		$path   = (string) wp_parse_url( site_url(), PHP_URL_PATH );
		$output = "User-agent: *\n";
		if ( '0' === $public ) {
			$output .= "Disallow: /\n";
		} else {
			$output .= "Disallow: {$path}/wp-admin/\n";
			$output .= "Allow: {$path}/wp-admin/admin-ajax.php\n";
		}
		return array(
			'source' => 'wordpress',
			'text'   => (string) apply_filters( 'robots_txt', $output, $public ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		);
	}

	/**
	 * Can each AI crawler read the homepage, per robots.txt?
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function crawler_access(): array {
		$robots = AN10_Robots::parse( self::robots_txt()['text'] );
		$path   = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$path   = '' === $path ? '/' : $path;
		$out    = array();
		foreach ( AN10_Registry::crawlers() as $c ) {
			$verdict = AN10_Robots::evaluate( $robots, $c['token'], $path );
			$out[]   = $c + array(
				'allowed' => $verdict['allowed'],
				'line'    => $verdict['rule']['line'] ?? null,
				'rule'    => $verdict['rule'],
				'via'     => $verdict['via'],
			);
		}
		return $out;
	}

	/**
	 * Who provides the sitemap, or null when nothing does.
	 */
	public static function sitemap(): ?string {
		foreach ( self::seo_plugins() as $p ) {
			return $p['name'];
		}
		if ( function_exists( 'wp_sitemaps_get_server' ) && wp_sitemaps_get_server()->sitemaps_enabled() ) {
			return __( 'WordPress', 'an10-ai-crawler-analytics' );
		}
		return null;
	}

	/**
	 * Where llms.txt comes from.
	 *
	 * 'file' on disk, 'plugin' (this one), 'other' (another plugin serves a
	 * valid one), 'other-invalid' (another plugin serves text that does not
	 * follow the format: the product counts an llms.txt only when it is plain
	 * text starting with a "# " title line), or 'none'.
	 *
	 * @param bool $may_fetch Allowed to request /llms.txt from the site now.
	 *                        Never true while rendering a public page.
	 */
	public static function llms_txt( bool $may_fetch = false ): string {
		if ( is_readable( self::home_path() . 'llms.txt' ) ) {
			return 'file';
		}
		if ( get_option( AN10_Changes::LLMS_OPTION ) ) {
			return 'plugin';
		}
		$cached = get_transient( 'an10_llms_other' );
		if ( is_string( $cached ) ) {
			return $cached;
		}
		if ( ! $may_fetch ) {
			return 'none';
		}
		$response = wp_remote_get(
			home_url( '/llms.txt' ),
			array(
				'timeout'     => 8,
				'redirection' => 0,
				'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter.
				'user-agent'  => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . AN10_SLUG . '/' . AN10_VERSION,
			)
		);
		$state    = 'none';
		if ( ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response ) ) {
			$type = (string) wp_remote_retrieve_header( $response, 'content-type' );
			$body = (string) wp_remote_retrieve_body( $response );
			if ( ! preg_match( '#text/html#i', $type ) && ! preg_match( '/^\s*</', $body ) ) {
				$state = preg_match( '/^\s*#\s/', $body ) ? 'other' : 'other-invalid';
			}
		}
		set_transient( 'an10_llms_other', $state, 12 * HOUR_IN_SECONDS );
		return $state;
	}

	/**
	 * Is this post set to noindex, by the site or by its SEO plugin?
	 *
	 * @param WP_Post $post Post.
	 */
	public static function noindex( WP_Post $post ): bool {
		if ( '0' === (string) get_option( 'blog_public' ) ) {
			return true;
		}
		$id    = $post->ID;
		$yoast = get_post_meta( $id, '_yoast_wpseo_meta-robots-noindex', true );
		if ( '1' === $yoast ) {
			return true;
		}
		if ( '' === $yoast && class_exists( 'WPSEO_Options' ) && WPSEO_Options::get( 'noindex-' . $post->post_type ) ) {
			return true;
		}
		$rank_math = get_post_meta( $id, 'rank_math_robots', true );
		if ( is_array( $rank_math ) && in_array( 'noindex', $rank_math, true ) ) {
			return true;
		}
		if ( 'yes' === get_post_meta( $id, '_seopress_robots_index', true ) ) {
			return true;
		}
		$aioseo = self::aioseo_row( $id );
		return $aioseo && empty( $aioseo['robots_default'] ) && ! empty( $aioseo['robots_noindex'] );
	}

	/**
	 * A canonical URL pointing somewhere other than the post itself, or null.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function canonical_elsewhere( WP_Post $post ): ?string {
		$id     = $post->ID;
		$aioseo = self::aioseo_row( $id );
		foreach (
			array(
				get_post_meta( $id, '_yoast_wpseo_canonical', true ),
				get_post_meta( $id, 'rank_math_canonical_url', true ),
				get_post_meta( $id, '_seopress_robots_canonical', true ),
				$aioseo['canonical_url'] ?? '',
			) as $url
		) {
			$url = is_string( $url ) ? trim( $url ) : '';
			if ( '' !== $url && untrailingslashit( $url ) !== untrailingslashit( (string) get_permalink( $post ) ) ) {
				return $url;
			}
		}
		return null;
	}

	/**
	 * IDs of posts linked from any navigation menu.
	 *
	 * @return int[]
	 */
	public static function menu_post_ids(): array {
		static $ids = null;
		if ( null !== $ids ) {
			return $ids;
		}
		$ids = array();
		foreach ( (array) wp_get_nav_menus() as $menu ) {
			foreach ( (array) wp_get_nav_menu_items( $menu ) as $item ) {
				if ( isset( $item->type ) && 'post_type' === $item->type ) {
					$ids[] = (int) $item->object_id;
				}
			}
		}
		$ids = array_values( array_unique( $ids ) );
		return $ids;
	}

	/**
	 * The directory the site's root URL maps to, with a trailing slash.
	 */
	public static function home_path(): string {
		if ( ! function_exists( 'get_home_path' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return trailingslashit( get_home_path() );
	}

	/**
	 * All in One SEO keeps its per-post settings in its own table.
	 *
	 * @param int $post_id Post.
	 * @return array|null
	 */
	private static function aioseo_row( int $post_id ): ?array {
		if ( ! defined( 'AIOSEO_VERSION' ) && ! function_exists( 'aioseo' ) ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare( 'SELECT robots_default, robots_noindex, canonical_url FROM %i WHERE post_id = %d', $wpdb->prefix . 'aioseo_posts', $post_id ),
			ARRAY_A
		);
		return is_array( $row ) ? $row : null;
	}
}
