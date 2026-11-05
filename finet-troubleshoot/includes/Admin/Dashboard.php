<?php
/**
 * WPFINET → Översikt: key figures for guides, statistics and outages plus the status of every 3.0 module.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Admin;

use FinetTroubleshoot\Access\AccessService;
use FinetTroubleshoot\Access\AccessSettings;
use FinetTroubleshoot\Analytics\Database;
use FinetTroubleshoot\Consent\Inventory;
use FinetTroubleshoot\Consent\Policy;
use FinetTroubleshoot\Core\MigrationManager;
use FinetTroubleshoot\Core\Modules;
use FinetTroubleshoot\Instagram\InstagramService;
use FinetTroubleshoot\Migration\LegacyScanner;
use FinetTroubleshoot\Outages\OutageRepository;
use FinetTroubleshoot\PostTypes\GuidePostType;

final class Dashboard {
	public function render(): void {
		global $wpdb;
		$guides   = wp_count_posts( GuidePostType::POST_TYPE );
		$repo     = new OutageRepository();
		$current  = count( $repo->find( 'current', array(), 100 ) );
		$upcoming = count( $repo->find( 'upcoming', array(), 100 ) );
		$table    = ( new Database() )->sessions_table();
		$stats    = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS starts, SUM(status = 'completed') AS completed FROM {$table} WHERE started_at >= %s", gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$starts   = (int) ( $stats['starts'] ?? 0 );
		$done     = (int) ( $stats['completed'] ?? 0 );
		$modules  = Modules::all();

		View::open( __( 'WPFINET – översikt', 'finet-troubleshoot' ) );
		if ( MigrationManager::needs_migration() ) {
			View::notice( 'warning', __( 'Databasen väntar på migrering (körs automatiskt, tar inte bort data).', 'finet-troubleshoot' ) );
		}
		$settings = get_option( 'finet_ts_settings', array() );
		if ( ! empty( $settings['advanced']['delete_data'] ) ) {
			View::notice( 'error', __( '"Radera data vid avinstallation" är aktiverat. En avinstallation raderar alla guider, driftstörningar och all statistik.', 'finet-troubleshoot' ) );
		}
		echo '<div class="wpf-kpis">';
		View::kpi( (string) (int) ( $guides->publish ?? 0 ), __( 'publicerade guider', 'finet-troubleshoot' ) );
		View::kpi( (string) (int) ( $guides->draft ?? 0 ), __( 'guideutkast', 'finet-troubleshoot' ) );
		View::kpi( (string) $starts, __( 'startade felsökningar (30 dagar)', 'finet-troubleshoot' ) );
		View::kpi( $starts ? round( $done / $starts * 100 ) . ' %' : '–', __( 'slutförda (30 dagar)', 'finet-troubleshoot' ) );
		View::kpi( (string) $current, __( 'aktuella driftstörningar', 'finet-troubleshoot' ) );
		View::kpi( (string) $upcoming, __( 'planerade arbeten', 'finet-troubleshoot' ) );
		echo '</div><div class="wpf-actions">';
		foreach ( array( array( 'post-new.php?post_type=' . GuidePostType::POST_TYPE, __( 'Ny felsökningsguide', 'finet-troubleshoot' ), 'button button-primary' ), array( 'post-new.php?post_type=wpfinet_outage', __( 'Ny driftstörning', 'finet-troubleshoot' ), 'button' ), array( 'admin.php?page=finet-ts-import-export', __( 'Importera guide', 'finet-troubleshoot' ), 'button' ), array( 'admin.php?page=wpfinet-statistics', __( 'Visa statistik', 'finet-troubleshoot' ), 'button' ) ) as [ $url, $label, $class ] ) {
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( admin_url( $url ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</div><h2 class="wpf-section-title">' . esc_html__( 'Moduler', 'finet-troubleshoot' ) . '</h2><div class="wpf-grid">';

		$this->module_card( __( 'Adressökning', 'finet-troubleshoot' ), 'wpfinet-access', $modules['access'], function (): array {
			$s = AccessSettings::get();
			$h = ( new AccessService( $s ) )->health();
			$real_missing = AccessSettings::missing( array_merge( $s, array( 'adapter' => 'http' ) ) );
			return array(
				array( __( 'Datakälla', 'finet-troubleshoot' ), 'mock' === $s['adapter'] ? __( 'Testdata', 'finet-troubleshoot' ) : 'Finet Access API' ),
				array( __( 'Index', 'finet-troubleshoot' ), sprintf( __( '%d adresser', 'finet-troubleshoot' ), $h['index_rows'] ) . ' · ' . View::time_ago( (int) $h['last_success'] ) ),
				array( __( 'Riktig integration', 'finet-troubleshoot' ), $real_missing ? sprintf( _n( '%d uppgift saknas', '%d uppgifter saknas', count( $real_missing ), 'finet-troubleshoot' ), count( $real_missing ) ) : __( 'konfigurerad', 'finet-troubleshoot' ) ),
			);
		} );
		$this->module_card( __( 'Instagram', 'finet-troubleshoot' ), 'wpfinet-instagram', $modules['instagram'], function (): array {
			$h = ( new InstagramService() )->health();
			return array(
				array( __( 'Konto', 'finet-troubleshoot' ), $h['username'] ? '@' . $h['username'] : __( 'ej anslutet', 'finet-troubleshoot' ) ),
				array( __( 'Lokala inlägg', 'finet-troubleshoot' ), (string) $h['posts'] ),
				array( __( 'Senaste hämtning', 'finet-troubleshoot' ), View::time_ago( (int) $h['last_sync'] ) ),
			);
		} );
		$mode = $modules['consent'];
		$this->module_card( __( 'Cookies & samtycke', 'finet-troubleshoot' ), 'wpfinet-consent', 'off' !== $mode, function () use ( $mode ): array {
			$c = Inventory::counts();
			return array(
				array( __( 'Läge', 'finet-troubleshoot' ), array( 'off' => __( 'av (annan CMP)', 'finet-troubleshoot' ), 'preview' => __( 'testläge', 'finet-troubleshoot' ), 'live' => __( 'live', 'finet-troubleshoot' ) )[ $mode ] ),
				array( __( 'Inventarium', 'finet-troubleshoot' ), sprintf( __( '%1$d verifierade, %2$d att granska', 'finet-troubleshoot' ), $c['verified'], $c['suggested'] ) ),
				array( __( 'Publicerad policy', 'finet-troubleshoot' ), '0' === Policy::version() ? __( 'ingen', 'finet-troubleshoot' ) : 'v' . Policy::version() ),
			);
		}, 'preview' === $mode ? __( 'Testläge', 'finet-troubleshoot' ) : null );
		$report = LegacyScanner::last_report();
		$this->module_card( __( 'Migrering & inventering', 'finet-troubleshoot' ), 'wpfinet-diagnostics', true, function () use ( $report ): array {
			return array(
				array( __( 'Databasversion', 'finet-troubleshoot' ), (string) get_option( 'finet_ts_db_version', '–' ) ),
				array( __( 'Senaste inventering', 'finet-troubleshoot' ), $report ? View::time_ago( (int) strtotime( (string) $report['generated_at'] ) ) : __( 'inte körd', 'finet-troubleshoot' ) ),
				array( __( 'Oersatta äldre referenser', 'finet-troubleshoot' ), $report ? (string) $report['unresolved_legacy'] : '–' ),
			);
		}, __( 'Alltid på', 'finet-troubleshoot' ) );
		echo '</div>';
		View::close();
	}

	/** @param callable():array<int,array{0:string,1:string}> $rows */
	private function module_card( string $title, string $page, bool $on, callable $rows, ?string $badge = null ): void {
		echo '<section class="wpf-card wpf-module"><div class="wpf-module__head"><h3 class="wpf-card__title">' . esc_html( $title ) . '</h3>' . View::badge( $badge ?? ( $on ? __( 'På', 'finet-troubleshoot' ) : __( 'Av', 'finet-troubleshoot' ) ), $on ? ( $badge && 'Alltid på' !== $badge ? 'warn' : 'ok' ) : 'muted' ) . '</div><dl class="wpf-dl">'; // phpcs:ignore WordPress.Security.EscapeOutput
		foreach ( $rows() as [ $label, $value ] ) {
			echo '<dt>' . esc_html( $label ) . '</dt><dd>' . esc_html( $value ) . '</dd>';
		}
		echo '</dl><a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . $page ) ) . '">' . esc_html__( 'Öppna', 'finet-troubleshoot' ) . '</a></section>';
	}
}
