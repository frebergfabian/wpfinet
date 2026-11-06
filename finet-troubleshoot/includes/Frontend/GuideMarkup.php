<?php
/**
 * Server-side markup of a guide (3.1.1). Mirrors cardHTML()/the shell in assets/src/frontend/runtime.js exactly, so
 * the first paint already has the final layout and height:
 * - the shell (title, progress, stage, navigation) and the start step are rendered on the server;
 * - the stage contains an invisible "sizer" with every step stacked in the same grid cell, so CSS alone makes the
 *   stage as tall as the tallest step at any width, font or zoom level (no JavaScript measuring);
 * - the runtime adopts this markup instead of re-rendering it (data-ssr), so nothing moves when JavaScript starts.
 * tests/js/guide-ssr.test.js verifies that this output and the runtime's output produce the same DOM.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Frontend;

final class GuideMarkup {
	public const MAX_SIZER_NODES = 200;
	private const S = array( 'step' => 'Steg', 'of' => 'av', 'back' => 'Tillbaka', 'restart' => 'Börja om', 'checking' => 'Kontrollerar driftstatus …', 'supportPage' => 'Öppna supportsidan', 'zoom' => 'Förstora bilden', 'next' => 'Fortsätt' );

	/** @param array<string,mixed> $data Public guide data (Renderer::public_data()). */
	public static function render( array $data ): string {
		$nodes = array();
		foreach ( (array) ( $data['nodes'] ?? array() ) as $node ) {
			if ( is_array( $node ) && ! empty( $node['id'] ) ) { $nodes[ (string) $node['id'] ] = $node; }
		}
		$start = (string) ( $data['start_node'] ?? '' );
		if ( ! isset( $nodes[ $start ] ) ) { return ''; }
		$general      = (array) ( $data['settings']['general'] ?? array() );
		$show_title   = false !== ( $general['show_title'] ?? true ) && ! empty( $data['title'] );
		$progress     = (string) ( ( $general['progress_mode'] ?? '' ) ?: 'step' );
		$show_back    = false !== ( $general['show_back'] ?? true );
		$show_restart = false !== ( $general['show_restart'] ?? true );

		$html = '<div class="wpfinet-guide" data-ssr="' . esc_attr( $start ) . '">';
		if ( $show_title || 'none' !== $progress ) {
			$html .= '<header class="wpfinet-guide__header">';
			if ( $show_title ) { $html .= '<h2 class="wpfinet-guide__title">' . esc_html( (string) $data['title'] ) . '</h2>'; }
			if ( 'none' !== $progress ) {
				$left  = self::remaining( $nodes, $start );
				$text  = self::S['step'] . ' 1' . ( 'bar' === $progress && null !== $left ? ' ' . self::S['of'] . ' ' . ( 1 + $left ) : '' );
				$html .= '<div class="wpfinet-guide__progress-row"><p class="wpfinet-guide__progress" data-progress>' . esc_html( $text ) . '</p>';
				if ( 'bar' === $progress ) {
					$pct   = null === $left ? 0 : (int) round( 1 / max( 1, 1 + $left ) * 100 );
					$html .= '<div class="wpfinet-guide__progressbar" role="progressbar" aria-label="' . esc_attr( self::S['step'] ) . '" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $pct . '"' . ( null === $left ? ' hidden' : '' ) . '><span style="width: ' . $pct . '%;"></span></div>';
				}
				$html .= '</div>';
			}
			$html .= '</header>';
		}
		$html .= '<div class="wpfinet-guide__stage" data-stage aria-live="polite">' . self::sizer( $data ) . self::card( $nodes[ $start ], $data, false ) . '</div>';
		if ( $show_back || $show_restart ) {
			$html .= '<nav class="wpfinet-guide__navigation" aria-label="' . esc_attr( (string) ( $data['title'] ?: self::S['step'] ) ) . '">';
			$html .= $show_back ? '<button type="button" class="wpfinet-guide__text-button is-placeholder" data-back disabled aria-hidden="true" tabindex="-1">← ' . esc_html( self::S['back'] ) . '</button>' : '<span></span>';
			if ( $show_restart ) { $html .= '<button type="button" class="wpfinet-guide__text-button" data-restart>' . esc_html( self::S['restart'] ) . '</button>'; }
			$html .= '</nav>';
		}
		return $html . '</div>';
	}

	/** Every step without forms, stacked invisibly in one grid cell: reserves the height of the tallest one. */
	public static function sizer( array $data ): string {
		$html  = '<div class="wpfinet-guide__sizer" aria-hidden="true" inert>';
		$count = 0;
		foreach ( (array) ( $data['nodes'] ?? array() ) as $node ) {
			if ( ! is_array( $node ) || empty( $node['id'] ) || ! empty( $node['form_html'] ) ) { continue; }
			if ( ++$count > self::MAX_SIZER_NODES ) { break; }
			$html .= self::card( $node, $data, true );
		}
		return $html . '</div>';
	}

	/** Shortest number of steps to a result/support step, null if none is reachable (runtime: Infinity). */
	private static function remaining( array $nodes, string $id ): ?int {
		$seen  = array();
		$queue = array( array( $id, 0 ) );
		while ( $queue ) {
			[ $current, $depth ] = array_shift( $queue );
			$n = $nodes[ $current ] ?? null;
			if ( ! $n || isset( $seen[ $current ] ) ) { continue; }
			$seen[ $current ] = true;
			if ( in_array( $n['type'] ?? '', array( 'result', 'support' ), true ) ) { return $depth; }
			$next = array();
			if ( ! empty( $n['next'] ) ) { $next[] = (string) $n['next']; }
			foreach ( (array) ( $n['options'] ?? array() ) as $o ) { if ( is_array( $o ) && ! empty( $o['next'] ) ) { $next[] = (string) $o['next']; } }
			if ( ! empty( $n['outage'] ) && is_array( $n['outage'] ) ) {
				if ( ! empty( $n['outage']['match_node'] ) ) { $next[] = (string) $n['outage']['match_node']; }
				if ( ! empty( $n['outage']['no_match_node'] ) ) { $next[] = (string) $n['outage']['no_match_node']; }
			}
			foreach ( $next as $x ) { $queue[] = array( $x, $depth + 1 ); }
		}
		return null;
	}

	private static function href( $value ): string {
		$v = trim( (string) $value );
		if ( '' === $v ) { return '#'; }
		if ( preg_match( '#^[/\#?]#', $v ) && ! str_starts_with( $v, '//' ) ) { return $v; }
		if ( ! preg_match( '/^([a-z][a-z0-9+.-]*):/i', $v, $m ) ) { return str_starts_with( $v, '//' ) ? '#' : $v; }
		return in_array( strtolower( $m[1] ), array( 'http', 'https', 'mailto', 'tel' ), true ) ? $v : '#';
	}

	private static function figure( array $image, bool $measuring ): string {
		$placement = (string) ( ( $image['placement'] ?? '' ) ?: 'above_heading' );
		$alt       = (string) ( $image['alt'] ?? '' );
		$media     = $measuring ? '<span class="wpfinet-guide__img"></span>' : '<img class="wpfinet-guide__img" ' . ( ! empty( $image['zoom'] ) ? 'data-zoom tabindex="0" role="button" aria-label="' . esc_attr( self::S['zoom'] . ( '' !== $alt ? ': ' . $alt : '' ) ) . '" ' : '' ) . 'src="' . esc_attr( self::href( $image['url'] ?? '' ) ) . '" alt="' . esc_attr( $alt ) . '" decoding="async">';
		return '<figure class="wpfinet-guide__figure wpfinet-guide__figure--' . esc_attr( $placement ) . '"><div class="wpfinet-guide__frame">' . $media . '</div>' . ( ! empty( $image['caption'] ) ? '<figcaption>' . esc_html( (string) $image['caption'] ) . '</figcaption>' : '' ) . '</figure>';
	}

	private static function support( array $node, array $global ): string {
		$s    = ( isset( $node['support'] ) && is_array( $node['support'] ) && false === ( $node['support']['inherit_global'] ?? null ) ) ? $node['support'] : $global;
		$html = array();
		if ( ! empty( $s['company'] ) ) { $html[] = '<strong>' . esc_html( (string) $s['company'] ) . '</strong>'; }
		if ( ! empty( $s['phone'] ) ) { $html[] = '<a href="tel:' . esc_attr( (string) preg_replace( '/[^+\d]/', '', (string) $s['phone'] ) ) . '">' . esc_html( (string) $s['phone'] ) . '</a>'; }
		if ( ! empty( $s['email'] ) ) { $html[] = '<a href="mailto:' . esc_attr( (string) $s['email'] ) . '">' . esc_html( (string) $s['email'] ) . '</a>'; }
		if ( ! empty( $s['url'] ) ) { $html[] = '<a href="' . esc_attr( self::href( $s['url'] ) ) . '">' . esc_html( self::S['supportPage'] ) . '</a>'; }
		if ( ! empty( $s['opening_hours'] ) ) { $html[] = '<span>' . esc_html( (string) $s['opening_hours'] ) . '</span>'; }
		return $html ? '<aside class="wpfinet-guide__support">' . implode( '', $html ) . '</aside>' : '';
	}

	private static function ctas( array $node ): string {
		if ( empty( $node['ctas'] ) || ! is_array( $node['ctas'] ) ) { return ''; }
		$out = '';
		foreach ( array_values( $node['ctas'] ) as $index => $cta ) {
			$cta   = (array) $cta;
			$type  = (string) ( $cta['type'] ?? '' );
			$label = esc_html( (string) ( ( $cta['label'] ?? '' ) ?: $type ) );
			$value = (string) ( $cta['value'] ?? '' );
			$cls   = 'wpfinet-guide__button wpfinet-guide__button--secondary';
			if ( 'phone' === $type ) { $out .= '<a class="' . $cls . '" data-cta="phone" href="tel:' . esc_attr( (string) preg_replace( '/[^+\d]/', '', $value ) ) . '">' . $label . '</a>'; continue; }
			if ( 'email' === $type ) { $out .= '<a class="' . $cls . '" data-cta="email" href="mailto:' . esc_attr( $value ) . '">' . $label . '</a>'; continue; }
			if ( in_array( $type, array( 'url', 'internal', 'support' ), true ) ) { $out .= '<a class="' . $cls . '" data-cta="' . esc_attr( $type ) . '" href="' . esc_attr( self::href( $value ) ) . '">' . $label . '</a>'; continue; }
			if ( 'wpforms' === $type ) { $out .= '<button type="button" class="' . $cls . '" data-cta-form="' . esc_attr( (string) ( ( $cta['id'] ?? '' ) ?: $index ) ) . '">' . $label . '</button>'; continue; }
			$out .= '<button type="button" class="' . $cls . '" data-cta-action="' . esc_attr( $type ) . '">' . $label . '</button>';
		}
		return '<div class="wpfinet-guide__ctas">' . $out . '</div>';
	}

	/** One step (cardHTML in runtime.js). */
	public static function card( array $node, array $data, bool $measuring ): string {
		$image     = ! empty( $node['image'] ) && is_array( $node['image'] ) && ! empty( $node['image']['url'] ) ? $node['image'] : null;
		$placement = $image ? (string) ( ( $image['placement'] ?? '' ) ?: 'above_heading' ) : '';
		$side      = in_array( $placement, array( 'left', 'right' ), true );
		$type      = (string) ( $node['type'] ?? '' );
		$body      = '';
		if ( $image && ( 'above_heading' === $placement || $side ) ) { $body .= self::figure( $image, $measuring ); }
		$body .= '<div class="wpfinet-guide__text"><h3 tabindex="-1" class="wpfinet-guide__heading">' . esc_html( (string) ( ( $node['heading'] ?? '' ) ?: ( $node['title'] ?? '' ) ) ) . '</h3>';
		if ( $image && 'below_heading' === $placement ) { $body .= self::figure( $image, $measuring ); }
		if ( ! empty( $node['intro'] ) ) { $body .= '<p class="wpfinet-guide__intro">' . esc_html( (string) $node['intro'] ) . '</p>'; }
		if ( ! empty( $node['content'] ) ) { $body .= '<div class="wpfinet-guide__content">' . wp_kses_post( (string) $node['content'] ) . '</div>'; }
		if ( $image && 'below_text' === $placement ) { $body .= self::figure( $image, $measuring ); }
		if ( ! empty( $node['form_html'] ) && ! $measuring ) { $body .= '<div class="wpfinet-guide__form">' . $node['form_html'] . '</div>'; }
		if ( 'support' === $type ) { $body .= self::support( $node, (array) ( $data['support'] ?? array() ) ); }
		if ( 'outage' === $type ) { $body .= '<div class="wpfinet-guide__outages" data-outage-result role="status">' . esc_html( self::S['checking'] ) . '</div>'; }
		$body .= '</div>';
		$actions = '';
		if ( in_array( $type, array( 'question', 'check' ), true ) && isset( $node['options'] ) && is_array( $node['options'] ) ) {
			$actions .= '<div class="wpfinet-guide__options">';
			foreach ( $node['options'] as $o ) { $o = (array) $o; $actions .= '<button type="button" class="wpfinet-guide__button wpfinet-guide__button--option" data-next="' . esc_attr( (string) ( $o['next'] ?? '' ) ) . '">' . esc_html( (string) ( $o['label'] ?? '' ) ) . '</button>'; }
			$actions .= '</div>';
		} elseif ( ! empty( $node['next'] ) && 'outage' !== $type ) {
			$actions .= '<div class="wpfinet-guide__options wpfinet-guide__options--single"><button type="button" class="wpfinet-guide__button" data-next="' . esc_attr( (string) $node['next'] ) . '">' . esc_html( (string) ( ( $node['button_text'] ?? '' ) ?: self::S['next'] ) ) . '</button></div>';
		}
		$actions .= self::ctas( $node );
		$cls = 'wpfinet-guide__card wpfinet-guide__card--' . ( $type ?: 'information' ) . ( 'result' === $type ? ' wpfinet-guide__result--' . ( ( $node['result_type'] ?? '' ) ?: 'info' ) : '' ) . ( $side ? ' wpfinet-guide__card--media-' . $placement : '' ) . ( ! empty( $node['css_class'] ) ? ' ' . $node['css_class'] : '' );
		return '<article class="' . esc_attr( $cls ) . '"' . ( $measuring ? '' : ' data-node="' . esc_attr( (string) $node['id'] ) . '"' ) . '><div class="wpfinet-guide__body">' . $body . '</div>' . ( '' !== $actions ? '<div class="wpfinet-guide__actions">' . $actions . '</div>' : '' ) . '</article>';
	}
}
