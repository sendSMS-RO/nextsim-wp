<?php
/**
 * eSIM delivery email (HTML).
 *
 * @var \WC_Order                          $order
 * @var string                             $email_heading
 * @var \NextSIM\Woo\Fulfilment\Qr_Renderer $qr
 * @var array<string, string>              $qr_cids Content-IDs of the attached QR PNGs, keyed by LPA string.
 * @var \WC_Email                          $email
 *
 * @package NextSIM\Woo
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- WooCommerce email template: the
// header/footer hooks and the injected variables ($order, $email_heading, $email) are WooCommerce's
// template contract; the rest are locals of this include, not real globals.

use NextSIM\Woo\Data\Order_Esim_Store;
use NextSIM\Woo\Emails\Email_Esim_Delivery;

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email ); ?>

<p><?php esc_html_e( 'Thank you! Your eSIM is ready. Installation details are below.', 'nextsim-woo' ); ?></p>
<?php
// The QR codes are attached to this email as images, but a mail client may still hide
// them, so always offer the order page, where the QR code renders in the browser.
printf(
	'<p><a href="%s" style="display:inline-block;padding:10px 18px;background:#7f54b3;color:#ffffff;text-decoration:none;border-radius:4px;">%s</a></p>',
	esc_url( Email_Esim_Delivery::online_url( $order ) ),
	esc_html__( 'View your eSIM & QR code online', 'nextsim-woo' )
);
?>

<?php
foreach ( $order->get_items() as $item ) {
	if ( ! $item instanceof \WC_Order_Item_Product || ! Order_Esim_Store::is_nextsim_item( $item ) ) {
		continue;
	}

	if ( Order_Esim_Store::ITEM_STATUS_COMPLETED !== (string) $item->get_meta( Order_Esim_Store::ITEM_STATUS ) ) {
		continue;
	}

	$esims = Order_Esim_Store::esims_for_item( $item );
	$multi = count( $esims ) > 1;

	echo '<h2 style="margin-top:24px;">' . esc_html( $item->get_name() ) . '</h2>';

	foreach ( $esims as $index => $esim ) {
		if ( $multi ) {
			printf( '<p><strong>%s %d</strong></p>', esc_html__( 'eSIM', 'nextsim-woo' ), (int) $index + 1 );
		}

		// Attached PNG when the email is being sent; inline SVG otherwise (email preview,
		// or a server without the GD extension).
		$qr_src = isset( $qr_cids[ $esim['lpa'] ] ) ? 'cid:' . $qr_cids[ $esim['lpa'] ] : $qr->svg_data_uri( $esim['lpa'] );
		if ( '' !== $qr_src ) {
			printf( '<p><img src="%s" alt="%s" width="220" height="220" /></p>', esc_attr( $qr_src ), esc_attr__( 'eSIM QR code', 'nextsim-woo' ) );
		}

		if ( '' !== $esim['activation_code'] ) {
			printf(
				'<p style="font-family:monospace;"><strong>%s</strong> %s</p>',
				esc_html__( 'Activation code:', 'nextsim-woo' ),
				esc_html( $esim['activation_code'] )
			);
		}

		if ( '' !== $esim['lpa'] ) {
			printf(
				'<p style="font-family:monospace;word-break:break-all;"><strong>%s</strong> %s</p>',
				esc_html__( 'Manual installation (LPA):', 'nextsim-woo' ),
				esc_html( $esim['lpa'] )
			);
		}

		$links = array();
		if ( '' !== $esim['apple_url'] ) {
			$links[] = '<a href="' . esc_url( $esim['apple_url'] ) . '">' . esc_html__( 'Install on iPhone', 'nextsim-woo' ) . '</a>';
		}
		if ( '' !== $esim['android_url'] ) {
			$links[] = '<a href="' . esc_url( $esim['android_url'] ) . '">' . esc_html__( 'Install on Android', 'nextsim-woo' ) . '</a>';
		}
		if ( array() !== $links ) {
			echo '<p>' . wp_kses_post( implode( ' &nbsp;|&nbsp; ', $links ) ) . '</p>';
		}
	}
}

do_action( 'woocommerce_email_footer', $email );
