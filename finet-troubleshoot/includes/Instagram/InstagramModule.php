<?php
/**
 * [wpfinet_instagram_feed]: renders only locally stored posts and images. No request to Meta happens while a page is
 * rendered; if nothing is stored, a discreet profile link (or nothing) is shown.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Instagram;

final class InstagramModule {
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'wpfinet_instagram_sync', array( $this, 'sync' ) );
		add_action( 'wpfinet_instagram_sync_manual', array( $this, 'sync' ) );
		add_action( 'wpfinet_instagram_refresh_token', array( $this, 'refresh' ) );
		add_filter( 'wpfinet_shortcode_wpfinet_instagram_feed', array( $this, 'render' ), 10, 2 );
	}

	public function init(): void {
		wp_register_style( 'wpfinet-instagram', FINET_TS_URL . 'assets/build/instagram.css', array(), \FinetTroubleshoot\Core\Assets::ver( 'instagram.css' ) );
		$interval = (string) InstagramService::settings()['sync_interval'];
		if ( wp_next_scheduled( 'wpfinet_instagram_sync' ) && wp_get_schedule( 'wpfinet_instagram_sync' ) !== $interval ) {
			wp_clear_scheduled_hook( 'wpfinet_instagram_sync' );
		}
		if ( ! wp_next_scheduled( 'wpfinet_instagram_sync' ) ) { wp_schedule_event( time() + 120, $interval, 'wpfinet_instagram_sync' ); }
		if ( ! wp_next_scheduled( 'wpfinet_instagram_refresh_token' ) ) { wp_schedule_event( time() + 300, 'daily', 'wpfinet_instagram_refresh_token' ); }
	}

	public function sync(): void {
		if ( 'none' === \FinetTroubleshoot\Core\Secrets::source( 'instagram_token' ) ) { return; }
		( new InstagramService() )->sync();
	}

	public function refresh(): void { ( new InstagramService() )->maybe_refresh_token(); }

	/** @param array<string,string> $atts */
	public function render( string $html, array $atts ): string {
		$settings = InstagramService::settings();
		$atts     = shortcode_atts( array( 'limit' => (string) $settings['max_posts'], 'columns' => '3', 'layout' => 'grid', 'show_caption' => 'true', 'show_date' => 'true', 'title' => '', 'class' => '' ), $atts, 'wpfinet_instagram_feed' );
		$items    = array_slice( InstagramService::media(), 0, min( 50, max( 1, absint( $atts['limit'] ) ) ) );
		$state    = InstagramService::state();
		$profile  = (string) $settings['profile_url'] ?: ( $state['username'] ? 'https://www.instagram.com/' . rawurlencode( (string) $state['username'] ) . '/' : '' );
		wp_enqueue_style( 'wpfinet-instagram' );
		if ( ! $items ) {
			return $profile ? '<p class="wpfinet-instagram__fallback"><a href="' . esc_url( $profile ) . '" rel="noopener">' . esc_html( (string) $settings['fallback_text'] ) . '</a></p>' : '';
		}
		$dir     = InstagramService::upload_dir();
		$columns = min( 6, max( 1, absint( $atts['columns'] ) ) );
		$layout  = 'list' === $atts['layout'] ? 'list' : 'grid';
		$captions = filter_var( $atts['show_caption'], FILTER_VALIDATE_BOOLEAN );
		$dates    = filter_var( $atts['show_date'], FILTER_VALIDATE_BOOLEAN );
		$length   = (int) $settings['caption_length'];
		$out = '<section class="wpfinet-instagram wpfinet-instagram--' . esc_attr( $layout ) . ' ' . esc_attr( sanitize_html_class( $atts['class'] ) ) . '" style="--wpfinet-ig-columns:' . (int) $columns . '">';
		if ( $atts['title'] ) { $out .= '<h2 class="wpfinet-instagram__title">' . esc_html( $atts['title'] ) . '</h2>'; }
		$out .= '<ul class="wpfinet-instagram__list">';
		foreach ( $items as $item ) {
			$caption = (string) $item['caption'];
			$short   = $length && mb_strlen( $caption ) > $length ? rtrim( mb_substr( $caption, 0, $length ) ) . '…' : $caption;
			$alt     = $caption ? mb_substr( $caption, 0, 120 ) : sprintf( /* translators: %s username */ __( 'Instagraminlägg från @%s', 'finet-troubleshoot' ), (string) $state['username'] );
			$out    .= '<li class="wpfinet-instagram__item"><a class="wpfinet-instagram__link" href="' . esc_url( (string) $item['permalink'] ) . '" rel="noopener"><img src="' . esc_url( $dir['url'] . '/' . rawurlencode( (string) $item['file'] ) ) . '" alt="' . esc_attr( $alt ) . '" width="640" height="640" loading="lazy" decoding="async"' . ( $item['width'] ? ' width="' . (int) $item['width'] . '" height="' . (int) $item['height'] . '"' : '' ) . '>' . ( 'VIDEO' === $item['type'] ? '<span class="wpfinet-instagram__badge">' . esc_html__( 'Video', 'finet-troubleshoot' ) . '</span>' : '' ) . '<span class="screen-reader-text">' . esc_html__( '(öppnas på Instagram)', 'finet-troubleshoot' ) . '</span></a>';
			if ( ( $captions && $short ) || ( $dates && $item['timestamp'] ) ) {
				$out .= '<div class="wpfinet-instagram__meta">' . ( $dates && $item['timestamp'] ? '<time datetime="' . esc_attr( gmdate( 'c', (int) $item['timestamp'] ) ) . '">' . esc_html( wp_date( (string) get_option( 'date_format' ), (int) $item['timestamp'] ) ) . '</time>' : '' ) . ( $captions && $short ? '<p>' . esc_html( $short ) . '</p>' : '' ) . '</div>';
			}
			$out .= '</li>';
		}
		$out .= '</ul>';
		if ( $profile ) { $out .= '<p class="wpfinet-instagram__more"><a href="' . esc_url( $profile ) . '" rel="noopener">' . esc_html( (string) $settings['fallback_text'] ) . '</a></p>'; }
		return $out . '</section>';
	}
}
