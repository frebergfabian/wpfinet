<?php
/**
 * Admin page WPFINET → Instagram: connection (paste a long-lived token or OAuth via Business Login for Instagram),
 * status, settings, manual (asynchronous, rate limited) sync. Tokens and app secret are write-only.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Instagram;

use FinetTroubleshoot\Admin\View;
use FinetTroubleshoot\Core\Modules;
use FinetTroubleshoot\Core\Secrets;

final class InstagramAdmin {
	public static function register_hooks(): void {
		foreach ( array( 'save', 'token', 'disconnect', 'app_secret', 'oauth_start', 'oauth', 'sync', 'refresh' ) as $action ) {
			add_action( 'admin_post_wpfinet_instagram_' . $action, array( self::class, 'handle_' . $action ) );
		}
	}

	private static function guard( string $action, bool $nonce = true ): void {
		if ( $nonce ) { check_admin_referer( 'wpfinet_instagram_' . $action ); }
		if ( ! current_user_can( 'manage_wpfinet_settings' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Du saknar behörighet.', 'finet-troubleshoot' ), 403 );
		}
	}

	private static function back( string $notice, string $message = '' ): void {
		if ( $message ) { set_transient( 'wpfinet_ig_msg_' . get_current_user_id(), $message, 5 * MINUTE_IN_SECONDS ); }
		wp_safe_redirect( add_query_arg( 'wpfinet_ig', $notice, admin_url( 'admin.php?page=wpfinet-instagram' ) ) );
		exit;
	}

	public static function handle_save(): void {
		self::guard( 'save' );
		InstagramService::update_settings( isset( $_POST['ig'] ) && is_array( $_POST['ig'] ) ? wp_unslash( $_POST['ig'] ) : array() ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in update_settings().
		self::back( 'saved' );
	}

	public static function handle_token(): void {
		self::guard( 'token' );
		$token  = isset( $_POST['token'] ) ? (string) wp_unslash( $_POST['token'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated by format in connect_token(), never output.
		$result = ( new InstagramService() )->connect_token( $token );
		self::back( $result['ok'] ? 'connected' : 'error', $result['message'] );
	}

	public static function handle_disconnect(): void {
		self::guard( 'disconnect' );
		( new InstagramService() )->disconnect();
		self::back( 'disconnected' );
	}

	public static function handle_app_secret(): void {
		self::guard( 'app_secret' );
		$secret = isset( $_POST['app_secret'] ) ? trim( (string) wp_unslash( $_POST['app_secret'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( '' !== $secret ) { Secrets::set( 'instagram_app_secret', $secret ); }
		self::back( 'saved' );
	}

	public static function handle_oauth_start(): void {
		self::guard( 'oauth_start' );
		$state = wp_generate_password( 32, false, false );
		set_transient( 'wpfinet_ig_oauth_' . get_current_user_id(), $state, 10 * MINUTE_IN_SECONDS );
		wp_redirect( ( new InstagramService() )->authorize_url( $state ) ); // phpcs:ignore WordPress.Security.SafeRedirect -- fixed Instagram host.
		exit;
	}

	/** OAuth callback (redirect URI). Protected by capability + one-time state bound to the user. */
	public static function handle_oauth(): void {
		self::guard( 'oauth', false );
		// phpcs:disable WordPress.Security.NonceVerification -- the one-time OAuth state replaces the nonce.
		$expected = (string) get_transient( 'wpfinet_ig_oauth_' . get_current_user_id() );
		delete_transient( 'wpfinet_ig_oauth_' . get_current_user_id() );
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		if ( ! $expected || ! hash_equals( $expected, $state ) ) { self::back( 'error', __( 'Ogiltigt eller utgånget OAuth-tillstånd. Försök igen.', 'finet-troubleshoot' ) ); }
		if ( isset( $_GET['error'] ) ) { self::back( 'error', sanitize_text_field( wp_unslash( $_GET['error_description'] ?? $_GET['error'] ) ) ); }
		$code = isset( $_GET['code'] ) ? preg_replace( '/#_$/', '', sanitize_text_field( wp_unslash( $_GET['code'] ) ) ) : '';
		// phpcs:enable
		$result = ( new InstagramService() )->exchange_code( (string) $code );
		self::back( $result['ok'] ? 'connected' : 'error', $result['message'] );
	}

	public static function handle_sync(): void {
		self::guard( 'sync' );
		$state = InstagramService::state();
		if ( time() - (int) $state['last_manual'] < 5 * MINUTE_IN_SECONDS ) {
			self::back( 'error', __( 'Manuell synk kan köras högst var femte minut.', 'finet-troubleshoot' ) );
		}
		InstagramService::update_state( array( 'last_manual' => time() ) );
		wp_schedule_single_event( time(), 'wpfinet_instagram_sync_manual' );
		spawn_cron();
		self::back( 'queued' );
	}

	public static function handle_refresh(): void {
		self::guard( 'refresh' );
		$result = ( new InstagramService() )->maybe_refresh_token( true );
		self::back( $result['ok'] ? 'refreshed' : 'error', $result['message'] );
	}

	private static function form( string $action, string $inner, string $button, string $class = 'button' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wpfinet_instagram_' . $action );
		echo '<input type="hidden" name="action" value="wpfinet_instagram_' . esc_attr( $action ) . '">' . $inner . '<button class="' . esc_attr( $class ) . '">' . esc_html( $button ) . '</button></form>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $inner is built from escaped parts below.
	}

	public static function render(): void {
		$service  = new InstagramService();
		$health   = $service->health();
		$settings = InstagramService::settings();
		$notice   = isset( $_GET['wpfinet_ig'] ) ? sanitize_key( wp_unslash( $_GET['wpfinet_ig'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$message  = (string) get_transient( 'wpfinet_ig_msg_' . get_current_user_id() );
		delete_transient( 'wpfinet_ig_msg_' . get_current_user_id() );
		View::open( __( 'Instagramflöde', 'finet-troubleshoot' ), __( 'Inlägg hämtas i bakgrunden via Metas officiella API och sparas lokalt – även bilderna. Sidvisningar gör inga anrop till Meta och laddar inget från Metas servrar.', 'finet-troubleshoot' ) );
		$labels = array( 'saved' => __( 'Sparat.', 'finet-troubleshoot' ), 'connected' => __( 'Kontot är anslutet.', 'finet-troubleshoot' ), 'disconnected' => __( 'Kontot är frånkopplat.', 'finet-troubleshoot' ), 'queued' => __( 'Synken körs i bakgrunden.', 'finet-troubleshoot' ), 'refreshed' => __( 'Token förnyad.', 'finet-troubleshoot' ) );
		if ( 'error' === $notice ) { View::notice( 'error', $message ?: __( 'Något gick fel.', 'finet-troubleshoot' ) ); }
		elseif ( isset( $labels[ $notice ] ) ) { View::notice( 'success', $message ?: $labels[ $notice ] ); }
		if ( ! Modules::enabled( 'instagram' ) ) { View::notice( 'info', __( 'Modulen är avstängd. Slå på den under Moduler & integrationer för att schemalägga hämtning och visa flödet.', 'finet-troubleshoot' ) ); }

		echo '<div class="wpf-grid">';
		View::card_open( __( 'Status', 'finet-troubleshoot' ) );
		echo '<div class="wpf-status">' . View::badge( $health['connected'] ? __( 'Ansluten', 'finet-troubleshoot' ) : __( 'Ej ansluten', 'finet-troubleshoot' ), $health['connected'] ? 'ok' : 'warn' ) . ( $health['circuit_open'] ? View::badge( __( 'API pausat efter fel', 'finet-troubleshoot' ), 'error' ) : '' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		echo '<dl class="wpf-dl"><dt>' . esc_html__( 'Konto', 'finet-troubleshoot' ) . '</dt><dd>' . esc_html( $health['username'] ? '@' . $health['username'] . ' (' . $health['account_type'] . ')' : '–' ) . '</dd>';
		echo '<dt>' . esc_html__( 'Token', 'finet-troubleshoot' ) . '</dt><dd>' . esc_html( array( 'constant' => 'wp-config.php', 'database' => __( 'krypterad i databasen', 'finet-troubleshoot' ), 'none' => '–' )[ $health['token_source'] ] ) . ( $health['token_expires'] ? ' · ' . esc_html( sprintf( __( 'går ut %s', 'finet-troubleshoot' ), wp_date( 'Y-m-d', $health['token_expires'] ) ) ) : '' ) . '</dd>';
		echo '<dt>' . esc_html__( 'Lokalt sparade inlägg', 'finet-troubleshoot' ) . '</dt><dd>' . (int) $health['posts'] . '</dd>';
		echo '<dt>' . esc_html__( 'Senaste lyckade hämtning', 'finet-troubleshoot' ) . '</dt><dd>' . esc_html( View::time_ago( $health['last_sync'] ) ) . '</dd>';
		echo '<dt>' . esc_html__( 'Senaste resultat', 'finet-troubleshoot' ) . '</dt><dd>' . esc_html( $health['last_result'] ?: '–' ) . '</dd>';
		echo '<dt>' . esc_html__( 'Nästa hämtning', 'finet-troubleshoot' ) . '</dt><dd>' . esc_html( $health['next_sync'] ? wp_date( 'Y-m-d H:i', $health['next_sync'] ) : '–' ) . '</dd></dl><div class="wpf-actions">';
		if ( $health['connected'] ) {
			self::form( 'sync', '', __( 'Hämta nu (i bakgrunden)', 'finet-troubleshoot' ), 'button button-primary' );
			self::form( 'refresh', '', __( 'Förnya token', 'finet-troubleshoot' ) );
			self::form( 'disconnect', '', __( 'Koppla från', 'finet-troubleshoot' ), 'button button-link-delete' );
		}
		echo '</div>';
		View::card_close();

		View::card_open( __( 'Anslut konto', 'finet-troubleshoot' ) );
		echo '<p>' . esc_html__( 'Kräver ett professionellt Instagramkonto (Business eller Creator) och en Meta-app med produkten "Instagram API with Instagram Login" och behörigheten instagram_business_basic.', 'finet-troubleshoot' ) . '</p>';
		echo '<h3>' . esc_html__( 'Alternativ A: klistra in långlivad token', 'finet-troubleshoot' ) . '</h3><p class="wpf-muted">' . esc_html__( 'Genereras i Meta App Dashboard. Token verifieras mot API:t innan den sparas krypterat och förnyas automatiskt innan den går ut (60 dagar).', 'finet-troubleshoot' ) . '</p>';
		self::form( 'token', '<div class="wpf-field"><label class="wpf-field__label" for="wpf-ig-token">' . esc_html__( 'Access token', 'finet-troubleshoot' ) . '</label><input id="wpf-ig-token" type="password" name="token" class="large-text" autocomplete="off" required></div>', __( 'Verifiera och spara', 'finet-troubleshoot' ), 'button button-primary' );
		echo '<h3>' . esc_html__( 'Alternativ B: logga in med Instagram (OAuth)', 'finet-troubleshoot' ) . '</h3><p class="wpf-muted">' . esc_html( sprintf( __( 'Registrera omdirigerings-URI:n %s i Meta-appen (verifiera att Meta godtar URI:n med query-parameter).', 'finet-troubleshoot' ), InstagramService::redirect_uri() ) ) . '</p>';
		echo '<p>' . esc_html( sprintf( __( 'App-hemlighet: %s', 'finet-troubleshoot' ), array( 'constant' => 'wp-config.php', 'database' => __( 'sparad (krypterad)', 'finet-troubleshoot' ), 'none' => __( 'saknas', 'finet-troubleshoot' ) )[ Secrets::source( 'instagram_app_secret' ) ] ) ) . '</p>';
		if ( 'constant' !== Secrets::source( 'instagram_app_secret' ) ) {
			self::form( 'app_secret', '<div class="wpf-field"><label class="wpf-field__label" for="wpf-ig-secret">' . esc_html__( 'Instagram app secret', 'finet-troubleshoot' ) . '</label><input id="wpf-ig-secret" type="password" name="app_secret" class="regular-text" autocomplete="new-password"></div>', __( 'Spara app-hemlighet', 'finet-troubleshoot' ) );
		}
		if ( $settings['app_id'] && 'none' !== Secrets::source( 'instagram_app_secret' ) ) {
			self::form( 'oauth_start', '', __( 'Logga in med Instagram', 'finet-troubleshoot' ), 'button button-primary' );
		}
		View::card_close();
		echo '</div>';

		View::card_open( __( 'Inställningar', 'finet-troubleshoot' ) );
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'wpfinet_instagram_save' );
		echo '<input type="hidden" name="action" value="wpfinet_instagram_save"><div class="wpf-grid"><div>';
		View::input( 'ig[app_id]', __( 'Instagram app-ID', 'finet-troubleshoot' ), (string) $settings['app_id'], __( 'Behövs bara för OAuth.', 'finet-troubleshoot' ) );
		View::input( 'ig[api_version]', __( 'API-version', 'finet-troubleshoot' ), (string) $settings['api_version'], __( 'T.ex. v23.0. Tomt = appens standardversion. Kontrollera aktuell version hos Meta.', 'finet-troubleshoot' ) );
		View::select( 'ig[sync_interval]', __( 'Hämtningsintervall', 'finet-troubleshoot' ), (string) $settings['sync_interval'], array( 'wpfinet_30min' => '30 min', 'hourly' => __( 'Varje timme', 'finet-troubleshoot' ), 'twicedaily' => __( 'Två gånger per dygn', 'finet-troubleshoot' ), 'daily' => __( 'Dagligen', 'finet-troubleshoot' ) ) );
		echo '</div><div>';
		View::input( 'ig[max_posts]', __( 'Antal inlägg att spara', 'finet-troubleshoot' ), (string) $settings['max_posts'], '', array( 'type' => 'number', 'min' => 1, 'max' => 50 ) );
		View::input( 'ig[caption_length]', __( 'Max tecken i bildtext', 'finet-troubleshoot' ), (string) $settings['caption_length'], '', array( 'type' => 'number', 'min' => 0, 'max' => 1000 ) );
		View::input( 'ig[profile_url]', __( 'Profillänk', 'finet-troubleshoot' ), (string) $settings['profile_url'], __( 'https://www.instagram.com/… – används som diskret reserv om inga inlägg finns.', 'finet-troubleshoot' ) );
		View::input( 'ig[fallback_text]', __( 'Länktext', 'finet-troubleshoot' ), (string) $settings['fallback_text'] );
		echo '</div></div>';
		submit_button( __( 'Spara inställningar', 'finet-troubleshoot' ) );
		echo '</form>';
		View::card_close();

		View::card_open( __( 'Användning och villkor', 'finet-troubleshoot' ) );
		echo '<p><code>[wpfinet_instagram_feed limit="6" columns="3" layout="grid" show_caption="true" show_date="true" title=""]</code></p>';
		echo '<p class="wpf-muted">' . esc_html__( 'Inlägg som tas bort på Instagram tas bort här vid nästa hämtning, inklusive lokala bildfiler. Kontrollera Metas plattformsvillkor för lagring och visning innan publicering. Länkar till Instagram är vanliga länkar; inga skript eller iframes från Meta laddas.', 'finet-troubleshoot' ) . '</p>';
		View::card_close();
		View::close();
	}
}
