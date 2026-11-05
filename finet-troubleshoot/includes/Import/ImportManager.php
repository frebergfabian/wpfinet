<?php
/**
 * Document import orchestration.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Import;

use FinetTroubleshoot\Import\Versions\ImporterV1;
use FinetTroubleshoot\PostTypes\GuidePostType;
use FinetTroubleshoot\Outages\OutageExchange;
use FinetTroubleshoot\Validation\FlowValidator;

final class ImportManager {
	private SchemaValidator $schema_validator;
	public function __construct( FlowValidator $flow_validator ) { $this->schema_validator = new SchemaValidator( $flow_validator ); }
	/** @return array<string,mixed> */
	public function preview( array $document ): array {
		$result = $this->schema_validator->validate( $document );
		foreach ( $result['guides'] as &$guide ) {
			$existing = $this->find_by_external_id( $guide['external_id'] );
			$guide['existing_post_id'] = $existing ? $existing : null;
			$guide['flow'] = ( new FlowValidator() )->validate( $guide );
		}
		unset( $guide );
		$result['outages'] = is_array( $document['outages'] ?? null ) ? $document['outages'] : array();
		$result['summary']['outages'] = count( $result['outages'] );
		return $result;
	}
	public function import_data( array $document, string $strategy = 'copy', bool $is_activation = false ): ImportResult {
		$preview = $this->preview( $document );
		$result = new ImportResult();
		if ( ! $preview['valid'] ) { foreach ( $preview['errors'] as $error ) { $result->add_error( $error ); } return $result; }
		$strategy = in_array( $strategy, array( 'copy', 'update', 'skip' ), true ) ? $strategy : 'copy';
		$writer = new ImporterV1();
		foreach ( $preview['guides'] as $guide ) {
			$existing = $this->find_by_external_id( $guide['external_id'] );
			if ( $existing && 'skip' === $strategy ) {
				$result->add_item( array( 'title' => $guide['title'], 'status' => 'skipped', 'message' => __( 'Hoppades över eftersom external_id redan finns.', 'finet-troubleshoot' ) ) );
				continue;
			}
			if ( $existing && 'copy' === $strategy ) {
				$guide['external_id'] = $this->unique_external_id( $guide['external_id'] );
			}
			if ( ! ( $existing && 'update' === $strategy ) ) {
				$guide['slug'] = $this->unique_slug( $guide['slug'] );
			}
			$saved = $writer->save_guide( $guide, $existing && 'update' === $strategy ? 'update' : 'copy', $existing ?: null, (string) ( $document['schema_version'] ?? '1.0' ) );
			if ( is_wp_error( $saved ) ) {
				$result->add_item( array( 'title' => $guide['title'], 'status' => 'failed', 'message' => $saved->get_error_message() ) );
				continue;
			}
			$result->add_item( array( 'title' => $guide['title'], 'status' => 'imported', 'post_id' => $saved, 'nodes' => count( $guide['nodes'] ), 'message' => $is_activation ? __( 'Demo-guide skapad.', 'finet-troubleshoot' ) : __( 'Importerad.', 'finet-troubleshoot' ) ) );
		}
		if ( ! empty( $preview['outages'] ) ) {
			$outages = ( new OutageExchange() )->import( $preview['outages'] );
			foreach ( $outages['items'] as $item ) { $result->add_item( $item ); }
		}
		return $result;
	}
	private function find_by_external_id( string $external_id ): ?int {
		$posts = get_posts( array( 'post_type' => GuidePostType::POST_TYPE, 'post_status' => array( 'publish', 'draft', 'private', 'pending', 'future' ), 'meta_key' => GuidePostType::META_EXTERNAL_ID, 'meta_value' => $external_id, 'fields' => 'ids', 'posts_per_page' => 1, 'no_found_rows' => true ) );
		return ! empty( $posts ) ? (int) $posts[0] : null;
	}
	/** 2.0.1 used wp_unique_post_slug(..., 'draft') which never de-duplicates drafts. */
	private function unique_slug( string $slug ): string {
		$base = $slug; $number = 2;
		while ( get_posts( array( 'post_type' => GuidePostType::POST_TYPE, 'name' => $slug, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1, 'no_found_rows' => true ) ) ) { $slug = $base . '-' . $number++; }
		return $slug;
	}
	private function unique_external_id( string $external_id ): string {
		$base = $external_id; $number = 2;
		while ( $this->find_by_external_id( $external_id ) ) { $external_id = $base . '-' . $number++; }
		return $external_id;
	}
}
