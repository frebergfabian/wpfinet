<?php
namespace WPFinet\Tests\Integration;

use FinetTroubleshoot\Core\Secrets;
use FinetTroubleshoot\Instagram\InstagramModule;
use FinetTroubleshoot\Instagram\InstagramService;
use WPFinet\Tests\TestCase;

/** 3.1: both Meta API models, Account ID handling, connection test, image redirects and error explanations. */
final class InstagramModelsTest extends TestCase {
	private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
	private array $calls = array();
	private array $pages = array();
	private string $media_host = 'scontent-arn2-1.cdninstagram.com';

	protected function setUp(): void {
		parent::setUp();
		foreach ( array( InstagramService::SETTINGS, InstagramService::STATE, InstagramService::MEDIA, InstagramService::TEST, 'wpfinet_cb_instagram', 'wpfinet_cb_instagram-media' ) as $o ) { delete_option( $o ); }
		Secrets::set( 'instagram_token', '' );
		$this->calls = array();
		$this->pages = array( array( 'id' => '111', 'name' => 'Finet', 'instagram_business_account' => array( 'id' => '17841400000000001', 'username' => 'finet' ) ) );
		add_filter( 'pre_http_request', array( $this, 'fake' ), 10, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', array( $this, 'fake' ), 10 );
		Secrets::set( 'instagram_token', '' );
		parent::tearDown();
	}

	private function r( $data, int $code = 200, string $type = 'application/json', array $headers = array() ): array {
		return array( 'headers' => new \WpOrg\Requests\Utility\CaseInsensitiveDictionary( array_merge( array( 'content-type' => $type ), $headers ) ), 'body' => is_string( $data ) ? $data : wp_json_encode( $data ), 'response' => array( 'code' => $code, 'message' => 'X' ), 'cookies' => array(), 'filename' => null );
	}

	public function fake( $pre, $args, $url ) {
		$this->calls[] = $url;
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$token = (string) ( $q['access_token'] ?? '' );
		$media = array( 'data' => array( array( 'id' => '9001', 'caption' => 'Hej', 'media_type' => 'IMAGE', 'media_url' => 'https://' . $this->media_host . '/a.jpg', 'permalink' => 'https://www.instagram.com/p/X1/', 'timestamp' => '2026-10-01T10:00:00+0000' ) ) );
		if ( 'graph.facebook.com' === $host ) {
			if ( ! str_starts_with( $token, 'EAA' ) ) { return $this->r( array( 'error' => array( 'message' => 'Invalid OAuth access token - Cannot parse access token', 'type' => 'OAuthException', 'code' => 190 ) ), 400 ); }
			if ( str_ends_with( $path, '/me/accounts' ) ) { return $this->r( array( 'data' => $this->pages ) ); }
			if ( str_ends_with( $path, '/me/permissions' ) ) { return $this->r( array( 'data' => array( array( 'permission' => 'instagram_basic', 'status' => 'granted' ), array( 'permission' => 'pages_show_list', 'status' => 'granted' ) ) ) ); }
			if ( str_ends_with( $path, '/me' ) ) { return $this->r( array( 'id' => '111', 'name' => 'Finet' ) ); }
			if ( preg_match( '#/(\d+)/media$#', $path, $m ) ) {
				return in_array( $m[1], array_column( array_column( $this->pages, 'instagram_business_account' ), 'id' ), true ) ? $this->r( $media ) : $this->r( array( 'error' => array( 'message' => 'Unsupported get request.', 'code' => 100, 'error_subcode' => 33 ) ), 400 );
			}
		}
		if ( 'graph.instagram.com' === $host ) {
			if ( str_starts_with( $token, 'EAA' ) ) { return $this->r( array( 'error' => array( 'message' => 'Invalid OAuth access token - Cannot parse access token', 'type' => 'OAuthException', 'code' => 190 ) ), 400 ); }
			if ( str_ends_with( $path, '/me' ) ) { return $this->r( array( 'user_id' => '17841499999999999', 'username' => 'finet_ig', 'account_type' => 'BUSINESS' ) ); }
			if ( str_ends_with( $path, '/media' ) ) { return $this->r( $media ); }
		}
		if ( 'redirect.cdninstagram.com' === $host ) { return $this->r( '', 302, 'text/html', array( 'location' => 'https://evil.example/a.jpg' ) ); }
		if ( str_contains( $host, 'cdninstagram.com' ) && str_ends_with( $path, '/a.jpg' ) && 'scontent-arn2-1.cdninstagram.com' !== $host ) { return $this->r( '', 302, 'text/html', array( 'location' => 'https://scontent-arn2-1.cdninstagram.com/a.jpg' ) ); }
		if ( str_contains( $host, 'cdninstagram.com' ) ) { return $this->r( base64_decode( self::PNG ), 200, 'image/png' ); }
		return new \WP_Error( 'unexpected', 'Unexpected ' . $url );
	}

	public function test_model_detection(): void {
		$this->assertSame( 'facebook_login', InstagramService::detect_model( 'EAABwzLixnjYBO123' ) );
		$this->assertSame( 'instagram_login', InstagramService::detect_model( 'IGAAJ123456' ) );
	}

	public function test_facebook_login_token_finds_account_and_syncs(): void {
		$r = ( new InstagramService() )->connect_token( 'EAABwzLixnjYBO1234567890abcdefXYZ' );
		$this->assertTrue( $r['ok'], $r['message'] );
		$this->assertSame( '17841400000000001', InstagramService::state()['detected_account_id'] );
		$sync = ( new InstagramService() )->sync();
		$this->assertTrue( $sync['ok'], $sync['message'] );
		$this->assertCount( 1, InstagramService::media() );
		$this->assertNotEmpty( array_filter( $this->calls, static fn ( $u ) => str_contains( $u, 'graph.facebook.com/17841400000000001/media' ) ) );
		$this->assertSame( array(), array_values( array_filter( $this->calls, static fn ( $u ) => str_contains( $u, 'graph.instagram.com' ) ) ), 'never calls the wrong host' );
		$this->calls = array();
		$html = ( new InstagramModule() )->render( '', array() );
		$this->assertSame( array(), $this->calls );
		$this->assertStringContainsString( 'wpfinet-instagram__item', $html );
	}

	public function test_multiple_accounts_require_a_choice_and_manual_id_is_used(): void {
		$this->pages[] = array( 'id' => '222', 'name' => 'Annan sida', 'instagram_business_account' => array( 'id' => '17841400000000002', 'username' => 'annat' ) );
		$r = ( new InstagramService() )->connect_token( 'EAABwzLixnjYBO1234567890abcdefXYZ' );
		$this->assertTrue( $r['ok'] );
		$this->assertStringContainsString( '2 konton', $r['message'] );
		$this->assertFalse( ( new InstagramService() )->sync()['ok'], 'no account chosen yet' );
		InstagramService::update_settings( array( 'account_id' => '17841400000000002' ) );
		$this->assertTrue( ( new InstagramService() )->sync()['ok'] );
		$this->assertNotEmpty( array_filter( $this->calls, static fn ( $u ) => str_contains( $u, '/17841400000000002/media' ) ) );
	}

	public function test_wrong_model_is_explained(): void {
		Secrets::set( 'instagram_token', 'EAABwzLixnjYBO1234567890abcdefXYZ' );
		InstagramService::update_settings( array( 'api_model' => 'instagram_login' ) );
		$test = ( new InstagramService() )->test_connection();
		$this->assertFalse( $test['ok'] );
		$text = wp_json_encode( $test['steps'], JSON_UNESCAPED_UNICODE );
		$this->assertStringContainsString( 'kod 190', $text );
		$this->assertStringContainsString( 'Facebook-inloggning', $text, 'hint points to the right model' );
		InstagramService::update_settings( array( 'api_model' => 'auto' ) );
		$ok = ( new InstagramService() )->test_connection();
		$this->assertTrue( $ok['ok'], wp_json_encode( $ok['steps'], JSON_UNESCAPED_UNICODE ) );
		$this->assertSame( array( 'Token', 'API-modell', 'Konto via token', 'Instagram Account ID', 'Behörigheter', 'Hämta inlägg (17841400000000001/media)', 'Hämta bild (scontent-arn2-1.cdninstagram.com)', 'Uppladdningsmapp', 'Bildbehandling', 'Modul och schema', 'Lokalt sparade inlägg' ), array_column( $ok['steps'], 'label' ) );
	}

	public function test_unknown_manual_account_gives_clear_error(): void {
		Secrets::set( 'instagram_token', 'EAABwzLixnjYBO1234567890abcdefXYZ' );
		InstagramService::update_settings( array( 'account_id' => '123' ) );
		$r = ( new InstagramService() )->sync();
		$this->assertFalse( $r['ok'] );
		$this->assertStringContainsString( 'Account ID', $r['message'] );
	}

	public function test_image_redirects_inside_allowlist_are_followed_others_reported(): void {
		$this->media_host = 'scontent-other.cdninstagram.com';
		( new InstagramService() )->connect_token( 'IGAAtesttoken1234567890abcdefghij' );
		$this->assertTrue( ( new InstagramService() )->sync()['ok'], 'redirect to another Meta CDN host is followed' );
		delete_option( InstagramService::MEDIA );
		$this->media_host = 'redirect.cdninstagram.com';
		$r = ( new InstagramService() )->sync();
		$this->assertFalse( $r['ok'] );
		$this->assertStringContainsString( 'evil.example', $r['message'], 'the reason is shown to the admin' );
		$this->assertNotContains( 'https://evil.example/a.jpg', $this->calls );
	}

	public function test_undecryptable_secret_is_detected(): void {
		update_option( Secrets::OPTION, array( 'instagram_token' => 'v1:' . base64_encode( str_repeat( 'x', 60 ) ) ), false );
		$this->assertSame( 'undecryptable', Secrets::status( 'instagram_token' ) );
		$this->assertStringContainsString( 'kan inte läsas', ( new InstagramService() )->sync()['message'] );
		delete_option( Secrets::OPTION );
	}

	public function test_error_explanations(): void {
		$this->assertStringContainsString( 'gått ut', InstagramService::explain_error( array( 'message' => 'x', 'code' => 190, 'error_subcode' => 463 ), '' ) );
		$this->assertStringContainsString( 'Behörighet saknas', InstagramService::explain_error( array( 'message' => 'x', 'code' => 10 ), '' ) );
		$this->assertStringContainsString( 'anropsgräns', InstagramService::explain_error( array( 'message' => 'x', 'code' => 4 ), '' ) );
		$this->assertStringContainsString( 'Account ID', InstagramService::explain_error( array( 'message' => 'x', 'code' => 100, 'error_subcode' => 33 ), '' ) );
	}

	public function test_admin_page_shows_test_and_never_the_token(): void {
		( new InstagramService() )->connect_token( 'EAABwzLixnjYBO1234567890abcdefXYZ' );
		( new InstagramService() )->test_connection();
		$this->login( 'administrator' );
		ob_start();
		\FinetTroubleshoot\Instagram\InstagramAdmin::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Senaste anslutningstest', $html );
		$this->assertStringContainsString( 'Facebook-inloggning', $html );
		$this->assertStringContainsString( '17841400000000001', $html );
		$this->assertStringNotContainsString( '1234567890abcdefXYZ', $html );
	}
}
