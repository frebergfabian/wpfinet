<?php
/**
 * E2E fixture: simulates third-party trackers that a theme / Elementor HTML widget would print.
 * Installed into wp-content/mu-plugins by tests/e2e/run-consent.sh only.
 */
add_action( 'wp_head', static function (): void {
	if ( ! is_page( 'tracker-test' ) ) { return; }
	echo '<script async src="https://www.googletagmanager.com/gtag/js?id=G-TEST123"></script>' . "\n";
	echo "<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)};gtag('js',new Date());document.cookie='_ga=GA1.1.e2e; path=/';</script>\n";
} );
add_filter( 'the_content', static function ( string $c ): string {
	return is_page( 'tracker-test' ) ? $c . '<iframe title="Video" width="320" height="180" src="https://www.youtube.com/embed/e2e"></iframe>' : $c;
} );
