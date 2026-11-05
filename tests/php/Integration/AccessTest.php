<?php
namespace WPFinet\Tests\Integration;

use FinetTroubleshoot\Access\AccessModule;
use FinetTroubleshoot\Access\AccessService;
use FinetTroubleshoot\Access\AccessSettings;
use FinetTroubleshoot\Access\AddressIndex;
use FinetTroubleshoot\Core\Modules;
use FinetTroubleshoot\Core\Secrets;
use WPFinet\Tests\TestCase;

final class AccessTest extends TestCase {
	/** @var array<int,array{url:string,args:array}> */
	private array $calls = array();
	private $responder = null;

	protected function setUp(): void {
		parent::setUp();
		delete_option( AccessSettings::OPTION );
		delete_option( AddressIndex::STATE_OPTION );
		delete_option( 'wpfinet_cb_access' );
		$this->calls = array();
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		Secrets::set( 'access_api_secret', '' );
		Modules::update( array( 'access' => false ) );
		parent::tearDown();
	}

	public function fake_http( $pre, $args, $url ) {
		$this->calls[] = array( 'url' => $url, 'args' => $args );
		return $this->responder ? ( $this->responder )( $url, $args ) : new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out after 3000 milliseconds' );
	}

	private function json( $data, int $code = 200 ): array {
		return array( 'headers' => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array( 'content-type' => 'application/json' ) ), 'body' => wp_json_encode( $data ), 'response' => array( 'code' => $code, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	}

	private function configure( array $settings ): void {
		AccessSettings::update( $settings );
	}

	public function test_defaults_report_what_is_unknown(): void {
		$missing = AccessSettings::missing();
		$this->assertNotEmpty( $missing );
		$this->assertStringContainsString( 'sökväg', $missing[0] );
		$this->assertSame( 'https://access.finet.se/api/onapi/2.4/', AccessSettings::get()['base_url'] );
		$this->assertSame( '', AccessSettings::endpoint_url() );
		$result = ( new AccessService() )->sync( false );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( array(), $this->calls, 'no request is made with an incomplete configuration' );
	}

	public function test_mock_sync_and_lookups(): void {
		$this->configure( array( 'adapter' => 'mock' ) );
		$sync = ( new AccessService() )->sync( false );
		$this->assertTrue( $sync['ok'], $sync['message'] );
		$this->assertSame( 7, AddressIndex::state()['rows'] );
		$svc = new AccessService();
		$cases = array(
			'Exempelgatan 1'  => array( 'connected', '/tjanster' ),
			'exempelgatan 2'  => array( 'not_connected', '/kontakta-oss' ),
			'Exempelgatan 3'  => array( 'no_match', '/kontakta-oss' ),
			'EXEMPELGATAN 3a' => array( 'connected', '/tjanster' ),
			'Exempelgatan 3 B' => array( 'not_connected', '/kontakta-oss' ),
			'Åtestvägen 5'    => array( 'connected', '/tjanster' ),
			'Atestvägen 5'    => array( 'not_connected', '/kontakta-oss' ),
			'Testallen 12B'   => array( 'connected', '/tjanster' ),
			'Exempelgatan 99' => array( 'no_match', '/kontakta-oss' ),
		);
		foreach ( $cases as $input => [ $status, $url ] ) {
			$r = $svc->lookup( $input );
			$this->assertSame( $status, $r['status'], $input );
			$this->assertSame( $url, $r['cta']['url'], $input );
			$this->assertFalse( $r['stale'] );
		}
		$this->assertSame( 'letter_required', $svc->lookup( 'Exempelgatan 3' )['hint'] );
		$invalid = $svc->lookup( 'hej' );
		$this->assertSame( 'invalid', $invalid['status'] );
		$this->assertNull( $invalid['cta'] );
		$this->assertSame( array(), $this->calls );
	}

	public function test_http_adapter_builds_request_and_maps_nested_response(): void {
		Secrets::set( 'access_api_secret', 'nyckel-123' );
		$this->configure( array( 'endpoint_path' => 'addresses', 'auth' => 'bearer', 'extra_query' => "municipality=finspang\npage_size=5000", 'data_path' => 'data.items', 'field_street' => 'address.street', 'connected_values' => 'CONNECTED, ACTIVE' ) );
		$this->responder = fn ( $url, $args ) => $this->json( array( 'data' => array( 'items' => array(
			array( 'address' => array( 'street' => 'Storgatan' ), 'streetNumber' => '12B', 'streetLittera' => '', 'accessState' => 'ACTIVE' ),
			array( 'address' => array( 'street' => 'Storgatan' ), 'streetNumber' => '14', 'streetLittera' => '', 'accessState' => 'NOT_CONNECTED' ),
			array( 'address' => array( 'street' => '' ), 'streetNumber' => '1', 'accessState' => 'CONNECTED' ),
		) ) ) );
		$sync = ( new AccessService() )->sync( false );
		$this->assertTrue( $sync['ok'], $sync['message'] );
		$this->assertSame( 2, AddressIndex::state()['rows'] );
		$this->assertCount( 1, $this->calls );
		$this->assertStringStartsWith( 'https://access.finet.se/api/onapi/2.4/addresses?', $this->calls[0]['url'] );
		$this->assertStringContainsString( 'municipality=finspang', $this->calls[0]['url'] );
		$this->assertSame( 'Bearer nyckel-123', $this->calls[0]['args']['headers']['Authorization'] );
		$this->assertSame( 0, $this->calls[0]['args']['redirection'] );
		$this->assertSame( 'connected', ( new AccessService() )->lookup( 'Storgatan 12 b' )['status'] );
		$this->assertSame( 'not_connected', ( new AccessService() )->lookup( 'Storgatan 14' )['status'] );
		$test = ( new AccessService() )->test_connection();
		$this->assertArrayHasKey( 'data.items', $test['list_paths'] );
		$this->assertStringNotContainsString( 'Storgatan', wp_json_encode( $test ), 'schema preview masks values' );
		$this->assertStringContainsString( 'ACTIVE', wp_json_encode( $test['mapped_sample'] ) );
	}

	public function test_failures_keep_index_and_open_circuit(): void {
		$this->configure( array( 'adapter' => 'mock' ) );
		( new AccessService() )->sync( false );
		$this->configure( array( 'adapter' => 'http', 'endpoint_path' => 'x' ) );
		$this->responder = null; // Timeouts.
		for ( $i = 0; $i < 3; $i++ ) { $this->assertFalse( ( new AccessService() )->sync( false )['ok'] ); }
		$this->assertSame( 3, count( $this->calls ) );
		$this->assertSame( 7, AddressIndex::state()['rows'], 'existing index is kept' );
		$this->assertTrue( ( new AccessService() )->health()['circuit_open'] );
		$before = count( $this->calls );
		$start  = microtime( true );
		$this->assertFalse( ( new AccessService() )->sync( false )['ok'] );
		$this->assertSame( $before, count( $this->calls ), 'open circuit makes no network call' );
		$this->assertLessThan( 0.5, microtime( true ) - $start );
		$this->assertSame( 'connected', ( new AccessService() )->lookup( 'Exempelgatan 1' )['status'], 'lookups keep working from the index' );
	}

	public function test_safety_guard_refuses_large_drop(): void {
		$this->configure( array( 'adapter' => 'mock' ) );
		( new AccessService() )->sync( false );
		$this->configure( array( 'adapter' => 'http', 'endpoint_path' => 'x' ) );
		$this->responder = fn () => $this->json( array( array( 'streetName' => 'Ny', 'streetNumber' => 1, 'accessState' => 'CONNECTED' ) ) );
		$r = ( new AccessService() )->sync( false );
		$this->assertFalse( $r['ok'] );
		$this->assertStringContainsString( 'sjönk', $r['message'] );
		$this->assertSame( 7, AddressIndex::state()['rows'] );
	}

	public function test_freshness_stale_and_expired(): void {
		$this->configure( array( 'adapter' => 'mock', 'stale_after_hours' => 24, 'max_age_hours' => 48 ) );
		( new AccessService() )->sync( false );
		AddressIndex::update_state( array( 'last_success' => time() - 30 * HOUR_IN_SECONDS ) );
		$stale = ( new AccessService() )->lookup( 'Exempelgatan 1' );
		$this->assertSame( 'connected', $stale['status'] );
		$this->assertTrue( $stale['stale'] );
		$this->assertStringContainsString( 'kunde inte bekräftas', $stale['message'] );
		AddressIndex::update_state( array( 'last_success' => time() - 50 * HOUR_IN_SECONDS ) );
		$this->assertSame( 'unavailable', ( new AccessService() )->lookup( 'Exempelgatan 1' )['status'] );
	}

	public function test_live_lookup_mode_with_stale_fallback(): void {
		$this->configure( array( 'mode' => 'lookup', 'endpoint_path' => 'search', 'lookup_params' => array( 'street' => 'street', 'number' => 'no', 'letter' => '' ) ) );
		$this->responder = fn ( $url ) => $this->json( array( array( 'streetName' => 'Storgatan', 'streetNumber' => '5', 'streetLittera' => '', 'accessState' => 'CONNECTED' ) ) );
		$this->assertSame( 'connected', ( new AccessService() )->lookup( 'Storgatan 5' )['status'] );
		$this->assertStringContainsString( 'street=Storgatan', $this->calls[0]['url'] );
		$this->assertSame( 3.0, (float) $this->calls[0]['args']['timeout'] );
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_wpfinet_access_q_%' OR option_name LIKE '_transient_timeout_wpfinet_access_q_%'" );
		wp_cache_flush();
		$this->responder = null;
		$fallback = ( new AccessService() )->lookup( 'Storgatan 5' );
		$this->assertSame( 'connected', $fallback['status'] );
		$this->assertTrue( $fallback['stale'] );
		$this->assertSame( 'unavailable', ( new AccessService() )->lookup( 'Annangatan 1' )['status'] );
	}

	public function test_settings_reject_ssrf_and_path_traversal(): void {
		$this->configure( array( 'base_url' => 'https://evil.example/api/', 'endpoint_path' => '../../etc' ) );
		$this->assertSame( 'https://access.finet.se/api/onapi/2.4/', AccessSettings::get()['base_url'] );
		$this->assertSame( '', AccessSettings::get()['endpoint_path'] );
		$this->configure( array( 'base_url' => 'http://access.finet.se/api/' ) );
		$this->assertSame( 'https://access.finet.se/api/onapi/2.4/', AccessSettings::get()['base_url'], 'http is rejected' );
		$this->configure( array( 'cta_connected_url' => 'javascript:alert(1)' ) );
		$this->assertSame( '/tjanster', AccessSettings::get()['cta_connected_url'] );
	}

	public function test_rest_route_is_minimal_and_rate_limited(): void {
		$this->configure( array( 'adapter' => 'mock', 'rate_limit' => 3 ) );
		( new AccessService() )->sync( false );
		$module = new AccessModule();
		add_action( 'rest_api_init', array( $module, 'routes' ) );
		do_action( 'rest_api_init', rest_get_server() );
		$_SERVER['REMOTE_ADDR'] = '198.51.100.' . wp_rand( 1, 250 );
		$ok = $this->rest( 'POST', '/wpfinet/v1/access/check', array( 'address' => 'Exempelgatan 1' ) );
		$this->assertSame( 200, $ok->get_status() );
		$this->assertSame( array( 'status', 'message', 'cta', 'stale', 'checked_at', 'test_data' ), array_keys( $ok->get_data() ) );
		$this->assertSame( 'no-store, private', $ok->get_headers()['Cache-Control'] );
		$this->assertTrue( $ok->get_data()['test_data'] );
		$this->assertSame( 400, $this->rest( 'POST', '/wpfinet/v1/access/check', array( 'address' => 'x' ) )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', '/wpfinet/v1/access/check', array( 'address' => 'Exempelgatan 1', 'website' => 'spam' ) )->get_status() );
		$this->assertSame( 429, $this->rest( 'POST', '/wpfinet/v1/access/check', array( 'address' => 'Exempelgatan 1' ) )->get_status() );
		$this->assertSame( 400, $this->rest( 'POST', '/wpfinet/v1/access/check', array( 'address' => str_repeat( 'a', 200 ) ) )->get_status(), 'maxLength enforced before rate limit' );
		$this->assertArrayNotHasKey( '/wpfinet/v1/access/check', array_filter( rest_get_server()->get_routes(), static fn ( $h, $r ) => str_contains( $r, 'access' ) && str_contains( $r, 'list' ), ARRAY_FILTER_USE_BOTH ) );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$routes = array_keys( rest_get_server()->get_routes() );
		$this->assertSame( array( '/wpfinet/v1/access/check' ), array_values( array_filter( $routes, static fn ( $r ) => str_contains( $r, '/access' ) ) ), 'no endpoint lists addresses' );
		$this->assertNotContains( 'GET', array_keys( array_filter( rest_get_server()->get_routes()['/wpfinet/v1/access/check'][0]['methods'] ) ) );
	}

	public function test_rendering_never_calls_the_api(): void {
		$this->configure( array( 'endpoint_path' => 'x', 'mode' => 'lookup', 'lookup_params' => array( 'street' => 's', 'number' => 'n', 'letter' => '' ) ) );
		Modules::update( array( 'access' => true ) );
		$module = new AccessModule();
		$module->init();
		$html = $module->render( '', array( 'title' => 'Kolla din adress' ) );
		$this->assertStringContainsString( 'data-wpfinet-access', $html );
		$this->assertStringContainsString( 'wpfinet/v1/access/check', $html );
		$this->assertStringNotContainsString( '<script', $html, 'no inline script in the content' );
		$this->assertStringContainsString( 'autocomplete="street-address"', $html );
		$this->assertSame( array(), $this->calls );
		$this->assertTrue( wp_script_is( 'wpfinet-access', 'enqueued' ) );
	}

	public function test_shortcode_stub_when_module_is_off(): void {
		$this->assertSame( '', do_shortcode( '[wpfinet_access_check]' ), 'visitors see nothing, not the raw shortcode' );
		$this->login( 'editor' );
		$this->assertStringContainsString( 'avstängd', do_shortcode( '[wpfinet_access_check]' ) );
	}

	public function test_admin_page_renders_without_leaking_secret(): void {
		Secrets::set( 'access_api_secret', 'mycket-hemlig-nyckel' );
		$this->login( 'administrator' );
		ob_start();
		\FinetTroubleshoot\Access\AccessAdmin::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Vad saknas för riktig integration', $html );
		$this->assertStringContainsString( 'krypterat', $html );
		$this->assertStringNotContainsString( 'mycket-hemlig-nyckel', $html );
	}
}
