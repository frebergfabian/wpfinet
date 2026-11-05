<?php
/** Extra seed for the UI review: all modules visible with realistic data (fictitious). Run after seed.php. */
use FinetTroubleshoot\Core\Modules;
Modules::update( array( 'access' => true, 'instagram' => true, 'consent' => 'preview' ) );
// Instagram: fictitious local posts with generated images.
$dir = FinetTroubleshoot\Instagram\InstagramService::upload_dir();
wp_mkdir_p( $dir['path'] );
$items = array();
$colors = array( array( 6, 40, 77 ), array( 244, 124, 32 ), array( 25, 99, 58 ), array( 120, 130, 140 ), array( 160, 30, 40 ), array( 60, 90, 160 ) );
foreach ( $colors as $i => $c ) {
	$img = imagecreatetruecolor( 600, 600 ); imagefill( $img, 0, 0, imagecolorallocate( $img, $c[0], $c[1], $c[2] ) );
	imagejpeg( $img, $dir['path'] . "/ig-90$i.jpg", 80 );
	$items[] = array( 'id' => "90$i", 'type' => 1 === $i ? 'VIDEO' : 'IMAGE', 'permalink' => "https://www.instagram.com/p/TEST$i/", 'timestamp' => time() - $i * DAY_IN_SECONDS, 'caption' => 1 === $i ? '' : "Testinlägg nummer $i från fiberutbyggnaden i Finspång. Vi gräver vidare i området och informerar löpande om när din gata står på tur.", 'file' => "ig-90$i.jpg", 'width' => 600, 'height' => 600 );
}
update_option( FinetTroubleshoot\Instagram\InstagramService::MEDIA, $items, false );
FinetTroubleshoot\Instagram\InstagramService::update_state( array( 'username' => 'finet_test', 'account_type' => 'BUSINESS', 'last_sync' => time() - 600, 'last_result' => '6 inlägg sparade lokalt.' ) );
FinetTroubleshoot\Core\Secrets::set( 'instagram_token', 'IGAAfaketoken1234567890abcdefghij' );
// Consent: inventory + approved policy.
foreach ( array( array( 'wpfinet_consent', 'cookie', 'necessary' ), array( '_ga', 'cookie', 'statistics' ), array( 'wpfinet_analytics_session', 'sessionStorage', 'statistics' ) ) as [ $n, $t, $cat ] ) {
	FinetTroubleshoot\Consent\Inventory::observe( array( 'name' => $n, 'domain' => '127.0.0.1', 'storage_type' => $t, 'source' => 'default' ) );
}
foreach ( FinetTroubleshoot\Consent\Inventory::all() as $r ) {
	FinetTroubleshoot\Consent\Inventory::update_item( (int) $r['id'], array( 'category' => $r['suggested_category'] ?: 'necessary', 'status' => 'verified' ) );
}
FinetTroubleshoot\Consent\Inventory::observe( array( 'name' => '_fbp', 'domain' => '127.0.0.1', 'before_consent' => true, 'source' => 'scan', 'page' => home_url( '/' ) ) );
FinetTroubleshoot\Consent\Policy::approve( 1, 'UI-granskning' );
$policy = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Cookiepolicy', 'post_name' => 'cookiepolicy', 'post_content' => '[wpfinet_cookie_policy]' ) );
FinetTroubleshoot\Consent\ConsentSettings::update( array( 'policy_page_id' => $policy ) );
wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Instagram', 'post_name' => 'instagram', 'post_content' => '[wpfinet_instagram_feed title="Följ oss" limit="6"]' ) );
( new FinetTroubleshoot\Migration\LegacyScanner() )->scan();
echo 'ui-seeded';
