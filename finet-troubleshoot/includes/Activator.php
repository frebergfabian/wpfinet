<?php
/**
 * Activation lifecycle.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot;

use FinetTroubleshoot\Analytics\Database;
use FinetTroubleshoot\Core\Capabilities;
use FinetTroubleshoot\Core\Cron;
use FinetTroubleshoot\Import\ImportManager;
use FinetTroubleshoot\PostTypes\GuidePostType;
use FinetTroubleshoot\Validation\FlowValidator;

final class Activator {
	public static function activate(): void {
		( new GuidePostType() )->register();
		self::install_default_settings();
		Capabilities::grant_administrators();
		( new Database() )->install();
		Cron::schedule();
		$is_upgrade_or_reactivation = false !== get_option( 'finet_ts_db_version', false );

		$existing_guides = get_posts( array( 'post_type' => GuidePostType::POST_TYPE, 'post_status' => array( 'publish', 'draft', 'private', 'pending', 'future' ), 'fields' => 'ids', 'posts_per_page' => 1, 'no_found_rows' => true ) );
		if ( ! $is_upgrade_or_reactivation && ! get_option( 'finet_ts_demo_installed' ) && empty( $existing_guides ) ) {
			$path = FINET_TS_DIR . 'examples/finet-troubleshoot-example.json';
			if ( is_readable( $path ) ) {
				$json = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				$data = is_string( $json ) ? json_decode( $json, true ) : null;
				if ( is_array( $data ) ) {
					$result = ( new ImportManager( new FlowValidator() ) )->import_data( $data, 'copy', true );
					if ( $result->imported_count() > 0 ) {
						update_option( 'finet_ts_demo_installed', '1', false );
						set_transient( 'finet_ts_activation_notice', '1', DAY_IN_SECONDS );
					}
				}
			}
		}

		add_option( 'finet_ts_schema_version', '2.0', '', false );
		// 3.0: never overwrite the stored DB version (2.0.1 forced '2.0.0', hiding later migrations). Run migrations instead.
		( new Core\MigrationManager() )->run();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		Cron::unschedule();
		flush_rewrite_rules();
	}

	/** @return array<string, mixed> */
	public static function default_settings(): array {
		return array(
			'general' => array(
				'show_title'      => true,
				'show_back'       => true,
				'show_restart'    => true,
				'progress_mode'   => 'step',
				'animation'       => 'fade',
			),
			'appearance' => array(
				'preset'        => 'inherit',
				'primary_color' => '#005baa',
				'background'    => 'transparent',
				'card_background'=> '#ffffff',
				'border_color'   => '#d8dee7',
				'radius'         => 8,
				'spacing'        => 24,
				'max_width'      => 760,
				'progress'       => true,
			),
			'support' => array(
				'company'       => '',
				'phone'         => '',
				'email'         => '',
				'url'           => '',
				'outage_url'    => '',
				'opening_hours' => '',
				'text'          => '',
			),
			'outages' => array(
				'banner_enabled' => true,
				'status_url'     => '',
			),
			'analytics' => array(
				'enabled'         => true,
				'session_timeout_minutes' => 30,
				'retention_days'  => 180,
			),
			'advanced' => array(
				'delete_data' => false,
				'debug'       => false,
			),
		);
	}

	public static function install_default_settings(): void {
		$current = get_option( 'finet_ts_settings', array() );
		if ( ! is_array( $current ) || array() === $current ) {
			add_option( 'finet_ts_settings', self::default_settings(), '', false );
		}
	}
}
