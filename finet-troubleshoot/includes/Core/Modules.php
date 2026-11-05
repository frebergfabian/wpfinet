<?php
/**
 * Feature flags for optional WPFINET modules.
 *
 * Guides, outages and statistics are always on (they are the 2.0 feature set). New modules are opt-in so they can be
 * tested one at a time on staging without touching existing functionality.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Core;

final class Modules {
	public const OPTION = 'wpfinet_modules';

	/** Consent manager modes: off = not loaded, preview = only for logged-in admins (testing), live = all visitors. */
	public const CONSENT_MODES = array( 'off', 'preview', 'live' );

	/** @return array{access:bool,instagram:bool,consent:string,strict_capabilities:bool} */
	public static function defaults(): array {
		return array( 'access' => false, 'instagram' => false, 'consent' => 'off', 'strict_capabilities' => false );
	}

	/** @return array{access:bool,instagram:bool,consent:string,strict_capabilities:bool} */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		return self::sanitize( array_merge( self::defaults(), $stored ) );
	}

	public static function enabled( string $module ): bool {
		$all = self::all();
		if ( 'consent' === $module ) {
			return 'off' !== $all['consent'];
		}
		return ! empty( $all[ $module ] );
	}

	public static function consent_mode(): string {
		return self::all()['consent'];
	}

	/** @param array<string,mixed> $values */
	public static function sanitize( array $values ): array {
		$defaults = self::defaults();
		return array(
			'access'              => ! empty( $values['access'] ),
			'instagram'           => ! empty( $values['instagram'] ),
			'consent'             => in_array( $values['consent'] ?? 'off', self::CONSENT_MODES, true ) ? (string) $values['consent'] : $defaults['consent'],
			'strict_capabilities' => ! empty( $values['strict_capabilities'] ),
		);
	}

	/** @param array<string,mixed> $values */
	public static function update( array $values ): void {
		update_option( self::OPTION, self::sanitize( array_merge( self::all(), $values ) ), false );
	}
}
