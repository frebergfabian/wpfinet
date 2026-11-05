<?php
/**
 * Schema 1.0 importer.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Import\Versions;

use FinetTroubleshoot\PostTypes\GuidePostType;

final class ImporterV1 {
	/** @return int|\WP_Error */
	public function save_guide( array $guide, string $strategy, ?int $existing_id = null, string $schema_version = '1.0' ): int|\WP_Error {
		$post_data = array(
			'post_type'    => GuidePostType::POST_TYPE,
			'post_title'   => $guide['title'],
			'post_name'    => $guide['slug'],
			'post_excerpt' => $guide['description'],
			'post_status'  => $guide['status'],
		);
		if ( 'update' === $strategy && $existing_id ) {
			$post_data['ID'] = $existing_id;
			wp_save_post_revision( $existing_id );
			$post_id = wp_update_post( wp_slash( $post_data ), true );
		} else {
			$post_data['post_status'] = 'copy' === $strategy ? 'draft' : $post_data['post_status'];
			$post_id = wp_insert_post( wp_slash( $post_data ), true );
		}
		if ( is_wp_error( $post_id ) ) { return $post_id; }

		$metadata = array(
			GuidePostType::META_EXTERNAL_ID => $guide['external_id'],
			GuidePostType::META_START_NODE  => $guide['start_node'],
			GuidePostType::META_NODES       => $guide['nodes'],
			GuidePostType::META_SETTINGS    => $guide['settings'],
			GuidePostType::META_SCHEMA      => in_array( $schema_version, array( '1.0', '2.0', '3.0' ), true ) ? $schema_version : '1.0',
		);
		if ( ! empty( $guide['variables'] ) && is_array( $guide['variables'] ) ) { $metadata[ GuidePostType::META_VARIABLES ] = $guide['variables']; }
		foreach ( $metadata as $key => $value ) {
			update_post_meta( $post_id, $key, $value );
			if ( get_post_meta( $post_id, $key, true ) !== $value ) {
				if ( 'update' !== $strategy ) { wp_delete_post( $post_id, true ); }
				return new \WP_Error( 'finet_ts_save_failed', __( 'Guidens data kunde inte sparas.', 'finet-troubleshoot' ) );
			}
		}
		do_action( 'finet_troubleshoot_import_guide', $post_id, $guide, $strategy );
		return $post_id;
	}
}
