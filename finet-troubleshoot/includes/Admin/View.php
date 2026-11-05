<?php
/**
 * Small, escaped view helpers for the WPFINET design system (see assets/src/admin/wpfinet-admin.css).
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Admin;

final class View {
	public static function open( string $title, string $lead = '' ): void {
		echo '<div class="wrap wpf-page"><h1 class="wpf-page__title">' . esc_html( $title ) . '</h1>';
		if ( $lead ) {
			echo '<p class="wpf-page__lead">' . esc_html( $lead ) . '</p>';
		}
		echo '<hr class="wp-header-end">';
	}

	public static function close(): void { echo '</div>'; }

	public static function card_open( string $title, string $class = '' ): void {
		echo '<section class="wpf-card ' . esc_attr( $class ) . '"><h2 class="wpf-card__title">' . esc_html( $title ) . '</h2>';
	}

	public static function card_close(): void { echo '</section>'; }

	public static function notice( string $type, string $message ): void {
		$type = in_array( $type, array( 'success', 'info', 'warning', 'error' ), true ) ? $type : 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' wpf-notice"><p>' . esc_html( $message ) . '</p></div>';
	}

	/** @return string Escaped HTML. */
	public static function badge( string $label, string $tone = 'muted' ): string {
		return '<span class="wpf-badge wpf-badge--' . esc_attr( $tone ) . '">' . esc_html( $label ) . '</span>';
	}

	public static function kpi( string $value, string $label ): void {
		echo '<div class="wpf-kpi"><strong>' . esc_html( $value ) . '</strong><span>' . esc_html( $label ) . '</span></div>';
	}

	public static function toggle( string $name, string $label, bool $checked, string $help = '' ): void {
		$id = 'wpf-' . sanitize_html_class( $name );
		echo '<div class="wpf-field wpf-field--toggle"><label class="wpf-switch" for="' . esc_attr( $id ) . '"><input type="checkbox" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="1" ' . checked( $checked, true, false ) . '><span class="wpf-switch__label">' . esc_html( $label ) . '</span></label>';
		if ( $help ) {
			echo '<p class="wpf-field__help">' . esc_html( $help ) . '</p>';
		}
		echo '</div>';
	}

	/** @param array<string,mixed> $attrs */
	public static function input( string $name, string $label, string $value, string $help = '', array $attrs = array() ): void {
		$id    = 'wpf-' . sanitize_html_class( str_replace( array( '[', ']' ), '-', $name ) );
		$extra = '';
		foreach ( $attrs as $k => $v ) {
			$extra .= ' ' . esc_attr( (string) $k ) . '="' . esc_attr( (string) $v ) . '"';
		}
		echo '<div class="wpf-field"><label class="wpf-field__label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><input class="regular-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"' . $extra . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
		if ( $help ) {
			echo '<p class="wpf-field__help" id="' . esc_attr( $id ) . '-help">' . esc_html( $help ) . '</p>';
		}
		echo '</div>';
	}

	/** @param array<string,string> $options */
	public static function select( string $name, string $label, string $value, array $options, string $help = '' ): void {
		$id = 'wpf-' . sanitize_html_class( str_replace( array( '[', ']' ), '-', $name ) );
		echo '<div class="wpf-field"><label class="wpf-field__label" for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label><select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '" ' . selected( $value, $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select>';
		if ( $help ) {
			echo '<p class="wpf-field__help">' . esc_html( $help ) . '</p>';
		}
		echo '</div>';
	}

	public static function time_ago( int $timestamp ): string {
		if ( $timestamp <= 0 ) {
			return __( 'aldrig', 'finet-troubleshoot' );
		}
		/* translators: %s: human time difference */
		return sprintf( __( '%s sedan', 'finet-troubleshoot' ), human_time_diff( $timestamp ) ) . ' (' . wp_date( 'Y-m-d H:i', $timestamp ) . ')';
	}
}
