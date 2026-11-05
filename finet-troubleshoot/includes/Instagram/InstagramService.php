<?php
/**
 * Instagram feed via Meta's official "Instagram API with Instagram Login" (professional Business/Creator account,
 * permission instagram_business_basic). Background-only: sync and token refresh run in WP-Cron / Task Scheduler /
 * WP-CLI; visitors only ever get locally stored posts with locally stored images (no requests to Meta on page view).
 *
 * Endpoints used (verify against Meta's current reference before go-live):
 *  - GET https://graph.instagram.com/{version}/me?fields=user_id,username,account_type
 *  - GET https://graph.instagram.com/{version}/me/media?fields=id,caption,media_type,media_url,permalink,thumbnail_url,timestamp
 *  - GET https://graph.instagram.com/refresh_access_token?grant_type=ig_refresh_token (long-lived token, ≥ 24 h old, 60 days)
 *  - OAuth: https://www.instagram.com/oauth/authorize, POST https://api.instagram.com/oauth/access_token,
 *    GET https://graph.instagram.com/access_token?grant_type=ig_exchange_token
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Instagram;

use FinetTroubleshoot\Core\Http\HttpClient;
use FinetTroubleshoot\Core\Logger;
use FinetTroubleshoot\Core\Secrets;

final class InstagramService {
	public const SETTINGS = 'wpfinet_instagram_settings';
	public const STATE    = 'wpfinet_instagram_state';
	public const MEDIA    = 'wpfinet_instagram_media';
	public const API_HOSTS   = array( 'graph.instagram.com', 'api.instagram.com' );
	public const MEDIA_HOSTS = array( '.cdninstagram.com', '.fbcdn.net' );

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return array( 'app_id' => '', 'api_version' => '', 'sync_interval' => 'hourly', 'max_posts' => 12, 'profile_url' => '', 'caption_length' => 140, 'fallback_text' => 'Följ oss på Instagram' );
	}

	/** @return array<string,mixed> */
	public static function settings(): array {
		$s = get_option( self::SETTINGS, array() );
		return array_replace( self::defaults(), is_array( $s ) ? $s : array() );
	}

	/** @param array<string,mixed> $in */
	public static function update_settings( array $in ): void {
		$cur = self::settings();
		$out = $cur;
		if ( isset( $in['app_id'] ) ) { $out['app_id'] = preg_replace( '/\D/', '', (string) $in['app_id'] ); }
		if ( isset( $in['api_version'] ) ) { $out['api_version'] = preg_match( '/^(v\d{1,3}\.\d)?$/', trim( (string) $in['api_version'] ) ) ? trim( (string) $in['api_version'] ) : $cur['api_version']; }
		if ( isset( $in['sync_interval'] ) && in_array( $in['sync_interval'], array( 'wpfinet_30min', 'hourly', 'twicedaily', 'daily' ), true ) ) { $out['sync_interval'] = (string) $in['sync_interval']; }
		if ( isset( $in['max_posts'] ) ) { $out['max_posts'] = min( 50, max( 1, absint( $in['max_posts'] ) ) ); }
		if ( isset( $in['caption_length'] ) ) { $out['caption_length'] = min( 1000, max( 0, absint( $in['caption_length'] ) ) ); }
		if ( isset( $in['profile_url'] ) ) {
			$url = esc_url_raw( trim( (string) $in['profile_url'] ), array( 'https' ) );
			$out['profile_url'] = $url && 'www.instagram.com' === wp_parse_url( $url, PHP_URL_HOST ) ? $url : '';
		}
		if ( isset( $in['fallback_text'] ) ) { $out['fallback_text'] = sanitize_text_field( (string) $in['fallback_text'] ); }
		update_option( self::SETTINGS, $out, false );
	}

	/** @return array<string,mixed> */
	public static function state(): array {
		$s = get_option( self::STATE, array() );
		return array_replace( array( 'username' => '', 'account_type' => '', 'token_obtained' => 0, 'token_expires' => 0, 'last_refresh' => 0, 'last_sync' => 0, 'last_attempt' => 0, 'last_result' => '', 'last_error' => '', 'last_manual' => 0 ), is_array( $s ) ? $s : array() );
	}

	/** @param array<string,mixed> $changes */
	public static function update_state( array $changes ): void { update_option( self::STATE, array_merge( self::state(), $changes ), false ); }

	/** @return array<int,array<string,mixed>> */
	public static function media(): array {
		$m = get_option( self::MEDIA, array() );
		return is_array( $m ) ? $m : array();
	}

	private function api(): HttpClient { return new HttpClient( 'instagram', self::API_HOSTS ); }

	private function url( string $path, array $query = array() ): string {
		$version = (string) self::settings()['api_version'];
		return add_query_arg( array_map( 'rawurlencode', $query ), 'https://graph.instagram.com/' . ( $version ? $version . '/' : '' ) . ltrim( $path, '/' ) );
	}

	/** @return array{ok:bool,data:array<string,mixed>,error:string,status:int} */
	private function get( string $path, array $query, float $timeout = 10, int $retries = 1 ): array {
		$token = Secrets::get( 'instagram_token' );
		if ( '' === $token ) {
			return array( 'ok' => false, 'data' => array(), 'error' => 'Ingen access token är sparad.', 'status' => 0 );
		}
		$res  = $this->api()->request( $this->url( $path, $query + array( 'access_token' => $token ) ), array( 'timeout' => $timeout, 'retries' => $retries, 'max_bytes' => 2 * MB_IN_BYTES ) );
		$data = json_decode( $res['body'], true );
		$data = is_array( $data ) ? $data : array();
		$error = $res['ok'] ? '' : ( (string) ( $data['error']['message'] ?? $res['error'] ) );
		return array( 'ok' => $res['ok'], 'data' => $data, 'error' => Logger::redact( $error ), 'status' => $res['status'] );
	}

	/** Stores a token pasted by the admin (or from OAuth) after verifying it against /me. */
	public function connect_token( string $token, int $expires_in = 0 ): array {
		$token = trim( $token );
		if ( ! preg_match( '/^[A-Za-z0-9._\-|]{20,1000}$/', $token ) ) {
			return array( 'ok' => false, 'message' => 'Token har fel format.' );
		}
		$previous = Secrets::get( 'instagram_token' );
		Secrets::set( 'instagram_token', $token );
		$me = $this->get( 'me', array( 'fields' => 'user_id,username,account_type' ), 8, 0 );
		if ( ! $me['ok'] ) {
			Secrets::set( 'instagram_token', $previous );
			return array( 'ok' => false, 'message' => 'Token kunde inte verifieras: ' . $me['error'] );
		}
		self::update_state( array( 'username' => sanitize_user( (string) ( $me['data']['username'] ?? '' ), true ), 'account_type' => sanitize_key( (string) ( $me['data']['account_type'] ?? '' ) ), 'token_obtained' => time(), 'token_expires' => $expires_in ? time() + $expires_in : 0, 'last_error' => '' ) );
		Logger::log( 'instagram', 'info', 'Instagramkonto anslutet: @' . ( $me['data']['username'] ?? '?' ) );
		return array( 'ok' => true, 'message' => 'Anslutet till @' . ( $me['data']['username'] ?? '' ) . '.' );
	}

	public function disconnect(): void {
		Secrets::set( 'instagram_token', '' );
		self::update_state( array( 'username' => '', 'account_type' => '', 'token_obtained' => 0, 'token_expires' => 0 ) );
		Logger::log( 'instagram', 'info', 'Instagramkonto frånkopplat.' );
	}

	/** Refreshes the long-lived token when it is ≥ 24 h old and expires within 20 days (or expiry is unknown). */
	public function maybe_refresh_token( bool $force = false ): array {
		$state = self::state();
		if ( 'none' === Secrets::source( 'instagram_token' ) || 'constant' === Secrets::source( 'instagram_token' ) ) {
			return array( 'ok' => false, 'message' => 'constant' === Secrets::source( 'instagram_token' ) ? 'Token i wp-config.php förnyas inte automatiskt.' : 'Ingen token.' );
		}
		$age_ok  = time() - (int) $state['token_obtained'] >= DAY_IN_SECONDS;
		$expires = (int) $state['token_expires'];
		if ( ! $force && ( ! $age_ok || ( $expires && $expires - time() > 20 * DAY_IN_SECONDS ) ) ) {
			return array( 'ok' => true, 'message' => 'Förnyelse behövs inte än.' );
		}
		$res = $this->api()->request( add_query_arg( array( 'grant_type' => 'ig_refresh_token', 'access_token' => rawurlencode( Secrets::get( 'instagram_token' ) ) ), 'https://graph.instagram.com/refresh_access_token' ), array( 'timeout' => 10, 'retries' => 1 ) );
		$data = json_decode( $res['body'], true );
		if ( ! $res['ok'] || empty( $data['access_token'] ) ) {
			$msg = 'Tokenförnyelse misslyckades: ' . Logger::redact( (string) ( $data['error']['message'] ?? $res['error'] ) );
			self::update_state( array( 'last_error' => $msg ) );
			Logger::log( 'instagram', 'warning', $msg );
			return array( 'ok' => false, 'message' => $msg );
		}
		Secrets::set( 'instagram_token', (string) $data['access_token'] );
		self::update_state( array( 'token_obtained' => time(), 'token_expires' => time() + absint( $data['expires_in'] ?? 0 ), 'last_refresh' => time() ) );
		Logger::log( 'instagram', 'info', 'Access token förnyad.' );
		return array( 'ok' => true, 'message' => 'Token förnyad.' );
	}

	/** Fetches posts and stores them locally (images downloaded into uploads). Never runs during a page view. */
	public function sync(): array {
		self::update_state( array( 'last_attempt' => time() ) );
		$limit = (int) self::settings()['max_posts'];
		$res   = $this->get( 'me/media', array( 'fields' => 'id,caption,media_type,media_url,permalink,thumbnail_url,timestamp', 'limit' => (string) $limit ), 15, 2 );
		if ( ! $res['ok'] || ! isset( $res['data']['data'] ) || ! is_array( $res['data']['data'] ) ) {
			$msg = 'Hämtning misslyckades: ' . ( $res['error'] ?: 'oväntat svar' ) . '. Tidigare inlägg visas fortfarande.';
			self::update_state( array( 'last_result' => $msg, 'last_error' => $msg ) );
			Logger::log( 'instagram', 'warning', $msg, array( 'status' => $res['status'] ) );
			return array( 'ok' => false, 'message' => $msg );
		}
		$old   = array_column( self::media(), null, 'id' );
		$items = array();
		foreach ( array_slice( $res['data']['data'], 0, $limit ) as $raw ) {
			$item = $this->store_item( is_array( $raw ) ? $raw : array(), $old );
			if ( $item ) { $items[] = $item; }
		}
		// Posts removed on Instagram are removed here too, including their local image files.
		$keep = array_column( $items, 'id' );
		foreach ( $old as $id => $previous ) {
			if ( ! in_array( (string) $id, $keep, true ) && ! empty( $previous['file'] ) ) { wp_delete_file( self::upload_dir()['path'] . '/' . basename( (string) $previous['file'] ) ); }
		}
		update_option( self::MEDIA, $items, false );
		$msg = sprintf( '%d inlägg sparade lokalt.', count( $items ) );
		self::update_state( array( 'last_sync' => time(), 'last_result' => $msg, 'last_error' => '' ) );
		Logger::log( 'instagram', 'info', $msg );
		return array( 'ok' => true, 'message' => $msg );
	}

	/** @param array<string,mixed> $raw @param array<string,array<string,mixed>> $old @return array<string,mixed>|null */
	private function store_item( array $raw, array $old ): ?array {
		$id = preg_replace( '/\D/', '', (string) ( $raw['id'] ?? '' ) );
		$permalink = esc_url_raw( (string) ( $raw['permalink'] ?? '' ), array( 'https' ) );
		if ( ! $id || ! $permalink || ! in_array( wp_parse_url( $permalink, PHP_URL_HOST ), array( 'www.instagram.com', 'instagram.com' ), true ) ) {
			return null;
		}
		$type   = \FinetTroubleshoot\Import\SchemaValidator::pick( $raw['media_type'] ?? null, array( 'IMAGE', 'VIDEO', 'CAROUSEL_ALBUM' ), 'IMAGE' );
		$source = (string) ( 'VIDEO' === $type ? ( $raw['thumbnail_url'] ?? '' ) : ( $raw['media_url'] ?? '' ) );
		$file   = (string) ( $old[ $id ]['file'] ?? '' );
		$dir    = self::upload_dir();
		if ( ! $file || ! file_exists( $dir['path'] . '/' . $file ) ) {
			$file = $this->download( $source, $id ) ?? '';
		}
		if ( ! $file ) {
			return null; // Without a local image the post is not shown (no hotlinking to Meta's CDN).
		}
		$caption = trim( wp_strip_all_tags( (string) ( $raw['caption'] ?? '' ) ) );
		$size    = @getimagesize( $dir['path'] . '/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return array(
			'id'        => $id,
			'type'      => $type,
			'permalink' => $permalink,
			'timestamp' => strtotime( (string) ( $raw['timestamp'] ?? '' ) ) ?: 0,
			'caption'   => mb_substr( $caption, 0, 2200 ),
			'file'      => $file,
			'width'     => (int) ( $size[0] ?? 0 ),
			'height'    => (int) ( $size[1] ?? 0 ),
		);
	}

	/** @return array{path:string,url:string} */
	public static function upload_dir(): array {
		$up = wp_upload_dir( null, false );
		return array( 'path' => trailingslashit( $up['basedir'] ) . 'wpfinet-instagram', 'url' => trailingslashit( $up['baseurl'] ) . 'wpfinet-instagram' );
	}

	private function download( string $url, string $id ): ?string {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $url || ! HttpClient::host_allowed( $host, self::MEDIA_HOSTS ) ) {
			return null;
		}
		$res = ( new HttpClient( 'instagram-media', self::MEDIA_HOSTS ) )->request( $url, array( 'timeout' => 15, 'retries' => 1, 'max_bytes' => 8 * MB_IN_BYTES ) );
		if ( ! $res['ok'] || '' === $res['body'] ) {
			return null;
		}
		$dir = self::upload_dir();
		wp_mkdir_p( $dir['path'] );
		$tmp = wp_tempnam( 'wpfinet-ig' );
		file_put_contents( $tmp, $res['body'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$mime = wp_get_image_mime( $tmp );
		$ext  = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' )[ $mime ] ?? '';
		if ( ! $ext ) {
			wp_delete_file( $tmp );
			return null; // Not an image – never stored.
		}
		$name   = 'ig-' . $id . '.' . $ext;
		$editor = wp_get_image_editor( $tmp );
		if ( ! is_wp_error( $editor ) ) {
			$editor->resize( 1080, 1080, false );
			$editor->set_quality( 82 );
			$saved = $editor->save( $dir['path'] . '/' . $name );
			wp_delete_file( $tmp );
			return is_wp_error( $saved ) ? null : basename( (string) $saved['path'] );
		}
		$ok = rename( $tmp, $dir['path'] . '/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $ok ? $name : null;
	}

	/** @return array<string,mixed> */
	public function health(): array {
		$state = self::state();
		return array( 'connected' => 'none' !== Secrets::source( 'instagram_token' ), 'token_source' => Secrets::source( 'instagram_token' ), 'username' => $state['username'], 'account_type' => $state['account_type'], 'token_expires' => (int) $state['token_expires'], 'last_sync' => (int) $state['last_sync'], 'last_result' => $state['last_result'], 'last_error' => $state['last_error'], 'posts' => count( self::media() ), 'next_sync' => (int) wp_next_scheduled( 'wpfinet_instagram_sync' ), 'circuit_open' => $this->api()->breaker()->is_open() );
	}

	// ---- OAuth (Business Login for Instagram) ----------------------------------------------------------------

	public static function redirect_uri(): string { return admin_url( 'admin-post.php?action=wpfinet_instagram_oauth' ); }

	public function authorize_url( string $state ): string {
		return add_query_arg( array( 'client_id' => rawurlencode( (string) self::settings()['app_id'] ), 'redirect_uri' => rawurlencode( self::redirect_uri() ), 'response_type' => 'code', 'scope' => 'instagram_business_basic', 'state' => rawurlencode( $state ) ), 'https://www.instagram.com/oauth/authorize' );
	}

	public function exchange_code( string $code ): array {
		$secret = Secrets::get( 'instagram_app_secret' );
		$app_id = (string) self::settings()['app_id'];
		if ( ! $app_id || ! $secret ) {
			return array( 'ok' => false, 'message' => 'App-ID eller app-hemlighet saknas.' );
		}
		$short = $this->api()->request( 'https://api.instagram.com/oauth/access_token', array( 'method' => 'POST', 'timeout' => 10, 'body' => array( 'client_id' => $app_id, 'client_secret' => $secret, 'grant_type' => 'authorization_code', 'redirect_uri' => self::redirect_uri(), 'code' => $code ) ) );
		$data  = json_decode( $short['body'], true );
		if ( ! $short['ok'] || empty( $data['access_token'] ) ) {
			return array( 'ok' => false, 'message' => 'Kod kunde inte bytas mot token: ' . Logger::redact( (string) ( $data['error_message'] ?? $short['error'] ) ) );
		}
		$long = $this->api()->request( add_query_arg( array( 'grant_type' => 'ig_exchange_token', 'client_secret' => rawurlencode( $secret ), 'access_token' => rawurlencode( (string) $data['access_token'] ) ), 'https://graph.instagram.com/access_token' ), array( 'timeout' => 10 ) );
		$ld    = json_decode( $long['body'], true );
		if ( ! $long['ok'] || empty( $ld['access_token'] ) ) {
			return array( 'ok' => false, 'message' => 'Kunde inte hämta långlivad token: ' . Logger::redact( (string) ( $ld['error']['message'] ?? $long['error'] ) ) );
		}
		return $this->connect_token( (string) $ld['access_token'], absint( $ld['expires_in'] ?? 0 ) );
	}
}
