<?php
/**
 * Bootstrap: autoloading, hooks, activation, scheduled work.
 *
 * Standalone mode is the only mode in this version. Nothing here talks to
 * agentnumber10.com; the only outbound requests are the crawler IP range
 * lists, fetched once a day by a scheduled event, never on a page request.
 * The free checker button is a plain link the site owner may click. Any new
 * outbound request must be added to readme.txt's External services first.
 *
 * @package AiCrawlerAnalytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap.
 */
final class AN10_Plugin {

	const SETTINGS = 'an10_settings';

	/**
	 * Default settings. Everything optional starts off.
	 */
	const DEFAULTS = array(
		'retention_days'      => 90,
		'weekly_email'        => false,
		'proxy_header'        => '',
		'delete_on_uninstall' => false,
	);

	/**
	 * Wire the plugin into WordPress.
	 */
	public static function boot(): void {
		spl_autoload_register( array( self::class, 'autoload' ) );

		add_action( 'init', array( 'AN10_Changes', 'register_outputs' ) );
		add_action( 'plugins_loaded', array( 'AN10_Observer', 'observe' ), 0 );
		add_action( 'template_redirect', array( 'AN10_Changes', 'serve_llms_txt' ), 1 );
		add_action( 'rest_api_init', array( 'AN10_Beacon', 'register_route' ) );
		add_action( 'wp_enqueue_scripts', array( 'AN10_Beacon', 'enqueue' ) );
		add_action( 'save_post', array( 'AN10_Scanner', 'on_save' ), 20, 3 );

		add_action( 'an10_refresh_lists', array( 'AN10_Verifier', 'refresh' ) );
		add_action( 'an10_scan_batch', array( 'AN10_Scanner', 'run_batch' ) );
		add_action( 'an10_daily', array( self::class, 'daily' ) );
		add_action( 'an10_weekly', array( 'AN10_Email', 'send_weekly' ) );

		if ( is_admin() ) {
			AN10_Admin::boot();
		}
	}

	/**
	 * Load AN10_* classes from includes/.
	 *
	 * @param string $class_name Class being loaded.
	 */
	public static function autoload( string $class_name ): void {
		if ( ! str_starts_with( $class_name, 'AN10_' ) ) {
			return;
		}
		$file = 'class-' . strtolower( str_replace( '_', '-', $class_name ) ) . '.php';
		foreach ( array( 'includes/', 'includes/admin/', 'includes/generated/' ) as $dir ) {
			if ( is_readable( AN10_DIR . $dir . $file ) ) {
				require_once AN10_DIR . $dir . $file;
				return;
			}
		}
	}

	/**
	 * Activation: create the event table, schedule work, scan the site.
	 *
	 * @param bool $network_wide Network activation.
	 */
	public static function activate( $network_wide = false ): void {
		spl_autoload_register( array( self::class, 'autoload' ) );
		AN10_Store::install();
		add_option( self::SETTINGS, self::DEFAULTS );
		add_option( 'an10_installed_at', time() );

		if ( ! wp_next_scheduled( 'an10_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'an10_daily' );
		}
		if ( ! wp_next_scheduled( 'an10_weekly' ) ) {
			wp_schedule_event( time() + WEEK_IN_SECONDS, 'weekly', 'an10_weekly' );
		}
		// The IP lists are fetched in the background so activation never waits on
		// another site. Until they arrive every crawler visit is labelled Unverified.
		wp_schedule_single_event( time(), 'an10_refresh_lists' );

		AN10_Scanner::start_full_scan();

		if ( ! $network_wide ) {
			set_transient( 'an10_activation_redirect', 1, 60 );
		}
	}

	/**
	 * Deactivation: stop scheduled work. Data stays until uninstall.
	 */
	public static function deactivate(): void {
		foreach ( array( 'an10_daily', 'an10_weekly', 'an10_refresh_lists', 'an10_scan_batch' ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Daily housekeeping: refresh IP lists, prune old events, rescan weekly.
	 */
	public static function daily(): void {
		AN10_Verifier::refresh();
		AN10_Store::prune( (int) self::setting( 'retention_days' ) );
		$state = get_option( 'an10_scan_state', array() );
		if ( empty( $state['finished_at'] ) || time() - (int) $state['finished_at'] > WEEK_IN_SECONDS ) {
			AN10_Scanner::start_full_scan();
		}
	}

	/**
	 * One setting, with its default.
	 *
	 * @param string $key Setting name.
	 * @return mixed
	 */
	public static function setting( string $key ) {
		$settings = wp_parse_args( (array) get_option( self::SETTINGS, array() ), self::DEFAULTS );
		return $settings[ $key ] ?? null;
	}
}
