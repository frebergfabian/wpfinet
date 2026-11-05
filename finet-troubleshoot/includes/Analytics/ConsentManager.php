<?php
/**
 * Backwards-compatible facade for the 2.0 consent integration. All decisions are delegated to the internal
 * ConsentService, which supports WPFINET's own consent manager and WP Consent API without requiring WPConsent.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Analytics;

use FinetTroubleshoot\Consent\ConsentService;

final class ConsentManager {
	public const SERVICE = 'wpfinet-analytics';

	public function register_hooks(): void {
		add_filter( 'wp_consent_api_registered_' . plugin_basename( FINET_TS_FILE ), '__return_true' );
	}

	public function provider_available(): bool {
		return ConsentService::instance()->provider_available();
	}

	public function has_analytics_consent(): bool {
		return ConsentService::instance()->has( 'statistics', self::SERVICE );
	}

	/** @return array<string,mixed> */
	public function public_config(): array {
		$service = ConsentService::instance();
		return array( 'enabled' => $service->provider_available(), 'service' => self::SERVICE, 'category' => 'statistics', 'provider' => $service->provider() );
	}
}
