<?php
/**
 * Import outcome value object.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Import;

final class ImportResult {
	/** @var array<int, array<string, mixed>> */
	private array $items = array();
	/** @var array<int, string> */
	private array $errors = array();
	/** @var array<int, string> */
	private array $warnings = array();

	public function add_item( array $item ): void { $this->items[] = $item; }
	public function add_error( string $message ): void { $this->errors[] = $message; }
	public function add_warning( string $message ): void { $this->warnings[] = $message; }
	/** @return array<string, mixed> */
	public function to_array(): array {
		return array(
			'items'     => $this->items,
			'errors'    => array_values( array_unique( $this->errors ) ),
			'warnings'  => array_values( array_unique( $this->warnings ) ),
			'imported'  => $this->imported_count(),
			'skipped'   => count( array_filter( $this->items, static fn ( array $item ): bool => 'skipped' === ( $item['status'] ?? '' ) ) ),
			'failed'    => count( array_filter( $this->items, static fn ( array $item ): bool => 'failed' === ( $item['status'] ?? '' ) ) ),
		);
	}
	public function imported_count(): int { return count( array_filter( $this->items, static fn ( array $item ): bool => 'imported' === ( $item['status'] ?? '' ) ) ); }
}
