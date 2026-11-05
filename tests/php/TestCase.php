<?php
namespace WPFinet\Tests;

/**
 * Minimal WordPress-aware base test case (no wp-phpunit dependency).
 */
abstract class TestCase extends \PHPUnit\Framework\TestCase {
	/** @var array<int,int> */
	protected array $created_posts = array();

	protected function tearDown(): void {
		foreach ( $this->created_posts as $id ) { wp_delete_post( $id, true ); }
		$this->created_posts = array();
		wp_set_current_user( 0 );
		$GLOBALS['current_screen'] = null; // is_admin() must not leak between tests.
		parent::tearDown();
	}

	protected function user( string $role ): int {
		static $users = array();
		if ( ! isset( $users[ $role ] ) ) {
			$users[ $role ] = wp_insert_user( array( 'user_login' => 'test_' . $role, 'user_pass' => wp_generate_password(), 'user_email' => $role . '@example.test', 'role' => $role ) );
		}
		return (int) $users[ $role ];
	}

	protected function login( string $role ): int {
		$id = $this->user( $role );
		wp_set_current_user( $id );
		return $id;
	}

	protected function rest( string $method, string $route, array $params = array(), array $files = array() ): \WP_REST_Response {
		$request = new \WP_REST_Request( $method, $route );
		if ( 'GET' === $method ) { $request->set_query_params( $params ); }
		else { $request->set_header( 'content-type', 'application/json' ); $request->set_body( wp_json_encode( $params ) ); }
		if ( $files ) { $request->set_file_params( $files ); }
		$response = rest_get_server()->dispatch( $request );
		return rest_ensure_response( $response );
	}

	protected function import_example(): int {
		$doc     = json_decode( (string) file_get_contents( WP_PLUGIN_DIR . '/finet-troubleshoot/examples/finet-troubleshoot-example.json' ), true );
		$manager = new \FinetTroubleshoot\Import\ImportManager( new \FinetTroubleshoot\Validation\FlowValidator() );
		$doc['guides'][0]['external_id'] = 'test-' . strtolower( wp_generate_password( 6, false, false ) );
		$doc['guides'][0]['slug']        = $doc['guides'][0]['external_id'];
		$result  = $manager->import_data( $doc, 'copy' );
		$items   = $result->to_array()['items'];
		$id      = (int) ( $items[0]['post_id'] ?? 0 );
		$this->created_posts[] = $id;
		return $id;
	}

	protected function publish( int $id ): void { wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ) ); }

	protected function outage( array $meta = array(), array $post = array(), array $terms = array() ): int {
		$id = wp_insert_post( array_merge( array( 'post_type' => 'wpfinet_outage', 'post_title' => 'Störning ' . wp_generate_password( 4, false, false ), 'post_status' => 'publish', 'post_excerpt' => 'Sammanfattning' ), $post ) );
		$defaults = array( '_wpfinet_incident_type' => 'acute', '_wpfinet_outage_status' => 'investigating', '_wpfinet_priority' => 'normal', '_wpfinet_incident_start' => wp_date( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ), '_wpfinet_banner' => '1' );
		foreach ( array_merge( $defaults, $meta ) as $k => $v ) { update_post_meta( $id, $k, $v ); }
		foreach ( $terms as $tax => $names ) { wp_set_object_terms( $id, $names, $tax ); }
		( new \FinetTroubleshoot\Outages\OutageRepository() )->flush();
		wp_cache_flush(); // Simulate a new request (2.0.1 flushes transients with raw SQL).
		$this->created_posts[] = $id;
		return $id;
	}
}
