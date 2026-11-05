<?php
/**
 * Local, server-side address index (table {prefix}wpfinet_access_index). Filled by the background sync, read by
 * single-address lookups. A new sync writes a new generation and switches atomically; the full list is never
 * exposed through any endpoint or HTML.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Access;

final class AddressIndex {
	public const STATE_OPTION = 'wpfinet_access_state';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'wpfinet_access_index';
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			generation int(10) unsigned NOT NULL DEFAULT 0,
			street_key varchar(150) NOT NULL DEFAULT '',
			street_fold varchar(150) NOT NULL DEFAULT '',
			number int(10) unsigned NOT NULL DEFAULT 0,
			letter varchar(4) NOT NULL DEFAULT '',
			state varchar(40) NOT NULL DEFAULT '',
			connected tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY lookup (generation,street_key,number,letter),
			KEY fold (generation,street_fold,number)
		) {$charset};" );
	}

	/** @return array{generation:int,rows:int,last_success:int,last_attempt:int,last_error:string,last_result:string} */
	public static function state(): array {
		$s = get_option( self::STATE_OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		return array( 'generation' => (int) ( $s['generation'] ?? 0 ), 'rows' => (int) ( $s['rows'] ?? 0 ), 'last_success' => (int) ( $s['last_success'] ?? 0 ), 'last_attempt' => (int) ( $s['last_attempt'] ?? 0 ), 'last_error' => (string) ( $s['last_error'] ?? '' ), 'last_result' => (string) ( $s['last_result'] ?? '' ) );
	}

	/** @param array<string,mixed> $changes */
	public static function update_state( array $changes ): void {
		update_option( self::STATE_OPTION, array_merge( self::state(), $changes ), false );
	}

	/**
	 * Replaces the index atomically. Rows must already be normalised.
	 *
	 * @param array<int,array{street_key:string,number:int,letter:string,state:string,connected:bool}> $rows
	 */
	public static function replace( array $rows ): int {
		global $wpdb;
		self::install();
		$table = self::table();
		$old   = self::state()['generation'];
		$new   = $old + 1;
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE generation = %d", $new ) ); // phpcs:ignore WordPress.DB
		$seen = array();
		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$values = array();
			$args   = array();
			foreach ( $chunk as $row ) {
				$key = $row['street_key'] . '|' . $row['number'] . '|' . $row['letter'];
				if ( isset( $seen[ $key ] ) && ! $row['connected'] ) {
					continue; // Duplicate address: a connected entry wins.
				}
				$seen[ $key ] = true;
				$values[] = '(%d,%s,%s,%d,%s,%s,%d)';
				array_push( $args, $new, mb_substr( $row['street_key'], 0, 150 ), mb_substr( Normalizer::fold_key( $row['street_key'] ), 0, 150 ), $row['number'], mb_substr( $row['letter'], 0, 4 ), mb_substr( $row['state'], 0, 40 ), $row['connected'] ? 1 : 0 );
			}
			if ( $values ) {
				$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (generation, street_key, street_fold, number, letter, state, connected) VALUES " . implode( ',', $values ), $args ) ); // phpcs:ignore WordPress.DB
			}
		}
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE generation = %d", $new ) ); // phpcs:ignore WordPress.DB
		self::update_state( array( 'generation' => $new, 'rows' => $count, 'last_success' => time() ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE generation <> %d", $new ) ); // phpcs:ignore WordPress.DB
		return $count;
	}

	/** Fills street_fold for rows written by 3.0 (column added in 3.1). Returns the number of distinct streets. */
	public static function backfill_fold(): int {
		global $wpdb;
		$table = self::table();
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) { // phpcs:ignore WordPress.DB
			return 0;
		}
		$streets = (array) $wpdb->get_col( "SELECT DISTINCT street_key FROM {$table} WHERE street_fold = ''" ); // phpcs:ignore WordPress.DB
		foreach ( $streets as $street ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET street_fold = %s WHERE street_key = %s AND street_fold = ''", mb_substr( Normalizer::fold_key( (string) $street ), 0, 150 ), $street ) ); // phpcs:ignore WordPress.DB
		}
		return count( $streets );
	}

	/**
	 * Accent-insensitive fallback. Returns rows only when the folded key maps to exactly ONE street, so that two
	 * different streets (e.g. Ågatan and Agatan) are never merged.
	 *
	 * @return array{rows:array<int,array{state:string,connected:int,letter:string}>,ambiguous:bool}
	 */
	public static function find_folded( string $fold_key, int $number ): array {
		global $wpdb;
		$state = self::state();
		if ( $state['generation'] < 1 || '' === $fold_key ) {
			return array( 'rows' => array(), 'ambiguous' => false );
		}
		$table = self::table();
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT street_key, state, connected, letter FROM {$table} WHERE generation = %d AND street_fold = %s AND number = %d LIMIT 50", $state['generation'], $fold_key, $number ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$streets = array_unique( array_column( $rows, 'street_key' ) );
		if ( count( $streets ) > 1 ) {
			return array( 'rows' => array(), 'ambiguous' => true );
		}
		return array( 'rows' => array_map( static fn ( $r ) => array( 'state' => $r['state'], 'connected' => (int) $r['connected'], 'letter' => $r['letter'] ), $rows ), 'ambiguous' => false );
	}

	/** @return array<int,array{state:string,connected:int,letter:string}> */
	public static function find( string $street_key, int $number ): array {
		global $wpdb;
		$state = self::state();
		if ( $state['generation'] < 1 ) {
			return array();
		}
		$table = self::table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT state, connected, letter FROM {$table} WHERE generation = %d AND street_key = %s AND number = %d LIMIT 50", $state['generation'], $street_key, $number ), ARRAY_A ); // phpcs:ignore WordPress.DB
	}
}
