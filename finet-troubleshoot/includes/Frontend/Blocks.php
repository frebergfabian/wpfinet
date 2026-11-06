<?php
/**
 * Dynamic blocks that wrap the module shortcodes (same output in Gutenberg, Elementor shortcode widget and classic).
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Frontend;

final class Blocks {
	public function register_hooks(): void { add_action( 'init', array( $this, 'register' ) ); }

	public function register(): void {
		if ( ! function_exists( 'register_block_type' ) ) { return; }
		wp_register_script( 'wpfinet-blocks', FINET_TS_URL . 'assets/build/blocks.js', array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render', 'wp-i18n' ), \FinetTroubleshoot\Core\Assets::ver( 'blocks.js' ), true );
		register_block_type( 'wpfinet/access-check', array(
			'api_version'     => 3,
			'editor_script'   => 'wpfinet-blocks',
			'attributes'      => array( 'title' => array( 'type' => 'string', 'default' => '' ) ),
			'render_callback' => static fn ( array $a ): string => do_shortcode( '[wpfinet_access_check title="' . esc_attr( (string) ( $a['title'] ?? '' ) ) . '"]' ),
		) );
		register_block_type( 'wpfinet/instagram-feed', array(
			'api_version'     => 3,
			'editor_script'   => 'wpfinet-blocks',
			'attributes'      => array( 'title' => array( 'type' => 'string', 'default' => '' ), 'limit' => array( 'type' => 'number', 'default' => 6 ), 'columns' => array( 'type' => 'number', 'default' => 3 ), 'layout' => array( 'type' => 'string', 'default' => 'grid' ) ),
			'render_callback' => static fn ( array $a ): string => do_shortcode( sprintf( '[wpfinet_instagram_feed title="%s" limit="%d" columns="%d" layout="%s"]', esc_attr( (string) ( $a['title'] ?? '' ) ), absint( $a['limit'] ?? 6 ), absint( $a['columns'] ?? 3 ), 'list' === ( $a['layout'] ?? '' ) ? 'list' : 'grid' ) ),
		) );
	}
}
