<?php
/**
 * Talks to the Finet Access API (or to the fictitious mock data set). Builds requests strictly from settings – no
 * endpoint, header or credential is hard-coded – and maps the response to normalised index rows.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Access;

use FinetTroubleshoot\Core\Http\HttpClient;
use FinetTroubleshoot\Core\Secrets;

final class AccessClient {
	/** Clearly fictitious streets for staging/E2E tests (never real addresses). */
	public const MOCK_DATA = array(
		array( 'streetName' => 'Exempelgatan', 'streetNumber' => '1', 'streetLittera' => '', 'accessState' => 'CONNECTED' ),
		array( 'streetName' => 'Exempelgatan', 'streetNumber' => '2', 'streetLittera' => '', 'accessState' => 'NOT_CONNECTED' ),
		array( 'streetName' => 'Exempelgatan', 'streetNumber' => '3', 'streetLittera' => 'A', 'accessState' => 'CONNECTED' ),
		array( 'streetName' => 'Exempelgatan', 'streetNumber' => '3', 'streetLittera' => 'B', 'accessState' => 'PLANNED' ),
		array( 'streetName' => 'Åtestvägen', 'streetNumber' => '5', 'streetLittera' => '', 'accessState' => 'CONNECTED' ),
		array( 'streetName' => 'Atestvägen', 'streetNumber' => '5', 'streetLittera' => '', 'accessState' => 'NOT_CONNECTED' ),
		array( 'streetName' => 'Testallén', 'streetNumber' => '12', 'streetLittera' => 'b', 'accessState' => 'CONNECTED' ),
	);

	/** @param array<string,mixed> $settings */
	public function __construct( private array $settings ) {}

	public function http(): HttpClient {
		return new HttpClient( 'access', AccessSettings::allowed_hosts() );
	}

	/**
	 * @param array<string,string> $query Extra (lookup) query parameters.
	 * @return array{ok:bool,status:int,error:string,duration_ms:int,data:mixed,bytes:int,content_type:string}
	 */
	public function request( array $query, float $timeout, int $retries ): array {
		if ( 'mock' === $this->settings['adapter'] ) {
			return array( 'ok' => true, 'status' => 200, 'error' => '', 'duration_ms' => 0, 'data' => self::MOCK_DATA, 'bytes' => 0, 'content_type' => 'application/json (testdata)' );
		}
		$missing = AccessSettings::missing( $this->settings );
		if ( ! $missing && 'wpgetapi' === $this->settings['adapter'] ) {
			return $this->wpgetapi();
		}
		if ( $missing ) {
			return array( 'ok' => false, 'status' => 0, 'error' => 'Integrationen är inte konfigurerad: ' . implode( ' ', $missing ), 'duration_ms' => 0, 'data' => null, 'bytes' => 0, 'content_type' => '' );
		}
		$url     = AccessSettings::endpoint_url( $this->settings );
		$params  = array();
		foreach ( preg_split( '/\R/', (string) $this->settings['extra_query'] ) as $line ) {
			if ( str_contains( $line, '=' ) ) {
				[ $k, $v ] = explode( '=', $line, 2 );
				$params[ trim( $k ) ] = trim( $v );
			}
		}
		$params  = array_merge( $params, $query );
		$headers = array( 'Accept' => 'application/json' );
		$secret  = Secrets::get( 'access_api_secret' );
		switch ( $this->settings['auth'] ) {
			case 'bearer':
				$headers['Authorization'] = 'Bearer ' . $secret;
				break;
			case 'header':
				$headers[ (string) $this->settings['auth_header'] ] = $secret;
				break;
			case 'basic':
				$headers['Authorization'] = 'Basic ' . base64_encode( Secrets::get( 'access_api_username' ) . ':' . $secret ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				break;
			case 'query':
				$params[ (string) $this->settings['auth_query_param'] ] = $secret;
				break;
		}
		$body = null;
		if ( 'POST' === $this->settings['method'] ) {
			$headers['Content-Type'] = 'application/json';
			$body = wp_json_encode( $params );
		} elseif ( $params ) {
			$url = add_query_arg( array_map( 'rawurlencode', $params ), $url );
		}
		$response = $this->http()->request( $url, array( 'method' => $this->settings['method'], 'headers' => $headers, 'body' => $body, 'timeout' => $timeout, 'retries' => $retries ) );
		$data     = null;
		if ( $response['ok'] ) {
			$data = json_decode( $response['body'], true );
			if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
				$response['ok']    = false;
				$response['error'] = 'Svaret är inte giltig JSON.';
			}
		}
		return array( 'ok' => $response['ok'], 'status' => $response['status'], 'error' => $response['error'], 'duration_ms' => $response['duration_ms'], 'data' => $data, 'bytes' => strlen( $response['body'] ), 'content_type' => (string) ( $response['headers']['content-type'] ?? '' ) );
	}

	/**
	 * Reuses the site's existing WPGetAPI configuration (API "finet_access", endpoint "get_access_data") so the
	 * endpoint path and credentials never have to be copied. Only called from the background sync and the admin
	 * test – never while a page is rendered or a visitor searches – so WPGetAPI's own timeout cannot block visitors.
	 *
	 * @return array{ok:bool,status:int,error:string,duration_ms:int,data:mixed,bytes:int,content_type:string}
	 */
	private function wpgetapi(): array {
		$start = microtime( true );
		$out   = array( 'ok' => false, 'status' => 0, 'error' => '', 'duration_ms' => 0, 'data' => null, 'bytes' => 0, 'content_type' => 'WPGetAPI' );
		try {
			$data = \call_user_func( 'wpgetapi_endpoint', (string) $this->settings['wpgetapi_api_id'], (string) $this->settings['wpgetapi_endpoint_id'], array( 'debug' => false ) );
		} catch ( \Throwable $e ) {
			$out['error']       = 'WPGetAPI kastade ett fel: ' . $e->getMessage();
			$out['duration_ms'] = (int) round( ( microtime( true ) - $start ) * 1000 );
			return $out;
		}
		$out['duration_ms'] = (int) round( ( microtime( true ) - $start ) * 1000 );
		if ( is_wp_error( $data ) ) {
			$out['error'] = 'WPGetAPI: ' . $data->get_error_message();
			return $out;
		}
		if ( is_string( $data ) ) {
			$out['bytes'] = strlen( $data );
			$decoded      = json_decode( $data, true );
			if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
				$out['error'] = '' === trim( $data ) ? 'WPGetAPI returnerade ett tomt svar (kontrollera endpointen i WPGetAPI).' : 'WPGetAPI returnerade text som inte är giltig JSON. Sätt "Results Format" till JSON eller PHP array i WPGetAPI.';
				return $out;
			}
			$data = $decoded;
		} elseif ( is_object( $data ) || is_array( $data ) ) {
			$data = json_decode( (string) wp_json_encode( $data ), true ); // Normalises stdClass items to arrays.
		}
		if ( ! is_array( $data ) ) {
			$out['error'] = 'WPGetAPI returnerade inget svar (false/null). Kontrollera API-ID, endpoint-ID och nyckeln i WPGetAPI.';
			return $out;
		}
		if ( ! $out['bytes'] ) {
			$out['bytes'] = strlen( (string) wp_json_encode( $data ) );
		}
		$out['ok']     = true;
		$out['status'] = 200;
		$out['data']   = $data;
		return $out;
	}

	/** Finds the list of address objects at the configured dot path. @return array<int,array<string,mixed>>|null */
	public function items( mixed $data ): ?array {
		$path = trim( (string) $this->settings['data_path'], '.' );
		if ( '' !== $path ) {
			foreach ( explode( '.', $path ) as $segment ) {
				if ( ! is_array( $data ) || ! array_key_exists( $segment, $data ) ) {
					return null;
				}
				$data = $data[ $segment ];
			}
		}
		if ( ! is_array( $data ) ) {
			return null;
		}
		$items = array_is_list( $data ) ? $data : array_values( $data );
		return array_values( array_filter( $items, 'is_array' ) );
	}

	/** @param array<string,mixed> $item */
	private static function field( array $item, string $path ): mixed {
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $item ) || ! array_key_exists( $segment, $item ) ) {
				return null;
			}
			$item = $item[ $segment ];
		}
		return $item;
	}

	/**
	 * @param array<int,array<string,mixed>> $items
	 * @return array{rows:array<int,array{street_key:string,number:int,letter:string,state:string,connected:bool}>,skipped:int}
	 */
	public function rows( array $items ): array {
		$connected = array_filter( array_map( static fn ( $v ) => strtoupper( trim( $v ) ), explode( ',', (string) $this->settings['connected_values'] ) ) );
		$rows      = array();
		$skipped   = 0;
		foreach ( $items as $item ) {
			$street = self::field( $item, (string) $this->settings['field_street'] );
			[ $number, $letter ] = Normalizer::number_and_letter( self::field( $item, (string) $this->settings['field_number'] ), self::field( $item, (string) $this->settings['field_letter'] ) );
			$key    = is_scalar( $street ) ? Normalizer::street_key( (string) $street ) : '';
			if ( '' === $key || $number < 1 ) {
				++$skipped;
				continue;
			}
			$state  = strtoupper( trim( (string) ( self::field( $item, (string) $this->settings['field_state'] ) ?? '' ) ) );
			$rows[] = array( 'street_key' => $key, 'number' => $number, 'letter' => $letter, 'state' => $state, 'connected' => in_array( $state, $connected, true ) );
		}
		return array( 'rows' => $rows, 'skipped' => $skipped );
	}

	/**
	 * Describes the response structure without exposing data (values are masked) for the admin schema preview.
	 *
	 * @return array<string,mixed>
	 */
	public static function describe( mixed $data, int $depth = 0 ): array {
		if ( is_array( $data ) ) {
			if ( array_is_list( $data ) ) {
				return array( 'type' => 'list', 'count' => count( $data ), 'item' => $data && $depth < 4 ? self::describe( $data[0], $depth + 1 ) : null );
			}
			$keys = array();
			foreach ( array_slice( $data, 0, 40, true ) as $k => $v ) { $keys[ (string) $k ] = $depth < 4 ? self::describe( $v, $depth + 1 ) : array( 'type' => gettype( $v ) ); }
			return array( 'type' => 'object', 'keys' => $keys );
		}
		if ( is_string( $data ) ) {
			$len = mb_strlen( $data );
			$masked = $len <= 2 ? str_repeat( '•', $len ) : mb_substr( $data, 0, 1 ) . str_repeat( '•', min( 8, $len - 2 ) ) . mb_substr( $data, -1 );
			// Enumeration-like upper-case values (e.g. CONNECTED) are shown in full: they describe the schema, not a person.
			return array( 'type' => 'string', 'sample' => preg_match( '/^[A-Z_]{3,30}$/', $data ) ? $data : $masked );
		}
		return array( 'type' => gettype( $data ) );
	}

	/** Dot paths to lists of objects found in the response (helps to configure data_path). @return array<string,int> */
	public static function list_paths( mixed $data, string $prefix = '', int $depth = 0 ): array {
		$found = array();
		if ( ! is_array( $data ) || $depth > 4 ) {
			return $found;
		}
		if ( array_is_list( $data ) ) {
			if ( $data && is_array( $data[0] ) && ! array_is_list( $data[0] ) ) { $found[ '' === $prefix ? '(rot)' : $prefix ] = count( $data ); }
			return $found;
		}
		foreach ( $data as $k => $v ) { $found += self::list_paths( $v, ltrim( $prefix . '.' . $k, '.' ), $depth + 1 ); }
		return $found;
	}
}
