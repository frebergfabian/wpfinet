<?php
/**
 * Fixed-window rate limiter keyed on a salted hash (raw IP addresses are never stored).
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Core;

final class RateLimiter {
	/** Returns true when the action is allowed and counts it. */
	public static function hit( string $bucket, string $identity, int $limit, int $window_seconds ): bool {
		if ( $limit <= 0 ) {
			return true;
		}
		$window = (int) floor( time() / max( 1, $window_seconds ) );
		$key    = 'wpfinet_rl_' . substr( hash_hmac( 'sha256', $bucket . '|' . $identity . '|' . $window, wp_salt( 'nonce' ) ), 0, 32 );
		if ( wp_using_ext_object_cache() ) {
			$count = wp_cache_incr( $key, 1, 'wpfinet' );
			if ( false === $count ) {
				wp_cache_add( $key, 1, 'wpfinet', $window_seconds );
				$count = 1;
			}
		} else {
			$count = (int) get_transient( $key ) + 1;
			set_transient( $key, $count, $window_seconds );
		}
		return $count <= $limit;
	}
}
