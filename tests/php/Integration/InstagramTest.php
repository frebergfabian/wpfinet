<?php
namespace WPFinet\Tests\Integration;

use FinetTroubleshoot\Core\Secrets;
use FinetTroubleshoot\Instagram\InstagramModule;
use FinetTroubleshoot\Instagram\InstagramService;
use WPFinet\Tests\TestCase;

final class InstagramTest extends TestCase {
	private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
	private array $calls = array();
	private array $media_list = array();
	private bool $api_down = false;

	protected function setUp(): void {
		parent::setUp();
		foreach ( array( InstagramService::SETTINGS, InstagramService::STATE, InstagramService::MEDIA, 'wpfinet_cb_instagram', 'wpfinet_cb_instagram-media' ) as $o ) { delete_option( $o ); }
		Secrets::set( 'instagram_token', '' );
		$this->calls = array();
		$this->api_down = false;
		$this->media_list = array(
			array( 'id' => '1001', 'caption' => 'Fibergrävning i Finspång <b>idag</b>', 'media_type' => 'IMAGE', 'media_url' => 'https://scontent-arn2-1.cdninstagram.com/v/a.jpg?x=1', 'permalink' => 'https://www.instagram.com/p/AAA/', 'timestamp' => '2026-10-01T10:00:00+0000' ),
			array( 'id' => '1002', 'caption' => '', 'media_type' => 'VIDEO', 'media_url' => 'https://video.example/v.mp4', 'thumbnail_url' => 'https://scontent.cdninstagram.com/t.jpg', 'permalink' => 'https://www.instagram.com/reel/BBB/', 'timestamp' => '2026-09-01T10:00:00+0000' ),
			array( 'id' => '1003', 'caption' => 'Fel värd', 'media_type' => 'IMAGE', 'media_url' => 'https://evil.example/x.jpg', 'permalink' => 'https://www.instagram.com/p/CCC/', 'timestamp' => '2026-08-01T10:00:00+0000' ),
			array( 'id' => '1004', 'caption' => 'Inte en bild', 'media_type' => 'IMAGE', 'media_url' => 'https://scontent.cdninstagram.com/notimage.jpg', 'permalink' => 'https://www.instagram.com/p/DDD/', 'timestamp' => '2026-07-01T10:00:00+0000' ),
		);
		add_filter( 'pre_http_request', array( $this, 'fake' ), 10, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'fake' ), 10 );
		Secrets::set( 'instagram_token', '' );
		parent::tearDown();
	}

	private function response( string $body, int $code = 200, string $type = 'application/json' ): array {
		return array( 'headers' => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array( 'content-type' => $type ) ), 'body' => $body, 'response' => array( 'code' => $code, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	}

	public function fake( $pre, $args, $url ) {
		$this->calls[] = $url;
		if ( $this->api_down ) { return new \WP_Error( 'http_request_failed', 'cURL error 28: timed out' ); }
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		if ( str_ends_with( $path, '/me' ) ) { return $this->response( wp_json_encode( array( 'user_id' => '17841', 'username' => 'finet_test', 'account_type' => 'BUSINESS' ) ) ); }
		if ( str_ends_with( $path, '/me/media' ) ) { return $this->response( wp_json_encode( array( 'data' => $this->media_list ) ) ); }
		if ( str_ends_with( $path, '/refresh_access_token' ) ) { return $this->response( wp_json_encode( array( 'access_token' => 'IGAAnewtoken1234567890abcdef', 'token_type' => 'bearer', 'expires_in' => 5184000 ) ) ); }
		if ( str_contains( $path, 'notimage' ) ) { return $this->response( '<html>nope</html>', 200, 'text/html' ); }
		if ( str_contains( (string) wp_parse_url( $url, PHP_URL_HOST ), 'cdninstagram.com' ) ) { return $this->response( base64_decode( self::PNG ), 200, 'image/png' ); }
		return new \WP_Error( 'unexpected', 'Unexpected URL ' . $url );
	}

	private function connect(): void {
		$r = ( new InstagramService() )->connect_token( 'IGAAtesttoken1234567890abcdefghij' );
		$this->assertTrue( $r['ok'], $r['message'] );
	}

	public function test_connect_verifies_token_and_stores_encrypted(): void {
		$this->assertFalse( ( new InstagramService() )->connect_token( 'kort' )['ok'] );
		$this->connect();
		$this->assertSame( 'finet_test', InstagramService::state()['username'] );
		$this->assertSame( 'database', Secrets::source( 'instagram_token' ) );
		$this->assertStringNotContainsString( 'IGAAtesttoken', wp_json_encode( get_option( Secrets::OPTION ) ) );
	}

	public function test_sync_stores_posts_and_images_locally_and_render_uses_only_local_data(): void {
		$this->connect();
		$result = ( new InstagramService() )->sync();
		$this->assertTrue( $result['ok'], $result['message'] );
		$media = InstagramService::media();
		$this->assertSame( array( '1001', '1002' ), array_column( $media, 'id' ), 'wrong host and non-image are skipped' );
		$this->assertSame( 'Fibergrävning i Finspång idag', $media[0]['caption'] );
		$dir = InstagramService::upload_dir();
		foreach ( $media as $m ) { $this->assertFileExists( $dir['path'] . '/' . $m['file'] ); }
		$this->assertNotContains( 'https://evil.example/x.jpg', $this->calls, 'disallowed media host is never requested' );

		$this->calls = array();
		$html = ( new InstagramModule() )->render( '', array( 'limit' => '6' ) );
		$this->assertSame( array(), $this->calls, 'rendering makes no HTTP requests' );
		$this->assertStringContainsString( $dir['url'] . '/ig-1001.', $html );
		$this->assertStringNotContainsString( 'cdninstagram', $html );
		$this->assertStringContainsString( 'href="https://www.instagram.com/p/AAA/"', $html );
		$this->assertStringContainsString( 'alt="Fibergrävning i Finspång idag"', $html );
		$this->assertStringContainsString( 'Instagraminlägg från @finet_test', $html, 'fallback alt text' );
		$this->assertStringContainsString( 'loading="lazy"', $html );
		$this->assertStringNotContainsString( '<script', $html );
		$this->assertStringNotContainsString( '<iframe', $html );

		// Deleted on Instagram → deleted here (incl. file).
		$file = $dir['path'] . '/' . $media[1]['file'];
		$this->media_list = array( $this->media_list[0] );
		( new InstagramService() )->sync();
		$this->assertCount( 1, InstagramService::media() );
		$this->assertFileDoesNotExist( $file );
	}

	public function test_api_down_keeps_last_good_posts_and_is_fast_for_visitors(): void {
		$this->connect();
		( new InstagramService() )->sync();
		$this->api_down = true;
		for ( $i = 0; $i < 3; $i++ ) { $this->assertFalse( ( new InstagramService() )->sync()['ok'] ); }
		$this->assertCount( 2, InstagramService::media(), 'last good posts are kept' );
		$this->assertTrue( ( new InstagramService() )->health()['circuit_open'] );
		$start = microtime( true );
		$html  = ( new InstagramModule() )->render( '', array() );
		$this->assertLessThan( 0.05, microtime( true ) - $start );
		$this->assertStringContainsString( 'wpfinet-instagram__item', $html );
	}

	public function test_token_refresh_rules(): void {
		$this->connect();
		$this->calls = array();
		$this->assertTrue( ( new InstagramService() )->maybe_refresh_token()['ok'] );
		$this->assertSame( array(), $this->calls, 'token younger than 24 h is not refreshed' );
		InstagramService::update_state( array( 'token_obtained' => time() - 2 * DAY_IN_SECONDS, 'token_expires' => time() + 50 * DAY_IN_SECONDS ) );
		( new InstagramService() )->maybe_refresh_token();
		$this->assertSame( array(), $this->calls, 'not refreshed when far from expiry' );
		InstagramService::update_state( array( 'token_expires' => time() + 5 * DAY_IN_SECONDS ) );
		$this->assertTrue( ( new InstagramService() )->maybe_refresh_token()['ok'] );
		$this->assertStringContainsString( 'refresh_access_token', $this->calls[0] );
		$this->assertSame( 'IGAAnewtoken1234567890abcdef', Secrets::get( 'instagram_token' ) );
		$this->assertGreaterThan( time() + 59 * DAY_IN_SECONDS, InstagramService::state()['token_expires'] );
	}

	public function test_empty_feed_fallback_and_admin_page(): void {
		update_option( InstagramService::SETTINGS, array( 'profile_url' => 'https://www.instagram.com/finet/' ) );
		$this->assertStringContainsString( 'https://www.instagram.com/finet/', ( new InstagramModule() )->render( '', array() ) );
		$this->connect();
		$this->login( 'administrator' );
		ob_start();
		\FinetTroubleshoot\Instagram\InstagramAdmin::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( '@finet_test', $html );
		$this->assertStringNotContainsString( 'IGAAtesttoken', $html );
		InstagramService::update_settings( array( 'profile_url' => 'https://evil.example/' ) );
		$this->assertSame( '', InstagramService::settings()['profile_url'] );
	}
}
