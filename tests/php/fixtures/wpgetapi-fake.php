<?php
/**
 * Stand-in for the WPGetAPI plugin's template function, controlled by the tests through a global callable.
 * Mirrors the real signature wpgetapi_endpoint( $api_id, $endpoint_id, $args = array(), $keys = array() ).
 */
if ( ! function_exists( 'wpgetapi_endpoint' ) ) {
	function wpgetapi_endpoint( $api_id = '', $endpoint_id = '', $args = array(), $keys = array() ) {
		$GLOBALS['wpfinet_fake_wpgetapi_calls'][] = array( $api_id, $endpoint_id, $args );
		$fn = $GLOBALS['wpfinet_fake_wpgetapi'] ?? null;
		return is_callable( $fn ) ? $fn( $api_id, $endpoint_id, $args ) : false;
	}
}
