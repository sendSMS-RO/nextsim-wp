<?php
/**
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Tests\Unit;

use NextSIM\Woo\Exchange_Rate;
use PHPUnit\Framework\TestCase;

final class ExchangeRateTest extends TestCase {

	private const ECB_SAMPLE = <<<'XML'
<gesmes:Envelope xmlns:gesmes="http://www.gesmes.org/xml/2002-08-01" xmlns="http://www.ecb.int/vocabulary/2002-08-01/eurofxref">
	<Cube>
		<Cube time="2026-08-26">
			<Cube currency="USD" rate="1.1743"/>
			<Cube currency="RON" rate="5.0731"/>
			<Cube currency="HUF" rate="396.05"/>
		</Cube>
	</Cube>
</gesmes:Envelope>
XML;

	public function test_parses_rate_for_currency(): void {
		$this->assertSame( 5.0731, Exchange_Rate::parse_ecb_rate( self::ECB_SAMPLE, 'RON' ) );
		$this->assertSame( 1.1743, Exchange_Rate::parse_ecb_rate( self::ECB_SAMPLE, 'USD' ) );
	}

	public function test_currency_lookup_is_case_insensitive(): void {
		$this->assertSame( 5.0731, Exchange_Rate::parse_ecb_rate( self::ECB_SAMPLE, 'ron' ) );
	}

	public function test_unknown_currency_returns_null(): void {
		$this->assertNull( Exchange_Rate::parse_ecb_rate( self::ECB_SAMPLE, 'GBP' ) );
	}

	public function test_garbage_body_returns_null(): void {
		$this->assertNull( Exchange_Rate::parse_ecb_rate( '<html>maintenance</html>', 'RON' ) );
	}

	public function test_zero_rate_returns_null(): void {
		$xml = '<Cube currency="RON" rate="0"/>';

		$this->assertNull( Exchange_Rate::parse_ecb_rate( $xml, 'RON' ) );
	}
}
