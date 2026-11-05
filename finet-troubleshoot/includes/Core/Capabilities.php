<?php
/**
 * WPFinet capability registration.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Core;

final class Capabilities {
	/** @return array<int,string> */
	public static function all(): array {
		return array( 'manage_wpfinet', 'manage_wpfinet_guides', 'publish_wpfinet_guides', 'manage_wpfinet_outages', 'publish_wpfinet_outages', 'view_wpfinet_statistics', 'manage_wpfinet_settings' );
	}
	public static function grant_administrators(): void {
		$role = get_role( 'administrator' );
		if ( ! $role ) { return; }
		foreach ( self::all() as $capability ) { $role->add_cap( $capability ); }
	}
}
