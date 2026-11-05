<?php
/**
 * Prior blocking of non-necessary scripts/iframes/tracking pixels in the HTML response.
 *
 * The rewrite is identical for every visitor (deterministic, so it is safe with Cloudflare/page caches); the client
 * runtime re-activates blocked elements after consent. Covers enqueued scripts, theme/Elementor HTML widgets and
 * inline snippets that match a rule. Limitation (documented in the admin): scripts that a *non-blocked* script injects
 * at runtime cannot be intercepted here – block the loader (e.g. GTM) instead.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Consent;

final class Blocker {
	/** @var array<int,array{pattern:string,category:string,label:string}> */
	private array $src_rules;
	/** @var array<int,array{pattern:string,category:string,label:string}> */
	private array $inline_rules;
	/** @var array<string,string> handle => category */
	private array $handle_rules = array();
	private int $blocked = 0;

	public function __construct( ?array $settings = null ) {
		$settings           = $settings ?? ConsentSettings::get();
		$this->src_rules    = ConsentSettings::parse_rules( (string) $settings['rules'] );
		$this->inline_rules = ConsentSettings::parse_rules( (string) $settings['inline_rules'] );
		foreach ( ConsentSettings::parse_rules( (string) $settings['handle_rules'] ) as $r ) { $this->handle_rules[ $r['pattern'] ] = $r['category']; }
	}

	public function blocked_count(): int { return $this->blocked; }

	public function register_hooks(): void {
		add_filter( 'script_loader_tag', array( $this, 'script_tag' ), 99, 3 );
		add_action( 'template_redirect', array( $this, 'start_buffer' ), PHP_INT_MAX );
	}

	public function start_buffer(): void {
		if ( is_admin() || wp_doing_ajax() || wp_is_json_request() || is_feed() || is_robots() || is_trackback() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_customize_preview() ) {
			return;
		}
		ob_start( array( $this, 'process' ) );
	}

	/** Blocks enqueued scripts by handle (exact) or src rule. */
	public function script_tag( string $tag, string $handle, string $src ): string {
		$category = $this->handle_rules[ $handle ] ?? $this->match( $src, $this->src_rules );
		return $category ? $this->block_script( $tag, $category ) : $tag;
	}

	public function process( string $html ): string {
		if ( '' === $html || ( false === stripos( $html, '<script' ) && false === stripos( $html, '<iframe' ) && false === stripos( $html, '<noscript' ) ) ) {
			return $html;
		}
		$html = (string) preg_replace_callback( '#<script\b([^>]*)>(.*?)</script>#is', array( $this, 'script_cb' ), $html );
		$html = (string) preg_replace_callback( '#<iframe\b([^>]*)>(.*?)</iframe>#is', array( $this, 'iframe_cb' ), $html );
		$html = (string) preg_replace_callback( '#<noscript\b[^>]*>(.*?)</noscript>#is', array( $this, 'noscript_cb' ), $html );
		return $html;
	}

	/** @param array<int,string> $m */
	private function script_cb( array $m ): string {
		$attrs = $m[1];
		if ( preg_match( '/\bdata-wpfinet-(consent|necessary)\b/i', $attrs ) ) {
			return $m[0];
		}
		$type = preg_match( '/\btype\s*=\s*["\']?([^"\'\s>]+)/i', $attrs, $t ) ? strtolower( $t[1] ) : 'text/javascript';
		if ( ! in_array( $type, array( 'text/javascript', 'application/javascript', 'module', 'text/ecmascript' ), true ) ) {
			return $m[0]; // JSON data, templates etc. are never executed anyway.
		}
		$src      = preg_match( '/(?<![\w-])src\s*=\s*["\']([^"\']+)["\']/i', $attrs, $s ) ? html_entity_decode( $s[1] ) : '';
		$category = $src ? $this->match( $src, $this->src_rules ) : $this->match( $m[2], $this->inline_rules );
		return $category ? $this->block_script( $m[0], $category ) : $m[0];
	}

	private function block_script( string $tag, string $category ): string {
		++$this->blocked;
		$original_type = preg_match( '/\btype\s*=\s*["\']?([^"\'\s>]+)/i', $tag, $t ) ? $t[1] : '';
		$tag = (string) preg_replace( '/\s+type\s*=\s*(["\'][^"\']*["\']|[^\s>]+)/i', '', $tag, 1 );
		$tag = (string) preg_replace( '/(<script\b[^>]*?)\s+src\s*=\s*(["\'])/i', '$1 data-wpfinet-src=$2', $tag, 1 );
		$extra = ' type="text/plain" data-wpfinet-consent="' . esc_attr( $category ) . '"' . ( $original_type && 'text/javascript' !== strtolower( $original_type ) ? ' data-wpfinet-type="' . esc_attr( $original_type ) . '"' : '' );
		return (string) preg_replace( '/<script\b/i', '<script' . $extra, $tag, 1 );
	}

	/** @param array<int,string> $m */
	private function iframe_cb( array $m ): string {
		$attrs = $m[1];
		if ( preg_match( '/\bdata-wpfinet-(consent|necessary)\b/i', $attrs ) || ! preg_match( '/(?<![\w-])src\s*=\s*["\']([^"\']+)["\']/i', $attrs, $s ) ) {
			return $m[0];
		}
		$category = $this->match( html_entity_decode( $s[1] ), $this->src_rules );
		if ( ! $category ) {
			return $m[0];
		}
		++$this->blocked;
		$label = '';
		foreach ( $this->src_rules as $r ) { if ( $r['category'] === $category && str_contains( $s[1], $r['pattern'] ) ) { $label = $r['label']; break; } }
		$iframe = (string) preg_replace( '/\s+src\s*=\s*(["\'])/i', ' data-wpfinet-src=$1', $m[0], 1 );
		$iframe = (string) preg_replace( '/<iframe\b/i', '<iframe data-wpfinet-consent="' . esc_attr( $category ) . '" hidden', $iframe, 1 );
		$cat    = ConsentSettings::CATEGORY_LABELS[ $category ] ?? $category;
		return '<span class="wpfinet-consent-placeholder" data-wpfinet-placeholder="' . esc_attr( $category ) . '"><span>' . esc_html( sprintf( /* translators: 1 provider 2 category */ __( 'Innehållet från %1$s visas först när du godkänner cookies för %2$s.', 'finet-troubleshoot' ), $label ?: __( 'en extern tjänst', 'finet-troubleshoot' ), mb_strtolower( $cat ) ) ) . '</span> <button type="button" data-wpfinet-consent-accept="' . esc_attr( $category ) . '">' . esc_html__( 'Tillåt och visa', 'finet-troubleshoot' ) . '</button></span>' . $iframe;
	}

	/** Tracking pixels inside <noscript> (e.g. Meta Pixel fallback) cannot be consented without JS: neutralise them. @param array<int,string> $m */
	private function noscript_cb( array $m ): string {
		return (string) preg_replace_callback( '#<img\b([^>]*)(?<![\w-])src\s*=\s*(["\'])([^"\']+)\2#i', function ( array $img ): string {
			if ( ! $this->match( html_entity_decode( $img[3] ), $this->src_rules ) ) { return $img[0]; }
			++$this->blocked;
			return '<img' . $img[1] . 'data-wpfinet-blocked-src=' . $img[2] . $img[3] . $img[2];
		}, $m[0] );
	}

	/** @param array<int,array{pattern:string,category:string,label:string}> $rules */
	private function match( string $subject, array $rules ): string {
		if ( '' === $subject ) {
			return '';
		}
		$home = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( $rules as $rule ) {
			if ( '' !== $rule['pattern'] && false !== stripos( $subject, $rule['pattern'] ) ) {
				// Never block the site's own WPFINET assets.
				if ( str_contains( $subject, '/finet-troubleshoot/assets/' ) && str_contains( $subject, $home ) ) { return ''; }
				return $rule['category'];
			}
		}
		return '';
	}
}
