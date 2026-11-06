<?php
/**
 * Turns a page recorded from dev.finet.se (plugin 3.1.0 markup) into what the CURRENT plugin code would output on the
 * same page, using the plugin's own PHP:
 * - guide mounts are filled with GuideMarkup::render() of the guide data embedded in the page;
 * - WPFINET stylesheets printed in the footer are moved into <head> (what HeadStyles/OutageModule::head_assets do);
 * - the outage banner is replaced by OutageModule::banner_html() and the <head> state script is added.
 * Mode "fallback" simulates a theme without wp_body_open (banner printed in the footer, fallback variant).
 *
 * wp eval-file transform.php <in.html> <out.html> [normal|fallback]
 */
use FinetTroubleshoot\Frontend\GuideMarkup;
use FinetTroubleshoot\Outages\OutageModule;

[ $in, $out ] = $args;
$mode = $args[2] ?? 'normal';
$html = (string) file_get_contents( $in );

// 1. Server-rendered guides.
$html = (string) preg_replace_callback(
	'#(<script type="application/json" class="finet-troubleshoot__data">)(.*?)(</script>\s*<div class="finet-troubleshoot__mount"[^>]*>)(</div>)#s',
	static function ( array $m ): string {
		$data = json_decode( $m[2], true );
		return $m[1] . $m[2] . $m[3] . ( is_array( $data ) ? GuideMarkup::render( $data ) : '' ) . $m[4];
	},
	$html
);

// 2. WPFINET stylesheets into <head>.
$body_start = strpos( $html, '<body' );
$links      = array();
$html       = substr( $html, 0, $body_start ) . (string) preg_replace_callback(
	"#<link rel='stylesheet' id='(wpfinet-[a-z-]+|finet-troubleshoot)-css'[^>]*>\s*#",
	static function ( array $m ) use ( &$links ): string { $links[] = trim( $m[0] ); return ''; },
	substr( $html, $body_start )
);

// 3. Banner.
$state = '';
if ( preg_match( '#<aside class="wpfinet-banner wpfinet-banner--(acute|planned)"[^>]*data-version="([a-f0-9]+)".*?</aside>#s', $html, $b ) ) {
	preg_match( '#wpfinet-banner__label">([^<]*)</span>#', $b[0], $label );
	preg_match( '#wpfinet-banner__title">([^<]*)</strong>#', $b[0], $title );
	preg_match( '#wpfinet-banner__link" href="([^"]+)"#', $b[0], $url );
	preg_match( '#wpfinet-banner__more" href="([^"]+)">\+(\d+)#', $b[0], $more );
	$status = trim( (string) preg_replace( '/^[^·]*·\s*/u', '', html_entity_decode( $label[1] ?? '' ) ) );
	$data   = array( 'type' => $b[1], 'status' => $status, 'title' => html_entity_decode( $title[1] ?? '' ), 'url' => html_entity_decode( $url[1] ?? '' ), 'version' => $b[2], 'more' => (int) ( $more[2] ?? 0 ), 'list_url' => html_entity_decode( $more[1] ?? '' ) );
	$html   = str_replace( $b[0], 'fallback' === $mode ? '' : OutageModule::banner_html( $data, false ), $html );
	if ( 'fallback' === $mode ) { $html = str_replace( '</body>', OutageModule::banner_html( $data, true ) . '</body>', $html ); }
	$state = "<script id='wpfinet-banner-state-js-after'>\n" . OutageModule::state_script( $data['version'] ) . "\n</script>\n";
}
$html = str_replace( '</head>', implode( "\n", $links ) . "\n" . $state . '</head>', $html );
file_put_contents( $out, $html );
echo wp_json_encode( array( 'guides' => substr_count( $html, 'data-ssr=' ), 'head_styles' => count( $links ), 'banner' => '' !== $state ) );
