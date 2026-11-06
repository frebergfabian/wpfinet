<?php
/**
 * 3.1.1 (CLS): prints the stylesheets of WPFINET components in <head> when the current page uses them.
 *
 * Shortcodes enqueue their CSS while the body is rendered, so WordPress prints it in the footer: the components are
 * painted unstyled first and then jump. This class looks at the queried page (post content and Elementor data)
 * during wp_enqueue_scripts and enqueues the matching styles early. Components that are found nowhere else (e.g. in a
 * widget) keep the old behaviour. Extend with the `wpfinet_head_styles` filter.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Frontend;

use FinetTroubleshoot\Core\Modules;

final class HeadStyles {
	/** Shortcode or block name => [style handle, module flag or ''] */
	public const MAP = array(
		'[finet_troubleshoot'      => array( 'finet-troubleshoot', '' ),
		'wp:finet-troubleshoot/'   => array( 'finet-troubleshoot', '' ),
		'[wpfinet_outages'         => array( 'wpfinet-outages', '' ),
		'[wpfinet_outage_status'   => array( 'wpfinet-outages', '' ),
		'[wpfinet_instagram_feed'  => array( 'wpfinet-instagram', 'instagram' ),
		'wp:wpfinet/instagram-feed' => array( 'wpfinet-instagram', 'instagram' ),
		'[wpfinet_access_check'    => array( 'wpfinet-access', 'access' ),
		'wp:wpfinet/access-check'  => array( 'wpfinet-access', 'access' ),
	);

	public function register_hooks(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ), 30 );
	}

	public function enqueue(): void {
		foreach ( self::handles_for( self::page_source() ) as $handle ) {
			if ( wp_style_is( $handle, 'registered' ) ) { wp_enqueue_style( $handle ); }
		}
	}

	/** @return array<int,string> */
	public static function handles_for( string $source ): array {
		$handles = array();
		if ( '' !== $source ) {
			foreach ( self::MAP as $needle => [ $handle, $module ] ) {
				if ( false !== stripos( $source, $needle ) && ( '' === $module || Modules::enabled( $module ) ) ) { $handles[] = $handle; }
			}
		}
		if ( is_singular( 'wpfinet_outage' ) || is_post_type_archive( 'wpfinet_outage' ) ) { $handles[] = 'wpfinet-outages'; }
		return array_values( array_unique( (array) apply_filters( 'wpfinet_head_styles', $handles, $source ) ) );
	}

	/** Content of the queried page that may contain WPFINET shortcodes (post content + Elementor JSON). */
	public static function page_source(): string {
		if ( is_admin() || ! is_singular() ) { return ''; }
		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) { return ''; }
		$elementor = get_post_meta( $post->ID, '_elementor_data', true );
		return $post->post_content . "\n" . ( is_string( $elementor ) ? stripslashes( $elementor ) : '' );
	}
}
