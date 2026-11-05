<?php
/**
 * Main plugin bootstrapper.
 *
 * The 2.0 services (guides, outages, statistics, import/export, admin) are registered exactly as before so every hook,
 * shortcode and REST route keeps its contract. 3.0 modules are registered behind feature flags (see Core\Modules).
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot;

use FinetTroubleshoot\Admin\AdminApp;
use FinetTroubleshoot\Admin\AdminMenu;
use FinetTroubleshoot\Admin\GuideEditor;
use FinetTroubleshoot\Admin\Settings;
use FinetTroubleshoot\Analytics\EventController;
use FinetTroubleshoot\Analytics\ConsentManager;
use FinetTroubleshoot\Core\Cron;
use FinetTroubleshoot\Core\MigrationManager;
use FinetTroubleshoot\Core\Modules;
use FinetTroubleshoot\Core\StrictCapabilities;
use FinetTroubleshoot\Export\ExportManager;
use FinetTroubleshoot\Frontend\Renderer;
use FinetTroubleshoot\Frontend\Shortcode;
use FinetTroubleshoot\Import\ImportManager;
use FinetTroubleshoot\Integrations\WPForms;
use FinetTroubleshoot\PostTypes\GuidePostType;
use FinetTroubleshoot\Outages\OutageModule;
use FinetTroubleshoot\REST\Controller;
use FinetTroubleshoot\Validation\FlowValidator;

final class Plugin {
	private static ?Plugin $instance = null;
	private bool $booted = false;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		if ( $this->booted ) {
			return; // Guard against double registration of CPTs, shortcodes and REST routes.
		}
		$this->booted = true;
		load_plugin_textdomain( 'finet-troubleshoot', false, dirname( plugin_basename( FINET_TS_FILE ) ) . '/languages' );

		( new StrictCapabilities() )->register_hooks();
		$post_type = new GuidePostType();
		$post_type->register_hooks();
		( new OutageModule() )->register_hooks();
		( new MigrationManager() )->register_hooks();
		( new Cron() )->register_hooks();

		$validator = new FlowValidator();
		$importer  = new ImportManager( $validator );
		$exporter  = new ExportManager();
		$renderer  = new Renderer();
		$wpforms   = new WPForms();

		( new Shortcode( $renderer ) )->register_hooks();
		( new AdminMenu( $importer, $exporter ) )->register_hooks();
		( new Settings() )->register_hooks();
		( new GuideEditor( $validator, $wpforms ) )->register_hooks();
		( new Controller( $validator, $importer, $exporter, $wpforms ) )->register_hooks();
		( new EventController() )->register_hooks();
		( new ConsentManager() )->register_hooks();

		// 3.0 modules.
		( new AdminApp() )->register_hooks();
		( new Frontend\ModuleShortcodes() )->register_hooks();
		if ( Modules::enabled( 'access' ) && class_exists( Access\AccessModule::class ) ) {
			( new Access\AccessModule() )->register_hooks();
		}
		if ( Modules::enabled( 'instagram' ) && class_exists( Instagram\InstagramModule::class ) ) {
			( new Instagram\InstagramModule() )->register_hooks();
		}
		if ( Modules::enabled( 'consent' ) && class_exists( Consent\ConsentModule::class ) ) {
			( new Consent\ConsentModule() )->register_hooks();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			CLI\Commands::register();
		}
		do_action( 'wpfinet_loaded', $this );
	}

	private function __construct() {}
}
