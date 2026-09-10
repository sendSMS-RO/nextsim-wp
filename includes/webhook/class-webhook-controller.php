<?php
/**
 * REST endpoint for the nextSIM activation callback.
 *
 * The callback carries no payload the plugin relies on: it only reschedules the
 * provisioning poll for the referenced order item, which then re-fetches the
 * authoritative eSIM data via the reseller API. The URL handed to the API carries an
 * HMAC of the order/item pair, so a stranger who guesses ids cannot use the endpoint
 * to fire polling chains (and burn the reseller's API rate limit).
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Webhook;

use NextSIM\Woo\Data\Order_Esim_Store;
use NextSIM\Woo\Fulfilment\Provisioner;

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
				// Authentication is the signature check in handle(); an invalid signature
				// gets the same 200 as a valid one, so the endpoint is not an oracle.
				'permission_callback' => '__return_true',
				'callback'            => array( $this, 'handle' ),
				'args'                => array(
					'order_id' => array( 'sanitize_callback' => 'absint' ),
					'item_id'  => array( 'sanitize_callback' => 'absint' ),
					'sig'      => array( 'sanitize_callback' => 'sanitize_text_field' ),
				),
			)
		);
	}

	/**
	 * Signature embedded in the callback URL given to the API for one order item.
	 */
	public static function signature( int $order_id, int $item_id ): string {
		return hash_hmac( 'sha256', $order_id . ':' . $item_id, wp_salt( 'auth' ) );
	}

	/**
	 * The callback URL for an order item, as sent to the API in the activate payload.
	 */
	public static function callback_url( int $order_id, int $item_id ): string {
		return add_query_arg(
			'sig',
			self::signature( $order_id, $item_id ),
			rest_url( sprintf( 'nextsim-woo/v1/callback/order/%d/item/%d', $order_id, $item_id ) )
		);
	}

	public function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$order_id = (int) $request['order_id'];
		$item_id  = (int) $request['item_id'];
		$sig      = (string) ( $request['sig'] ?? '' );

		if ( '' !== $sig && hash_equals( self::signature( $order_id, $item_id ), $sig ) ) {
			$this->maybe_resume_poll( $order_id, $item_id );
		}

		return new \WP_REST_Response( array( 'received' => true ), 200 );
	}

	private function maybe_resume_poll( int $order_id, int $item_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$item = $order->get_item( $item_id );

		// Only items that are actively awaiting provisioning (status POLLING, i.e. an
		// order token exists) may be re-polled. Anything else — unpaid, completed,
		// failed — ignores the ping, so the endpoint cannot poison an order.
		if ( ! $item instanceof \WC_Order_Item_Product
			|| ! Order_Esim_Store::is_nextsim_item( $item )
			|| Order_Esim_Store::ITEM_STATUS_POLLING !== (string) $item->get_meta( Order_Esim_Store::ITEM_STATUS )
		) {
			return;
		}

		// Debounce: cap enqueues to one per item per minute — the legitimate callback
		// fires only once per provisioning.
		$guard = sprintf( 'nextsim_woo_cb_%d_%d', $order_id, $item_id );

		if ( false !== get_transient( $guard ) ) {
			return;
		}

		set_transient( $guard, 1, MINUTE_IN_SECONDS );

		// Pull the scheduled poll forward (replacing it, so the item keeps a single
		// chain) and resume at the stored attempt, so a ping cannot reset the counter
		// and extend the polling window indefinitely.
		Provisioner::enqueue_poll_now( $order_id, $item_id, (int) $item->get_meta( Order_Esim_Store::ITEM_POLL_ATTEMPTS ) );
	}
}
