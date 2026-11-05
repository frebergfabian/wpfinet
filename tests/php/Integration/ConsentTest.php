<?php
namespace WPFinet\Tests\Integration;

use FinetTroubleshoot\Consent\Blocker;
use FinetTroubleshoot\Consent\ConsentModule;
use FinetTroubleshoot\Consent\ConsentSettings;
use FinetTroubleshoot\Consent\Inventory;
use FinetTroubleshoot\Consent\Policy;
use FinetTroubleshoot\Consent\ScanReceiver;
use FinetTroubleshoot\Core\Modules;
use WPFinet\Tests\TestCase;

final class ConsentTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		global $wpdb;
		Inventory::install();
		$wpdb->query( 'DELETE FROM ' . Inventory::table() );
		$wpdb->query( 'DELETE FROM ' . Inventory::log_table() );
		foreach ( array( ConsentSettings::OPTION, Policy::OPTION, ScanReceiver::OPTION ) as $o ) { delete_option( $o ); }
	}

	protected function tearDown(): void {
		Modules::update( array( 'consent' => 'off' ) );
		parent::tearDown();
	}

	public function test_blocker_rewrites_trackers_and_keeps_others(): void {
		$html = implode( "\n", array(
			'<script src="https://www.googletagmanager.com/gtag/js?id=G-1" async></script>',
			"<script>window.dataLayer=[];function gtag(){dataLayer.push(arguments)}gtag('js',new Date());</script>",
			'<script type="application/ld+json">{"gtag(":1}</script>',
			'<script src="/wp-includes/js/jquery/jquery.min.js"></script>',
			'<script>console.log("ok")</script>',
			'<iframe src="https://www.youtube.com/embed/abc" width="560"></iframe>',
			'<iframe src="https://example.org/widget"></iframe>',
			'<noscript><img height="1" src="https://www.facebook.com/tr?id=1&ev=PageView&noscript=1"></noscript>',
			'<script type="module" src="https://connect.facebook.net/en_US/fbevents.js"></script>',
		) );
		$blocker = new Blocker( array_merge( ConsentSettings::defaults(), array( 'rules' => ConsentSettings::defaults()['rules'] . "\nfacebook.com/tr | marketing | Meta Pixel" ) ) );
		$out = $blocker->process( $html );
		$this->assertStringContainsString( '<script type="text/plain" data-wpfinet-consent="statistics" data-wpfinet-src="https://www.googletagmanager.com/gtag/js?id=G-1" async></script>', $out );
		$this->assertMatchesRegularExpression( '#<script type="text/plain" data-wpfinet-consent="statistics">window\.dataLayer#', $out );
		$this->assertStringContainsString( '<script type="application/ld+json">', $out, 'JSON data untouched' );
		$this->assertStringContainsString( '<script src="/wp-includes/js/jquery/jquery.min.js"></script>', $out );
		$this->assertStringContainsString( '<script>console.log("ok")</script>', $out );
		$this->assertStringContainsString( '<iframe data-wpfinet-consent="marketing" hidden data-wpfinet-src="https://www.youtube.com/embed/abc" width="560"></iframe>', $out );
		$this->assertStringContainsString( 'data-wpfinet-placeholder="marketing"', $out );
		$this->assertStringContainsString( '<iframe src="https://example.org/widget"></iframe>', $out );
		$this->assertStringContainsString( 'data-wpfinet-blocked-src="https://www.facebook.com/tr', $out );
		$this->assertStringContainsString( 'data-wpfinet-type="module"', $out );
		$this->assertDoesNotMatchRegularExpression( '#<(script|iframe|img)[^>]*\ssrc="https://(www\.googletagmanager|www\.youtube|www\.facebook|connect\.facebook)#', $out, 'no tracker keeps an active src' );
		$this->assertSame( $out, $blocker->process( $out ), 'idempotent' );
		$this->assertSame( 5, $blocker->blocked_count() );
	}

	public function test_blocker_handle_rules_for_enqueued_scripts(): void {
		$blocker = new Blocker( array_merge( ConsentSettings::defaults(), array( 'handle_rules' => 'my-analytics | statistics' ) ) );
		$tag = $blocker->script_tag( "<script src='https://cdn.example/a.js' id='my-analytics-js'></script>", 'my-analytics', 'https://cdn.example/a.js' );
		$this->assertStringContainsString( 'type="text/plain" data-wpfinet-consent="statistics"', $tag );
		$this->assertStringContainsString( "data-wpfinet-src='https://cdn.example/a.js'", $tag );
		$this->assertSame( "<script src='/x.js'></script>", $blocker->script_tag( "<script src='/x.js'></script>", 'other', '/x.js' ) );
	}

	public function test_inventory_suggests_but_never_verifies_automatically(): void {
		$this->assertSame( 'new', Inventory::observe( array( 'name' => '_ga', 'domain' => '.example.test', 'before_consent' => true, 'page' => 'https://example.test/' ) ) );
		$this->assertSame( 'seen', Inventory::observe( array( 'name' => '_ga', 'domain' => '.example.test', 'page' => 'https://example.test/b/' ) ) );
		$row = Inventory::all()[0];
		$this->assertSame( 'suggested', $row['status'] );
		$this->assertSame( '', $row['category'] );
		$this->assertSame( 'statistics', $row['suggested_category'] );
		$this->assertSame( 'Google Analytics', $row['provider'] );
		$this->assertSame( 1, Inventory::counts()['leaks'] );
		Inventory::update_item( (int) $row['id'], array( 'status' => 'verified' ) );
		$this->assertSame( 'suggested', Inventory::get( (int) $row['id'] )['status'], 'cannot verify without category' );
		Inventory::update_item( (int) $row['id'], array( 'status' => 'verified', 'category' => 'statistics' ) );
		$this->assertSame( 'verified', Inventory::get( (int) $row['id'] )['status'] );
		Inventory::observe( array( 'name' => '_ga', 'domain' => '.example.test' ) );
		$this->assertSame( 'statistics', Inventory::get( (int) $row['id'] )['category'], 'a rescan never overwrites human decisions' );
	}

	public function test_scan_ingest_creates_suggestions_and_diff(): void {
		$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$first = ScanReceiver::ingest( array( 'pages' => array( home_url( '/' ) ), 'scenarios' => array( 'before_consent', 'accept_all' ), 'observations' => array(
			array( 'name' => 'wpfinet_consent', 'domain' => $home, 'storage_type' => 'cookie', 'scenario' => 'accept_all' ),
			array( 'name' => '_fbp', 'domain' => $home, 'storage_type' => 'cookie', 'scenario' => 'before_consent' ),
			array( 'name' => 'yt-player', 'domain' => 'www.youtube.com', 'storage_type' => 'localStorage', 'scenario' => 'accept_all' ),
		), 'requests' => array( array( 'host' => 'www.googletagmanager.com', 'scenario' => 'before_consent' ), array( 'host' => $home, 'scenario' => 'before_consent' ) ) ) );
		$this->assertSame( 3, $first['new'] + 0 );
		$names = array_column( Inventory::all(), 'party', 'name' );
		$this->assertSame( 'first', $names['_fbp'] );
		$this->assertSame( 'third', $names['yt-player'] );
		$this->assertSame( 'third', $names['www.googletagmanager.com'] );
		$this->assertArrayNotHasKey( $home, $names, 'own host is not a third-party service' );
		$this->assertSame( 2, Inventory::counts()['leaks'], '_fbp and GTM before consent' );
		$second = ScanReceiver::ingest( array( 'observations' => array( array( 'name' => 'wpfinet_consent', 'domain' => $home, 'scenario' => 'accept_all' ) ) ) );
		$this->assertSame( 2, $second['disappeared'] );
	}

	public function test_policy_is_public_only_after_approval_and_frozen(): void {
		Inventory::observe( array( 'name' => '_ga', 'domain' => 'example.test' ) );
		$id = (int) Inventory::all()[0]['id'];
		Inventory::update_item( $id, array( 'category' => 'statistics', 'status' => 'verified', 'purpose' => 'Mäter besök.' ) );
		$this->assertSame( '', Policy::render( false ), 'visitors see nothing before approval' );
		$v = Policy::approve( 1, 'Ärende 123' );
		$this->assertSame( '1', $v['version'] );
		Inventory::update_item( $id, array( 'purpose' => 'Ändrat efteråt.' ) );
		$html = Policy::render( false );
		$this->assertStringContainsString( 'Mäter besök.', $html, 'published version is a frozen snapshot' );
		$this->assertStringNotContainsString( 'Ändrat efteråt.', $html );
		$this->assertStringContainsString( 'data-wpfinet-consent-open', $html );
		$this->assertStringContainsString( 'Version 1', $html );
		$this->assertStringContainsString( 'Ändrat efteråt.', Policy::render( true ) );
	}

	public function test_consent_log_is_anonymous_and_validated(): void {
		Modules::update( array( 'consent' => 'live' ) );
		$module = new ConsentModule();
		add_action( 'rest_api_init', array( $module, 'routes' ) );
		do_action( 'rest_api_init', rest_get_server() );
		$_SERVER['REMOTE_ADDR'] = '192.0.2.' . wp_rand( 1, 250 );
		$ok = $this->rest( 'POST', '/wpfinet/v1/consent/log', array( 'id' => wp_generate_uuid4(), 'action' => 'custom', 'categories' => array( 'statistics', 'evil' ), 'policy' => '1' ) );
		$this->assertSame( 201, $ok->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', '/wpfinet/v1/consent/log', array( 'id' => 'nope', 'action' => 'custom' ) )->get_status() );
		global $wpdb;
		$row = $wpdb->get_row( 'SELECT * FROM ' . Inventory::log_table(), ARRAY_A );
		$this->assertSame( 'statistics', $row['categories'] );
		$this->assertSame( array( 'id', 'consent_id', 'created_at', 'action', 'categories', 'policy_version' ), array_keys( $row ), 'no IP or user agent column' );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
	}

	public function test_banner_markup_has_equal_choices_and_no_precheck(): void {
		Modules::update( array( 'consent' => 'live' ) );
		ob_start();
		( new ConsentModule() )->banner();
		$html = (string) ob_get_clean();
		foreach ( array( 'accept', 'reject', 'customize' ) as $a ) { $this->assertSame( 1, preg_match_all( '#<div class="wpfinet-consent__banner".*?data-action="' . $a . '"#s', $html ), $a ); }
		preg_match( '#<div class="wpfinet-consent__actions">(.*?)</div>#s', $html, $m );
		$this->assertSame( 3, substr_count( $m[1], '<button type="button" data-action=' ), 'three buttons with identical markup/class' );
		$this->assertSame( 1, substr_count( $html, 'checked' ), 'only "necessary" is checked' );
		$this->assertStringContainsString( 'checked disabled', $html );
		$this->assertStringContainsString( 'data-wpfinet-consent-open', $html, 'footer settings link' );
		$this->assertStringNotContainsString( '<script', $html, 'config is a data attribute, not inline script' );
	}

	public function test_preview_mode_only_for_admins(): void {
		Modules::update( array( 'consent' => 'preview' ) );
		$this->assertFalse( \FinetTroubleshoot\Consent\ConsentService::instance()->internal_active() );
		$this->login( 'editor' );
		$this->assertFalse( \FinetTroubleshoot\Consent\ConsentService::instance()->internal_active() );
		$this->login( 'administrator' );
		$this->assertTrue( \FinetTroubleshoot\Consent\ConsentService::instance()->internal_active() );
	}

	public function test_admin_tabs_render(): void {
		$this->login( 'administrator' );
		foreach ( array( 'overview', 'inventory', 'blocking', 'banner', 'policy', 'scan' ) as $tab ) {
			$_GET['tab'] = $tab;
			ob_start();
			\FinetTroubleshoot\Consent\ConsentAdmin::render();
			$html = (string) ob_get_clean();
			$this->assertStringContainsString( 'nav-tab-active', $html, $tab );
		}
		unset( $_GET['tab'] );
		$this->assertStringContainsString( 'påstår inte', $html );
	}
}
