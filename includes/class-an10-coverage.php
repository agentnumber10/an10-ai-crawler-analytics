<?php
/**
 * How much of the traffic the crawler log can see.
 *
 * When a page cache serves a request, WordPress never runs, so no plugin can
 * record that crawler visit. There is no way to count what was missed from
 * inside WordPress, so this class does not estimate a percentage: it names
 * the caches it can detect and says plainly that the counts are a floor.
 * A server or CDN cache WordPress cannot see is possible even when nothing is
 * detected, and the statement says that too.
 *
 * @package AiCrawlerAnalytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Page cache detection.
 */
final class AN10_Coverage {

	const PLUGINS = array(
		'wp-rocket/wp-rocket.php'                    => 'WP Rocket',
		'w3-total-cache/w3-total-cache.php'          => 'W3 Total Cache',
		'wp-super-cache/wp-cache.php'                => 'WP Super Cache',
		'litespeed-cache/litespeed-cache.php'        => 'LiteSpeed Cache',
		'wp-fastest-cache/wpFastestCache.php'        => 'WP Fastest Cache',
		'cache-enabler/cache-enabler.php'            => 'Cache Enabler',
		'sg-cachepress/sg-cachepress.php'            => 'SiteGround Speed Optimizer',
		'breeze/breeze.php'                          => 'Breeze',
		'hummingbird-performance/wp-hummingbird.php' => 'Hummingbird',
		'nitropack/main.php'                         => 'NitroPack',
		'wp-optimize/wp-optimize.php'                => 'WP-Optimize',
		'comet-cache/comet-cache.php'                => 'Comet Cache',
		'cloudflare/cloudflare.php'                  => 'Cloudflare',
		'swift-performance-lite/performance.php'     => 'Swift Performance',
		'powered-cache/powered-cache.php'            => 'Powered Cache',
	);

	/**
	 * Detected page caches.
	 *
	 * @return string[] Names, empty when none is detected.
	 */
	public static function detect(): array {
		$found  = array();
		$active = (array) get_option( 'active_plugins', array() );
		foreach ( self::PLUGINS as $file => $name ) {
			if ( in_array( $file, $active, true ) ) {
				$found[] = $name;
			}
		}
		$hosts = array(
			'WP Engine'     => defined( 'WPE_APIKEY' ) || class_exists( 'WpeCommon' ),
			'Kinsta'        => defined( 'KINSTAMU_VERSION' ),
			'Pantheon'      => ! empty( $_ENV['PANTHEON_ENVIRONMENT'] ) || false !== getenv( 'PANTHEON_ENVIRONMENT' ),
			'Pressable'     => defined( 'IS_PRESSABLE' ),
			'WordPress.com' => defined( 'WPCOMSH_VERSION' ) || defined( 'ATOMIC_SITE_ID' ),
			'GoDaddy'       => defined( 'GD_SYSTEM_PLUGIN_DIR' ),
			'Flywheel'      => defined( 'FLYWHEEL_CONFIG_DIR' ),
		);
		foreach ( $hosts as $name => $present ) {
			if ( $present ) {
				$found[] = $name;
			}
		}
		if ( ! $found && defined( 'WP_CACHE' ) && WP_CACHE && file_exists( WP_CONTENT_DIR . '/advanced-cache.php' ) ) {
			$found[] = __( 'a page cache drop-in (advanced-cache.php)', 'an10-ai-crawler-analytics' );
		}
		return $found;
	}

	/**
	 * The sentence shown next to every crawler count.
	 */
	public static function statement(): string {
		$caches = self::detect();
		if ( $caches ) {
			return sprintf(
				/* translators: %s: names of detected page caches. */
				__( 'Page caching detected (%s). Crawler visits served from the cache never reach WordPress, so these counts are a floor, not a total.', 'an10-ai-crawler-analytics' ),
				implode( ', ', $caches )
			);
		}
		return __( 'No page cache detected. A cache at your host or CDN that WordPress cannot see would still hide some visits, so treat these counts as a floor.', 'an10-ai-crawler-analytics' );
	}
}
