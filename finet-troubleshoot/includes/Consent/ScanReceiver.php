<?php
/**
 * Receives results from the headless scanner (scanner/scan.mjs) – POST /wpfinet/v1/consent/scan, authenticated with
 * a WordPress Application Password of a user with manage_wpfinet_settings. Available in every consent mode so the
 * current site (still protected by WPConsent) can be inventoried before switching.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Consent;

use FinetTroubleshoot\Core\Logger;

final class ScanReceiver {
	public const OPTION = 'wpfinet_consent_scans';

	public function register_hooks(): void { add_action( 'rest_api_init', array( $this, 'routes' ) ); }

	public function routes(): void {
		register_rest_route( 'wpfinet/v1', '/consent/scan', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'permission_callback' => static fn (): bool => current_user_can( 'manage_wpfinet_settings' ) || current_user_can( 'manage_options' ),
			'callback'            => array( $this, 'receive' ),
		) );
	}

	public function receive( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) || ! isset( $body['observations'] ) || ! is_array( $body['observations'] ) ) {
			return new \WP_Error( 'wpfinet_invalid_scan', 'observations saknas', array( 'status' => 400 ) );
		}
		return rest_ensure_response( self::ingest( $body ) );
	}

	/**
	 * @param array<string,mixed> $scan { scanner, base_url, started_at, pages[], scenarios[], observations[{name,domain,storage_type,party,lifetime,scenario,page}], requests[{host,scenario,page,type}] }
	 * @return array<string,mixed>
	 */
	public static function ingest( array $scan ): array {
		$home    = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$new     = array();
		$seen    = 0;
		$keys    = array();
		foreach ( array_slice( (array) $scan['observations'], 0, 5000 ) as $o ) {
			if ( ! is_array( $o ) ) { continue; }
			$domain = ltrim( strtolower( (string) ( $o['domain'] ?? '' ) ), '.' );
			$party  = '' === $domain || $domain === $home || str_ends_with( $home, '.' . $domain ) || str_ends_with( $domain, '.' . $home ) ? 'first' : 'third';
			$before = 'before_consent' === ( $o['scenario'] ?? '' ) || 'reject' === ( $o['scenario'] ?? '' );
			$res    = Inventory::observe( array( 'name' => $o['name'] ?? '', 'domain' => $domain, 'storage_type' => $o['storage_type'] ?? 'cookie', 'party' => $party, 'lifetime' => $o['lifetime'] ?? '', 'before_consent' => $before, 'page' => $o['page'] ?? '', 'source' => 'scan' ) );
			$keys[] = ( $o['storage_type'] ?? 'cookie' ) . ':' . ( $o['name'] ?? '' ) . '@' . $domain;
			if ( 'new' === $res ) { $new[] = (string) ( $o['name'] ?? '' ); } elseif ( 'seen' === $res ) { ++$seen; }
		}
		$hosts = array();
		foreach ( array_slice( (array) ( $scan['requests'] ?? array() ), 0, 5000 ) as $r ) {
			if ( ! is_array( $r ) ) { continue; }
			$host = strtolower( (string) preg_replace( '/[^A-Za-z0-9.\-]/', '', (string) ( $r['host'] ?? '' ) ) );
			if ( ! $host || $host === $home || str_ends_with( $host, '.' . $home ) ) { continue; }
			$hosts[ $host ][ (string) ( $r['scenario'] ?? '' ) ] = true;
			if ( 'before_consent' === ( $r['scenario'] ?? '' ) || 'reject' === ( $r['scenario'] ?? '' ) ) {
				Inventory::observe( array( 'name' => $host, 'domain' => $host, 'storage_type' => 'service', 'party' => 'third', 'before_consent' => true, 'page' => $r['page'] ?? '', 'source' => 'scan' ) );
			} else {
				Inventory::observe( array( 'name' => $host, 'domain' => $host, 'storage_type' => 'service', 'party' => 'third', 'page' => $r['page'] ?? '', 'source' => 'scan' ) );
			}
		}
		$history  = get_option( self::OPTION, array() );
		$history  = is_array( $history ) ? $history : array();
		$previous = $history ? end( $history ) : null;
		$keys     = array_values( array_unique( $keys ) );
		$summary  = array(
			'received_at'  => gmdate( 'c' ),
			'started_at'   => sanitize_text_field( (string) ( $scan['started_at'] ?? '' ) ),
			'scanner'      => sanitize_text_field( (string) ( $scan['scanner'] ?? '' ) ),
			'pages'        => array_slice( array_map( 'esc_url_raw', (array) ( $scan['pages'] ?? array() ) ), 0, 50 ),
			'scenarios'    => array_slice( array_map( 'sanitize_key', (array) ( $scan['scenarios'] ?? array() ) ), 0, 10 ),
			'observations' => count( $keys ),
			'new'          => array_slice( $new, 0, 100 ),
			'third_party_hosts' => array_map( static fn ( $s ) => array_keys( $s ), $hosts ),
			'keys'         => array_slice( $keys, 0, 2000 ),
			'disappeared'  => $previous ? array_values( array_slice( array_diff( (array) ( $previous['keys'] ?? array() ), $keys ), 0, 100 ) ) : array(),
			'warnings'     => array_slice( array_map( 'sanitize_text_field', (array) ( $scan['warnings'] ?? array() ) ), 0, 50 ),
		);
		$history[] = $summary;
		update_option( self::OPTION, array_slice( $history, -20 ), false );
		Logger::log( 'consent', count( $new ) ? 'warning' : 'info', sprintf( 'Skanning mottagen: %d observationer, %d nya förslag.', count( $keys ), count( $new ) ) );
		return array( 'ok' => true, 'new' => count( $new ), 'seen' => $seen, 'observations' => count( $keys ), 'disappeared' => count( $summary['disappeared'] ) );
	}

	/** @return array<string,mixed>|null */
	public static function last(): ?array {
		$h = get_option( self::OPTION, array() );
		return is_array( $h ) && $h ? end( $h ) : null;
	}
}
