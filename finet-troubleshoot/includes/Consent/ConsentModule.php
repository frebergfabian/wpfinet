<?php
/**
 * Consent manager runtime (mode "preview" for admins or "live" for everyone): banner, prior blocking, WP Consent API
 * registration, consent log endpoint, policy/settings shortcodes, footer link.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Consent;

use FinetTroubleshoot\Core\ClientIp;
use FinetTroubleshoot\Core\Modules;
use FinetTroubleshoot\Core\RateLimiter;

final class ConsentModule {
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'wpfinet_daily_maintenance', array( $this, 'maintenance' ) );
		add_filter( 'wpfinet_shortcode_wpfinet_cookie_policy', array( $this, 'policy_shortcode' ), 10, 2 );
		add_filter( 'wpfinet_shortcode_wpfinet_cookie_settings', array( $this, 'settings_button' ), 10, 2 );
		add_action( 'wp', array( $this, 'maybe_activate' ) );
	}

	/** Runs after the user is known, so "preview" can be limited to WPFINET administrators. */
	public function maybe_activate(): void {
		if ( is_admin() || ! ConsentService::instance()->internal_active() ) {
			return;
		}
		( new Blocker() )->register_hooks();
		add_filter( 'wp_get_consent_type', static fn () => 'optin', 20 );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ), 1 );
		add_action( 'wp_footer', array( $this, 'banner' ), 5 );
	}

	public function assets(): void {
		wp_enqueue_style( 'wpfinet-consent', FINET_TS_URL . 'assets/build/consent.css', array(), \FinetTroubleshoot\Core\Assets::ver( 'consent.css' ) );
		wp_enqueue_script( 'wpfinet-consent', FINET_TS_URL . 'assets/build/consent.js', array(), \FinetTroubleshoot\Core\Assets::ver( 'consent.js' ), array( 'in_footer' => true ) );
	}

	/** In preview mode visitors keep seeing the existing (WPConsent) policy; only WPFINET admins see the new one. */
	public function policy_shortcode( string $html, array $atts ): string {
		if ( ! ConsentService::instance()->internal_active() ) {
			return current_user_can( 'edit_posts' ) ? '<p class="wpfinet-module-off">' . esc_html__( 'WPFINET-cookiepolicyn visas för besökare när samtyckeshanteraren är live.', 'finet-troubleshoot' ) . '</p>' : '';
		}
		wp_enqueue_style( 'wpfinet-consent', FINET_TS_URL . 'assets/build/consent.css', array(), \FinetTroubleshoot\Core\Assets::ver( 'consent.css' ) );
		return Policy::render( false );
	}

	public function settings_button( string $html = '', array $atts = array() ): string {
		if ( ! ConsentService::instance()->internal_active() ) {
			return '';
		}
		$label = sanitize_text_field( (string) ( $atts['label'] ?? ConsentSettings::get()['settings_label'] ) );
		return '<button type="button" class="wpfinet-consent-link" data-wpfinet-consent-open>' . esc_html( $label ) . '</button>';
	}

	public function banner(): void {
		$s       = ConsentSettings::get();
		$policy  = $s['policy_page_id'] ? get_permalink( (int) $s['policy_page_id'] ) : '';
		$cleanup = array_map( static fn ( $r ) => array( 'pattern' => $r['pattern'], 'category' => $r['category'] ), ConsentSettings::parse_rules( (string) $s['cookie_cleanup'] ) );
		$config  = array( 'cookie' => ConsentService::COOKIE, 'lifetime' => (int) $s['lifetime_days'], 'policyVersion' => Policy::version(), 'reconsent' => (bool) $s['reconsent_on_policy_change'], 'logEnabled' => (bool) $s['log_enabled'], 'logEndpoint' => esc_url_raw( rest_url( 'wpfinet/v1/consent/log' ) ), 'cleanup' => $cleanup, 'preview' => 'preview' === Modules::consent_mode() );
		?>
		<div class="wpfinet-consent" data-wpfinet-consent-root data-config="<?php echo esc_attr( (string) wp_json_encode( $config ) ); ?>" hidden>
			<div class="wpfinet-consent__banner" role="region" aria-labelledby="wpfinet-consent-title" hidden>
				<h2 id="wpfinet-consent-title"><?php echo esc_html( (string) $s['title'] ); ?></h2>
				<p><?php echo esc_html( (string) $s['body'] ); ?><?php if ( $policy ) : ?> <a href="<?php echo esc_url( $policy ); ?>"><?php esc_html_e( 'Läs vår cookiepolicy', 'finet-troubleshoot' ); ?></a><?php endif; ?></p>
				<div class="wpfinet-consent__actions">
					<button type="button" data-action="accept"><?php echo esc_html( (string) $s['accept_label'] ); ?></button>
					<button type="button" data-action="reject"><?php echo esc_html( (string) $s['reject_label'] ); ?></button>
					<button type="button" data-action="customize"><?php echo esc_html( (string) $s['customize_label'] ); ?></button>
				</div>
			</div>
			<div class="wpfinet-consent__panel" role="dialog" aria-modal="true" aria-labelledby="wpfinet-consent-panel-title" hidden>
				<h2 id="wpfinet-consent-panel-title"><?php echo esc_html( (string) $s['settings_label'] ); ?></h2>
				<fieldset style="border:0;margin:0;padding:0"><legend class="screen-reader-text"><?php esc_html_e( 'Kategorier', 'finet-troubleshoot' ); ?></legend>
				<?php foreach ( ConsentSettings::CATEGORY_LABELS as $cat => $label ) : ?>
					<div class="wpfinet-consent__cat">
						<input type="checkbox" id="wpfinet-consent-<?php echo esc_attr( $cat ); ?>" name="<?php echo esc_attr( $cat ); ?>" <?php echo 'necessary' === $cat ? 'checked disabled' : ''; ?> aria-describedby="wpfinet-consent-<?php echo esc_attr( $cat ); ?>-d">
						<label for="wpfinet-consent-<?php echo esc_attr( $cat ); ?>"><?php echo esc_html( $label ); ?><?php echo 'necessary' === $cat ? ' ' . esc_html__( '(alltid på)', 'finet-troubleshoot' ) : ''; ?></label>
						<p id="wpfinet-consent-<?php echo esc_attr( $cat ); ?>-d"><?php echo esc_html( (string) $s['category_texts'][ $cat ] ); ?></p>
					</div>
				<?php endforeach; ?>
				</fieldset>
				<?php if ( $policy ) : ?><p><a href="<?php echo esc_url( $policy ); ?>"><?php esc_html_e( 'Läs vår cookiepolicy', 'finet-troubleshoot' ); ?></a></p><?php endif; ?>
				<div class="wpfinet-consent__actions">
					<button type="button" data-action="save"><?php echo esc_html( (string) $s['save_label'] ); ?></button>
					<button type="button" data-action="accept"><?php echo esc_html( (string) $s['accept_label'] ); ?></button>
					<button type="button" data-action="reject"><?php echo esc_html( (string) $s['reject_label'] ); ?></button>
				</div>
			</div>
		</div>
		<?php
		if ( ! empty( $s['footer_link'] ) ) {
			echo '<p class="wpfinet-consent-footer">' . $this->settings_button() . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in settings_button().
		}
	}

	public function routes(): void {
		register_rest_route( 'wpfinet/v1', '/consent/log', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'permission_callback' => '__return_true',
			'callback'            => array( $this, 'rest_log' ),
			'args'                => array( 'id' => array( 'type' => 'string', 'required' => true, 'maxLength' => 36 ), 'action' => array( 'type' => 'string', 'required' => true, 'maxLength' => 20 ), 'categories' => array( 'type' => 'array', 'maxItems' => 3 ), 'policy' => array( 'type' => 'string', 'maxLength' => 20 ) ),
		) );
	}

	/** Stores proof of the choice without IP/user agent: random ID, time, action, categories, policy version. */
	public function rest_log( \WP_REST_Request $request ): \WP_REST_Response {
		if ( empty( ConsentSettings::get()['log_enabled'] ) ) {
			return new \WP_REST_Response( array( 'stored' => false ), 200 );
		}
		if ( ! RateLimiter::hit( 'consent-log', ClientIp::resolve(), 20, MINUTE_IN_SECONDS ) ) {
			return new \WP_REST_Response( array( 'stored' => false ), 429 );
		}
		$ok = Inventory::log_consent( strtolower( (string) $request->get_param( 'id' ) ), (string) $request->get_param( 'action' ), array_map( 'strval', (array) $request->get_param( 'categories' ) ), (string) $request->get_param( 'policy' ) );
		return new \WP_REST_Response( array( 'stored' => $ok ), $ok ? 201 : 400, array( 'Cache-Control' => 'no-store' ) );
	}

	public function maintenance(): void {
		Inventory::cleanup_log( (int) ConsentSettings::get()['log_retention_days'] );
	}
}
