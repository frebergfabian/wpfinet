<?php
/**
 * Cookie/storage/service inventory ({prefix}wpfinet_cookie_inventory) and anonymous consent log
 * ({prefix}wpfinet_consent_log). Scanner findings become "suggested" entries; only a human can mark them verified.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Consent;

final class Inventory {
	public const STATUSES = array( 'suggested', 'verified', 'ignored' );
	public const TYPES    = array( 'cookie', 'localStorage', 'sessionStorage', 'service' );

	/** Classification suggestions for well-known names (pattern => [category, provider, purpose, lifetime]). Never auto-verified. */
	public const KNOWN = array(
		'wpfinet_consent'             => array( 'necessary', 'Webbplatsen (WPFINET)', 'Sparar ditt cookieval.', 'Enligt inställning (standard 180 dagar)' ),
		'wpfinet_analytics_session'   => array( 'statistics', 'Webbplatsen (WPFINET)', 'Anonymt sessions-ID för guidestatistik, endast efter samtycke.', 'Webbläsarsessionen' ),
		'wpfinet_banner_dismissed_*'  => array( 'necessary', 'Webbplatsen (WPFINET)', 'Kommer ihåg att du stängt driftbannern under sessionen.', 'Webbläsarsessionen' ),
		'wordpress_logged_in_*'       => array( 'necessary', 'WordPress', 'Inloggning för redaktörer.', 'Session/14 dagar' ),
		'wordpress_sec_*'             => array( 'necessary', 'WordPress', 'Säker inloggning för redaktörer.', 'Session/14 dagar' ),
		'wordpress_test_cookie'       => array( 'necessary', 'WordPress', 'Kontrollerar att cookies är tillåtna vid inloggning.', 'Session' ),
		'wp-settings-*'               => array( 'preferences', 'WordPress', 'Redaktörens adminpreferenser.', '1 år' ),
		'wp_consent_*'                => array( 'necessary', 'WP Consent API', 'Sparar samtycke per kategori för andra tillägg.', 'Enligt CMP' ),
		'__cf_bm'                     => array( 'necessary', 'Cloudflare', 'Botskydd.', '30 minuter' ),
		'cf_clearance'                => array( 'necessary', 'Cloudflare', 'Säkerhetskontroll.', 'Upp till 1 år' ),
		'_cfuvid'                     => array( 'necessary', 'Cloudflare', 'Rate limiting/säkerhet.', 'Session' ),
		'_ga'                         => array( 'statistics', 'Google Analytics', 'Skiljer användare åt.', '2 år' ),
		'_ga_*'                       => array( 'statistics', 'Google Analytics', 'Sessionsstatus.', '2 år' ),
		'_gid'                        => array( 'statistics', 'Google Analytics', 'Skiljer användare åt.', '24 timmar' ),
		'_gat*'                       => array( 'statistics', 'Google Analytics', 'Begränsar anropsfrekvens.', '1 minut' ),
		'_hj*'                        => array( 'statistics', 'Hotjar', 'Besöksanalys.', 'Upp till 1 år' ),
		'_clck'                       => array( 'statistics', 'Microsoft Clarity', 'Besöksanalys.', '1 år' ),
		'_clsk'                       => array( 'statistics', 'Microsoft Clarity', 'Besöksanalys.', '1 dag' ),
		'_fbp'                        => array( 'marketing', 'Meta', 'Annonsmätning.', '3 månader' ),
		'_fbc'                        => array( 'marketing', 'Meta', 'Annonsmätning.', '3 månader' ),
		'wpEmojiSettingsSupports'     => array( 'necessary', 'WordPress', 'Cachar webbläsarens emoji-stöd (ingen spårning). Verifiera eller stäng av emoji-skriptet.', 'Webbläsarsessionen' ),
		'wpforms-*'                   => array( 'necessary', 'WPForms', 'Formulärfunktion (verifiera).', 'Varierar' ),
	);

	public static function table(): string { global $wpdb; return $wpdb->prefix . 'wpfinet_cookie_inventory'; }
	public static function log_table(): string { global $wpdb; return $wpdb->prefix . 'wpfinet_consent_log'; }

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(150) NOT NULL DEFAULT '',
			domain varchar(150) NOT NULL DEFAULT '',
			storage_type varchar(20) NOT NULL DEFAULT 'cookie',
			party varchar(10) NOT NULL DEFAULT 'first',
			category varchar(20) NOT NULL DEFAULT '',
			suggested_category varchar(20) NOT NULL DEFAULT '',
			provider varchar(150) NOT NULL DEFAULT '',
			purpose text NULL,
			lifetime varchar(100) NOT NULL DEFAULT '',
			source varchar(20) NOT NULL DEFAULT 'manual',
			status varchar(20) NOT NULL DEFAULT 'suggested',
			before_consent tinyint(1) NOT NULL DEFAULT 0,
			first_seen datetime NULL,
			last_seen datetime NULL,
			pages text NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY item (name,domain,storage_type),
			KEY status (status)
		) {$c};" );
		dbDelta( 'CREATE TABLE ' . self::log_table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			consent_id char(36) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			action varchar(20) NOT NULL DEFAULT '',
			categories varchar(100) NOT NULL DEFAULT '',
			policy_version varchar(20) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY consent_id (consent_id)
		) {$c};" );
	}

	/** @return array{0:string,1:string,2:string,3:string}|null */
	public static function suggestion( string $name ): ?array {
		foreach ( self::KNOWN as $pattern => $info ) {
			if ( fnmatch( $pattern, $name ) ) { return $info; }
		}
		return null;
	}

	/**
	 * Upserts an observed item. Human decisions (category/status/purpose) are never overwritten by a scan.
	 *
	 * @param array<string,mixed> $item name, domain, storage_type, party, lifetime, before_consent, page, source
	 * @return 'new'|'seen'|'invalid'
	 */
	public static function observe( array $item ): string {
		global $wpdb;
		self::install_once();
		$name   = mb_substr( sanitize_text_field( (string) ( $item['name'] ?? '' ) ), 0, 150 );
		$type   = \FinetTroubleshoot\Import\SchemaValidator::pick( $item['storage_type'] ?? null, self::TYPES, 'cookie' );
		$domain = mb_substr( strtolower( (string) preg_replace( '/[^A-Za-z0-9.\-]/', '', (string) ( $item['domain'] ?? '' ) ) ), 0, 150 );
		if ( '' === $name ) { return 'invalid'; }
		$now      = current_time( 'mysql', true );
		$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id, pages, before_consent FROM ' . self::table() . ' WHERE name = %s AND domain = %s AND storage_type = %s', $name, $domain, $type ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$page     = esc_url_raw( (string) ( $item['page'] ?? '' ) );
		if ( $existing ) {
			$pages = array_filter( explode( "\n", (string) $existing['pages'] ) );
			if ( $page && ! in_array( $page, $pages, true ) && count( $pages ) < 20 ) { $pages[] = $page; }
			$wpdb->update( self::table(), array( 'last_seen' => $now, 'pages' => implode( "\n", $pages ), 'before_consent' => (int) $existing['before_consent'] || ! empty( $item['before_consent'] ) ? 1 : 0 ), array( 'id' => (int) $existing['id'] ) ); // phpcs:ignore WordPress.DB
			return 'seen';
		}
		$s = self::suggestion( $name );
		$wpdb->insert( self::table(), array( // phpcs:ignore WordPress.DB
			'name' => $name, 'domain' => $domain, 'storage_type' => $type,
			'party' => \FinetTroubleshoot\Import\SchemaValidator::pick( $item['party'] ?? null, array( 'first', 'third' ), 'first' ),
			'category' => '', 'suggested_category' => $s[0] ?? '', 'provider' => $s[1] ?? '', 'purpose' => $s[2] ?? '',
			'lifetime' => mb_substr( sanitize_text_field( (string) ( $item['lifetime'] ?? ( $s[3] ?? '' ) ) ), 0, 100 ),
			'source' => \FinetTroubleshoot\Import\SchemaValidator::pick( $item['source'] ?? null, array( 'scan', 'manual', 'default' ), 'scan' ),
			'status' => 'suggested', 'before_consent' => empty( $item['before_consent'] ) ? 0 : 1, 'first_seen' => $now, 'last_seen' => $now, 'pages' => $page,
		) );
		return 'new';
	}

	private static function install_once(): void {
		static $done = false;
		if ( ! $done ) { self::install(); $done = true; }
	}

	/** @param array<string,mixed> $data */
	public static function update_item( int $id, array $data ): void {
		global $wpdb;
		$row = array();
		if ( isset( $data['category'] ) && in_array( $data['category'], array_merge( array( '' ), ConsentService::CATEGORIES ), true ) ) { $row['category'] = (string) $data['category']; }
		if ( isset( $data['status'] ) && in_array( $data['status'], self::STATUSES, true ) ) { $row['status'] = (string) $data['status']; }
		foreach ( array( 'provider' => 150, 'lifetime' => 100 ) as $k => $len ) { if ( isset( $data[ $k ] ) ) { $row[ $k ] = mb_substr( sanitize_text_field( (string) $data[ $k ] ), 0, $len ); } }
		if ( isset( $data['purpose'] ) ) { $row['purpose'] = sanitize_textarea_field( (string) $data['purpose'] ); }
		if ( isset( $row['status'] ) && 'verified' === $row['status'] ) {
			$current = self::get( $id );
			if ( '' === ( $row['category'] ?? (string) ( $current['category'] ?? '' ) ) ) { unset( $row['status'] ); } // Cannot verify without a category.
		}
		if ( $row ) { $wpdb->update( self::table(), $row, array( 'id' => $id ) ); } // phpcs:ignore WordPress.DB
	}

	/** @return array<string,mixed>|null */
	public static function get( int $id ): ?array {
		global $wpdb;
		self::install_once();
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ?: null;
	}

	/** @return array<int,array<string,mixed>> */
	public static function all( string $status = '' ): array {
		global $wpdb;
		self::install_once();
		$sql = 'SELECT * FROM ' . self::table() . ( $status ? $wpdb->prepare( ' WHERE status = %s', $status ) : '' ) . ' ORDER BY status = "verified", category, name LIMIT 2000';
		return (array) $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB
	}

	/** @return array<string,int> */
	public static function counts(): array {
		$c = array( 'total' => 0, 'suggested' => 0, 'verified' => 0, 'ignored' => 0, 'unclassified' => 0, 'leaks' => 0 );
		foreach ( self::all() as $r ) {
			++$c['total'];
			++$c[ $r['status'] ];
			if ( 'suggested' === $r['status'] && '' === $r['category'] ) { ++$c['unclassified']; }
			if ( $r['before_consent'] && 'necessary' !== ( $r['category'] ?: $r['suggested_category'] ) && 'ignored' !== $r['status'] ) { ++$c['leaks']; }
		}
		return $c;
	}

	public static function log_consent( string $consent_id, string $action, array $categories, string $policy_version ): bool {
		global $wpdb;
		self::install_once();
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $consent_id ) || ! in_array( $action, array( 'accept_all', 'reject', 'custom', 'revoke' ), true ) ) { return false; }
		$cats = implode( ',', array_values( array_intersect( array( 'preferences', 'statistics', 'marketing' ), $categories ) ) );
		return false !== $wpdb->insert( self::log_table(), array( 'consent_id' => $consent_id, 'created_at' => current_time( 'mysql', true ), 'action' => $action, 'categories' => $cats, 'policy_version' => mb_substr( sanitize_text_field( $policy_version ), 0, 20 ) ) ); // phpcs:ignore WordPress.DB
	}

	public static function cleanup_log( int $days ): void {
		global $wpdb;
		self::install_once();
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::log_table() . ' WHERE created_at < %s', gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB
	}

	/** @return array<string,int> Aggregated choices for the dashboard (no identifiers). */
	public static function log_summary( int $days = 30 ): array {
		global $wpdb;
		self::install_once();
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT action, COUNT(*) AS n FROM ' . self::log_table() . ' WHERE created_at >= %s GROUP BY action', gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map( 'intval', array_column( $rows, 'n', 'action' ) );
	}
}
