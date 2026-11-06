<?php
/** Seeds a guide page for the stability test. GUIDE_JSON = import file (default: bundled example + a long-text step). */
$file = getenv( 'GUIDE_JSON' ) ?: '';
if ( $file && is_readable( $file ) ) {
	$doc = json_decode( (string) file_get_contents( $file ), true );
} else {
	$doc = json_decode( (string) file_get_contents( WP_PLUGIN_DIR . '/finet-troubleshoot/examples/finet-troubleshoot-example.json' ), true );
	// Add a very long step to prove that long texts are not cut off.
	$doc['guides'][0]['nodes'][1]['content'] = '<p>' . str_repeat( 'Koppla ur strömmen till router och mediakonverter, vänta och koppla in igen. ', 18 ) . '</p><p>Ett mycket_långt_ord_utan_mellanslag_som_inte_får_spräcka_layouten_på_mobilen_0123456789.</p>';
}
$doc['guides'][0]['external_id'] = 'stability-guide';
$doc['guides'][0]['slug'] = 'stability-guide';
$r  = ( new FinetTroubleshoot\Import\ImportManager( new FinetTroubleshoot\Validation\FlowValidator() ) )->import_data( $doc, 'copy' )->to_array();
$id = (int) $r['items'][0]['post_id'];
wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) );
$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Guidetest', 'post_name' => 'guidetest', 'post_content' => '[finet_troubleshoot id="' . $id . '"]<p id="after-guide">Text under guiden</p>' ) );
echo wp_json_encode( array( 'guide' => $id, 'url' => get_permalink( $page ) ) );
