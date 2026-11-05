<?php
/** Prints a JSON snapshot of all WPFINET data for before/after comparison. wp eval-file snapshot.php */
global $wpdb;
$posts = array();
foreach ( array( 'finet_ts_guide', 'wpfinet_outage', 'page' ) as $type ) {
	foreach ( get_posts( array( 'post_type' => $type, 'post_status' => 'any', 'posts_per_page' => -1, 'orderby' => 'ID', 'order' => 'ASC' ) ) as $p ) {
		$meta = get_post_meta( $p->ID );
		ksort( $meta );
		$meta = array_filter( $meta, static fn ( $k ) => ! str_starts_with( (string) $k, '_edit_' ), ARRAY_FILTER_USE_KEY );
		$posts[] = array( 'ID' => $p->ID, 'type' => $type, 'name' => $p->post_name, 'status' => $p->post_status, 'date' => $p->post_date, 'title' => $p->post_title, 'content_md5' => md5( $p->post_content ), 'excerpt' => $p->post_excerpt, 'meta_md5' => md5( serialize( $meta ) ), 'permalink' => get_permalink( $p ) );
	}
}
$terms = array_map( static fn ( $t ) => array( $t->term_id, $t->taxonomy, $t->slug, $t->name ), get_terms( array( 'taxonomy' => array( 'wpfinet_provider', 'wpfinet_service', 'wpfinet_area' ), 'hide_empty' => false ) ) );
$rel   = $wpdb->get_results( "SELECT object_id, term_taxonomy_id FROM {$wpdb->term_relationships} ORDER BY object_id, term_taxonomy_id", ARRAY_N );
$stats = array();
foreach ( array( 'wpfinet_sessions', 'wpfinet_events' ) as $t ) {
	$stats[ $t ] = md5( (string) wp_json_encode( $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}{$t} ORDER BY id", ARRAY_A ) ) );
}
$caps = array_keys( array_filter( get_role( 'administrator' )->capabilities, static fn ( $v, $k ) => str_contains( (string) $k, 'wpfinet' ), ARRAY_FILTER_USE_BOTH ) );
$v3 = array();
foreach ( array( 'wpfinet_modules', 'wpfinet_access_settings', 'wpfinet_instagram_settings', 'wpfinet_instagram_media', 'wpfinet_consent_settings', 'wpfinet_consent_policy', 'wpfinet_secrets' ) as $o ) {
	$v3[ $o ] = md5( (string) wp_json_encode( get_option( $o, null ) ) );
}
$index = $wpdb->prefix . 'wpfinet_access_index';
$has   = $index === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $index ) );
$v3['access_index'] = $has ? md5( (string) wp_json_encode( $wpdb->get_results( "SELECT street_key, number, letter, state, connected FROM {$index} ORDER BY street_key, number, letter", ARRAY_A ) ) ) : null;
$v3['access_index_rows'] = $has ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$index}" ) : 0;
$v3['access_index_unfolded'] = $has && in_array( 'street_fold', (array) $wpdb->get_col( "SHOW COLUMNS FROM {$index}" ), true ) ? (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$index} WHERE street_fold = ''" ) : -1;
echo wp_json_encode( array( 'v3' => $v3, 'posts' => $posts, 'terms' => $terms, 'relationships' => $rel, 'settings' => get_option( 'finet_ts_settings' ), 'stats' => $stats, 'caps' => $caps, 'db_version' => get_option( 'finet_ts_db_version' ), 'plugin_version' => defined( 'FINET_TS_VERSION' ) ? FINET_TS_VERSION : '' ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
