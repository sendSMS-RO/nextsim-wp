<?php
/**
 * REST endpoint for the nextSIM activation callback.
 *
 * The callback is UNSIGNED and best-effort, so this handler trusts nothing in the
 * payload: it only reschedules the provisioning poll for the referenced order item,
 * which then re-fetches the authoritative eSIM data via the reseller API.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Webhook;

use NextSIM\Woo\Data\Order_Esim_Store;
use NextSIM\Woo\Fulfilment\Provisioner;
use NextSIM\Woo\Import\Importer;

defined( 'ABSPATH' ) || exit;

class Webhook_Controller {

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'nextsim-woo/v1',
			'/callback/order/(?P<order_id>\d+)/item/(?P<item_id>\d+)',
			array(
				'methods'             => 'POST',
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'handle' ),
				'args'                => array(
					'order_id' => array( 'sanitize_callback' => 'absint' ),
					'item_id'  => array( 'sanitize_callback' => 'absint' ),
				),
			)
		);
	}

	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$order_id = (int) $request['order_id'];
		$item_id  = (int) $request['item_id'];

		$order = wc_get_order( $order_id );

		if ( $order instanceof \WC_Order ) {
			$item = $order->get_item( $item_id );

			// Only items that are actively awaiting provisioning (status POLLING, i.e. an
			// order token exists) may be re-polled. Anything else — unpaid, completed,
			// failed — ignores the ping, so the public endpoint cannot poison an order.
			if ( $item instanceof \WC_Order_Item_Product
				&& Order_Esim_Store::is_nextsim_item( $item )
				&& Order_Esim_Store::ITEM_STATUS_POLLING === (string) $item->get_meta( Order_Esim_Store::ITEM_STATUS )
				&& function_exists( 'as_enqueue_async_action' )
			) {
				// Debounce: the endpoint is public, so cap enqueues to one per item per
				// minute — the legitimate callback fires only once per provisioning.
				$guard = sprintf( 'nextsim_woo_cb_%d_%d', $order_id, $item_id );

				if ( false === get_transient( $guard ) ) {
					set_transient( $guard, 1, MINUTE_IN_SECONDS );

					as_enqueue_async_action(
						Provisioner::HOOK_POLL,
						array( array( 'order_id' => $order_id, 'item_id' => $item_id, 'try' => 0 ) ),
						Importer::GROUP
					);
				}
			}
		}

		return new \WP_REST_Response( array( 'received' => true ), 200 );
	}
}
