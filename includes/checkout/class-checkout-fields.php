<?php
/**
 * Product-page fields: a manual "Top-up activation code" (nextsim.eu style) and a
 * Multi-eSIM quantity selector. Persists them onto the order line item and adjusts
 * the cart price for extra eSIMs.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Checkout;

use NextSIM\Woo\Api\Api_Client;
use NextSIM\Woo\Api\Api_Exception;
use NextSIM\Woo\Data\Order_Esim_Store;
use NextSIM\Woo\Data\Product_Meta;
use NextSIM\Woo\Import\Pricing_Engine;

defined( 'ABSPATH' ) || exit;

class Checkout_Fields {

	public function __construct(
		private ?Pricing_Engine $pricing = null,
		private ?Api_Client $client = null
	) {}

	public function register(): void {
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_fields' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate' ), 10, 3 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'adjust_price' ), 10, 1 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_to_order_item' ), 10, 4 );
	}

	private function is_esim_product( \WC_Product $product ): bool {
		return '' !== (string) $product->get_meta( Product_Meta::PACKAGE_ID );
	}

	public function render_fields(): void {
		global $product;

		if ( ! $product instanceof \WC_Product || ! $this->is_esim_product( $product ) ) {
			return;
		}

		if ( '1' === (string) $product->get_meta( Product_Meta::CAN_TOP_UP ) ) {
			$prefill = $_POST['nextsim_topup_code'] ?? ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$prefill = is_string( $prefill ) ? wp_unslash( $prefill ) : '';

			printf(
				'<p class="form-row nextsim-topup-field"><label for="nextsim_topup_code">%s</label>
				<input type="text" id="nextsim_topup_code" name="nextsim_topup_code" value="%s" placeholder="%s" />
				<span class="description">%s</span></p>',
				esc_html__( 'Top-up activation code (optional)', 'nextsim-woo' ),
				esc_attr( $prefill ),
				esc_attr__( 'e.g. K2-21XA8K-12345', 'nextsim-woo' ),
				esc_html__( 'Leave empty to buy a new eSIM. Enter your existing activation code to top it up.', 'nextsim-woo' )
			);
		}

		$max = (int) $product->get_meta( Product_Meta::MAX_ESIMS );
		if ( '1' === (string) $product->get_meta( Product_Meta::ALLOW_MULTI_ESIM ) && $max > 1 ) {
			echo '<p class="form-row nextsim-quantity-field"><label for="nextsim_esim_quantity">'
				. esc_html__( 'Number of eSIMs (shared data)', 'nextsim-woo' ) . '</label>';
			echo '<select id="nextsim_esim_quantity" name="nextsim_esim_quantity">';
			for ( $i = 1; $i <= $max; $i++ ) {
				printf( '<option value="%1$d">%1$d</option>', $i );
			}
			echo '</select></p>';
		}
	}

	/**
	 * @param bool $passed
	 * @param int  $product_id
	 * @param int  $quantity
	 */
	public function validate( $passed, $product_id, $quantity ): bool {
		$product = wc_get_product( $product_id );

		if ( ! $product instanceof \WC_Product || ! $this->is_esim_product( $product ) ) {
			return (bool) $passed;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$code = sanitize_text_field( wp_unslash( $_POST['nextsim_topup_code'] ?? '' ) );
		$qty  = (int) ( $_POST['nextsim_esim_quantity'] ?? 1 );
		// phpcs:enable

		if ( '' !== $code && '1' !== (string) $product->get_meta( Product_Meta::CAN_TOP_UP ) ) {
			wc_add_notice( __( 'This plan does not support top-up.', 'nextsim-woo' ), 'error' );

			return false;
		}

		$max = max( 1, (int) $product->get_meta( Product_Meta::MAX_ESIMS ) );
		if ( $qty > 1 && ( '1' !== (string) $product->get_meta( Product_Meta::ALLOW_MULTI_ESIM ) || $qty > $max ) ) {
			wc_add_notice( __( 'Invalid number of eSIMs for this plan.', 'nextsim-woo' ), 'error' );

			return false;
		}

		if ( '' !== $code && $qty > 1 ) {
			wc_add_notice( __( 'Top-up cannot be combined with buying multiple eSIMs.', 'nextsim-woo' ), 'error' );

			return false;
		}

		if ( '' !== $code && ! $this->topup_code_is_valid( $code, $product ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Verify a top-up code against the API before it enters the cart, so bad codes
	 * are rejected while the customer can still fix them — not after payment. The
	 * provisioning-time check remains as the safety net for codes that expire
	 * between add-to-cart and payment.
	 *
	 * Fails closed on API errors: accepting payment for an unverifiable top-up is
	 * worse than asking the customer to retry in a moment.
	 */
	private function topup_code_is_valid( string $code, \WC_Product $product ): bool {
		if ( null === $this->client ) {
			return true;
		}

		$package_id = (int) $product->get_meta( Product_Meta::PACKAGE_ID );

		try {
			$result = $this->client->check_topup_compatibility( $code, $package_id );
		} catch ( Api_Exception $e ) {
			if ( 404 === $e->get_http_status() ) {
				wc_add_notice(
					__( 'We could not find an eSIM with this activation code. Check the code, or leave the field empty to buy a new eSIM.', 'nextsim-woo' ),
					'error'
				);
			} elseif ( $e->is_retryable() ) {
				wc_add_notice(
					__( 'We could not verify your activation code right now. Please try again in a moment.', 'nextsim-woo' ),
					'error'
				);
			} else {
				wc_add_notice(
					__( 'This activation code cannot be topped up with this plan.', 'nextsim-woo' ),
					'error'
				);
			}

			return false;
		}

		if ( ! $result['compatible'] ) {
			wc_add_notice(
				'expired' === $result['reason']
					? __( 'This eSIM has expired and can no longer be topped up. Leave the field empty to buy a new eSIM instead.', 'nextsim-woo' )
					: __( 'This plan is not compatible with your eSIM. Choose a compatible plan, or leave the field empty to buy a new eSIM.', 'nextsim-woo' ),
				'error'
			);

			return false;
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $cart_item_data
	 * @param int                  $product_id
	 * @return array<string, mixed>
	 */
	public function add_cart_item_data( $cart_item_data, $product_id ): array {
		$product = wc_get_product( $product_id );

		if ( ! $product instanceof \WC_Product || ! $this->is_esim_product( $product ) ) {
			return $cart_item_data;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$code = sanitize_text_field( wp_unslash( $_POST['nextsim_topup_code'] ?? '' ) );
		$qty  = max( 1, (int) ( $_POST['nextsim_esim_quantity'] ?? 1 ) );
		// phpcs:enable

		if ( '' !== $code ) {
			$cart_item_data['nextsim_topup_code'] = $code;
		}

		if ( $qty > 1 ) {
			$cart_item_data['nextsim_esim_quantity'] = $qty;
		}

		return $cart_item_data;
	}

	/**
	 * @param array<int, array<string, string>> $item_data
	 * @param array<string, mixed>              $cart_item
	 * @return array<int, array<string, string>>
	 */
	public function display_cart_item_data( $item_data, $cart_item ): array {
		if ( ! empty( $cart_item['nextsim_topup_code'] ) ) {
			$item_data[] = array(
				'key'   => __( 'Top-up code', 'nextsim-woo' ),
				'value' => wc_clean( $cart_item['nextsim_topup_code'] ),
			);
		}

		if ( ! empty( $cart_item['nextsim_esim_quantity'] ) ) {
			$item_data[] = array(
				'key'   => __( 'eSIMs', 'nextsim-woo' ),
				'value' => (string) (int) $cart_item['nextsim_esim_quantity'],
			);
		}

		return $item_data;
	}

	/**
	 * Add the per-extra-eSIM price for Multi-eSIM lines.
	 *
	 * @param \WC_Cart $cart
	 */
	public function adjust_price( $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			$qty = (int) ( $cart_item['nextsim_esim_quantity'] ?? 1 );

			if ( $qty <= 1 ) {
				continue;
			}

			$product = $cart_item['data'];

			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			$extra = (float) $product->get_meta( Product_Meta::EXTRA_ESIM_PRICE );

			// extra_esim_price is the reseller's EUR COST per additional eSIM — apply
			// the same markup as the base plan so extras are not sold at zero margin.
			if ( null !== $this->pricing && $extra > 0 ) {
				$extra = $this->pricing->compute( $extra, $product->get_category_ids() );
			}

			// Read the base from a pristine product: the cart clone's price may already
			// have been mutated by an earlier run of this hook, and get_regular_price()
			// would silently cancel an active sale.
			$pristine = wc_get_product( $product->get_id() );
			$base     = $pristine instanceof \WC_Product ? (float) $pristine->get_price() : (float) $product->get_regular_price();

			$product->set_price( $base + ( $extra * ( $qty - 1 ) ) );
		}
	}

	/**
	 * @param \WC_Order_Item_Product $item
	 * @param string                 $cart_item_key
	 * @param array<string, mixed>   $values
	 * @param \WC_Order              $order
	 */
	public function save_to_order_item( $item, $cart_item_key, $values, $order ): void {
		$product = $item->get_product();

		if ( ! $product instanceof \WC_Product || ! $this->is_esim_product( $product ) ) {
			return;
		}

		$item->update_meta_data( Order_Esim_Store::ITEM_PACKAGE_ID, (string) $product->get_meta( Product_Meta::PACKAGE_ID ) );

		if ( ! empty( $values['nextsim_topup_code'] ) ) {
			$item->update_meta_data( Order_Esim_Store::ITEM_TOPUP_CODE, wc_clean( $values['nextsim_topup_code'] ) );
		}

		$qty = (int) ( $values['nextsim_esim_quantity'] ?? 1 );
		if ( $qty > 1 ) {
			$item->update_meta_data( Order_Esim_Store::ITEM_QUANTITY, $qty );
		}
	}
}
