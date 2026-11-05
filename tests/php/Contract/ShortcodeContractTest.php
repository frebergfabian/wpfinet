<?php
namespace WPFinet\Tests\Contract;

use WPFinet\Tests\TestCase;

/** Frozen 2.0.1 behaviour of [finet_troubleshoot]. */
final class ShortcodeContractTest extends TestCase {
	private function payload( string $html ): array {
		$this->assertMatchesRegularExpression( '#<script type="application/json" class="finet-troubleshoot__data">(.*?)</script>#s', $html );
		preg_match( '#<script type="application/json" class="finet-troubleshoot__data">(.*?)</script>#s', $html, $m );
		$data = json_decode( $m[1], true );
		$this->assertIsArray( $data );
		return $data;
	}

	public function test_shortcodes_are_registered(): void {
		foreach ( array( 'finet_troubleshoot', 'wpfinet_outages', 'wpfinet_outage_status' ) as $tag ) {
			$this->assertTrue( shortcode_exists( $tag ), $tag );
		}
	}

	public function test_render_by_id_has_stable_markup_and_payload(): void {
		$id = $this->import_example();
		$this->publish( $id );
		$html = do_shortcode( '[finet_troubleshoot id="' . $id . '"]' );
		$this->assertStringContainsString( 'data-finet-troubleshoot', $html );
		$this->assertMatchesRegularExpression( '/id="finet-ts-instance-' . $id . '-\d+"/', $html );
		$this->assertMatchesRegularExpression( '/class="finet-troubleshoot finet-troubleshoot--[a-z]*[" ]/', $html );
		$this->assertStringContainsString( 'finet-troubleshoot__mount', $html );
		$data = $this->payload( $html );
		foreach ( array( 'guide_id', 'guide_external_id', 'title', 'description', 'start_node', 'nodes', 'settings', 'support', 'analytics', 'outages_endpoint' ) as $key ) {
			$this->assertArrayHasKey( $key, $data, $key );
		}
		$this->assertSame( $id, $data['guide_id'] );
		$this->assertSame( 'start-om-router', $data['start_node'] );
		$this->assertCount( 10, $data['nodes'] );
		foreach ( array( 'enabled', 'service', 'category', 'endpoint' ) as $key ) { $this->assertArrayHasKey( $key, $data['analytics'] ); }
		$this->assertSame( 'wpfinet-analytics', $data['analytics']['service'] );
		$this->assertSame( 'statistics', $data['analytics']['category'] );
		$this->assertStringContainsString( 'wpfinet/v1/events', $data['analytics']['endpoint'] );
		$this->assertStringContainsString( 'wpfinet/v1/outages/current', $data['outages_endpoint'] );
		foreach ( $data['nodes'] as $node ) {
			$this->assertArrayNotHasKey( 'admin_comment', $node );
			$this->assertArrayHasKey( 'form_html', $node );
			$this->assertArrayHasKey( 'cta_forms', $node );
		}
		$this->assertTrue( wp_script_is( 'finet-troubleshoot', 'enqueued' ) );
		$this->assertTrue( wp_style_is( 'finet-troubleshoot', 'enqueued' ) );
	}

	public function test_render_by_slug_and_attribute_overrides(): void {
		$id   = $this->import_example();
		$this->publish( $id );
		$slug = get_post_field( 'post_name', $id );
		$html = do_shortcode( '[finet_troubleshoot slug="' . $slug . '" show_title="false" show_restart="no" class="my-class"]' );
		$data = $this->payload( $html );
		$this->assertSame( $id, $data['guide_id'] );
		$this->assertFalse( $data['settings']['general']['show_title'] );
		$this->assertFalse( $data['settings']['general']['show_restart'] );
		$this->assertStringContainsString( 'my-class', $html );
	}

	public function test_id_wins_over_slug(): void {
		$a = $this->import_example(); $this->publish( $a );
		$b = $this->import_example(); $this->publish( $b );
		$data = $this->payload( do_shortcode( '[finet_troubleshoot id="' . $a . '" slug="' . get_post_field( 'post_name', $b ) . '"]' ) );
		$this->assertSame( $a, $data['guide_id'] );
	}

	public function test_unpublished_or_missing_guide_shows_error(): void {
		$id = $this->import_example(); // Imports are drafts.
		$this->assertSame( 'draft', get_post_status( $id ) );
		$html = do_shortcode( '[finet_troubleshoot id="' . $id . '"]' );
		$this->assertStringContainsString( 'finet-troubleshoot__error', $html );
		$this->assertStringNotContainsString( 'finet-troubleshoot__data', $html );
		$this->assertStringContainsString( 'finet-troubleshoot__error', do_shortcode( '[finet_troubleshoot slug="finns-inte-alls"]' ) );
		$this->login( 'editor' );
		$this->assertStringContainsString( 'Guide ID 999999', do_shortcode( '[finet_troubleshoot id="999999"]' ) );
	}

	public function test_multiple_instances_have_unique_ids(): void {
		$id = $this->import_example(); $this->publish( $id );
		$html = do_shortcode( '[finet_troubleshoot id="' . $id . '"][finet_troubleshoot id="' . $id . '"]' );
		preg_match_all( '/id="(finet-ts-instance-[^"]+)"/', $html, $m );
		$this->assertCount( 2, $m[1] );
		$this->assertNotSame( $m[1][0], $m[1][1] );
	}

	public function test_render_hooks_and_data_filter(): void {
		$id = $this->import_example(); $this->publish( $id );
		$calls = array();
		$before = static function ( $post_id, $data ) use ( &$calls ) { $calls[] = array( 'before', $post_id, is_array( $data ) ); };
		$after  = static function ( $post_id, $data ) use ( &$calls ) { $calls[] = array( 'after', $post_id, is_array( $data ) ); };
		$filter = static function ( $data, $post_id ) { $data['title'] = 'Filtrerad ' . $post_id; return $data; };
		add_action( 'finet_troubleshoot_before_render', $before, 10, 2 );
		add_action( 'finet_troubleshoot_after_render', $after, 10, 2 );
		add_filter( 'finet_troubleshoot_guide_data', $filter, 10, 2 );
		$data = $this->payload( do_shortcode( '[finet_troubleshoot id="' . $id . '"]' ) );
		remove_action( 'finet_troubleshoot_before_render', $before, 10 );
		remove_action( 'finet_troubleshoot_after_render', $after, 10 );
		remove_filter( 'finet_troubleshoot_guide_data', $filter, 10 );
		$this->assertSame( 'Filtrerad ' . $id, $data['title'] );
		$this->assertSame( array( array( 'before', $id, true ), array( 'after', $id, true ) ), $calls );
	}
}
