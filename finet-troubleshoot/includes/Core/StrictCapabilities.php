<?php
/**
 * Optional strict capability mode (feature flag "strict_capabilities", default off).
 *
 * In 2.0 both post types used the generic "post" capabilities, so any Author could publish an outage (shown in the
 * site-wide banner) and any Contributor could list all guides via REST. Strict mode maps the post types to the
 * existing WPFINET capabilities (manage_/publish_wpfinet_guides, manage_/publish_wpfinet_outages). It is off by
 * default because turning it on without granting roles first would lock editors out.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Core;

final class StrictCapabilities {
	private const TYPES = array( 'finet_ts_guide' => array( 'wpfinet_guide', 'wpfinet_guides', 'manage_wpfinet_guides' ), 'wpfinet_outage' => array( 'wpfinet_outage', 'wpfinet_outages', 'manage_wpfinet_outages' ) );

	public static function active(): bool { return Modules::enabled( 'strict_capabilities' ); }

	public function register_hooks(): void {
		if ( ! self::active() ) { return; }
		add_filter( 'register_post_type_args', array( $this, 'post_type_args' ), 10, 2 );
		add_filter( 'user_has_cap', array( $this, 'grant_primitives' ), 10, 1 );
	}

	/** @param array<string,mixed> $args */
	public function post_type_args( array $args, string $post_type ): array {
		if ( isset( self::TYPES[ $post_type ] ) ) {
			$args['capability_type'] = array( self::TYPES[ $post_type ][0], self::TYPES[ $post_type ][1] );
			$args['map_meta_cap']    = true;
			unset( $args['capabilities'] );
		}
		return $args;
	}

	/** @param array<string,bool> $allcaps */
	public function grant_primitives( array $allcaps ): array {
		foreach ( self::TYPES as [ , $plural, $manage ] ) {
			if ( empty( $allcaps[ $manage ] ) ) { continue; }
			foreach ( array( 'edit_%s', 'edit_others_%s', 'edit_published_%s', 'edit_private_%s', 'delete_%s', 'delete_others_%s', 'delete_published_%s', 'delete_private_%s', 'read_private_%s' ) as $pattern ) {
				$allcaps[ sprintf( $pattern, $plural ) ] = true;
			}
		}
		return $allcaps;
	}

	/** Grants the WPFINET content capabilities to the Editor role (explicit admin action). */
	public static function grant_editors(): void {
		$role = get_role( 'editor' );
		if ( ! $role ) { return; }
		foreach ( array( 'manage_wpfinet', 'manage_wpfinet_guides', 'publish_wpfinet_guides', 'manage_wpfinet_outages', 'publish_wpfinet_outages' ) as $cap ) { $role->add_cap( $cap ); }
	}

	public static function can_edit_guides(): bool {
		return self::active() ? current_user_can( 'manage_wpfinet_guides' ) : ( current_user_can( 'manage_wpfinet_guides' ) || current_user_can( 'edit_posts' ) );
	}
}
