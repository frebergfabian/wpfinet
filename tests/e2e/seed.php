<?php
/** Seeds representative content for HTTP smoke/E2E tests. Run with: wp eval-file tests/e2e/seed.php */
$doc = json_decode( (string) file_get_contents( WP_PLUGIN_DIR . '/finet-troubleshoot/examples/finet-troubleshoot-example.json' ), true );
$doc['guides'][0]['external_id'] = 'e2e-guide'; $doc['guides'][0]['slug'] = 'e2e-guide';
$result = ( new FinetTroubleshoot\Import\ImportManager( new FinetTroubleshoot\Validation\FlowValidator() ) )->import_data( $doc, 'copy' )->to_array();
$guide  = (int) $result['items'][0]['post_id'];
wp_update_post( array( 'ID' => $guide, 'post_status' => 'publish' ) );
$outage = wp_insert_post( array( 'post_type' => 'wpfinet_outage', 'post_title' => 'E2E fiberavbrott', 'post_name' => 'e2e-fiberavbrott', 'post_status' => 'publish', 'post_date' => '2026-03-04 10:00:00', 'post_excerpt' => 'Avbrott i område Norr', 'post_content' => '<p>Tekniker är på plats.</p>' ) );
foreach ( array( '_wpfinet_incident_type' => 'acute', '_wpfinet_outage_status' => 'investigating', '_wpfinet_priority' => 'high', '_wpfinet_incident_start' => wp_date( 'Y-m-d H:i:s', time() - 3600 ), '_wpfinet_banner' => '1', '_wpfinet_internal_note' => 'INTERN-HEMLIGHET', '_wpfinet_timeline' => array( array( 'time' => wp_date( 'Y-m-d H:i:s' ), 'text' => 'Felsökning pågår', 'status' => 'investigating', 'author' => 1, 'public' => true ) ) ) as $k => $v ) { update_post_meta( $outage, $k, $v ); }
wp_set_object_terms( $outage, array( 'Internet' ), 'wpfinet_service' );
$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Felsökning', 'post_name' => 'felsokning', 'post_content' => '[finet_troubleshoot id="' . $guide . '"][finet_troubleshoot slug="e2e-guide" show_title="false"][wpfinet_outage_status][wpfinet_outages status="current" service="internet"]' ) );
$home = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Start', 'post_name' => 'start', 'post_content' => '<p>Välkommen</p>' ) );
update_option( 'show_on_front', 'page' ); update_option( 'page_on_front', $home );
// Address check with fictitious test data.
FinetTroubleshoot\Core\Modules::update( array( 'access' => true ) );
FinetTroubleshoot\Access\AccessSettings::update( array( 'adapter' => 'mock', 'rate_limit' => 100 ) );
( new FinetTroubleshoot\Access\AccessService() )->sync( false );
$access = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Adresskoll', 'post_name' => 'adresskoll', 'post_content' => '[wpfinet_access_check title="Kan du få fiber?"]' ) );
( new FinetTroubleshoot\Outages\OutageRepository() )->flush();
echo wp_json_encode( array( 'guide' => $guide, 'outage' => $outage, 'outage_url' => get_permalink( $outage ), 'page' => get_permalink( $page ) ) );
