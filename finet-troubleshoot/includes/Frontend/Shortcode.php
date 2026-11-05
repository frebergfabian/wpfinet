<?php
/**
 * Shortcode registration.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Frontend;

use FinetTroubleshoot\PostTypes\GuidePostType;

final class Shortcode {
	public function __construct( private Renderer $renderer ) {}
	public function register_hooks(): void { add_shortcode( 'finet_troubleshoot', array( $this, 'render' ) ); }
	public function render( array $attributes = array() ): string {
		$attributes = shortcode_atts( array( 'id' => '', 'slug' => '', 'class' => '', 'show_title' => '', 'show_restart' => '' ), $attributes, 'finet_troubleshoot' );
		$post = null;
		if ( '' !== $attributes['id'] ) {
			$post = get_post( absint( $attributes['id'] ) );
		} elseif ( '' !== $attributes['slug'] ) {
			$posts = get_posts( array( 'post_type' => GuidePostType::POST_TYPE, 'name' => sanitize_title( $attributes['slug'] ), 'post_status' => 'publish', 'posts_per_page' => 1, 'no_found_rows' => true ) );
			$post = $posts[0] ?? null;
		}
		if ( ! $post || GuidePostType::POST_TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			if ( current_user_can( 'edit_posts' ) && '' !== $attributes['id'] ) {
				return '<p class="finet-troubleshoot__error">' . esc_html( sprintf( __( 'Finet Troubleshoot: Guide ID %s finns inte eller är inte publicerad.', 'finet-troubleshoot' ), $attributes['id'] ) ) . '</p>';
			}
			return '<p class="finet-troubleshoot__error">' . esc_html__( 'Felsökningsguiden kunde inte laddas.', 'finet-troubleshoot' ) . '</p>';
		}
		return $this->renderer->render( $post, $attributes );
	}
}
