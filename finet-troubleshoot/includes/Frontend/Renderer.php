<?php
/**
 * Public guide renderer.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Frontend;

use FinetTroubleshoot\Activator;
use FinetTroubleshoot\Analytics\ConsentManager;
use FinetTroubleshoot\Integrations\WPForms;
use FinetTroubleshoot\PostTypes\GuidePostType;

final class Renderer {
	private static int $instance = 0;
	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}
	public function register_assets(): void {
		wp_register_style( 'finet-troubleshoot', FINET_TS_URL . 'assets/build/frontend.css', array(), \FinetTroubleshoot\Core\Assets::ver( 'frontend.css' ) );
		wp_register_script( 'finet-troubleshoot', FINET_TS_URL . 'assets/build/frontend.js', array(), \FinetTroubleshoot\Core\Assets::ver( 'frontend.js' ), true );
	}
	public function render( \WP_Post $post, array $attributes = array() ): string {
		$this->ensure_assets();
		++self::$instance;
		$id = 'finet-ts-instance-' . $post->ID . '-' . self::$instance;
		$data = $this->public_data( $post, $attributes );
		if ( empty( $data['nodes'] ) || empty( $data['start_node'] ) ) {
			return '<p class="finet-troubleshoot__error">' . esc_html__( 'Felsökningsguiden kunde inte laddas.', 'finet-troubleshoot' ) . '</p>';
		}
		$classes = array( 'finet-troubleshoot', 'finet-troubleshoot--' . sanitize_html_class( (string) $data['settings']['general']['animation'] ) );
		if ( ! empty( $attributes['class'] ) ) { $classes[] = sanitize_html_class( (string) $attributes['class'] ); }
		$style = $this->css_variables( $data['settings']['appearance'] );
		ob_start();
		do_action( 'finet_troubleshoot_before_render', $post->ID, $data );
		?>
		<section id="<?php echo esc_attr( $id ); ?>" class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" style="<?php echo esc_attr( $style ); ?>" data-finet-troubleshoot>
			<script type="application/json" class="finet-troubleshoot__data"><?php echo wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></script>
			<div class="finet-troubleshoot__mount" aria-live="polite"><?php echo empty( $data['preview'] ) ? GuideMarkup::render( $data ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in GuideMarkup. ?></div>
		</section>
		<?php
		do_action( 'finet_troubleshoot_after_render', $post->ID, $data );
		return (string) ob_get_clean();
	}
	/** @return array<string,mixed> */
	public function public_data( \WP_Post $post, array $attributes = array() ): array {
		$global = get_option( 'finet_ts_settings', Activator::default_settings() );
		$global = is_array( $global ) ? $global : Activator::default_settings();
		$guide_settings = get_post_meta( $post->ID, GuidePostType::META_SETTINGS, true );
		$guide_settings = is_array( $guide_settings ) ? $guide_settings : array();
		$settings = $this->merge_settings( $global, $guide_settings );
		if ( '' !== (string) ( $attributes['show_title'] ?? '' ) ) { $settings['general']['show_title'] = filter_var( $attributes['show_title'], FILTER_VALIDATE_BOOLEAN ); }
		if ( '' !== (string) ( $attributes['show_restart'] ?? '' ) ) { $settings['general']['show_restart'] = filter_var( $attributes['show_restart'], FILTER_VALIDATE_BOOLEAN ); }
		$nodes = get_post_meta( $post->ID, GuidePostType::META_NODES, true );
		$data = array(
			'guide_id'    => $post->ID,
			'guide_external_id' => (string) get_post_meta( $post->ID, GuidePostType::META_EXTERNAL_ID, true ),
			'title'       => $post->post_title,
			'description' => $post->post_excerpt,
			'start_node'  => get_post_meta( $post->ID, GuidePostType::META_START_NODE, true ),
			'nodes'       => is_array( $nodes ) ? $this->strip_admin_data( $nodes ) : array(),
			'settings'    => $settings,
			'support'     => $settings['support'],
			'analytics'   => array_merge( ( new ConsentManager() )->public_config(), array( 'enabled' => ! empty( $global['analytics']['enabled'] ), 'endpoint' => esc_url_raw( rest_url( 'wpfinet/v1/events' ) ) ) ),
			'outages_endpoint' => esc_url_raw( rest_url( 'wpfinet/v1/outages/current' ) ),
		);
		return apply_filters( 'finet_troubleshoot_guide_data', $data, $post->ID );
	}
	private function ensure_assets(): void {
		if ( ! wp_style_is( 'finet-troubleshoot', 'registered' ) ) { $this->register_assets(); }
		wp_enqueue_style( 'finet-troubleshoot' ); wp_enqueue_script( 'finet-troubleshoot' );
	}
	/** @return array<int,array<string,mixed>> */
	private function strip_admin_data( array $nodes ): array {
		$wpforms = new WPForms();
		return array_map(
			static function ( array $node ) use ( $wpforms ): array {
				unset( $node['admin_comment'] );
				$node['form_html'] = $wpforms->render( (array) ( $node['wpforms'] ?? array() ) );
				$node['cta_forms'] = array();
				foreach ( (array) ( $node['ctas'] ?? array() ) as $cta ) { if ( is_array( $cta ) && 'wpforms' === ( $cta['type'] ?? '' ) ) { $node['cta_forms'][ $cta['id'] ?? '' ] = $wpforms->render( $cta ); } }
				return $node;
			},
			array_values( array_filter( $nodes, 'is_array' ) )
		);
	}
	/** @return array<string,mixed> */
	private function merge_settings( array $global, array $guide ): array {
		$settings = array_replace_recursive( Activator::default_settings(), $global );
		if ( empty( $guide ) ) { return $settings; }
		if ( isset( $guide['general'] ) && is_array( $guide['general'] ) ) { $settings['general'] = array_replace( $settings['general'], $guide['general'] ); }
		if ( isset( $guide['support'] ) && is_array( $guide['support'] ) ) { $settings['support'] = array_replace( $settings['support'], $guide['support'] ); }
		if ( empty( $guide['use_global_design'] ) && isset( $guide['appearance'] ) && is_array( $guide['appearance'] ) ) { $settings['appearance'] = array_replace( $settings['appearance'], $guide['appearance'] ); }
		return $settings;
	}
	/** @param array<string,mixed> $appearance */
	/**
	 * Inline custom properties from the appearance settings. 3.1: values equal to the 2.x defaults (which were stored on
	 * every 2.x site) are treated as "not customised", so the Finet look of the stylesheet applies. Values an
	 * administrator actually changed still override it.
	 */
	private function css_variables( array $appearance ): string {
		$legacy = array( 'primary_color' => '#005baa', 'background' => 'transparent', 'card_background' => '#ffffff', 'border_color' => '#d8dee7', 'radius' => 8, 'spacing' => 24, 'max_width' => 760 );
		$custom = static function ( string $key ) use ( $appearance, $legacy ) {
			if ( ! isset( $appearance[ $key ] ) || '' === $appearance[ $key ] ) { return null; }
			$value = $appearance[ $key ];
			return strtolower( (string) $value ) === strtolower( (string) $legacy[ $key ] ) ? null : $value;
		};
		$properties = array();
		foreach ( array( 'primary_color' => '--wpfinet-primary', 'background' => '--wpfinet-background', 'card_background' => '--wpfinet-surface', 'border_color' => '--wpfinet-border' ) as $key => $property ) {
			$value = $custom( $key );
			$color = null === $value ? '' : \FinetTroubleshoot\Import\SchemaValidator::css_color( (string) $value );
			if ( '' !== $color ) {
				$properties[ $property ] = $color;
				if ( 'primary_color' === $key ) { $properties['--wpfinet-primary-hover'] = $color; }
				if ( 'card_background' === $key ) { $properties['--wpfinet-card-background'] = $color; }
			}
		}
		foreach ( array( 'radius' => array( '--wpfinet-radius', 0, 48 ), 'spacing' => array( '--wpfinet-space', 8, 64 ), 'max_width' => array( '--wpfinet-max-width', 280, 1440 ) ) as $key => [ $property, $min, $max ] ) {
			$value = $custom( $key );
			if ( null !== $value ) { $properties[ $property ] = min( $max, max( $min, absint( $value ) ) ) . 'px'; }
		}
		$style = '';
		foreach ( $properties as $property => $value ) { $style .= $property . ':' . sanitize_text_field( (string) $value ) . ';'; }
		return $style;
	}
}
