<?php
/**
 * Privacy-minimised analytics storage.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Analytics;

final class Database {
	public function sessions_table(): string { global $wpdb; return $wpdb->prefix . 'wpfinet_sessions'; }
	public function events_table(): string { global $wpdb; return $wpdb->prefix . 'wpfinet_events'; }
	public function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$sessions = $this->sessions_table(); $events = $this->events_table();
		$sql_sessions = "CREATE TABLE {$sessions} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			anonymous_session_id char(36) NOT NULL,
			guide_id bigint(20) unsigned NOT NULL DEFAULT 0,
			started_at datetime NOT NULL,
			last_event_at datetime NOT NULL,
			completed_at datetime NULL,
			result_node varchar(100) NOT NULL DEFAULT '',
			device_class varchar(16) NOT NULL DEFAULT 'unknown',
			page_path varchar(255) NOT NULL DEFAULT '',
			event_count int(10) unsigned NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'active',
			PRIMARY KEY  (id),
			UNIQUE KEY anonymous_session_id (anonymous_session_id),
			KEY guide_id (guide_id),
			KEY last_event_at (last_event_at),
			KEY status (status)
		) {$charset};";
		$sql_events = "CREATE TABLE {$events} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			anonymous_session_id char(36) NOT NULL,
			event_type varchar(40) NOT NULL,
			guide_id bigint(20) unsigned NOT NULL DEFAULT 0,
			node_id varchar(100) NOT NULL DEFAULT '',
			option_id varchar(100) NOT NULL DEFAULT '',
			cta_type varchar(32) NOT NULL DEFAULT '',
			form_id bigint(20) unsigned NOT NULL DEFAULT 0,
			outage_id bigint(20) unsigned NOT NULL DEFAULT 0,
			created_at datetime NOT NULL,
			metadata longtext NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY anonymous_session_id (anonymous_session_id),
			KEY guide_event (guide_id,event_type),
			KEY node_id (node_id)
		) {$charset};";
		dbDelta( $sql_sessions ); dbDelta( $sql_events );
	}
	public function cleanup(): void {
		global $wpdb;
		$settings = get_option( 'finet_ts_settings', array() ); $days = absint( $settings['analytics']['retention_days'] ?? 180 );
		if ( 0 === $days ) { return; }
		$before = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->events_table()} WHERE created_at < %s", $before ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->sessions_table()} WHERE last_event_at < %s", $before ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$timeout = max( 5, absint( $settings['analytics']['session_timeout_minutes'] ?? 30 ) ) * MINUTE_IN_SECONDS;
		$abandoned_before = gmdate( 'Y-m-d H:i:s', time() - $timeout );
		$wpdb->query( $wpdb->prepare( "UPDATE {$this->sessions_table()} SET status = 'abandoned' WHERE status = 'active' AND last_event_at < %s", $abandoned_before ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}
}
