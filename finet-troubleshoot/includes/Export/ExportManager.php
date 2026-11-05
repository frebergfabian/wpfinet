<?php
/**
 * Portable JSON export.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Export;

use FinetTroubleshoot\PostTypes\GuidePostType;
use FinetTroubleshoot\Outages\OutageExchange;

final class ExportManager {
	/** @return array<string,mixed>|\WP_Error */
	public function export_guide( int $post_id ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post || GuidePostType::POST_TYPE !== $post->post_type ) {
			return new \WP_Error( 'finet_ts_guide_not_found', __( 'Guiden kunde inte hittas.', 'finet-troubleshoot' ) );
		}
		return $this->guide_from_post( $post );
	}
	/** @return array<string,mixed> */
	public function export_all(): array {
		$posts = get_posts( array( 'post_type' => GuidePostType::POST_TYPE, 'post_status' => array( 'publish', 'draft', 'private', 'pending', 'future' ), 'posts_per_page' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		return $this->document( array_map( fn ( \WP_Post $post ): array => $this->guide_from_post( $post ), $posts ) );
	}
	/** @return array<string,mixed> */
	public function document( array $guides, bool $include_outages = true ): array {
		return array(
			'schema'         => 'finet-troubleshoot',
			'schema_version' => '2.0',
			'generated_at'   => current_time( 'c' ),
			'generator'      => 'Finet Troubleshoot ' . FINET_TS_VERSION,
			'guides'         => $guides,
			'outages'        => $include_outages ? ( new OutageExchange() )->export_all() : array(),
		);
	}
	/** @return array<string,mixed> */
	public function guide_from_post( \WP_Post $post ): array {
		return array(
			'external_id' => (string) get_post_meta( $post->ID, GuidePostType::META_EXTERNAL_ID, true ),
			'title'       => $post->post_title,
			'slug'        => $post->post_name,
			'description' => $post->post_excerpt,
			'status'      => $post->post_status,
			'start_node'  => (string) get_post_meta( $post->ID, GuidePostType::META_START_NODE, true ),
			'settings'    => get_post_meta( $post->ID, GuidePostType::META_SETTINGS, true ) ?: array(),
			'variables'   => self::variables( $post->ID ),
			'nodes'       => get_post_meta( $post->ID, GuidePostType::META_NODES, true ) ?: array(),
		);
	}
	/** Guide variables are stored since 3.0 (2.0.1 accepted them on import but dropped them). @return array<string,mixed>|array{} */
	public static function variables( int $post_id ): array {
		$variables = get_post_meta( $post_id, GuidePostType::META_VARIABLES, true );
		return is_array( $variables ) ? $variables : array();
	}
	public function send_download( array $document, string $filename ): never {
		nocache_headers();
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		echo wp_json_encode( $document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}
}
