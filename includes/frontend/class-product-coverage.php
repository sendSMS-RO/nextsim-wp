<?php
/**
 * Shows the countries an eSIM plan covers on the single-product page, from the
 * coverage meta the importer stored off the plan's operators.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Frontend;

use NextSIM\Woo\Data\Product_Meta;

defined( 'ABSPATH' ) || exit;

class Product_Coverage {

	private const MAX_SHOWN = 10;

	public function register(): void {
		add_action( 'woocommerce_single_product_summary', array( $this, 'render' ), 25 );
	}

	public function render(): void {
		global $product;

		if ( ! $product instanceof \WC_Product ) {
			return;
		}

		$raw   = (string) $product->get_meta( Product_Meta::COVERAGE );
		$codes = '' !== $raw ? json_decode( $raw, true ) : array();

		if ( ! is_array( $codes ) || array() === $codes ) {
			return;
		}

		$names = $this->country_names( $codes );
		if ( array() === $names ) {
			return;
		}

		sort( $names );
		$total  = count( $names );
		$shown  = array_slice( $names, 0, self::MAX_SHOWN );
		$suffix = '';

		if ( $total > self::MAX_SHOWN ) {
			$suffix = ' ' . sprintf(
				/* translators: %d: number of additional countries. */
				esc_html__( '+%d more', 'nextsim-woo' ),
				$total - self::MAX_SHOWN
			);
		}

		printf(
			'<p class="nextsim-coverage"><strong>%s</strong> %s%s</p>',
			esc_html( _n( 'Covers:', 'Covers:', $total, 'nextsim-woo' ) ),
			esc_html( implode( ', ', $shown ) ),
			esc_html( $suffix )
		);
	}

	/**
	 * Map upstream country codes to display names. Two-letter ISO codes become their
	 * WooCommerce country name; anything else is shown as-is.
	 *
	 * @param array<int, mixed> $codes
	 * @return array<int, string>
	 */
	private function country_names( array $codes ): array {
		$wc_countries = ( function_exists( 'WC' ) && WC()->countries )
			? WC()->countries->get_countries()
			: array();

		$names = array();
		foreach ( $codes as $code ) {
			$code = strtoupper( trim( (string) $code ) );
			if ( '' === $code ) {
				continue;
			}

			$name           = $wc_countries[ $code ] ?? $code;
			$names[ $name ] = true;
		}

		return array_keys( $names );
	}
}
