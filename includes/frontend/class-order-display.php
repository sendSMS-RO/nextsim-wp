<?php
/**
 * Renders eSIM QR codes and install links on the order-received and view-order pages.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Frontend;

use NextSIM\Woo\Data\Order_Esim_Store;
use NextSIM\Woo\Frontend\Install_Guide;
use NextSIM\Woo\Fulfilment\Qr_Renderer;

defined( 'ABSPATH' ) || exit;

class Order_Display {

	public function __construct( private Qr_Renderer $qr ) {}

	public function register(): void {
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets(): void {
		if ( ! function_exists( 'is_wc_endpoint_url' ) ) {
			return;
		}

		if ( is_order_received_page() || is_view_order_page() || is_account_page() ) {
			wp_enqueue_style( 'nextsim-woo-frontend', NEXTSIM_WOO_URL . 'assets/css/frontend.css', array(), NEXTSIM_WOO_VERSION );
		}
	}

	public function render( \WC_Order $order ): void {
		$blocks = '';

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product || ! Order_Esim_Store::is_nextsim_item( $item ) ) {
				continue;
			}

			$status = (string) $item->get_meta( Order_Esim_Store::ITEM_STATUS );

			if ( Order_Esim_Store::ITEM_STATUS_COMPLETED === $status ) {
				$blocks .= $this->render_item( $item );
			} elseif ( Order_Esim_Store::ITEM_STATUS_FAILED === $status ) {
				$blocks .= '<p class="nextsim-esim-error">' . esc_html( (string) $item->get_meta( Order_Esim_Store::ITEM_LAST_ERROR ) ) . '</p>';
			} elseif ( '' !== $status ) {
				$blocks .= '<p class="nextsim-esim-pending">' . esc_html__( 'Your eSIM is being provisioned. This page will show the QR code shortly.', 'nextsim-woo' ) . '</p>';
			}
		}

		if ( '' === $blocks ) {
			return;
		}

		echo '<section class="nextsim-esims"><h2>' . esc_html__( 'Your eSIM', 'nextsim-woo' ) . '</h2>' . $blocks . '</section>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	private function render_item( \WC_Order_Item_Product $item ): string {
		$esims = Order_Esim_Store::esims_for_item( $item );
		$multi = count( $esims ) > 1;
		$out   = '<div class="nextsim-esim-plan"><h3>' . esc_html( $item->get_name() ) . '</h3>';

		foreach ( $esims as $index => $esim ) {
			$out .= '<div class="nextsim-esim">';

			if ( $multi ) {
				$out .= '<h4>' . esc_html( sprintf( /* translators: %d: index */ __( 'eSIM %d', 'nextsim-woo' ), (int) $index + 1 ) ) . '</h4>';
			}

			$data_uri = $this->qr->svg_data_uri( $esim['lpa'] );
			if ( '' !== $data_uri ) {
				$out .= '<img class="nextsim-qr" src="' . esc_attr( $data_uri ) . '" alt="' . esc_attr__( 'eSIM QR code', 'nextsim-woo' ) . '" width="220" height="220" />';
			}

			if ( '' !== $esim['activation_code'] ) {
				$out .= '<p class="nextsim-code"><span>' . esc_html__( 'Activation code', 'nextsim-woo' ) . ':</span> <code>' . esc_html( $esim['activation_code'] ) . '</code></p>';
			}

			$links = array();
			if ( '' !== $esim['apple_url'] ) {
				$links[] = '<a class="button" href="' . esc_url( $esim['apple_url'] ) . '">' . esc_html__( 'Install on iPhone', 'nextsim-woo' ) . '</a>';
			}
			if ( '' !== $esim['android_url'] ) {
				$links[] = '<a class="button" href="' . esc_url( $esim['android_url'] ) . '">' . esc_html__( 'Install on Android', 'nextsim-woo' ) . '</a>';
			}
			if ( array() !== $links ) {
				$out .= '<p class="nextsim-install">' . implode( ' ', $links ) . '</p>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}

			$out .= '</div>';
		}

		$out .= Install_Guide::html();

		return $out . '</div>';
	}
}
