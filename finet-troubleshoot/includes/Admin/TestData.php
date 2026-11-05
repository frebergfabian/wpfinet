<?php
/**
 * Fictitious test content for MANUAL testing on staging: private test pages, mock addresses for the address check,
 * demo Instagram posts and a tracker test page. Everything is marked and can be removed again. Refuses to run when
 * WP_ENVIRONMENT_TYPE is "production". Used by WPFinet → Diagnos and `wp wpfinet testdata create|remove`.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Admin;

use FinetTroubleshoot\Access\AccessService;
use FinetTroubleshoot\Access\AccessSettings;
use FinetTroubleshoot\Access\AddressIndex;
use FinetTroubleshoot\Core\Logger;
use FinetTroubleshoot\Core\Secrets;
use FinetTroubleshoot\Instagram\InstagramService;

final class TestData {
	public const MARKER = '_wpfinet_testdata';

	public static function allowed(): bool { return 'production' !== wp_get_environment_type(); }

	/** @return array<int,array{0:string,1:string,2:string}> slug, title, content */
	private static function pages(): array {
		return array(
			array( 'wpfinet-test-adress', 'WPFINET test: adressökning', '[wpfinet_access_check title="Kan du få fiber?"]' ),
			array( 'wpfinet-test-instagram', 'WPFINET test: Instagram', '[wpfinet_instagram_feed title="Följ oss" limit="6"]' ),
			array( 'wpfinet-test-cookies', 'WPFINET test: cookiepolicy', '<p>[wpfinet_cookie_settings]</p>[wpfinet_cookie_policy]' ),
			array( 'wpfinet-test-sparare', 'WPFINET test: spårare', '<!-- wp:html --><script async src="https://www.googletagmanager.com/gtag/js?id=G-TEST000"></script><script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments)};gtag("js",new Date());</script><iframe title="Testvideo" width="560" height="315" src="https://www.youtube-nocookie.com/embed/aqz-KE-bpKQ"></iframe><!-- /wp:html --><p>Sidan innehåller ett Google-tagg-skript och en YouTube-video för att testa förhandsblockering. Öppna utvecklarverktygens Nätverk-flik: före samtycke ska inga anrop gå till googletagmanager.com eller youtube-nocookie.com.</p>' ),
		);
	}

	/** @return array{ok:bool,lines:array<int,string>} */
	public static function create(): array {
		if ( ! self::allowed() ) {
			return array( 'ok' => false, 'lines' => array( 'Vägrar: WP_ENVIRONMENT_TYPE är production. Sätt staging i wp-config.php på testmiljön.' ) );
		}
		$lines = array();
		kses_remove_filters(); // The tracker page must keep its <script>/<iframe>.
		foreach ( self::pages() as [ $slug, $title, $content ] ) {
			$existing = get_page_by_path( $slug, OBJECT, 'page' );
			if ( $existing ) {
				$lines[] = 'Finns redan: ' . get_permalink( $existing );
				continue;
			}
			$id = wp_insert_post( wp_slash( array( 'post_type' => 'page', 'post_status' => 'private', 'post_name' => $slug, 'post_title' => $title, 'post_content' => $content ) ) );
			update_post_meta( (int) $id, self::MARKER, 1 );
			$lines[] = 'Skapade privat sida: ' . get_permalink( (int) $id );
		}
		kses_init_filters();
		if ( AccessSettings::mock_allowed() && '' === AccessSettings::get()['endpoint_path'] ) {
			AccessSettings::update( array( 'adapter' => 'mock' ) );
			update_option( 'wpfinet_testdata_access', 1, false );
			$r       = ( new AccessService() )->sync( false );
			$lines[] = 'Adressökning: testdata aktiverad. ' . $r['message'] . ' Testadresser: Exempelgatan 1 (ansluten), Exempelgatan 2 (ej ansluten), Exempelgatan 3 (kräver bokstav), Exempelgatan 3A (ansluten), Exempelgatan 3B (ej ansluten), Åtestvägen 5 (ansluten) / Atestvägen 5 (ej ansluten), Testallén 12B (ansluten).';
		} else {
			$lines[] = 'Adressökning: endpoint är redan konfigurerad – testdata aktiverades inte.';
		}
		if ( 'none' === Secrets::source( 'instagram_token' ) && ! InstagramService::media() && function_exists( 'imagecreatetruecolor' ) ) {
			$dir = InstagramService::upload_dir();
			wp_mkdir_p( $dir['path'] );
			$items = array();
			foreach ( array( array( 6, 40, 77 ), array( 244, 124, 32 ), array( 25, 99, 58 ), array( 60, 90, 160 ), array( 120, 130, 140 ), array( 160, 30, 40 ) ) as $i => $c ) {
				$img = imagecreatetruecolor( 600, 600 );
				imagefill( $img, 0, 0, (int) imagecolorallocate( $img, $c[0], $c[1], $c[2] ) );
				imagejpeg( $img, $dir['path'] . "/ig-test{$i}.jpg", 80 );
				$items[] = array( 'id' => "999{$i}", 'type' => 1 === $i ? 'VIDEO' : 'IMAGE', 'permalink' => 'https://www.instagram.com/', 'timestamp' => time() - $i * DAY_IN_SECONDS, 'caption' => 1 === $i ? '' : "Testinlägg {$i} (påhittat innehåll för manuell test).", 'file' => "ig-test{$i}.jpg", 'width' => 600, 'height' => 600 );
			}
			update_option( InstagramService::MEDIA, $items, false );
			update_option( 'wpfinet_testdata_instagram', 1, false );
			$lines[] = 'Instagram: 6 påhittade inlägg lagrade lokalt (inget konto anslutet).';
		} else {
			$lines[] = 'Instagram: konto eller inlägg finns redan – inga testinlägg skapades.';
		}
		$lines[] = 'Slå på modulerna under WPFinet → Moduler & integrationer. Testsidorna är privata (syns bara inloggad).';
		Logger::log( 'admin', 'info', 'Testdata skapad.', array( 'user' => get_current_user_id() ) );
		return array( 'ok' => true, 'lines' => $lines );
	}

	/** @return array{ok:bool,lines:array<int,string>} */
	public static function remove(): array {
		global $wpdb;
		$lines = array();
		$ids   = get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'meta_key' => self::MARKER, 'posts_per_page' => -1, 'fields' => 'ids' ) );
		foreach ( $ids as $id ) { wp_delete_post( (int) $id, true ); }
		$lines[] = sprintf( '%d testsidor borttagna.', count( $ids ) );
		if ( get_option( 'wpfinet_testdata_instagram' ) ) {
			foreach ( InstagramService::media() as $m ) { wp_delete_file( InstagramService::upload_dir()['path'] . '/' . basename( (string) $m['file'] ) ); }
			delete_option( InstagramService::MEDIA );
			delete_option( 'wpfinet_testdata_instagram' );
			$lines[] = 'Instagram: testinlägg borttagna.';
		}
		if ( get_option( 'wpfinet_testdata_access' ) ) {
			AccessSettings::update( array( 'adapter' => 'http' ) );
			$wpdb->query( 'DELETE FROM ' . AddressIndex::table() ); // phpcs:ignore WordPress.DB
			delete_option( AddressIndex::STATE_OPTION );
			delete_option( 'wpfinet_testdata_access' );
			$lines[] = 'Adressökning: testindexet är tömt och datakällan återställd till Finet Access API.';
		}
		Logger::log( 'admin', 'info', 'Testdata borttagen.', array( 'user' => get_current_user_id() ) );
		return array( 'ok' => true, 'lines' => $lines );
	}
}
