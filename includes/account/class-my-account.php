<?php
/**
 * "My eSIMs" account area: lists the customer's eSIMs and lets them check data
 * consumption. Consumption lookups are restricted to activation codes that belong
 * to the current user's own orders.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Account;

use NextSIM\Woo\Api\Api_Client;
use NextSIM\Woo\Api\Api_Exception;
use NextSIM\Woo\Data\Order_Esim_Store;
use NextSIM\Woo\Fulfilment\Qr_Renderer;
use NextSIM\Woo\Logger;

defined( 'ABSPATH' ) || exit;

class My_Account {

	public const ENDPOINT = 'nextsim-esims';

	public function __construct(
		private Api_Client $client,
		private Qr_Renderer $qr,
		private Logger $logger
	) {}

	public static function add_endpoints(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	public function register(): void {
		add_action( 'init', array( self::class, 'add_endpoints' ) );
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'add_menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render' ) );
		add_action( 'wp_ajax_nextsim_check_consumption', array( $this, 'ajax_check_consumption' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * @param array<int, string> $vars
	 * @return array<int, string>
	 */
	public function add_query_var( $vars ): array {
		$vars[] = self::ENDPOINT;

		return $vars;
	}

	/**
	 * @param array<string, string> $items
	 * @return array<string, string>
	 */
	public function add_menu_item( $items ): array {
		$new = array();
		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'orders' === $key ) {
				$new[ self::ENDPOINT ] = __( 'My eSIMs', 'nextsim-woo' );
			}
		}

		if ( ! isset( $new[ self::ENDPOINT ] ) ) {
			$new[ self::ENDPOINT ] = __( 'My eSIMs', 'nextsim-woo' );
		}

		return $new;
	}

	public function enqueue_assets(): void {
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			wp_enqueue_script( 'nextsim-woo-frontend', NEXTSIM_WOO_URL . 'assets/js/frontend.js', array( 'jquery' ), NEXTSIM_WOO_VERSION, true );
			wp_localize_script(
				'nextsim-woo-frontend',
				'nextsimWooFront',
				array(
					'ajaxUrl' => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'nextsim_check_consumption' ),
					'loading' => __( 'Checking…', 'nextsim-woo' ),
					'error'   => __( 'Could not fetch usage.', 'nextsim-woo' ),
				)
			);
		}
	}

	public function render(): void {
		$esims = $this->collect_user_esims( get_current_user_id() );

		if ( array() === $esims ) {
			wc_print_notice( __( 'You have no eSIMs yet.', 'nextsim-woo' ), 'notice' );

			return;
		}

		echo '<div class="nextsim-my-esims">';
		foreach ( $esims as $esim ) {
			$this->render_esim( $esim );
		}
		echo '</div>';
	}

	/**
	 * @param array{plan: string, activation_code: string, lpa: string, apple_url: string, android_url: string, is_creator: string} $esim
	 */
	private function render_esim( array $esim ): void {
		echo '<div class="nextsim-esim">';
		echo '<h3>' . esc_html( $esim['plan'] ) . '</h3>';

		$data_uri = $this->qr->svg_data_uri( $esim['lpa'] );
		if ( '' !== $data_uri ) {
			echo '<img class="nextsim-qr" src="' . esc_attr( $data_uri ) . '" alt="' . esc_attr__( 'eSIM QR code', 'nextsim-woo' ) . '" width="180" height="180" />';
		}

		if ( '' !== $esim['activation_code'] ) {
			echo '<p class="nextsim-code"><code>' . esc_html( $esim['activation_code'] ) . '</code></p>';

			// The usage lookup only works for the creator eSIM: the backend resolves
			// balance by the order's activation code, and Multi-eSIM member codes live
			// outside esim_orders, so a member lookup would always fail. Members still
			// see their QR and code above — just not the usage button.
			if ( '1' === ( $esim['is_creator'] ?? '1' ) ) {
				printf(
					'<p><button type="button" class="button nextsim-check-usage" data-code="%s">%s</button></p><div class="nextsim-usage-result"></div>',
					esc_attr( $esim['activation_code'] ),
					esc_html__( 'Check data usage', 'nextsim-woo' )
				);
			}
		}

		echo '</div>';
	}

	public function ajax_check_consumption(): void {
		check_ajax_referer( 'nextsim_check_consumption', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Please log in.', 'nextsim-woo' ) ), 403 );
		}

		$code = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';

		if ( '' === $code || ! in_array( $code, $this->collect_user_activation_codes( get_current_user_id() ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Unknown activation code.', 'nextsim-woo' ) ), 404 );
		}

		try {
			$usage = $this->client->get_consumption( $code );
		} catch ( Api_Exception $e ) {
			$this->logger->warning( 'Consumption check failed', array( 'error' => $e->getMessage() ) );
			wp_send_json_error( array( 'message' => __( 'Could not fetch usage right now. Please try again later.', 'nextsim-woo' ) ) );
		}

		wp_send_json_success( array( 'usage' => array_values( $usage ) ) );
	}

	/**
	 * @return array<int, array{plan: string, activation_code: string, lpa: string, apple_url: string, android_url: string, is_creator: string}>
	 */
	private function collect_user_esims( int $user_id ): array {
		if ( $user_id <= 0 ) {
			return array();
		}

		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'limit'       => -1,
				'status'      => array( 'processing', 'completed', 'on-hold' ),
			)
		);

		$esims = array();
		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product || ! Order_Esim_Store::is_nextsim_item( $item ) ) {
					continue;
				}

				if ( Order_Esim_Store::ITEM_STATUS_COMPLETED !== (string) $item->get_meta( Order_Esim_Store::ITEM_STATUS ) ) {
					continue;
				}

				foreach ( Order_Esim_Store::esims_for_item( $item ) as $esim ) {
					if ( '' === $esim['activation_code'] && '' === $esim['lpa'] ) {
						continue;
					}

					$esims[] = array(
						'plan'            => $item->get_name(),
						'activation_code' => $esim['activation_code'],
						'lpa'             => $esim['lpa'],
						'apple_url'       => $esim['apple_url'],
						'android_url'     => $esim['android_url'],
						'is_creator'      => $esim['is_creator'] ?? '1',
					);
				}
			}
		}

		return $esims;
	}

	/**
	 * @return array<int, string>
	 */
	private function collect_user_activation_codes( int $user_id ): array {
		return array_values(
			array_filter(
				array_map(
					static fn ( array $e ): string => $e['activation_code'],
					$this->collect_user_esims( $user_id )
				)
			)
		);
	}
}
