<?php
/**
 * Access API settings. Only the base URL and the legacy WPGetAPI IDs are known facts; the endpoint path,
 * authentication and response schema are intentionally empty until confirmed (see missing()).
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Access;

use FinetTroubleshoot\Core\Secrets;

final class AccessSettings {
	public const OPTION = 'wpfinet_access_settings';
	public const KNOWN_BASE_URL = 'https://access.finet.se/api/onapi/2.4/';
	public const LEGACY_WPGETAPI = array( 'api_id' => 'finet_access', 'endpoint_id' => 'get_access_data' );

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return array(
			'adapter'               => 'http',           // http | mock (mock = fictitious test data, never in production).
			'mode'                  => 'index',          // index = scheduled full download into a local index; lookup = one request per search.
			'base_url'              => self::KNOWN_BASE_URL,
			'endpoint_path'         => '',               // UNKNOWN – must be taken from the WPGetAPI configuration / API docs.
			'method'                => 'GET',
			'auth'                  => 'none',           // none | bearer | header | basic | query
			'auth_header'           => '',
			'auth_query_param'      => '',
			'extra_query'           => '',               // Non-secret query parameters, one key=value per line.
			'lookup_params'         => array( 'street' => '', 'number' => '', 'letter' => '' ),
			'data_path'             => '',               // Dot path to the list of addresses in the JSON response ('' = root).
			'field_street'          => 'streetName',
			'field_number'          => 'streetNumber',
			'field_letter'          => 'streetLittera',
			'field_state'           => 'accessState',
			'connected_values'      => 'CONNECTED',
			'timeout'               => 3,
			'sync_timeout'          => 20,
			'sync_interval'         => 'hourly',
			'stale_after_hours'     => 24,
			'max_age_hours'         => 168,
			'min_rows'              => 1,
			'max_drop_percent'      => 50,
			'rate_limit'            => 20,
			'trust_cloudflare'      => false,
			'cta_connected_url'     => '/tjanster',
			'cta_connected_label'   => 'Se våra tjänster',
			'cta_not_connected_url' => '/kontakta-oss',
			'cta_not_connected_label' => 'Kontakta oss',
			'text_connected'        => 'Din adress är ansluten till Finets nät.',
			'text_not_connected'    => 'Din adress är inte ansluten ännu.',
			'text_no_match'         => 'Vi hittade inte adressen. Kontrollera stavning, nummer och eventuell bokstav.',
			'text_letter_required'  => 'Adressen finns med bokstav. Lägg till bokstaven, till exempel 12B.',
			'text_invalid'          => 'Ange gatunamn och nummer, till exempel Storgatan 12B.',
			'text_unavailable'      => 'Adresskontrollen är tillfälligt otillgänglig. Försök igen senare eller kontakta oss.',
			'text_stale'            => 'Uppgiften kunde inte bekräftas mot det senaste underlaget.',
		);
	}

	/** @return array<string,mixed> */
	public static function get(): array {
		$stored = get_option( self::OPTION, array() );
		return array_replace( self::defaults(), is_array( $stored ) ? $stored : array() );
	}

	/** Hosts the Access API may live on (SSRF protection). Extend only via wp-config: WPFINET_ACCESS_ALLOWED_HOSTS. @return array<int,string> */
	public static function allowed_hosts(): array {
		$hosts = array( 'access.finet.se' );
		if ( defined( 'WPFINET_ACCESS_ALLOWED_HOSTS' ) ) {
			$hosts = array_merge( $hosts, array_map( 'trim', explode( ',', (string) WPFINET_ACCESS_ALLOWED_HOSTS ) ) );
		}
		return array_values( array_filter( array_unique( array_map( 'strtolower', $hosts ) ) ) );
	}

	public static function mock_allowed(): bool {
		return 'production' !== wp_get_environment_type() || ( defined( 'WPFINET_ACCESS_ALLOW_MOCK' ) && WPFINET_ACCESS_ALLOW_MOCK );
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public static function sanitize( array $input ): array {
		$d   = self::defaults();
		$cur = self::get();
		$out = $cur;
		$pick = static fn ( string $key, array $allowed ) => in_array( $input[ $key ] ?? '', $allowed, true ) ? (string) $input[ $key ] : (string) $cur[ $key ];
		$out['adapter']   = $pick( 'adapter', array( 'http', 'mock' ) );
		$out['mode']      = $pick( 'mode', array( 'index', 'lookup' ) );
		$out['method']    = $pick( 'method', array( 'GET', 'POST' ) );
		$out['auth']      = $pick( 'auth', array( 'none', 'bearer', 'header', 'basic', 'query' ) );
		$out['sync_interval'] = $pick( 'sync_interval', array( 'wpfinet_15min', 'wpfinet_30min', 'hourly', 'twicedaily', 'daily' ) );
		if ( isset( $input['base_url'] ) ) {
			$url  = esc_url_raw( trim( (string) $input['base_url'] ), array( 'https' ) );
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			$out['base_url'] = $url && in_array( $host, self::allowed_hosts(), true ) ? trailingslashit( $url ) : $cur['base_url'];
		}
		if ( isset( $input['endpoint_path'] ) ) {
			$path = ltrim( trim( (string) $input['endpoint_path'] ), '/' );
			$out['endpoint_path'] = preg_match( '#^[A-Za-z0-9._~\-/{}]*$#', $path ) && ! str_contains( $path, '..' ) ? $path : $cur['endpoint_path'];
		}
		foreach ( array( 'auth_header', 'auth_query_param' ) as $key ) {
			if ( isset( $input[ $key ] ) ) { $out[ $key ] = preg_replace( '/[^A-Za-z0-9_\-]/', '', (string) $input[ $key ] ); }
		}
		if ( isset( $input['extra_query'] ) ) {
			$lines = array();
			foreach ( preg_split( '/\R/', (string) $input['extra_query'] ) as $line ) {
				if ( preg_match( '/^\s*([A-Za-z0-9_\-\.\[\]]+)\s*=\s*(.*?)\s*$/', $line, $m ) ) { $lines[] = $m[1] . '=' . sanitize_text_field( $m[2] ); }
			}
			$out['extra_query'] = implode( "\n", array_slice( $lines, 0, 20 ) );
		}
		if ( isset( $input['lookup_params'] ) && is_array( $input['lookup_params'] ) ) {
			foreach ( array( 'street', 'number', 'letter' ) as $k ) { $out['lookup_params'][ $k ] = preg_replace( '/[^A-Za-z0-9_\-\.]/', '', (string) ( $input['lookup_params'][ $k ] ?? '' ) ); }
		}
		foreach ( array( 'data_path', 'field_street', 'field_number', 'field_letter', 'field_state' ) as $key ) {
			if ( isset( $input[ $key ] ) ) { $out[ $key ] = preg_replace( '/[^A-Za-z0-9_\-\.]/', '', (string) $input[ $key ] ); }
		}
		if ( isset( $input['connected_values'] ) ) { $out['connected_values'] = sanitize_text_field( (string) $input['connected_values'] ); }
		$ints = array( 'timeout' => array( 1, 5 ), 'sync_timeout' => array( 5, 60 ), 'stale_after_hours' => array( 1, 720 ), 'max_age_hours' => array( 1, 2160 ), 'min_rows' => array( 0, 1000000 ), 'max_drop_percent' => array( 0, 100 ), 'rate_limit' => array( 1, 600 ) );
		foreach ( $ints as $key => [ $min, $max ] ) {
			if ( isset( $input[ $key ] ) ) { $out[ $key ] = min( $max, max( $min, absint( $input[ $key ] ) ) ); }
		}
		if ( array_key_exists( 'trust_cloudflare', $input ) || isset( $input['_form'] ) ) { $out['trust_cloudflare'] = ! empty( $input['trust_cloudflare'] ); }
		foreach ( array( 'cta_connected_url', 'cta_not_connected_url' ) as $key ) {
			if ( isset( $input[ $key ] ) ) { $out[ $key ] = \FinetTroubleshoot\Import\SchemaValidator::safe_url( (string) $input[ $key ] ) ?: $d[ $key ]; }
		}
		foreach ( array( 'cta_connected_label', 'cta_not_connected_label', 'text_connected', 'text_not_connected', 'text_no_match', 'text_letter_required', 'text_invalid', 'text_unavailable', 'text_stale' ) as $key ) {
			if ( isset( $input[ $key ] ) ) { $out[ $key ] = sanitize_text_field( (string) $input[ $key ] ) ?: $d[ $key ]; }
		}
		if ( 'mock' === $out['adapter'] && ! self::mock_allowed() ) { $out['adapter'] = 'http'; }
		$out['max_age_hours'] = max( $out['max_age_hours'], $out['stale_after_hours'] );
		return $out;
	}

	public static function update( array $input ): void {
		update_option( self::OPTION, self::sanitize( $input ), false );
	}

	/** What is still unknown/missing for a real integration. @return array<int,string> */
	public static function missing( ?array $s = null ): array {
		$s       = $s ?? self::get();
		$missing = array();
		if ( 'mock' === $s['adapter'] ) {
			return $missing;
		}
		if ( '' === $s['endpoint_path'] ) {
			$missing[] = __( 'Endpointens relativa sökväg under base URL (finns i WPGetAPI-konfigurationen för endpoint "get_access_data" eller i API-dokumentationen).', 'finet-troubleshoot' );
		}
		if ( 'none' !== $s['auth'] && 'none' === Secrets::source( 'access_api_secret' ) ) {
			$missing[] = __( 'API-hemlighet för vald autentiseringsmetod (lägg i wp-config.php som WPFINET_ACCESS_API_SECRET eller spara krypterat här).', 'finet-troubleshoot' );
		}
		if ( 'header' === $s['auth'] && '' === $s['auth_header'] ) { $missing[] = __( 'Namnet på autentiseringsheadern.', 'finet-troubleshoot' ); }
		if ( 'query' === $s['auth'] && '' === $s['auth_query_param'] ) { $missing[] = __( 'Namnet på query-parametern för API-nyckeln.', 'finet-troubleshoot' ); }
		if ( 'basic' === $s['auth'] && 'none' === Secrets::source( 'access_api_username' ) ) { $missing[] = __( 'Användarnamn för Basic-autentisering.', 'finet-troubleshoot' ); }
		if ( 'lookup' === $s['mode'] && '' === $s['lookup_params']['street'] ) {
			$missing[] = __( 'Query-parametrar för uppslag av en adress (bara om API:t stödjer filtrerad sökning – annars använd indexläge).', 'finet-troubleshoot' );
		}
		return $missing;
	}

	public static function endpoint_url( ?array $s = null ): string {
		$s = $s ?? self::get();
		return '' === $s['endpoint_path'] ? '' : trailingslashit( (string) $s['base_url'] ) . $s['endpoint_path'];
	}
}
