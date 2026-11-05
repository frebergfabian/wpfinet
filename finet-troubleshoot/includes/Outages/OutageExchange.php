<?php
/**
 * Portable import/export of outage records in schema 2.0.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Outages;

final class OutageExchange {
	/** @return array<int,array<string,mixed>> */
	public function export_all(): array {
		$posts = get_posts( array( 'post_type' => OutageModule::POST_TYPE, 'post_status' => array( 'publish', 'draft', 'private', 'future' ), 'posts_per_page' => -1, 'orderby' => 'date', 'order' => 'DESC' ) );
		return array_map( fn ( \WP_Post $post ): array => $this->export_one( $post ), $posts );
	}
	/** @return array<string,mixed> */
	public function export_one( \WP_Post $post ): array {
		return array( 'external_id' => (string) get_post_meta( $post->ID, '_wpfinet_external_id', true ) ?: 'outage-' . $post->ID, 'title' => $post->post_title, 'slug' => $post->post_name, 'status' => $post->post_status, 'published_at' => get_date_from_gmt( $post->post_date_gmt, 'c' ), 'summary' => $post->post_excerpt, 'content' => $post->post_content, 'incident_type' => get_post_meta( $post->ID, OutageModule::META_TYPE, true ), 'outage_status' => get_post_meta( $post->ID, OutageModule::META_STATUS, true ), 'priority' => get_post_meta( $post->ID, OutageModule::META_PRIORITY, true ), 'incident_start' => get_post_meta( $post->ID, OutageModule::META_START, true ), 'estimated_end' => get_post_meta( $post->ID, OutageModule::META_ESTIMATED_END, true ), 'actual_end' => get_post_meta( $post->ID, OutageModule::META_ACTUAL_END, true ), 'show_banner' => '1' === get_post_meta( $post->ID, OutageModule::META_BANNER, true ), 'providers' => wp_get_post_terms( $post->ID, OutageModule::TAX_PROVIDER, array( 'fields' => 'names' ) ), 'services' => wp_get_post_terms( $post->ID, OutageModule::TAX_SERVICE, array( 'fields' => 'names' ) ), 'areas' => wp_get_post_terms( $post->ID, OutageModule::TAX_AREA, array( 'fields' => 'names' ) ), 'area_note' => get_post_meta( $post->ID, OutageModule::META_AREA_NOTE, true ), 'timeline' => get_post_meta( $post->ID, OutageModule::META_TIMELINE, true ) ?: array() );
	}
	/** @return array{imported:int,failed:int,items:array<int,array<string,mixed>>} */
	public function import( array $outages ): array {
		$result = array( 'imported' => 0, 'failed' => 0, 'items' => array() );
		foreach ( array_slice( $outages, 0, 100 ) as $raw ) {
			if ( ! is_array( $raw ) || empty( $raw['title'] ) || empty( $raw['external_id'] ) ) { ++$result['failed']; $result['items'][] = array( 'title' => __( 'Okänd driftstörning', 'finet-troubleshoot' ), 'status' => 'failed', 'message' => __( 'Saknar title eller external_id.', 'finet-troubleshoot' ) ); continue; }
			$external = sanitize_title( (string) $raw['external_id'] ); $existing = get_posts( array( 'post_type' => OutageModule::POST_TYPE, 'post_status' => 'any', 'meta_key' => '_wpfinet_external_id', 'meta_value' => $external, 'fields' => 'ids', 'posts_per_page' => 1 ) );
			$post_data = array( 'post_type' => OutageModule::POST_TYPE, 'post_title' => sanitize_text_field( (string) $raw['title'] ), 'post_name' => sanitize_title( (string) ( $raw['slug'] ?? $raw['title'] ) ), 'post_excerpt' => sanitize_textarea_field( (string) ( $raw['summary'] ?? '' ) ), 'post_content' => wp_kses_post( (string) ( $raw['content'] ?? '' ) ), 'post_status' => in_array( $raw['status'] ?? 'draft', array( 'publish', 'draft', 'private', 'future' ), true ) ? $raw['status'] : 'draft' );
			if ( ! empty( $raw['published_at'] ) && strtotime( (string) $raw['published_at'] ) ) { $post_data['post_date_gmt'] = gmdate( 'Y-m-d H:i:s', strtotime( (string) $raw['published_at'] ) ); $post_data['post_date'] = get_date_from_gmt( $post_data['post_date_gmt'] ); }
			if ( $existing ) { $post_data['ID'] = (int) $existing[0]; wp_save_post_revision( $post_data['ID'] ); $id = wp_update_post( wp_slash( $post_data ), true ); } else { $id = wp_insert_post( wp_slash( $post_data ), true ); }
			if ( is_wp_error( $id ) ) { ++$result['failed']; $result['items'][] = array( 'title' => $post_data['post_title'], 'status' => 'failed', 'message' => $id->get_error_message() ); continue; }
			update_post_meta( $id, '_wpfinet_external_id', $external );
			$types = array( 'incident_type' => OutageModule::META_TYPE, 'outage_status' => OutageModule::META_STATUS, 'priority' => OutageModule::META_PRIORITY, 'incident_start' => OutageModule::META_START, 'estimated_end' => OutageModule::META_ESTIMATED_END, 'actual_end' => OutageModule::META_ACTUAL_END, 'area_note' => OutageModule::META_AREA_NOTE ); foreach ( $types as $field => $meta ) { update_post_meta( $id, $meta, sanitize_text_field( (string) ( $raw[ $field ] ?? '' ) ) ); }
			update_post_meta( $id, OutageModule::META_BANNER, ! empty( $raw['show_banner'] ) ? '1' : '0' ); update_post_meta( $id, OutageModule::META_TIMELINE, is_array( $raw['timeline'] ?? null ) ? $raw['timeline'] : array() );
			foreach ( array( 'providers' => OutageModule::TAX_PROVIDER, 'services' => OutageModule::TAX_SERVICE, 'areas' => OutageModule::TAX_AREA ) as $field => $taxonomy ) { $terms = array_map( 'sanitize_text_field', (array) ( $raw[ $field ] ?? array() ) ); if ( $terms ) { wp_set_object_terms( $id, $terms, $taxonomy, false ); } }
			++$result['imported']; $result['items'][] = array( 'title' => $post_data['post_title'], 'status' => 'imported', 'post_id' => $id, 'message' => __( 'Driftstörning importerad.', 'finet-troubleshoot' ) );
		}
		( new OutageRepository() )->flush(); return $result;
	}
}
