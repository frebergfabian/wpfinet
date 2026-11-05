<?php
/**
 * Guide editor meta box and assets.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Admin;

use FinetTroubleshoot\PostTypes\GuidePostType;
use FinetTroubleshoot\Integrations\WPForms;
use FinetTroubleshoot\Validation\FlowValidator;

final class GuideEditor {
	public function __construct( private FlowValidator $validator, private WPForms $wpforms ) {}
	public function register_hooks(): void {
		add_action( 'add_meta_boxes_' . GuidePostType::POST_TYPE, array( $this, 'add_meta_box' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}
	public function add_meta_box(): void { add_meta_box( 'finet-ts-builder', __( 'Guidebyggare', 'finet-troubleshoot' ), array( $this, 'render' ), GuidePostType::POST_TYPE, 'normal', 'high' ); }
	public function render( \WP_Post $post ): void {
		$nodes = get_post_meta( $post->ID, GuidePostType::META_NODES, true ); $start = get_post_meta( $post->ID, GuidePostType::META_START_NODE, true ); $settings = get_post_meta( $post->ID, GuidePostType::META_SETTINGS, true );
		$data = array( 'id' => $post->ID, 'title' => $post->post_title, 'description' => $post->post_excerpt, 'start_node' => $start ?: '', 'nodes' => is_array( $nodes ) ? $nodes : array(), 'settings' => is_array( $settings ) ? $settings : array() );
		// 3.0 (B28): 2.0.1 reused the meta box ID 'finet-ts-builder' for this element, so the builder read the empty outer box.
		echo '<div id="finet-ts-builder-app" data-finet-builder data-guide="' . esc_attr( wp_json_encode( $data ) ) . '"><p>' . esc_html__( 'Laddar guidebyggaren …', 'finet-troubleshoot' ) . '</p></div>';
	}
	public function assets( string $hook ): void {
		$screen = get_current_screen();
		$is_guide = $screen && GuidePostType::POST_TYPE === $screen->post_type;
		if ( ! $is_guide ) { return; } // The import page assets are enqueued by AdminMenu (2.0.1 checked a hook name that never matched, B16).
		wp_enqueue_media();
		wp_enqueue_style( 'finet-troubleshoot-admin', FINET_TS_URL . 'assets/build/admin.css', array(), FINET_TS_VERSION );
		wp_enqueue_style( 'finet-troubleshoot', FINET_TS_URL . 'assets/build/frontend.css', array(), FINET_TS_VERSION );
		wp_enqueue_script( 'finet-troubleshoot', FINET_TS_URL . 'assets/build/frontend.js', array(), FINET_TS_VERSION, true );
		wp_enqueue_script( 'finet-troubleshoot-admin', FINET_TS_URL . 'assets/build/admin.js', array( 'wp-api-fetch', 'wp-i18n' ), FINET_TS_VERSION, true );
		wp_add_inline_script( 'finet-troubleshoot-admin', 'window.FinetTroubleshootAdmin=' . wp_json_encode( array( 'restRoot' => esc_url_raw( rest_url( 'finet-troubleshoot/v1/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'wpforms' => $this->wpforms->forms(), 'strings' => array( 'saved' => __( 'Guiden är sparad.', 'finet-troubleshoot' ), 'copied' => __( 'Shortcode kopierad.', 'finet-troubleshoot' ) ) ) ) . ';', 'before' );
	}
}
