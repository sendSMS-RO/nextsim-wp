<?php
/**
 * Customer email delivering the eSIM QR code(s) and install links once provisioned.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Emails;

use NextSIM\Woo\Fulfilment\Qr_Renderer;

defined( 'ABSPATH' ) || exit;

class Email_Esim_Delivery extends \WC_Email {

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

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
	}

	public function get_content_html(): string {
		return wc_get_template_html(
			$this->template_html,
			array(
				'order'         => $this->object,
				'email_heading' => $this->get_heading(),
				'qr'            => new Qr_Renderer(),
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
