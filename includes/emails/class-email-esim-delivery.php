<?php
/**
 * Customer email delivering the eSIM QR code(s) and install links once provisioned.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Emails;

use NextSIM\Woo\Data\Order_Esim_Store;
use NextSIM\Woo\Fulfilment\Qr_Renderer;

defined( 'ABSPATH' ) || exit;

class Email_Esim_Delivery extends \WC_Email {

	/**
	 * QR codes embedded in the email being sent, keyed by LPA string. Empty outside
	 * a send (e.g. the WooCommerce email preview), where the template falls back to
	 * the inline SVG.
	 *
	 * @var array<string, array{cid: string, png: string}>
	 */
	private array $qr_images = array();

	public function __construct() {
		$this->id             = 'nextsim_esim_delivery';
		$this->title          = __( 'nextSIM eSIM delivery', 'nextsim-woo' );
		$this->description     = __( 'Sent to the customer with their eSIM QR code(s) once provisioning completes.', 'nextsim-woo' );
		$this->customer_email = true;
		$this->template_html  = 'email/esim-delivery.php';
		$this->template_plain = 'email/esim-delivery-plain.php';
		$this->template_base  = NEXTSIM_WOO_PATH . 'templates/';
		$this->placeholders   = array( '{order_number}' => '' );

		add_action( 'nextsim_woo_order_esims_ready', array( $this, 'trigger' ), 10, 1 );

		parent::__construct();
	}

	public function get_default_subject(): string {
		return __( 'Your eSIM for order #{order_number} is ready', 'nextsim-woo' );
	}

	public function get_default_heading(): string {
		return __( 'Your eSIM is ready', 'nextsim-woo' );
	}

	/**
	 * @param int $order_id
	 */
	public function trigger( $order_id ): void {
		$this->setup_locale();

		$order = wc_get_order( $order_id );

		if ( $order instanceof \WC_Order ) {
			$this->object                         = $order;
			$this->recipient                      = $order->get_billing_email();
			$this->placeholders['{order_number}'] = $order->get_order_number();
		}

		$sent = false;

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->qr_images = $order instanceof \WC_Order && 'plain' !== $this->get_email_type()
				? $this->build_qr_images( $order )
				: array();

			add_action( 'phpmailer_init', array( $this, 'embed_qr_images' ) );

			try {
				$sent = (bool) $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
			} finally {
				remove_action( 'phpmailer_init', array( $this, 'embed_qr_images' ) );
				$this->qr_images = array();
			}
		}

		// Leave a trace either way: the QR is on the order page regardless, but the
		// shop should know when the customer did not get it by email (disabled email,
		// missing address, mail failure) and can use "resend eSIM delivery email".
		if ( $order instanceof \WC_Order ) {
			$order->add_order_note(
				$sent
					? __( 'nextSIM: eSIM delivery email sent to the customer.', 'nextsim-woo' )
					: __( 'nextSIM: eSIM delivery email NOT sent (email disabled, no billing email, or mail failure). Use "nextSIM: resend eSIM delivery email" once fixed.', 'nextsim-woo' )
			);
			$order->save();
		}

		$this->restore_locale();
	}

	/**
	 * Mail clients such as Gmail and Outlook block data-URI and SVG images, so each QR
	 * code travels as a PNG attached to the message and referenced by Content-ID.
	 *
	 * @return array<string, array{cid: string, png: string}>
	 */
	private function build_qr_images( \WC_Order $order ): array {
		$qr     = new Qr_Renderer();
		$images = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product
				|| ! Order_Esim_Store::is_nextsim_item( $item )
				|| Order_Esim_Store::ITEM_STATUS_COMPLETED !== (string) $item->get_meta( Order_Esim_Store::ITEM_STATUS )
			) {
				continue;
			}

			foreach ( Order_Esim_Store::esims_for_item( $item ) as $esim ) {
				$lpa = $esim['lpa'];

				if ( '' === $lpa || isset( $images[ $lpa ] ) ) {
					continue;
				}

				$png = $qr->png( $lpa );

				if ( '' !== $png ) {
					$images[ $lpa ] = array(
						'cid' => 'nextsim-esim-qr-' . ( count( $images ) + 1 ),
						'png' => $png,
					);
				}
			}
		}

		return $images;
	}

	/**
	 * @param \PHPMailer\PHPMailer\PHPMailer $phpmailer
	 */
	public function embed_qr_images( $phpmailer ): void {
		foreach ( $this->qr_images as $image ) {
			$phpmailer->addStringEmbeddedImage( $image['png'], $image['cid'], $image['cid'] . '.png', 'base64', 'image/png' );
		}
	}

	/**
	 * Where the customer can see the eSIM online. A guest has no account to log in to,
	 * so they get the order-received page, which opens with the order key in the link.
	 */
	public static function online_url( \WC_Order $order ): string {
		return $order->get_customer_id() > 0 ? $order->get_view_order_url() : $order->get_checkout_order_received_url();
	}

	public function get_content_html(): string {
		return wc_get_template_html(
			$this->template_html,
			array(
				'order'         => $this->object,
				'email_heading' => $this->get_heading(),
				'qr'            => new Qr_Renderer(),
				'qr_cids'       => array_map( static fn ( array $image ): string => $image['cid'], $this->qr_images ),
				'sent_to_admin' => false,
				'plain_text'    => false,
				'email'         => $this,
			),
			'',
			$this->template_base
		);
	}

	public function get_content_plain(): string {
		return wc_get_template_html(
			$this->template_plain,
			array(
				'order'         => $this->object,
				'email_heading' => $this->get_heading(),
				'sent_to_admin' => false,
				'plain_text'    => true,
				'email'         => $this,
			),
			'',
			$this->template_base
		);
	}
}
