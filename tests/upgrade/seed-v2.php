<?php
/** Seeds data using only 2.0.1 APIs (run while 2.0.1 is active). */
global $wpdb;
$doc = json_decode( (string) file_get_contents( WP_PLUGIN_DIR . '/finet-troubleshoot/examples/finet-troubleshoot-example.json' ), true );
$ids = array();
foreach ( array( 'prod-guide-a', 'prod-guide-b' ) as $ext ) {
	$doc['guides'][0]['external_id'] = $ext;
	$doc['guides'][0]['slug']        = $ext;
	$doc['guides'][0]['title']       = 'Guide ' . $ext;
	$r = ( new FinetTroubleshoot\Import\ImportManager( new FinetTroubleshoot\Validation\FlowValidator() ) )->import_data( $doc, 'copy' )->to_array();
	wp_update_post( array( 'ID' => $r['items'][0]['post_id'], 'post_status' => 'publish' ) );
	$ids[] = $r['items'][0]['post_id'];
}
$o = wp_insert_post( array( 'post_type' => 'wpfinet_outage', 'post_title' => 'Fiberavbrott Norr', 'post_name' => 'fiberavbrott-norr', 'post_status' => 'publish', 'post_date' => '2026-02-10 08:00:00', 'post_excerpt' => 'Avbrott', 'post_content' => '<p>Info</p>' ) );
foreach ( array( '_wpfinet_incident_type' => 'acute', '_wpfinet_outage_status' => 'identified', '_wpfinet_priority' => 'high', '_wpfinet_incident_start' => '2026-02-10 08:00:00', '_wpfinet_banner' => '1', '_wpfinet_internal_note' => 'intern', '_wpfinet_external_id' => 'ext-1', '_wpfinet_timeline' => array( array( 'time' => '2026-02-10 09:00:00', 'text' => 'Felsöker', 'status' => 'identified', 'author' => 1, 'public' => true ) ) ) as $k => $v ) {
	update_post_meta( $o, $k, $v );
}
wp_set_object_terms( $o, array( 'Internet', 'TV' ), 'wpfinet_service' );
wp_set_object_terms( $o, array( 'Norr' ), 'wpfinet_area' );
wp_set_object_terms( $o, array( 'Openinfra' ), 'wpfinet_provider' );
wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Hjälp', 'post_name' => 'hjalp', 'post_content' => '[finet_troubleshoot id="' . $ids[0] . '"][finet_troubleshoot slug="prod-guide-b"][wpfinet_outage_status][wpfinet_outages]' ) );
$s = get_option( 'finet_ts_settings' );
$s['support']['phone']           = '010-123 45 67';
$s['appearance']['primary_color'] = '#123456';
update_option( 'finet_ts_settings', $s );
$sid = wp_generate_uuid4();
$wpdb->insert( $wpdb->prefix . 'wpfinet_sessions', array( 'anonymous_session_id' => $sid, 'guide_id' => $ids[0], 'started_at' => '2026-10-01 10:00:00', 'last_event_at' => '2026-10-01 10:05:00', 'completed_at' => '2026-10-01 10:05:00', 'result_node' => 'lost', 'device_class' => 'mobile', 'page_path' => '/hjalp/', 'event_count' => 2, 'status' => 'completed' ) );
foreach ( array( 'guide_viewed', 'result_reached' ) as $e ) {
	$wpdb->insert( $wpdb->prefix . 'wpfinet_events', array( 'anonymous_session_id' => $sid, 'event_type' => $e, 'guide_id' => $ids[0], 'node_id' => 'lost', 'created_at' => '2026-10-01 10:05:00' ) );
}
echo 'seeded';
