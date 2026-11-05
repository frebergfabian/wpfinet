<?php
/**
 * Instagram feed via Meta's official Graph APIs. Two API models are supported because Meta issues two kinds of tokens:
 *
 *  1. "Instagram API with Instagram Login" – token usually starts with "IGAA". Host graph.instagram.com.
 *     Profile: /me?fields=user_id,username,account_type,media_count · Media: /me/media (or /{ig-user-id}/media).
 *     Permission: instagram_business_basic. Long-lived tokens (60 days) are refreshed with /refresh_access_token.
 *  2. "Instagram API with Facebook Login" (Instagram Graph API via a Facebook Page) – token usually starts with "EAA".
 *     Host graph.facebook.com. The Instagram professional account ID is read from
 *     /me/accounts?fields=instagram_business_account (user token) or /me?fields=instagram_business_account (page token),
 *     or entered manually. Media: /{ig-user-id}/media. Permissions: instagram_basic, pages_show_list
 *     (and usually pages_read_engagement / business_management).
 *
 * Background-only: sync and token refresh run in WP-Cron / Task Scheduler / WP-CLI or when an administrator presses
 * "Hämta nu". Visitors only ever get locally stored posts with locally stored images.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Instagram;

use FinetTroubleshoot\Core\Http\HttpClient;
use FinetTroubleshoot\Core\Logger;
use FinetTroubleshoot\Core\Secrets;

final class InstagramService {
	public const SETTINGS    = 'wpfinet_instagram_settings';
	public const STATE       = 'wpfinet_instagram_state';
	public const MEDIA       = 'wpfinet_instagram_media';
	public const TEST        = 'wpfinet_instagram_last_test';
	public const API_HOSTS   = array( 'graph.instagram.com', 'api.instagram.com', 'graph.facebook.com' );
	public const MEDIA_HOSTS = array( '.cdninstagram.com', '.fbcdn.net' );
	public const MODELS      = array( 'auto', 'instagram_login', 'facebook_login' );
	private const MEDIA_FIELDS = 'id,caption,media_type,media_url,permalink,thumbnail_url,timestamp';

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return array( 'api_model' => 'auto', 'account_id' => '', 'app_id' => '', 'facebook_app_id' => '', 'api_version' => '', 'sync_interval' => 'hourly', 'max_posts' => 12, 'profile_url' => '', 'caption_length' => 140, 'fallback_text' => 'Följ oss på Instagram' );
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
		if ( isset( $in['api_model'] ) && in_array( $in['api_model'], self::MODELS, true ) ) { $out['api_model'] = (string) $in['api_model']; }
		if ( isset( $in['account_id'] ) ) { $out['account_id'] = (string) preg_replace( '/\D/', '', (string) $in['account_id'] ); }
		foreach ( array( 'app_id', 'facebook_app_id' ) as $key ) {
			if ( isset( $in[ $key ] ) ) { $out[ $key ] = (string) preg_replace( '/\D/', '', (string) $in[ $key ] ); }
		}
		if ( isset( $in['api_version'] ) ) { $out['api_version'] = preg_match( '/^(v\d{1,3}\.\d)?$/', trim( (string) $in['api_version'] ) ) ? trim( (string) $in['api_version'] ) : $cur['api_version']; }
		if ( isset( $in['sync_interval'] ) && in_array( $in['sync_interval'], array( 'wpfinet_30min', 'hourly', 'twicedaily', 'daily' ), true ) ) { $out['sync_interval'] = (string) $in['sync_interval']; }
		if ( isset( $in['max_posts'] ) ) { $out['max_posts'] = min( 50, max( 1, absint( $in['max_posts'] ) ) ); }
		if ( isset( $in['caption_length'] ) ) { $out['caption_length'] = min( 1000, max( 0, absint( $in['caption_length'] ) ) ); }
		if ( isset( $in['profile_url'] ) ) {
			$url = esc_url_raw( trim( (string) $in['profile_url'] ), array( 'https' ) );
			$out['profile_url'] = $url && in_array( wp_parse_url( $url, PHP_URL_HOST ), array( 'www.instagram.com', 'instagram.com' ), true ) ? $url : '';
		}
		if ( isset( $in['fallback_text'] ) ) { $out['fallback_text'] = sanitize_text_field( (string) $in['fallback_text'] ); }
		if ( $out['api_model'] !== $cur['api_model'] || $out['account_id'] !== $cur['account_id'] ) {
			self::update_state( array( 'detected_account_id' => '' ) );
		}
		update_option( self::SETTINGS, $out, false );
	}

	/** @return array<string,mixed> */
	public static function state(): array {
		$s = get_option( self::STATE, array() );
		return array_replace( array( 'username' => '', 'account_type' => '', 'detected_account_id' => '', 'token_obtained' => 0, 'token_expires' => 0, 'last_refresh' => 0, 'last_sync' => 0, 'last_attempt' => 0, 'last_result' => '', 'last_error' => '', 'last_manual' => 0, 'image_failures' => 0 ), is_array( $s ) ? $s : array() );
	}

	/** @param array<string,mixed> $changes */
	public static function update_state( array $changes ): void { update_option( self::STATE, array_merge( self::state(), $changes ), false ); }

	/** @return array<int,array<string,mixed>> */
	public static function media(): array {
		$m = get_option( self::MEDIA, array() );
		return is_array( $m ) ? $m : array();
	}

	// ---- API model and account ---------------------------------------------------------------------------------

	public static function detect_model( string $token ): string {
		$token = trim( $token );
		if ( str_starts_with( $token, 'EAA' ) ) { return 'facebook_login'; }
		return 'instagram_login';
	}

	/** The API model in use: the explicit setting, or detected from the token. */
	public function model(): string {
		$setting = (string) self::settings()['api_model'];
		return 'auto' === $setting ? self::detect_model( Secrets::get( 'instagram_token' ) ) : $setting;
	}

	public static function model_label( string $model ): string {
		return 'facebook_login' === $model ? 'Instagram API med Facebook-inloggning (graph.facebook.com)' : 'Instagram API med Instagram-inloggning (graph.instagram.com)';
	}

	private function host(): string { return 'facebook_login' === $this->model() ? 'graph.facebook.com' : 'graph.instagram.com'; }

	private function api(): HttpClient { return new HttpClient( 'instagram', self::API_HOSTS ); }

	private function url( string $path, array $query = array() ): string {
		$version = (string) self::settings()['api_version'];
		return add_query_arg( array_map( 'rawurlencode', $query ), 'https://' . $this->host() . '/' . ( $version ? $version . '/' : '' ) . ltrim( $path, '/' ) );
	}

	/**
	 * GET against the Graph API of the current model.
	 *
	 * @return array{ok:bool,data:array<string,mixed>,error:string,status:int,meta:array<string,mixed>}
	 */
	private function get( string $path, array $query, float $timeout = 10, int $retries = 1 ): array {
		$token = Secrets::get( 'instagram_token' );
		if ( '' === $token ) {
			$status = Secrets::status( 'instagram_token' );
			return array( 'ok' => false, 'data' => array(), 'status' => 0, 'meta' => array(), 'error' => 'undecryptable' === $status ? 'Den sparade token kan inte läsas (AUTH_KEY/SECURE_AUTH_SALT i wp-config.php har ändrats eller PHP-tillägget för kryptering saknas). Spara token igen.' : 'Ingen access token är sparad.' );
		}
		$res   = $this->api()->request( $this->url( $path, $query + array( 'access_token' => $token ) ), array( 'timeout' => $timeout, 'retries' => $retries, 'max_bytes' => 2 * MB_IN_BYTES ) );
		$data  = json_decode( $res['body'], true );
		$data  = is_array( $data ) ? $data : array();
		$meta  = is_array( $data['error'] ?? null ) ? $data['error'] : array();
		$error = $res['ok'] ? '' : self::explain_error( $meta, $res['error'], $this->model(), $token );
		return array( 'ok' => $res['ok'], 'data' => $data, 'error' => Logger::redact( $error ), 'status' => $res['status'], 'meta' => array_intersect_key( $meta, array_flip( array( 'message', 'type', 'code', 'error_subcode', 'fbtrace_id' ) ) ) );
	}

	/** Human explanation of a Meta Graph API error (codes documented by Meta for both APIs). */
	public static function explain_error( array $meta, string $fallback, string $model = '', string $token = '' ): string {
		$code = (int) ( $meta['code'] ?? 0 );
		$sub  = (int) ( $meta['error_subcode'] ?? 0 );
		$msg  = (string) ( $meta['message'] ?? $fallback );
		$hint = '';
		if ( 190 === $code ) {
			$hint = 463 === $sub ? 'Token har gått ut – skapa en ny långlivad token.' : ( 460 === $sub ? 'Token gäller inte längre (lösenordet ändrades) – skapa en ny token.' : 'Token är ogiltig för det här API:t.' );
			if ( $token && self::detect_model( $token ) !== $model ) {
				$hint .= ' Token ser ut att höra till ' . self::model_label( self::detect_model( $token ) ) . ' – välj den API-modellen eller "Automatiskt".';
			}
		} elseif ( 10 === $code || ( $code >= 200 && $code <= 299 ) ) {
			$hint = 'Behörighet saknas. Instagram-inloggning kräver instagram_business_basic; Facebook-inloggning kräver instagram_basic och pages_show_list (ofta även pages_read_engagement/business_management).';
		} elseif ( 100 === $code ) {
			$hint = 33 === $sub ? 'Objektet finns inte eller kan inte läsas med denna token – kontrollera Instagram Account ID och API-modell.' : 'Ogiltig parameter eller fält – kontrollera Account ID, API-modell och API-version.';
		} elseif ( in_array( $code, array( 4, 17, 32, 613 ), true ) ) {
			$hint = 'Metas anropsgräns är nådd – vänta en stund och försök igen.';
		} elseif ( 368 === $code ) {
			$hint = 'Kontot/appen är tillfälligt spärrat av Meta.';
		}
		return trim( $msg . ( $code ? ' (kod ' . $code . ( $sub ? '/' . $sub : '' ) . ')' : '' ) . ( $hint ? ' – ' . $hint : '' ) );
	}

	/**
	 * Finds the Instagram professional account(s) the token can read.
	 *
	 * @return array{ok:bool,accounts:array<int,array{id:string,username:string,source:string}>,error:string}
	 */
	public function discover_accounts(): array {
		if ( 'instagram_login' === $this->model() ) {
			$me = $this->get( 'me', array( 'fields' => 'user_id,username,account_type,media_count' ), 8, 0 );
			if ( ! $me['ok'] ) { return array( 'ok' => false, 'accounts' => array(), 'error' => $me['error'] ); }
			$id = (string) ( $me['data']['user_id'] ?? $me['data']['id'] ?? '' );
			return array( 'ok' => '' !== $id, 'accounts' => '' !== $id ? array( array( 'id' => $id, 'username' => (string) ( $me['data']['username'] ?? '' ), 'source' => 'me' ) ) : array(), 'error' => '' !== $id ? '' : 'Svaret saknade user_id.' );
		}
		$accounts = array();
		$pages    = $this->get( 'me/accounts', array( 'fields' => 'id,name,instagram_business_account{id,username}', 'limit' => '100' ), 8, 0 );
		if ( $pages['ok'] ) {
			foreach ( (array) ( $pages['data']['data'] ?? array() ) as $page ) {
				$ig = is_array( $page['instagram_business_account'] ?? null ) ? $page['instagram_business_account'] : null;
				if ( $ig && ! empty( $ig['id'] ) ) { $accounts[] = array( 'id' => (string) $ig['id'], 'username' => (string) ( $ig['username'] ?? '' ), 'source' => 'Facebook-sida: ' . sanitize_text_field( (string) ( $page['name'] ?? '' ) ) ); }
			}
		}
		if ( ! $accounts ) { // Page access token: the page itself is "me".
			$self = $this->get( 'me', array( 'fields' => 'id,name,instagram_business_account{id,username}' ), 8, 0 );
			$ig   = $self['ok'] && is_array( $self['data']['instagram_business_account'] ?? null ) ? $self['data']['instagram_business_account'] : null;
			if ( $ig && ! empty( $ig['id'] ) ) { $accounts[] = array( 'id' => (string) $ig['id'], 'username' => (string) ( $ig['username'] ?? '' ), 'source' => 'Facebook-sida: ' . sanitize_text_field( (string) ( $self['data']['name'] ?? '' ) ) ); }
			if ( ! $accounts ) {
				return array( 'ok' => false, 'accounts' => array(), 'error' => $pages['ok'] || $self['ok'] ? 'Ingen Facebook-sida med kopplat Instagram-proffskonto hittades för denna token. Koppla kontot till sidan i Meta Business Suite eller ange Account ID manuellt.' : ( $pages['error'] ?: $self['error'] ) );
			}
		}
		return array( 'ok' => true, 'accounts' => $accounts, 'error' => '' );
	}

	/** Manual Account ID wins; otherwise the cached detected one; otherwise detect now (only one candidate is accepted). */
	public function account_id( bool $detect = true ): string {
		$manual = (string) self::settings()['account_id'];
		if ( '' !== $manual ) { return $manual; }
		$cached = (string) self::state()['detected_account_id'];
		if ( '' !== $cached || ! $detect ) { return $cached; }
		$found = $this->discover_accounts();
		if ( $found['ok'] && 1 === count( $found['accounts'] ) ) {
			self::update_state( array( 'detected_account_id' => $found['accounts'][0]['id'], 'username' => $found['accounts'][0]['username'] ?: self::state()['username'] ) );
			return $found['accounts'][0]['id'];
		}
		return '';
	}

	private function media_path(): string {
		$id = $this->account_id();
		if ( 'instagram_login' === $this->model() ) {
			return '' !== (string) self::settings()['account_id'] ? $id . '/media' : 'me/media';
		}
		return '' === $id ? '' : $id . '/media';
	}

	// ---- Token -------------------------------------------------------------------------------------------------

	/** Stores a token pasted by the admin (or from OAuth) after verifying it. */
	public function connect_token( string $token, int $expires_in = 0 ): array {
		$token = trim( $token );
		if ( ! preg_match( '/^[A-Za-z0-9._\-|]{20,1000}$/', $token ) ) {
			return array( 'ok' => false, 'message' => 'Token har fel format (kopiera hela token utan mellanslag).' );
		}
		if ( ! Secrets::available() ) {
			return array( 'ok' => false, 'message' => 'Servern kan inte kryptera hemligheter (PHP-tilläggen sodium och openssl saknas). Lägg token i wp-config.php som WPFINET_INSTAGRAM_TOKEN.' );
		}
		$previous = Secrets::get( 'instagram_token' );
		Secrets::set( 'instagram_token', $token );
		if ( Secrets::get( 'instagram_token' ) !== $token ) {
			Secrets::set( 'instagram_token', $previous );
			return array( 'ok' => false, 'message' => 'Token kunde inte sparas krypterat. Lägg den i wp-config.php som WPFINET_INSTAGRAM_TOKEN.' );
		}
		self::update_state( array( 'detected_account_id' => '' ) );
		$found = $this->discover_accounts();
		if ( ! $found['ok'] ) {
			Secrets::set( 'instagram_token', $previous );
			return array( 'ok' => false, 'message' => 'Token kunde inte verifieras med ' . self::model_label( $this->model() ) . ': ' . $found['error'] );
		}
		$first = $found['accounts'][0];
		self::update_state( array( 'username' => sanitize_user( $first['username'], true ), 'detected_account_id' => 1 === count( $found['accounts'] ) ? $first['id'] : '', 'token_obtained' => time(), 'token_expires' => $expires_in ? time() + $expires_in : 0, 'last_error' => '' ) );
		Logger::log( 'instagram', 'info', 'Instagramkonto anslutet: @' . $first['username'] . ' via ' . $this->model() );
		$extra = count( $found['accounts'] ) > 1 ? ' Token har åtkomst till ' . count( $found['accounts'] ) . ' konton – välj Account ID under Inställningar.' : '';
		return array( 'ok' => true, 'message' => 'Anslutet till @' . $first['username'] . ' (' . self::model_label( $this->model() ) . ').' . $extra );
	}

	public function disconnect(): void {
		Secrets::set( 'instagram_token', '' );
		self::update_state( array( 'username' => '', 'account_type' => '', 'detected_account_id' => '', 'token_obtained' => 0, 'token_expires' => 0 ) );
		Logger::log( 'instagram', 'info', 'Instagramkonto frånkopplat.' );
	}

	/** Instagram Login: refreshes the long-lived token when ≥ 24 h old and expiring within 20 days (or expiry unknown). */
	public function maybe_refresh_token( bool $force = false ): array {
		$state = self::state();
		if ( 'none' === Secrets::source( 'instagram_token' ) || 'constant' === Secrets::source( 'instagram_token' ) ) {
			return array( 'ok' => false, 'message' => 'constant' === Secrets::source( 'instagram_token' ) ? 'Token i wp-config.php förnyas inte automatiskt.' : 'Ingen token.' );
		}
		if ( 'facebook_login' === $this->model() ) {
			return array( 'ok' => true, 'message' => 'Facebook-inloggning: sid-token som skapats från en långlivad användartoken går inte ut. Förnyas inte automatiskt.' );
		}
		$age_ok  = time() - (int) $state['token_obtained'] >= DAY_IN_SECONDS;
		$expires = (int) $state['token_expires'];
		if ( ! $force && ( ! $age_ok || ( $expires && $expires - time() > 20 * DAY_IN_SECONDS ) ) ) {
			return array( 'ok' => true, 'message' => 'Förnyelse behövs inte än.' );
		}
		$res  = $this->api()->request( add_query_arg( array( 'grant_type' => 'ig_refresh_token', 'access_token' => rawurlencode( Secrets::get( 'instagram_token' ) ) ), 'https://graph.instagram.com/refresh_access_token' ), array( 'timeout' => 10, 'retries' => 1 ) );
		$data = json_decode( $res['body'], true );
		if ( ! $res['ok'] || empty( $data['access_token'] ) ) {
			$msg = 'Tokenförnyelse misslyckades: ' . Logger::redact( self::explain_error( is_array( $data['error'] ?? null ) ? $data['error'] : array(), $res['error'], 'instagram_login' ) );
			self::update_state( array( 'last_error' => $msg ) );
			Logger::log( 'instagram', 'warning', $msg );
			return array( 'ok' => false, 'message' => $msg );
		}
		Secrets::set( 'instagram_token', (string) $data['access_token'] );
		self::update_state( array( 'token_obtained' => time(), 'token_expires' => time() + absint( $data['expires_in'] ?? 0 ), 'last_refresh' => time() ) );
		Logger::log( 'instagram', 'info', 'Access token förnyad.' );
		return array( 'ok' => true, 'message' => 'Token förnyad.' );
	}

	// ---- Sync --------------------------------------------------------------------------------------------------

	/** Fetches posts and stores them locally (images downloaded into uploads). Never runs during a page view. */
	public function sync(): array {
		self::update_state( array( 'last_attempt' => time() ) );
		$path = $this->media_path();
		if ( '' === $path ) {
			$msg = 'Instagram Account ID saknas och kunde inte identifieras automatiskt. Kör "Testa anslutning" eller ange ID manuellt. Tidigare inlägg visas fortfarande.';
			self::update_state( array( 'last_result' => $msg, 'last_error' => $msg ) );
			return array( 'ok' => false, 'message' => $msg );
		}
		$limit = (int) self::settings()['max_posts'];
		$res   = $this->get( $path, array( 'fields' => self::MEDIA_FIELDS, 'limit' => (string) $limit ), 15, 2 );
		if ( ! $res['ok'] || ! isset( $res['data']['data'] ) || ! is_array( $res['data']['data'] ) ) {
			$msg = 'Hämtning misslyckades: ' . ( $res['error'] ?: 'oväntat svar från Meta' ) . '. Tidigare inlägg visas fortfarande.';
			self::update_state( array( 'last_result' => $msg, 'last_error' => $msg ) );
			Logger::log( 'instagram', 'warning', $msg, array( 'status' => $res['status'] ) );
			return array( 'ok' => false, 'message' => $msg );
		}
		$old      = array_column( self::media(), null, 'id' );
		$items    = array();
		$failures = array();
		foreach ( array_slice( $res['data']['data'], 0, $limit ) as $raw ) {
			$item = $this->store_item( is_array( $raw ) ? $raw : array(), $old, $failures );
			if ( $item ) { $items[] = $item; }
		}
		$received = count( $res['data']['data'] );
		if ( 0 === count( $items ) && $received > 0 ) {
			$msg = sprintf( 'Meta returnerade %d inlägg men inga bilder kunde sparas lokalt (%s). Tidigare inlägg behålls.', $received, implode( '; ', array_slice( array_unique( $failures ), 0, 3 ) ) );
			self::update_state( array( 'last_result' => $msg, 'last_error' => $msg, 'image_failures' => count( $failures ) ) );
			Logger::log( 'instagram', 'warning', $msg );
			return array( 'ok' => false, 'message' => $msg );
		}
		$keep = array_column( $items, 'id' );
		foreach ( $old as $id => $previous ) {
			if ( ! in_array( (string) $id, $keep, true ) && ! empty( $previous['file'] ) ) { wp_delete_file( self::upload_dir()['path'] . '/' . basename( (string) $previous['file'] ) ); }
		}
		update_option( self::MEDIA, $items, false );
		$msg = sprintf( '%d inlägg sparade lokalt.', count( $items ) ) . ( $failures ? sprintf( ' %d bild(er) kunde inte sparas: %s', count( $failures ), implode( '; ', array_slice( array_unique( $failures ), 0, 3 ) ) ) : '' );
		self::update_state( array( 'last_sync' => time(), 'last_result' => $msg, 'last_error' => '', 'image_failures' => count( $failures ) ) );
		Logger::log( 'instagram', $failures ? 'warning' : 'info', $msg );
		return array( 'ok' => true, 'message' => $msg );
	}

	/** @param array<string,mixed> $raw @param array<string,array<string,mixed>> $old @param array<int,string> $failures @return array<string,mixed>|null */
	private function store_item( array $raw, array $old, array &$failures ): ?array {
		$id = (string) preg_replace( '/\D/', '', (string) ( $raw['id'] ?? '' ) );
		$permalink = esc_url_raw( (string) ( $raw['permalink'] ?? '' ), array( 'https' ) );
		if ( ! $id || ! $permalink || ! in_array( wp_parse_url( $permalink, PHP_URL_HOST ), array( 'www.instagram.com', 'instagram.com' ), true ) ) {
			$failures[] = 'inlägg utan giltig permalänk';
			return null;
		}
		$type   = in_array( $raw['media_type'] ?? '', array( 'IMAGE', 'VIDEO', 'CAROUSEL_ALBUM' ), true ) ? (string) $raw['media_type'] : 'IMAGE';
		$source = (string) ( 'VIDEO' === $type ? ( $raw['thumbnail_url'] ?? '' ) : ( $raw['media_url'] ?? '' ) );
		$file   = (string) ( $old[ $id ]['file'] ?? '' );
		$dir    = self::upload_dir();
		if ( ! $file || ! file_exists( $dir['path'] . '/' . $file ) ) {
			$download = $this->download( $source, $id );
			$file     = $download['file'];
			if ( ! $file ) {
				$failures[] = $download['error'];
				return null; // Without a local image the post is not shown (no hotlinking to Meta's CDN).
			}
		}
		$caption = trim( wp_strip_all_tags( (string) ( $raw['caption'] ?? '' ) ) );
		$size    = @getimagesize( $dir['path'] . '/' . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		return array( 'id' => $id, 'type' => $type, 'permalink' => $permalink, 'timestamp' => strtotime( (string) ( $raw['timestamp'] ?? '' ) ) ?: 0, 'caption' => mb_substr( $caption, 0, 2200 ), 'file' => $file, 'width' => (int) ( $size[0] ?? 0 ), 'height' => (int) ( $size[1] ?? 0 ) );
	}

	/** @return array{path:string,url:string} */
	public static function upload_dir(): array {
		$up = wp_upload_dir( null, false );
		return array( 'path' => trailingslashit( $up['basedir'] ) . 'wpfinet-instagram', 'url' => trailingslashit( $up['baseurl'] ) . 'wpfinet-instagram' );
	}

	/** @return array{file:string,error:string} */
	private function download( string $url, string $id, bool $store = true ): array {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( ! $url ) { return array( 'file' => '', 'error' => 'media_url saknas i svaret' ); }
		if ( ! HttpClient::host_allowed( $host, self::MEDIA_HOSTS ) ) { return array( 'file' => '', 'error' => 'bildvärden ' . $host . ' är inte tillåten' ); }
		$res = ( new HttpClient( 'instagram-media', self::MEDIA_HOSTS ) )->request( $url, array( 'timeout' => 15, 'retries' => 1, 'max_bytes' => 8 * MB_IN_BYTES, 'follow_redirects' => 3 ) );
		if ( ! $res['ok'] || '' === $res['body'] ) { return array( 'file' => '', 'error' => 'bildhämtning: ' . ( $res['error'] ?: 'tomt svar' ) ); }
		$dir = self::upload_dir();
		if ( ! wp_mkdir_p( $dir['path'] ) || ! wp_is_writable( $dir['path'] ) ) { return array( 'file' => '', 'error' => 'mappen ' . $dir['path'] . ' är inte skrivbar' ); }
		$tmp = wp_tempnam( 'wpfinet-ig' );
		file_put_contents( $tmp, $res['body'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$mime = wp_get_image_mime( $tmp );
		$ext  = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp' )[ $mime ] ?? '';
		if ( ! $ext ) {
			wp_delete_file( $tmp );
			return array( 'file' => '', 'error' => 'svaret var inte en bild (' . ( $res['headers']['content-type'] ?? 'okänd typ' ) . ')' );
		}
		if ( ! $store ) {
			wp_delete_file( $tmp );
			return array( 'file' => 'ok', 'error' => '' );
		}
		$name   = 'ig-' . $id . '.' . $ext;
		$editor = wp_get_image_editor( $tmp );
		if ( ! is_wp_error( $editor ) ) {
			$editor->resize( 1080, 1080, false );
			$editor->set_quality( 82 );
			$saved = $editor->save( $dir['path'] . '/' . $name );
			wp_delete_file( $tmp );
			return is_wp_error( $saved ) ? array( 'file' => '', 'error' => 'bilden kunde inte sparas: ' . $saved->get_error_message() ) : array( 'file' => basename( (string) $saved['path'] ), 'error' => '' );
		}
		$ok = @rename( $tmp, $dir['path'] . '/' . $name ); // phpcs:ignore WordPress.WP.AlternativeFunctions,WordPress.PHP.NoSilencedErrors
		return $ok ? array( 'file' => $name, 'error' => '' ) : array( 'file' => '', 'error' => 'bilden kunde inte flyttas till uppladdningsmappen' );
	}

	// ---- Connection test ---------------------------------------------------------------------------------------

	/**
	 * Step-by-step diagnosis that makes no changes to stored posts. Result is kept for the admin page.
	 *
	 * @return array{ok:bool,time:int,model:string,steps:array<int,array{label:string,ok:bool|null,detail:string}>,accounts:array<int,array<string,string>>}
	 */
	public function test_connection(): array {
		$steps = array();
		$add   = static function ( string $label, ?bool $ok, string $detail ) use ( &$steps ): void { $steps[] = array( 'label' => $label, 'ok' => $ok, 'detail' => $detail ); };
		$status = Secrets::status( 'instagram_token' );
		$token  = Secrets::get( 'instagram_token' );
		$labels = array( 'constant' => 'läses från wp-config.php (WPFINET_INSTAGRAM_TOKEN)', 'ok' => 'sparad krypterat i databasen', 'undecryptable' => 'SPARAD MEN KAN INTE LÄSAS – AUTH_KEY/SECURE_AUTH_SALT har ändrats eller krypteringstillägget saknas. Spara token igen.', 'none' => 'saknas' );
		$add( 'Token', in_array( $status, array( 'constant', 'ok' ), true ), $labels[ $status ] . ( $token ? ' · börjar med "' . substr( $token, 0, 4 ) . '…", ' . strlen( $token ) . ' tecken' : '' ) );
		$accounts = array();
		$model    = $this->model();
		if ( $token ) {
			$detected = self::detect_model( $token );
			$add( 'API-modell', 'auto' === self::settings()['api_model'] || $detected === $model, self::model_label( $model ) . ( 'auto' === self::settings()['api_model'] ? ' (identifierad från token)' : ( $detected !== $model ? ' – OBS: token ser ut att höra till ' . self::model_label( $detected ) : ' (vald manuellt)' ) ) );
			$found    = $this->discover_accounts();
			$accounts = $found['accounts'];
			$add( 'Konto via token', $found['ok'], $found['ok'] ? implode( ', ', array_map( static fn ( $a ) => '@' . $a['username'] . ' (ID ' . $a['id'] . ', ' . $a['source'] . ')', $accounts ) ) : $found['error'] );
			$manual = (string) self::settings()['account_id'];
			$id     = $manual ?: ( 1 === count( $accounts ) ? $accounts[0]['id'] : '' );
			if ( $found['ok'] && ! $manual && 1 === count( $accounts ) ) { self::update_state( array( 'detected_account_id' => $id, 'username' => $accounts[0]['username'] ) ); }
			$add( 'Instagram Account ID', '' !== $id || 'instagram_login' === $model, $manual ? $manual . ' (manuellt angivet)' . ( $accounts && ! in_array( $manual, array_column( $accounts, 'id' ), true ) ? ' – OBS: ingår inte bland kontona token har åtkomst till' : '' ) : ( $id ? $id . ' (automatiskt identifierat)' : ( 'instagram_login' === $model ? 'används ej (me/media)' : ( count( $accounts ) > 1 ? 'flera konton – välj ett och spara' : 'saknas' ) ) ) );
			if ( 'facebook_login' === $model ) {
				$perm = $this->get( 'me/permissions', array(), 8, 0 );
				if ( $perm['ok'] ) {
					$granted = array();
					foreach ( (array) ( $perm['data']['data'] ?? array() ) as $p ) { if ( 'granted' === ( $p['status'] ?? '' ) ) { $granted[] = (string) $p['permission']; } }
					$missing = array_diff( array( 'instagram_basic', 'pages_show_list' ), $granted );
					$add( 'Behörigheter', ! $missing, $missing ? 'Saknas: ' . implode( ', ', $missing ) . ' · beviljade: ' . implode( ', ', $granted ) : 'Beviljade: ' . implode( ', ', $granted ) );
				} else {
					$add( 'Behörigheter', null, 'Kunde inte läsas (normalt för sid-token): ' . $perm['error'] );
				}
			}
			$path = $this->media_path();
			if ( '' !== $path ) {
				$media = $this->get( $path, array( 'fields' => self::MEDIA_FIELDS, 'limit' => '3' ), 10, 0 );
				$list  = $media['ok'] && is_array( $media['data']['data'] ?? null ) ? $media['data']['data'] : array();
				$add( 'Hämta inlägg (' . $path . ')', $media['ok'], $media['ok'] ? count( $list ) . ' inlägg i provhämtningen' . ( $list ? ' · typer: ' . implode( ', ', array_unique( array_column( $list, 'media_type' ) ) ) : ' (kontot har inga inlägg?)' ) : $media['error'] );
				if ( $list ) {
					$first = $list[0];
					$src   = (string) ( 'VIDEO' === ( $first['media_type'] ?? '' ) ? ( $first['thumbnail_url'] ?? '' ) : ( $first['media_url'] ?? '' ) );
					$probe = $this->download( $src, 'test', false );
					$add( 'Hämta bild (' . ( (string) wp_parse_url( $src, PHP_URL_HOST ) ?: '–' ) . ')', '' !== $probe['file'], '' !== $probe['file'] ? 'OK – bilden kan sparas lokalt' : $probe['error'] );
				}
			} else {
				$add( 'Hämta inlägg', false, 'Account ID saknas – välj konto eller ange ID manuellt.' );
			}
		}
		$dir = self::upload_dir();
		$add( 'Uppladdningsmapp', wp_mkdir_p( $dir['path'] ) && wp_is_writable( $dir['path'] ), $dir['path'] );
		$add( 'Bildbehandling', function_exists( 'imagecreatefromjpeg' ) || extension_loaded( 'imagick' ), extension_loaded( 'imagick' ) ? 'Imagick' : ( function_exists( 'imagecreatefromjpeg' ) ? 'GD' : 'GD/Imagick saknas – bilder sparas utan storleksanpassning' ) );
		$add( 'Modul och schema', \FinetTroubleshoot\Core\Modules::enabled( 'instagram' ) ? true : null, \FinetTroubleshoot\Core\Modules::enabled( 'instagram' ) ? 'Modulen är på · nästa schemalagda hämtning: ' . ( wp_next_scheduled( 'wpfinet_instagram_sync' ) ? wp_date( 'Y-m-d H:i', (int) wp_next_scheduled( 'wpfinet_instagram_sync' ) ) : 'inte schemalagd' ) : 'OBS: modulen är avstängd – flödet visas inte för besökare och hämtas inte automatiskt. Slå på den under Moduler & integrationer.' );
		$add( 'Lokalt sparade inlägg', count( self::media() ) > 0 ? true : null, count( self::media() ) . ' · senaste resultat: ' . ( self::state()['last_result'] ?: '–' ) );
		$ok     = ! in_array( false, array_column( $steps, 'ok' ), true );
		$result = array( 'ok' => $ok, 'time' => time(), 'model' => $model, 'steps' => $steps, 'accounts' => $accounts );
		update_option( self::TEST, $result, false );
		Logger::log( 'instagram', $ok ? 'info' : 'warning', 'Anslutningstest: ' . ( $ok ? 'OK' : 'fel hittades' ) );
		return $result;
	}

	/** @return array<string,mixed> */
	public function health(): array {
		$state = self::state();
		return array( 'connected' => 'none' !== Secrets::source( 'instagram_token' ), 'token_source' => Secrets::source( 'instagram_token' ), 'token_status' => Secrets::status( 'instagram_token' ), 'model' => 'none' !== Secrets::source( 'instagram_token' ) ? $this->model() : (string) self::settings()['api_model'], 'account_id' => $this->account_id( false ), 'username' => $state['username'], 'account_type' => $state['account_type'], 'token_expires' => (int) $state['token_expires'], 'last_sync' => (int) $state['last_sync'], 'last_result' => $state['last_result'], 'last_error' => $state['last_error'], 'posts' => count( self::media() ), 'next_sync' => (int) wp_next_scheduled( 'wpfinet_instagram_sync' ), 'circuit_open' => $this->api()->breaker()->is_open() );
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
		$settings = self::settings();
		if ( 'facebook_login' === $settings['api_model'] ) { self::update_settings( array( 'api_model' => 'auto' ) ); }
		return $this->connect_token( (string) $ld['access_token'], absint( $ld['expires_in'] ?? 0 ) );
	}
}
