<?php
/**
 * Visitors sent by AI answers.
 *
 * Counted in the browser, not on the server. Most WordPress sites serve pages
 * from a cache, and a cached page never reaches PHP, so a server-side count of
 * referrals would read close to zero on exactly the sites that are busiest.
 * A small script runs on every page view, cached or not, and only when the
 * visitor arrived from an AI engine (by referrer or utm_source) does it tell
 * this site's own REST API which engine and which page. A reload or a step
 * back or forward is not a new arrival, though the browser keeps the same
 * referrer and address for it, so those are not counted. Nothing leaves the
 * site, no cookie is set, and the row stores the engine name only: no IP
 * address, no referrer URL, no query string.
 *
 * The endpoint is public because a cached page cannot carry a fresh nonce.
 * That means anyone could post a fake referral. Only known AI engines are
 * accepted, and a per-minute and a per-day cap bound how far fakes can skew
 * the numbers and how many rows they can add to the database.
 *
 * @package AiCrawlerAnalytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * AI referral beacon.
 */
final class AN10_Beacon {

	const ROUTE_NS = 'an10/v1';

	const PER_MINUTE = 120;

	const PER_DAY = 2000;

	/**
	 * Register the endpoint.
	 */
	public static function register_route(): void {
		register_rest_route(
			self::ROUTE_NS,
			'/visit',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'receive' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'ref'  => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'utm'  => array( 'sanitize_callback' => 'sanitize_text_field' ),
					'id'   => array( 'sanitize_callback' => 'absint' ),
					'path' => array( 'sanitize_callback' => array( self::class, 'clean_path' ) ),
				),
			)
		);
	}

	/**
	 * The page path the browser reported, cleaned as a URL so percent-encoded
	 * characters (any non-English address) survive.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function clean_path( $value ): string {
		return (string) wp_parse_url( esc_url_raw( (string) $value ), PHP_URL_PATH );
	}

	/**
	 * Store one referral.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function receive( WP_REST_Request $request ): WP_REST_Response {
		$hosts  = AN10_Registry::ai_source_hosts();
		$engine = AN10_Agents::engine_for( (string) $request['ref'], $hosts );
		if ( null === $engine ) {
			$engine = AN10_Agents::engine_for( (string) $request['utm'], $hosts );
		}
		if ( null === $engine ) {
			return new WP_REST_Response( null, 204 );
		}

		$minute = 'an10_beacon_' . gmdate( 'YmdHi' );
		$day    = 'an10_beacon_day_' . gmdate( 'Ymd' );
		$count  = (int) get_transient( $minute );
		$today  = (int) get_transient( $day );
		if ( $count >= self::PER_MINUTE || $today >= self::PER_DAY ) {
			return new WP_REST_Response( null, 204 );
		}
		set_transient( $minute, $count + 1, 2 * MINUTE_IN_SECONDS );
		set_transient( $day, $today + 1, DAY_IN_SECONDS + HOUR_IN_SECONDS );

		$post_id = (int) $request['id'];
		if ( $post_id && 'publish' !== get_post_status( $post_id ) ) {
			$post_id = 0;
		}
		$path = (string) wp_parse_url( (string) $request['path'], PHP_URL_PATH );
		if ( '' === $path || '/' !== $path[0] ) {
			$path = '/';
		}

		AN10_Store::insert( AN10_Store::KIND_REFERRAL, $engine, AN10_Store::NOT_APPLICABLE, $post_id, $path, 200 );
		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Print the script on front-end pages.
	 */
	public static function enqueue(): void {
		wp_register_script( 'an10-visit', '', array(), AN10_VERSION, array( 'in_footer' => true ) );
		wp_enqueue_script( 'an10-visit' );
		$config = array(
			'u'  => esc_url_raw( rest_url( self::ROUTE_NS . '/visit' ) ),
			'h'  => array_keys( AN10_Registry::ai_source_hosts() ),
			'id' => is_singular() || is_front_page() ? (int) get_queried_object_id() : 0,
		);
		wp_add_inline_script( 'an10-visit', 'window.an10v=' . wp_json_encode( $config ) . ';' . self::script() );
	}

	/**
	 * The browser side: send only when the visit came from an AI engine.
	 */
	private static function script(): string {
		return '(function(){try{var c=window.an10v;'
			. 'function b(v){return(v||"").toLowerCase().replace(/^https?:\/\//,"").replace(/^www\./,"").replace(/[\/?#].*$/,"");}'
			. 'function m(v){v=b(v);if(!v)return false;for(var i=0;i<c.h.length;i++){var h=c.h[i];if(v===h||v.slice(-h.length-1)==="."+h)return true;}return false;}'
			. 'var r=document.referrer?new URL(document.referrer).hostname:"";'
			. 'var s=new URLSearchParams(location.search).get("utm_source")||"";'
			. 'if(r===location.hostname)r="";if(!m(r)&&!m(s))return;'
			. 'var n=performance.getEntriesByType?performance.getEntriesByType("navigation")[0]:null;'
			. 'var t=n?n.type:(performance.navigation?["navigate","reload","back_forward"][performance.navigation.type]:"navigate");'
			. 'if(t==="reload"||t==="back_forward")return;'
			. 'var d=new FormData();d.append("ref",r);d.append("utm",s);d.append("id",c.id);d.append("path",location.pathname);'
			. 'if(navigator.sendBeacon){navigator.sendBeacon(c.u,d);}else{fetch(c.u,{method:"POST",body:d,keepalive:true,credentials:"omit"});}'
			. '}catch(e){}})();';
	}
}
