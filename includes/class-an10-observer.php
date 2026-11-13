<?php
/**
 * Records requests from AI crawlers.
 *
 * Runs as soon as all plugins have loaded. For every request it does one
 * case-insensitive comparison of the user agent against the registry's
 * tokens and returns at once when nothing matches, which is every human
 * visit. A match queues one row, written on shutdown so the final HTTP status
 * is known, even when another plugin ends the request early. No other work
 * happens on the request.
 *
 * Requests WordPress never sees (a page served from a cache, an image, a
 * static file on disk) are not recorded. AN10_Coverage says so next to every
 * count.
 *
 * @package AiCrawlerAnalytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Crawler request observer.
 */
final class AN10_Observer {

	/**
	 * The event waiting for shutdown, if any.
	 *
	 * @var array|null
	 */
	private static $pending = null;

	/**
	 * Hooked to plugins_loaded, as early as every plugin is available.
	 *
	 * Not template_redirect: other plugins answer some requests themselves
	 * before WordPress gets that far (a plugin serving robots.txt, a cache
	 * plugin serving a page, a redirect plugin) and those crawler visits would
	 * be lost. The shutdown hook runs even when a plugin ends the request
	 * early, so the row is still written.
	 */
	public static function observe(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$crawler    = AN10_Agents::crawler_for( $user_agent, AN10_Registry::crawlers() );
		if ( null === $crawler ) {
			return;
		}

		$client        = AN10_Verifier::client();
		self::$pending = array(
			'agent'        => $crawler['token'],
			'verification' => AN10_Verifier::verify( $crawler, $client['ip'], $client['proxied'] ),
			'path'         => self::path(),
		);
		add_action( 'shutdown', array( self::class, 'record' ) );
	}

	/**
	 * Hooked to shutdown: write the queued event.
	 */
	public static function record(): void {
		if ( null === self::$pending ) {
			return;
		}
		$e             = self::$pending;
		self::$pending = null;
		$status        = http_response_code();
		AN10_Store::insert( AN10_Store::KIND_CRAWLER, $e['agent'], $e['verification'], self::post_id(), $e['path'], is_int( $status ) ? $status : 200 );
		if ( AN10_Store::VERIFIED === $e['verification'] && ! get_option( 'an10_first_verified_at' ) ) {
			update_option( 'an10_first_verified_at', time(), false );
		}
	}

	/**
	 * The post this request resolved to, or 0 when the request ended before
	 * WordPress resolved one (robots.txt answered by a plugin, for example).
	 */
	private static function post_id(): int {
		global $wp_query;
		if ( ! $wp_query instanceof WP_Query || ! did_action( 'wp' ) ) {
			return 0;
		}
		if ( is_singular() || is_front_page() || is_home() ) {
			return (int) get_queried_object_id();
		}
		return 0;
	}

	/**
	 * The request path, without the query string, which can carry personal data.
	 * Cleaned as a URL, not as text: text cleaning deletes percent-encoded
	 * characters, which would turn /caf%C3%A9/ into /caf/ and a page with a
	 * Hindi or Arabic address into the homepage.
	 */
	private static function path(): string {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		return '' === $path ? '/' : $path;
	}
}
