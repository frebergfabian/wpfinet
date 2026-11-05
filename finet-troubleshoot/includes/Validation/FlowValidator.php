<?php
/**
 * Graph validation and payload sanitisation.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Validation;

final class FlowValidator {
	public const NODE_TYPES = array( 'information', 'action', 'question', 'check', 'result', 'support', 'outage', 'wpforms' );
	public const RESULT_TYPES = array( 'success', 'info', 'warning', 'failure' );

	/** @return array<string, mixed> */
	public function validate( array $guide ): array {
		$errors   = array();
		$warnings = array();
		$nodes    = isset( $guide['nodes'] ) && is_array( $guide['nodes'] ) ? $guide['nodes'] : array();
		$by_id    = array();
		$edges    = array();
		$terminals = 0;

		if ( empty( $guide['start_node'] ) || ! is_string( $guide['start_node'] ) ) {
			$errors[] = __( 'Startnod saknas.', 'finet-troubleshoot' );
		}

		foreach ( $nodes as $index => $node ) {
			$label = is_array( $node ) && isset( $node['id'] ) ? (string) $node['id'] : '#' . ( $index + 1 );
			if ( ! is_array( $node ) ) {
				$errors[] = sprintf( __( 'Nod %s är inte ett objekt.', 'finet-troubleshoot' ), $label );
				continue;
			}
			if ( empty( $node['id'] ) || ! is_string( $node['id'] ) || ! preg_match( '/^[a-z0-9][a-z0-9-]{0,99}$/', $node['id'] ) ) {
				$errors[] = sprintf( __( 'Nod %s har ett ogiltigt ID. Använd små bokstäver, siffror och bindestreck.', 'finet-troubleshoot' ), $label );
				continue;
			}
			if ( isset( $by_id[ $node['id'] ] ) ) {
				$errors[] = sprintf( __( 'Nod-ID %s förekommer mer än en gång.', 'finet-troubleshoot' ), $node['id'] );
				continue;
			}
			$by_id[ $node['id'] ] = $node;
			$edges[ $node['id'] ] = array();
			if ( empty( $node['type'] ) || ! in_array( $node['type'], self::NODE_TYPES, true ) ) {
				$errors[] = sprintf( __( 'Noden %1$s har en okänd nodtyp: %2$s.', 'finet-troubleshoot' ), $node['id'], (string) ( $node['type'] ?? '' ) );
			}
			if ( empty( $node['title'] ) || ! is_string( $node['title'] ) ) {
				$errors[] = sprintf( __( 'Noden %s saknar intern titel.', 'finet-troubleshoot' ), $node['id'] );
			}
			if ( in_array( $node['type'] ?? '', array( 'question', 'check' ), true ) && ( empty( $node['options'] ) || ! is_array( $node['options'] ) ) ) {
				$errors[] = sprintf( __( 'Frågenoden %s saknar svarsalternativ.', 'finet-troubleshoot' ), $node['id'] );
			}
			if ( in_array( $node['type'] ?? '', array( 'result', 'support' ), true ) ) {
				++$terminals;
			}
			if ( 'result' === ( $node['type'] ?? '' ) && ! in_array( $node['result_type'] ?? 'info', self::RESULT_TYPES, true ) ) {
				$errors[] = sprintf( __( 'Resultatnoden %s har en ogiltig resultattyp.', 'finet-troubleshoot' ), $node['id'] );
			}
		}

		foreach ( $by_id as $id => $node ) {
			$option_ids = array();
			foreach ( (array) ( $node['options'] ?? array() ) as $option_index => $option ) {
				if ( ! is_array( $option ) || empty( $option['id'] ) || empty( $option['label'] ) || empty( $option['next'] ) ) {
					$errors[] = sprintf( __( 'Noden %1$s har ett ofullständigt svarsalternativ (%2$d).', 'finet-troubleshoot' ), $id, $option_index + 1 );
					continue;
				}
				if ( isset( $option_ids[ $option['id'] ] ) ) {
					$errors[] = sprintf( __( 'Noden %1$s har ett dubbelt svarsalternativ-ID: %2$s.', 'finet-troubleshoot' ), $id, $option['id'] );
				}
				$option_ids[ $option['id'] ] = true;
				$edges[ $id ][] = (string) $option['next'];
			}
			if ( ! empty( $node['next'] ) ) {
				$edges[ $id ][] = (string) $node['next'];
			}
			if ( 'outage' === ( $node['type'] ?? '' ) ) {
				if ( ! empty( $node['outage']['match_node'] ) ) { $edges[ $id ][] = (string) $node['outage']['match_node']; }
				if ( ! empty( $node['outage']['no_match_node'] ) ) { $edges[ $id ][] = (string) $node['outage']['no_match_node']; }
			}
		}

		foreach ( $edges as $from => $targets ) {
			foreach ( $targets as $target ) {
				if ( ! isset( $by_id[ $target ] ) ) {
					$errors[] = sprintf( __( 'Noden %1$s länkar till %2$s, men den noden finns inte.', 'finet-troubleshoot' ), $from, $target );
				}
			}
		}

		$start = (string) ( $guide['start_node'] ?? '' );
		if ( $start && ! isset( $by_id[ $start ] ) ) {
			$errors[] = sprintf( __( 'Startnoden %s finns inte.', 'finet-troubleshoot' ), $start );
		}
		if ( 0 === $terminals ) {
			$errors[] = __( 'Guiden måste ha minst en slutnod (resultat eller support).', 'finet-troubleshoot' );
		}

		if ( isset( $by_id[ $start ] ) ) {
			$visited = $this->walk( $start, $edges );
			foreach ( array_keys( $by_id ) as $id ) {
				if ( ! isset( $visited[ $id ] ) ) {
					$warnings[] = sprintf( __( 'Noden %s går inte att nå från startnoden.', 'finet-troubleshoot' ), $id );
				}
			}
			if ( $this->has_cycle( $start, $edges ) ) {
				$warnings[] = __( 'Guiden innehåller ett cirkulärt flöde. Det är tillåtet, men kontrollera att det är avsiktligt.', 'finet-troubleshoot' );
			}
		}

		return array(
			'valid'    => empty( $errors ),
			'errors'   => array_values( array_unique( $errors ) ),
			'warnings' => array_values( array_unique( $warnings ) ),
			'summary'  => array( 'nodes' => count( $by_id ), 'terminals' => $terminals ),
		);
	}

	/** @return array<string, bool> */
	private function walk( string $start, array $edges ): array {
		$seen = array();
		$todo = array( $start );
		while ( $todo ) {
			$id = array_pop( $todo );
			if ( isset( $seen[ $id ] ) || ! isset( $edges[ $id ] ) ) {
				continue;
			}
			$seen[ $id ] = true;
			foreach ( $edges[ $id ] as $target ) {
				$todo[] = $target;
			}
		}
		return $seen;
	}

	private function has_cycle( string $start, array $edges ): bool {
		$visited = array();
		$stack = array();
		$visit = static function ( string $id ) use ( &$visit, &$visited, &$stack, $edges ): bool {
			if ( isset( $stack[ $id ] ) ) {
				return true;
			}
			if ( isset( $visited[ $id ] ) || ! isset( $edges[ $id ] ) ) {
				return false;
			}
			$visited[ $id ] = true;
			$stack[ $id ]   = true;
			foreach ( $edges[ $id ] as $next ) {
				if ( $visit( $next ) ) {
					return true;
				}
			}
			unset( $stack[ $id ] );
			return false;
		};
		return $visit( $start );
	}
}
