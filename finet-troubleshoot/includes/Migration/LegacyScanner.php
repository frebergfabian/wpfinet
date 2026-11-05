<?php
/**
 * Read-only inventory of the live site: where WPFINET shortcodes and legacy integrations are used (post content,
 * Elementor data, Elementor custom code, widgets, theme mods), broken guide references and data volumes.
 * It never writes content; it only stores its last report in the non-autoloaded option `wpfinet_migration_report`.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Migration;

use FinetTroubleshoot\Core\MigrationManager;
use FinetTroubleshoot\PostTypes\GuidePostType;

final class LegacyScanner {
	public const OPTION = 'wpfinet_migration_report';

	/** @return array<string,array{label:string,regex:string,like:string,status:string}> */
	public static function patterns(): array {
		return array(
			'finet_troubleshoot'    => array( 'label' => 'Felsökningsguide [finet_troubleshoot]', 'regex' => '/\[finet_troubleshoot\b[^\]]*\]/i', 'like' => '[finet_troubleshoot', 'status' => 'bevaras' ),
			'wpfinet_outages'       => array( 'label' => 'Driftlista [wpfinet_outages]', 'regex' => '/\[wpfinet_outages\b[^\]]*\]/i', 'like' => '[wpfinet_outages', 'status' => 'bevaras' ),
			'wpfinet_outage_status' => array( 'label' => 'Driftstatus [wpfinet_outage_status]', 'regex' => '/\[wpfinet_outage_status\b[^\]]*\]/i', 'like' => '[wpfinet_outage_status', 'status' => 'bevaras' ),
			'wpgetapi_endpoint'     => array( 'label' => 'WPGetAPI shortcode/template tag', 'regex' => '/\[wpgetapi_endpoint\b[^\]]*\]|wpgetapi_endpoint\s*\([^)]*\)/i', 'like' => 'wpgetapi_endpoint', 'status' => 'ersätts' ),
			'legacy_address_js'     => array( 'label' => 'Gammalt adresskript (checkAddress/addressInput/finetApiRaw)', 'regex' => '/checkAddress\s*\(|id\s*=\s*["\']addressInput["\']|finetApiRaw/i', 'like' => 'checkAddress', 'status' => 'ersätts' ),
			'legacy_address_raw'    => array( 'label' => 'Gammal rå-JSON-behållare (#finetApiRaw)', 'regex' => '/finetApiRaw/i', 'like' => 'finetApiRaw', 'status' => 'ersätts' ),
			'instagram_thirdparty'  => array( 'label' => 'Instagram-shortcode från tredjepart', 'regex' => '/\[(instagram-feed|instagram_feed|sbi[-_][a-z-]*|insta-gallery|jr_instagram|wpzoom-instagram[^\s\]]*|spotlight-instagram)\b[^\]]*\]/i', 'like' => 'instagram', 'status' => 'ersätts' ),
			'wpconsent'             => array( 'label' => 'WPConsent-shortcode/attribut', 'regex' => '/\[wpconsent[^\]]*\]|data-wpconsent[a-z-]*/i', 'like' => 'wpconsent', 'status' => 'ersätts' ),
			'third_party_script'    => array( 'label' => 'Tredjepartsskript i innehåll (spårning)', 'regex' => '#<script[^>]+src=["\'][^"\']*(googletagmanager\.com|google-analytics\.com|connect\.facebook\.net|hotjar\.com|clarity\.ms|snap\.licdn\.com|analytics\.tiktok\.com)[^"\']*["\']#i', 'like' => '<script', 'status' => 'granska (samtycke)' ),
			'third_party_iframe'    => array( 'label' => 'Tredjeparts-iframe i innehåll', 'regex' => '#<iframe[^>]+src=["\'][^"\']*(youtube\.com|youtube-nocookie\.com|vimeo\.com|google\.com/maps|instagram\.com|facebook\.com)[^"\']*["\']#i', 'like' => '<iframe', 'status' => 'granska (samtycke)' ),
			'wpfinet_access_check'  => array( 'label' => 'Ny adressökning [wpfinet_access_check]', 'regex' => '/\[wpfinet_access_check\b[^\]]*\]/i', 'like' => '[wpfinet_access_check', 'status' => 'ny' ),
			'wpfinet_instagram_feed' => array( 'label' => 'Nytt Instagramflöde [wpfinet_instagram_feed]', 'regex' => '/\[wpfinet_instagram_feed\b[^\]]*\]/i', 'like' => '[wpfinet_instagram_feed', 'status' => 'ny' ),
			'wpfinet_cookie_policy' => array( 'label' => 'Ny cookiepolicy [wpfinet_cookie_policy]', 'regex' => '/\[wpfinet_cookie_(policy|settings)\b[^\]]*\]/i', 'like' => '[wpfinet_cookie_', 'status' => 'ny' ),
		);
	}

	/** @return array<string,mixed> */
	public function scan( bool $store = true ): array {
		global $wpdb;
		$patterns = self::patterns();
		$findings = array_fill_keys( array_keys( $patterns ), array() );
		$likes    = array_values( array_unique( array_map( static fn ( $p ) => $p['like'], $patterns ) ) );

		// 1) Post content (all post types incl. Elementor templates and drafts; not revisions/auto-drafts).
		$where = implode( ' OR ', array_fill( 0, count( $likes ), 'post_content LIKE %s' ) );
		$args  = array_map( static fn ( $l ) => '%' . $wpdb->esc_like( $l ) . '%', $likes );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_type, post_status, post_title, post_content FROM {$wpdb->posts} WHERE post_type NOT IN ('revision','nav_menu_item') AND post_status NOT IN ('auto-draft','inherit') AND ({$where})", $args ) ); // phpcs:ignore WordPress.DB
		foreach ( $rows as $row ) {
			$this->match( $findings, $patterns, (string) $row->post_content, array( 'source' => 'post_content', 'post_id' => (int) $row->ID, 'post_type' => $row->post_type, 'status' => $row->post_status, 'title' => $row->post_title ) );
		}

		// 2) Elementor data and Elementor Pro custom code.
		$meta_where = implode( ' OR ', array_fill( 0, count( $likes ), 'pm.meta_value LIKE %s' ) );
		$meta_rows  = $wpdb->get_results( $wpdb->prepare( "SELECT pm.post_id, pm.meta_key, pm.meta_value, p.post_type, p.post_status, p.post_title FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key IN ('_elementor_data','_elementor_code','_elementor_page_settings') AND p.post_type <> 'revision' AND ({$meta_where})", $args ) ); // phpcs:ignore WordPress.DB
		foreach ( $meta_rows as $row ) {
			$text = str_replace( array( '\\"', '\\/', '\\n' ), array( '"', '/', "\n" ), (string) $row->meta_value );
			$this->match( $findings, $patterns, $text, array( 'source' => $row->meta_key, 'post_id' => (int) $row->post_id, 'post_type' => $row->post_type, 'status' => $row->post_status, 'title' => $row->post_title ) );
		}

		// 3) Widgets and theme mods.
		$options = $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'widget\\_%' OR option_name LIKE 'theme\\_mods\\_%'" ); // phpcs:ignore WordPress.DB
		foreach ( $options as $row ) {
			$this->match( $findings, $patterns, (string) $row->option_value, array( 'source' => 'option:' . $row->option_name, 'post_id' => 0, 'post_type' => '', 'status' => '', 'title' => $row->option_name ) );
		}

		$broken  = $this->broken_guide_references( $findings['finet_troubleshoot'] );
		$summary = array();
		foreach ( $patterns as $key => $pattern ) {
			$summary[ $key ] = array( 'label' => $pattern['label'], 'status' => $pattern['status'], 'occurrences' => array_sum( array_column( $findings[ $key ], 'count' ) ), 'locations' => count( $findings[ $key ] ) );
		}
		$settings = get_option( 'finet_ts_settings', array() );
		$report   = array(
			'generated_at'      => gmdate( 'c' ),
			'plugin_version'    => FINET_TS_VERSION,
			'db_version'        => (string) get_option( 'finet_ts_db_version', '' ),
			'counts'            => $this->counts(),
			'settings'          => array( 'delete_data_on_uninstall' => ! empty( $settings['advanced']['delete_data'] ), 'analytics_enabled' => ! empty( $settings['analytics']['enabled'] ), 'banner_enabled' => ! empty( $settings['outages']['banner_enabled'] ) ),
			'plugins'           => $this->plugins(),
			'summary'           => $summary,
			'findings'          => $findings,
			'broken_guide_refs' => $broken,
			'unresolved_legacy' => $summary['wpgetapi_endpoint']['locations'] + $summary['legacy_address_js']['locations'] + $summary['instagram_thirdparty']['locations'] + $summary['wpconsent']['locations'],
			'migration_log'     => array_slice( (array) get_option( 'finet_ts_migration_log', array() ), -20 ),
		);
		if ( $store ) {
			update_option( self::OPTION, $report, false );
		}
		return $report;
	}

	/** @return array<string,mixed>|null */
	public static function last_report(): ?array {
		$report = get_option( self::OPTION );
		return is_array( $report ) ? $report : null;
	}

	/**
	 * @param array<string,array<int,array<string,mixed>>> $findings
	 * @param array<string,array<string,string>>            $patterns
	 * @param array<string,mixed>                           $location
	 */
	private function match( array &$findings, array $patterns, string $text, array $location ): void {
		foreach ( $patterns as $key => $pattern ) {
			if ( ! preg_match_all( $pattern['regex'], $text, $matches ) ) {
				continue;
			}
			$snippets = array_values( array_unique( array_map( static fn ( $m ) => mb_substr( (string) $m, 0, 160 ), $matches[0] ) ) );
			$findings[ $key ][] = array_merge( $location, array( 'count' => count( $matches[0] ), 'snippets' => array_slice( $snippets, 0, 10 ), 'edit_url' => $location['post_id'] ? (string) get_edit_post_link( $location['post_id'], 'raw' ) : '' ) );
		}
	}

	/** @param array<int,array<string,mixed>> $locations @return array<int,array<string,mixed>> */
	private function broken_guide_references( array $locations ): array {
		$broken = array();
		foreach ( $locations as $location ) {
			foreach ( $location['snippets'] as $snippet ) {
				$atts  = shortcode_parse_atts( trim( (string) preg_replace( '/^\[finet_troubleshoot|\]$/i', '', $snippet ) ) );
				$atts  = is_array( $atts ) ? $atts : array();
				$guide = null;
				if ( ! empty( $atts['id'] ) ) {
					$guide = get_post( absint( $atts['id'] ) );
				} elseif ( ! empty( $atts['slug'] ) ) {
					$found = get_posts( array( 'post_type' => GuidePostType::POST_TYPE, 'name' => sanitize_title( (string) $atts['slug'] ), 'post_status' => 'any', 'posts_per_page' => 1 ) );
					$guide = $found[0] ?? null;
				}
				$problem = '';
				if ( ! $guide || GuidePostType::POST_TYPE !== $guide->post_type ) {
					$problem = 'Guiden finns inte';
				} elseif ( 'publish' !== $guide->post_status ) {
					$problem = 'Guiden är inte publicerad (' . $guide->post_status . ')';
				}
				if ( $problem ) {
					$broken[] = array( 'shortcode' => $snippet, 'problem' => $problem, 'post_id' => $location['post_id'], 'title' => $location['title'], 'source' => $location['source'] );
				}
			}
		}
		return $broken;
	}

	/** @return array<string,mixed> */
	private function counts(): array {
		$by_status = static function ( string $type ): array {
			$counts = (array) wp_count_posts( $type );
			unset( $counts['auto-draft'] );
			return array_filter( array_map( 'intval', $counts ) );
		};
		$terms = array();
		foreach ( array( 'wpfinet_provider', 'wpfinet_service', 'wpfinet_area' ) as $taxonomy ) {
			$terms[ $taxonomy ] = (int) wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
		}
		return array( 'guides' => $by_status( GuidePostType::POST_TYPE ), 'outages' => $by_status( 'wpfinet_outage' ), 'terms' => $terms, 'tables' => MigrationManager::counts() );
	}

	/** @return array<string,bool|string> */
	private function plugins(): array {
		$active = array_merge( (array) get_option( 'active_plugins', array() ), array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		$has    = static fn ( string $needle ): bool => (bool) array_filter( $active, static fn ( $p ) => str_contains( strtolower( (string) $p ), $needle ) );
		return array(
			'wpconsent'      => $has( 'wpconsent' ),
			'wp_consent_api' => $has( 'wp-consent-api' ) || function_exists( 'wp_has_consent' ),
			'wpgetapi'       => $has( 'wpgetapi' ),
			'instagram'      => implode( ', ', array_values( array_filter( $active, static fn ( $p ) => str_contains( strtolower( (string) $p ), 'instagram' ) ) ) ),
			'elementor'      => $has( 'elementor/' ),
			'elementor_pro'  => $has( 'elementor-pro' ),
			'wpforms'        => $has( 'wpforms' ),
		);
	}
}
