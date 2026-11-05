<?php
/**
 * Main plugin bootstrapper.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot;

use FinetTroubleshoot\Admin\AdminMenu;
use FinetTroubleshoot\Admin\GuideEditor;
use FinetTroubleshoot\Admin\Settings;
use FinetTroubleshoot\Analytics\EventController;
use FinetTroubleshoot\Analytics\ConsentManager;
use FinetTroubleshoot\Core\Cron;
use FinetTroubleshoot\Core\MigrationManager;
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

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function boot(): void {
		load_plugin_textdomain( 'finet-troubleshoot', false, dirname( plugin_basename( FINET_TS_FILE ) ) . '/languages' );

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
	}

	private function __construct() {}
}
