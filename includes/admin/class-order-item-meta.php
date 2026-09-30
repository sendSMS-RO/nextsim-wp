<?php
/**
 * Order editor: keeps the plugin's line-item meta out of WooCommerce's editable
 * meta rows and shows a read-only eSIM summary instead.
 *
 * WooCommerce renders every item meta row as an input and writes the values back on
 * "Update". For provisioning state that means a page loaded before a job ran would
 * overwrite the job's result (status, token) with the stale values on save.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Admin;

use NextSIM\Woo\Data\Order_Esim_Store;

defined( 'ABSPATH' ) || exit;

class Order_Item_Meta {

	public function register(): void {
		add_filter( 'woocommerce_hidden_order_itemmeta', array( $this, 'hide_keys' ) );
		add_action( 'woocommerce_after_order_itemmeta', array( $this, 'render_summary' ), 10, 2 );
	}

	/**
	 * @param array<int, string> $keys
	 * @return array<int, string>
	 */
	public function hide_keys( $keys ): array {
		return array_values( array_unique( array_merge( (array) $keys, Order_Esim_Store::item_meta_keys() ) ) );
	}

	/**
	 * @param int            $item_id
	 * @param \WC_Order_Item $item
	 */
	public function render_summary( $item_id, $item ): void {
		if ( ! $item instanceof \WC_Order_Item_Product || ! Order_Esim_Store::is_nextsim_item( $item ) ) {
			return;
		}

		$statuses = array(
			Order_Esim_Store::ITEM_STATUS_PENDING    => __( 'Waiting to be provisioned', 'nextsim-woo' ),
			Order_Esim_Store::ITEM_STATUS_ACTIVATING => __( 'Activating', 'nextsim-woo' ),
			Order_Esim_Store::ITEM_STATUS_POLLING    => __( 'Ordered, waiting for the eSIM', 'nextsim-woo' ),
			Order_Esim_Store::ITEM_STATUS_COMPLETED  => __( 'Delivered', 'nextsim-woo' ),
			Order_Esim_Store::ITEM_STATUS_FAILED     => __( 'Failed', 'nextsim-woo' ),
		);

		$status   = (string) $item->get_meta( Order_Esim_Store::ITEM_STATUS );
		$quantity = (int) $item->get_meta( Order_Esim_Store::ITEM_QUANTITY );

		$rows = array(
			__( 'eSIM status', 'nextsim-woo' )             => '' === $status ? __( 'Not started (order not paid yet)', 'nextsim-woo' ) : ( $statuses[ $status ] ?? $status ),
			__( 'Package ID', 'nextsim-woo' )              => (string) $item->get_meta( Order_Esim_Store::ITEM_PACKAGE_ID ),
			__( 'Top-up of eSIM', 'nextsim-woo' )          => (string) $item->get_meta( Order_Esim_Store::ITEM_TOPUP_CODE ),
			__( 'eSIMs ordered', 'nextsim-woo' )           => $quantity > 1 ? (string) $quantity : '',
			__( 'ICCID', 'nextsim-woo' )                   => (string) $item->get_meta( Order_Esim_Store::ITEM_ICCID ),
			__( 'Reseller order', 'nextsim-woo' )          => (string) $item->get_meta( Order_Esim_Store::ITEM_ORDER_TOKEN ),
			__( 'Canceled reseller orders', 'nextsim-woo' ) => (string) $item->get_meta( Order_Esim_Store::ITEM_PREV_TOKENS ),
			__( 'Last error', 'nextsim-woo' )              => Order_Esim_Store::ITEM_STATUS_FAILED === $status ? (string) $item->get_meta( Order_Esim_Store::ITEM_LAST_ERROR ) : '',
		);

		echo '<table cellspacing="0" class="display_meta nextsim-woo-item-summary">';

		foreach ( $rows as $label => $value ) {
			if ( '' === $value ) {
				continue;
			}

			printf( '<tr><th>%s:</th><td><p>%s</p></td></tr>', esc_html( $label ), esc_html( $value ) );
		}

		echo '</table>';
	}
}
