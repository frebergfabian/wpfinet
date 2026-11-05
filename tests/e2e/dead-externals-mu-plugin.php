<?php
/** Perf fixture: every outbound HTTP request hangs 30 s (simulates Access API / Instagram / Meta CDN being down). */
add_filter( 'pre_http_request', static function ( $pre, $args, $url ) {
	file_put_contents( WP_CONTENT_DIR . '/outbound.log', gmdate( 'c' ) . ' ' . ( $_SERVER['REQUEST_URI'] ?? 'cli' ) . ' -> ' . $url . "\n", FILE_APPEND ); // phpcs:ignore
	sleep( 30 );
	return new WP_Error( 'http_request_failed', 'simulated hang' );
}, 1, 3 );
