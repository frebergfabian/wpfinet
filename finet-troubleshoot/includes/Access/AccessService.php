<?php
/**
 * Address check domain service: single-address lookups (index or live), background sync with safety guards,
 * health/status and admin connection test. Never returns more than one address' status.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Access;

use FinetTroubleshoot\Core\Logger;

final class AccessService {
	public const STATUSES = array( 'connected', 'not_connected', 'no_match', 'invalid', 'unavailable' );

	private array $settings;

	public function __construct( ?array $settings = null ) {
		$this->settings = $settings ?? AccessSettings::get();
	}

	public function client(): AccessClient { return new AccessClient( $this->settings ); }

	/** @return array{status:string,message:string,cta:array{url:string,label:string}|null,stale:bool,checked_at:string,source:string,hint:string} */
	public function lookup( string $input ): array {
		$address = Normalizer::parse( $input );
		if ( ! $address ) {
			return $this->result( 'invalid' );
		}
		$source = 'mock' === $this->settings['adapter'] ? 'mock' : $this->settings['mode'];
		if ( 'lookup' === $this->settings['mode'] && 'mock' !== $this->settings['adapter'] ) {
			return $this->live_lookup( $address );
		}
		$state = AddressIndex::state();
		if ( $state['generation'] < 1 || ! $state['last_success'] ) {
			return $this->result( 'unavailable', array( 'source' => $source, 'hint' => 'index_empty' ) );
		}
		$age = time() - $state['last_success'];
		if ( $age > (int) $this->settings['max_age_hours'] * HOUR_IN_SECONDS ) {
			return $this->result( 'unavailable', array( 'source' => $source, 'hint' => 'index_too_old', 'checked_at' => gmdate( 'c', $state['last_success'] ) ) );
		}
		$rows = AddressIndex::find( $address['street_key'], $address['number'] );
		return $this->evaluate( $address, $rows, $state['last_success'], $age > (int) $this->settings['stale_after_hours'] * HOUR_IN_SECONDS, $source );
	}

	/** @param array{street:string,number:int,letter:string,street_key:string} $address */
	private function live_lookup( array $address ): array {
		$cache_key = 'wpfinet_access_q_' . md5( $address['street_key'] . '|' . $address['number'] . '|' . $address['letter'] . '|' . wp_json_encode( $this->settings['lookup_params'] ) );
		$fresh     = get_transient( $cache_key );
		if ( is_array( $fresh ) ) {
			return $this->evaluate( $address, $fresh['rows'], (int) $fresh['time'], false, 'live-cache' );
		}
		$p     = $this->settings['lookup_params'];
		$query = array_filter( array( $p['street'] => $address['street'], $p['number'] => (string) $address['number'], $p['letter'] => strtoupper( $address['letter'] ) ), static fn ( $v, $k ) => '' !== (string) $k && '' !== $v, ARRAY_FILTER_USE_BOTH );
		$response = $this->client()->request( $query, (float) $this->settings['timeout'], 0 );
		$items    = $response['ok'] ? $this->client()->items( $response['data'] ) : null;
		if ( null === $items ) {
			$last = get_transient( 'wpfinet_access_lk_' . md5( $cache_key ) );
			if ( is_array( $last ) ) {
				return $this->evaluate( $address, $last['rows'], (int) $last['time'], true, 'live-stale' );
			}
			return $this->result( 'unavailable', array( 'source' => 'live' ) );
		}
		$rows = array_map( static fn ( $r ) => array( 'state' => $r['state'], 'connected' => (int) $r['connected'], 'letter' => $r['letter'] ), array_values( array_filter( $this->client()->rows( $items )['rows'], static fn ( $r ) => $r['street_key'] === $address['street_key'] && $r['number'] === $address['number'] ) ) );
		$entry = array( 'rows' => $rows, 'time' => time() );
		set_transient( $cache_key, $entry, 15 * MINUTE_IN_SECONDS );
		set_transient( 'wpfinet_access_lk_' . md5( $cache_key ), $entry, (int) $this->settings['max_age_hours'] * HOUR_IN_SECONDS );
		return $this->evaluate( $address, $rows, time(), false, 'live' );
	}

	/** @param array<int,array{state:string,connected:int|bool,letter:string}> $rows */
	private function evaluate( array $address, array $rows, int $time, bool $stale, string $source ): array {
		$exact = array_values( array_filter( $rows, static fn ( $r ) => (string) $r['letter'] === $address['letter'] ) );
		$extra = array( 'stale' => $stale, 'checked_at' => gmdate( 'c', $time ), 'source' => $source );
		if ( ! $exact ) {
			$letters = array_filter( $rows, static fn ( $r ) => '' !== (string) $r['letter'] );
			return $this->result( 'no_match', $extra + array( 'hint' => '' === $address['letter'] && $letters ? 'letter_required' : '' ) );
		}
		$connected = (bool) array_filter( $exact, static fn ( $r ) => ! empty( $r['connected'] ) );
		return $this->result( $connected ? 'connected' : 'not_connected', $extra );
	}

	/** @param array<string,mixed> $extra */
	public function result( string $status, array $extra = array() ): array {
		$s       = $this->settings;
		$hint    = (string) ( $extra['hint'] ?? '' );
		$message = array( 'connected' => $s['text_connected'], 'not_connected' => $s['text_not_connected'], 'no_match' => 'letter_required' === $hint ? $s['text_letter_required'] : $s['text_no_match'], 'invalid' => $s['text_invalid'], 'unavailable' => $s['text_unavailable'] )[ $status ];
		$cta     = null;
		if ( 'connected' === $status ) {
			$cta = array( 'url' => (string) $s['cta_connected_url'], 'label' => (string) $s['cta_connected_label'] );
		} elseif ( in_array( $status, array( 'not_connected', 'no_match', 'unavailable' ), true ) ) {
			$cta = array( 'url' => (string) $s['cta_not_connected_url'], 'label' => (string) $s['cta_not_connected_label'] );
		}
		$stale = ! empty( $extra['stale'] );
		return array(
			'status'     => $status,
			'message'    => $stale ? $message . ' ' . $s['text_stale'] : $message,
			'cta'        => $cta,
			'stale'      => $stale,
			'checked_at' => (string) ( $extra['checked_at'] ?? '' ),
			'source'     => (string) ( $extra['source'] ?? '' ),
			'hint'       => $hint,
		);
	}

	/** Background sync (WP-Cron / Task Scheduler / WP-CLI / admin). @return array{ok:bool,message:string,rows:int} */
	public function sync( bool $background = true ): array {
		$started = time();
		AddressIndex::update_state( array( 'last_attempt' => $started ) );
		if ( 'lookup' === $this->settings['mode'] && 'mock' !== $this->settings['adapter'] ) {
			return $this->sync_result( false, 'Läget "uppslag per sökning" använder inget index; ingen synk behövs.', 0 );
		}
		$response = $this->client()->request( array(), (float) ( $background ? $this->settings['sync_timeout'] : min( 10, (int) $this->settings['sync_timeout'] ) ), $background ? 2 : 0 );
		if ( ! $response['ok'] ) {
			return $this->sync_result( false, 'Synk misslyckades: ' . $response['error'] . '. Befintligt index behålls.', 0 );
		}
		$items = $this->client()->items( $response['data'] );
		if ( null === $items ) {
			return $this->sync_result( false, 'Hittade ingen adresslista på sökvägen "' . ( $this->settings['data_path'] ?: '(rot)' ) . '". Kontrollera fältmappningen i förhandsgranskningen. Befintligt index behålls.', 0 );
		}
		$mapped   = $this->client()->rows( $items );
		$count    = count( $mapped['rows'] );
		$previous = AddressIndex::state()['rows'];
		if ( $count < (int) $this->settings['min_rows'] || 0 === $count ) {
			return $this->sync_result( false, sprintf( 'Svaret innehöll %d giltiga adresser (%d hoppades över) – under miniminivån. Befintligt index behålls.', $count, $mapped['skipped'] ), 0 );
		}
		if ( $previous > 0 && $count < $previous * ( 100 - (int) $this->settings['max_drop_percent'] ) / 100 ) {
			return $this->sync_result( false, sprintf( 'Antalet adresser sjönk från %d till %d (mer än %d %%). Synken stoppades som säkerhetsåtgärd; befintligt index behålls.', $previous, $count, (int) $this->settings['max_drop_percent'] ), 0 );
		}
		$stored = AddressIndex::replace( $mapped['rows'] );
		return $this->sync_result( true, sprintf( 'Index uppdaterat: %d adresser (%d poster utan giltig adress hoppades över), %d ms.', $stored, $mapped['skipped'], $response['duration_ms'] ), $stored );
	}

	private function sync_result( bool $ok, string $message, int $rows ): array {
		AddressIndex::update_state( array( 'last_result' => $message, 'last_error' => $ok ? '' : $message ) );
		Logger::log( 'access', $ok ? 'info' : 'warning', $message );
		return array( 'ok' => $ok, 'message' => $message, 'rows' => $rows );
	}

	/** Admin connection test with masked schema preview. @return array<string,mixed> */
	public function test_connection(): array {
		$response = $this->client()->request( array(), 5.0, 0 );
		$out      = array( 'ok' => $response['ok'], 'status' => $response['status'], 'error' => $response['error'], 'duration_ms' => $response['duration_ms'], 'bytes' => $response['bytes'], 'content_type' => $response['content_type'], 'endpoint' => 'mock' === $this->settings['adapter'] ? '(testdata)' : AccessSettings::endpoint_url( $this->settings ) );
		if ( $response['ok'] ) {
			$items                = $this->client()->items( $response['data'] );
			$out['list_paths']    = AccessClient::list_paths( $response['data'] );
			$out['schema']        = AccessClient::describe( $response['data'] );
			$out['items_at_path'] = null === $items ? null : count( $items );
			$mapped               = null === $items ? null : $this->client()->rows( array_slice( $items, 0, 500 ) );
			$out['mapped_sample'] = null === $mapped ? null : array( 'valid' => count( $mapped['rows'] ), 'skipped' => $mapped['skipped'], 'states' => array_count_values( array_column( $mapped['rows'], 'state' ) ) );
		}
		Logger::log( 'access', $response['ok'] ? 'info' : 'warning', 'Anslutningstest: ' . ( $response['ok'] ? 'OK' : $response['error'] ), array( 'status' => $response['status'], 'ms' => $response['duration_ms'] ) );
		return $out;
	}

	/** @return array<string,mixed> */
	public function health(): array {
		$state   = AddressIndex::state();
		$breaker = $this->client()->http()->breaker()->state();
		$age     = $state['last_success'] ? time() - $state['last_success'] : null;
		return array(
			'configured'   => ! AccessSettings::missing( $this->settings ),
			'missing'      => AccessSettings::missing( $this->settings ),
			'adapter'      => $this->settings['adapter'],
			'mode'         => $this->settings['mode'],
			'endpoint'     => AccessSettings::endpoint_url( $this->settings ),
			'index_rows'   => $state['rows'],
			'last_success' => $state['last_success'],
			'last_attempt' => $state['last_attempt'],
			'last_result'  => $state['last_result'],
			'freshness'    => null === $age ? 'empty' : ( $age > (int) $this->settings['max_age_hours'] * HOUR_IN_SECONDS ? 'expired' : ( $age > (int) $this->settings['stale_after_hours'] * HOUR_IN_SECONDS ? 'stale' : 'fresh' ) ),
			'circuit_open' => $breaker['open_until'] > time(),
			'breaker'      => $breaker,
			'next_sync'    => (int) wp_next_scheduled( 'wpfinet_access_sync' ),
		);
	}
}
