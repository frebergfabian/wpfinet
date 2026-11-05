<?php
/**
 * Consent manager settings: banner texts, policy page, cookie lifetime, blocking rules.
 * Default blocking rules are suggestions for common services – they must be reviewed against the real inventory.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Consent;

final class ConsentSettings {
	public const OPTION = 'wpfinet_consent_settings';
	public const CATEGORY_LABELS = array( 'necessary' => 'Nödvändiga', 'preferences' => 'Inställningar/Funktionella', 'statistics' => 'Statistik', 'marketing' => 'Marknadsföring' );

	/** @return array<string,mixed> */
	public static function defaults(): array {
		return array(
			'title'           => 'Vi använder cookies',
			'body'            => 'Nödvändiga cookies behövs för att webbplatsen ska fungera. Med ditt samtycke använder vi även cookies och liknande tekniker för inställningar, statistik och marknadsföring. Du kan när som helst ändra eller återkalla ditt val under ”Cookieinställningar”.',
			'accept_label'    => 'Acceptera alla',
			'reject_label'    => 'Neka valfria',
			'customize_label' => 'Anpassa',
			'save_label'      => 'Spara val',
			'settings_label'  => 'Cookieinställningar',
			'category_texts'  => array(
				'necessary'   => 'Krävs för grundläggande funktioner som säkerhet, inloggning och att komma ihåg ditt cookieval. Kan inte stängas av.',
				'preferences' => 'Kommer ihåg val du gör och möjliggör inbäddat innehåll, till exempel kartor.',
				'statistics'  => 'Hjälper oss förstå hur webbplatsen används, till exempel anonym statistik över felsökningsguider.',
				'marketing'   => 'Används av oss och tredje part för att visa relevant innehåll och mäta kampanjer, till exempel inbäddade videor och sociala medier.',
			),
			'policy_page_id'  => 0,
			'lifetime_days'   => 180,
			'reconsent_on_policy_change' => true,
			'footer_link'     => true,
			'log_enabled'     => true,
			'log_retention_days' => 730,
			'rules'           => implode( "\n", array(
				'googletagmanager.com | statistics | Google Tag Manager/Analytics',
				'google-analytics.com | statistics | Google Analytics',
				'clarity.ms | statistics | Microsoft Clarity',
				'hotjar.com | statistics | Hotjar',
				'connect.facebook.net | marketing | Meta Pixel',
				'snap.licdn.com | marketing | LinkedIn Insight',
				'analytics.tiktok.com | marketing | TikTok Pixel',
				'youtube.com/embed | marketing | YouTube',
				'youtube-nocookie.com | marketing | YouTube (no-cookie)',
				'player.vimeo.com | marketing | Vimeo',
				'instagram.com/embed | marketing | Instagram-inbäddning',
				'platform.instagram.com | marketing | Instagram-inbäddning',
				'google.com/maps | preferences | Google Maps',
				'maps.googleapis.com | preferences | Google Maps',
			) ),
			'inline_rules'    => implode( "\n", array( 'gtag( | statistics', 'googletagmanager.com/gtm.js | statistics', 'fbq( | marketing', '_hjSettings | statistics', 'clarity( | statistics', '_linkedin_partner_id | marketing', 'ttq.load | marketing' ) ),
			'handle_rules'    => '',
			'cookie_cleanup'  => implode( "\n", array( '_ga* | statistics', '_gid | statistics', '_gat* | statistics', '_hj* | statistics', '_clck | statistics', '_clsk | statistics', '_fbp | marketing', '_fbc | marketing', 'li_* | marketing', '_ttp | marketing' ) ),
		);
	}

	/** @return array<string,mixed> */
	public static function get(): array {
		$s = get_option( self::OPTION, array() );
		$s = array_replace( self::defaults(), is_array( $s ) ? $s : array() );
		$s['category_texts'] = array_replace( self::defaults()['category_texts'], is_array( $s['category_texts'] ) ? $s['category_texts'] : array() );
		return $s;
	}

	/** @param array<string,mixed> $in */
	public static function update( array $in ): void {
		$cur = self::get();
		$d   = self::defaults();
		foreach ( array( 'title', 'accept_label', 'reject_label', 'customize_label', 'save_label', 'settings_label' ) as $k ) {
			if ( isset( $in[ $k ] ) ) { $cur[ $k ] = sanitize_text_field( (string) $in[ $k ] ) ?: $d[ $k ]; }
		}
		if ( isset( $in['body'] ) ) { $cur['body'] = sanitize_textarea_field( (string) $in['body'] ) ?: $d['body']; }
		if ( isset( $in['category_texts'] ) && is_array( $in['category_texts'] ) ) {
			foreach ( array_keys( $d['category_texts'] ) as $c ) { if ( isset( $in['category_texts'][ $c ] ) ) { $cur['category_texts'][ $c ] = sanitize_textarea_field( (string) $in['category_texts'][ $c ] ) ?: $d['category_texts'][ $c ]; } }
		}
		if ( isset( $in['policy_page_id'] ) ) { $page = get_post( absint( $in['policy_page_id'] ) ); $cur['policy_page_id'] = $page && 'page' === $page->post_type ? $page->ID : 0; }
		if ( isset( $in['lifetime_days'] ) ) { $cur['lifetime_days'] = min( 395, max( 1, absint( $in['lifetime_days'] ) ) ); }
		if ( isset( $in['log_retention_days'] ) ) { $cur['log_retention_days'] = min( 3650, max( 30, absint( $in['log_retention_days'] ) ) ); }
		if ( isset( $in['_form'] ) ) {
			foreach ( array( 'reconsent_on_policy_change', 'footer_link', 'log_enabled' ) as $k ) { $cur[ $k ] = ! empty( $in[ $k ] ); }
		}
		foreach ( array( 'rules', 'inline_rules', 'handle_rules', 'cookie_cleanup' ) as $k ) {
			if ( isset( $in[ $k ] ) ) { $cur[ $k ] = self::clean_rules( (string) $in[ $k ] ); }
		}
		update_option( self::OPTION, $cur, false );
	}

	public static function clean_rules( string $text ): string {
		$lines = array();
		foreach ( preg_split( '/\R/', $text ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( count( $parts ) < 2 || '' === $parts[0] || ! in_array( $parts[1], array( 'preferences', 'statistics', 'marketing' ), true ) ) { continue; }
			$lines[] = implode( ' | ', array( sanitize_text_field( $parts[0] ), $parts[1], sanitize_text_field( $parts[2] ?? '' ) ) );
		}
		return implode( "\n", array_slice( array_values( array_unique( $lines ) ), 0, 200 ) );
	}

	/** @return array<int,array{pattern:string,category:string,label:string}> */
	public static function parse_rules( string $text ): array {
		$out = array();
		foreach ( preg_split( '/\R/', $text ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			if ( count( $parts ) >= 2 && '' !== $parts[0] ) { $out[] = array( 'pattern' => $parts[0], 'category' => $parts[1], 'label' => $parts[2] ?? '' ); }
		}
		return $out;
	}
}
