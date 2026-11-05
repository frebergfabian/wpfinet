<?php
/**
 * Versioned cookie policy. Visitors only ever see the latest APPROVED version (a frozen snapshot of the verified
 * inventory at approval time). Everything else is a draft. Nothing here claims legal compliance.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Consent;

final class Policy {
	public const OPTION = 'wpfinet_consent_policy';

	/** @return array{draft_intro:string,versions:array<int,array<string,mixed>>} */
	public static function data(): array {
		$d = get_option( self::OPTION, array() );
		$d = is_array( $d ) ? $d : array();
		return array(
			'draft_intro' => (string) ( $d['draft_intro'] ?? "Den här sidan beskriver vilka cookies och liknande tekniker (till exempel localStorage) som används på webbplatsen, varför de används och hur länge de sparas.\n\nNödvändiga cookies används alltid. Övriga används bara om du samtycker. Du kan ändra eller återkalla ditt samtycke när som helst via knappen nedan. Uppgifter som redan har skickats till tredje part innan du återkallade ditt samtycke kan vi inte hämta tillbaka; kontakta respektive leverantör enligt deras integritetspolicy." ),
			'versions'    => array_values( array_filter( (array) ( $d['versions'] ?? array() ), 'is_array' ) ),
		);
	}

	public static function save_draft( string $intro ): void {
		$d = self::data();
		$d['draft_intro'] = wp_kses( $intro, array( 'p' => array(), 'br' => array(), 'strong' => array(), 'em' => array(), 'a' => array( 'href' => true ), 'ul' => array(), 'ol' => array(), 'li' => array() ) );
		update_option( self::OPTION, $d, false );
	}

	/** @return array<string,mixed>|null */
	public static function published(): ?array {
		$v = self::data()['versions'];
		return $v ? end( $v ) : null;
	}

	public static function version(): string {
		$p = self::published();
		return $p ? (string) $p['version'] : '0';
	}

	/** Freezes the verified inventory + intro into a new version. */
	public static function approve( int $user_id, string $note ): array {
		$d     = self::data();
		$items = array_map( static fn ( $r ) => array_intersect_key( $r, array_flip( array( 'name', 'domain', 'storage_type', 'party', 'category', 'provider', 'purpose', 'lifetime' ) ) ), Inventory::all( 'verified' ) );
		$version = array( 'version' => (string) ( count( $d['versions'] ) + 1 ), 'approved_at' => gmdate( 'c' ), 'approved_by' => $user_id, 'note' => sanitize_text_field( $note ), 'intro' => $d['draft_intro'], 'items' => $items );
		$d['versions'][] = $version;
		$d['versions']   = array_slice( $d['versions'], -30 );
		update_option( self::OPTION, $d, false );
		return $version;
	}

	public static function render( bool $draft = false ): string {
		$v = $draft ? array( 'version' => 'utkast', 'approved_at' => '', 'intro' => self::data()['draft_intro'], 'items' => Inventory::all( 'verified' ) ) : self::published();
		if ( ! $v ) {
			return current_user_can( 'manage_wpfinet_settings' ) ? '<p class="wpfinet-policy__draft">' . esc_html__( 'Cookiepolicyn har inte godkänts ännu (WPFINET → Cookies & samtycke → Policy).', 'finet-troubleshoot' ) . '</p>' : '';
		}
		$settings = ConsentSettings::get();
		$out = '<section class="wpfinet-policy">' . wpautop( wp_kses_post( (string) $v['intro'] ) );
		$out .= '<p class="wpfinet-policy__version">' . esc_html( sprintf( __( 'Version %1$s%2$s', 'finet-troubleshoot' ), $v['version'], $v['approved_at'] ? ', ' . wp_date( 'Y-m-d', (int) strtotime( (string) $v['approved_at'] ) ) : '' ) ) . '</p>';
		$out .= '<p><button type="button" class="wpfinet-policy__button" data-wpfinet-consent-open>' . esc_html( (string) $settings['settings_label'] ) . '</button></p>';
		foreach ( ConsentSettings::CATEGORY_LABELS as $cat => $label ) {
			$rows = array_values( array_filter( (array) $v['items'], static fn ( $i ) => $cat === $i['category'] ) );
			$out .= '<h3>' . esc_html( $label ) . '</h3><p>' . esc_html( (string) $settings['category_texts'][ $cat ] ) . '</p>';
			if ( ! $rows ) { $out .= '<p class="wpfinet-policy__none">' . esc_html__( 'Inga i denna kategori.', 'finet-troubleshoot' ) . '</p>'; continue; }
			$out .= '<div class="wpfinet-policy__table"><table><thead><tr><th scope="col">' . esc_html__( 'Namn', 'finet-troubleshoot' ) . '</th><th scope="col">' . esc_html__( 'Leverantör', 'finet-troubleshoot' ) . '</th><th scope="col">' . esc_html__( 'Syfte', 'finet-troubleshoot' ) . '</th><th scope="col">' . esc_html__( 'Lagringstid', 'finet-troubleshoot' ) . '</th><th scope="col">' . esc_html__( 'Typ', 'finet-troubleshoot' ) . '</th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$out .= '<tr><td><code>' . esc_html( (string) $r['name'] ) . '</code>' . ( $r['domain'] ? '<br><small>' . esc_html( (string) $r['domain'] ) . '</small>' : '' ) . '</td><td>' . esc_html( (string) $r['provider'] ) . ( 'third' === $r['party'] ? ' ' . esc_html__( '(tredje part)', 'finet-troubleshoot' ) : '' ) . '</td><td>' . esc_html( (string) $r['purpose'] ) . '</td><td>' . esc_html( (string) $r['lifetime'] ) . '</td><td>' . esc_html( (string) $r['storage_type'] ) . '</td></tr>';
			}
			$out .= '</tbody></table></div>';
		}
		return $out . '</section>';
	}
}
