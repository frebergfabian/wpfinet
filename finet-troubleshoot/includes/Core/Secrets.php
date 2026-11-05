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
		'facebook_app_secret' => 'WPFINET_FACEBOOK_APP_SECRET',
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
		return function_exists( 'sodium_crypto_secretbox' ) || self::openssl_available();
	}

	private static function openssl_available(): bool {
		return function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', array_map( 'strtolower', (array) openssl_get_cipher_methods() ), true );
	}

	/**
	 * Detailed status for admin screens: 'constant', 'ok', 'undecryptable' (stored, but the AUTH_KEY/SECURE_AUTH_SALT
	 * in wp-config.php changed or the crypto extension is missing), or 'none'.
	 */
	public static function status( string $name ): string {
		$source = self::source( $name );
		if ( 'database' !== $source ) {
			return $source;
		}
		return '' === self::get( $name ) ? 'undecryptable' : 'ok';
	}

	/** @return array<string,string> */
	private static function store(): array {
		$store = get_option( self::OPTION, array() );
		return is_array( $store ) ? $store : array();
	}

	private static function material(): string {
		return ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . '|' . ( defined( 'SECURE_AUTH_SALT' ) ? SECURE_AUTH_SALT : '' ) . '|wpfinet-secrets-v1';
	}

	private static function key(): string {
		return sodium_crypto_generichash( self::material(), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/** Fallback when libsodium is not loaded (e.g. php_sodium.dll not enabled on Windows): AES-256-GCM via OpenSSL. */
	private static function encrypt_openssl( string $plain ): string {
		$key   = hash( 'sha256', self::material(), true );
		$iv    = random_bytes( 12 );
		$tag   = '';
		$box   = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
		return false === $box ? '' : 'v2:' . base64_encode( $iv . $tag . $box ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private static function decrypt_openssl( string $cipher ): string {
		if ( ! self::openssl_available() ) {
			return '';
		}
		$raw = base64_decode( substr( $cipher, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) <= 28 ) {
			return '';
		}
		$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', hash( 'sha256', self::material(), true ), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return false === $plain ? '' : $plain;
	}

	private static function encrypt( string $plain ): string {
		if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
			return self::openssl_available() ? self::encrypt_openssl( $plain ) : '';
		}
		$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
		return 'v1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, self::key() ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private static function decrypt( string $cipher ): string {
		if ( str_starts_with( $cipher, 'v2:' ) ) {
			return self::decrypt_openssl( $cipher );
		}
		if ( ! function_exists( 'sodium_crypto_secretbox' ) || ! str_starts_with( $cipher, 'v1:' ) ) {
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
