<?php
/**
 * Loaded via php -d auto_prepend_file so WordPress boots in GLOBAL scope (PHPUnit includes bootstrap files inside
 * a function, which would make WordPress globals such as $shortcode_tags local). Use bin/test.sh.
 */
$wpfinet_root = dirname( __DIR__, 2 );
$wpfinet_env  = getenv( 'WPFINET_TEST_ENV' ) ?: $wpfinet_root . '/.test-env';
if ( ! file_exists( $wpfinet_env . '/clean.sqlite' ) ) { fwrite( STDERR, "Run bin/setup-test-env.sh first.\n" ); exit( 1 ); }
copy( $wpfinet_env . '/clean.sqlite', $wpfinet_env . '/wordpress/wp-content/database/test.sqlite' );
file_put_contents( $wpfinet_env . '/wordpress/wp-content/debug.log', '' ); // Each run starts with an empty log (ZzNoWarningsTest).
$_SERVER['HTTP_HOST'] = '127.0.0.1:8890'; $_SERVER['SERVER_NAME'] = '127.0.0.1'; $_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET'; $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
define( 'WP_USE_THEMES', false );
define( 'WPFINET_TESTING', true );
if ( ! function_exists( 'wp_has_consent' ) && ! getenv( 'WPFINET_NO_CONSENT_DOUBLE' ) ) {
	/** Test double for WP Consent API; controlled by $GLOBALS['wpfinet_test_consent']. */
	function wp_has_consent( $category ) { return ! empty( $GLOBALS['wpfinet_test_consent'] ); } // phpcs:ignore
}
require $wpfinet_env . '/wordpress/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/admin.php';
