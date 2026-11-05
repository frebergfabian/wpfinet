<?php
/**
 * Consent-gated public telemetry REST endpoint and reports.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Analytics;

final class EventController {
	private const EVENTS = array( 'guide_viewed', 'guide_started', 'node_viewed', 'answer_selected', 'guide_completed', 'guide_abandoned', 'result_reached', 'cta_viewed', 'cta_clicked', 'outage_displayed', 'outage_clicked', 'wpform_displayed', 'wpform_started', 'wpform_submitted', 'restart_clicked', 'back_clicked' );
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
	}
	public function routes(): void {
		register_rest_route( 'wpfinet/v1', '/events', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'store' ), 'permission_callback' => '__return_true' ) );
		register_rest_route( 'wpfinet/v1', '/analytics/summary', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'summary' ), 'permission_callback' => static fn (): bool => current_user_can( 'view_wpfinet_statistics' ) || current_user_can( 'manage_options' ) ) );
	}
	public function store( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$consent = new ConsentManager();
		if ( ! $consent->has_analytics_consent() ) { return rest_ensure_response( array( 'accepted' => false, 'reason' => 'consent_required' ) ); }
		$payload = $request->get_json_params(); if ( ! is_array( $payload ) ) { return new \WP_Error( 'wpfinet_invalid_event', __( 'Ogiltig statistikdata.', 'finet-troubleshoot' ), array( 'status' => 400 ) ); }
		$events = isset( $payload['events'] ) && is_array( $payload['events'] ) ? array_slice( $payload['events'], 0, 25 ) : array( $payload );
		$stored = 0;
		foreach ( $events as $event ) { if ( is_array( $event ) && $this->store_event( $event ) ) { ++$stored; } }
		return rest_ensure_response( array( 'accepted' => true, 'stored' => $stored ) );
	}
	private function store_event( array $event ): bool {
		global $wpdb;
		$session = sanitize_text_field( (string) ( $event['session'] ?? '' ) ); $type = sanitize_key( (string) ( $event['event'] ?? '' ) );
		if ( ! preg_match( '/^[a-f0-9-]{36}$/i', $session ) || ! in_array( $type, self::EVENTS, true ) ) { return false; }
		$limit_key = 'wpfinet_event_rate_' . md5( $session ); $count = (int) get_transient( $limit_key ); if ( $count >= 120 ) { return false; } set_transient( $limit_key, $count + 1, MINUTE_IN_SECONDS );
		$guide_id = absint( $event['guide_id'] ?? 0 ); $device = in_array( $event['device_class'] ?? 'unknown', array( 'desktop', 'tablet', 'mobile', 'unknown' ), true ) ? $event['device_class'] : 'unknown';
		$path = wp_parse_url( esc_url_raw( (string) ( $event['page_path'] ?? '' ) ), PHP_URL_PATH ); $path = is_string( $path ) ? substr( $path, 0, 255 ) : '';
		$db = new Database(); $now = current_time( 'mysql', true );
		$session_table = $db->sessions_table(); $event_table = $db->events_table();
		$wpdb->query( $wpdb->prepare( "INSERT INTO {$session_table} (anonymous_session_id, guide_id, started_at, last_event_at, device_class, page_path, event_count, status) VALUES (%s, %d, %s, %s, %s, %s, 1, 'active') ON DUPLICATE KEY UPDATE guide_id = VALUES(guide_id), last_event_at = VALUES(last_event_at), device_class = VALUES(device_class), page_path = VALUES(page_path), event_count = event_count + 1", $session, $guide_id, $now, $now, $device, $path ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$metadata = array(); if ( ! empty( $event['guide_external_id'] ) ) { $metadata['guide_external_id'] = sanitize_title( (string) $event['guide_external_id'] ); }
		$inserted = $wpdb->insert( $event_table, array( 'anonymous_session_id' => $session, 'event_type' => $type, 'guide_id' => $guide_id, 'node_id' => sanitize_title( (string) ( $event['node_id'] ?? '' ) ), 'option_id' => sanitize_title( (string) ( $event['option_id'] ?? '' ) ), 'cta_type' => sanitize_key( (string) ( $event['cta_type'] ?? '' ) ), 'form_id' => absint( $event['form_id'] ?? 0 ), 'outage_id' => absint( $event['outage_id'] ?? 0 ), 'created_at' => $now, 'metadata' => $metadata ? wp_json_encode( $metadata ) : null ), array( '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( in_array( $type, array( 'guide_completed', 'result_reached' ), true ) ) { $wpdb->update( $session_table, array( 'completed_at' => $now, 'result_node' => sanitize_title( (string) ( $event['node_id'] ?? '' ) ), 'status' => 'completed' ), array( 'anonymous_session_id' => $session ), array( '%s', '%s', '%s' ), array( '%s' ) ); }
		return false !== $inserted;
	}
	public function summary( \WP_REST_Request $request ): \WP_REST_Response {
		global $wpdb; $days = min( 365, max( 1, absint( $request->get_param( 'days' ) ?: 30 ) ) ); $since = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) ); $table = ( new Database() )->sessions_table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS starts, SUM(status = 'completed') AS completions, SUM(status = 'abandoned') AS abandoned, AVG(event_count) AS average_steps FROM {$table} WHERE started_at >= %s", $since ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$starts = (int) ( $row['starts'] ?? 0 ); $complete = (int) ( $row['completions'] ?? 0 );
		return rest_ensure_response( array( 'days' => $days, 'starts' => $starts, 'completions' => $complete, 'abandoned' => (int) ( $row['abandoned'] ?? 0 ), 'completion_rate' => $starts ? round( $complete / $starts * 100, 1 ) : 0, 'average_steps' => round( (float) ( $row['average_steps'] ?? 0 ), 1 ) ) );
	}
}
