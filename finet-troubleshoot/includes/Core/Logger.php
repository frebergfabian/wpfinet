<?php
/**
 * Small ring-buffer log for background jobs and admin actions. Never written on ordinary page views, never stores
 * personal data (messages are redacted for e-mail addresses, tokens and long digit sequences).
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Core;

final class Logger {
	public const OPTION = 'wpfinet_log';
	private const MAX   = 200;

	/** @param array<string,scalar|null> $context */
	public static function log( string $channel, string $level, string $message, array $context = array() ): void {
		$log   = get_option( self::OPTION, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'time'    => gmdate( 'c' ),
			'channel' => sanitize_key( $channel ),
			'level'   => in_array( $level, array( 'debug', 'info', 'warning', 'error' ), true ) ? $level : 'info',
			'message' => self::redact( $message ),
			'context' => array_map( static fn ( $v ) => is_string( $v ) ? self::redact( $v ) : $v, $context ),
		);
		update_option( self::OPTION, array_slice( $log, -self::MAX ), false );
	}

	/** @return array<int,array<string,mixed>> */
	public static function entries( string $channel = '' ): array {
		$log = get_option( self::OPTION, array() );
		$log = is_array( $log ) ? $log : array();
		if ( $channel ) {
			$log = array_values( array_filter( $log, static fn ( $e ) => ( $e['channel'] ?? '' ) === $channel ) );
		}
		return array_reverse( $log );
	}

	public static function redact( string $text ): string {
		$text = (string) preg_replace( '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[e-post]', $text );
		$text = (string) preg_replace( '/(access_token|token|secret|password|apikey|api_key|key)=([^&\s"]+)/i', '$1=[dolt]', $text );
		$text = (string) preg_replace( '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i', '$1 [dolt]', $text );
		$text = (string) preg_replace( '/\b[A-Za-z0-9_-]{40,}\b/', '[dolt]', $text );
		return mb_substr( $text, 0, 500 );
	}
}
