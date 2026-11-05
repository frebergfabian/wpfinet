<?php
/**
 * Removes Finet Troubleshoot data only when the explicit opt-in setting is enabled.
 *
 * @package FinetTroubleshoot
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'finet_ts_settings', array() );
if ( empty( $settings['advanced']['delete_data'] ) ) {
	return;
}

$ids = get_posts(
	array(
		'post_type'      => 'finet_ts_guide',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $ids as $id ) {
	wp_delete_post( (int) $id, true );
}
$outage_ids = get_posts(
	array(
		'post_type'      => 'wpfinet_outage',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);
foreach ( $outage_ids as $id ) {
	wp_delete_post( (int) $id, true );
}
delete_option( 'finet_ts_settings' );
delete_option( 'finet_ts_demo_installed' );
delete_option( 'finet_ts_schema_version' );
delete_option( 'finet_ts_db_version' );
delete_option( 'finet_ts_upgrade_notice' );
delete_option( 'finet_ts_migration_log' );
delete_transient( 'finet_ts_activation_notice' );

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_finet_ts_import_%' OR option_name LIKE '_transient_timeout_finet_ts_import_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpfinet_events, {$wpdb->prefix}wpfinet_sessions" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery
