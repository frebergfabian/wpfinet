<?php
/**
 * Admin pages and post list enhancements.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Admin;

use FinetTroubleshoot\Activator;
use FinetTroubleshoot\Export\ExportManager;
use FinetTroubleshoot\Import\ImportManager;
use FinetTroubleshoot\PostTypes\GuidePostType;
use FinetTroubleshoot\Analytics\Database;
use FinetTroubleshoot\Outages\OutageRepository;

final class AdminMenu {
	public function __construct( private ImportManager $importer, private ExportManager $exporter ) {}
	public function register_hooks(): void {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_filter( 'manage_' . GuidePostType::POST_TYPE . '_posts_columns', array( $this, 'columns' ) );
		add_action( 'manage_' . GuidePostType::POST_TYPE . '_posts_custom_column', array( $this, 'column_content' ), 10, 2 );
		add_filter( 'post_row_actions', array( $this, 'row_actions' ), 10, 2 );
		add_action( 'admin_post_finet_ts_duplicate', array( $this, 'duplicate' ) );
		add_action( 'admin_post_finet_ts_export', array( $this, 'export' ) );
		add_action( 'admin_notices', array( $this, 'activation_notice' ) );
		add_action( 'admin_init', array( $this, 'dismiss_notice' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}
	public function assets( string $hook ): void {
		if ( false === strpos( $hook, 'wpfinet' ) && false === strpos( $hook, 'finet-ts' ) ) { return; }
		wp_enqueue_style( 'finet-troubleshoot-admin', FINET_TS_URL . 'assets/build/admin.css', array(), \FinetTroubleshoot\Core\Assets::ver( 'admin.css' ) );
		wp_enqueue_script( 'finet-troubleshoot-admin', FINET_TS_URL . 'assets/build/admin.js', array( 'wp-api-fetch', 'wp-i18n' ), \FinetTroubleshoot\Core\Assets::ver( 'admin.js' ), true );
		wp_add_inline_script( 'finet-troubleshoot-admin', 'window.FinetTroubleshootAdmin=' . wp_json_encode( array( 'restRoot' => esc_url_raw( rest_url( 'finet-troubleshoot/v1/' ) ), 'wpfinetRoot' => esc_url_raw( rest_url( 'wpfinet/v1/' ) ), 'nonce' => wp_create_nonce( 'wp_rest' ), 'strings' => array( 'saved' => __( 'Sparad.', 'finet-troubleshoot' ), 'copied' => __( 'Shortcode kopierad.', 'finet-troubleshoot' ) ) ) ) . ';', 'before' );
	}
	public function register_menu(): void {
		if ( ! $this->wpfinet_menu_exists() ) { add_menu_page( __( 'WPFinet', 'finet-troubleshoot' ), __( 'WPFinet', 'finet-troubleshoot' ), 'manage_wpfinet', 'wpfinet', array( $this, 'dashboard' ), 'dashicons-admin-tools', 26 ); }
		add_submenu_page( 'wpfinet', __( 'Översikt', 'finet-troubleshoot' ), __( 'Översikt', 'finet-troubleshoot' ), 'manage_wpfinet', 'wpfinet', array( $this, 'dashboard' ) );
		add_submenu_page( 'wpfinet', __( 'Felsökningsguider', 'finet-troubleshoot' ), __( 'Felsökningsguider', 'finet-troubleshoot' ), 'manage_wpfinet_guides', 'edit.php?post_type=' . GuidePostType::POST_TYPE );
		add_submenu_page( 'wpfinet', __( 'Ny guide', 'finet-troubleshoot' ), __( 'Ny guide', 'finet-troubleshoot' ), 'manage_wpfinet_guides', 'post-new.php?post_type=' . GuidePostType::POST_TYPE );
		add_submenu_page( 'wpfinet', __( 'Driftstörningar', 'finet-troubleshoot' ), __( 'Driftstörningar', 'finet-troubleshoot' ), 'manage_wpfinet_outages', 'edit.php?post_type=wpfinet_outage' );
		add_submenu_page( 'wpfinet', __( 'Ny driftstörning', 'finet-troubleshoot' ), __( 'Ny driftstörning', 'finet-troubleshoot' ), 'manage_wpfinet_outages', 'post-new.php?post_type=wpfinet_outage' );
		add_submenu_page( 'wpfinet', __( 'Statistik', 'finet-troubleshoot' ), __( 'Statistik', 'finet-troubleshoot' ), 'view_wpfinet_statistics', 'wpfinet-statistics', array( $this, 'statistics_page' ) );
		add_submenu_page( 'wpfinet', __( 'Import / Export', 'finet-troubleshoot' ), __( 'Import / Export', 'finet-troubleshoot' ), 'manage_wpfinet_settings', 'finet-ts-import-export', array( $this, 'import_export_page' ) );
		add_submenu_page( 'wpfinet', __( 'Importformat', 'finet-troubleshoot' ), __( 'Importformat', 'finet-troubleshoot' ), 'manage_wpfinet_settings', 'finet-ts-import-format', array( $this, 'import_format_page' ) );
		add_submenu_page( 'wpfinet', __( 'Inställningar', 'finet-troubleshoot' ), __( 'Inställningar', 'finet-troubleshoot' ), 'manage_wpfinet_settings', 'finet-ts-settings', array( $this, 'settings_page' ) );
	}
	private function wpfinet_menu_exists(): bool { global $menu; foreach ( (array) $menu as $item ) { if ( isset( $item[2] ) && 'wpfinet' === $item[2] ) { return true; } } return false; }
	public function dashboard(): void {
		if ( ! current_user_can( 'manage_wpfinet' ) && ! current_user_can( 'manage_options' ) ) { return; }
		( new Dashboard() )->render();
	}
	public function statistics_page(): void {
		if ( ! current_user_can( 'view_wpfinet_statistics' ) && ! current_user_can( 'manage_options' ) ) { return; }
		?>
		<div class="wrap wpfinet-statistics" id="wpfinet-statistics"><h1><?php esc_html_e( 'WPFinet Statistik', 'finet-troubleshoot' ); ?></h1><p><?php esc_html_e( 'Statistik visas endast när besökaren har lämnat statistik-samtycke.', 'finet-troubleshoot' ); ?></p><p><button class="button" data-days="7">7 dagar</button> <button class="button button-primary" data-days="30">30 dagar</button> <button class="button" data-days="90">90 dagar</button></p><div class="wpfinet-statistics__cards" aria-live="polite"></div></div>
		<?php
	}
	/** @return array<string,string> */
	public function columns( array $columns ): array {
		return array( 'cb' => $columns['cb'] ?? '<input type="checkbox" />', 'title' => __( 'Titel', 'finet-troubleshoot' ), 'status' => __( 'Status', 'finet-troubleshoot' ), 'shortcode' => __( 'Shortcode', 'finet-troubleshoot' ), 'steps' => __( 'Antal steg', 'finet-troubleshoot' ), 'start_node' => __( 'Startsteg', 'finet-troubleshoot' ), 'date' => __( 'Senast ändrad', 'finet-troubleshoot' ) );
	}
	public function column_content( string $column, int $post_id ): void {
		if ( 'status' === $column ) { echo esc_html( get_post_status_object( get_post_status( $post_id ) )->label ?? '' ); }
		if ( 'shortcode' === $column ) {
			$shortcode = '[finet_troubleshoot id="' . $post_id . '"]';
			echo '<code class="finet-ts-shortcode">' . esc_html( $shortcode ) . '</code> <button type="button" class="button-link finet-ts-copy" data-copy="' . esc_attr( $shortcode ) . '">' . esc_html__( 'Kopiera shortcode', 'finet-troubleshoot' ) . '</button>';
		}
		if ( 'steps' === $column ) { $nodes = get_post_meta( $post_id, GuidePostType::META_NODES, true ); echo esc_html( is_array( $nodes ) ? (string) count( $nodes ) : '0' ); }
		if ( 'start_node' === $column ) { echo '<code>' . esc_html( (string) get_post_meta( $post_id, GuidePostType::META_START_NODE, true ) ) . '</code>'; }
	}
	/** @return array<string,string> */
	public function row_actions( array $actions, \WP_Post $post ): array {
		if ( GuidePostType::POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) { return $actions; }
		$duplicate = wp_nonce_url( admin_url( 'admin-post.php?action=finet_ts_duplicate&id=' . $post->ID ), 'finet_ts_duplicate_' . $post->ID );
		$export = wp_nonce_url( admin_url( 'admin-post.php?action=finet_ts_export&id=' . $post->ID ), 'finet_ts_export_' . $post->ID );
		$actions['finet_ts_duplicate'] = '<a href="' . esc_url( $duplicate ) . '">' . esc_html__( 'Duplicera', 'finet-troubleshoot' ) . '</a>';
		$actions['finet_ts_export'] = '<a href="' . esc_url( $export ) . '">' . esc_html__( 'Exportera', 'finet-troubleshoot' ) . '</a>';
		return $actions;
	}
	public function duplicate(): void {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'finet_ts_duplicate_' . $id );
		if ( ! current_user_can( 'edit_post', $id ) ) { wp_die( esc_html__( 'Du saknar behörighet att duplicera denna guide.', 'finet-troubleshoot' ) ); }
		$post = get_post( $id );
		if ( ! $post || GuidePostType::POST_TYPE !== $post->post_type ) { wp_die( esc_html__( 'Guiden kunde inte hittas.', 'finet-troubleshoot' ) ); }
		$new = wp_insert_post( array( 'post_type' => GuidePostType::POST_TYPE, 'post_title' => $post->post_title . ' – ' . __( 'Kopia', 'finet-troubleshoot' ), 'post_excerpt' => $post->post_excerpt, 'post_status' => 'draft' ), true );
		if ( is_wp_error( $new ) ) { wp_die( esc_html( $new->get_error_message() ) ); }
		foreach ( array( GuidePostType::META_START_NODE, GuidePostType::META_NODES, GuidePostType::META_SETTINGS, GuidePostType::META_SCHEMA ) as $meta ) { update_post_meta( $new, $meta, get_post_meta( $id, $meta, true ) ); }
		update_post_meta( $new, GuidePostType::META_EXTERNAL_ID, sanitize_title( $post->post_name ) . '-' . wp_generate_password( 8, false, false ) );
		wp_safe_redirect( get_edit_post_link( $new, 'url' ) ); exit;
	}
	public function export(): void {
		$id = absint( $_GET['id'] ?? 0 ); check_admin_referer( 'finet_ts_export_' . $id );
		if ( ! empty( $_GET['all'] ) ) {
			if ( ! current_user_can( 'manage_wpfinet_settings' ) && ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Du saknar behörighet att exportera guider.', 'finet-troubleshoot' ) ); }
			$this->exporter->send_download( $this->exporter->export_all(), 'finet-troubleshoot-export-' . gmdate( 'Y-m-d' ) . '.json' );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) { wp_die( esc_html__( 'Du saknar behörighet att exportera denna guide.', 'finet-troubleshoot' ) ); }
		$guide = $this->exporter->export_guide( $id ); if ( is_wp_error( $guide ) ) { wp_die( esc_html( $guide->get_error_message() ) ); }
		$this->exporter->send_download( $this->exporter->document( array( $guide ) ), sanitize_file_name( $guide['slug'] . '.json' ) );
	}
	public function import_export_page(): void {
		if ( ! current_user_can( 'manage_wpfinet_settings' ) && ! current_user_can( 'manage_options' ) ) { return; } // B15: match the menu capability.
		$example = FINET_TS_URL . 'examples/finet-troubleshoot-example.json';
		$export_all = wp_nonce_url( admin_url( 'admin-post.php?action=finet_ts_export&id=0&all=1' ), 'finet_ts_export_0' );
		?>
		<div class="wrap finet-ts-admin-page" id="finet-ts-import-app" data-example-url="<?php echo esc_url( $example ); ?>">
			<h1><?php esc_html_e( 'Import / Export', 'finet-troubleshoot' ); ?></h1>
			<div class="finet-ts-import-card"><h2><?php esc_html_e( 'Importera felsökningsguider', 'finet-troubleshoot' ); ?></h2><p><?php esc_html_e( 'Välj en JSON-fil och analysera den innan någonting skrivs till databasen.', 'finet-troubleshoot' ); ?></p><input type="file" accept="application/json,.json" class="finet-ts-import-file"><fieldset><legend><?php esc_html_e( 'Konflikthantering', 'finet-troubleshoot' ); ?></legend><label><input type="radio" name="finet-ts-strategy" value="copy" checked> <?php esc_html_e( 'Skapa kopia', 'finet-troubleshoot' ); ?></label><label><input type="radio" name="finet-ts-strategy" value="update"> <?php esc_html_e( 'Uppdatera befintlig guide', 'finet-troubleshoot' ); ?></label><label><input type="radio" name="finet-ts-strategy" value="skip"> <?php esc_html_e( 'Hoppa över befintlig guide', 'finet-troubleshoot' ); ?></label></fieldset><p><button class="button button-primary finet-ts-analyse"><?php esc_html_e( 'Analysera fil', 'finet-troubleshoot' ); ?></button></p><div class="finet-ts-import-result" aria-live="polite"></div></div>
			<div class="finet-ts-import-card"><h2><?php esc_html_e( 'Exportera guider', 'finet-troubleshoot' ); ?></h2><p><?php esc_html_e( 'Exportera en enskild guide från guideöversikten, eller exportera alla guider här.', 'finet-troubleshoot' ); ?></p><a class="button" href="<?php echo esc_url( $export_all ); ?>"><?php esc_html_e( 'Exportera alla guider', 'finet-troubleshoot' ); ?></a></div>
			<div class="finet-ts-import-card"><h2><?php esc_html_e( 'Exempelfil', 'finet-troubleshoot' ); ?></h2><p><?php esc_html_e( 'Exempelfilen innehåller demo-guiden ”Internet fungerar inte”.', 'finet-troubleshoot' ); ?></p><a class="button" href="<?php echo esc_url( $example ); ?>" download><?php esc_html_e( 'Ladda ner exempel', 'finet-troubleshoot' ); ?></a></div>
			<div class="finet-ts-import-card"><h2><?php esc_html_e( 'Så fungerar importformatet', 'finet-troubleshoot' ); ?></h2><p><?php esc_html_e( 'Finet Troubleshoot använder JSON-filer. En fil kan innehålla en eller flera guider. Varje guide består av noder och länkar mellan noderna.', 'finet-troubleshoot' ); ?></p><a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=finet-ts-import-format' ) ); ?>"><?php esc_html_e( 'Visa formatdokumentation', 'finet-troubleshoot' ); ?></a></div>
		</div>
		<?php
	}
	public function import_format_page(): void {
		if ( ! current_user_can( 'manage_wpfinet_settings' ) && ! current_user_can( 'manage_options' ) ) { return; }
		?>
		<div class="wrap"><h1><?php esc_html_e( 'WPFinet importformat', 'finet-troubleshoot' ); ?></h1><p><?php esc_html_e( 'Import accepterar schema 1.0 och 2.0. Nya exporter använder schema 2.0.', 'finet-troubleshoot' ); ?></p><h2><?php esc_html_e( 'Grundformat', 'finet-troubleshoot' ); ?></h2><pre>{
  "schema": "finet-troubleshoot",
  "schema_version": "2.0",
  "guides": [],
  "outages": []
}</pre><p><?php esc_html_e( 'Varje guide behöver external_id, title, slug, start_node och nodes. Tillåtna nodtyper är information, action, question, check, result, support, outage och wpforms. En driftstörning använder stabilt external_id och kan innehålla titel, status, tidslinje, taxonomier och tidpunkter.', 'finet-troubleshoot' ); ?></p><p><?php esc_html_e( 'Den fullständiga, versionshanterade Markdown-dokumentationen och JSON-schemat finns i pluginmappen under docs/ för utvecklare och distribution.', 'finet-troubleshoot' ); ?></p></div>
		<?php
	}
	public function settings_page(): void {
		if ( ! current_user_can( 'manage_wpfinet_settings' ) && ! current_user_can( 'manage_options' ) ) { return; }
		$s = array_replace_recursive( Activator::default_settings(), (array) get_option( 'finet_ts_settings', array() ) ); $tab = sanitize_key( $_GET['tab'] ?? 'general' );
		?>
		<div class="wrap"><h1><?php esc_html_e( 'WPFinet – Inställningar', 'finet-troubleshoot' ); ?></h1><nav class="nav-tab-wrapper"><?php foreach ( array( 'general' => __( 'Allmänt', 'finet-troubleshoot' ), 'appearance' => __( 'Utseende', 'finet-troubleshoot' ), 'support' => __( 'Support', 'finet-troubleshoot' ), 'outages' => __( 'Driftstörningar', 'finet-troubleshoot' ), 'analytics' => __( 'Statistik', 'finet-troubleshoot' ), 'system' => __( 'Systemstatus', 'finet-troubleshoot' ), 'advanced' => __( 'Avancerat', 'finet-troubleshoot' ) ) as $key => $label ) : ?><a class="nav-tab <?php echo $key === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=finet-ts-settings&tab=' . $key ) ); ?>"><?php echo esc_html( $label ); ?></a><?php endforeach; ?></nav><?php if ( 'system' === $tab ) : ?><table class="widefat striped"><tbody><tr><td><?php esc_html_e( 'Pluginversion', 'finet-troubleshoot' ); ?></td><td><?php echo esc_html( FINET_TS_VERSION ); ?></td></tr><tr><td><?php esc_html_e( 'Databasversion', 'finet-troubleshoot' ); ?></td><td><?php echo esc_html( (string) get_option( 'finet_ts_db_version', '–' ) ); ?></td></tr><tr><td><?php esc_html_e( 'WP Consent API / WPConsent', 'finet-troubleshoot' ); ?></td><td><?php echo function_exists( 'wp_has_consent' ) ? esc_html__( 'Tillgänglig', 'finet-troubleshoot' ) : esc_html__( 'Inte upptäckt', 'finet-troubleshoot' ); ?></td></tr><tr><td><?php esc_html_e( 'WPForms', 'finet-troubleshoot' ); ?></td><td><?php echo post_type_exists( 'wpforms' ) ? esc_html__( 'Tillgänglig', 'finet-troubleshoot' ) : esc_html__( 'Inte upptäckt', 'finet-troubleshoot' ); ?></td></tr></tbody></table><?php else : ?><form action="options.php" method="post"><?php settings_fields( 'finet_ts_settings' ); ?><input type="hidden" name="finet_ts_settings[_tab]" value="<?php echo esc_attr( $tab ); ?>"><?php $this->settings_fields( $tab, $s ); submit_button(); ?></form><?php endif; ?><?php if ( 'advanced' === $tab ) : ?><hr><form action="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=finet_ts_reinstall_demo' ), 'finet_ts_reinstall_demo' ) ); ?>" method="post"><button class="button"><?php esc_html_e( 'Skapa demo-guide på nytt', 'finet-troubleshoot' ); ?></button></form><?php endif; ?></div>
		<?php
	}
	private function settings_fields( string $tab, array $s ): void {
		if ( 'general' === $tab ) { $g = $s['general']; ?> <table class="form-table"><tr><th><?php esc_html_e( 'Visa guide-rubrik', 'finet-troubleshoot' ); ?></th><td><input type="checkbox" name="finet_ts_settings[general][show_title]" value="1" <?php checked( ! empty( $g['show_title'] ) ); ?>></td></tr><tr><th><?php esc_html_e( 'Visa tillbaka', 'finet-troubleshoot' ); ?></th><td><input type="checkbox" name="finet_ts_settings[general][show_back]" value="1" <?php checked( ! empty( $g['show_back'] ) ); ?>></td></tr><tr><th><?php esc_html_e( 'Visa börja om', 'finet-troubleshoot' ); ?></th><td><input type="checkbox" name="finet_ts_settings[general][show_restart]" value="1" <?php checked( ! empty( $g['show_restart'] ) ); ?>></td></tr><tr><th><?php esc_html_e( 'Progressläge', 'finet-troubleshoot' ); ?></th><td><select name="finet_ts_settings[general][progress_mode]"><option value="none" <?php selected( $g['progress_mode'], 'none' ); ?>><?php esc_html_e( 'Ingen', 'finet-troubleshoot' ); ?></option><option value="step" <?php selected( $g['progress_mode'], 'step' ); ?>><?php esc_html_e( 'Endast stegnummer', 'finet-troubleshoot' ); ?></option><option value="bar" <?php selected( $g['progress_mode'], 'bar' ); ?>><?php esc_html_e( 'Progressbar där möjligt', 'finet-troubleshoot' ); ?></option></select></td></tr><tr><th><?php esc_html_e( 'Standardanimation', 'finet-troubleshoot' ); ?></th><td><select name="finet_ts_settings[general][animation]"><?php foreach ( array( 'fade', 'slide', 'none' ) as $v ) : ?><option value="<?php echo esc_attr( $v ); ?>" <?php selected( $g['animation'], $v ); ?>><?php echo esc_html( ucfirst( $v ) ); ?></option><?php endforeach; ?></select></td></tr></table><?php }
		if ( 'appearance' === $tab ) { $a = $s['appearance']; ?> <table class="form-table"><tr><th><?php esc_html_e( 'Designpreset', 'finet-troubleshoot' ); ?></th><td><select name="finet_ts_settings[appearance][preset]"><option value="inherit" <?php selected( $a['preset'], 'inherit' ); ?>><?php esc_html_e( 'Ärv tema', 'finet-troubleshoot' ); ?></option><option value="wpfinet" <?php selected( $a['preset'], 'wpfinet' ); ?>>WPFinet</option><option value="custom" <?php selected( $a['preset'], 'custom' ); ?>><?php esc_html_e( 'Anpassad', 'finet-troubleshoot' ); ?></option></select></td></tr><tr><th><?php esc_html_e( 'Primärfärg', 'finet-troubleshoot' ); ?></th><td><input type="color" name="finet_ts_settings[appearance][primary_color]" value="<?php echo esc_attr( $a['primary_color'] ); ?>"></td></tr><tr><th><?php esc_html_e( 'Bakgrund', 'finet-troubleshoot' ); ?></th><td><input class="regular-text" name="finet_ts_settings[appearance][background]" value="<?php echo esc_attr( $a['background'] ); ?>"></td></tr><tr><th><?php esc_html_e( 'Kortbakgrund', 'finet-troubleshoot' ); ?></th><td><input class="regular-text" name="finet_ts_settings[appearance][card_background]" value="<?php echo esc_attr( $a['card_background'] ); ?>"></td></tr><tr><th><?php esc_html_e( 'Kantfärg', 'finet-troubleshoot' ); ?></th><td><input type="color" name="finet_ts_settings[appearance][border_color]" value="<?php echo esc_attr( $a['border_color'] ); ?>"></td></tr><tr><th><?php esc_html_e( 'Border radius', 'finet-troubleshoot' ); ?></th><td><input type="number" min="0" max="48" name="finet_ts_settings[appearance][radius]" value="<?php echo esc_attr( (string) $a['radius'] ); ?>"> px</td></tr><tr><th><?php esc_html_e( 'Spacing', 'finet-troubleshoot' ); ?></th><td><input type="number" min="8" max="64" name="finet_ts_settings[appearance][spacing]" value="<?php echo esc_attr( (string) $a['spacing'] ); ?>"> px</td></tr><tr><th><?php esc_html_e( 'Maxbredd', 'finet-troubleshoot' ); ?></th><td><input type="number" min="280" max="1440" name="finet_ts_settings[appearance][max_width]" value="<?php echo esc_attr( (string) $a['max_width'] ); ?>"> px</td></tr></table><?php }
		if ( 'support' === $tab ) { $p = $s['support']; ?> <table class="form-table"><?php foreach ( array( 'company' => __( 'Företagsnamn', 'finet-troubleshoot' ), 'phone' => __( 'Supporttelefon', 'finet-troubleshoot' ), 'email' => __( 'Supportmail', 'finet-troubleshoot' ), 'url' => __( 'Supportsida URL', 'finet-troubleshoot' ), 'outage_url' => __( 'Driftinformation URL', 'finet-troubleshoot' ), 'opening_hours' => __( 'Öppettider', 'finet-troubleshoot' ) ) as $key => $label ) : ?><tr><th><?php echo esc_html( $label ); ?></th><td><input class="regular-text" name="finet_ts_settings[support][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $p[ $key ] ); ?>"></td></tr><?php endforeach; ?><tr><th><?php esc_html_e( 'Standardtext', 'finet-troubleshoot' ); ?></th><td><textarea class="large-text" rows="4" name="finet_ts_settings[support][text]"><?php echo esc_textarea( $p['text'] ); ?></textarea></td></tr></table><?php }
		if ( 'outages' === $tab ) { $o = $s['outages']; ?> <table class="form-table"><tr><th><?php esc_html_e( 'Visa driftbanner', 'finet-troubleshoot' ); ?></th><td><label><input type="checkbox" name="finet_ts_settings[outages][banner_enabled]" value="1" <?php checked( ! empty( $o['banner_enabled'] ) ); ?>> <?php esc_html_e( 'Visa banner vid aktiva driftstörningar.', 'finet-troubleshoot' ); ?></label></td></tr><tr><th><?php esc_html_e( 'Statussida URL', 'finet-troubleshoot' ); ?></th><td><input class="regular-text" name="finet_ts_settings[outages][status_url]" value="<?php echo esc_attr( $o['status_url'] ); ?>" placeholder="<?php echo esc_attr( home_url( '/driftstorningar/' ) ); ?>"><p class="description"><?php esc_html_e( 'Sidan som driftbannerns "+N till" och [wpfinet_outage_status] länkar till, t.ex. sidan med [wpfinet_outages]. Tomt = arkivet /driftstorningar/.', 'finet-troubleshoot' ); ?></p></td></tr></table><?php }
		if ( 'analytics' === $tab ) { $a = $s['analytics']; ?> <table class="form-table"><tr><th><?php esc_html_e( 'Aktivera statistik', 'finet-troubleshoot' ); ?></th><td><label><input type="checkbox" name="finet_ts_settings[analytics][enabled]" value="1" <?php checked( ! empty( $a['enabled'] ) ); ?>> <?php esc_html_e( 'Lagra enbart anonym, samtyckesstyrd händelsestatistik.', 'finet-troubleshoot' ); ?></label></td></tr><tr><th><?php esc_html_e( 'Sessionsgräns', 'finet-troubleshoot' ); ?></th><td><input type="number" min="5" max="240" name="finet_ts_settings[analytics][session_timeout_minutes]" value="<?php echo esc_attr( (string) $a['session_timeout_minutes'] ); ?>"> <?php esc_html_e( 'minuter', 'finet-troubleshoot' ); ?></td></tr><tr><th><?php esc_html_e( 'Lagringstid', 'finet-troubleshoot' ); ?></th><td><input type="number" min="0" max="1825" name="finet_ts_settings[analytics][retention_days]" value="<?php echo esc_attr( (string) $a['retention_days'] ); ?>"> <?php esc_html_e( 'dagar (0 = obegränsat)', 'finet-troubleshoot' ); ?></td></tr></table><?php }
		if ( 'advanced' === $tab ) { $a = $s['advanced']; ?> <table class="form-table"><tr><th><?php esc_html_e( 'Radera data vid avinstallation', 'finet-troubleshoot' ); ?></th><td><label><input type="checkbox" name="finet_ts_settings[advanced][delete_data]" value="1" <?php checked( ! empty( $a['delete_data'] ) ); ?>> <?php esc_html_e( 'Radera endast vid riktig avinstallation, aldrig vid avaktivering.', 'finet-troubleshoot' ); ?></label></td></tr><tr><th><?php esc_html_e( 'Debugläge', 'finet-troubleshoot' ); ?></th><td><input type="checkbox" name="finet_ts_settings[advanced][debug]" value="1" <?php checked( ! empty( $a['debug'] ) ); ?>></td></tr></table><?php }
	}
	public function activation_notice(): void { if ( get_transient( 'finet_ts_activation_notice' ) && current_user_can( 'manage_options' ) ) { echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Finet Troubleshoot är installerat. En demo-guide har skapats så att du snabbt kan se hur systemet fungerar.', 'finet-troubleshoot' ) . '</p><p><a class="button" href="' . esc_url( admin_url( 'edit.php?post_type=' . GuidePostType::POST_TYPE ) ) . '">' . esc_html__( 'Öppna demo-guiden', 'finet-troubleshoot' ) . '</a> <a class="button" href="' . esc_url( admin_url( 'post-new.php?post_type=' . GuidePostType::POST_TYPE ) ) . '">' . esc_html__( 'Skapa ny guide', 'finet-troubleshoot' ) . '</a></p></div>'; delete_transient( 'finet_ts_activation_notice' ); } }
	public function dismiss_notice(): void {
		if ( ! empty( $_GET['action'] ) && 'finet_ts_reinstall_demo' === $_GET['action'] ) {
			check_admin_referer( 'finet_ts_reinstall_demo' ); if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Otillåten åtgärd.', 'finet-troubleshoot' ) ); }
			$existing = get_posts( array( 'post_type' => GuidePostType::POST_TYPE, 'post_status' => array( 'publish', 'draft', 'private', 'pending', 'future' ), 'meta_key' => GuidePostType::META_EXTERNAL_ID, 'meta_value' => 'internet-fungerar-inte', 'fields' => 'ids', 'posts_per_page' => 1, 'no_found_rows' => true ) );
			if ( ! empty( $existing ) ) { wp_safe_redirect( add_query_arg( 'finet_ts_demo', 'exists', admin_url( 'admin.php?page=finet-ts-settings&tab=advanced' ) ) ); exit; }
			$path = FINET_TS_DIR . 'examples/finet-troubleshoot-example.json'; $data = json_decode( (string) file_get_contents( $path ), true ); $result = is_array( $data ) ? $this->importer->import_data( $data, 'copy' ) : null;
			wp_safe_redirect( add_query_arg( 'finet_ts_demo', $result && $result->imported_count() ? 'created' : 'exists', admin_url( 'admin.php?page=finet-ts-settings&tab=advanced' ) ) ); exit;
		}
	}
}
