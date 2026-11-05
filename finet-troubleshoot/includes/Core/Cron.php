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
	public function register_hooks(): void { add_action( self::HOOK, array( $this, 'run' ) ); add_filter( 'cron_schedules', array( self::class, 'schedules' ) ); }
	/** @param array<string,array{interval:int,display:string}> $schedules */
	public static function schedules( array $schedules ): array {
		$schedules['wpfinet_15min'] = array( 'interval' => 15 * MINUTE_IN_SECONDS, 'display' => __( 'Var 15:e minut (WPFINET)', 'finet-troubleshoot' ) );
		$schedules['wpfinet_30min'] = array( 'interval' => 30 * MINUTE_IN_SECONDS, 'display' => __( 'Var 30:e minut (WPFINET)', 'finet-troubleshoot' ) );
		return $schedules;
	}
	public static function schedule(): void { if ( ! wp_next_scheduled( self::HOOK ) ) { wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK ); } }
	public static function unschedule(): void { $timestamp = wp_next_scheduled( self::HOOK ); if ( $timestamp ) { wp_unschedule_event( $timestamp, self::HOOK ); } }
	public function run(): void { ( new Database() )->cleanup(); }
}
