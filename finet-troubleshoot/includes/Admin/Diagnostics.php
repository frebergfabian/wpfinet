<?php
/**
 * System/integration status for the Diagnos page and the downloadable diagnosis report (no personal data).
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Admin;

use FinetTroubleshoot\Consent\ConsentService;
use FinetTroubleshoot\Core\Logger;
use FinetTroubleshoot\Core\MigrationManager;
use FinetTroubleshoot\Core\Modules;
use FinetTroubleshoot\Migration\LegacyScanner;

final class Diagnostics {
	/** @return array<string,string> */
	public static function system(): array {
		global $wp_version;
		$cron = array();
		foreach ( array( 'wpfinet_daily_maintenance', 'wpfinet_access_sync', 'wpfinet_instagram_sync' ) as $hook ) {
			$next = wp_next_scheduled( $hook );
			$cron[] = $hook . ': ' . ( $next ? wp_date( 'Y-m-d H:i', $next ) : '–' );
		}
		return array(
			'WPFINET'          => FINET_TS_VERSION . ' / DB ' . (string) get_option( 'finet_ts_db_version', '–' ) . ( MigrationManager::needs_migration() ? ' (migrering väntar)' : '' ),
			'WordPress'        => (string) $wp_version,
			'PHP'              => PHP_VERSION . ' (' . PHP_OS_FAMILY . ', ' . ( PHP_ZTS ? 'TS' : 'NTS' ) . ')',
			'Webbserver'       => sanitize_text_field( (string) ( $_SERVER['SERVER_SOFTWARE'] ?? 'okänd' ) ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			'PHP-tillägg'      => implode( ', ', array_map( static fn ( $e ) => $e . ( extension_loaded( $e ) ? ' ✓' : ' ✗' ), array( 'sodium', 'intl', 'mbstring', 'curl', 'openssl', 'json' ) ) ),
			'OPcache'          => function_exists( 'opcache_get_status' ) && is_array( @opcache_get_status( false ) ) ? 'aktiv' : 'okänd/inaktiv', // phpcs:ignore WordPress.PHP.NoSilencedErrors
			'Objektcache'      => wp_using_ext_object_cache() ? 'beständig' : 'ingen (transients i databasen)',
			'Permalänkar'      => (string) get_option( 'permalink_structure' ) ?: 'enkla (?p=)',
			'WP-Cron'          => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'DISABLE_WP_CRON – kräver extern schemaläggare (Windows Task Scheduler)' : 'aktiv (startas av besök)',
			'Schemalagda jobb' => implode( '; ', $cron ),
			'Moduler'          => wp_json_encode( Modules::all() ),
			'Samtycke'         => 'källa: ' . ConsentService::instance()->provider(),
		);
	}

	/** @return array<int,array{name:string,ok:bool,note:string}> */
	public static function integrations(): array {
		$active = (array) get_option( 'active_plugins', array() );
		$has    = static fn ( string $needle ): bool => (bool) array_filter( $active, static fn ( $p ) => str_contains( strtolower( (string) $p ), $needle ) );
		return array(
			array( 'name' => 'WPForms', 'ok' => post_type_exists( 'wpforms' ), 'note' => __( 'Används av formulärnoder och CTA:er.', 'finet-troubleshoot' ) ),
			array( 'name' => 'WP Consent API', 'ok' => function_exists( 'wp_has_consent' ), 'note' => __( 'WPFINET läser och skriver samtycke via API:t när det finns.', 'finet-troubleshoot' ) ),
			array( 'name' => 'WPConsent', 'ok' => defined( 'WPCONSENT_VERSION' ) || defined( 'WPCONSENT_PLUGIN_FILE' ) || class_exists( 'WPConsent' ), 'note' => __( 'Behålls aktivt tills WPFINET:s samtyckeshanterare verifierats.', 'finet-troubleshoot' ) ),
			array( 'name' => 'WPGetAPI', 'ok' => $has( 'wpgetapi' ), 'note' => __( 'Stäng inte av förrän alla gamla adressökningar ersatts (se Diagnos).', 'finet-troubleshoot' ) ),
			array( 'name' => 'Instagram (tredjepart)', 'ok' => $has( 'instagram' ), 'note' => __( 'Ersätts av [wpfinet_instagram_feed] efter kartläggning.', 'finet-troubleshoot' ) ),
			array( 'name' => 'Elementor', 'ok' => defined( 'ELEMENTOR_VERSION' ), 'note' => __( 'Shortcodes fungerar i Elementors Shortcode-widget.', 'finet-troubleshoot' ) ),
		);
	}

	/** @return array<string,mixed> */
	public static function report(): array {
		$inventory = LegacyScanner::last_report();
		if ( $inventory ) {
			// Keep the report free of page content: drop snippets, keep counts and IDs.
			foreach ( $inventory['findings'] as $key => $locations ) {
				$inventory['findings'][ $key ] = array_map( static fn ( $l ) => array( 'post_id' => $l['post_id'], 'post_type' => $l['post_type'], 'status' => $l['status'], 'source' => $l['source'], 'count' => $l['count'] ), $locations );
			}
		}
		return array( 'generated_at' => gmdate( 'c' ), 'system' => self::system(), 'integrations' => self::integrations(), 'counts' => MigrationManager::counts(), 'inventory' => $inventory, 'log' => array_slice( Logger::entries(), 0, 100 ) );
	}
}
