<?php
/**
 * Safe, idempotent, additive migrations (1.x → 2.0 → 3.0).
 *
 * Rules: never delete or rename posts, meta, terms, options or tables; never change post IDs, slugs or URLs; every
 * step can run again without side effects. Runs on admin_init for a capable user, on activation and via
 * `wp wpfinet migrate`.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Core;

use FinetTroubleshoot\Analytics\Database;
use FinetTroubleshoot\PostTypes\GuidePostType;

final class MigrationManager {
	public const VERSION = '3.1.1';

	public function register_hooks(): void { add_action( 'admin_init', array( $this, 'maybe_migrate' ) ); }

	public static function needs_migration(): bool {
		return version_compare( (string) get_option( 'finet_ts_db_version', '0' ), self::VERSION, '<' );
	}

	public function maybe_migrate(): void {
		if ( ! self::needs_migration() ) { return; }
		if ( ! current_user_can( 'manage_wpfinet' ) && ! current_user_can( 'manage_options' ) ) { return; }
		$this->run();
	}

	/** @return array<int,string> Log lines for this run. */
	public function run(): array {
		if ( get_transient( 'wpfinet_migration_lock' ) ) { return array( 'Migrering pågår redan.' ); }
		set_transient( 'wpfinet_migration_lock', 1, 5 * MINUTE_IN_SECONDS );
		$from  = (string) get_option( 'finet_ts_db_version', '0' );
		$lines = array( sprintf( 'Start: databasversion %s → %s.', $from ?: '(ingen)', self::VERSION ) );
		$lines[] = 'Före: ' . wp_json_encode( self::counts() );
		try {
			if ( version_compare( $from, '2.0.0', '<' ) ) {
				$lines[] = sprintf( '1.x → 2.0: %d guider kompletterade.', $this->migrate_guides_to_2() );
			}
			( new Database() )->install();
			foreach ( self::module_tables() as $installer ) { if ( is_callable( $installer ) ) { $installer(); } }
			if ( version_compare( $from, '3.1.0', '<' ) ) {
				$lines[] = sprintf( '3.0 → 3.1: accentokänslig nyckel ifylld för %d gatunamn i adressindexet.', \FinetTroubleshoot\Access\AddressIndex::backfill_fold() );
			}
			Capabilities::grant_administrators();
			add_option( Modules::OPTION, Modules::defaults(), '', false );
			if ( ! empty( get_option( 'finet_ts_settings', array() )['advanced']['delete_data'] ) ) {
				$lines[] = 'VARNING: "Radera data vid avinstallation" är aktiverat. Avinstallation raderar guider, driftstörningar och statistik. Rekommendation: stäng av före uppgradering och ta separat backup.';
			}
			update_option( 'finet_ts_db_version', self::VERSION, false );
			update_option( 'finet_ts_upgrade_notice', self::VERSION, false );
			$lines[] = 'Efter: ' . wp_json_encode( self::counts() );
			$lines[] = 'Migrering till ' . self::VERSION . ' slutförd utan borttagningar.';
		} catch ( \Throwable $e ) {
			$lines[] = 'FEL: ' . $e->getMessage() . ' – inga data har tagits bort; databasversionen är oförändrad.';
		}
		delete_transient( 'wpfinet_migration_lock' );
		foreach ( $lines as $line ) { $this->log( $line ); }
		return $lines;
	}

	/** Table installers registered by optional modules (run regardless of feature flags so data structures exist). @return array<int,callable> */
	public static function module_tables(): array {
		return (array) apply_filters( 'wpfinet_migration_table_installers', array(
			array( \FinetTroubleshoot\Access\AddressIndex::class, 'install' ),
			array( \FinetTroubleshoot\Consent\Inventory::class, 'install' ),
		) );
	}

	/** @return array<string,int> */
	public static function counts(): array {
		global $wpdb;
		$count = static fn ( string $type ): int => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('auto-draft','inherit')", $type ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$table = static function ( string $name ) use ( $wpdb ): int {
			$full = $wpdb->prefix . $name;
			return $full === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $full ) ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$full}" ) : -1; // phpcs:ignore WordPress.DB
		};
		return array( 'guides' => $count( GuidePostType::POST_TYPE ), 'outages' => $count( 'wpfinet_outage' ), 'sessions' => $table( 'wpfinet_sessions' ), 'events' => $table( 'wpfinet_events' ) );
	}

	private function migrate_guides_to_2(): int {
		$ids = get_posts( array( 'post_type' => GuidePostType::POST_TYPE, 'post_status' => array( 'publish', 'draft', 'private', 'pending', 'future' ), 'fields' => 'ids', 'posts_per_page' => -1, 'no_found_rows' => true ) );
		$touched = 0;
		foreach ( $ids as $id ) {
			$nodes = get_post_meta( (int) $id, GuidePostType::META_NODES, true );
			if ( ! is_array( $nodes ) ) { continue; }
			$changed = false;
			foreach ( $nodes as $index => &$node ) {
				if ( ! is_array( $node ) ) { continue; }
				if ( ! isset( $node['editor_position'] ) ) { $node['editor_position'] = array( 'x' => ( $index % 4 ) * 320, 'y' => intdiv( $index, 4 ) * 220 ); $changed = true; }
				foreach ( array( 'ctas', 'wpforms', 'outage' ) as $key ) { if ( ! isset( $node[ $key ] ) ) { $node[ $key ] = array(); $changed = true; } }
				if ( ! isset( $node['image_position'] ) ) { $node['image_position'] = 'above_heading'; $changed = true; }
			}
			unset( $node );
			if ( $changed ) { update_post_meta( (int) $id, GuidePostType::META_NODES, $nodes ); ++$touched; }
			if ( ! get_post_meta( (int) $id, GuidePostType::META_EXTERNAL_ID, true ) ) { update_post_meta( (int) $id, GuidePostType::META_EXTERNAL_ID, sanitize_title( get_post_field( 'post_name', (int) $id ) ) . '-' . (int) $id ); }
			update_post_meta( (int) $id, GuidePostType::META_SCHEMA, '2.0' ); // Same as 2.0.0's migration.
		}
		return $touched;
	}

	private function log( string $message ): void {
		$log   = get_option( 'finet_ts_migration_log', array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array( 'time' => current_time( 'mysql', true ), 'message' => $message );
		update_option( 'finet_ts_migration_log', array_slice( $log, -50 ), false );
	}
}
