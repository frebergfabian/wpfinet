<?php
namespace WPFinet\Tests\Contract;

use WPFinet\Tests\TestCase;

/** Frozen REST contracts: routes, methods, permissions, status codes and response keys. */
final class RestContractTest extends TestCase {
	public function test_all_routes_registered_with_methods(): void {
		$routes = rest_get_server()->get_routes();
		$expect = array(
			'/finet-troubleshoot/v1/guides'                         => array( 'GET' ),
			'/finet-troubleshoot/v1/guides/(?P<id>\d+)'             => array( 'GET', 'POST', 'PUT', 'PATCH' ),
			'/finet-troubleshoot/v1/guides/(?P<id>\d+)/duplicate'   => array( 'POST' ),
			'/finet-troubleshoot/v1/validate'                       => array( 'POST' ),
			'/finet-troubleshoot/v1/import/preview'                 => array( 'POST' ),
			'/finet-troubleshoot/v1/import'                         => array( 'POST' ),
			'/finet-troubleshoot/v1/export/(?P<id>\d+)'             => array( 'GET' ),
			'/wpfinet/v1/outages/current'                           => array( 'GET' ),
			'/wpfinet/v1/outages'                                   => array( 'GET' ),
			'/wpfinet/v1/events'                                    => array( 'POST' ),
			'/wpfinet/v1/analytics/summary'                         => array( 'GET' ),
		);
		foreach ( $expect as $route => $methods ) {
			$this->assertArrayHasKey( $route, $routes, $route );
			$registered = array();
			foreach ( $routes[ $route ] as $handler ) { $registered = array_merge( $registered, array_keys( array_filter( $handler['methods'] ) ) ); }
			foreach ( $methods as $method ) { $this->assertContains( $method, $registered, $route . ' ' . $method ); }
		}
		foreach ( array( '/wp/v2/finet_ts_guide', '/wp/v2/wpfinet_outage', '/wp/v2/wpfinet_provider', '/wp/v2/wpfinet_service', '/wp/v2/wpfinet_area' ) as $core ) {
			$this->assertArrayHasKey( $core, $routes, $core );
		}
	}

	public function test_guides_permissions(): void {
		$this->assertSame( 401, $this->rest( 'GET', '/finet-troubleshoot/v1/guides' )->get_status() );
		$this->login( 'subscriber' );
		$this->assertSame( 403, $this->rest( 'GET', '/finet-troubleshoot/v1/guides' )->get_status() );
		$this->login( 'editor' );
		$this->assertSame( 200, $this->rest( 'GET', '/finet-troubleshoot/v1/guides' )->get_status() );
	}

	public function test_guide_read_shape_and_404(): void {
		$id = $this->import_example();
		$this->login( 'administrator' );
		$list = $this->rest( 'GET', '/finet-troubleshoot/v1/guides' )->get_data();
		$this->assertContains( $id, wp_list_pluck( $list, 'id' ) );
		$one = $this->rest( 'GET', '/finet-troubleshoot/v1/guides/' . $id );
		$this->assertSame( 200, $one->get_status() );
		$this->assertSame( array( 'id', 'title', 'slug', 'description', 'status', 'external_id', 'start_node', 'nodes', 'settings' ), array_slice( array_keys( $one->get_data() ), 0, 9 ) );
		$missing = $this->rest( 'GET', '/finet-troubleshoot/v1/guides/999999' );
		$this->assertSame( 404, $missing->get_status() );
		$this->assertSame( 'finet_ts_not_found', $missing->get_data()['code'] );
	}

	public function test_guide_save_valid_and_invalid(): void {
		$id = $this->import_example();
		$this->login( 'administrator' );
		$guide = $this->rest( 'GET', '/finet-troubleshoot/v1/guides/' . $id )->get_data();
		$guide['description'] = 'Ny beskrivning';
		$saved = $this->rest( 'PUT', '/finet-troubleshoot/v1/guides/' . $id, $guide );
		$this->assertSame( 200, $saved->get_status() );
		$this->assertArrayHasKey( 'guide', $saved->get_data() );
		$this->assertArrayHasKey( 'validation', $saved->get_data() );
		$this->assertSame( 'Ny beskrivning', get_post_field( 'post_excerpt', $id ) );
		$guide['start_node'] = 'finns-inte';
		$bad = $this->rest( 'POST', '/finet-troubleshoot/v1/guides/' . $id, $guide );
		$this->assertSame( 400, $bad->get_status() );
		$this->assertSame( 'finet_ts_invalid_flow', $bad->get_data()['code'] );
		$this->assertArrayHasKey( 'validation', $bad->get_data()['data'] );
	}

	public function test_duplicate_and_validate(): void {
		$id = $this->import_example();
		$this->login( 'administrator' );
		$dup = $this->rest( 'POST', '/finet-troubleshoot/v1/guides/' . $id . '/duplicate' );
		$this->assertSame( 200, $dup->get_status() );
		$data = $dup->get_data();
		$this->created_posts[] = $data['id'];
		$this->assertNotSame( $id, $data['id'] );
		$this->assertSame( 'draft', $data['status'] );
		$this->assertStringEndsWith( '– Kopia', $data['title'] );
		$this->assertSame( get_post_meta( $id, '_finet_ts_nodes', true ), get_post_meta( $data['id'], '_finet_ts_nodes', true ) );
		$valid = $this->rest( 'POST', '/finet-troubleshoot/v1/validate', $this->rest( 'GET', '/finet-troubleshoot/v1/guides/' . $id )->get_data() )->get_data();
		$this->assertSame( array( 'valid', 'errors', 'warnings', 'summary' ), array_keys( $valid ) );
		$this->assertTrue( $valid['valid'] );
	}

	public function test_import_and_export_permissions_and_errors(): void {
		$id = $this->import_example();
		$this->login( 'editor' );
		$this->assertSame( 403, $this->rest( 'POST', '/finet-troubleshoot/v1/import/preview' )->get_status() );
		$this->assertSame( 403, $this->rest( 'GET', '/finet-troubleshoot/v1/export/' . $id )->get_status() );
		$this->login( 'administrator' );
		$missing = $this->rest( 'POST', '/finet-troubleshoot/v1/import/preview' );
		$this->assertSame( 400, $missing->get_status() );
		$this->assertSame( 'finet_ts_missing_file', $missing->get_data()['code'] );
		$expired = $this->rest( 'POST', '/finet-troubleshoot/v1/import', array( 'token' => 'nope', 'strategy' => 'copy' ) );
		$this->assertSame( 400, $expired->get_status() );
		$this->assertSame( 'finet_ts_import_expired', $expired->get_data()['code'] );
		$export = $this->rest( 'GET', '/finet-troubleshoot/v1/export/' . $id );
		$this->assertSame( 200, $export->get_status() );
		$doc = $export->get_data();
		$this->assertSame( 'finet-troubleshoot', $doc['schema'] );
		$this->assertSame( '2.0', $doc['schema_version'] );
		$this->assertCount( 1, $doc['guides'] );
		$this->assertArrayHasKey( 'outages', $doc );
		$this->assertSame( array( 'external_id', 'title', 'slug', 'description', 'status', 'start_node', 'settings', 'variables', 'nodes' ), array_keys( $doc['guides'][0] ) );
	}

	public function test_events_require_consent_and_summary_requires_capability(): void {
		$res = $this->rest( 'POST', '/wpfinet/v1/events', array( 'events' => array( array( 'session' => wp_generate_uuid4(), 'event' => 'guide_viewed' ) ) ) );
		$this->assertSame( 200, $res->get_status() );
		$this->assertSame( array( 'accepted' => false, 'reason' => 'consent_required' ), $res->get_data() );
		$this->assertSame( 401, $this->rest( 'GET', '/wpfinet/v1/analytics/summary' )->get_status() );
		$this->login( 'administrator' );
		$summary = $this->rest( 'GET', '/wpfinet/v1/analytics/summary', array( 'days' => 7 ) );
		$this->assertSame( 200, $summary->get_status() );
		$this->assertSame( array( 'days', 'starts', 'completions', 'abandoned', 'completion_rate', 'average_steps' ), array_keys( $summary->get_data() ) );
		$this->assertSame( 7, $summary->get_data()['days'] );
	}
}
