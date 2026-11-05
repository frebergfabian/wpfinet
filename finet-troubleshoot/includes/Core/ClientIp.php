<?php
/**
 * Resolves the visitor IP for rate limiting only. A proxy header (e.g. Cloudflare's CF-Connecting-IP) is trusted only
 * when the direct peer (REMOTE_ADDR) is inside an explicitly trusted CIDR list.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Core;

final class ClientIp {
	/** Cloudflare published ranges (https://www.cloudflare.com/ips/) – verify periodically. */
	public const CLOUDFLARE_RANGES = array(
		'173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20',
		'188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
		'2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
	);

	/** @param array<int,string> $trusted_cidrs */
	public static function resolve( bool $trust_cloudflare = false, array $trusted_cidrs = array() ): string {
		$remote = (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$remote = filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '0.0.0.0';
		$trusted = $trust_cloudflare ? array_merge( self::CLOUDFLARE_RANGES, $trusted_cidrs ) : $trusted_cidrs;
		if ( $trusted && self::in_ranges( $remote, $trusted ) ) {
			$header = (string) ( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			if ( filter_var( $header, FILTER_VALIDATE_IP ) ) {
				return $header;
			}
		}
		return $remote;
	}

	/** @param array<int,string> $ranges */
	public static function in_ranges( string $ip, array $ranges ): bool {
		foreach ( $ranges as $range ) {
			if ( self::in_cidr( $ip, trim( $range ) ) ) {
				return true;
			}
		}
		return false;
	}

	public static function in_cidr( string $ip, string $cidr ): bool {
		if ( ! str_contains( $cidr, '/' ) ) {
			return $ip === $cidr;
		}
		[ $subnet, $bits ] = explode( '/', $cidr, 2 );
		$ip_bin  = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$sub_bin = @inet_pton( $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $ip_bin || false === $sub_bin || strlen( $ip_bin ) !== strlen( $sub_bin ) ) {
			return false;
		}
		$bits  = (int) $bits;
		$bytes = intdiv( $bits, 8 );
		if ( substr( $ip_bin, 0, $bytes ) !== substr( $sub_bin, 0, $bytes ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = chr( 0xff << ( 8 - $rest ) & 0xff );
		return ( $ip_bin[ $bytes ] & $mask ) === ( $sub_bin[ $bytes ] & $mask );
	}
}
