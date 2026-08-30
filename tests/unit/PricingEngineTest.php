<?php
/**
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Tests\Unit;

use NextSIM\Woo\Import\Pricing_Engine;
use PHPUnit\Framework\TestCase;

final class PricingEngineTest extends TestCase {

	public function test_zero_markup_returns_base_cost(): void {
		$engine = new Pricing_Engine( 0.0 );

		$this->assertSame( 10.0, $engine->compute( 10.0 ) );
	}

	public function test_applies_percentage_markup(): void {
		$engine = new Pricing_Engine( 25.0 );

		$this->assertSame( 12.5, $engine->compute( 10.0 ) );
	}

	public function test_rounds_to_two_decimals_without_charm(): void {
		$engine = new Pricing_Engine( 25.0 );

		// 9.99 * 1.25 = 12.4875 -> 12.49 (standard rounding, no .99 charm).
		$this->assertSame( 12.49, $engine->compute( 9.99 ) );
	}

	public function test_respects_custom_decimal_places(): void {
		$engine = new Pricing_Engine( 33.0, array(), 3 );

		// 10 * 1.33 = 13.3 -> 13.300 -> 13.3.
		$this->assertSame( 13.3, $engine->compute( 10.0 ) );
	}

	public function test_per_category_markup_overrides_global(): void {
		$engine = new Pricing_Engine( 10.0, array( 5 => 50.0 ) );

		$this->assertSame( 150.0, $engine->compute( 100.0, 5 ) );
		$this->assertSame( 110.0, $engine->compute( 100.0, 99 ) );
	}

	public function test_first_matching_category_wins(): void {
		$engine = new Pricing_Engine( 10.0, array( 5 => 50.0 ) );

		$this->assertSame( 150.0, $engine->compute( 100.0, array( 99, 5 ) ) );
	}

	public function test_null_category_uses_global_markup(): void {
		$engine = new Pricing_Engine( 20.0, array( 5 => 50.0 ) );

		$this->assertSame( 120.0, $engine->compute( 100.0, null ) );
	}

	public function test_exchange_rate_applies_before_markup(): void {
		$engine = new Pricing_Engine( 20.0, array(), 2, 5.0 );

		// 10 EUR * 5.0 = 50 store currency, +20% markup = 60.
		$this->assertSame( 60.0, $engine->compute( 10.0 ) );
	}

	public function test_exchange_rate_result_is_rounded(): void {
		$engine = new Pricing_Engine( 0.0, array(), 2, 5.0731 );

		// 9.99 * 5.0731 = 50.680269 -> 50.68.
		$this->assertSame( 50.68, $engine->compute( 9.99 ) );
	}

	public function test_default_exchange_rate_is_neutral(): void {
		$engine = new Pricing_Engine( 25.0 );

		$this->assertSame( 12.5, $engine->compute( 10.0 ) );
	}

	public function test_convert_applies_only_exchange_rate_no_markup(): void {
		$engine = new Pricing_Engine( 50.0, array(), 2, 5.0 );

		// Markup is ignored by convert(): 10 EUR * 5.0 = 50.0.
		$this->assertSame( 50.0, $engine->convert( 10.0 ) );
	}

	public function test_convert_rounds_to_decimals(): void {
		$engine = new Pricing_Engine( 0.0, array(), 2, 5.2584 );

		// 9.99 * 5.2584 = 52.531416 -> 52.53.
		$this->assertSame( 52.53, $engine->convert( 9.99 ) );
	}

	public function test_convert_is_identity_without_exchange_rate(): void {
		$engine = new Pricing_Engine( 0.0 );

		$this->assertSame( 12.0, $engine->convert( 12.0 ) );
	}
}
