<?php
/**
 * Always-registered shortcodes of optional modules. When a module is switched off the tag renders nothing for
 * visitors (instead of leaking the raw "[shortcode]" text) and a short note for editors.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Frontend;

final class ModuleShortcodes {
	public const TAGS = array(
		'wpfinet_access_check'    => 'Adressökning',
		'wpfinet_instagram_feed'  => 'Instagram',
		'wpfinet_cookie_policy'   => 'Cookies & samtycke',
		'wpfinet_cookie_settings' => 'Cookies & samtycke',
	);

	public function register_hooks(): void {
		foreach ( array_keys( self::TAGS ) as $tag ) {
			add_shortcode( $tag, static function ( $atts ) use ( $tag ): string {
				$fallback = current_user_can( 'edit_posts' ) ? '<p class="wpfinet-module-off">' . esc_html( sprintf( /* translators: %s module */ __( 'WPFINET: modulen %s är avstängd (Moduler & integrationer).', 'finet-troubleshoot' ), self::TAGS[ $tag ] ) ) . '</p>' : '';
				return (string) apply_filters( 'wpfinet_shortcode_' . $tag, $fallback, is_array( $atts ) ? $atts : array() );
			} );
		}
	}
}
