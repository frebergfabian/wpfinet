<?php
/**
 * Scheduled maintenance for WPFinet.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Core;

use FinetTroubleshoot\Analytics\Database;

final class Cron {
	public const HOOK = 'wpfinet_daily_maintenance';
	public function register_hooks(): void { add_action( self::HOOK, array( $this, 'run' ) ); }
	public static function schedule(): void { if ( ! wp_next_scheduled( self::HOOK ) ) { wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK ); } }
	public static function unschedule(): void { $timestamp = wp_next_scheduled( self::HOOK ); if ( $timestamp ) { wp_unschedule_event( $timestamp, self::HOOK ); } }
	public function run(): void { ( new Database() )->cleanup(); }
}
