<?php
/**
 * Import document validation and sanitisation.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Import;

use FinetTroubleshoot\Validation\FlowValidator;

final class SchemaValidator {
	public function __construct( private FlowValidator $flow_validator ) {}

	/** @return array<string, mixed> */
	public function validate( array $document ): array {
		$errors   = array();
		$warnings = array();
		if ( 'finet-troubleshoot' !== ( $document['schema'] ?? '' ) ) {
			$errors[] = __( 'Filen är inte en giltig Finet Troubleshoot-importfil.', 'finet-troubleshoot' );
		}
		if ( ! in_array( (string) ( $document['schema_version'] ?? '' ), array( '1.0', '2.0' ), true ) ) {
			$errors[] = sprintf( __( 'Schema-version %s stöds inte.', 'finet-troubleshoot' ), (string) ( $document['schema_version'] ?? __( '(saknas)', 'finet-troubleshoot' ) ) );
		}
		if ( ! isset( $document['guides'] ) || ! is_array( $document['guides'] ) ) {
			$errors[] = __( 'Importfilen måste innehålla en guides-array.', 'finet-troubleshoot' );
			return array( 'valid' => false, 'errors' => $errors, 'warnings' => $warnings, 'guides' => array(), 'summary' => array() );
		}

		$max_guides = (int) apply_filters( 'finet_troubleshoot_max_import_guides', 100 );
		if ( count( $document['guides'] ) > $max_guides ) {
			$errors[] = sprintf( __( 'Filen innehåller fler än %d guider.', 'finet-troubleshoot' ), $max_guides );
		}

		$external_ids = array();
		$slugs = array();
		$guides = array();
		foreach ( $document['guides'] as $index => $guide ) {
			$prefix = sprintf( __( 'Guide %d', 'finet-troubleshoot' ), $index + 1 );
			if ( ! is_array( $guide ) ) {
				$errors[] = $prefix . ': ' . __( 'guideformatet är ogiltigt.', 'finet-troubleshoot' );
				continue;
			}
			$guide_errors = $this->validate_guide( $guide, $prefix );
			$errors = array_merge( $errors, $guide_errors['errors'] );
			$warnings = array_merge( $warnings, $guide_errors['warnings'] );
			$clean = $guide_errors['guide'];
			if ( isset( $external_ids[ $clean['external_id'] ] ) ) {
				$errors[] = sprintf( __( '%1$s: external_id %2$s förekommer mer än en gång i filen.', 'finet-troubleshoot' ), $prefix, $clean['external_id'] );
			}
			if ( isset( $slugs[ $clean['slug'] ] ) ) {
				$errors[] = sprintf( __( '%1$s: slug %2$s förekommer mer än en gång i filen.', 'finet-troubleshoot' ), $prefix, $clean['slug'] );
			}
			$external_ids[ $clean['external_id'] ] = true;
			$slugs[ $clean['slug'] ] = true;
			$guides[] = $clean;
		}

		return array(
			'valid'    => empty( $errors ),
			'errors'   => array_values( array_unique( $errors ) ),
			'warnings' => array_values( array_unique( $warnings ) ),
			'guides'   => $guides,
			'summary'  => array( 'guides' => count( $guides ), 'nodes' => array_sum( array_map( static fn ( array $guide ): int => count( $guide['nodes'] ), $guides ) ) ),
		);
	}

	/** @return array{errors: array<int,string>, warnings: array<int,string>, guide: array<string,mixed>} */
	private function validate_guide( array $guide, string $prefix ): array {
		$errors = array();
		foreach ( array( 'external_id', 'title', 'slug', 'start_node', 'nodes' ) as $field ) {
			if ( ! array_key_exists( $field, $guide ) || '' === $guide[ $field ] || null === $guide[ $field ] ) {
				$errors[] = sprintf( __( '%1$s saknar obligatoriskt fält: %2$s.', 'finet-troubleshoot' ), $prefix, $field );
			}
		}
		$clean = $this->sanitize_guide( $guide );
		if ( ! preg_match( '/^[a-z0-9][a-z0-9-]{0,99}$/', $clean['external_id'] ) ) {
			$errors[] = sprintf( __( '%1$s har ett ogiltigt external_id.', 'finet-troubleshoot' ), $prefix );
		}
		if ( ! is_array( $guide['nodes'] ?? null ) ) {
			$errors[] = sprintf( __( '%s har en ogiltig nodes-array.', 'finet-troubleshoot' ), $prefix );
		}
		$max_nodes = (int) apply_filters( 'finet_troubleshoot_max_nodes_per_guide', 500 );
		if ( count( $clean['nodes'] ) > $max_nodes ) {
			$errors[] = sprintf( __( '%1$s har fler än %2$d noder.', 'finet-troubleshoot' ), $prefix, $max_nodes );
		}
		$flow = $this->flow_validator->validate( $clean );
		foreach ( $flow['errors'] as $message ) { $errors[] = $prefix . ': ' . $message; }
		return array( 'errors' => $errors, 'warnings' => array_map( static fn ( string $message ): string => $prefix . ': ' . $message, $flow['warnings'] ), 'guide' => $clean );
	}

	/** @return array<string,mixed> */
	public function sanitize_guide( array $guide ): array {
		$nodes = array();
		$status = $guide['status'] ?? 'draft';
		foreach ( (array) ( $guide['nodes'] ?? array() ) as $node ) {
			if ( is_array( $node ) ) { $nodes[] = $this->sanitize_node( $node ); }
		}
		return array(
			'external_id' => sanitize_title( (string) ( $guide['external_id'] ?? '' ) ),
			'title'       => sanitize_text_field( (string) ( $guide['title'] ?? '' ) ),
			'slug'        => sanitize_title( (string) ( $guide['slug'] ?? '' ) ),
			'description' => sanitize_textarea_field( (string) ( $guide['description'] ?? '' ) ),
			'status'      => in_array( $status, array( 'publish', 'draft', 'private' ), true ) ? $status : 'draft',
			'start_node'  => sanitize_title( (string) ( $guide['start_node'] ?? '' ) ),
			'settings'    => $this->sanitize_settings( is_array( $guide['settings'] ?? null ) ? $guide['settings'] : array() ),
			'variables'   => is_array( $guide['variables'] ?? null ) ? self::sanitize_variables( $guide['variables'] ) : array(),
			'nodes'       => $nodes,
		);
	}

	/** @return array<string,mixed> */
	public function sanitize_node( array $node ): array {
		$options = array();
		$max_options = (int) apply_filters( 'finet_troubleshoot_max_options_per_node', 50 );
		foreach ( array_slice( (array) ( $node['options'] ?? array() ), 0, $max_options ) as $option ) {
			if ( ! is_array( $option ) ) { continue; }
			$options[] = array(
				'id'    => sanitize_title( (string) ( $option['id'] ?? '' ) ),
				'label' => sanitize_text_field( (string) ( $option['label'] ?? '' ) ),
				'next'  => sanitize_title( (string) ( $option['next'] ?? '' ) ),
				'style' => self::pick( $option['style'] ?? null, array( 'primary', 'secondary', 'success', 'warning', 'danger' ), 'primary' ),
				'icon'  => sanitize_html_class( (string) ( $option['icon'] ?? '' ) ),
			);
		}
		$image = is_array( $node['image'] ?? null ) ? $node['image'] : array();
		$image_url = 'demo:cpe' === (string) ( $image['url'] ?? '' ) ? FINET_TS_URL . 'assets/images/cpe.svg' : esc_url_raw( (string) ( $image['url'] ?? '' ) );
		$image_scheme = strtolower( (string) wp_parse_url( $image_url, PHP_URL_SCHEME ) );
		if ( ! in_array( $image_scheme, array( 'http', 'https' ), true ) ) { $image_url = ''; }
		$support = is_array( $node['support'] ?? null ) ? $node['support'] : array();
		$position = is_array( $node['editor_position'] ?? null ) ? $node['editor_position'] : array();
		$wpforms = is_array( $node['wpforms'] ?? null ) ? $node['wpforms'] : array();
		$outage = is_array( $node['outage'] ?? null ) ? $node['outage'] : array();
		$ctas = array();
		foreach ( array_slice( (array) ( $node['ctas'] ?? array() ), 0, 10 ) as $cta ) {
			if ( ! is_array( $cta ) ) { continue; }
			$type = sanitize_key( (string) ( $cta['type'] ?? '' ) );
			if ( ! in_array( $type, array( 'phone', 'email', 'wpforms', 'url', 'support', 'restart', 'back', 'internal' ), true ) ) { continue; }
			$ctas[] = array( 'id' => sanitize_title( (string) ( $cta['id'] ?? wp_generate_uuid4() ) ), 'type' => $type, 'label' => sanitize_text_field( (string) ( $cta['label'] ?? '' ) ), 'value' => in_array( $type, array( 'url', 'internal', 'support' ), true ) ? self::safe_url( (string) ( $cta['value'] ?? '' ) ) : sanitize_text_field( (string) ( $cta['value'] ?? '' ) ), /* 3.0 (B5): 'support' is rendered as href, so it is URL-sanitised. */ 'form_id' => absint( $cta['form_id'] ?? 0 ), 'form_title' => sanitize_text_field( (string) ( $cta['form_title'] ?? '' ) ) );
		}
		return array(
			'id'            => sanitize_title( (string) ( $node['id'] ?? '' ) ),
			'type'          => sanitize_key( (string) ( $node['type'] ?? '' ) ),
			'title'         => sanitize_text_field( (string) ( $node['title'] ?? '' ) ),
			'heading'       => sanitize_text_field( (string) ( $node['heading'] ?? '' ) ),
			'intro'         => sanitize_textarea_field( (string) ( $node['intro'] ?? '' ) ),
			'content'       => wp_kses( (string) ( $node['content'] ?? '' ), $this->allowed_html() ),
			'image'         => $image_url ? array( 'url' => $image_url, 'alt' => sanitize_text_field( (string) ( $image['alt'] ?? '' ) ), 'caption' => sanitize_text_field( (string) ( $image['caption'] ?? '' ) ), 'placement' => self::pick( $image['placement'] ?? null, array( 'above_heading', 'below_heading', 'below_text', 'left', 'right' ), 'above_heading' ), 'zoom' => ! empty( $image['zoom'] ) ) : null,
			'icon'          => sanitize_html_class( (string) ( $node['icon'] ?? '' ) ),
			'button_text'   => sanitize_text_field( (string) ( $node['button_text'] ?? __( 'Fortsätt', 'finet-troubleshoot' ) ) ),
			'next'          => sanitize_title( (string) ( $node['next'] ?? '' ) ),
			'options'       => $options,
			'css_class'     => sanitize_html_class( (string) ( $node['css_class'] ?? '' ) ),
			'admin_comment' => sanitize_textarea_field( (string) ( $node['admin_comment'] ?? '' ) ),
			'result_type'   => sanitize_key( (string) ( $node['result_type'] ?? 'info' ) ),
			'support'       => array(
				'inherit_global' => ! isset( $support['inherit_global'] ) || (bool) $support['inherit_global'],
				'phone'          => sanitize_text_field( (string) ( $support['phone'] ?? '' ) ),
				'email'          => sanitize_email( (string) ( $support['email'] ?? '' ) ),
				'url'            => self::safe_url( (string) ( $support['url'] ?? '' ) ),
				'opening_hours'  => sanitize_text_field( (string) ( $support['opening_hours'] ?? '' ) ),
			),
			'editor_position' => array( 'x' => max( -10000, min( 10000, (float) ( $position['x'] ?? 0 ) ) ), 'y' => max( -10000, min( 10000, (float) ( $position['y'] ?? 0 ) ) ) ),
			'ctas'          => $ctas,
			'wpforms'       => array( 'form_id' => absint( $wpforms['form_id'] ?? 0 ), 'form_title' => sanitize_text_field( (string) ( $wpforms['form_title'] ?? '' ) ), 'embedded' => ! empty( $wpforms['embedded'] ) ),
			'outage'        => array( 'service' => sanitize_title( (string) ( $outage['service'] ?? '' ) ), 'provider' => sanitize_title( (string) ( $outage['provider'] ?? '' ) ), 'area' => sanitize_title( (string) ( $outage['area'] ?? '' ) ), 'match_node' => sanitize_title( (string) ( $outage['match_node'] ?? '' ) ), 'no_match_node' => sanitize_title( (string) ( $outage['no_match_node'] ?? '' ) ), 'mode' => self::pick( $outage['mode'] ?? null, array( 'show', 'continue', 'branch' ), 'show' ) ),
			'meta'          => is_array( $node['meta'] ?? null ) ? $node['meta'] : array(),
		);
	}

	/** @return array<string,mixed> */
	public function sanitize_settings( array $settings ): array {
		$appearance = is_array( $settings['appearance'] ?? null ) ? $settings['appearance'] : array();
		$general    = is_array( $settings['general'] ?? null ) ? $settings['general'] : array();
		return array(
			'use_global_design' => ! isset( $settings['use_global_design'] ) || (bool) $settings['use_global_design'],
			'general' => array(
				'show_title'    => ! isset( $general['show_title'] ) || (bool) $general['show_title'],
				'show_back'     => ! isset( $general['show_back'] ) || (bool) $general['show_back'],
				'show_restart'  => ! isset( $general['show_restart'] ) || (bool) $general['show_restart'],
				'progress_mode' => self::pick( $general['progress_mode'] ?? null, array( 'none', 'step', 'bar' ), 'step' ),
				'animation'     => self::pick( $general['animation'] ?? null, array( 'fade', 'slide', 'none' ), 'fade' ),
			),
			'appearance' => array(
				'preset'         => self::pick( $appearance['preset'] ?? null, array( 'inherit', 'wpfinet', 'custom' ), 'inherit' ),
				'primary_color'  => sanitize_hex_color( (string) ( $appearance['primary_color'] ?? '' ) ) ?: '',
				'background'     => self::css_color( (string) ( $appearance['background'] ?? '' ) ),
				'card_background'=> self::css_color( (string) ( $appearance['card_background'] ?? '' ) ),
				'border_color'   => sanitize_hex_color( (string) ( $appearance['border_color'] ?? '' ) ) ?: '',
				'radius'         => min( 48, max( 0, absint( $appearance['radius'] ?? 8 ) ) ),
				'spacing'        => min( 64, max( 8, absint( $appearance['spacing'] ?? 24 ) ) ),
				'max_width'      => min( 1440, max( 280, absint( $appearance['max_width'] ?? 760 ) ) ),
			),
			'support' => is_array( $settings['support'] ?? null ) ? array_map( static fn ( $v ) => is_scalar( $v ) ? sanitize_text_field( (string) $v ) : '', array_intersect_key( $settings['support'], array_flip( array( 'company', 'phone', 'email', 'url', 'outage_url', 'opening_hours', 'text' ) ) ) ) : array(),
		);
	}

	/** Returns $value when it is one of $allowed, otherwise $default (2.0.1 returned null for missing keys, B26). */
	public static function pick( mixed $value, array $allowed, string $default ): string {
		return is_string( $value ) && in_array( $value, $allowed, true ) ? $value : $default;
	}

	/** URL usable as href: http(s), mailto, tel or site-relative. Everything else (javascript:, data: …) becomes ''. */
	public static function safe_url( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) { return ''; }
		if ( preg_match( '#^(/|\#|\?)#', $value ) ) { return esc_url_raw( $value ); }
		if ( preg_match( '#^([a-z][a-z0-9+.-]*):#i', $value, $m ) && ! in_array( strtolower( $m[1] ), array( 'http', 'https', 'mailto', 'tel' ), true ) ) { return ''; }
		return esc_url_raw( $value, array( 'http', 'https', 'mailto', 'tel' ) );
	}

	/** Accepts colour-like CSS values only (hex, rgb/hsl, named, transparent). Anything else (url(), expressions) → ''. */
	public static function css_color( string $value ): string {
		$value = trim( $value );
		return preg_match( '/^(#[0-9a-f]{3,8}|(rgb|rgba|hsl|hsla)\([0-9.,%\s\/]+\)|[a-z]{3,20})$/i', $value ) ? $value : '';
	}

	/** @param array<mixed> $variables @return array<string,mixed> */
	public static function sanitize_variables( array $variables, int $depth = 0 ): array {
		$clean = array();
		foreach ( array_slice( $variables, 0, 100, true ) as $key => $value ) {
			$key = is_int( $key ) ? $key : sanitize_key( (string) $key );
			if ( is_array( $value ) ) { $clean[ $key ] = $depth < 3 ? self::sanitize_variables( $value, $depth + 1 ) : array(); }
			elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) || null === $value ) { $clean[ $key ] = $value; }
			else { $clean[ $key ] = sanitize_text_field( (string) $value ); }
		}
		return $clean;
	}

	/** @return array<string,array<int|string,mixed>> */
	public function allowed_html(): array {
		$allowed = array(
			'p' => array( 'class' => true ), 'strong' => array(), 'em' => array(), 'ul' => array( 'class' => true ), 'ol' => array( 'class' => true ), 'li' => array(), 'a' => array( 'href' => true, 'title' => true, 'target' => true, 'rel' => true ), 'br' => array(), 'h2' => array( 'class' => true ), 'h3' => array( 'class' => true ), 'h4' => array( 'class' => true ), 'blockquote' => array( 'class' => true ), 'code' => array( 'class' => true ),
		);
		return apply_filters( 'finet_troubleshoot_allowed_html', $allowed );
	}
}
