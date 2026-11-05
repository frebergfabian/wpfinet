<?php
/**
 * Global plugin settings.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Admin;

use FinetTroubleshoot\Activator;
use FinetTroubleshoot\Import\SchemaValidator;

final class Settings {
	public function register_hooks(): void {
		add_action( 'admin_init', array( $this, 'register' ) );
	}
	public function register(): void {
		register_setting( 'finet_ts_settings', 'finet_ts_settings', array( 'type' => 'array', 'sanitize_callback' => array( $this, 'sanitize' ), 'default' => Activator::default_settings() ) );
	}
	/** @return array<string,mixed> */
	public function sanitize( mixed $settings ): array {
		$defaults = Activator::default_settings();
		$settings = is_array( $settings ) ? $settings : array();
		// 3.0 (B1): the settings screen posts one tab at a time. Only the posted tab is replaced; other sections keep
		// their stored values instead of being reset (2.0.1 switched off the banner/statistics when saving another tab).
		$stored  = get_option( 'finet_ts_settings', array() );
		$stored  = array_replace_recursive( $defaults, is_array( $stored ) ? $stored : array() );
		$tab     = isset( $settings['_tab'] ) ? sanitize_key( (string) $settings['_tab'] ) : '';
		unset( $settings['_tab'] );
		$clean   = $this->sanitize_all( $settings, $defaults );
		$replace = $tab ? array( $tab ) : array_keys( $settings );
		foreach ( array_keys( $defaults ) as $section ) {
			if ( ! in_array( $section, $replace, true ) ) { $clean[ $section ] = $stored[ $section ]; }
		}
		return $clean;
	}

	/** @param array<string,mixed> $settings @param array<string,mixed> $defaults @return array<string,mixed> */
	private function sanitize_all( array $settings, array $defaults ): array {
		return array(
			'general' => array(
				'show_title'    => ! empty( $settings['general']['show_title'] ), 'show_back' => ! empty( $settings['general']['show_back'] ), 'show_restart' => ! empty( $settings['general']['show_restart'] ),
				'progress_mode' => SchemaValidator::pick( $settings['general']['progress_mode'] ?? null, array( 'none', 'step', 'bar' ), $defaults['general']['progress_mode'] ),
				'animation'     => SchemaValidator::pick( $settings['general']['animation'] ?? null, array( 'fade', 'slide', 'none' ), $defaults['general']['animation'] ),
			),
			'appearance' => array(
				'preset'         => SchemaValidator::pick( $settings['appearance']['preset'] ?? null, array( 'inherit', 'wpfinet', 'custom' ), $defaults['appearance']['preset'] ),
				'primary_color'  => sanitize_hex_color( (string) ( $settings['appearance']['primary_color'] ?? '' ) ) ?: $defaults['appearance']['primary_color'],
				'background'     => SchemaValidator::css_color( (string) ( $settings['appearance']['background'] ?? $defaults['appearance']['background'] ) ) ?: $defaults['appearance']['background'],
				'card_background'=> SchemaValidator::css_color( (string) ( $settings['appearance']['card_background'] ?? $defaults['appearance']['card_background'] ) ) ?: $defaults['appearance']['card_background'],
				'border_color'   => sanitize_hex_color( (string) ( $settings['appearance']['border_color'] ?? '' ) ) ?: $defaults['appearance']['border_color'],
				'radius'         => min( 48, max( 0, absint( $settings['appearance']['radius'] ?? $defaults['appearance']['radius'] ) ) ),
				'spacing'        => min( 64, max( 8, absint( $settings['appearance']['spacing'] ?? $defaults['appearance']['spacing'] ) ) ),
				'max_width'      => min( 1440, max( 280, absint( $settings['appearance']['max_width'] ?? $defaults['appearance']['max_width'] ) ) ),
				'progress'       => ! empty( $settings['appearance']['progress'] ),
			),
			'support' => array(
				'company' => sanitize_text_field( (string) ( $settings['support']['company'] ?? '' ) ), 'phone' => sanitize_text_field( (string) ( $settings['support']['phone'] ?? '' ) ), 'email' => sanitize_email( (string) ( $settings['support']['email'] ?? '' ) ), 'url' => esc_url_raw( (string) ( $settings['support']['url'] ?? '' ) ), 'outage_url' => esc_url_raw( (string) ( $settings['support']['outage_url'] ?? '' ) ), 'opening_hours' => sanitize_text_field( (string) ( $settings['support']['opening_hours'] ?? '' ) ), 'text' => sanitize_textarea_field( (string) ( $settings['support']['text'] ?? '' ) ),
			),
			'outages' => array(
				'banner_enabled' => ! empty( $settings['outages']['banner_enabled'] ),
				'status_url' => esc_url_raw( (string) ( $settings['outages']['status_url'] ?? '' ) ),
			),
			'analytics' => array(
				'enabled' => ! empty( $settings['analytics']['enabled'] ),
				'session_timeout_minutes' => min( 240, max( 5, absint( $settings['analytics']['session_timeout_minutes'] ?? $defaults['analytics']['session_timeout_minutes'] ) ) ),
				'retention_days' => min( 1825, max( 0, absint( $settings['analytics']['retention_days'] ?? $defaults['analytics']['retention_days'] ) ) ),
			),
			'advanced' => array( 'delete_data' => ! empty( $settings['advanced']['delete_data'] ), 'debug' => ! empty( $settings['advanced']['debug'] ) ),
		);
	}
}
