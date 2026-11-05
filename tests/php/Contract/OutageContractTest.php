<?php
namespace WPFinet\Tests\Contract;

use WPFinet\Tests\TestCase;

/** Frozen 2.0.1 behaviour of outages: data, URLs, shortcodes, banner, public REST. */
final class OutageContractTest extends TestCase {
	public function test_post_type_and_taxonomies(): void {
		$pt = get_post_type_object( 'wpfinet_outage' );
		$this->assertTrue( $pt->public );
		$this->assertTrue( $pt->show_in_rest );
		$this->assertSame( 'driftstorningar', $pt->has_archive );
		$this->assertSame( 'driftstorningar', $pt->rewrite['slug'] );
		foreach ( array( 'wpfinet_provider', 'wpfinet_service', 'wpfinet_area' ) as $tax ) {
			$t = get_taxonomy( $tax );
			$this->assertNotFalse( $t, $tax );
			$this->assertTrue( $t->hierarchical );
			$this->assertContains( 'wpfinet_outage', $t->object_type );
		}
	}

	public function test_dated_permalink_and_rewrite_rules(): void {
		$id = $this->outage( array(), array( 'post_name' => 'fiberavbrott-test', 'post_date' => '2026-03-04 10:00:00' ) );
		$this->assertSame( home_url( '/driftstorningar/2026/03/04/fiberavbrott-test/' ), get_permalink( $id ) );
		$rules = get_option( 'rewrite_rules' );
		$this->assertIsArray( $rules );
		$this->assertArrayHasKey( '^driftstorningar/([0-9]{4})/([0-9]{2})/([0-9]{2})/([^/]+)/?$', $rules );
		$this->assertSame( 'index.php?post_type=wpfinet_outage&name=$matches[4]', $rules['^driftstorningar/([0-9]{4})/([0-9]{2})/([0-9]{2})/([^/]+)/?$'] );
		$this->assertArrayHasKey( 'driftstorningar/?$', $rules );
		$this->assertSame( home_url( '/driftstorningar/' ), get_post_type_archive_link( 'wpfinet_outage' ) );
	}

	public function test_classification_buckets(): void {
		$repo     = new \FinetTroubleshoot\Outages\OutageRepository();
		$current  = $this->outage();
		$upcoming = $this->outage( array( '_wpfinet_incident_type' => 'planned', '_wpfinet_outage_status' => 'scheduled', '_wpfinet_incident_start' => wp_date( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ) );
		$resolved = $this->outage( array( '_wpfinet_outage_status' => 'resolved' ) );
		$ended    = $this->outage( array( '_wpfinet_actual_end' => wp_date( 'Y-m-d H:i:s', time() - 60 ) ) );
		$this->assertSame( 'current', $repo->classify( get_post( $current ) ) );
		$this->assertSame( 'upcoming', $repo->classify( get_post( $upcoming ) ) );
		$this->assertSame( 'past', $repo->classify( get_post( $resolved ) ) );
		$this->assertSame( 'past', $repo->classify( get_post( $ended ) ) );
	}

	public function test_outages_shortcode_buckets_and_filters(): void {
		$a = $this->outage( array(), array( 'post_title' => 'Internetstörning Alfa' ), array( 'wpfinet_service' => array( 'Internet' ) ) );
		$b = $this->outage( array(), array( 'post_title' => 'TV-störning Beta' ), array( 'wpfinet_service' => array( 'TV' ) ) );
		$c = $this->outage( array( '_wpfinet_incident_start' => wp_date( 'Y-m-d H:i:s', time() + DAY_IN_SECONDS ) ), array( 'post_title' => 'Planerat Gamma' ) );
		$all = do_shortcode( '[wpfinet_outages]' );
		$this->assertStringContainsString( 'data-wpfinet-outages', $all );
		$this->assertStringContainsString( 'Nuvarande driftstörningar', $all );
		$this->assertStringContainsString( 'Kommande driftstörningar', $all );
		$this->assertStringContainsString( 'Tidigare driftstörningar', $all );
		$current = do_shortcode( '[wpfinet_outages status="current"]' );
		$this->assertStringContainsString( 'Internetstörning Alfa', $current );
		$this->assertStringNotContainsString( 'Planerat Gamma', $current );
		$filtered = do_shortcode( '[wpfinet_outages status="current" service="internet"]' );
		$this->assertStringContainsString( 'Internetstörning Alfa', $filtered );
		$this->assertStringNotContainsString( 'TV-störning Beta', $filtered );
		$this->assertStringContainsString( get_permalink( $a ), $filtered );
		$this->assertTrue( wp_style_is( 'wpfinet-outages', 'enqueued' ) );
	}

	public function test_status_shortcode(): void {
		$html = do_shortcode( '[wpfinet_outage_status]' );
		$this->assertStringContainsString( 'wpfinet-outage-status', $html );
		$this->assertStringContainsString( 'href="' . esc_url( home_url( '/driftstorningar/' ) ) . '"', $html );
		$this->assertStringContainsString( 'is-clear', $html );
		$this->outage();
		$this->assertStringContainsString( 'is-affected', do_shortcode( '[wpfinet_outage_status]' ) );
	}

	public function test_public_rest_shape_hides_internal_data(): void {
		$id = $this->outage( array( '_wpfinet_internal_note' => 'HEMLIG', '_wpfinet_timeline' => array(
			array( 'time' => '2026-01-01 10:00:00', 'text' => 'Publik uppdatering', 'status' => 'identified', 'author' => 1, 'public' => true ),
			array( 'time' => '2026-01-01 11:00:00', 'text' => 'Intern uppdatering', 'status' => 'identified', 'author' => 1, 'public' => false ),
		) ), array(), array( 'wpfinet_provider' => array( 'Leverantör X' ) ) );
		$response = $this->rest( 'GET', '/wpfinet/v1/outages/current' );
		$this->assertSame( 200, $response->get_status() );
		$rows = $response->get_data();
		$row  = current( array_filter( $rows, static fn ( $r ) => $r['id'] === $id ) );
		$this->assertIsArray( $row );
		$this->assertSame( array( 'id', 'title', 'url', 'type', 'status', 'classification', 'priority', 'start', 'estimated_end', 'actual_end', 'providers', 'services', 'areas', 'area_note', 'summary', 'content', 'timeline' ), array_keys( $row ) );
		$this->assertSame( array( 'Leverantör X' ), $row['providers'] );
		$this->assertCount( 1, $row['timeline'] );
		$this->assertSame( 'Publik uppdatering', $row['timeline'][0]['text'] );
		$this->assertStringNotContainsString( 'HEMLIG', wp_json_encode( $rows ) );
		$upcoming = $this->rest( 'GET', '/wpfinet/v1/outages', array( 'status' => 'upcoming' ) );
		$this->assertSame( 200, $upcoming->get_status() );
		$this->assertNotContains( $id, wp_list_pluck( $upcoming->get_data(), 'id' ) );
	}

	public function test_banner_renders_for_current_acute_outage(): void {
		$id = $this->outage( array(), array( 'post_title' => 'Bannerstörning' ) );
		ob_start();
		( new \FinetTroubleshoot\Outages\OutageModule() )->banner();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'data-wpfinet-banner', $html );
		$this->assertStringContainsString( 'data-wpfinet-banner-close', $html );
		$this->assertStringContainsString( get_permalink( $id ), $html );
	}
}
