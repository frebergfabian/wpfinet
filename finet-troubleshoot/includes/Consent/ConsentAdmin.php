<?php
/**
 * Admin page WPFINET → Cookies & samtycke: overview, inventory review, blocking rules, banner texts, policy
 * versions and scanner instructions/results. All write actions are nonce + capability protected.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Consent;

use FinetTroubleshoot\Admin\View;
use FinetTroubleshoot\Core\Logger;
use FinetTroubleshoot\Core\Modules;

final class ConsentAdmin {
	public static function register_hooks(): void {
		foreach ( array( 'save', 'item', 'add', 'defaults', 'policy', 'approve', 'export' ) as $a ) {
			add_action( 'admin_post_wpfinet_consent_' . $a, array( self::class, 'handle_' . $a ) );
		}
	}

	private static function guard( string $a ): void {
		check_admin_referer( 'wpfinet_consent_' . $a );
		if ( ! current_user_can( 'manage_wpfinet_settings' ) && ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'Du saknar behörighet.', 'finet-troubleshoot' ), 403 ); }
	}

	private static function back( string $tab, string $notice ): void {
		wp_safe_redirect( add_query_arg( array( 'tab' => $tab, 'wpfinet_consent' => $notice ), admin_url( 'admin.php?page=wpfinet-consent' ) ) );
		exit;
	}

	public static function handle_save(): void {
		self::guard( 'save' );
		$in = isset( $_POST['consent'] ) && is_array( $_POST['consent'] ) ? wp_unslash( $_POST['consent'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in ConsentSettings::update().
		ConsentSettings::update( $in );
		Logger::log( 'consent', 'info', 'Samtyckesinställningar ändrade.', array( 'user' => get_current_user_id() ) );
		self::back( sanitize_key( (string) ( $_POST['tab'] ?? 'banner' ) ), 'saved' ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	public static function handle_item(): void {
		self::guard( 'item' );
		$id = absint( $_POST['id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		Inventory::update_item( $id, isset( $_POST['item'] ) && is_array( $_POST['item'] ) ? wp_unslash( $_POST['item'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in update_item().
		Logger::log( 'consent', 'info', 'Inventariepost #' . $id . ' granskad/ändrad.', array( 'user' => get_current_user_id() ) );
		self::back( 'inventory', 'item_saved' );
	}

	public static function handle_add(): void {
		self::guard( 'add' );
		$in = isset( $_POST['item'] ) && is_array( $_POST['item'] ) ? wp_unslash( $_POST['item'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		Inventory::observe( array( 'name' => $in['name'] ?? '', 'domain' => $in['domain'] ?? '', 'storage_type' => $in['storage_type'] ?? 'cookie', 'party' => $in['party'] ?? 'first', 'lifetime' => $in['lifetime'] ?? '', 'source' => 'manual' ) );
		self::back( 'inventory', 'item_saved' );
	}

	public static function handle_defaults(): void {
		self::guard( 'defaults' );
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( array( array( 'wpfinet_consent', 'cookie' ), array( 'wpfinet_analytics_session', 'sessionStorage' ), array( 'wpfinet_banner_dismissed_*', 'sessionStorage' ) ) as [ $name, $type ] ) {
			Inventory::observe( array( 'name' => $name, 'domain' => $host, 'storage_type' => $type, 'source' => 'default' ) );
		}
		self::back( 'inventory', 'item_saved' );
	}

	public static function handle_policy(): void {
		self::guard( 'policy' );
		Policy::save_draft( isset( $_POST['intro'] ) ? (string) wp_unslash( $_POST['intro'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- kses in save_draft().
		self::back( 'policy', 'saved' );
	}

	public static function handle_approve(): void {
		self::guard( 'approve' );
		if ( empty( $_POST['reviewed'] ) ) { self::back( 'policy', 'not_reviewed' ); } // phpcs:ignore WordPress.Security.NonceVerification
		$v = Policy::approve( get_current_user_id(), isset( $_POST['note'] ) ? sanitize_text_field( wp_unslash( $_POST['note'] ) ) : '' );
		Logger::log( 'consent', 'info', 'Cookiepolicy version ' . $v['version'] . ' godkänd.', array( 'user' => get_current_user_id(), 'items' => count( $v['items'] ) ) );
		self::back( 'policy', 'approved' );
	}

	public static function handle_export(): void {
		self::guard( 'export' );
		$format = sanitize_key( (string) ( $_POST['format'] ?? 'json' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$rows   = array_map( static fn ( $r ) => array_intersect_key( $r, array_flip( array( 'name', 'domain', 'storage_type', 'party', 'category', 'suggested_category', 'provider', 'purpose', 'lifetime', 'source', 'status', 'before_consent', 'first_seen', 'last_seen' ) ) ), Inventory::all() );
		nocache_headers();
		if ( 'csv' === $format ) {
			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename="wpfinet-cookieinventarium-' . gmdate( 'Y-m-d' ) . '.csv"' );
			$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			fputcsv( $out, array_keys( $rows[0] ?? array( 'name' => '' ) ), ';' );
			foreach ( $rows as $r ) { fputcsv( $out, array_map( static fn ( $v ) => preg_replace( '/^[=+\-@]/', "'$0", (string) $v ), $r ), ';' ); }
			exit;
		}
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wpfinet-samtycke-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( array( 'generated_at' => gmdate( 'c' ), 'inventory' => $rows, 'policy_versions' => array_map( static fn ( $v ) => array_diff_key( $v, array( 'items' => 1 ) ) + array( 'items' => count( $v['items'] ) ), Policy::data()['versions'] ), 'scans' => array_map( static fn ( $s ) => array_diff_key( $s, array( 'keys' => 1 ) ), (array) get_option( ScanReceiver::OPTION, array() ) ), 'audit_log' => Logger::entries( 'consent' ), 'choices_30d' => Inventory::log_summary( 30 ) ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	private static function form( string $a ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wpfinet_consent_' . $a );
		echo '<input type="hidden" name="action" value="wpfinet_consent_' . esc_attr( $a ) . '">';
	}

	public static function render(): void {
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview'; // phpcs:ignore WordPress.Security.NonceVerification
		$note = isset( $_GET['wpfinet_consent'] ) ? sanitize_key( wp_unslash( $_GET['wpfinet_consent'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$tabs = array( 'overview' => __( 'Översikt', 'finet-troubleshoot' ), 'inventory' => __( 'Inventarium', 'finet-troubleshoot' ), 'blocking' => __( 'Blockering', 'finet-troubleshoot' ), 'banner' => __( 'Banner', 'finet-troubleshoot' ), 'policy' => __( 'Cookiepolicy', 'finet-troubleshoot' ), 'scan' => __( 'Skanning', 'finet-troubleshoot' ) );
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'overview';
		View::open( __( 'Cookies & samtycke', 'finet-troubleshoot' ), __( 'Verktyg för inventering, förhandsblockering och samtycke. WPFINET påstår inte att webbplatsen uppfyller GDPR/LEK – det kräver verifierad skanning, verifierad blockering och juridisk granskning.', 'finet-troubleshoot' ) );
		$msgs = array( 'saved' => __( 'Sparat.', 'finet-troubleshoot' ), 'item_saved' => __( 'Inventariet är uppdaterat.', 'finet-troubleshoot' ), 'approved' => __( 'Ny policyversion är godkänd och publicerad.', 'finet-troubleshoot' ) );
		if ( 'not_reviewed' === $note ) { View::notice( 'error', __( 'Bekräfta att policyn är granskad innan den godkänns.', 'finet-troubleshoot' ) ); } elseif ( isset( $msgs[ $note ] ) ) { View::notice( 'success', $msgs[ $note ] ); }
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $l ) { echo '<a class="nav-tab ' . ( $k === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( admin_url( 'admin.php?page=wpfinet-consent&tab=' . $k ) ) . '"' . ( $k === $tab ? ' aria-current="page"' : '' ) . '>' . esc_html( $l ) . '</a>'; }
		echo '</nav>';
		call_user_func( array( self::class, 'tab_' . $tab ) );
		View::close();
	}

	private static function tab_overview(): void {
		$mode   = Modules::consent_mode();
		$counts = Inventory::counts();
		$scan   = ScanReceiver::last();
		$wpc    = defined( 'WPCONSENT_VERSION' ) || defined( 'WPCONSENT_PLUGIN_FILE' ) || class_exists( 'WPConsent' );
		echo '<div class="wpf-kpis">';
		View::kpi( array( 'off' => __( 'Av', 'finet-troubleshoot' ), 'preview' => __( 'Testläge', 'finet-troubleshoot' ), 'live' => __( 'Live', 'finet-troubleshoot' ) )[ $mode ], __( 'WPFINET-samtycke', 'finet-troubleshoot' ) );
		View::kpi( (string) $counts['verified'], __( 'verifierade poster', 'finet-troubleshoot' ) );
		View::kpi( (string) $counts['suggested'], __( 'att granska', 'finet-troubleshoot' ) );
		View::kpi( (string) $counts['leaks'], __( 'icke-nödvändiga före samtycke', 'finet-troubleshoot' ) );
		View::kpi( Policy::version(), __( 'publicerad policyversion', 'finet-troubleshoot' ) );
		echo '</div>';
		if ( $counts['leaks'] ) { View::notice( 'error', __( 'Skanningen har sett icke-nödvändiga cookies/tjänster innan samtycke. Granska under Inventarium och komplettera blockeringsreglerna.', 'finet-troubleshoot' ) ); }
		echo '<div class="wpf-grid">';
		View::card_open( __( 'CMP-status', 'finet-troubleshoot' ) );
		echo '<dl class="wpf-dl"><dt>WPConsent</dt><dd>' . ( $wpc ? View::badge( __( 'Aktivt – skyddar besökare', 'finet-troubleshoot' ), 'ok' ) : View::badge( __( 'Inte aktivt', 'finet-troubleshoot' ), 'muted' ) ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<dt>WP Consent API</dt><dd>' . ( function_exists( 'wp_has_consent' ) ? View::badge( __( 'Finns – WPFINET skriver samtycke dit', 'finet-troubleshoot' ), 'ok' ) : View::badge( __( 'Saknas', 'finet-troubleshoot' ), 'muted' ) ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<dt>' . esc_html__( 'Statistikens samtyckeskälla', 'finet-troubleshoot' ) . '</dt><dd>' . esc_html( ConsentService::instance()->provider() ) . '</dd></dl>';
		if ( 'live' === $mode && $wpc ) { View::notice( 'error', __( 'Både WPConsent och WPFINET är live – besökare kan se två banners. Inaktivera WPConsent.', 'finet-troubleshoot' ) ); }
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=wpfinet-integrations' ) ) . '">' . esc_html__( 'Ändra läge', 'finet-troubleshoot' ) . '</a></p>';
		View::card_close();
		View::card_open( __( 'Senaste skanning', 'finet-troubleshoot' ) );
		if ( $scan ) {
			echo '<dl class="wpf-dl"><dt>' . esc_html__( 'Mottagen', 'finet-troubleshoot' ) . '</dt><dd>' . esc_html( (string) $scan['received_at'] ) . '</dd><dt>' . esc_html__( 'Sidor', 'finet-troubleshoot' ) . '</dt><dd>' . count( (array) $scan['pages'] ) . '</dd><dt>' . esc_html__( 'Observationer', 'finet-troubleshoot' ) . '</dt><dd>' . (int) $scan['observations'] . '</dd><dt>' . esc_html__( 'Nya sedan förra', 'finet-troubleshoot' ) . '</dt><dd>' . esc_html( implode( ', ', (array) $scan['new'] ) ?: '–' ) . '</dd><dt>' . esc_html__( 'Försvunna sedan förra', 'finet-troubleshoot' ) . '</dt><dd>' . esc_html( implode( ', ', (array) $scan['disappeared'] ) ?: '–' ) . '</dd></dl>';
			if ( $scan['warnings'] ) { echo '<ul>'; foreach ( (array) $scan['warnings'] as $w ) { echo '<li>' . esc_html( $w ) . '</li>'; } echo '</ul>'; }
		} else {
			echo '<p>' . esc_html__( 'Ingen skanning har rapporterats ännu. Se fliken Skanning.', 'finet-troubleshoot' ) . '</p>';
		}
		View::card_close();
		echo '</div>';
		$choices = Inventory::log_summary( 30 );
		if ( $choices ) { View::card_open( __( 'Val senaste 30 dagarna (anonymt)', 'finet-troubleshoot' ) ); echo '<p>' . esc_html( implode( ' · ', array_map( static fn ( $k, $v ) => "$k: $v", array_keys( $choices ), $choices ) ) ) . '</p>'; View::card_close(); }
		self::form( 'export' );
		echo '<div class="wpf-actions"><select name="format"><option value="json">JSON (inventarium, policyversioner, skanningar, granskningslogg)</option><option value="csv">CSV (inventarium)</option></select><button class="button">' . esc_html__( 'Exportera', 'finet-troubleshoot' ) . '</button></div></form>';
	}

	private static function tab_inventory(): void {
		$cats = array( '' => __( '– välj –', 'finet-troubleshoot' ) ) + ConsentSettings::CATEGORY_LABELS;
		echo '<p>' . esc_html__( 'Fynd från skannern är förslag. En post publiceras i cookiepolicyn först när den har en kategori och status "verifierad". Skannern hittar inte allt (inloggning, interaktioner, iframes, geostyrda skript) – lägg till sådant manuellt.', 'finet-troubleshoot' ) . '</p>';
		foreach ( array( 'suggested' => __( 'Att granska', 'finet-troubleshoot' ), 'verified' => __( 'Verifierade', 'finet-troubleshoot' ), 'ignored' => __( 'Ignorerade', 'finet-troubleshoot' ) ) as $status => $label ) {
			$rows = Inventory::all( $status );
			View::card_open( $label . ' (' . count( $rows ) . ')' );
			if ( ! $rows ) { echo '<p class="wpf-muted">' . esc_html__( 'Inga poster.', 'finet-troubleshoot' ) . '</p>'; View::card_close(); continue; }
			echo '<table class="wpf-table"><thead><tr><th>' . esc_html__( 'Namn / typ', 'finet-troubleshoot' ) . '</th><th>' . esc_html__( 'Observerad', 'finet-troubleshoot' ) . '</th><th>' . esc_html__( 'Granskning', 'finet-troubleshoot' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				echo '<tr><td><code>' . esc_html( $r['name'] ) . '</code><br><span class="wpf-muted">' . esc_html( $r['storage_type'] . ' · ' . ( $r['domain'] ?: '–' ) . ' · ' . ( 'third' === $r['party'] ? __( 'tredje part', 'finet-troubleshoot' ) : __( 'förstapart', 'finet-troubleshoot' ) ) . ' · ' . $r['source'] ) . '</span>' . ( $r['before_consent'] ? '<br>' . View::badge( __( 'sedd före samtycke', 'finet-troubleshoot' ), 'necessary' === ( $r['category'] ?: $r['suggested_category'] ) ? 'muted' : 'error' ) : '' ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput
				echo '<td>' . esc_html( (string) $r['last_seen'] ) . '<br><span class="wpf-muted">' . esc_html( implode( ', ', array_slice( array_filter( explode( "\n", (string) $r['pages'] ) ), 0, 3 ) ) ) . '</span></td><td>';
				self::form( 'item' );
				echo '<input type="hidden" name="id" value="' . (int) $r['id'] . '"><div class="wpf-actions">';
				echo '<label>' . esc_html__( 'Kategori', 'finet-troubleshoot' ) . ' <select name="item[category]">';
				foreach ( $cats as $k => $l ) { echo '<option value="' . esc_attr( $k ) . '" ' . selected( $r['category'] ?: '', $k, false ) . '>' . esc_html( $l . ( $k && $k === $r['suggested_category'] && ! $r['category'] ? ' (förslag)' : '' ) ) . '</option>'; }
				echo '</select></label><label>' . esc_html__( 'Status', 'finet-troubleshoot' ) . ' <select name="item[status]">';
				foreach ( array( 'suggested' => __( 'Förslag', 'finet-troubleshoot' ), 'verified' => __( 'Verifierad', 'finet-troubleshoot' ), 'ignored' => __( 'Ignorera', 'finet-troubleshoot' ) ) as $k => $l ) { echo '<option value="' . esc_attr( $k ) . '" ' . selected( $r['status'], $k, false ) . '>' . esc_html( $l ) . '</option>'; }
				echo '</select></label></div><input class="regular-text" name="item[provider]" value="' . esc_attr( $r['provider'] ) . '" placeholder="' . esc_attr__( 'Leverantör', 'finet-troubleshoot' ) . '" aria-label="' . esc_attr__( 'Leverantör', 'finet-troubleshoot' ) . '"> <input class="regular-text" name="item[lifetime]" value="' . esc_attr( $r['lifetime'] ) . '" placeholder="' . esc_attr__( 'Lagringstid', 'finet-troubleshoot' ) . '" aria-label="' . esc_attr__( 'Lagringstid', 'finet-troubleshoot' ) . '"><br><textarea class="large-text" rows="2" name="item[purpose]" aria-label="' . esc_attr__( 'Syfte', 'finet-troubleshoot' ) . '" placeholder="' . esc_attr__( 'Syfte', 'finet-troubleshoot' ) . '">' . esc_textarea( (string) $r['purpose'] ) . '</textarea><button class="button">' . esc_html__( 'Spara', 'finet-troubleshoot' ) . '</button></form></td></tr>';
			}
			echo '</tbody></table>';
			View::card_close();
		}
		View::card_open( __( 'Lägg till manuellt', 'finet-troubleshoot' ) );
		self::form( 'add' );
		echo '<div class="wpf-actions"><input name="item[name]" placeholder="' . esc_attr__( 'Namn (t.ex. _ga eller *.vimeo.com)', 'finet-troubleshoot' ) . '" required aria-label="' . esc_attr__( 'Namn', 'finet-troubleshoot' ) . '"><input name="item[domain]" placeholder="' . esc_attr__( 'Domän', 'finet-troubleshoot' ) . '" aria-label="' . esc_attr__( 'Domän', 'finet-troubleshoot' ) . '"><select name="item[storage_type]" aria-label="' . esc_attr__( 'Typ', 'finet-troubleshoot' ) . '"><option value="cookie">cookie</option><option value="localStorage">localStorage</option><option value="sessionStorage">sessionStorage</option><option value="service">' . esc_html__( 'tjänst/anrop', 'finet-troubleshoot' ) . '</option></select><select name="item[party]" aria-label="' . esc_attr__( 'Part', 'finet-troubleshoot' ) . '"><option value="first">' . esc_html__( 'förstapart', 'finet-troubleshoot' ) . '</option><option value="third">' . esc_html__( 'tredje part', 'finet-troubleshoot' ) . '</option></select><button class="button">' . esc_html__( 'Lägg till som förslag', 'finet-troubleshoot' ) . '</button></div></form>';
		self::form( 'defaults' );
		echo '<p><button class="button">' . esc_html__( 'Lägg till WPFINET:s egna lagringar som förslag', 'finet-troubleshoot' ) . '</button></p></form>';
		View::card_close();
	}

	private static function settings_form_open( string $tab ): void {
		self::form( 'save' );
		echo '<input type="hidden" name="tab" value="' . esc_attr( $tab ) . '"><input type="hidden" name="consent[_form]" value="1">';
	}

	private static function tab_blocking(): void {
		$s = ConsentSettings::get();
		View::card_open( __( 'Förhandsblockering', 'finet-troubleshoot' ) );
		echo '<p>' . esc_html__( 'Skript, iframes och spårningspixlar som matchar en regel skrivs om på servern innan sidan skickas (type="text/plain" respektive data-src) och aktiveras först efter samtycke. Omskrivningen är likadan för alla besökare och fungerar därför med Cloudflare- och sidcache. Formatet är "mönster | kategori | etikett", där kategori är preferences, statistics eller marketing.', 'finet-troubleshoot' ) . '</p>';
		echo '<div class="wpf-missing"><strong>' . esc_html__( 'Tekniska begränsningar', 'finet-troubleshoot' ) . '</strong><ul><li>' . esc_html__( 'Skript som ett icke blockerat skript laddar in i efterhand kan inte fångas. Blockera i stället laddaren (t.ex. Google Tag Manager).', 'finet-troubleshoot' ) . '</li><li>' . esc_html__( 'Cookies som sätts av servern (PHP/andra tillägg) eller av Cloudflare blockeras inte av detta. Kontrollera dem i skanningen.', 'finet-troubleshoot' ) . '</li><li>' . esc_html__( 'Vid återkallelse raderas kända förstapartscookies och sidan laddas om. Uppgifter som redan skickats till tredje part kan inte hämtas tillbaka.', 'finet-troubleshoot' ) . '</li></ul></div>';
		self::settings_form_open( 'blocking' );
		foreach ( array( 'rules' => __( 'Regler för extern källa (src för script/iframe/img)', 'finet-troubleshoot' ), 'inline_rules' => __( 'Regler för inline-skript (innehåller text)', 'finet-troubleshoot' ), 'handle_rules' => __( 'WordPress script-handles (exakt namn | kategori)', 'finet-troubleshoot' ), 'cookie_cleanup' => __( 'Cookies att radera vid återkallelse (namn med * | kategori)', 'finet-troubleshoot' ) ) as $k => $l ) {
			echo '<div class="wpf-field"><label class="wpf-field__label" for="wpf-c-' . esc_attr( $k ) . '">' . esc_html( $l ) . '</label><textarea id="wpf-c-' . esc_attr( $k ) . '" class="large-text code" rows="8" name="consent[' . esc_attr( $k ) . ']">' . esc_textarea( (string) $s[ $k ] ) . '</textarea></div>';
		}
		submit_button( __( 'Spara regler', 'finet-troubleshoot' ) );
		echo '</form>';
		View::card_close();
	}

	private static function tab_banner(): void {
		$s = ConsentSettings::get();
		View::card_open( __( 'Banner och texter', 'finet-troubleshoot' ) );
		echo '<p>' . esc_html__( 'Första besöket: bara nödvändiga cookies, inga förkryssade kategorier. Knapparna "Acceptera alla", "Neka valfria" och "Anpassa" har samma utseende och nivå. "Cookieinställningar" visas i sidfoten och via [wpfinet_cookie_settings].', 'finet-troubleshoot' ) . '</p>';
		self::settings_form_open( 'banner' );
		View::input( 'consent[title]', __( 'Rubrik', 'finet-troubleshoot' ), (string) $s['title'] );
		echo '<div class="wpf-field"><label class="wpf-field__label" for="wpf-c-body">' . esc_html__( 'Text', 'finet-troubleshoot' ) . '</label><textarea id="wpf-c-body" class="large-text" rows="4" name="consent[body]">' . esc_textarea( (string) $s['body'] ) . '</textarea></div>';
		foreach ( array( 'accept_label' => __( 'Acceptera-knapp', 'finet-troubleshoot' ), 'reject_label' => __( 'Neka-knapp', 'finet-troubleshoot' ), 'customize_label' => __( 'Anpassa-knapp', 'finet-troubleshoot' ), 'save_label' => __( 'Spara-knapp', 'finet-troubleshoot' ), 'settings_label' => __( 'Länktext för inställningar', 'finet-troubleshoot' ) ) as $k => $l ) { View::input( 'consent[' . $k . ']', $l, (string) $s[ $k ] ); }
		foreach ( ConsentSettings::CATEGORY_LABELS as $c => $l ) {
			echo '<div class="wpf-field"><label class="wpf-field__label" for="wpf-c-cat-' . esc_attr( $c ) . '">' . esc_html( $l ) . '</label><textarea id="wpf-c-cat-' . esc_attr( $c ) . '" class="large-text" rows="2" name="consent[category_texts][' . esc_attr( $c ) . ']">' . esc_textarea( (string) $s['category_texts'][ $c ] ) . '</textarea></div>';
		}
		$pages = get_pages( array( 'post_status' => array( 'publish', 'draft', 'private' ) ) );
		echo '<div class="wpf-field"><label class="wpf-field__label" for="wpf-c-policy">' . esc_html__( 'Befintlig cookiepolicysida', 'finet-troubleshoot' ) . '</label><select id="wpf-c-policy" name="consent[policy_page_id]"><option value="0">–</option>';
		foreach ( $pages as $p ) { echo '<option value="' . (int) $p->ID . '" ' . selected( (int) $s['policy_page_id'], $p->ID, false ) . '>' . esc_html( $p->post_title . ' (/' . $p->post_name . '/)' ) . '</option>'; }
		echo '</select><p class="wpf-field__help">' . esc_html__( 'WPFINET skapar ingen ny publik sida. Lägg [wpfinet_cookie_policy] på den befintliga sidan i staging.', 'finet-troubleshoot' ) . '</p></div>';
		View::input( 'consent[lifetime_days]', __( 'Spara valet i (dagar)', 'finet-troubleshoot' ), (string) $s['lifetime_days'], '', array( 'type' => 'number', 'min' => 1, 'max' => 395 ) );
		View::toggle( 'consent[reconsent_on_policy_change]', __( 'Fråga igen när en ny policyversion publiceras', 'finet-troubleshoot' ), ! empty( $s['reconsent_on_policy_change'] ) );
		View::toggle( 'consent[footer_link]', __( 'Visa "Cookieinställningar" i sidfoten', 'finet-troubleshoot' ), ! empty( $s['footer_link'] ) );
		View::toggle( 'consent[log_enabled]', __( 'Spara anonym logg över val (slumpat ID, tid, kategorier, policyversion – ingen IP)', 'finet-troubleshoot' ), ! empty( $s['log_enabled'] ) );
		View::input( 'consent[log_retention_days]', __( 'Spara loggen i (dagar)', 'finet-troubleshoot' ), (string) $s['log_retention_days'], '', array( 'type' => 'number', 'min' => 30 ) );
		submit_button( __( 'Spara', 'finet-troubleshoot' ) );
		echo '</form>';
		View::card_close();
	}

	private static function tab_policy(): void {
		$data = Policy::data();
		View::card_open( __( 'Utkast', 'finet-troubleshoot' ) );
		echo '<p>' . esc_html__( 'Tabellerna byggs av verifierade poster i inventariet. Besökare ser bara den senast godkända versionen. Utkastet nedan visas aldrig publikt.', 'finet-troubleshoot' ) . '</p>';
		self::form( 'policy' );
		echo '<textarea class="large-text" rows="8" name="intro" aria-label="' . esc_attr__( 'Inledande text', 'finet-troubleshoot' ) . '">' . esc_textarea( $data['draft_intro'] ) . '</textarea><button class="button">' . esc_html__( 'Spara utkast', 'finet-troubleshoot' ) . '</button></form>';
		echo '<details class="wpf-details"><summary>' . esc_html__( 'Förhandsgranska utkastet', 'finet-troubleshoot' ) . '</summary><div class="wpf-card">' . Policy::render( true ) . '</div></details>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in render().
		View::card_close();
		View::card_open( __( 'Godkänn och publicera', 'finet-troubleshoot' ) );
		self::form( 'approve' );
		echo '<label class="wpf-check"><input type="checkbox" name="reviewed" value="1"> ' . esc_html__( 'Innehållet är granskat av ansvarig person (och juridiskt vid behov) och motsvarar en manuellt verifierad inventering.', 'finet-troubleshoot' ) . '</label><input class="regular-text" name="note" placeholder="' . esc_attr__( 'Kommentar (t.ex. ärendenummer)', 'finet-troubleshoot' ) . '" aria-label="' . esc_attr__( 'Kommentar', 'finet-troubleshoot' ) . '"> <button class="button button-primary">' . esc_html__( 'Godkänn ny version', 'finet-troubleshoot' ) . '</button></form>';
		if ( $data['versions'] ) {
			echo '<table class="wpf-table"><thead><tr><th>Version</th><th>' . esc_html__( 'Godkänd', 'finet-troubleshoot' ) . '</th><th>' . esc_html__( 'Av', 'finet-troubleshoot' ) . '</th><th>' . esc_html__( 'Poster', 'finet-troubleshoot' ) . '</th><th>' . esc_html__( 'Kommentar', 'finet-troubleshoot' ) . '</th></tr></thead><tbody>';
			foreach ( array_reverse( $data['versions'] ) as $v ) { $u = get_userdata( (int) $v['approved_by'] ); echo '<tr><td>' . esc_html( (string) $v['version'] ) . '</td><td>' . esc_html( (string) $v['approved_at'] ) . '</td><td>' . esc_html( $u ? $u->display_name : '#' . $v['approved_by'] ) . '</td><td>' . count( (array) $v['items'] ) . '</td><td>' . esc_html( (string) $v['note'] ) . '</td></tr>'; }
			echo '</tbody></table>';
		}
		View::card_close();
	}

	private static function tab_scan(): void {
		View::card_open( __( 'Automatisk skanning med headless-webbläsare', 'finet-troubleshoot' ) );
		echo '<p>' . esc_html__( 'Skannern (mappen scanner/ i WPFINET-repot) kör Chromium via Playwright utanför WordPress, till exempel som schemalagd uppgift i Windows Task Scheduler på en separat maskin med Node.js. Den besöker de sidor du anger i en ren profil, registrerar cookies, localStorage, sessionStorage och tredjepartsanrop före samtycke, efter "neka" och efter "acceptera", och skickar resultatet hit.', 'finet-troubleshoot' ) . '</p>';
		echo '<ol><li>' . esc_html__( 'Skapa ett Application Password för en användare med behörigheten manage_wpfinet_settings (Användare → Profil).', 'finet-troubleshoot' ) . '</li><li>' . esc_html__( 'Kopiera scanner/config.example.json till config.json och fyll i webbplats, sidor och användare.', 'finet-troubleshoot' ) . '</li><li><code>node scan.mjs --config config.json</code></li></ol>';
		echo '<p>' . esc_html__( 'Mottagande endpoint:', 'finet-troubleshoot' ) . ' <code>' . esc_html( rest_url( 'wpfinet/v1/consent/scan' ) ) . '</code></p>';
		echo '<div class="wpf-missing"><strong>' . esc_html__( 'Skannern kan inte garantera att allt hittas', 'finet-troubleshoot' ) . '</strong><ul><li>' . esc_html__( 'Innehåll bakom inloggning, formulärsteg eller andra interaktioner.', 'finet-troubleshoot' ) . '</li><li>' . esc_html__( 'Cloudflare-skydd (botutmaningar) och geostyrda eller slumpade skript.', 'finet-troubleshoot' ) . '</li><li>' . esc_html__( 'Cookies i iframes från tredje part.', 'finet-troubleshoot' ) . '</li></ul>' . esc_html__( 'Komplettera med manuella poster. Nya fynd blir alltid förslag och aldrig automatiskt "nödvändiga".', 'finet-troubleshoot' ) . '</div>';
		View::card_close();
		$history = array_reverse( (array) get_option( ScanReceiver::OPTION, array() ) );
		View::card_open( __( 'Skanningshistorik', 'finet-troubleshoot' ) );
		if ( ! $history ) { echo '<p class="wpf-muted">' . esc_html__( 'Inga skanningar ännu.', 'finet-troubleshoot' ) . '</p>'; }
		else {
			echo '<table class="wpf-table"><thead><tr><th>' . esc_html__( 'Mottagen', 'finet-troubleshoot' ) . '</th><th>' . esc_html__( 'Sidor', 'finet-troubleshoot' ) . '</th><th>' . esc_html__( 'Observationer', 'finet-troubleshoot' ) . '</th><th>' . esc_html__( 'Nya', 'finet-troubleshoot' ) . '</th><th>' . esc_html__( 'Tredjepartsvärdar', 'finet-troubleshoot' ) . '</th></tr></thead><tbody>';
			foreach ( $history as $h ) { echo '<tr><td>' . esc_html( (string) $h['received_at'] ) . '</td><td>' . count( (array) $h['pages'] ) . '</td><td>' . (int) $h['observations'] . '</td><td>' . esc_html( implode( ', ', (array) $h['new'] ) ?: '–' ) . '</td><td>' . esc_html( implode( ', ', array_keys( (array) $h['third_party_hosts'] ) ) ?: '–' ) . '</td></tr>'; }
			echo '</tbody></table>';
		}
		View::card_close();
	}
}
