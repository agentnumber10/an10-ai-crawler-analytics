<?php
/**
 * Was it really them? Checks a crawler's claimed identity against the IP
 * ranges its operator publishes.
 *
 * Three outcomes, and only the first counts as a crawler visit anywhere a
 * headline number is shown:
 *   - Verified:   the request came from an address in the operator's list.
 *   - Spoofed:    the operator publishes a list, our copy of it is current,
 *                 the address is certainly the client's own, and it is not in
 *                 the list.
 *   - Unverified: everything we cannot be sure of. No list exists for this
 *                 crawler, the list has not been downloaded, our copy is more
 *                 than a week old, or the request came through a proxy whose
 *                 real client address we cannot see. Unverified is not
 *                 Spoofed: we say "unknown" rather than accuse a real crawler.
 *
 * The proxy case matters most. Behind a proxy with a public address (a
 * firewall service, a CDN other than Cloudflare, a host's own front server)
 * every request arrives from the proxy's address, which is in no crawler's
 * list. Without the rule below, every real crawler on such a site would be
 * called a fake. A request that carries a forwarding header we were not told
 * to trust is therefore never called Spoofed. Someone faking a crawler can add
 * such a header too; that moves their visit from Spoofed to Unverified, and
 * neither is ever counted.
 *
 * The lists are fetched by a daily scheduled event. A page request only
 * compares an address against lists already stored, and the address is never
 * written anywhere.
 *
 * @package AiCrawlerAnalytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * Crawler identity verification.
 */
final class AN10_Verifier {

	const OPTION = 'an10_ip_lists';

	/**
	 * Cloudflare's own ranges, from its public API. Needed to trust the
	 * CF-Connecting-IP header, which is only honest when the request really
	 * arrived through Cloudflare.
	 */
	const CLOUDFLARE = 'https://api.cloudflare.com/client/v4/ips';

	/**
	 * After this long without a successful download, a list is too old to
	 * accuse anyone with: operators add ranges, so a real crawler on a new
	 * address would look like a fake.
	 */
	const STALE_AFTER = 7 * DAY_IN_SECONDS;

	/**
	 * Headers a site owner can name in Settings as the one their proxy sets.
	 */
	const TRUSTABLE = array( 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_TRUE_CLIENT_IP' );

	/**
	 * Headers that mean the request passed through a proxy.
	 */
	const FORWARDING = array(
		'HTTP_X_FORWARDED_FOR',
		'HTTP_X_REAL_IP',
		'HTTP_TRUE_CLIENT_IP',
		'HTTP_FORWARDED',
		'HTTP_X_SUCURI_CLIENTIP',
		'HTTP_FASTLY_CLIENT_IP',
		'HTTP_X_CLUSTER_CLIENT_IP',
		'HTTP_X_CLIENT_IP',
		'HTTP_CF_CONNECTING_IP',
	);

	/**
	 * Every list the plugin downloads: the operators' plus Cloudflare's.
	 *
	 * @return string[]
	 */
	public static function list_urls(): array {
		$urls = array();
		foreach ( AN10_Registry::crawlers() as $c ) {
			if ( null !== $c['ip_ranges'] ) {
				$urls[] = $c['ip_ranges'];
			}
		}
		$urls[] = self::CLOUDFLARE;
		return array_values( array_unique( $urls ) );
	}

	/**
	 * Download every list. A failed download keeps the previous copy, and a
	 * list no longer in use is dropped.
	 */
	public static function refresh(): void {
		$lists = array_intersect_key( self::lists(), array_flip( self::list_urls() ) );
		foreach ( self::list_urls() as $url ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'    => 10,
					'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . AN10_SLUG . '/' . AN10_VERSION,
				)
			);
			$code     = (int) wp_remote_retrieve_response_code( $response );
			$ranges   = 200 === $code ? AN10_Ip::parse_list( (string) wp_remote_retrieve_body( $response ) ) : array();
			$previous = $lists[ $url ] ?? array(
				'ranges'     => array(),
				'fetched_at' => 0,
			);
			if ( $ranges ) {
				$lists[ $url ] = array(
					'ranges'     => $ranges,
					'fetched_at' => time(),
					'error'      => '',
				);
			} else {
				$previous['error'] = is_wp_error( $response ) ? $response->get_error_message() : sprintf( 'HTTP %d', $code );
				$lists[ $url ]     = $previous;
			}
		}
		update_option( self::OPTION, $lists, false );
	}

	/**
	 * Stored lists, keyed by URL.
	 *
	 * @return array<string, array{ranges: string[], fetched_at: int, error?: string}>
	 */
	public static function lists(): array {
		return (array) get_option( self::OPTION, array() );
	}

	/**
	 * Who sent this request, as far as can be known honestly.
	 *
	 * @return array{ip: string|null, proxied: bool}
	 */
	public static function client(): array {
		$server = array();
		foreach ( array_merge( array( 'REMOTE_ADDR' ), self::FORWARDING ) as $key ) {
			if ( isset( $_SERVER[ $key ] ) ) {
				$server[ $key ] = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			}
		}
		return self::client_from(
			$server,
			(string) AN10_Plugin::setting( 'proxy_header' ),
			self::lists()[ self::CLOUDFLARE ]['ranges'] ?? array()
		);
	}

	/**
	 * The client address from request headers. Pure.
	 *
	 * `proxied` is true when the address may be a proxy's rather than the
	 * client's: a non-matching address is then Unverified, never Spoofed.
	 *
	 * @param array<string, string> $server     Request headers, $_SERVER style.
	 * @param string                $trusted    The header the site owner said their proxy sets, or ''.
	 * @param string[]              $cloudflare Cloudflare's ranges, empty until downloaded.
	 * @return array{ip: string|null, proxied: bool}
	 */
	public static function client_from( array $server, string $trusted, array $cloudflare ): array {
		$remote = (string) ( $server['REMOTE_ADDR'] ?? '' );
		if ( ! filter_var( $remote, FILTER_VALIDATE_IP ) ) {
			return array(
				'ip'      => null,
				'proxied' => true,
			);
		}

		// A header the site owner told us their own proxy sets.
		if ( in_array( $trusted, self::TRUSTABLE, true ) && ! empty( $server[ $trusted ] ) ) {
			$first = trim( explode( ',', (string) $server[ $trusted ] )[0] );
			return array(
				'ip'      => filter_var( $first, FILTER_VALIDATE_IP ) ? $first : null,
				'proxied' => false,
			);
		}

		// Cloudflare: trust its header only when the request came from Cloudflare.
		if ( ! empty( $server['HTTP_CF_CONNECTING_IP'] ) ) {
			if ( ! $cloudflare ) {
				return array(
					'ip'      => null,
					'proxied' => true,
				);
			}
			if ( AN10_Ip::in_any( $remote, $cloudflare ) ) {
				$cf = (string) $server['HTTP_CF_CONNECTING_IP'];
				return array(
					'ip'      => filter_var( $cf, FILTER_VALIDATE_IP ) ? $cf : null,
					'proxied' => false,
				);
			}
		}

		// A private address means a proxy we cannot see through.
		if ( ! AN10_Ip::is_public( $remote ) ) {
			return array(
				'ip'      => null,
				'proxied' => true,
			);
		}

		// A public address with a forwarding header may be the proxy's own.
		$proxied = false;
		foreach ( self::FORWARDING as $header ) {
			if ( ! empty( $server[ $header ] ) ) {
				$proxied = true;
				break;
			}
		}
		return array(
			'ip'      => $remote,
			'proxied' => $proxied,
		);
	}

	/**
	 * Verification state for a crawler claim from an address.
	 *
	 * @param array       $crawler Registry row.
	 * @param string|null $ip      Client address, or null when unknown.
	 * @param bool        $proxied The address may be a proxy's.
	 */
	public static function verify( array $crawler, ?string $ip, bool $proxied = false ): int {
		if ( null === $crawler['ip_ranges'] ) {
			return AN10_Store::UNVERIFIED;
		}
		$list = self::lists()[ $crawler['ip_ranges'] ] ?? array();
		return self::verdict(
			$ip,
			(array) ( $list['ranges'] ?? array() ),
			$proxied,
			(int) ( $list['fetched_at'] ?? 0 ) < time() - self::STALE_AFTER
		);
	}

	/**
	 * The verdict. Pure.
	 *
	 * A match is Verified even from an old list: operators rarely give up
	 * ranges. A miss is Spoofed only when nothing about it is uncertain.
	 *
	 * @param string|null $ip      Client address, or null when unknown.
	 * @param string[]    $ranges  The operator's ranges, empty when not downloaded.
	 * @param bool        $proxied The address may be a proxy's.
	 * @param bool        $stale   Our copy of the list is older than STALE_AFTER.
	 */
	public static function verdict( ?string $ip, array $ranges, bool $proxied, bool $stale ): int {
		if ( null === $ip || ! $ranges ) {
			return AN10_Store::UNVERIFIED;
		}
		if ( AN10_Ip::in_any( $ip, $ranges ) ) {
			return AN10_Store::VERIFIED;
		}
		return ( $proxied || $stale ) ? AN10_Store::UNVERIFIED : AN10_Store::SPOOFED;
	}
}
