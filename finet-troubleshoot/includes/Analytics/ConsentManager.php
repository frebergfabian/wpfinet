<?php
/**
 * The sole integration point for the WP Consent API/WPConsent.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Analytics;

final class ConsentManager {
	public const SERVICE = 'wpfinet-analytics';
	public function register_hooks(): void {
		add_filter( 'wp_consent_api_registered_' . plugin_basename( FINET_TS_FILE ), '__return_true' );
	}
	public function provider_available(): bool {
		$available = function_exists( 'wp_has_consent' ) && ( defined( 'WPCONSENT_VERSION' ) || defined( 'WPCONSENT_PLUGIN_FILE' ) || class_exists( 'WPConsent' ) );
		return (bool) apply_filters( 'wpfinet_consent_provider_available', $available );
	}
	public function has_analytics_consent(): bool {
		if ( ! $this->provider_available() ) { return false; }
		if ( function_exists( 'wp_has_service_consent' ) ) { return (bool) wp_has_service_consent( self::SERVICE ); }
		return function_exists( 'wp_has_consent' ) && (bool) wp_has_consent( 'statistics' );
	}
	/** @return array<string,mixed> */
	public function public_config(): array {
		return array( 'enabled' => $this->provider_available(), 'service' => self::SERVICE, 'category' => 'statistics' );
	}
}
