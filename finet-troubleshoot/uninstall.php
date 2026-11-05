<?php
/**
 * Removes WPFINET / Finet Troubleshoot data ONLY when the explicit opt-in setting "advanced.delete_data" is enabled.
 * Default (and recommended): nothing is deleted. Deactivation never deletes anything.
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
// Extra guard (3.0): a site can forbid destructive uninstall entirely, e.g. in production wp-config.php.
if ( defined( 'WPFINET_PREVENT_DATA_DELETION' ) && WPFINET_PREVENT_DATA_DELETION ) {
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
foreach ( array( 'finet_ts_settings', 'finet_ts_demo_installed', 'finet_ts_schema_version', 'finet_ts_db_version', 'finet_ts_upgrade_notice', 'finet_ts_migration_log', 'wpfinet_modules', 'wpfinet_secrets', 'wpfinet_log', 'wpfinet_migration_report', 'wpfinet_access_settings', 'wpfinet_access_state', 'wpfinet_instagram_settings', 'wpfinet_instagram_state', 'wpfinet_instagram_media', 'wpfinet_consent_settings', 'wpfinet_consent_policy', 'wpfinet_outages_cache_gen' ) as $option ) {
	delete_option( $option );
}
delete_transient( 'finet_ts_activation_notice' );

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_finet_ts_import_%' OR option_name LIKE '_transient_timeout_finet_ts_import_%' OR option_name LIKE 'wpfinet_cb_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpfinet_events, {$wpdb->prefix}wpfinet_sessions, {$wpdb->prefix}wpfinet_access_index, {$wpdb->prefix}wpfinet_cookie_inventory, {$wpdb->prefix}wpfinet_consent_log" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery
