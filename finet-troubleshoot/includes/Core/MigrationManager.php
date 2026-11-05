<?php
/**
 * Safe, idempotent migration from Finet Troubleshoot 1.x.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Core;

use FinetTroubleshoot\Analytics\Database;
use FinetTroubleshoot\PostTypes\GuidePostType;

final class MigrationManager {
	public const VERSION = '2.0.0';
	public function register_hooks(): void { add_action( 'admin_init', array( $this, 'maybe_migrate' ) ); }
	public function maybe_migrate(): void {
		if ( get_option( 'finet_ts_db_version' ) === self::VERSION ) { return; }
		if ( ! current_user_can( 'manage_wpfinet' ) && ! current_user_can( 'manage_options' ) ) { return; }
		$this->migrate_guides();
		( new Database() )->install();
		Capabilities::grant_administrators();
		update_option( 'finet_ts_db_version', self::VERSION, false );
		update_option( 'finet_ts_upgrade_notice', self::VERSION, false );
		$this->log( 'Migrering till 2.0 slutförd.' );
	}
	private function migrate_guides(): void {
		$ids = get_posts( array( 'post_type' => GuidePostType::POST_TYPE, 'post_status' => array( 'publish', 'draft', 'private', 'pending', 'future' ), 'fields' => 'ids', 'posts_per_page' => -1, 'no_found_rows' => true ) );
		foreach ( $ids as $id ) {
			$nodes = get_post_meta( (int) $id, GuidePostType::META_NODES, true );
			if ( ! is_array( $nodes ) ) { continue; }
			$changed = false;
			foreach ( $nodes as $index => &$node ) {
				if ( ! is_array( $node ) ) { continue; }
				if ( ! isset( $node['editor_position'] ) ) { $node['editor_position'] = array( 'x' => ( $index % 4 ) * 320, 'y' => intdiv( $index, 4 ) * 220 ); $changed = true; }
				if ( ! isset( $node['ctas'] ) ) { $node['ctas'] = array(); $changed = true; }
				if ( ! isset( $node['wpforms'] ) ) { $node['wpforms'] = array(); $changed = true; }
				if ( ! isset( $node['outage'] ) ) { $node['outage'] = array(); $changed = true; }
				if ( ! isset( $node['image_position'] ) ) { $node['image_position'] = 'above_heading'; $changed = true; }
			}
			unset( $node );
			if ( $changed ) { update_post_meta( (int) $id, GuidePostType::META_NODES, $nodes ); }
			if ( ! get_post_meta( (int) $id, GuidePostType::META_EXTERNAL_ID, true ) ) { update_post_meta( (int) $id, GuidePostType::META_EXTERNAL_ID, sanitize_title( get_post_field( 'post_name', (int) $id ) ) . '-' . (int) $id ); }
			update_post_meta( (int) $id, GuidePostType::META_SCHEMA, '2.0' );
		}
	}
	private function log( string $message ): void {
		$log = get_option( 'finet_ts_migration_log', array() );
		$log = is_array( $log ) ? $log : array();
		$log[] = array( 'time' => current_time( 'mysql', true ), 'message' => $message );
		update_option( 'finet_ts_migration_log', array_slice( $log, -20 ), false );
	}
}
