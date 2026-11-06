<?php
/**
 * Asset version strings. 3.1.1: the plugin version plus the file's modification time, so an updated file always gets
 * a new URL even if a cache (Cloudflare, browser) still holds an older file under the same plugin version.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Core;

final class Assets {
	public static function ver( string $build_file ): string {
		$path = FINET_TS_DIR . 'assets/build/' . ltrim( $build_file, '/' );
		$time = is_readable( $path ) ? (int) filemtime( $path ) : 0;
		return FINET_TS_VERSION . ( $time ? '.' . $time : '' );
	}
}
