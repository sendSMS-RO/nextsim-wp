<?php
/**
 * Composition root: builds the object graph and wires each module's hooks.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo;

use NextSIM\Woo\Account\My_Account;
use NextSIM\Woo\Admin\Balance_Widget;
use NextSIM\Woo\Admin\Settings_Page;
use NextSIM\Woo\Api\Api_Client;
use NextSIM\Woo\Checkout\Checkout_Fields;
use NextSIM\Woo\Data\Package_Repository;
use NextSIM\Woo\Emails\Email_Esim_Delivery;
use NextSIM\Woo\Frontend\Order_Display;
use NextSIM\Woo\Fulfilment\Order_Manager;
use NextSIM\Woo\Fulfilment\Provisioner;
use NextSIM\Woo\Fulfilment\Qr_Renderer;
use NextSIM\Woo\Fulfilment\Topup_Service;
use NextSIM\Woo\Import\Importer;
use NextSIM\Woo\Import\Pricing_Engine;
use NextSIM\Woo\Import\Product_Mapper;
use NextSIM\Woo\Import\Taxonomy_Sync;
use NextSIM\Woo\Webhook\Webhook_Controller;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	private static ?Plugin $instance = null;

	private Settings $settings;
	private Logger $logger;

	private function __construct() {
		$this->settings = new Settings();
		$this->logger   = new Logger();
	}

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function run(): void {
		load_plugin_textdomain( 'nextsim-woo', false, dirname( NEXTSIM_WOO_BASENAME ) . '/languages' );

		$importer = $this->importer();
		$qr       = new Qr_Renderer();

		( new Settings_Page( $this->settings, $this->api_client(), $importer ) )->register();
		( new Balance_Widget( $this->settings, $this->api_client(), $this->logger, $this->exchange_rate() ) )->register();
		$importer->register();

		( new Checkout_Fields( $this->pricing_engine(), $this->api_client() ) )->register();
		( new Order_Manager( $this->logger ) )->register();
		( new Provisioner( $this->api_client(), new Topup_Service( $this->api_client() ), $this->logger ) )->register();
		( new Webhook_Controller() )->register();
		( new Order_Display( $qr ) )->register();
		( new My_Account( $this->api_client(), $qr, $this->logger ) )->register();

		add_filter( 'woocommerce_email_classes', array( $this, 'register_emails' ) );
	}

	/**
	 * @param array<string, \WC_Email> $emails
	 * @return array<string, \WC_Email>
	 */
	public function register_emails( array $emails ): array {
		$emails['Email_Esim_Delivery'] = new Email_Esim_Delivery();

		return $emails;
	}

	public function settings(): Settings {
		return $this->settings;
	}

	public function api_client(): Api_Client {
		return new Api_Client( $this->settings->api_host(), $this->settings->api_token(), $this->logger );
	}

	public function exchange_rate(): Exchange_Rate {
		return new Exchange_Rate( $this->settings, $this->logger );
	}

	public function pricing_engine(): Pricing_Engine {
		return new Pricing_Engine(
			$this->settings->markup_percent(),
			$this->settings->category_markup(),
			$this->price_decimals(),
			$this->exchange_rate()->rate()
		);
	}

	public function product_mapper(): Product_Mapper {
		return new Product_Mapper(
			new Package_Repository(),
			$this->pricing_engine(),
			new Taxonomy_Sync(),
			$this->settings,
			$this->logger,
			$this->price_decimals()
		);
	}

	public function importer(): Importer {
		return new Importer(
			$this->api_client(),
			$this->product_mapper(),
			new Package_Repository(),
			$this->settings,
			$this->logger
		);
	}

	private function price_decimals(): int {
		return function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
	}
}
