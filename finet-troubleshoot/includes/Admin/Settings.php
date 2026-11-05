<?php
/**
 * Global plugin settings.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Admin;

use FinetTroubleshoot\Activator;

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
		return array(
			'general' => array(
				'show_title'    => ! empty( $settings['general']['show_title'] ), 'show_back' => ! empty( $settings['general']['show_back'] ), 'show_restart' => ! empty( $settings['general']['show_restart'] ),
				'progress_mode' => in_array( $settings['general']['progress_mode'] ?? '', array( 'none', 'step', 'bar' ), true ) ? $settings['general']['progress_mode'] : $defaults['general']['progress_mode'],
				'animation'     => in_array( $settings['general']['animation'] ?? '', array( 'fade', 'slide', 'none' ), true ) ? $settings['general']['animation'] : $defaults['general']['animation'],
			),
			'appearance' => array(
				'preset'         => in_array( $settings['appearance']['preset'] ?? 'inherit', array( 'inherit', 'wpfinet', 'custom' ), true ) ? $settings['appearance']['preset'] : $defaults['appearance']['preset'],
				'primary_color'  => sanitize_hex_color( (string) ( $settings['appearance']['primary_color'] ?? '' ) ) ?: $defaults['appearance']['primary_color'],
				'background'     => sanitize_text_field( (string) ( $settings['appearance']['background'] ?? $defaults['appearance']['background'] ) ),
				'card_background'=> sanitize_text_field( (string) ( $settings['appearance']['card_background'] ?? $defaults['appearance']['card_background'] ) ),
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
