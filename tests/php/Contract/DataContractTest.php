<?php
namespace WPFinet\Tests\Contract;

use WPFinet\Tests\TestCase;

/** Frozen data-layer contracts: CPT, meta, options, capabilities, tables, cron, admin slugs, analytics storage. */
final class DataContractTest extends TestCase {
	public function test_guide_post_type_and_meta(): void {
		$pt = get_post_type_object( 'finet_ts_guide' );
		$this->assertFalse( $pt->public );
		$this->assertTrue( $pt->show_ui );
		$this->assertTrue( $pt->show_in_rest );
		$this->assertFalse( $pt->rewrite );
		$meta = get_registered_meta_keys( 'post', 'finet_ts_guide' );
		foreach ( array( '_finet_ts_external_id', '_finet_ts_start_node', '_finet_ts_nodes', '_finet_ts_settings', '_finet_ts_schema_version' ) as $key ) {
			$this->assertArrayHasKey( $key, $meta, $key );
		}
	}

	public function test_options_capabilities_tables_cron(): void {
		$settings = get_option( 'finet_ts_settings' );
		foreach ( array( 'general', 'appearance', 'support', 'outages', 'analytics', 'advanced' ) as $section ) { $this->assertArrayHasKey( $section, $settings ); }
		$this->assertFalse( (bool) $settings['advanced']['delete_data'], 'delete_data must default to false' );
		$this->assertTrue( version_compare( (string) get_option( 'finet_ts_db_version' ), '2.0.0', '>=' ) );
		$admin = get_role( 'administrator' );
		foreach ( array( 'manage_wpfinet', 'manage_wpfinet_guides', 'publish_wpfinet_guides', 'manage_wpfinet_outages', 'publish_wpfinet_outages', 'view_wpfinet_statistics', 'manage_wpfinet_settings' ) as $cap ) {
			$this->assertTrue( $admin->has_cap( $cap ), $cap );
		}
		global $wpdb;
		foreach ( array( 'wpfinet_sessions', 'wpfinet_events' ) as $table ) {
			$this->assertSame( $wpdb->prefix . $table, $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->prefix . $table ) ) );
		}
		$this->assertNotFalse( wp_next_scheduled( 'wpfinet_daily_maintenance' ) );
		$this->assertTrue( (bool) get_option( 'finet_ts_demo_installed' ) );
	}

	public function test_admin_menu_slugs(): void {
		global $submenu, $menu, $_wp_submenu_nopriv, $_registered_pages;
		$this->login( 'administrator' );
		set_current_screen( 'dashboard' );
		$menu = array(); $submenu = array();
		do_action( 'admin_menu' );
		$slugs = wp_list_pluck( $submenu['wpfinet'] ?? array(), 2 );
		foreach ( array( 'wpfinet', 'edit.php?post_type=finet_ts_guide', 'post-new.php?post_type=finet_ts_guide', 'edit.php?post_type=wpfinet_outage', 'post-new.php?post_type=wpfinet_outage', 'wpfinet-statistics', 'finet-ts-import-export', 'finet-ts-import-format', 'finet-ts-settings' ) as $slug ) {
			$this->assertContains( $slug, $slugs, $slug );
		}
		$this->assertCount( 1, array_filter( $menu, static fn ( $m ) => 'wpfinet' === ( $m[2] ?? '' ) ), 'exactly one WPFinet top-level menu' );
	}

	public function test_events_are_stored_only_with_consent(): void {
		global $wpdb;
		$GLOBALS['wpfinet_test_consent'] = true;
		$provider = static fn () => true;
		add_filter( 'wpfinet_consent_provider_available', $provider );
		$session = wp_generate_uuid4();
		$res = $this->rest( 'POST', '/wpfinet/v1/events', array( 'events' => array(
			array( 'session' => $session, 'event' => 'guide_viewed', 'guide_id' => 5, 'node_id' => 'start', 'page_path' => '/hjalp/?q=1', 'device_class' => 'mobile' ),
			array( 'session' => $session, 'event' => 'answer_selected', 'guide_id' => 5, 'node_id' => 'start', 'option_id' => 'klart', 'page_path' => '/hjalp/' ),
			array( 'session' => $session, 'event' => 'result_reached', 'guide_id' => 5, 'node_id' => 'klart', 'page_path' => '/hjalp/#x' ),
			array( 'session' => 'not-a-uuid', 'event' => 'guide_viewed' ),
			array( 'session' => $session, 'event' => 'unknown_event' ),
		) ) );
		$GLOBALS['wpfinet_test_consent'] = false;
		remove_filter( 'wpfinet_consent_provider_available', $provider );
		$this->assertSame( array( 'accepted' => true, 'stored' => 3 ), $res->get_data() );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpfinet_sessions WHERE anonymous_session_id = %s", $session ), ARRAY_A );
		$this->assertSame( 'completed', $row['status'] );
		$this->assertSame( '/hjalp/', $row['page_path'] );
		$this->assertSame( '3', (string) $row['event_count'] );
		$this->assertSame( 'klart', $row['result_node'] );
		$this->assertSame( '3', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpfinet_events WHERE anonymous_session_id = %s", $session ) ) );
		$no = $this->rest( 'POST', '/wpfinet/v1/events', array( 'session' => wp_generate_uuid4(), 'event' => 'guide_viewed' ) );
		$this->assertFalse( $no->get_data()['accepted'] );
	}
}
