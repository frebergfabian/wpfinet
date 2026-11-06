<?php
/**
 * Admin page WPFINET → Adressökning. Secrets are write-only (never printed), all actions are nonce + capability
 * protected and the connection test only shows a masked schema, never address data.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Access;

use FinetTroubleshoot\Admin\View;
use FinetTroubleshoot\Core\Modules;
use FinetTroubleshoot\Core\Secrets;
use FinetTroubleshoot\Migration\LegacyScanner;

final class AccessAdmin {
	public static function register_hooks(): void {
		foreach ( array( 'save', 'secret', 'test', 'sync', 'lookup', 'reset_breaker' ) as $action ) {
			add_action( 'admin_post_wpfinet_access_' . $action, array( self::class, 'handle_' . $action ) );
		}
		add_action( 'wpfinet_access_sync_manual', static function (): void { ( new AccessService() )->sync( true ); } );
	}

	/** Legacy deep link admin.php?page=wpgetapi_finet_access – only claimed when WPGetAPI is NOT active. */
	public static function register_legacy_page(): void {
		$active = array_filter( (array) get_option( 'active_plugins', array() ), static fn ( $p ) => str_contains( strtolower( (string) $p ), 'wpgetapi' ) );
		if ( $active ) {
			return;
		}
		add_submenu_page( '', __( 'WPGetAPI (pensionerad)', 'finet-troubleshoot' ), '', 'manage_wpfinet_settings', 'wpgetapi_finet_access', static function (): void {
			View::open( __( 'WPGetAPI har ersatts', 'finet-troubleshoot' ) );
			View::notice( 'info', __( 'Länken pekade på WPGetAPI:s inställningar för Finet Access API. Integrationen hanteras nu av WPFINET.', 'finet-troubleshoot' ) );
			echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=wpfinet-access' ) ) . '">' . esc_html__( 'Öppna WPFINET → Adressökning', 'finet-troubleshoot' ) . '</a></p>';
			View::close();
		} );
	}

	private static function guard( string $action ): void {
		check_admin_referer( $action );
		if ( ! current_user_can( 'manage_wpfinet_settings' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Du saknar behörighet.', 'finet-troubleshoot' ), 403 );
		}
	}

	private static function back( string $notice = '' ): void {
		wp_safe_redirect( add_query_arg( 'wpfinet_access', $notice, admin_url( 'admin.php?page=wpfinet-access' ) ) );
		exit;
	}

	public static function handle_save(): void {
		self::guard( 'wpfinet_access_save' );
		$input = isset( $_POST['access'] ) && is_array( $_POST['access'] ) ? wp_unslash( $_POST['access'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in AccessSettings::sanitize().
		$input['_form'] = 1;
		AccessSettings::update( $input );
		if ( Modules::enabled( 'access' ) ) { AccessModule::ensure_schedule(); }
		self::back( 'saved' );
	}

	public static function handle_secret(): void {
		self::guard( 'wpfinet_access_secret' );
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput -- secrets are stored verbatim (encrypted), never output.
		if ( ! empty( $_POST['clear_secret'] ) ) {
			Secrets::set( 'access_api_secret', '' );
			Secrets::set( 'access_api_username', '' );
		} else {
			$secret = isset( $_POST['secret'] ) ? trim( (string) wp_unslash( $_POST['secret'] ) ) : '';
			$user   = isset( $_POST['username'] ) ? trim( (string) wp_unslash( $_POST['username'] ) ) : '';
			if ( '' !== $secret ) { Secrets::set( 'access_api_secret', $secret ); }
			if ( '' !== $user ) { Secrets::set( 'access_api_username', $user ); }
		}
		// phpcs:enable
		self::back( 'secret_saved' );
	}

	public static function handle_test(): void {
		self::guard( 'wpfinet_access_test' );
		set_transient( 'wpfinet_access_test_' . get_current_user_id(), ( new AccessService() )->test_connection(), 10 * MINUTE_IN_SECONDS );
		self::back( 'tested' );
	}

	public static function handle_sync(): void {
		self::guard( 'wpfinet_access_sync' );
		if ( ! empty( $_POST['now'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$result = ( new AccessService() )->sync( false );
			self::back( $result['ok'] ? 'synced' : 'sync_failed' );
		}
		wp_schedule_single_event( time(), 'wpfinet_access_sync_manual' );
		spawn_cron();
		self::back( 'sync_queued' );
	}

	public static function handle_lookup(): void {
		self::guard( 'wpfinet_access_lookup' );
		$address = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
		set_transient( 'wpfinet_access_lookup_' . get_current_user_id(), array( 'address' => $address, 'result' => ( new AccessService() )->lookup( $address ) ), 10 * MINUTE_IN_SECONDS );
		self::back( 'looked_up' );
	}

	public static function handle_reset_breaker(): void {
		self::guard( 'wpfinet_access_reset_breaker' );
		( new AccessService() )->client()->http()->breaker()->reset();
		self::back( 'breaker_reset' );
	}

	private static function form_open( string $action ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wpfinet_access_' . $action );
		echo '<input type="hidden" name="action" value="wpfinet_access_' . esc_attr( $action ) . '">';
	}

	public static function render(): void {
		$s       = AccessSettings::get();
		$service = new AccessService( $s );
		$health  = $service->health();
		$real_missing = AccessSettings::missing( 'mock' === $s['adapter'] ? array_merge( $s, array( 'adapter' => 'http' ) ) : $s ); // What a REAL integration still needs, also while test data is used.
		$notice  = isset( $_GET['wpfinet_access'] ) ? sanitize_key( wp_unslash( $_GET['wpfinet_access'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		View::open( __( 'Adressökning – Finet Access API', 'finet-troubleshoot' ), __( 'Besökare söker en adress i taget. Servern svarar bara med status för den adressen – aldrig med listor. Sidvisningar gör aldrig anrop mot API:t.', 'finet-troubleshoot' ) );
		$messages = array( 'saved' => __( 'Inställningarna är sparade.', 'finet-troubleshoot' ), 'secret_saved' => __( 'Hemligheten är sparad krypterat.', 'finet-troubleshoot' ), 'tested' => __( 'Anslutningstestet är klart – se resultatet nedan.', 'finet-troubleshoot' ), 'synced' => __( 'Synken lyckades.', 'finet-troubleshoot' ), 'sync_failed' => __( 'Synken misslyckades – se senaste resultat.', 'finet-troubleshoot' ), 'sync_queued' => __( 'Synken har köats och körs i bakgrunden.', 'finet-troubleshoot' ), 'looked_up' => __( 'Testsökningen är klar.', 'finet-troubleshoot' ), 'breaker_reset' => __( 'Kretsbrytaren är återställd.', 'finet-troubleshoot' ) );
		if ( isset( $messages[ $notice ] ) ) { View::notice( 'sync_failed' === $notice ? 'warning' : 'success', $messages[ $notice ] ); }
		if ( ! Modules::enabled( 'access' ) ) {
			View::notice( 'info', __( 'Modulen är avstängd. Shortcoden [wpfinet_access_check] visar inget för besökare förrän den slås på under Moduler & integrationer.', 'finet-troubleshoot' ) );
		}

		echo '<div class="wpf-grid">';
		View::card_open( __( 'Status', 'finet-troubleshoot' ) );
		echo '<div class="wpf-status">' . View::badge( Modules::enabled( 'access' ) ? __( 'Modul på', 'finet-troubleshoot' ) : __( 'Modul av', 'finet-troubleshoot' ), Modules::enabled( 'access' ) ? 'ok' : 'muted' ) . View::badge( array( 'mock' => __( 'Testdata', 'finet-troubleshoot' ), 'wpgetapi' => __( 'Via WPGetAPI', 'finet-troubleshoot' ), 'http' => __( 'Riktigt API', 'finet-troubleshoot' ) )[ $s['adapter'] ] ?? '', 'mock' === $s['adapter'] ? 'warn' : 'muted' ) . View::badge( $real_missing ? __( 'Riktig integration ej komplett', 'finet-troubleshoot' ) : __( 'Konfigurerad', 'finet-troubleshoot' ), $real_missing ? 'warn' : 'ok' ) . View::badge( $health['circuit_open'] ? __( 'Kretsbrytare öppen', 'finet-troubleshoot' ) : __( 'Kretsbrytare stängd', 'finet-troubleshoot' ), $health['circuit_open'] ? 'error' : 'ok' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		$fresh = array( 'empty' => __( 'tomt', 'finet-troubleshoot' ), 'fresh' => __( 'aktuellt', 'finet-troubleshoot' ), 'stale' => __( 'inaktuellt (visas med varning)', 'finet-troubleshoot' ), 'expired' => __( 'för gammalt (besökare får "tillfälligt otillgänglig")', 'finet-troubleshoot' ) );
		echo '<dl class="wpf-dl"><dt>' . esc_html__( 'Läge', 'finet-troubleshoot' ) . '</dt><dd>' . esc_html( AccessSettings::uses_index( $s ) ? __( 'Lokalt index (schemalagd synk)', 'finet-troubleshoot' ) : __( 'Uppslag per sökning', 'finet-troubleshoot' ) ) . '</dd>';
		echo '<dt>' . esc_html__( 'Endpoint', 'finet-troubleshoot' ) . '</dt><dd><code>' . esc_html( $health['endpoint'] ?: $s['base_url'] . '…' ) . '</code></dd>';
		echo '<dt>' . esc_html__( 'Index', 'finet-troubleshoot' ) . '</dt><dd>' . esc_html( sprintf( __( '%1$d adresser, %2$s', 'finet-troubleshoot' ), $health['index_rows'], $fresh[ $health['freshness'] ] ) ) . '</dd>';
		echo '<dt>' . esc_html__( 'Senaste lyckade synk', 'finet-troubleshoot' ) . '</dt><dd>' . esc_html( View::time_ago( (int) $health['last_success'] ) ) . '</dd>';
		echo '<dt>' . esc_html__( 'Senaste resultat', 'finet-troubleshoot' ) . '</dt><dd>' . esc_html( $health['last_result'] ?: '–' ) . '</dd>';
		echo '<dt>' . esc_html__( 'Nästa synk', 'finet-troubleshoot' ) . '</dt><dd>' . esc_html( $health['next_sync'] ? wp_date( 'Y-m-d H:i', $health['next_sync'] ) : '–' ) . '</dd>';
		if ( $health['breaker']['last_error'] ) { echo '<dt>' . esc_html__( 'Senaste fel', 'finet-troubleshoot' ) . '</dt><dd>' . esc_html( $health['breaker']['last_error'] ) . '</dd>'; }
		echo '</dl><div class="wpf-actions">';
		self::form_open( 'test' ); echo '<button class="button">' . esc_html__( 'Testa anslutning', 'finet-troubleshoot' ) . '</button></form>';
		self::form_open( 'sync' ); echo '<button class="button">' . esc_html__( 'Synka i bakgrunden', 'finet-troubleshoot' ) . '</button></form>';
		self::form_open( 'sync' ); echo '<input type="hidden" name="now" value="1"><button class="button">' . esc_html__( 'Synka nu (vänta på svar)', 'finet-troubleshoot' ) . '</button></form>';
		if ( $health['circuit_open'] ) { self::form_open( 'reset_breaker' ); echo '<button class="button">' . esc_html__( 'Återställ kretsbrytare', 'finet-troubleshoot' ) . '</button></form>'; }
		echo '</div>';
		View::card_close();

		View::card_open( __( 'Vad saknas för riktig integration', 'finet-troubleshoot' ) );
		if ( 'mock' === $s['adapter'] ) {
			View::notice( 'warning', __( 'Testdata används. Besökare får svar från påhittade adresser. Byt datakälla till Finet Access API före publicering.', 'finet-troubleshoot' ) );
		}
		if ( $real_missing ) {
			echo '<div class="wpf-missing"><strong>' . esc_html__( 'Följande uppgifter är okända och har inte hittats på:', 'finet-troubleshoot' ) . '</strong><ul>';
			foreach ( $real_missing as $m ) { echo '<li>' . esc_html( $m ) . '</li>'; }
			echo '</ul></div>';
		} else {
			echo '<p>' . esc_html__( 'Alla obligatoriska uppgifter är ifyllda. Verifiera med "Testa anslutning".', 'finet-troubleshoot' ) . '</p>';
		}
		echo '<p class="wpf-muted">' . esc_html( sprintf( __( 'Känt från gamla lösningen: base URL %1$s, WPGetAPI API-ID "%2$s", endpoint-ID "%3$s", fälten streetName/streetNumber/streetLittera/accessState och värdet CONNECTED. Okänt: sökväg, autentisering, headers, query-parametrar och fullständigt svarsschema.', 'finet-troubleshoot' ), AccessSettings::KNOWN_BASE_URL, AccessSettings::LEGACY_WPGETAPI['api_id'], AccessSettings::LEGACY_WPGETAPI['endpoint_id'] ) ) . '</p>';
		$report = LegacyScanner::last_report();
		if ( $report ) {
			echo '<p>' . esc_html( sprintf( __( 'Senaste inventering: %1$d platser med WPGetAPI-shortcode, %2$d med gammalt adresskript, %3$d med [wpfinet_access_check].', 'finet-troubleshoot' ), $report['summary']['wpgetapi_endpoint']['locations'], $report['summary']['legacy_address_js']['locations'], $report['summary']['wpfinet_access_check']['locations'] ) ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=wpfinet-diagnostics' ) ) . '">' . esc_html__( 'Visa', 'finet-troubleshoot' ) . '</a></p>';
		}
		if ( 'wpgetapi' === $s['adapter'] ) {
			echo '<p class="wpf-muted">' . esc_html__( 'Datakällan WPGetAPI kräver att WPGetAPI-pluginet är aktivt. Avaktivera det först när du bytt till "Finet Access API" och synken fungerar där.', 'finet-troubleshoot' ) . '</p>';
		}
		echo '<p class="wpf-muted">' . esc_html__( 'Stäng inte av WPGetAPI förrän inventeringen visar 0 oersatta referenser och sidorna har verifierats.', 'finet-troubleshoot' ) . '</p>';
		View::card_close();
		echo '</div>';

		$test = get_transient( 'wpfinet_access_test_' . get_current_user_id() );
		if ( is_array( $test ) ) {
			View::card_open( __( 'Senaste anslutningstest', 'finet-troubleshoot' ) );
			echo '<dl class="wpf-dl"><dt>' . esc_html__( 'Resultat', 'finet-troubleshoot' ) . '</dt><dd>' . View::badge( $test['ok'] ? 'OK' : __( 'Fel', 'finet-troubleshoot' ), $test['ok'] ? 'ok' : 'error' ) . ' ' . esc_html( $test['error'] ) . '</dd>'; // phpcs:ignore WordPress.Security.EscapeOutput
			echo '<dt>HTTP</dt><dd>' . esc_html( (string) $test['status'] ) . ' · ' . esc_html( (string) $test['duration_ms'] ) . ' ms · ' . esc_html( size_format( (int) $test['bytes'] ) ?: '0 B' ) . ' · ' . esc_html( $test['content_type'] ) . '</dd>';
			if ( ! empty( $test['list_paths'] ) ) { echo '<dt>' . esc_html__( 'Listor i svaret', 'finet-troubleshoot' ) . '</dt><dd>' . esc_html( implode( ', ', array_map( static fn ( $k, $v ) => "$k ($v)", array_keys( $test['list_paths'] ), $test['list_paths'] ) ) ) . '</dd>'; }
			if ( isset( $test['mapped_sample'] ) && is_array( $test['mapped_sample'] ) ) { echo '<dt>' . esc_html__( 'Fältmappning (första 500)', 'finet-troubleshoot' ) . '</dt><dd>' . esc_html( sprintf( __( '%1$d giltiga, %2$d utan giltig adress. Statusvärden: %3$s', 'finet-troubleshoot' ), $test['mapped_sample']['valid'], $test['mapped_sample']['skipped'], wp_json_encode( $test['mapped_sample']['states'] ) ) ) . '</dd>'; }
			echo '</dl>';
			if ( ! empty( $test['schema'] ) ) { echo '<details class="wpf-details" open><summary>' . esc_html__( 'Schema (värden maskerade)', 'finet-troubleshoot' ) . '</summary><pre class="wpf-preview">' . esc_html( (string) wp_json_encode( $test['schema'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ) . '</pre></details>'; }
			View::card_close();
		}

		View::card_open( __( 'Testsökning', 'finet-troubleshoot' ) );
		self::form_open( 'lookup' );
		$last = get_transient( 'wpfinet_access_lookup_' . get_current_user_id() );
		View::input( 'address', __( 'Adress', 'finet-troubleshoot' ), is_array( $last ) ? (string) $last['address'] : '', __( 'Samma logik som besökarnas sökning, utan rate limit.', 'finet-troubleshoot' ) );
		echo '<button class="button">' . esc_html__( 'Sök', 'finet-troubleshoot' ) . '</button></form>';
		if ( is_array( $last ) ) { echo '<pre class="wpf-preview">' . esc_html( (string) wp_json_encode( $last['result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre>'; }
		View::card_close();

		View::card_open( __( 'Anslutning och fältmappning', 'finet-troubleshoot' ) );
		self::form_open( 'save' );
		echo '<div class="wpf-grid">';
		echo '<div>';
		View::select( 'access[adapter]', __( 'Datakälla', 'finet-troubleshoot' ), $s['adapter'], array_merge( array( 'http' => __( 'Finet Access API (direkt, inställningarna nedan)', 'finet-troubleshoot' ), 'wpgetapi' => __( 'Befintlig WPGetAPI-konfiguration (endast bakgrundssynk)', 'finet-troubleshoot' ) ), AccessSettings::mock_allowed() ? array( 'mock' => __( 'Testdata (påhittade adresser)', 'finet-troubleshoot' ) ) : array() ), __( 'WPGetAPI: endpoint-sökväg och nyckel läses från WPGetAPI. Läget blir alltid index och fälten nedan används för mappningen. Testdata är avstängt i produktionsmiljö.', 'finet-troubleshoot' ) );
		View::input( 'access[wpgetapi_api_id]', __( 'WPGetAPI: API-ID', 'finet-troubleshoot' ), (string) $s['wpgetapi_api_id'], __( 'Gamla lösningen: finet_access', 'finet-troubleshoot' ) );
		View::input( 'access[wpgetapi_endpoint_id]', __( 'WPGetAPI: endpoint-ID', 'finet-troubleshoot' ), (string) $s['wpgetapi_endpoint_id'], __( 'Gamla lösningen: get_access_data', 'finet-troubleshoot' ) );
		View::select( 'access[mode]', __( 'Läge', 'finet-troubleshoot' ), $s['mode'], array( 'index' => __( 'Index: hämta hela listan i bakgrunden', 'finet-troubleshoot' ), 'lookup' => __( 'Uppslag: fråga API:t per sökning (kräver filtrerad sökning i API:t)', 'finet-troubleshoot' ) ) );
		View::input( 'access[base_url]', __( 'Base URL', 'finet-troubleshoot' ), (string) $s['base_url'], sprintf( __( 'Tillåtna värdar: %s (utöka endast via WPFINET_ACCESS_ALLOWED_HOSTS i wp-config.php).', 'finet-troubleshoot' ), implode( ', ', AccessSettings::allowed_hosts() ) ) );
		View::input( 'access[endpoint_path]', __( 'Endpoint-sökväg', 'finet-troubleshoot' ), (string) $s['endpoint_path'], __( 'Relativ sökväg under base URL. OKÄND – hämtas från WPGetAPI-konfigurationen.', 'finet-troubleshoot' ) );
		View::select( 'access[method]', __( 'HTTP-metod', 'finet-troubleshoot' ), $s['method'], array( 'GET' => 'GET', 'POST' => 'POST (JSON)' ) );
		View::select( 'access[auth]', __( 'Autentisering', 'finet-troubleshoot' ), $s['auth'], array( 'none' => __( 'Ingen', 'finet-troubleshoot' ), 'bearer' => 'Authorization: Bearer', 'header' => __( 'Egen header', 'finet-troubleshoot' ), 'basic' => 'Basic', 'query' => __( 'Query-parameter', 'finet-troubleshoot' ) ) );
		View::input( 'access[auth_header]', __( 'Headernamn', 'finet-troubleshoot' ), (string) $s['auth_header'] );
		View::input( 'access[auth_query_param]', __( 'Query-parameter för nyckel', 'finet-troubleshoot' ), (string) $s['auth_query_param'] );
		echo '<div class="wpf-field"><label class="wpf-field__label" for="wpf-extra-query">' . esc_html__( 'Övriga query-parametrar (inte hemliga), en per rad: nyckel=värde', 'finet-troubleshoot' ) . '</label><textarea id="wpf-extra-query" class="large-text" rows="3" name="access[extra_query]">' . esc_textarea( (string) $s['extra_query'] ) . '</textarea></div>';
		echo '</div><div>';
		View::input( 'access[data_path]', __( 'Sökväg till adresslistan i svaret', 'finet-troubleshoot' ), (string) $s['data_path'], __( 'Punktnotation, t.ex. data.items. Tomt = svaret är själva listan.', 'finet-troubleshoot' ) );
		View::input( 'access[field_street]', __( 'Fält: gatunamn', 'finet-troubleshoot' ), (string) $s['field_street'] );
		View::input( 'access[field_number]', __( 'Fält: nummer', 'finet-troubleshoot' ), (string) $s['field_number'] );
		View::input( 'access[field_letter]', __( 'Fält: bokstav', 'finet-troubleshoot' ), (string) $s['field_letter'] );
		View::input( 'access[field_state]', __( 'Fält: status', 'finet-troubleshoot' ), (string) $s['field_state'] );
		View::input( 'access[connected_values]', __( 'Statusvärden som betyder ansluten', 'finet-troubleshoot' ), (string) $s['connected_values'], __( 'Kommaseparerat. Gamla lösningen: CONNECTED.', 'finet-troubleshoot' ) );
		View::input( 'access[lookup_params][street]', __( 'Uppslag: parameter för gata', 'finet-troubleshoot' ), (string) $s['lookup_params']['street'] );
		View::input( 'access[lookup_params][number]', __( 'Uppslag: parameter för nummer', 'finet-troubleshoot' ), (string) $s['lookup_params']['number'] );
		View::input( 'access[lookup_params][letter]', __( 'Uppslag: parameter för bokstav', 'finet-troubleshoot' ), (string) $s['lookup_params']['letter'] );
		echo '</div><div>';
		View::input( 'access[timeout]', __( 'Timeout per sökning (s)', 'finet-troubleshoot' ), (string) $s['timeout'], __( '1–5 s. Gäller bara uppslagsläget; inga omförsök vid besökarsökningar.', 'finet-troubleshoot' ), array( 'type' => 'number', 'min' => 1, 'max' => 5 ) );
		View::input( 'access[sync_timeout]', __( 'Timeout för bakgrundssynk (s)', 'finet-troubleshoot' ), (string) $s['sync_timeout'], __( 'Upp till 2 omförsök med backoff.', 'finet-troubleshoot' ), array( 'type' => 'number', 'min' => 5, 'max' => 60 ) );
		View::select( 'access[sync_interval]', __( 'Synkintervall', 'finet-troubleshoot' ), $s['sync_interval'], array( 'wpfinet_15min' => '15 min', 'wpfinet_30min' => '30 min', 'hourly' => __( 'Varje timme', 'finet-troubleshoot' ), 'twicedaily' => __( 'Två gånger per dygn', 'finet-troubleshoot' ), 'daily' => __( 'Dagligen', 'finet-troubleshoot' ) ) );
		View::input( 'access[stale_after_hours]', __( 'Visa varning efter (timmar)', 'finet-troubleshoot' ), (string) $s['stale_after_hours'], '', array( 'type' => 'number', 'min' => 1 ) );
		View::input( 'access[max_age_hours]', __( 'Sluta svara efter (timmar)', 'finet-troubleshoot' ), (string) $s['max_age_hours'], __( 'Äldre underlag ger "tillfälligt otillgänglig" i stället för ett overifierat svar.', 'finet-troubleshoot' ), array( 'type' => 'number', 'min' => 1 ) );
		View::input( 'access[min_rows]', __( 'Minsta antal adresser för att godkänna en synk', 'finet-troubleshoot' ), (string) $s['min_rows'], '', array( 'type' => 'number', 'min' => 0 ) );
		View::input( 'access[max_drop_percent]', __( 'Stoppa synk om antalet sjunker mer än (%)', 'finet-troubleshoot' ), (string) $s['max_drop_percent'], '', array( 'type' => 'number', 'min' => 0, 'max' => 100 ) );
		View::input( 'access[rate_limit]', __( 'Max sökningar per minut och besökare', 'finet-troubleshoot' ), (string) $s['rate_limit'], '', array( 'type' => 'number', 'min' => 1 ) );
		View::toggle( 'access[trust_cloudflare]', __( 'Lita på CF-Connecting-IP från Cloudflares IP-intervall', 'finet-troubleshoot' ), ! empty( $s['trust_cloudflare'] ), __( 'Slå på när sajten ligger bakom Cloudflare-proxy, annars delar alla besökare samma gräns.', 'finet-troubleshoot' ) );
		echo '</div></div><h3>' . esc_html__( 'Texter och knappar', 'finet-troubleshoot' ) . '</h3><div class="wpf-grid"><div>';
		View::input( 'access[cta_connected_url]', __( 'Länk vid ansluten', 'finet-troubleshoot' ), (string) $s['cta_connected_url'], __( 'Gamla lösningen: /tjanster', 'finet-troubleshoot' ) );
		View::input( 'access[cta_connected_label]', __( 'Knapptext vid ansluten', 'finet-troubleshoot' ), (string) $s['cta_connected_label'] );
		View::input( 'access[cta_not_connected_url]', __( 'Länk vid ej ansluten', 'finet-troubleshoot' ), (string) $s['cta_not_connected_url'], __( 'Gamla lösningen: /kontakta-oss', 'finet-troubleshoot' ) );
		View::input( 'access[cta_not_connected_label]', __( 'Knapptext vid ej ansluten', 'finet-troubleshoot' ), (string) $s['cta_not_connected_label'] );
		echo '</div><div>';
		foreach ( array( 'text_connected' => __( 'Ansluten', 'finet-troubleshoot' ), 'text_not_connected' => __( 'Ej ansluten', 'finet-troubleshoot' ), 'text_no_match' => __( 'Ingen träff', 'finet-troubleshoot' ), 'text_letter_required' => __( 'Bokstav saknas', 'finet-troubleshoot' ), 'text_invalid' => __( 'Ogiltigt format', 'finet-troubleshoot' ), 'text_unavailable' => __( 'Tillfälligt otillgänglig', 'finet-troubleshoot' ), 'text_stale' => __( 'Inaktuellt underlag (tillägg)', 'finet-troubleshoot' ) ) as $key => $label ) {
			View::input( 'access[' . $key . ']', $label, (string) $s[ $key ], '', array( 'class' => 'large-text' ) );
		}
		echo '</div></div>';
		submit_button( __( 'Spara inställningar', 'finet-troubleshoot' ) );
		echo '</form>';
		View::card_close();

		View::card_open( __( 'API-hemlighet', 'finet-troubleshoot' ) );
		$source = Secrets::source( 'access_api_secret' );
		echo '<p>' . esc_html( array( 'constant' => __( 'Hemligheten läses från wp-config.php (WPFINET_ACCESS_API_SECRET). Rekommenderat.', 'finet-troubleshoot' ), 'database' => __( 'En hemlighet är sparad krypterat i databasen. Den visas aldrig.', 'finet-troubleshoot' ), 'none' => __( 'Ingen hemlighet är sparad.', 'finet-troubleshoot' ) )[ $source ] ) . '</p>';
		if ( ! Secrets::available() ) { View::notice( 'error', __( 'PHP-tillägget sodium saknas – hemligheter kan bara anges via wp-config.php.', 'finet-troubleshoot' ) ); }
		if ( 'constant' !== $source ) {
			self::form_open( 'secret' );
			echo '<div class="wpf-field"><label class="wpf-field__label" for="wpf-access-secret">' . esc_html__( 'Ny hemlighet / API-nyckel', 'finet-troubleshoot' ) . '</label><input id="wpf-access-secret" type="password" name="secret" class="regular-text" autocomplete="new-password" value=""></div>';
			echo '<div class="wpf-field"><label class="wpf-field__label" for="wpf-access-user">' . esc_html__( 'Användarnamn (endast Basic)', 'finet-troubleshoot' ) . '</label><input id="wpf-access-user" type="text" name="username" class="regular-text" autocomplete="off" value=""></div>';
			echo '<div class="wpf-actions"><button class="button button-primary">' . esc_html__( 'Spara hemlighet', 'finet-troubleshoot' ) . '</button><label><input type="checkbox" name="clear_secret" value="1"> ' . esc_html__( 'Radera sparad hemlighet', 'finet-troubleshoot' ) . '</label></div></form>';
		}
		View::card_close();

		View::card_open( __( 'Användning', 'finet-troubleshoot' ) );
		echo '<p><code>[wpfinet_access_check]</code> ' . esc_html__( '– fungerar i Elementors Shortcode-widget och i blockredigeraren (block "WPFINET adressökning"). Attribut: title, label, button, placeholder, hide_label (1 = etiketten visas bara för skärmläsare), hint (0 = dölj hjälptexten), theme (light | dark för mörk bakgrund som startsidans hero), class.', 'finet-troubleshoot' ) . '</p>';
		echo '<p>' . esc_html__( 'Cloudflare: lägg gärna en WAF-regel med rate limiting för /wp-json/wpfinet/v1/access/check (t.ex. 30 anrop/min per IP) och undanta rutten från cache.', 'finet-troubleshoot' ) . '</p>';
		View::card_close();
		View::close();
	}
}
