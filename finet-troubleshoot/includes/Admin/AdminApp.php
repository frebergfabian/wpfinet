<?php
/**
 * WPFINET 3.0 admin shell: shared navigation and design system on every WPFINET screen, plus the new pages
 * (Adressökning, Instagram, Cookies & samtycke, Moduler & integrationer, Diagnos). Existing 2.0 slugs are untouched.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Admin;

use FinetTroubleshoot\Core\Logger;
use FinetTroubleshoot\Core\MigrationManager;
use FinetTroubleshoot\Core\Modules;
use FinetTroubleshoot\Core\StrictCapabilities;
use FinetTroubleshoot\Migration\LegacyScanner;

final class AdminApp {
	/** Slugs of screens that get the WPFINET shell. */
	private const PAGES = array( 'wpfinet', 'wpfinet-statistics', 'finet-ts-import-export', 'finet-ts-import-format', 'finet-ts-settings', 'wpfinet-access', 'wpfinet-instagram', 'wpfinet-consent', 'wpfinet-integrations', 'wpfinet-diagnostics' );

	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'menu' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'in_admin_header', array( $this, 'shell_header' ) );
		add_action( 'admin_post_wpfinet_save_modules', array( $this, 'save_modules' ) );
		add_action( 'admin_post_wpfinet_run_migration', array( $this, 'run_migration' ) );
		add_action( 'admin_post_wpfinet_run_inventory', array( $this, 'run_inventory' ) );
		add_action( 'admin_post_wpfinet_download_diagnostics', array( $this, 'download_diagnostics' ) );
		add_action( 'admin_post_wpfinet_grant_editors', array( $this, 'grant_editors' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		\FinetTroubleshoot\Access\AccessAdmin::register_hooks();
		\FinetTroubleshoot\Instagram\InstagramAdmin::register_hooks();
	}

	public function menu(): void {
		\FinetTroubleshoot\Access\AccessAdmin::register_legacy_page();
		$pages = array(
			'wpfinet-access'       => array( __( 'Adressökning', 'finet-troubleshoot' ), 'manage_wpfinet_settings', array( \FinetTroubleshoot\Access\AccessAdmin::class, 'render' ) ),
			'wpfinet-instagram'    => array( __( 'Instagram', 'finet-troubleshoot' ), 'manage_wpfinet_settings', array( \FinetTroubleshoot\Instagram\InstagramAdmin::class, 'render' ) ),
			'wpfinet-consent'      => array( __( 'Cookies & samtycke', 'finet-troubleshoot' ), 'manage_wpfinet_settings', array( \FinetTroubleshoot\Consent\ConsentAdmin::class, 'render' ) ),
			'wpfinet-integrations' => array( __( 'Moduler & integrationer', 'finet-troubleshoot' ), 'manage_wpfinet_settings', array( $this, 'integrations_page' ) ),
			'wpfinet-diagnostics'  => array( __( 'Diagnos', 'finet-troubleshoot' ), 'manage_wpfinet_settings', array( $this, 'diagnostics_page' ) ),
		);
		foreach ( $pages as $slug => [ $title, $cap, $callback ] ) {
			add_submenu_page( 'wpfinet', $title, $title, $cap, $slug, static function () use ( $callback, $title ): void {
				if ( is_callable( $callback ) ) {
					call_user_func( $callback );
					return;
				}
				View::open( $title );
				View::notice( 'info', __( 'Den här modulen levereras i en senare fas.', 'finet-troubleshoot' ) );
				View::close();
			} );
		}
	}

	public static function is_wpfinet_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return in_array( $page, self::PAGES, true ) || in_array( $screen->post_type, array( 'finet_ts_guide', 'wpfinet_outage' ), true );
	}

	public function assets(): void {
		if ( ! self::is_wpfinet_screen() ) {
			return;
		}
		wp_enqueue_style( 'wpfinet-admin', FINET_TS_URL . 'assets/build/wpfinet-admin.css', array(), FINET_TS_VERSION );
	}

	public function shell_header(): void {
		if ( ! self::is_wpfinet_screen() ) {
			return;
		}
		$current = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$screen  = get_current_screen();
		$links   = array(
			'wpfinet'              => array( __( 'Översikt', 'finet-troubleshoot' ), 'admin.php?page=wpfinet', 'manage_wpfinet' ),
			'guides'               => array( __( 'Guider', 'finet-troubleshoot' ), 'edit.php?post_type=finet_ts_guide', 'manage_wpfinet_guides' ),
			'outages'              => array( __( 'Driftstörningar', 'finet-troubleshoot' ), 'edit.php?post_type=wpfinet_outage', 'manage_wpfinet_outages' ),
			'wpfinet-access'       => array( __( 'Adressökning', 'finet-troubleshoot' ), 'admin.php?page=wpfinet-access', 'manage_wpfinet_settings' ),
			'wpfinet-instagram'    => array( __( 'Instagram', 'finet-troubleshoot' ), 'admin.php?page=wpfinet-instagram', 'manage_wpfinet_settings' ),
			'wpfinet-consent'      => array( __( 'Cookies', 'finet-troubleshoot' ), 'admin.php?page=wpfinet-consent', 'manage_wpfinet_settings' ),
			'wpfinet-statistics'   => array( __( 'Statistik', 'finet-troubleshoot' ), 'admin.php?page=wpfinet-statistics', 'view_wpfinet_statistics' ),
			'finet-ts-import-export' => array( __( 'Import/Export', 'finet-troubleshoot' ), 'admin.php?page=finet-ts-import-export', 'manage_wpfinet_settings' ),
			'finet-ts-settings'    => array( __( 'Inställningar', 'finet-troubleshoot' ), 'admin.php?page=finet-ts-settings', 'manage_wpfinet_settings' ),
			'wpfinet-integrations' => array( __( 'Moduler', 'finet-troubleshoot' ), 'admin.php?page=wpfinet-integrations', 'manage_wpfinet_settings' ),
			'wpfinet-diagnostics'  => array( __( 'Diagnos', 'finet-troubleshoot' ), 'admin.php?page=wpfinet-diagnostics', 'manage_wpfinet_settings' ),
		);
		$active = $current;
		if ( $screen && 'finet_ts_guide' === $screen->post_type ) { $active = 'guides'; }
		if ( $screen && 'wpfinet_outage' === $screen->post_type ) { $active = 'outages'; }
		echo '<header class="wpf-shell" role="banner"><div class="wpf-shell__brand"><span class="wpf-shell__logo" aria-hidden="true">W</span><span>WPFINET</span><span class="wpf-shell__version">' . esc_html( FINET_TS_VERSION ) . '</span></div><nav class="wpf-shell__nav" aria-label="' . esc_attr__( 'WPFINET-navigering', 'finet-troubleshoot' ) . '"><ul>';
		foreach ( $links as $key => [ $label, $url, $cap ] ) {
			if ( ! current_user_can( $cap ) && ! current_user_can( 'manage_options' ) ) {
				continue;
			}
			$is = $key === $active;
			echo '<li><a href="' . esc_url( admin_url( $url ) ) . '"' . ( $is ? ' aria-current="page" class="is-active"' : '' ) . '>' . esc_html( $label ) . '</a></li>';
		}
		echo '</ul></nav></header>';
	}

	public function notices(): void {
		if ( ! self::is_wpfinet_screen() ) {
			return;
		}
		$notice = isset( $_GET['wpfinet_notice'] ) ? sanitize_key( wp_unslash( $_GET['wpfinet_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$messages = array(
			'modules_saved'     => array( 'success', __( 'Modulinställningarna är sparade.', 'finet-troubleshoot' ) ),
			'consent_blocked'   => array( 'error', __( 'Samtyckeshanteraren kan inte gå live förrän du bekräftar att den har verifierats och att WPConsent stängs av samtidigt (annars visas två banners).', 'finet-troubleshoot' ) ),
			'migrated'          => array( 'success', __( 'Migreringen kördes. Se loggen nedan.', 'finet-troubleshoot' ) ),
			'inventory_done'    => array( 'success', __( 'Migreringsrapporten är uppdaterad.', 'finet-troubleshoot' ) ),
			'editors_granted'   => array( 'success', __( 'Redaktörer har fått WPFINET-behörigheter för guider och driftstörningar.', 'finet-troubleshoot' ) ),
		);
		if ( isset( $messages[ $notice ] ) ) {
			View::notice( $messages[ $notice ][0], $messages[ $notice ][1] );
		}
		if ( MigrationManager::needs_migration() && current_user_can( 'manage_wpfinet_settings' ) ) {
			View::notice( 'warning', __( 'WPFINET-databasen behöver uppdateras. Migreringen körs automatiskt för administratörer och tar inte bort data.', 'finet-troubleshoot' ) );
		}
	}

	private static function guard( string $action ): void {
		check_admin_referer( $action );
		if ( ! current_user_can( 'manage_wpfinet_settings' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Du saknar behörighet.', 'finet-troubleshoot' ), 403 );
		}
	}

	private static function back( string $page, string $notice ): void {
		wp_safe_redirect( add_query_arg( 'wpfinet_notice', $notice, admin_url( 'admin.php?page=' . $page ) ) );
		exit;
	}

	public function save_modules(): void {
		self::guard( 'wpfinet_save_modules' );
		// phpcs:disable WordPress.Security.NonceVerification -- verified in guard().
		$consent = sanitize_key( wp_unslash( $_POST['consent'] ?? 'off' ) );
		$values  = array(
			'access'              => ! empty( $_POST['access'] ),
			'instagram'           => ! empty( $_POST['instagram'] ),
			'consent'             => $consent,
			'strict_capabilities' => ! empty( $_POST['strict_capabilities'] ),
		);
		if ( 'live' === $consent && 'live' !== Modules::consent_mode() ) {
			$confirmed = ! empty( $_POST['confirm_consent_live'] );
			$wpconsent = defined( 'WPCONSENT_VERSION' ) || defined( 'WPCONSENT_PLUGIN_FILE' ) || class_exists( 'WPConsent' );
			if ( ! $confirmed || ( $wpconsent && empty( $_POST['confirm_wpconsent_replaced'] ) ) ) {
				$values['consent'] = Modules::consent_mode();
				Modules::update( $values );
				self::back( 'wpfinet-integrations', 'consent_blocked' );
			}
		}
		// phpcs:enable
		Modules::update( $values );
		if ( empty( $values['access'] ) ) { wp_clear_scheduled_hook( 'wpfinet_access_sync' ); }
		if ( empty( $values['instagram'] ) ) { wp_clear_scheduled_hook( 'wpfinet_instagram_sync' ); wp_clear_scheduled_hook( 'wpfinet_instagram_refresh_token' ); }
		Logger::log( 'admin', 'info', 'Moduler sparade: ' . wp_json_encode( Modules::all() ), array( 'user' => get_current_user_id() ) );
		flush_rewrite_rules( false );
		self::back( 'wpfinet-integrations', 'modules_saved' );
	}

	public function grant_editors(): void {
		self::guard( 'wpfinet_grant_editors' );
		StrictCapabilities::grant_editors();
		Logger::log( 'admin', 'info', 'WPFINET-behörigheter gavs till rollen Redaktör.', array( 'user' => get_current_user_id() ) );
		self::back( 'wpfinet-integrations', 'editors_granted' );
	}

	public function run_migration(): void {
		self::guard( 'wpfinet_run_migration' );
		( new MigrationManager() )->run();
		self::back( 'wpfinet-diagnostics', 'migrated' );
	}

	public function run_inventory(): void {
		self::guard( 'wpfinet_run_inventory' );
		( new LegacyScanner() )->scan();
		self::back( 'wpfinet-diagnostics', 'inventory_done' );
	}

	public function download_diagnostics(): void {
		self::guard( 'wpfinet_download_diagnostics' );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wpfinet-diagnos-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( Diagnostics::report(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	public function integrations_page(): void {
		$modules   = Modules::all();
		$wpconsent = defined( 'WPCONSENT_VERSION' ) || defined( 'WPCONSENT_PLUGIN_FILE' ) || class_exists( 'WPConsent' );
		View::open( __( 'Moduler & integrationer', 'finet-troubleshoot' ), __( 'Slå på nya moduler en i taget på staging. Guider, driftstörningar och statistik påverkas inte av dessa val.', 'finet-troubleshoot' ) );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wpf-stack">';
		wp_nonce_field( 'wpfinet_save_modules' );
		echo '<input type="hidden" name="action" value="wpfinet_save_modules">';
		View::card_open( __( 'Moduler', 'finet-troubleshoot' ) );
		View::toggle( 'access', __( 'Adressökning (Finet Access API)', 'finet-troubleshoot' ), $modules['access'], __( 'Aktiverar [wpfinet_access_check], REST-rutten för en adressförfrågan och bakgrundssynk. Kräver konfiguration under Adressökning.', 'finet-troubleshoot' ) );
		View::toggle( 'instagram', __( 'Instagramflöde', 'finet-troubleshoot' ), $modules['instagram'], __( 'Aktiverar [wpfinet_instagram_feed] och schemalagd hämtning via Metas officiella API.', 'finet-troubleshoot' ) );
		echo '<fieldset class="wpf-field"><legend class="wpf-field__label">' . esc_html__( 'Cookies & samtycke (ersättare för WPConsent)', 'finet-troubleshoot' ) . '</legend>';
		foreach ( array( 'off' => __( 'Av – WPConsent eller annan CMP skyddar webbplatsen', 'finet-troubleshoot' ), 'preview' => __( 'Testläge – endast inloggade WPFINET-administratörer ser bannern och blockeringen', 'finet-troubleshoot' ), 'live' => __( 'Live – alla besökare (kräver verifiering och att WPConsent stängs av)', 'finet-troubleshoot' ) ) as $value => $label ) {
			echo '<label class="wpf-radio"><input type="radio" name="consent" value="' . esc_attr( $value ) . '" ' . checked( $modules['consent'], $value, false ) . '> ' . esc_html( $label ) . '</label>';
		}
		echo '<label class="wpf-check"><input type="checkbox" name="confirm_consent_live" value="1"> ' . esc_html__( 'Jag bekräftar att banner, blockering och cookiepolicy har verifierats i testläge (ren webbläsare, neka/acceptera/återkalla) och juridiskt granskats.', 'finet-troubleshoot' ) . '</label>';
		if ( $wpconsent ) {
			echo '<label class="wpf-check"><input type="checkbox" name="confirm_wpconsent_replaced" value="1"> ' . esc_html__( 'WPConsent är aktivt. Jag inaktiverar WPConsent direkt efter att WPFINET gått live (annars visas två banners).', 'finet-troubleshoot' ) . '</label>';
		}
		echo '</fieldset>';
		View::card_close();
		View::card_open( __( 'Behörigheter', 'finet-troubleshoot' ) );
		View::toggle( 'strict_capabilities', __( 'Strikta WPFINET-behörigheter', 'finet-troubleshoot' ), $modules['strict_capabilities'], __( 'Guider och driftstörningar styrs av manage_/publish_wpfinet_*-behörigheterna i stället för vanliga inläggsbehörigheter. Ge först redaktörer behörighet nedan, annars förlorar de åtkomst.', 'finet-troubleshoot' ) );
		View::card_close();
		submit_button( __( 'Spara moduler', 'finet-troubleshoot' ) );
		echo '</form>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wpfinet_grant_editors' );
		echo '<input type="hidden" name="action" value="wpfinet_grant_editors"><button class="button">' . esc_html__( 'Ge rollen Redaktör WPFINET-behörigheter', 'finet-troubleshoot' ) . '</button></form>';
		View::card_open( __( 'Integrationer', 'finet-troubleshoot' ) );
		$status  = Diagnostics::integrations();
		echo '<table class="wpf-table"><thead><tr><th>' . esc_html__( 'Integration', 'finet-troubleshoot' ) . '</th><th>' . esc_html__( 'Status', 'finet-troubleshoot' ) . '</th><th>' . esc_html__( 'Kommentar', 'finet-troubleshoot' ) . '</th></tr></thead><tbody>';
		foreach ( $status as $row ) {
			echo '<tr><td>' . esc_html( $row['name'] ) . '</td><td>' . View::badge( $row['ok'] ? __( 'Upptäckt', 'finet-troubleshoot' ) : __( 'Saknas', 'finet-troubleshoot' ), $row['ok'] ? 'ok' : 'muted' ) . '</td><td>' . esc_html( $row['note'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		echo '</tbody></table>';
		View::card_close();
		View::close();
	}

	public function diagnostics_page(): void {
		$report = LegacyScanner::last_report();
		View::open( __( 'Diagnos', 'finet-troubleshoot' ), __( 'Systemstatus, migrering och inventering av äldre referenser. Inget här ändrar webbplatsens innehåll.', 'finet-troubleshoot' ) );
		echo '<div class="wpf-grid">';
		View::card_open( __( 'Migrering', 'finet-troubleshoot' ) );
		echo '<p>' . esc_html( sprintf( __( 'Databasversion: %1$s (kod: %2$s)', 'finet-troubleshoot' ), (string) get_option( 'finet_ts_db_version', '–' ), MigrationManager::VERSION ) ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wpfinet_run_migration' );
		echo '<input type="hidden" name="action" value="wpfinet_run_migration"><button class="button">' . esc_html__( 'Kör migrering igen (idempotent)', 'finet-troubleshoot' ) . '</button></form>';
		$log = array_reverse( array_slice( (array) get_option( 'finet_ts_migration_log', array() ), -10 ) );
		if ( $log ) {
			echo '<ol class="wpf-log">';
			foreach ( $log as $entry ) { echo '<li><time>' . esc_html( (string) ( $entry['time'] ?? '' ) ) . '</time> ' . esc_html( (string) ( $entry['message'] ?? '' ) ) . '</li>'; }
			echo '</ol>';
		}
		View::card_close();
		View::card_open( __( 'System', 'finet-troubleshoot' ) );
		echo '<dl class="wpf-dl">';
		foreach ( Diagnostics::system() as $label => $value ) { echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( (string) $value ) . '</dd>'; }
		echo '</dl>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wpfinet_download_diagnostics' );
		echo '<input type="hidden" name="action" value="wpfinet_download_diagnostics"><button class="button">' . esc_html__( 'Ladda ner diagnosrapport (utan personuppgifter)', 'finet-troubleshoot' ) . '</button></form>';
		View::card_close();
		echo '</div>';

		View::card_open( __( 'Migreringsrapport – inventering av innehåll', 'finet-troubleshoot' ) );
		echo '<p>' . esc_html__( 'Söker igenom inlägg, sidor, Elementor-data, Elementor-kod, widgets och temainställningar efter WPFINET-shortcodes och äldre integrationer. Endast läsning.', 'finet-troubleshoot' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wpfinet_run_inventory' );
		echo '<input type="hidden" name="action" value="wpfinet_run_inventory"><button class="button button-primary">' . esc_html__( 'Kör inventering', 'finet-troubleshoot' ) . '</button></form>';
		if ( $report ) {
			echo '<p class="wpf-muted">' . esc_html( sprintf( __( 'Senast körd: %s', 'finet-troubleshoot' ), (string) $report['generated_at'] ) ) . '</p>';
			echo '<div class="wpf-kpis">';
			View::kpi( (string) array_sum( (array) ( $report['counts']['guides'] ?? array() ) ), __( 'guider', 'finet-troubleshoot' ) );
			View::kpi( (string) array_sum( (array) ( $report['counts']['outages'] ?? array() ) ), __( 'driftstörningar', 'finet-troubleshoot' ) );
			View::kpi( (string) count( (array) $report['broken_guide_refs'] ), __( 'trasiga guidereferenser', 'finet-troubleshoot' ) );
			View::kpi( (string) $report['unresolved_legacy'], __( 'oersatta äldre referenser', 'finet-troubleshoot' ) );
			echo '</div>';
			if ( ! empty( $report['settings']['delete_data_on_uninstall'] ) ) {
				View::notice( 'error', __( '"Radera data vid avinstallation" är aktiverat. En avinstallation raderar alla guider, driftstörningar och all statistik. Stäng av inställningen och ta separat backup.', 'finet-troubleshoot' ) );
			}
			echo '<table class="wpf-table"><thead><tr><th>' . esc_html__( 'Referens', 'finet-troubleshoot' ) . '</th><th>' . esc_html__( 'Plan', 'finet-troubleshoot' ) . '</th><th>' . esc_html__( 'Förekomster', 'finet-troubleshoot' ) . '</th><th>' . esc_html__( 'Platser', 'finet-troubleshoot' ) . '</th></tr></thead><tbody>';
			foreach ( (array) $report['summary'] as $key => $row ) {
				echo '<tr><td>' . esc_html( $row['label'] ) . '</td><td>' . View::badge( $row['status'], 'bevaras' === $row['status'] ? 'ok' : ( 'ersätts' === $row['status'] ? 'warn' : 'muted' ) ) . '</td><td>' . (int) $row['occurrences'] . '</td><td>' . (int) $row['locations'] . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
			}
			echo '</tbody></table>';
			foreach ( (array) $report['findings'] as $key => $locations ) {
				if ( ! $locations ) { continue; }
				echo '<details class="wpf-details"><summary>' . esc_html( $report['summary'][ $key ]['label'] ) . ' (' . count( $locations ) . ')</summary><table class="wpf-table"><tbody>';
				foreach ( $locations as $loc ) {
					$title = $loc['edit_url'] ? '<a href="' . esc_url( $loc['edit_url'] ) . '">' . esc_html( $loc['title'] ?: '#' . $loc['post_id'] ) . '</a>' : esc_html( $loc['title'] );
					echo '<tr><td>' . $title . '<br><span class="wpf-muted">' . esc_html( $loc['post_type'] . ' · ' . $loc['status'] . ' · ' . $loc['source'] ) . '</span></td><td><code>' . esc_html( implode( "\n", $loc['snippets'] ) ) . '</code></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput
				}
				echo '</tbody></table></details>';
			}
			if ( $report['broken_guide_refs'] ) {
				echo '<h3>' . esc_html__( 'Trasiga guidereferenser', 'finet-troubleshoot' ) . '</h3><ul>';
				foreach ( $report['broken_guide_refs'] as $b ) { echo '<li><code>' . esc_html( $b['shortcode'] ) . '</code> – ' . esc_html( $b['problem'] ) . ' (' . esc_html( $b['title'] ) . ')</li>'; }
				echo '</ul>';
			}
		}
		View::card_close();

		View::card_open( __( 'Händelselogg', 'finet-troubleshoot' ) );
		$entries = array_slice( Logger::entries(), 0, 50 );
		if ( ! $entries ) {
			echo '<p class="wpf-muted">' . esc_html__( 'Inga loggposter ännu.', 'finet-troubleshoot' ) . '</p>';
		} else {
			echo '<table class="wpf-table"><tbody>';
			foreach ( $entries as $e ) { echo '<tr><td><time>' . esc_html( $e['time'] ) . '</time></td><td>' . View::badge( $e['channel'], 'muted' ) . '</td><td>' . View::badge( $e['level'], 'error' === $e['level'] ? 'error' : ( 'warning' === $e['level'] ? 'warn' : 'muted' ) ) . '</td><td>' . esc_html( $e['message'] ) . '</td></tr>'; } // phpcs:ignore WordPress.Security.EscapeOutput
			echo '</tbody></table>';
		}
		View::card_close();
		View::close();
	}
}
