<?php
// Router for `php -S` emulating IIS URL Rewrite / pretty permalinks: serve real files, otherwise index.php.
$root = $_SERVER['DOCUMENT_ROOT'];
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
if ( '/' !== $path && is_file( $root . $path ) ) {
	if ( str_ends_with( $path, '.php' ) ) { chdir( dirname( $root . $path ) ); require $root . $path; return true; }
	return false;
}
if ( is_dir( $root . $path ) && is_file( rtrim( $root . $path, '/' ) . '/index.php' ) && '/' !== $path ) { require rtrim( $root . $path, '/' ) . '/index.php'; return true; }
require $root . '/index.php';
