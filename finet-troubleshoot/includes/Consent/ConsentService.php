<?php
/**
 * Internal consent interface used by every WPFINET feature (replaces the hard WPConsent dependency of 2.0).
 *
 * Resolution order for a category:
 *  1. "necessary" is always allowed.
 *  2. WPFINET's own consent manager, when it is active for this request (mode "live", or "preview" for an admin).
 *  3. WP Consent API, but only when a consent management platform has actually set a consent type (or when the
 *     legacy filter wpfinet_consent_provider_available says so). WP Consent API without a CMP returns "allowed" for
 *     everything, which must never be treated as consent.
 *  4. Otherwise: no consent.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Consent;

use FinetTroubleshoot\Core\Modules;

final class ConsentService {
	public const COOKIE     = 'wpfinet_consent';
	public const CATEGORIES = array( 'necessary', 'preferences', 'statistics', 'marketing' );
	/** WPFINET category => WP Consent API category. */
	public const WP_CONSENT_MAP = array( 'preferences' => 'preferences', 'statistics' => 'statistics', 'marketing' => 'marketing' );

	private static ?ConsentService $instance = null;

	public static function instance(): ConsentService {
		return self::$instance ??= new self();
	}

	/** True when WPFINET's own banner/blocker governs this request. */
	public function internal_active(): bool {
		$mode = Modules::consent_mode();
		if ( 'live' === $mode ) {
			return true;
		}
		if ( 'preview' === $mode && function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			return current_user_can( 'manage_wpfinet_settings' ) || current_user_can( 'manage_options' );
		}
		return false;
	}

	public function wp_consent_api_ready(): bool {
		if ( ! function_exists( 'wp_has_consent' ) ) {
			return false;
		}
		$legacy_cmp = defined( 'WPCONSENT_VERSION' ) || defined( 'WPCONSENT_PLUGIN_FILE' ) || class_exists( 'WPConsent' );
		$type_set   = function_exists( 'wp_get_consent_type' ) && (bool) wp_get_consent_type();
		return $legacy_cmp || $type_set;
	}

	/** Whether any consent source exists. Keeps the 2.0 filter `wpfinet_consent_provider_available`. */
	public function provider_available(): bool {
		$available = $this->internal_active() || $this->wp_consent_api_ready();
		return (bool) apply_filters( 'wpfinet_consent_provider_available', $available );
	}

	/** @return 'wpfinet'|'wp-consent-api'|'none' */
	public function provider(): string {
		if ( $this->internal_active() ) {
			return 'wpfinet';
		}
		return $this->provider_available() && function_exists( 'wp_has_consent' ) ? 'wp-consent-api' : 'none';
	}

	public function has( string $category, string $service = '' ): bool {
		if ( 'necessary' === $category ) {
			return true;
		}
		if ( ! in_array( $category, self::CATEGORIES, true ) ) {
			return false;
		}
		if ( $this->internal_active() ) {
			$state = $this->read_cookie();
			return ! empty( $state['c'][ $category ] );
		}
		if ( ! $this->provider_available() ) {
			return false;
		}
		if ( $service && function_exists( 'wp_has_service_consent' ) ) {
			return (bool) wp_has_service_consent( $service );
		}
		return function_exists( 'wp_has_consent' ) && (bool) wp_has_consent( self::WP_CONSENT_MAP[ $category ] ?? $category );
	}

	/**
	 * Parses and validates the WPFINET consent cookie.
	 *
	 * @return array{v:int,id:string,t:int,p:string,c:array<string,bool>}|null
	 */
	public function read_cookie(): ?array {
		$raw = isset( $_COOKIE[ self::COOKIE ] ) ? wp_unslash( (string) $_COOKIE[ self::COOKIE ] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return self::parse( $raw );
	}

	/** @return array{v:int,id:string,t:int,p:string,c:array<string,bool>}|null */
	public static function parse( string $raw ): ?array {
		if ( '' === $raw || strlen( $raw ) > 1024 ) {
			return null;
		}
		$data = json_decode( rawurldecode( $raw ), true );
		if ( ! is_array( $data ) || 1 !== (int) ( $data['v'] ?? 0 ) || ! is_array( $data['c'] ?? null ) ) {
			return null;
		}
		$categories = array();
		foreach ( array( 'preferences', 'statistics', 'marketing' ) as $category ) {
			$categories[ $category ] = ! empty( $data['c'][ $category ] );
		}
		$id = (string) ( $data['id'] ?? '' );
		return array(
			'v'  => 1,
			'id' => preg_match( '/^[a-f0-9-]{36}$/i', $id ) ? strtolower( $id ) : '',
			't'  => absint( $data['t'] ?? 0 ),
			'p'  => sanitize_text_field( (string) ( $data['p'] ?? '' ) ),
			'c'  => $categories,
		);
	}
}
