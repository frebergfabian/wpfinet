<?php
/**
 * Guide post type and metadata registration.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\PostTypes;

final class GuidePostType {
	public const POST_TYPE = 'finet_ts_guide';
	public const META_EXTERNAL_ID = '_finet_ts_external_id';
	public const META_START_NODE  = '_finet_ts_start_node';
	public const META_NODES       = '_finet_ts_nodes';
	public const META_SETTINGS    = '_finet_ts_settings';
	public const META_SCHEMA      = '_finet_ts_schema_version';
	public const META_VARIABLES   = '_finet_ts_variables';

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
	}

	public function register(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels' => array(
					'name'               => __( 'Felsökningsguider', 'finet-troubleshoot' ),
					'singular_name'      => __( 'Felsökningsguide', 'finet-troubleshoot' ),
					'add_new'            => __( 'Lägg till guide', 'finet-troubleshoot' ),
					'add_new_item'       => __( 'Lägg till guide', 'finet-troubleshoot' ),
					'edit_item'          => __( 'Redigera guide', 'finet-troubleshoot' ),
					'new_item'           => __( 'Ny guide', 'finet-troubleshoot' ),
					'view_item'          => __( 'Visa guide', 'finet-troubleshoot' ),
					'search_items'       => __( 'Sök guider', 'finet-troubleshoot' ),
					'not_found'          => __( 'Inga guider hittades.', 'finet-troubleshoot' ),
					'not_found_in_trash' => __( 'Inga guider i papperskorgen.', 'finet-troubleshoot' ),
				),
				'public'             => false,
				'show_ui'            => true,
				'show_in_menu'       => false,
				'show_in_rest'       => true,
				'map_meta_cap'       => true,
				'capability_type'    => 'post',
				'hierarchical'       => false,
				'supports'           => array( 'title', 'excerpt', 'revisions' ),
				'has_archive'        => false,
				'rewrite'            => false,
				'menu_icon'          => 'dashicons-admin-tools',
				'publicly_queryable' => false,
			)
		);

		foreach ( array( self::META_EXTERNAL_ID, self::META_START_NODE, self::META_NODES, self::META_SETTINGS, self::META_SCHEMA, self::META_VARIABLES ) as $key ) {
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'type'              => in_array( $key, array( self::META_NODES, self::META_SETTINGS, self::META_VARIABLES ), true ) ? 'array' : 'string',
					'single'            => true,
					'show_in_rest'      => false,
					'auth_callback'     => static fn (): bool => current_user_can( 'edit_posts' ),
					'revisions_enabled' => true,
				)
			);
		}
	}
}
