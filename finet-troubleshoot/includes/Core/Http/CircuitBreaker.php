<?php
/**
 * Per-service circuit breaker. After N consecutive failures the circuit opens for a cool-down period and calls are
 * refused immediately (no network wait), so a dead upstream can never slow down WordPress responses.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Core\Http;

final class CircuitBreaker {
	public function __construct( private string $service, private int $threshold = 3, private int $cooldown = 300 ) {}

	private function key(): string { return 'wpfinet_cb_' . sanitize_key( $this->service ); }

	/** @return array{failures:int,open_until:int,last_error:string,last_success:int,last_failure:int} */
	public function state(): array {
		$state = get_option( $this->key(), array() );
		$state = is_array( $state ) ? $state : array();
		return array(
			'failures'     => (int) ( $state['failures'] ?? 0 ),
			'open_until'   => (int) ( $state['open_until'] ?? 0 ),
			'last_error'   => (string) ( $state['last_error'] ?? '' ),
			'last_success' => (int) ( $state['last_success'] ?? 0 ),
			'last_failure' => (int) ( $state['last_failure'] ?? 0 ),
		);
	}

	public function is_open(): bool { return $this->state()['open_until'] > time(); }

	public function success(): void {
		$state = $this->state();
		if ( 0 === $state['failures'] && 0 === $state['open_until'] && $state['last_success'] > time() - 60 ) {
			return; // Avoid an option write on every successful call.
		}
		$state['failures'] = 0; $state['open_until'] = 0; $state['last_success'] = time();
		update_option( $this->key(), $state, false );
	}

	public function failure( string $error ): void {
		$state = $this->state();
		++$state['failures'];
		$state['last_error']   = mb_substr( $error, 0, 200 );
		$state['last_failure'] = time();
		if ( $state['failures'] >= $this->threshold ) {
			$state['open_until'] = time() + $this->cooldown;
		}
		update_option( $this->key(), $state, false );
	}

	public function reset(): void { delete_option( $this->key() ); }
}
