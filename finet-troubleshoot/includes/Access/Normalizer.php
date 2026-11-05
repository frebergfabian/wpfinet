<?php
/**
 * Swedish street address parsing and normalisation.
 *
 * Rules (tested in tests/php/Unit/NormalizerTest.php):
 * - case, surrounding/multiple spaces, "12B" vs "12 B" and Unicode NFC/NFD forms are equivalent;
 * - å, ä and ö are kept as distinct letters (Ågatan ≠ Agatan, Ödevägen ≠ Odevägen);
 * - other diacritics are folded (Linnégatan = Linnegatan, Müllers = Mullers); ø/æ map to ö/ä;
 * - hyphens and spaces are equivalent inside street names; dots/apostrophes are dropped;
 * - anything after the first comma (postcode/city) is ignored, like the legacy lookup which only used
 *   streetName/streetNumber/streetLittera.
 *
 * @package FinetTroubleshoot
 */

declare( strict_types=1 );

namespace FinetTroubleshoot\Access;

final class Normalizer {
	public const MAX_LENGTH = 120;

	private const FOLD = array(
		'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ü' => 'u', 'ú' => 'u', 'ù' => 'u', 'û' => 'u',
		'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ç' => 'c', 'ñ' => 'n', 'ÿ' => 'y', 'ý' => 'y',
		'ø' => 'ö', 'æ' => 'ä', 'ß' => 'ss',
	);

	/** @return array{street:string,number:int,letter:string,street_key:string}|null */
	public static function parse( string $input ): ?array {
		$input = self::nfc( $input );
		if ( '' === trim( $input ) || mb_strlen( $input ) > self::MAX_LENGTH ) {
			return null;
		}
		$input = trim( explode( ',', $input, 2 )[0] );
		$input = (string) preg_replace( '/\s+/u', ' ', $input );
		if ( ! preg_match( '/^(?<street>[\p{L}][\p{L}\p{M} .:\'’\-]*?)\s*(?<number>\d{1,5})\s*(?<letter>\p{L})?$/u', $input, $m ) ) {
			return null;
		}
		$street = trim( $m['street'] );
		$key    = self::street_key( $street );
		$number = (int) $m['number'];
		if ( '' === $key || mb_strlen( $key ) < 2 || $number < 1 ) {
			return null;
		}
		return array( 'street' => $street, 'number' => $number, 'letter' => self::letter( (string) ( $m['letter'] ?? '' ) ), 'street_key' => $key );
	}

	public static function street_key( string $street ): string {
		$s = mb_strtolower( self::nfc( $street ), 'UTF-8' );
		$s = strtr( $s, self::FOLD );
		$s = (string) preg_replace( '/[\.:\'’`´]/u', '', $s );
		$s = (string) preg_replace( '/[\s\-–]+/u', ' ', $s );
		$s = (string) preg_replace( '/[^\p{L}\p{N} ]/u', '', $s );
		return trim( $s );
	}

	public static function letter( string $letter ): string {
		$letter = mb_strtolower( trim( self::nfc( $letter ) ), 'UTF-8' );
		return 1 === mb_strlen( $letter ) && preg_match( '/^\p{L}$/u', $letter ) ? $letter : '';
	}

	/** Splits API values such as "12B" or "12 b" into number and letter. @return array{0:int,1:string} */
	public static function number_and_letter( mixed $number, mixed $letter = '' ): array {
		$raw = trim( (string) $number );
		if ( preg_match( '/^(\d{1,5})\s*(\p{L})?$/u', self::nfc( $raw ), $m ) ) {
			$l = self::letter( (string) $letter );
			return array( (int) $m[1], '' !== $l ? $l : self::letter( (string) ( $m[2] ?? '' ) ) );
		}
		return array( 0, '' );
	}

	public static function display( array $address ): string {
		return $address['street'] . ' ' . $address['number'] . strtoupper( $address['letter'] );
	}

	private static function nfc( string $value ): string {
		if ( class_exists( '\Normalizer' ) ) {
			$normal = \Normalizer::normalize( $value, \Normalizer::FORM_C );
			if ( is_string( $normal ) ) {
				return $normal;
			}
		}
		return $value;
	}
}
