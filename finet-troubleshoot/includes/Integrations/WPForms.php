<?php
/**
 * Stable WPForms integration through its public shortcode interface.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Integrations;

final class WPForms {
	public function available(): bool { return post_type_exists( 'wpforms' ) && shortcode_exists( 'wpforms' ); }
	/** @return array<int,array{id:int,title:string}> */
	public function forms(): array {
		if ( ! $this->available() ) { return array(); }
		$posts = get_posts( array( 'post_type' => 'wpforms', 'post_status' => 'publish', 'posts_per_page' => 200, 'orderby' => 'title', 'order' => 'ASC' ) );
		return array_map( static fn ( \WP_Post $post ): array => array( 'id' => $post->ID, 'title' => $post->post_title ), $posts );
	}
	public function render( array $reference ): string {
		$id = absint( $reference['form_id'] ?? 0 );
		if ( ! $id || ! $this->available() || 'wpforms' !== get_post_type( $id ) ) { return ''; }
		return do_shortcode( '[wpforms id="' . $id . '" title="false" description="false"]' );
	}
	public function find_by_title( string $title ): int {
		$posts = get_posts( array( 'post_type' => 'wpforms', 'post_status' => 'publish', 'title' => sanitize_text_field( $title ), 'fields' => 'ids', 'posts_per_page' => 1 ) );
		return $posts ? (int) $posts[0] : 0;
	}
}
