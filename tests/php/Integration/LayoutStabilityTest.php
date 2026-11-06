<?php
namespace WPFinet\Tests\Integration;

use FinetTroubleshoot\Core\Assets;
use FinetTroubleshoot\Core\Modules;
use FinetTroubleshoot\Frontend\GuideMarkup;
use FinetTroubleshoot\Frontend\HeadStyles;
use FinetTroubleshoot\Outages\OutageModule;
use WPFinet\Tests\TestCase;

/** 3.1.1: guide width/height stability and CLS – the server-side parts (browser parts: tests/e2e/elementor). */
final class LayoutStabilityTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['wp_styles']  = null;
		$GLOBALS['wp_scripts'] = null;
	}

	/** @param array<string,mixed> $query Main query vars, e.g. array( 'page_id' => 5 ). */
	private function front_end( array $query = array() ): void {
		$GLOBALS['wp_the_query'] = new \WP_Query();
		$GLOBALS['wp_query']     = $GLOBALS['wp_the_query'];
		$GLOBALS['wp_query']->query( $query );
		if ( isset( $GLOBALS['wp_query']->post ) ) { $GLOBALS['post'] = $GLOBALS['wp_query']->post; }
		do_action( 'wp_enqueue_scripts' );
	}

	public function test_banner_css_and_state_script_are_enqueued_before_head(): void {
		$this->outage( array(), array( 'post_title' => 'Fiberavbrott "Norr"' ) );
		$this->front_end();
		$this->assertTrue( wp_style_is( 'wpfinet-outages', 'enqueued' ), 'banner CSS is enqueued during wp_enqueue_scripts, i.e. printed in <head>' );
		$this->assertTrue( wp_script_is( 'wpfinet-banner-state', 'enqueued' ) );
		$this->assertFalse( (bool) wp_scripts()->get_data( 'wpfinet-banner-state', 'group' ), 'state script is a head script' );
		$inline = implode( '', (array) wp_scripts()->get_data( 'wpfinet-banner-state', 'after' ) );
		$this->assertMatchesRegularExpression( '/sessionStorage\.getItem\("wpfinet_banner_dismissed_[a-f0-9]{32}"\)/', $inline );
		$this->assertStringContainsString( 'wpfinet-banner-dismissed', $inline );
		ob_start(); wp_print_styles(); $head = (string) ob_get_clean();
		$this->assertStringContainsString( 'outages.css', $head );
	}

	public function test_no_banner_no_head_assets(): void {
		$this->front_end();
		$this->assertFalse( wp_style_is( 'wpfinet-outages', 'enqueued' ) );
		$this->assertFalse( wp_script_is( 'wpfinet-banner-state', 'enqueued' ) );
	}

	public function test_banner_markup_escapes_and_fallback_is_fixed_variant(): void {
		$data   = array( 'type' => 'acute', 'status' => 'Undersöks', 'title' => 'Avbrott <b>"x"</b>', 'url' => 'https://example.test/d/', 'version' => str_repeat( 'a', 32 ), 'more' => 2, 'list_url' => 'https://example.test/drift/' );
		$normal = OutageModule::banner_html( $data );
		$this->assertStringNotContainsString( '<b>', $normal );
		$this->assertStringContainsString( '+2 till', $normal );
		$this->assertStringContainsString( 'href="https://example.test/drift/"', $normal );
		$this->assertStringNotContainsString( 'wpfinet-banner--fallback', $normal );
		$fallback = OutageModule::banner_html( $data, true );
		$this->assertStringContainsString( 'wpfinet-banner--fallback', $fallback );
		$this->assertStringContainsString( 'data-wpfinet-banner-fallback', $fallback );
		$this->assertStringContainsString( '(function(){try{', OutageModule::state_script( 'abc"; alert(1); "' ) );
		$this->assertStringNotContainsString( 'alert', OutageModule::state_script( 'abc"; alert(1); "' ), 'version is reduced to hex' );
	}

	public function test_guide_is_server_rendered_with_sizer(): void {
		$id   = $this->import_example();
		$this->publish( $id );
		$html = do_shortcode( '[finet_troubleshoot id="' . $id . '"]' );
		$this->assertMatchesRegularExpression( '#<div class="finet-troubleshoot__mount"[^>]*><div class="wpfinet-guide" data-ssr="[^"]+">#', $html );
		$this->assertStringContainsString( '<div class="wpfinet-guide__sizer" aria-hidden="true" inert>', $html );
		$this->assertSame( 1, preg_match_all( '/data-node="/', $html ), 'exactly one visible step' );
		$this->assertSame( 1, substr_count( $html, 'finet-troubleshoot__data' ), '2.x contract: one data script per instance' );
		$this->assertStringContainsString( 'data-back disabled aria-hidden="true" tabindex="-1"', $html );
	}

	public function test_guide_markup_escapes_attributes(): void {
		$data = array( 'title' => 'A "quoted" <title>', 'start_node' => 's', 'settings' => array( 'general' => array( 'progress_mode' => 'bar' ) ), 'nodes' => array( array( 'id' => 's', 'type' => 'question', 'heading' => '<img src=x onerror=alert(1)>', 'options' => array( array( 'label' => '"><script>', 'next' => 'a"b' ) ) ), array( 'id' => 'a"b', 'type' => 'result' ) ) );
		$html = GuideMarkup::render( $data );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<img src=x', $html );
		$this->assertStringContainsString( 'aria-label="A &quot;quoted&quot; &lt;title&gt;"', $html );
		$this->assertStringContainsString( 'data-next="a&quot;b"', $html );
		$this->assertStringContainsString( 'Steg 1 av 2', $html );
	}

	public function test_head_styles_detect_shortcodes_in_content_and_elementor_data(): void {
		$id   = $this->import_example();
		$this->publish( $id );
		$page = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Elementor', 'post_content' => '' ) );
		$this->created_posts[] = $page;
		update_post_meta( $page, '_elementor_data', wp_slash( wp_json_encode( array( array( 'elType' => 'widget', 'widgetType' => 'shortcode', 'settings' => array( 'shortcode' => '[finet_troubleshoot id="' . $id . '"]' ) ) ) ) ) );
		$this->front_end( array( 'page_id' => $page ) );
		$this->assertTrue( is_singular() );
		$this->assertContains( 'finet-troubleshoot', HeadStyles::handles_for( HeadStyles::page_source() ) );
		$this->assertTrue( wp_style_is( 'finet-troubleshoot', 'enqueued' ), 'guide CSS in <head> for an Elementor page' );
		$this->assertSame( array(), HeadStyles::handles_for( '[wpfinet_instagram_feed]' ), 'disabled module: no stylesheet' );
		Modules::update( array( 'instagram' => true ) );
		$this->assertSame( array( 'wpfinet-instagram' ), HeadStyles::handles_for( '<!-- wp:wpfinet/instagram-feed /-->' ) );
		Modules::update( array( 'instagram' => false ) );
	}

	public function test_asset_versions_change_with_the_file(): void {
		$ver = Assets::ver( 'frontend.css' );
		$this->assertStringStartsWith( FINET_TS_VERSION . '.', $ver );
		$this->assertSame( FINET_TS_VERSION . '.' . filemtime( FINET_TS_DIR . 'assets/build/frontend.css' ), $ver );
		$this->assertSame( FINET_TS_VERSION, Assets::ver( 'missing.css' ) );
		$this->front_end();
		$this->assertSame( $ver, wp_styles()->registered['finet-troubleshoot']->ver );
	}

	public function test_frontend_css_has_no_size_containment_or_anywhere_breaks(): void {
		$css = (string) file_get_contents( FINET_TS_DIR . 'assets/build/frontend.css' );
		$code = (string) preg_replace( '#/\*.*?\*/#s', '', $css );
		$this->assertStringNotContainsString( 'container-type', $code, 'size containment made the guide collapse to 0 px in shrink-to-fit containers (3.1.0)' );
		$this->assertStringNotContainsString( 'overflow-wrap: anywhere', $code, 'anywhere lets the browser break between letters' );
		$this->assertMatchesRegularExpression( '/\.finet-troubleshoot \{[^}]*width: 100%;[^}]*max-width: var\(--wpfinet-max-width\)/s', $code );
	}
}
