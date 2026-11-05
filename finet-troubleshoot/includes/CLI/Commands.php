<?php
/**
 * WP-CLI commands. Designed to be run from Windows Task Scheduler on IIS, e.g. every 15 minutes:
 *   php.exe C:\wp-cli\wp-cli.phar --path=C:\inetpub\wwwroot wpfinet jobs run
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\CLI;

use FinetTroubleshoot\Core\MigrationManager;
use FinetTroubleshoot\Core\Modules;
use FinetTroubleshoot\Migration\LegacyScanner;

final class Commands {
	public static function register(): void {
		\WP_CLI::add_command( 'wpfinet', self::class );
	}

	/**
	 * Runs the idempotent database migration (never deletes data).
	 *
	 * ## EXAMPLES
	 *     wp wpfinet migrate
	 */
	public function migrate(): void {
		foreach ( ( new MigrationManager() )->run() as $line ) { \WP_CLI::log( $line ); }
		\WP_CLI::success( 'Klart.' );
	}

	/**
	 * Read-only inventory of shortcodes and legacy integrations.
	 *
	 * ## OPTIONS
	 * [--format=<format>]
	 * : table or json. Default: table.
	 *
	 * @param array<int,string>    $args
	 * @param array<string,string> $assoc
	 */
	public function inventory( array $args, array $assoc ): void {
		$report = ( new LegacyScanner() )->scan();
		if ( 'json' === ( $assoc['format'] ?? 'table' ) ) {
			\WP_CLI::line( (string) wp_json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
			return;
		}
		$rows = array();
		foreach ( $report['summary'] as $key => $row ) { $rows[] = array( 'nyckel' => $key, 'referens' => $row['label'], 'plan' => $row['status'], 'förekomster' => $row['occurrences'], 'platser' => $row['locations'] ); }
		\WP_CLI\Utils\format_items( 'table', $rows, array( 'nyckel', 'referens', 'plan', 'förekomster', 'platser' ) );
		\WP_CLI::log( 'Trasiga guidereferenser: ' . count( $report['broken_guide_refs'] ) . ' · Oersatta äldre referenser: ' . $report['unresolved_legacy'] );
	}

	/**
	 * Runs WPFINET background jobs that are due (use with DISABLE_WP_CRON + Windows Task Scheduler).
	 *
	 * ## OPTIONS
	 * <action>
	 * : "run" runs due jobs; "list" shows schedule.
	 *
	 * @param array<int,string> $args
	 */
	public function jobs( array $args ): void {
		$hooks = array( 'wpfinet_daily_maintenance', 'wpfinet_access_sync', 'wpfinet_instagram_sync', 'wpfinet_instagram_refresh_token' );
		foreach ( $hooks as $hook ) {
			$next = wp_next_scheduled( $hook );
			if ( 'list' === ( $args[0] ?? 'run' ) ) {
				\WP_CLI::log( $hook . ': ' . ( $next ? wp_date( 'Y-m-d H:i:s', $next ) : 'ej schemalagd' ) );
				continue;
			}
			$event = wp_get_scheduled_event( $hook );
			if ( $event && $event->timestamp <= time() ) {
				\WP_CLI::log( 'Kör ' . $hook );
				if ( $event->schedule ) { wp_reschedule_event( $event->timestamp, $event->schedule, $hook, $event->args ); } // Same order as wp-cron.php.
				wp_unschedule_event( $event->timestamp, $hook, $event->args );
				do_action_ref_array( $hook, $event->args );
			}
		}
		\WP_CLI::success( 'Moduler: ' . wp_json_encode( Modules::all() ) );
	}

	/**
	 * Access API: sync | status | lookup "<adress>".
	 *
	 * @param array<int,string> $args
	 */
	public function access( array $args ): void {
		if ( ! class_exists( \FinetTroubleshoot\Access\AccessService::class ) ) { \WP_CLI::error( 'Modulen saknas.' ); }
		$service = new \FinetTroubleshoot\Access\AccessService();
		switch ( $args[0] ?? 'status' ) {
			case 'sync':
				$result = $service->sync( true );
				$result['ok'] ? \WP_CLI::success( $result['message'] ) : \WP_CLI::error( $result['message'], false );
				break;
			case 'lookup':
				\WP_CLI::line( (string) wp_json_encode( $service->lookup( (string) ( $args[1] ?? '' ) ), JSON_UNESCAPED_UNICODE ) );
				break;
			default:
				\WP_CLI::line( (string) wp_json_encode( $service->health(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
		}
	}

	/**
	 * Instagram: sync | status.
	 *
	 * @param array<int,string> $args
	 */
	public function instagram( array $args ): void {
		if ( ! class_exists( \FinetTroubleshoot\Instagram\InstagramService::class ) ) { \WP_CLI::error( 'Modulen saknas.' ); }
		$service = new \FinetTroubleshoot\Instagram\InstagramService();
		if ( 'sync' === ( $args[0] ?? 'status' ) ) {
			$result = $service->sync();
			$result['ok'] ? \WP_CLI::success( $result['message'] ) : \WP_CLI::error( $result['message'], false );
			return;
		}
		\WP_CLI::line( (string) wp_json_encode( $service->health(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
	}

	/**
	 * Creates or removes fictitious test content for manual testing (see WPFinet → Diagnos). Refuses in production.
	 *
	 * ## OPTIONS
	 * <action>
	 * : create or remove
	 *
	 * @param array<int,string> $args
	 */
	public function testdata( array $args ): void {
		$action = $args[0] ?? '';
		if ( ! in_array( $action, array( 'create', 'remove' ), true ) ) { \WP_CLI::error( 'Ange create eller remove.' ); }
		$result = 'create' === $action ? \FinetTroubleshoot\Admin\TestData::create() : \FinetTroubleshoot\Admin\TestData::remove();
		foreach ( $result['lines'] as $line ) { \WP_CLI::log( $line ); }
		$result['ok'] ? \WP_CLI::success( 'Klart.' ) : \WP_CLI::error( 'Avbröts.' );
	}
}
