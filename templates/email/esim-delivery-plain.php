<?php
/**
 * eSIM delivery email (plain text).
 *
 * @var \WC_Order $order
 * @var string    $email_heading
 *
 * @package NextSIM\Woo
 */

use NextSIM\Woo\Data\Order_Esim_Store;

defined( 'ABSPATH' ) || exit;

echo "= " . esc_html( wp_strip_all_tags( $email_heading ) ) . " =\n\n";
echo esc_html__( 'Your eSIM is ready. Use the activation code or install link on your device.', 'nextsim-woo' ) . "\n\n";

foreach ( $order->get_items() as $item ) {
	if ( ! $item instanceof \WC_Order_Item_Product || ! Order_Esim_Store::is_nextsim_item( $item ) ) {
		continue;
	}

	if ( Order_Esim_Store::ITEM_STATUS_COMPLETED !== (string) $item->get_meta( Order_Esim_Store::ITEM_STATUS ) ) {
		continue;
	}

	echo "\n" . esc_html( $item->get_name() ) . "\n";

	foreach ( Order_Esim_Store::esims_for_item( $item ) as $index => $esim ) {
		echo "----------\n";
		if ( '' !== $esim['activation_code'] ) {
			echo esc_html__( 'Activation code:', 'nextsim-woo' ) . ' ' . esc_html( $esim['activation_code'] ) . "\n";
		}
		if ( '' !== $esim['lpa'] ) {
			echo 'LPA: ' . esc_html( $esim['lpa'] ) . "\n";
		}
		if ( '' !== $esim['apple_url'] ) {
			echo esc_html__( 'iPhone:', 'nextsim-woo' ) . ' ' . esc_url_raw( $esim['apple_url'] ) . "\n";
		}
		if ( '' !== $esim['android_url'] ) {
			echo esc_html__( 'Android:', 'nextsim-woo' ) . ' ' . esc_url_raw( $esim['android_url'] ) . "\n";
		}
	}
}

echo "\n" . esc_html( wp_strip_all_tags( wptexturize( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ) ) ) . "\n";
