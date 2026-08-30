<?php
/**
 * Upserts a WooCommerce product from a nextSIM plan.
 *
 * Pricing rules (see plan): auto-mode products are (re)priced from the base cost on
 * every run; manual-mode products keep the reseller's price untouched. The last-seen
 * base cost is always refreshed so margin display and change detection stay correct.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Import;

use NextSIM\Woo\Data\Package_Repository;
use NextSIM\Woo\Data\Product_Meta;
use NextSIM\Woo\Logger;
use NextSIM\Woo\Settings;

defined( 'ABSPATH' ) || exit;

class Product_Mapper {

	public const RESULT_CREATED = 'created';
	public const RESULT_UPDATED = 'updated';
	public const RESULT_SKIPPED = 'skipped';

	public function __construct(
		private Package_Repository $repository,
		private Pricing_Engine $pricing,
		private Taxonomy_Sync $taxonomy,
		private Settings $settings,
		private Logger $logger,
		private int $price_decimals = 2
	) {}

	/**
	 * @return self::RESULT_*
	 */
	public function upsert( Plan_Data $plan, int $run_timestamp ): string {
		$product = $this->repository->find_by_package_id( $plan->id );
		$term_id = $this->taxonomy->term_id_for_zone( $plan->location_zone_name );

		if ( null === $product ) {
			return $this->create( $plan, $term_id, $run_timestamp );
		}

		return $this->update( $product, $plan, $term_id, $run_timestamp );
	}

	/**
	 * @return self::RESULT_CREATED
	 */
	private function create( Plan_Data $plan, ?int $term_id, int $run_timestamp ): string {
		$product = new \WC_Product_Simple();
		$mode    = $this->settings->default_price_mode();

		// Manual mode seeds from the provider RRP, which is in EUR — convert it to the
		// store currency (no markup) so a non-EUR store does not launch at the raw EUR
		// figure. The admin can then adjust; the importer never overwrites it again.
		$price = Settings::PRICE_MODE_AUTO === $mode
			? $this->pricing->compute( $plan->reseller_price, $term_id )
			: $this->pricing->convert( $plan->rrp_price );

		$product->set_name( $plan->display_name );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'visible' );
		$product->set_virtual( true );
		// One activation is provisioned per line item, so cap the WC quantity at 1.
		// Multi-eSIM plans use their own quantity selector (shared-wallet eSIMs).
		$product->set_sold_individually( true );
		$product->set_stock_status( 'instock' );
		$product->set_regular_price( $this->format_price( $price ) );
		$product->set_price( $this->format_price( $price ) );

		if ( null !== $term_id ) {
			$product->set_category_ids( array( $term_id ) );
		}

		$this->apply_meta( $product, $plan, $mode, $run_timestamp );
		$product->save();

		return self::RESULT_CREATED;
	}

	/**
	 * @return self::RESULT_UPDATED|self::RESULT_SKIPPED
	 */
	private function update( \WC_Product $product, Plan_Data $plan, ?int $term_id, int $run_timestamp ): string {
		$mode = $product->get_meta( Product_Meta::PRICE_MODE ) ?: $this->settings->default_price_mode();

		$price_changed = false;
		if ( Settings::PRICE_MODE_AUTO === $mode ) {
			$price = $this->pricing->compute( $plan->reseller_price, $term_id );

			if ( ! $this->prices_equal( (float) $product->get_regular_price(), $price ) ) {
				$product->set_regular_price( $this->format_price( $price ) );

				// Preserve an admin-set sale price: the active price stays the sale
				// price while it undercuts the new regular price.
				$sale = (string) $product->get_sale_price();
				if ( '' !== $sale && (float) $sale < $price ) {
					$product->set_price( $sale );
				} else {
					$product->set_price( $this->format_price( $price ) );
				}

				$price_changed = true;
			}
		}

		$attrs_changed = $product->get_meta( Product_Meta::META_HASH ) !== $plan->attributes_hash();
		$base_changed  = $this->format_price( (float) $product->get_meta( Product_Meta::RESELLER_PRICE ) ) !== $this->format_price( $plan->reseller_price );

		// The plan is live in the API again, so undo a previous orphan sweep.
		$restocked = 'instock' !== $product->get_stock_status();
		if ( $restocked ) {
			$product->set_stock_status( 'instock' );
		}

		// Enforce invariants on products imported by older plugin versions: exactly one
		// activation is provisioned per line item, so WC quantity must be capped at 1.
		$hardened = ! $product->get_sold_individually();
		if ( $hardened ) {
			$product->set_sold_individually( true );
		}

		if ( $attrs_changed ) {
			$product->set_name( $plan->display_name );

			if ( null !== $term_id ) {
				$product->set_category_ids( array( $term_id ) );
			}
		}

		if ( $price_changed || $attrs_changed || $base_changed || $restocked || $hardened ) {
			$this->apply_meta( $product, $plan, $mode, $run_timestamp );
			$product->save();

			return self::RESULT_UPDATED;
		}

		// Nothing material changed — only bump the sync timestamp so the orphan sweep
		// does not draft this still-live product.
		update_post_meta( $product->get_id(), Product_Meta::LAST_SYNCED, $run_timestamp );

		return self::RESULT_SKIPPED;
	}

	private function apply_meta( \WC_Product $product, Plan_Data $plan, string $mode, int $run_timestamp ): void {
		$product->update_meta_data( Product_Meta::PACKAGE_ID, (string) $plan->id );
		$product->update_meta_data( Product_Meta::RESELLER_PRICE, $this->format_price( $plan->reseller_price ) );
		$product->update_meta_data( Product_Meta::RRP_PRICE, $this->format_price( $plan->rrp_price ) );
		$product->update_meta_data( Product_Meta::PRICE_MODE, $mode );
		$product->update_meta_data( Product_Meta::META_HASH, $plan->attributes_hash() );
		$product->update_meta_data( Product_Meta::LAST_SYNCED, $run_timestamp );

		$product->update_meta_data( Product_Meta::DATA_GB, (string) $plan->data_gb );
		$product->update_meta_data( Product_Meta::IS_UNLIMITED, $plan->is_unlimited ? '1' : '0' );
		$product->update_meta_data( Product_Meta::DATA_CAP_GB, (string) $plan->data_cap_gb );
		$product->update_meta_data( Product_Meta::DATA_CAP_PER, $plan->data_cap_per );
		$product->update_meta_data( Product_Meta::PERIOD_DAYS, (string) $plan->period_days );
		$product->update_meta_data( Product_Meta::CAN_TOP_UP, $plan->can_top_up ? '1' : '0' );
		$product->update_meta_data( Product_Meta::MSISDN_AVAILABLE, $plan->msisdn_available ? '1' : '0' );
		$product->update_meta_data( Product_Meta::ALLOW_MULTI_ESIM, $plan->allow_multi_esim ? '1' : '0' );
		$product->update_meta_data( Product_Meta::EXTRA_ESIM_PRICE, $this->format_price( $plan->extra_esim_price ) );
		$product->update_meta_data( Product_Meta::MAX_ESIMS, (string) $plan->max_esims_per_order );
		$product->update_meta_data( Product_Meta::ROUTE, $plan->route );
		$product->update_meta_data( Product_Meta::LOCATION_ZONE, $plan->location_zone_name );
	}

	/**
	 * Apply a price change from the /changes delta to an existing product, without a
	 * full plan payload. Auto-mode products are repriced (preserving an active sale);
	 * manual-mode products keep their price but refresh the stored reseller/RRP figures
	 * so margin display stays correct. Both prices are in EUR.
	 *
	 * @return bool True if the product's active price changed.
	 */
	public function apply_price_change( \WC_Product $product, float $reseller_price, float $rrp_price ): bool {
		$mode = $product->get_meta( Product_Meta::PRICE_MODE ) ?: $this->settings->default_price_mode();

		$product->update_meta_data( Product_Meta::RESELLER_PRICE, $this->format_price( $reseller_price ) );
		$product->update_meta_data( Product_Meta::RRP_PRICE, $this->format_price( $rrp_price ) );

		$changed = false;

		if ( Settings::PRICE_MODE_AUTO === $mode ) {
			$price = $this->pricing->compute( $reseller_price, $product->get_category_ids() );

			if ( ! $this->prices_equal( (float) $product->get_regular_price(), $price ) ) {
				$product->set_regular_price( $this->format_price( $price ) );

				$sale = (string) $product->get_sale_price();
				if ( '' !== $sale && (float) $sale < $price ) {
					$product->set_price( $sale );
				} else {
					$product->set_price( $this->format_price( $price ) );
				}

				$changed = true;
			}
		}

		$product->save();

		return $changed;
	}

	private function prices_equal( float $a, float $b ): bool {
		return $this->format_price( $a ) === $this->format_price( $b );
	}

	private function format_price( float $value ): string {
		return number_format( $value, $this->price_decimals, '.', '' );
	}
}
