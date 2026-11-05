<?php
/**
 * Address check module: [wpfinet_access_check] shortcode + block, POST /wpfinet/v1/access/check (one address per
 * request, rate limited, no caching) and the scheduled index sync. Rendering never performs network calls.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Access;

use FinetTroubleshoot\Core\ClientIp;
use FinetTroubleshoot\Core\RateLimiter;

final class AccessModule {
	public const CRON = 'wpfinet_access_sync';

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( self::CRON, array( $this, 'run_sync' ) );
		add_filter( 'wpfinet_shortcode_wpfinet_access_check', array( $this, 'render' ), 10, 2 );
	}

	public function init(): void {
		wp_register_style( 'wpfinet-access', FINET_TS_URL . 'assets/build/access.css', array(), FINET_TS_VERSION );
		wp_register_script( 'wpfinet-access', FINET_TS_URL . 'assets/build/access.js', array(), FINET_TS_VERSION, array( 'in_footer' => true, 'strategy' => 'defer' ) );
		self::ensure_schedule();
	}

	public static function ensure_schedule(): void {
		$settings = AccessSettings::get();
		$needs    = 'index' === $settings['mode'] || 'mock' === $settings['adapter'];
		$next     = wp_next_scheduled( self::CRON );
		if ( ! $needs ) {
			if ( $next ) { wp_clear_scheduled_hook( self::CRON ); }
			return;
		}
		if ( $next && wp_get_schedule( self::CRON ) !== $settings['sync_interval'] ) {
			wp_clear_scheduled_hook( self::CRON );
			$next = false;
		}
		if ( ! $next ) {
			wp_schedule_event( time() + 60, (string) $settings['sync_interval'], self::CRON );
		}
	}

	public function run_sync(): void {
		( new AccessService() )->sync( true );
	}

	public function routes(): void {
		register_rest_route( 'wpfinet/v1', '/access/check', array(
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => array( $this, 'rest_check' ),
			'permission_callback' => '__return_true',
			'args'                => array(
				'address' => array( 'type' => 'string', 'required' => true, 'maxLength' => Normalizer::MAX_LENGTH ),
				'website' => array( 'type' => 'string', 'required' => false, 'maxLength' => 200 ),
			),
		) );
	}

	public function rest_check( \WP_REST_Request $request ): \WP_REST_Response {
		$settings = AccessSettings::get();
		$headers  = array( 'Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex' );
		if ( ! RateLimiter::hit( 'access', ClientIp::resolve( ! empty( $settings['trust_cloudflare'] ) ), (int) $settings['rate_limit'], MINUTE_IN_SECONDS ) ) {
			return new \WP_REST_Response( array( 'status' => 'rate_limited', 'message' => __( 'För många sökningar. Vänta en minut och försök igen.', 'finet-troubleshoot' ) ), 429, $headers + array( 'Retry-After' => '60' ) );
		}
		$origin = (string) $request->get_header( 'origin' );
		if ( $origin && strtolower( (string) wp_parse_url( $origin, PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) && ! in_array( $origin, (array) apply_filters( 'wpfinet_access_allowed_origins', array() ), true ) ) {
			return new \WP_REST_Response( array( 'status' => 'forbidden_origin', 'message' => '' ), 403, $headers ); // Other websites may not embed the lookup.
		}
		$service = new AccessService( $settings );
		if ( '' !== (string) $request->get_param( 'website' ) ) {
			return new \WP_REST_Response( $this->public( $service->result( 'invalid' ) ), 400, $headers ); // Honeypot.
		}
		$result = $service->lookup( (string) $request->get_param( 'address' ) );
		return new \WP_REST_Response( $this->public( $result ), 'invalid' === $result['status'] ? 400 : 200, $headers );
	}

	/** Minimal public response: never the address list, never the raw upstream state. @return array<string,mixed> */
	private function public( array $result ): array {
		return array( 'status' => $result['status'], 'message' => $result['message'], 'cta' => $result['cta'], 'stale' => $result['stale'], 'checked_at' => $result['checked_at'], 'test_data' => 'mock' === $result['source'] );
	}

	/** @param array<string,string> $atts */
	public function render( string $html, array $atts ): string {
		$atts = shortcode_atts( array( 'title' => '', 'button' => __( 'Sök', 'finet-troubleshoot' ), 'label' => __( 'Din adress', 'finet-troubleshoot' ), 'class' => '' ), $atts, 'wpfinet_access_check' );
		wp_enqueue_style( 'wpfinet-access' );
		wp_enqueue_script( 'wpfinet-access' );
		static $n = 0;
		$id   = 'wpfinet-access-' . ( ++$n );
		$mock = 'mock' === AccessSettings::get()['adapter'];
		ob_start();
		?>
		<section class="wpfinet-access <?php echo esc_attr( sanitize_html_class( $atts['class'] ) ); ?>" data-wpfinet-access data-endpoint="<?php echo esc_url( rest_url( 'wpfinet/v1/access/check' ) ); ?>">
			<?php if ( $atts['title'] ) : ?><h2 class="wpfinet-access__title"><?php echo esc_html( $atts['title'] ); ?></h2><?php endif; ?>
			<form class="wpfinet-access__form" novalidate>
				<label class="wpfinet-access__label" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $atts['label'] ); ?></label>
				<p class="wpfinet-access__hint" id="<?php echo esc_attr( $id ); ?>-hint"><?php esc_html_e( 'Gata, nummer och eventuell bokstav, till exempel Storgatan 12B.', 'finet-troubleshoot' ); ?></p>
				<div class="wpfinet-access__row">
					<input class="wpfinet-access__input" id="<?php echo esc_attr( $id ); ?>" name="address" type="text" inputmode="text" autocomplete="street-address" maxlength="<?php echo (int) Normalizer::MAX_LENGTH; ?>" required aria-describedby="<?php echo esc_attr( $id ); ?>-hint <?php echo esc_attr( $id ); ?>-result">
					<button class="wpfinet-access__button" type="submit"><?php echo esc_html( $atts['button'] ); ?></button>
				</div>
				<div class="wpfinet-access__hp" aria-hidden="true"><label>Webbplats <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
			</form>
			<div class="wpfinet-access__result" id="<?php echo esc_attr( $id ); ?>-result" role="status" aria-live="polite"></div>
			<?php if ( $mock ) : ?><p class="wpfinet-access__test"><?php esc_html_e( 'Testläge: sökningen använder påhittade testadresser.', 'finet-troubleshoot' ); ?></p><?php endif; ?>
			<noscript><p><?php esc_html_e( 'Adresskontrollen kräver JavaScript.', 'finet-troubleshoot' ); ?> <a href="<?php echo esc_url( (string) AccessSettings::get()['cta_not_connected_url'] ); ?>"><?php echo esc_html( (string) AccessSettings::get()['cta_not_connected_label'] ); ?></a></p></noscript>
		</section>
		<?php
		return (string) ob_get_clean();
	}
}
