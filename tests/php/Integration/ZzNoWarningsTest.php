<?php
namespace WPFinet\Tests\Integration;

use WPFinet\Tests\TestCase;

/** Runs last (alphabetical): the whole test run must not have produced PHP warnings/notices from plugin code. */
final class ZzNoWarningsTest extends TestCase {
	public function test_no_php_warnings_from_plugin_code(): void {
		$log = WP_CONTENT_DIR . '/debug.log';
		$lines = is_readable( $log ) ? file( $log, FILE_IGNORE_NEW_LINES ) : array();
		$own = array_values( array_filter( $lines, static fn ( $l ) => str_contains( $l, (string) realpath( WP_PLUGIN_DIR . '/finet-troubleshoot' ) ) && preg_match( '/PHP (Warning|Notice|Deprecated|Fatal)/', $l ) ) );
		$this->assertSame( array(), array_slice( $own, 0, 10 ) );
	}
}
