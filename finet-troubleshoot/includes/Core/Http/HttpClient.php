<?php
/**
 * Hardened outbound HTTP for integrations: HTTPS only, host allowlist (SSRF protection), wp_safe_remote_request
 * (blocks private/loopback targets), no redirects, short timeouts, size cap, circuit breaker and bounded retries
 * (retries only when the caller says it is running in the background).
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Core\Http;

use FinetTroubleshoot\Core\Logger;

final class HttpClient {
	/** @param array<int,string> $allowed_hosts */
	public function __construct( private string $service, private array $allowed_hosts, private ?CircuitBreaker $breaker = null ) {
		$this->breaker ??= new CircuitBreaker( $service );
	}

	public function breaker(): CircuitBreaker { return $this->breaker; }

	/** Exact host match, or suffix match for entries starting with a dot (".cdninstagram.com"). @param array<int,string> $allowed */
	public static function host_allowed( string $host, array $allowed ): bool {
		$host = strtolower( $host );
		foreach ( $allowed as $entry ) {
			$entry = strtolower( trim( $entry ) );
			if ( '' === $entry ) { continue; }
			if ( $host === $entry || ( str_starts_with( $entry, '.' ) && str_ends_with( $host, $entry ) && strlen( $host ) > strlen( $entry ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param array<string,mixed> $options method, headers, body, timeout (s), retries (background only), max_bytes.
	 * @return array{ok:bool,status:int,body:string,headers:array<string,string>,error:string,duration_ms:int}
	 */
	public function request( string $url, array $options = array() ): array {
		$fail = static fn ( string $error, int $status = 0, int $ms = 0 ): array => array( 'ok' => false, 'status' => $status, 'body' => '', 'headers' => array(), 'error' => $error, 'duration_ms' => $ms );
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( 'https' !== strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) ) ) {
			return $fail( 'Endast https tillåts.' );
		}
		if ( ! $host || ! self::host_allowed( $host, $this->allowed_hosts ) ) {
			return $fail( sprintf( 'Värden %s finns inte i allowlist.', $host ?: '(saknas)' ) );
		}
		if ( $this->breaker->is_open() ) {
			return $fail( 'Kretsbrytaren är öppen efter upprepade fel; nytt försök senare.' );
		}
		$timeout   = max( 1.0, min( 30.0, (float) ( $options['timeout'] ?? 3 ) ) );
		$retries   = max( 0, min( 3, (int) ( $options['retries'] ?? 0 ) ) );
		$max_bytes = max( 1024, (int) ( $options['max_bytes'] ?? 20 * MB_IN_BYTES ) );
		$args      = array(
			'method'              => strtoupper( (string) ( $options['method'] ?? 'GET' ) ),
			'headers'             => (array) ( $options['headers'] ?? array() ),
			'body'                => $options['body'] ?? null,
			'timeout'             => $timeout,
			'redirection'         => 0,
			'reject_unsafe_urls'  => true,
			'limit_response_size' => $max_bytes,
			'user-agent'          => 'WPFINET/' . ( defined( 'FINET_TS_VERSION' ) ? FINET_TS_VERSION : '3' ) . '; ' . home_url( '/' ),
		);
		$attempt = 0;
		do {
			$start    = microtime( true );
			$response = wp_safe_remote_request( $url, $args );
			$ms       = (int) round( ( microtime( true ) - $start ) * 1000 );
			if ( is_wp_error( $response ) ) {
				$result = $fail( $response->get_error_message(), 0, $ms );
			} else {
				$status = (int) wp_remote_retrieve_response_code( $response );
				$body   = (string) wp_remote_retrieve_body( $response );
				$headers = array();
				foreach ( (array) wp_remote_retrieve_headers( $response )->getAll() as $k => $v ) { $headers[ strtolower( (string) $k ) ] = is_array( $v ) ? implode( ', ', $v ) : (string) $v; }
				$result = array( 'ok' => $status >= 200 && $status < 300, 'status' => $status, 'body' => $body, 'headers' => $headers, 'error' => $status >= 200 && $status < 300 ? '' : 'HTTP ' . $status, 'duration_ms' => $ms );
			}
			$retryable = ! $result['ok'] && ( 0 === $result['status'] || $result['status'] >= 500 || 429 === $result['status'] );
			if ( ! $retryable || $attempt >= $retries ) {
				break;
			}
			++$attempt;
			usleep( (int) ( min( 8, 2 ** $attempt ) * 250000 ) ); // 0.5s, 1s, 2s – background jobs only.
		} while ( true );

		if ( $result['ok'] ) {
			$this->breaker->success();
		} elseif ( 0 === $result['status'] || $result['status'] >= 500 || 429 === $result['status'] ) {
			$this->breaker->failure( $result['error'] );
			Logger::log( $this->service, 'warning', 'Externt anrop misslyckades: ' . $result['error'], array( 'host' => $host, 'status' => $result['status'], 'ms' => $result['duration_ms'] ) );
		} else {
			Logger::log( $this->service, 'warning', 'Externt anrop gav fel: ' . $result['error'], array( 'host' => $host, 'status' => $result['status'] ) );
		}
		return $result;
	}
}
