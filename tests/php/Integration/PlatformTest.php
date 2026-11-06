<?php
namespace WPFinet\Tests\Integration;

use FinetTroubleshoot\Consent\ConsentService;
use FinetTroubleshoot\Core\ClientIp;
use FinetTroubleshoot\Core\Logger;
use FinetTroubleshoot\Core\MigrationManager;
use FinetTroubleshoot\Core\Modules;
use FinetTroubleshoot\Core\Secrets;
use FinetTroubleshoot\Migration\LegacyScanner;
use WPFinet\Tests\TestCase;

/** 3.0 platform: migration, consent resolution, secrets, scanner, admin screens. */
final class PlatformTest extends TestCase {
	public function test_migration_is_idempotent_and_non_destructive(): void {
		$id      = $this->import_example();
		$nodes   = get_post_meta( $id, '_finet_ts_nodes', true );
		$counts  = MigrationManager::counts();
		update_option( 'finet_ts_db_version', '2.0.0', false );
		$this->assertTrue( MigrationManager::needs_migration() );
		$first  = ( new MigrationManager() )->run();
		$second = ( new MigrationManager() )->run();
		$this->assertSame( \FinetTroubleshoot\Core\MigrationManager::VERSION, get_option( 'finet_ts_db_version' ) );
		$this->assertFalse( MigrationManager::needs_migration() );
		$this->assertSame( $counts, MigrationManager::counts() );
		$this->assertSame( $nodes, get_post_meta( $id, '_finet_ts_nodes', true ) );
		$this->assertStringContainsString( 'utan borttagningar', implode( "\n", $first ) );
		$this->assertStringContainsString( 'utan borttagningar', implode( "\n", $second ) );
	}

	public function test_activation_on_existing_install_never_recreates_demo(): void {
		$guides = get_posts( array( 'post_type' => 'finet_ts_guide', 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1 ) );
		foreach ( $guides as $g ) { wp_trash_post( $g ); }
		delete_option( 'finet_ts_demo_installed' );
		\FinetTroubleshoot\Activator::activate();
		$after = get_posts( array( 'post_type' => 'finet_ts_guide', 'post_status' => array( 'publish', 'draft' ), 'fields' => 'ids', 'posts_per_page' => -1 ) );
		foreach ( $guides as $g ) { wp_untrash_post( $g ); }
		$this->assertSame( array(), $after, 'demo guide must not be recreated on reactivation/upgrade' );
		$this->assertSame( \FinetTroubleshoot\Core\MigrationManager::VERSION, get_option( 'finet_ts_db_version' ) );
	}

	public function test_consent_wp_consent_api_without_cmp_is_not_consent(): void {
		$GLOBALS['wpfinet_test_consent'] = true; // wp_has_consent() double returns true, like WP Consent API without a CMP.
		$this->assertFalse( ConsentService::instance()->provider_available() );
		$this->assertFalse( ConsentService::instance()->has( 'statistics' ) );
		$this->assertTrue( ConsentService::instance()->has( 'necessary' ) );
		$GLOBALS['wpfinet_test_consent'] = false;
	}

	public function test_consent_internal_cookie_in_preview_and_live(): void {
		$_COOKIE['wpfinet_consent'] = rawurlencode( wp_json_encode( array( 'v' => 1, 'id' => wp_generate_uuid4(), 't' => time(), 'p' => '1', 'c' => array( 'statistics' => true, 'marketing' => false ) ) ) );
		Modules::update( array( 'consent' => 'preview' ) );
		$this->assertFalse( ConsentService::instance()->has( 'statistics' ), 'anonymous visitor: preview mode is inactive' );
		$this->login( 'administrator' );
		$this->assertTrue( ConsentService::instance()->has( 'statistics' ) );
		$this->assertFalse( ConsentService::instance()->has( 'marketing' ) );
		$this->assertSame( 'wpfinet', ConsentService::instance()->provider() );
		wp_set_current_user( 0 );
		Modules::update( array( 'consent' => 'live' ) );
		$this->assertTrue( ConsentService::instance()->has( 'statistics' ) );
		$_COOKIE['wpfinet_consent'] = 'garbage';
		$this->assertFalse( ConsentService::instance()->has( 'statistics' ) );
		unset( $_COOKIE['wpfinet_consent'] );
		Modules::update( array( 'consent' => 'off' ) );
	}

	public function test_secrets_are_encrypted_at_rest(): void {
		$this->assertTrue( Secrets::set( 'access_api_secret', 'super-hemligt-värde' ) );
		$raw = get_option( Secrets::OPTION );
		$this->assertStringNotContainsString( 'super-hemligt', wp_json_encode( $raw ) );
		$this->assertSame( 'super-hemligt-värde', Secrets::get( 'access_api_secret' ) );
		$this->assertSame( 'database', Secrets::source( 'access_api_secret' ) );
		Secrets::set( 'access_api_secret', '' );
		$this->assertSame( 'none', Secrets::source( 'access_api_secret' ) );
		$this->assertFalse( Secrets::set( 'unknown', 'x' ) );
		$this->assertSame( 'no', $GLOBALS['wpdb']->get_var( "SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name = 'wpfinet_secrets'" ) === 'off' ? 'no' : ( $GLOBALS['wpdb']->get_var( "SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name = 'wpfinet_secrets'" ) === 'no' ? 'no' : 'yes' ) );
	}

	public function test_logger_redacts(): void {
		Logger::log( 'test', 'info', 'Fel för kund@example.se med token=abc123 och Bearer eyJhbGciOiJIUzI1NiJ9xyz' );
		$entry = Logger::entries( 'test' )[0];
		$this->assertStringNotContainsString( 'kund@example.se', $entry['message'] );
		$this->assertStringNotContainsString( 'abc123', $entry['message'] );
		$this->assertStringNotContainsString( 'eyJhbGci', $entry['message'] );
	}

	public function test_client_ip_trusts_cloudflare_header_only_from_cloudflare(): void {
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.7';
		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
		$this->assertSame( '203.0.113.5', ClientIp::resolve( true ) );
		$_SERVER['REMOTE_ADDR'] = '172.70.1.1'; // 172.64.0.0/13
		$this->assertSame( '198.51.100.7', ClientIp::resolve( true ) );
		$this->assertSame( '172.70.1.1', ClientIp::resolve( false ) );
		$this->assertTrue( ClientIp::in_cidr( '2606:4700::1', '2606:4700::/32' ) );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		unset( $_SERVER['HTTP_CF_CONNECTING_IP'] );
	}

	public function test_legacy_scanner_finds_content_and_elementor_references(): void {
		$guide = $this->import_example(); $this->publish( $guide );
		$draft = $this->import_example();
		$page  = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Adress', 'post_content' => "[finet_troubleshoot id=\"$guide\"] [finet_troubleshoot id=\"$draft\"] [finet_troubleshoot slug=\"finns-inte\"] [wpgetapi_endpoint api_id='finet_access' endpoint_id='get_access_data' debug='false']" ) );
		$el    = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'draft', 'post_title' => 'Elementor', 'post_content' => '' ) );
		update_post_meta( $el, '_elementor_data', wp_slash( wp_json_encode( array( array( 'elType' => 'widget', 'settings' => array( 'html' => '<input id="addressInput"><div id="finetApiRaw"></div><script>function checkAddress(){}</script>', 'shortcode' => '[wpfinet_outages status="current"]' ) ) ) ) ) );
		$this->created_posts[] = $page; $this->created_posts[] = $el;
		$report = ( new LegacyScanner() )->scan( false );
		$this->assertSame( 3, $report['summary']['finet_troubleshoot']['occurrences'] );
		$this->assertSame( 1, $report['summary']['wpgetapi_endpoint']['locations'] );
		$this->assertSame( 1, $report['summary']['legacy_address_js']['locations'] );
		$this->assertSame( '_elementor_data', $report['findings']['legacy_address_js'][0]['source'] );
		$this->assertSame( 1, $report['summary']['wpfinet_outages']['locations'] );
		$problems = wp_list_pluck( $report['broken_guide_refs'], 'problem' );
		$this->assertContains( 'Guiden finns inte', $problems );
		$this->assertContains( 'Guiden är inte publicerad (draft)', $problems );
		$this->assertCount( 2, $problems );
		$this->assertGreaterThanOrEqual( 2, $report['unresolved_legacy'] );
		$this->assertArrayHasKey( 'delete_data_on_uninstall', $report['settings'] );
	}

	public function test_admin_screens_render_for_admin(): void {
		$this->login( 'administrator' );
		set_current_screen( 'toplevel_page_wpfinet' );
		$app = new \FinetTroubleshoot\Admin\AdminApp();
		foreach ( array( 'integrations_page', 'diagnostics_page' ) as $method ) {
			ob_start();
			$app->$method();
			$html = (string) ob_get_clean();
			$this->assertStringContainsString( 'wpf-page', $html, $method );
		}
		ob_start();
		$_GET['page'] = 'wpfinet';
		$app->shell_header();
		$header = (string) ob_get_clean();
		unset( $_GET['page'] );
		$this->assertStringContainsString( 'aria-current="page"', $header );
	}

	public function test_strict_capabilities_map_post_types(): void {
		Modules::update( array( 'strict_capabilities' => true ) );
		$args = ( new \FinetTroubleshoot\Core\StrictCapabilities() )->post_type_args( array( 'capability_type' => 'post' ), 'wpfinet_outage' );
		$this->assertSame( array( 'wpfinet_outage', 'wpfinet_outages' ), $args['capability_type'] );
		$caps = ( new \FinetTroubleshoot\Core\StrictCapabilities() )->grant_primitives( array( 'manage_wpfinet_outages' => true ) );
		$this->assertTrue( $caps['edit_others_wpfinet_outages'] );
		$this->assertArrayNotHasKey( 'publish_wpfinet_outages', $caps, 'publishing still requires publish_wpfinet_outages' );
		$this->login( 'contributor' );
		$this->assertFalse( \FinetTroubleshoot\Core\StrictCapabilities::can_edit_guides() );
		Modules::update( array( 'strict_capabilities' => false ) );
		$this->assertTrue( \FinetTroubleshoot\Core\StrictCapabilities::can_edit_guides(), 'default keeps 2.0 behaviour' );
	}

	public function test_uninstall_deletes_nothing_by_default(): void {
		$id = $this->import_example();
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) { define( 'WP_UNINSTALL_PLUGIN', 'finet-troubleshoot/finet-troubleshoot.php' ); }
		include WP_PLUGIN_DIR . '/finet-troubleshoot/uninstall.php';
		$this->assertNotNull( get_post( $id ) );
		$this->assertNotFalse( get_option( 'finet_ts_settings' ) );
		global $wpdb;
		$this->assertSame( $wpdb->prefix . 'wpfinet_events', $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . 'wpfinet_events' ) ) );
	}
}
