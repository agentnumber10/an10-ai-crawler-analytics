<?php
/**
 * IP addresses and CIDR ranges, IPv4 and IPv6.
 *
 * Used only to compare the address of a request that claims to be an AI
 * crawler against the ranges its operator publishes. Nothing here stores an
 * address.
 *
 * Pure: no WordPress functions.
 *
 * @package AiCrawlerAnalytics
 */

defined( 'ABSPATH' ) || exit;

/**
 * IP range matching.
 */
final class AN10_Ip {

	/**
	 * Is `$ip` inside the CIDR range `$cidr`?
	 *
	 * @param string $ip   Address, IPv4 or IPv6.
	 * @param string $cidr Range such as "20.171.206.0/24" or "2a03:2880::/32".
	 */
	public static function in_range( string $ip, string $cidr ): bool {
		$slash = strpos( $cidr, '/' );
		$net   = false === $slash ? $cidr : substr( $cidr, 0, $slash );
		$a     = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$b     = @inet_pton( $net ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $a || false === $b || strlen( $a ) !== strlen( $b ) ) {
			return false;
		}
		$max  = strlen( $a ) * 8;
		$bits = false === $slash ? $max : (int) substr( $cidr, $slash + 1 );
		if ( $bits < 0 || $bits > $max ) {
			return false;
		}

		$full = intdiv( $bits, 8 );
		if ( substr( $a, 0, $full ) !== substr( $b, 0, $full ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
		return ( ord( $a[ $full ] ) & $mask ) === ( ord( $b[ $full ] ) & $mask );
	}

	/**
	 * Is `$ip` inside any of the ranges?
	 *
	 * @param string   $ip     Address.
	 * @param string[] $ranges CIDR ranges.
	 */
	public static function in_any( string $ip, array $ranges ): bool {
		foreach ( $ranges as $cidr ) {
			if ( self::in_range( $ip, (string) $cidr ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A routable public address, not private, loopback or reserved.
	 *
	 * @param string $ip Address.
	 */
	public static function is_public( string $ip ): bool {
		return false !== filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Extract the CIDR prefixes from an operator's published list.
	 *
	 * Every list in the registry uses the same shape, verified on 2026-11-12:
	 * `{"creationTime": "...", "prefixes": [{"ipv4Prefix": "..."}, {"ipv6Prefix": "..."}]}`.
	 * Cloudflare's API answers `{"result": {"ipv4_cidrs": [...], "ipv6_cidrs": [...]}}`.
	 * Plain text, one range per line, is read too.
	 *
	 * @param string $body Response body.
	 * @return string[] Valid ranges; an unreadable body gives an empty list.
	 */
	public static function parse_list( string $body ): array {
		$out  = array();
		$json = json_decode( $body, true );
		if ( is_array( $json ) && isset( $json['prefixes'] ) && is_array( $json['prefixes'] ) ) {
			foreach ( $json['prefixes'] as $p ) {
				$cidr = is_array( $p ) ? ( $p['ipv4Prefix'] ?? $p['ipv6Prefix'] ?? null ) : null;
				if ( is_string( $cidr ) && self::is_cidr( $cidr ) ) {
					$out[] = $cidr;
				}
			}
			return $out;
		}
		if ( is_array( $json ) && isset( $json['result'] ) && is_array( $json['result'] ) ) {
			foreach ( array( 'ipv4_cidrs', 'ipv6_cidrs' ) as $key ) {
				foreach ( (array) ( $json['result'][ $key ] ?? array() ) as $cidr ) {
					if ( is_string( $cidr ) && self::is_cidr( $cidr ) ) {
						$out[] = $cidr;
					}
				}
			}
			return $out;
		}
		foreach ( preg_split( '/\s+/', trim( $body ) ) as $line ) {
			if ( self::is_cidr( $line ) ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/**
	 * Is this a well-formed CIDR range?
	 *
	 * @param string $cidr Candidate.
	 */
	public static function is_cidr( string $cidr ): bool {
		$parts = explode( '/', $cidr );
		if ( 2 !== count( $parts ) || ! preg_match( '/^\d+$/', $parts[1] ) ) {
			return false;
		}
		$bin = @inet_pton( $parts[0] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return false !== $bin && (int) $parts[1] <= strlen( $bin ) * 8;
	}
}
