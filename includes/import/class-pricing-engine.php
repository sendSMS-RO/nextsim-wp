<?php
/**
 * Computes a WooCommerce retail price from the nextSIM base cost.
 *
 * retail = base * exchange_rate * (1 + markup%), rounded to `decimals` places.
 * Per-category markup overrides the global markup. No charm (.99) rounding.
 *
 * Pure PHP (no WordPress dependency) so it is fully unit-testable.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Import;

defined( 'ABSPATH' ) || exit;

final class Pricing_Engine {

	/**
	 * @param float             $global_markup_percent Markup applied over the base cost, e.g. 25 => x1.25.
	 * @param array<int, float> $category_markup       Per product_cat term id markup overrides.
	 * @param int               $decimals              Price decimal places (default WooCommerce is 2).
	 * @param float             $exchange_rate         EUR → store-currency multiplier applied before markup.
	 */
	public function __construct(
		private float $global_markup_percent = 0.0,
		private array $category_markup = array(),
		private int $decimals = 2,
		private float $exchange_rate = 1.0
	) {}

	/**
	 * @param float                   $base_cost           The reseller_price the reseller pays.
	 * @param array<int, int>|int|null $category_term_ids  Category term id(s) of the product, for per-category markup.
	 */
	public function compute( float $base_cost, array|int|null $category_term_ids = null ): float {
		$markup = $this->resolve_markup( $category_term_ids );
		$raw    = $base_cost * $this->exchange_rate * ( 1 + ( $markup / 100 ) );

		return round( $raw, $this->decimals );
	}

	/**
	 * Convert an EUR figure to the store currency using only the exchange rate
	 * (no markup), rounded to `decimals`. Used to seed a manual-mode price from the
	 * provider's RRP so a non-EUR store does not launch products at the raw EUR
	 * number as if it were store currency.
	 */
	public function convert( float $eur ): float {
		return round( $eur * $this->exchange_rate, $this->decimals );
	}

	public function global_markup_percent(): float {
		return $this->global_markup_percent;
	}

	/**
	 * The first category with a configured override wins; otherwise the global markup.
	 *
	 * @param array<int, int>|int|null $category_term_ids
	 */
	private function resolve_markup( array|int|null $category_term_ids ): float {
		if ( array() === $this->category_markup || null === $category_term_ids ) {
			return $this->global_markup_percent;
		}

		$ids = is_array( $category_term_ids ) ? $category_term_ids : array( $category_term_ids );

		foreach ( $ids as $term_id ) {
			if ( isset( $this->category_markup[ (int) $term_id ] ) ) {
				return $this->category_markup[ (int) $term_id ];
			}
		}

		return $this->global_markup_percent;
	}
}
