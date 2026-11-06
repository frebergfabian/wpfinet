<?php
/** Creates 3.x module data with the OLD 3.x plugin active (wp eval-file seed-v3.php). */
use FinetTroubleshoot\Access\AccessService;
use FinetTroubleshoot\Access\AccessSettings;
use FinetTroubleshoot\Core\Modules;
use FinetTroubleshoot\Core\Secrets;

Modules::update( array( 'access' => true, 'instagram' => false, 'consent' => 'preview' ) );
AccessSettings::update( array( 'adapter' => 'http', 'mode' => 'index', 'endpoint_path' => 'addresses', 'text_connected' => 'Egen text: ansluten.' ) );
Secrets::set( 'access_api_secret', 'upgrade-secret-123' );
add_filter( 'pre_http_request', static fn () => array(
	'headers'  => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array( 'content-type' => 'application/json' ) ),
	'body'     => wp_json_encode( array(
		array( 'streetName' => 'Ostra Langgatan', 'streetNumber' => '3', 'streetLittera' => '', 'accessState' => 'CONNECTED' ),
		array( 'streetName' => 'Storgatan', 'streetNumber' => '12', 'streetLittera' => 'B', 'accessState' => 'CONNECTED' ),
		array( 'streetName' => 'Storgatan', 'streetNumber' => '14', 'streetLittera' => '', 'accessState' => 'NOT_CONNECTED' ),
	) ),
	'response' => array( 'code' => 200, 'message' => 'OK' ),
	'cookies'  => array(),
	'filename' => null,
) );
$sync = ( new AccessService() )->sync( false );
update_option( 'wpfinet_instagram_settings', array_merge( (array) get_option( 'wpfinet_instagram_settings', array() ), array( 'limit' => 6, 'columns' => 3 ) ), false );
update_option( 'wpfinet_consent_settings', array_merge( (array) get_option( 'wpfinet_consent_settings', array() ), array( 'banner_title' => 'Cookies på finet.se' ) ), false );
echo $sync['ok'] ? "seeded\n" : 'seed failed: ' . $sync['message'] . "\n";
