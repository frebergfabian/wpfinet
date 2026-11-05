<?php
/**
 * Plugin Name: WPFINET (Finet Troubleshoot)
 * Plugin URI:  https://github.com/fabianfreberg/finet-troubleshoot
 * Description: Felsökningsguider, driftstörningar, adressökning, Instagramflöde och samtyckeshantering för Finet. Uppgradering av Finet Troubleshoot 2.x – samma pluginidentitet och data.
 * Version:     3.0.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author:      Fabian Freberg
 * Author URI:  https://freberg.se
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: finet-troubleshoot
 * Domain Path: /languages
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'FINET_TS_VERSION', '3.0.0' );
define( 'FINET_TS_FILE', __FILE__ );
define( 'FINET_TS_DIR', plugin_dir_path( __FILE__ ) );
define( 'FINET_TS_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( string $class ): void {
		$prefix = 'FinetTroubleshoot\\';
		if ( ! str_starts_with( $class, $prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $prefix ) );
		$file     = FINET_TS_DIR . 'includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

register_activation_hook( __FILE__, array( 'FinetTroubleshoot\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'FinetTroubleshoot\\Activator', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		FinetTroubleshoot\Plugin::instance()->boot();
	}
);
