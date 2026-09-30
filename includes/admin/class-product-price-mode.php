<?php
/**
 * Product editor: the Automatic / Manual price mode of an imported eSIM product.
 *
 * Automatic products are repriced by every sync from the reseller price, exchange
 * rate and markup. Manual products keep whatever price the merchant sets.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Admin;

use NextSIM\Woo\Data\Product_Meta;
use NextSIM\Woo\Settings;

defined( 'ABSPATH' ) || exit;

class Product_Price_Mode {

	private const FIELD = 'nextsim_woo_price_mode';

	public function __construct( private Settings $settings ) {}

	public function register(): void {
		add_action( 'woocommerce_product_options_pricing', array( $this, 'render_field' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save_field' ) );
	}

	public function render_field(): void {
		global $product_object;

		if ( ! $product_object instanceof \WC_Product || '' === (string) $product_object->get_meta( Product_Meta::PACKAGE_ID ) ) {
			return;
		}

		woocommerce_wp_select(
			array(
				'id'          => self::FIELD,
				'label'       => __( 'nextSIM price mode', 'nextsim-woo' ),
				'value'       => $this->mode_of( $product_object ),
				'options'     => array(
					Settings::PRICE_MODE_AUTO   => __( 'Automatic (set by every sync)', 'nextsim-woo' ),
					Settings::PRICE_MODE_MANUAL => __( 'Manual (never changed by sync)', 'nextsim-woo' ),
				),
				'desc_tip'    => true,
				'description' => __( 'Automatic: the price is recalculated from the reseller price and your markup at every sync, replacing anything typed here. Manual: the sync never changes your price.', 'nextsim-woo' ),
			)
		);
	}

	/**
	 * Runs inside WooCommerce's product save, after it verified the product nonce and
	 * the user's capability.
	 *
	 * @param \WC_Product $product
	 */
	public function save_field( $product ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by WooCommerce (woocommerce_meta_nonce) before this hook.
		if ( ! $product instanceof \WC_Product || ! isset( $_POST[ self::FIELD ] ) || '' === (string) $product->get_meta( Product_Meta::PACKAGE_ID ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified by WooCommerce (woocommerce_meta_nonce) before this hook.
		$mode = sanitize_key( wp_unslash( $_POST[ self::FIELD ] ) );

		if ( in_array( $mode, array( Settings::PRICE_MODE_AUTO, Settings::PRICE_MODE_MANUAL ), true ) ) {
			$product->update_meta_data( Product_Meta::PRICE_MODE, $mode );
		}
	}

	private function mode_of( \WC_Product $product ): string {
		$mode = (string) $product->get_meta( Product_Meta::PRICE_MODE );

		return '' !== $mode ? $mode : $this->settings->default_price_mode();
	}
}
