<?php
/**
 * Server-side secret storage.
 *
 * Preferred: a constant in wp-config.php (e.g. WPFINET_ACCESS_API_SECRET), which never touches the database.
 * Fallback: an encrypted, non-autoloaded option (libsodium secretbox, key derived from the site's AUTH salts).
 * Secrets are never returned to the browser; admin screens only show whether a secret is set and where it comes from.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Core;

final class Secrets {
	public const OPTION = 'wpfinet_secrets';

	/** Known secret names => wp-config constant. */
	public const KEYS = array(
		'access_api_secret'   => 'WPFINET_ACCESS_API_SECRET',
		'access_api_username' => 'WPFINET_ACCESS_API_USERNAME',
		'instagram_token'     => 'WPFINET_INSTAGRAM_TOKEN',
		'instagram_app_secret' => 'WPFINET_INSTAGRAM_APP_SECRET',
	);

	public static function get( string $name ): string {
		$constant = self::KEYS[ $name ] ?? '';
		if ( $constant && defined( $constant ) ) {
			return (string) constant( $constant );
		}
		$store = self::store();
		if ( empty( $store[ $name ] ) ) {
			return '';
		}
		return self::decrypt( (string) $store[ $name ] );
	}

	public static function set( string $name, string $value ): bool {
		if ( ! isset( self::KEYS[ $name ] ) ) {
			return false;
		}
		$store = self::store();
		if ( '' === $value ) {
			unset( $store[ $name ] );
		} else {
			$encrypted = self::encrypt( $value );
			if ( '' === $encrypted ) {
				return false;
			}
			$store[ $name ] = $encrypted;
		}
		return update_option( self::OPTION, $store, false ) || true;
	}

	/** @return 'constant'|'database'|'none' */
	public static function source( string $name ): string {
		$constant = self::KEYS[ $name ] ?? '';
		if ( $constant && defined( $constant ) && '' !== (string) constant( $constant ) ) {
			return 'constant';
		}
		$store = self::store();
		return empty( $store[ $name ] ) ? 'none' : 'database';
	}

	public static function available(): bool {
		return function_exists( 'sodium_crypto_secretbox' );
	}

	/** @return array<string,string> */
	private static function store(): array {
		$store = get_option( self::OPTION, array() );
		return is_array( $store ) ? $store : array();
	}

	private static function key(): string {
		$material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . '|' . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' ) . '|wpfinet-secrets-v1';
		return sodium_crypto_generichash( $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	private static function encrypt( string $plain ): string {
		if ( ! self::available() ) {
			return '';
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private static function decrypt( string $cipher ): string {
		if ( ! self::available() || ! str_starts_with( $cipher, 'v1:' ) ) {
			return '';
		}
		$raw = base64_decode( substr( $cipher, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return '';
		}
		$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
		return false === $plain ? '' : $plain;
	}
}
