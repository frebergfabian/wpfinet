<?php
namespace WPFinet\Tests\Unit;

use FinetTroubleshoot\Access\Normalizer;
use PHPUnit\Framework\TestCase;

final class NormalizerTest extends TestCase {
	private function key( string $input ): string {
		$a = Normalizer::parse( $input );
		$this->assertNotNull( $a, $input );
		return $a['street_key'] . '|' . $a['number'] . '|' . $a['letter'];
	}

	public function test_equivalent_spellings(): void {
		$expected = 'storgatan|12|b';
		foreach ( array( 'Storgatan 12B', 'storgatan 12 b', 'STORGATAN 12b', '  Storgatan   12  B ', 'Storgatan12B', 'Storgatan 12B, 612 30 Finspång' ) as $input ) {
			$this->assertSame( $expected, $this->key( $input ), $input );
		}
		$this->assertSame( 'storgatan|12|', $this->key( 'Storgatan 12' ) );
	}

	public function test_swedish_letters_are_distinct(): void {
		$this->assertNotSame( $this->key( 'Ågatan 1' ), $this->key( 'Agatan 1' ) );
		$this->assertNotSame( $this->key( 'Ödevägen 3' ), $this->key( 'Odevägen 3' ) );
		$this->assertNotSame( $this->key( 'Ängsvägen 3' ), $this->key( 'Angsvägen 3' ) );
		$this->assertSame( $this->key( 'ÅGATAN 1' ), $this->key( 'ågatan 1' ) );
	}

	public function test_unicode_forms_and_foreign_diacritics(): void {
		$this->assertSame( $this->key( "A\u{030A}gatan 1" ), $this->key( 'Ågatan 1' ), 'NFD å equals NFC å' );
		$this->assertSame( $this->key( 'Linnégatan 5' ), $this->key( 'Linnegatan 5' ) );
		$this->assertSame( $this->key( 'Karl-Johans väg 4' ), $this->key( 'Karl Johans väg 4' ) );
		$this->assertSame( $this->key( "S:t Persgatan 2" ), $this->key( "St Persgatan 2" ) );
	}

	public function test_invalid_input(): void {
		foreach ( array( '', '   ', 'Storgatan', '12', '12B', '<script>alert(1)</script> 1', 'Gata 0', str_repeat( 'a', 130 ) . ' 1', 'Storgatan 123456', 'Storgatan 12BC' ) as $input ) {
			$this->assertNull( Normalizer::parse( $input ), $input );
		}
	}

	public function test_api_number_formats(): void {
		$this->assertSame( array( 12, 'b' ), Normalizer::number_and_letter( '12B' ) );
		$this->assertSame( array( 12, 'b' ), Normalizer::number_and_letter( '12', 'B' ) );
		$this->assertSame( array( 7, '' ), Normalizer::number_and_letter( 7, null ) );
		$this->assertSame( array( 0, '' ), Normalizer::number_and_letter( 'x' ) );
	}
}
