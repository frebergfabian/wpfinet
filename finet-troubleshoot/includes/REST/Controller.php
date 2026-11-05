<?php
/**
 * Private REST endpoints used by the editor and importer.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\REST;

use FinetTroubleshoot\Export\ExportManager;
use FinetTroubleshoot\Import\ImportManager;
use FinetTroubleshoot\Import\SchemaValidator;
use FinetTroubleshoot\PostTypes\GuidePostType;
use FinetTroubleshoot\Integrations\WPForms;
use FinetTroubleshoot\Core\StrictCapabilities;
use FinetTroubleshoot\Validation\FlowValidator;

final class Controller {
	private SchemaValidator $schema_validator;
	public function __construct( private FlowValidator $validator, private ImportManager $importer, private ExportManager $exporter, private WPForms $wpforms ) { $this->schema_validator = new SchemaValidator( $validator ); }
	public function register_hooks(): void { add_action( 'rest_api_init', array( $this, 'register_routes' ) ); }
	public function register_routes(): void {
		$namespace = 'finet-troubleshoot/v1';
		register_rest_route( $namespace, '/guides', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'guides' ), 'permission_callback' => array( $this, 'can_edit' ) ) );
		register_rest_route( $namespace, '/guides/(?P<id>\d+)', array(
			array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'guide' ), 'permission_callback' => array( $this, 'can_edit' ), 'args' => array( 'id' => array( 'validate_callback' => static fn ( $value ): bool => is_numeric( $value ) ) ) ), // 2.0.1 passed 'is_numeric' directly, which throws ArgumentCountError on PHP 8 (WordPress passes 3 args).
			array( 'methods' => \WP_REST_Server::EDITABLE, 'callback' => array( $this, 'save_guide' ), 'permission_callback' => array( $this, 'can_edit' ) ),
		) );
		register_rest_route( $namespace, '/guides/(?P<id>\d+)/duplicate', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'duplicate' ), 'permission_callback' => array( $this, 'can_edit' ) ) );
		register_rest_route( $namespace, '/validate', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'validate' ), 'permission_callback' => array( $this, 'can_edit' ) ) );
		register_rest_route( $namespace, '/import/preview', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'import_preview' ), 'permission_callback' => array( $this, 'can_manage' ) ) );
		register_rest_route( $namespace, '/import', array( 'methods' => \WP_REST_Server::CREATABLE, 'callback' => array( $this, 'import' ), 'permission_callback' => array( $this, 'can_manage' ) ) );
		register_rest_route( $namespace, '/export/(?P<id>\d+)', array( 'methods' => \WP_REST_Server::READABLE, 'callback' => array( $this, 'export' ), 'permission_callback' => array( $this, 'can_manage' ) ) );
	}
	public function can_edit(): bool { return StrictCapabilities::can_edit_guides(); }
	public function can_manage(): bool { return current_user_can( 'manage_wpfinet_settings' ) || current_user_can( 'manage_options' ); }
	public function guides(): \WP_REST_Response {
		$posts = get_posts( array( 'post_type' => GuidePostType::POST_TYPE, 'post_status' => array( 'publish', 'draft', 'private' ), 'posts_per_page' => -1, 'orderby' => 'modified', 'order' => 'DESC' ) );
		return rest_ensure_response( array_map( fn ( \WP_Post $post ): array => $this->guide_data( $post ), $posts ) );
	}
	public function guide( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post = $this->get_editable_post( (int) $request['id'] ); if ( is_wp_error( $post ) ) { return $post; }
		return rest_ensure_response( $this->guide_data( $post ) );
	}
	public function save_guide( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post = $this->get_editable_post( (int) $request['id'] ); if ( is_wp_error( $post ) ) { return $post; }
		$body = $request->get_json_params(); if ( ! is_array( $body ) ) { return new \WP_Error( 'finet_ts_invalid_payload', __( 'Ogiltiga guidedata.', 'finet-troubleshoot' ), array( 'status' => 400 ) ); }
		$clean = $this->schema_validator->sanitize_guide( array_merge( $body, array( 'external_id' => get_post_meta( $post->ID, GuidePostType::META_EXTERNAL_ID, true ) ?: sanitize_title( $post->post_name ) ) ) );
		$validation = $this->validator->validate( $clean );
		if ( ! $validation['valid'] ) { return new \WP_Error( 'finet_ts_invalid_flow', __( 'Guiden kunde inte sparas eftersom flödet har fel.', 'finet-troubleshoot' ), array( 'status' => 400, 'validation' => $validation ) ); }
		wp_save_post_revision( $post->ID );
		$updated = wp_update_post( wp_slash( array( 'ID' => $post->ID, 'post_excerpt' => $clean['description'] ) ), true );
		if ( is_wp_error( $updated ) ) { return $updated; }
		update_post_meta( $post->ID, GuidePostType::META_START_NODE, $clean['start_node'] );
		update_post_meta( $post->ID, GuidePostType::META_NODES, $clean['nodes'] );
		update_post_meta( $post->ID, GuidePostType::META_SETTINGS, $clean['settings'] );
		update_post_meta( $post->ID, GuidePostType::META_SCHEMA, '2.0' );
		return rest_ensure_response( array( 'guide' => $this->guide_data( get_post( $post->ID ) ), 'validation' => $validation ) );
	}
	public function duplicate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post = $this->get_editable_post( (int) $request['id'] ); if ( is_wp_error( $post ) ) { return $post; }
		$new = wp_insert_post( array( 'post_type' => GuidePostType::POST_TYPE, 'post_title' => $post->post_title . ' – ' . __( 'Kopia', 'finet-troubleshoot' ), 'post_excerpt' => $post->post_excerpt, 'post_status' => 'draft' ), true );
		if ( is_wp_error( $new ) ) { return $new; }
		foreach ( array( GuidePostType::META_START_NODE, GuidePostType::META_NODES, GuidePostType::META_SETTINGS, GuidePostType::META_SCHEMA ) as $key ) { update_post_meta( $new, $key, get_post_meta( $post->ID, $key, true ) ); }
		update_post_meta( $new, GuidePostType::META_EXTERNAL_ID, sanitize_title( $post->post_name ) . '-' . wp_generate_password( 8, false, false ) );
		return rest_ensure_response( $this->guide_data( get_post( $new ) ) );
	}
	public function validate( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$body = $request->get_json_params(); if ( ! is_array( $body ) ) { return new \WP_Error( 'finet_ts_invalid_payload', __( 'Ogiltiga guidedata.', 'finet-troubleshoot' ), array( 'status' => 400 ) ); }
		return rest_ensure_response( $this->validator->validate( $this->schema_validator->sanitize_guide( $body ) ) );
	}
	public function import_preview( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$files = $request->get_file_params(); $file = $files['file'] ?? null;
		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) { return new \WP_Error( 'finet_ts_missing_file', __( 'Välj en JSON-fil att analysera.', 'finet-troubleshoot' ), array( 'status' => 400 ) ); }
		$max = (int) apply_filters( 'finet_troubleshoot_max_import_size', 5 * MB_IN_BYTES );
		if ( (int) $file['size'] > $max ) { return new \WP_Error( 'finet_ts_file_too_large', sprintf( __( 'Filen är större än tillåtna %s.', 'finet-troubleshoot' ), size_format( $max ) ), array( 'status' => 400 ) ); }
		$contents = file_get_contents( $file['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$document = is_string( $contents ) ? json_decode( $contents, true ) : null;
		if ( ! is_array( $document ) || JSON_ERROR_NONE !== json_last_error() ) { return new \WP_Error( 'finet_ts_invalid_json', __( 'Filen innehåller inte giltig JSON.', 'finet-troubleshoot' ), array( 'status' => 400 ) ); }
		$preview = $this->importer->preview( $document );
		$token = wp_generate_uuid4(); set_transient( 'finet_ts_import_' . $token, array( 'user' => get_current_user_id(), 'document' => $document ), 15 * MINUTE_IN_SECONDS );
		$preview['token'] = $token;
		return rest_ensure_response( $preview );
	}
	public function import( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$token = sanitize_text_field( (string) $request->get_param( 'token' ) ); $cached = get_transient( 'finet_ts_import_' . $token );
		if ( ! is_array( $cached ) || get_current_user_id() !== (int) ( $cached['user'] ?? 0 ) || ! is_array( $cached['document'] ?? null ) ) { return new \WP_Error( 'finet_ts_import_expired', __( 'Importanalysen har gått ut. Analysera filen igen.', 'finet-troubleshoot' ), array( 'status' => 400 ) ); }
		$strategy = sanitize_key( (string) $request->get_param( 'strategy' ) ); $result = $this->importer->import_data( $cached['document'], $strategy ); delete_transient( 'finet_ts_import_' . $token );
		return rest_ensure_response( $result->to_array() );
	}
	public function export( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$guide = $this->exporter->export_guide( (int) $request['id'] ); if ( is_wp_error( $guide ) ) { return $guide; }
		// 2.0 behaviour (kept): a single-guide export also contains all outages. ?include_outages=0 omits them.
		$include_outages = null === $request->get_param( 'include_outages' ) || rest_sanitize_boolean( $request->get_param( 'include_outages' ) );
		return rest_ensure_response( $this->exporter->document( array( $guide ), $include_outages ) );
	}
	private function get_editable_post( int $id ): \WP_Post|\WP_Error {
		$post = get_post( $id );
		if ( ! $post || GuidePostType::POST_TYPE !== $post->post_type ) { return new \WP_Error( 'finet_ts_not_found', __( 'Guiden kunde inte hittas.', 'finet-troubleshoot' ), array( 'status' => 404 ) ); }
		if ( ! current_user_can( 'edit_post', $id ) ) { return new \WP_Error( 'finet_ts_forbidden', __( 'Du saknar behörighet att redigera denna guide.', 'finet-troubleshoot' ), array( 'status' => 403 ) ); }
		return $post;
	}
	/** @return array<string,mixed> */
	private function guide_data( \WP_Post $post ): array {
		return array( 'id' => $post->ID, 'title' => $post->post_title, 'slug' => $post->post_name, 'description' => $post->post_excerpt, 'status' => $post->post_status, 'external_id' => get_post_meta( $post->ID, GuidePostType::META_EXTERNAL_ID, true ), 'start_node' => get_post_meta( $post->ID, GuidePostType::META_START_NODE, true ), 'nodes' => get_post_meta( $post->ID, GuidePostType::META_NODES, true ) ?: array(), 'settings' => get_post_meta( $post->ID, GuidePostType::META_SETTINGS, true ) ?: array() );
	}
}
