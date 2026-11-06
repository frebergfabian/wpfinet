<?php
namespace WPFinet\Tests\Integration;

use FinetTroubleshoot\Admin\Settings;
use FinetTroubleshoot\Export\ExportManager;
use FinetTroubleshoot\Import\ImportManager;
use FinetTroubleshoot\Import\SchemaValidator;
use FinetTroubleshoot\Outages\OutageModule;
use FinetTroubleshoot\Validation\FlowValidator;
use WPFinet\Tests\TestCase;

/** Regression tests for the 2.0.1 bugs fixed in 3.0 (numbers refer to docs/fas-a/INVENTERING.md). */
final class BugfixTest extends TestCase {
	public function test_b1_saving_one_settings_tab_keeps_other_sections(): void {
		$before = get_option( 'finet_ts_settings' );
		$this->assertTrue( $before['outages']['banner_enabled'] );
		$this->assertTrue( $before['analytics']['enabled'] );
		$clean = ( new Settings() )->sanitize( array( '_tab' => 'support', 'support' => array( 'phone' => '010-123 45 67', 'email' => 'a@b.se' ) ) );
		$this->assertSame( '010-123 45 67', $clean['support']['phone'] );
		$this->assertTrue( $clean['outages']['banner_enabled'], 'banner must not be switched off' );
		$this->assertTrue( $clean['analytics']['enabled'], 'statistics must not be switched off' );
		$this->assertSame( $before['appearance'], $clean['appearance'] );
		$general = ( new Settings() )->sanitize( array( '_tab' => 'general', 'general' => array( 'show_title' => '1' ) ) );
		$this->assertFalse( $general['general']['show_back'], 'unchecked box on the posted tab is false' );
	}

	public function test_b3_status_update_defaults_to_current_status(): void {
		$id = $this->outage( array( '_wpfinet_outage_status' => 'monitoring' ) );
		ob_start();
		( new OutageModule() )->box_updates( get_post( $id ) );
		$html = (string) ob_get_clean();
		$this->assertMatchesRegularExpression( '/<option value="monitoring"\s+selected/', $html );
	}

	public function test_status_url_setting_is_used_for_outage_links(): void {
		$this->outage();
		$this->assertStringContainsString( home_url( '/driftstorningar/' ), do_shortcode( '[wpfinet_outage_status]' ), 'default keeps the 2.x link' );
		$settings = (array) get_option( 'finet_ts_settings', array() );
		$settings['outages']['status_url'] = home_url( '/drift/' );
		update_option( 'finet_ts_settings', $settings );
		$this->assertStringContainsString( home_url( '/drift/' ), do_shortcode( '[wpfinet_outage_status]' ) );
		unset( $settings['outages']['status_url'] );
		update_option( 'finet_ts_settings', $settings );
	}

	public function test_b4_status_shortcode_counts_more_than_two(): void {
		for ( $i = 0; $i < 4; $i++ ) { $this->outage(); }
		$this->assertStringContainsString( '4 aktuella driftstörningar', do_shortcode( '[wpfinet_outage_status]' ) );
	}

	public function test_b5_dangerous_urls_are_removed(): void {
		$node = ( new SchemaValidator( new FlowValidator() ) )->sanitize_node( array( 'id' => 'x', 'type' => 'support', 'title' => 'X', 'ctas' => array(
			array( 'id' => 'a', 'type' => 'support', 'label' => 'A', 'value' => 'javascript:alert(1)' ),
			array( 'id' => 'b', 'type' => 'url', 'label' => 'B', 'value' => '/kontakta-oss' ),
			array( 'id' => 'c', 'type' => 'internal', 'label' => 'C', 'value' => 'data:text/html,x' ),
		), 'support' => array( 'inherit_global' => false, 'url' => 'javascript:alert(2)' ) ) );
		$this->assertSame( '', $node['ctas'][0]['value'] );
		$this->assertSame( '/kontakta-oss', $node['ctas'][1]['value'] );
		$this->assertSame( '', $node['ctas'][2]['value'] );
		$this->assertSame( '', $node['support']['url'] );
		$this->assertSame( 'tel:+4610', SchemaValidator::safe_url( 'tel:+4610' ) );
	}

	public function test_b6_css_values_are_colours_only(): void {
		$this->assertSame( '#fff', SchemaValidator::css_color( '#fff' ) );
		$this->assertSame( 'rgb(1, 2, 3)', SchemaValidator::css_color( 'rgb(1, 2, 3)' ) );
		$this->assertSame( '', SchemaValidator::css_color( 'url(https://evil.example/x)' ) );
		$this->assertSame( '', SchemaValidator::css_color( 'red;background:url(x)' ) );
		$id = $this->import_example(); $this->publish( $id );
		update_post_meta( $id, '_finet_ts_settings', array( 'use_global_design' => false, 'appearance' => array( 'card_background' => '#f0f0f0', 'spacing' => 32 ) ) );
		$html = do_shortcode( '[finet_troubleshoot id="' . $id . '"]' );
		$this->assertStringContainsString( '--wpfinet-surface:#f0f0f0', $html );
		$this->assertStringContainsString( '--wpfinet-space:32px', $html );
	}

	public function test_b9_variables_roundtrip_and_b10_unique_slugs(): void {
		$doc = array( 'schema' => 'finet-troubleshoot', 'schema_version' => '2.0', 'guides' => array( array(
			'external_id' => 'vars-guide', 'title' => 'Vars', 'slug' => 'samma-slug', 'start_node' => 's', 'variables' => array( 'kundtyp' => 'privat', 'max' => 3 ),
			'nodes' => array( array( 'id' => 's', 'type' => 'result', 'result_type' => 'info', 'title' => 'S' ) ),
		) ) );
		$manager = new ImportManager( new FlowValidator() );
		$a = $manager->import_data( $doc, 'copy' )->to_array()['items'][0]['post_id'];
		$doc['guides'][0]['external_id'] = 'vars-guide-2';
		$b = $manager->import_data( $doc, 'copy' )->to_array()['items'][0]['post_id'];
		$this->created_posts[] = $a; $this->created_posts[] = $b;
		$this->assertNotSame( get_post_field( 'post_name', $a ), get_post_field( 'post_name', $b ) );
		$export = ( new ExportManager() )->export_guide( $a );
		$this->assertSame( array( 'kundtyp' => 'privat', 'max' => 3 ), $export['variables'] );
	}

	public function test_b21_export_can_omit_outages(): void {
		$id = $this->import_example();
		$this->outage();
		$this->login( 'administrator' );
		$with = $this->rest( 'GET', '/finet-troubleshoot/v1/export/' . $id )->get_data();
		$without = $this->rest( 'GET', '/finet-troubleshoot/v1/export/' . $id, array( 'include_outages' => '0' ) )->get_data();
		$this->assertNotEmpty( $with['outages'] );
		$this->assertSame( array(), $without['outages'] );
	}

	public function test_b24_cache_generation_invalidates_without_sql_flush(): void {
		$repo = new \FinetTroubleshoot\Outages\OutageRepository();
		$before = count( $repo->find( 'current', array(), 50 ) );
		$id = wp_insert_post( array( 'post_type' => 'wpfinet_outage', 'post_title' => 'Gen', 'post_status' => 'publish' ) );
		update_post_meta( $id, '_wpfinet_incident_start', wp_date( 'Y-m-d H:i:s', time() - 60 ) );
		$this->created_posts[] = $id;
		$repo->flush(); // No wp_cache_flush(): must work with a persistent object cache too.
		$this->assertSame( $before + 1, count( $repo->find( 'current', array(), 50 ) ) );
	}

	public function test_b12_imported_timeline_is_sanitised(): void {
		$clean = \FinetTroubleshoot\Outages\OutageExchange::sanitize_timeline( array( array( 'time' => '2026-01-01 10:00:00', 'text' => '<script>x</script>Hej', 'status' => 'bogus', 'author' => '7', 'public' => 1 ), 'junk' ) );
		$this->assertCount( 1, $clean );
		$this->assertSame( 'Hej', $clean[0]['text'] );
		$this->assertSame( '', $clean[0]['status'] );
		$this->assertSame( 7, $clean[0]['author'] );
		$this->assertTrue( $clean[0]['public'] );
	}

	public function test_b26_missing_settings_get_defaults(): void {
		$settings = ( new SchemaValidator( new FlowValidator() ) )->sanitize_settings( array() );
		$this->assertSame( 'step', $settings['general']['progress_mode'] );
		$this->assertSame( 'fade', $settings['general']['animation'] );
		$node = ( new SchemaValidator( new FlowValidator() ) )->sanitize_node( array( 'id' => 'a', 'type' => 'outage', 'title' => 'A', 'options' => array( array( 'id' => 'o', 'label' => 'L', 'next' => 'b' ) ) ) );
		$this->assertSame( 'show', $node['outage']['mode'] );
		$this->assertSame( 'primary', $node['options'][0]['style'] );
	}

	public function test_events_rate_limited_per_client(): void {
		$GLOBALS['wpfinet_test_consent'] = true;
		$provider = static fn () => true;
		$limit    = static fn () => 2;
		add_filter( 'wpfinet_consent_provider_available', $provider );
		add_filter( 'wpfinet_events_rate_limit', $limit );
		$_SERVER['REMOTE_ADDR'] = '203.0.113.' . wp_rand( 1, 250 );
		$codes = array();
		for ( $i = 0; $i < 3; $i++ ) { $codes[] = $this->rest( 'POST', '/wpfinet/v1/events', array( 'session' => wp_generate_uuid4(), 'event' => 'node_viewed' ) )->get_status(); }
		remove_filter( 'wpfinet_consent_provider_available', $provider );
		remove_filter( 'wpfinet_events_rate_limit', $limit );
		$GLOBALS['wpfinet_test_consent'] = false;
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$this->assertSame( array( 200, 200, 429 ), $codes );
	}
}
