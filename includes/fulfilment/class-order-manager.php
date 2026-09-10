<?php
/**
 * Hooks order payment and enqueues one provisioning job per eSIM line item.
 * Idempotent: guarded by an order-level provisioning state flag.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Fulfilment;

use NextSIM\Woo\Data\Order_Esim_Store;
use NextSIM\Woo\Import\Importer;
use NextSIM\Woo\Logger;

defined( 'ABSPATH' ) || exit;

class Order_Manager {

	// An item stuck in ACTIVATING longer than this had its provision job die mid-flight
	// (fatal/timeout). Below it, a live activation may still be in progress, so leave it.
	private const STUCK_ACTIVATING_SECONDS = 15 * MINUTE_IN_SECONDS;

	public function __construct( private Logger $logger ) {}

	public function register(): void {
		add_action( 'woocommerce_payment_complete', array( $this, 'on_order_paid' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'on_order_paid' ) );
		add_action( 'woocommerce_order_status_completed', array( $this, 'on_order_paid' ) );

		add_filter( 'woocommerce_order_actions', array( $this, 'add_retry_action' ) );
		add_action( 'woocommerce_order_action_nextsim_retry_provisioning', array( $this, 'retry_provisioning' ) );
	}

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public function add_retry_action( array $actions ): array {
		$actions['nextsim_retry_provisioning'] = __( 'nextSIM: retry eSIM provisioning', 'nextsim-woo' );

		return $actions;
	}

	/**
	 * Admin-triggered recovery: clears the provisioning state and re-enqueues every
	 * eSIM item that has not been delivered yet. Completed items are left untouched.
	 */
	public function retry_provisioning( \WC_Order $order ): void {
		$retried = 0;

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product || ! Order_Esim_Store::is_nextsim_item( $item ) ) {
				continue;
			}

			$status = (string) $item->get_meta( Order_Esim_Store::ITEM_STATUS );

			if ( Order_Esim_Store::ITEM_STATUS_COMPLETED === $status ) {
				continue;
			}

			// An activation may be in flight — retrying now could double-charge. Skip a
			// recent ACTIVATING item, but recover one whose job provably died (stale):
			// otherwise it is stuck forever with no other recovery path.
			if ( Order_Esim_Store::ITEM_STATUS_ACTIVATING === $status ) {
				$started = (int) $item->get_meta( Order_Esim_Store::ITEM_ACTIVATING_AT );
				$stale   = $started > 0 && ( time() - $started ) > self::STUCK_ACTIVATING_SECONDS;

				if ( ! $stale ) {
					continue;
				}

				$order->add_order_note( __( 'nextSIM: an eSIM was stuck activating and is being retried — the previous attempt may have charged the reseller account, so verify it before assuming a double charge.', 'nextsim-woo' ) );
			}

			// If a token exists the upstream order may already be provisioning — resume
			// polling instead of re-activating (which would charge the reseller again).
			$has_token = '' !== (string) $item->get_meta( Order_Esim_Store::ITEM_ORDER_TOKEN );
			$hook      = $has_token ? Provisioner::HOOK_POLL : Provisioner::HOOK_PROVISION;

			$item->update_meta_data(
				Order_Esim_Store::ITEM_STATUS,
				$has_token ? Order_Esim_Store::ITEM_STATUS_POLLING : Order_Esim_Store::ITEM_STATUS_PENDING
			);
			$item->update_meta_data( Order_Esim_Store::ITEM_LAST_ERROR, '' );
			$item->save();

			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action(
					$hook,
					array( array( 'order_id' => $order->get_id(), 'item_id' => (int) $item_id, 'try' => 0 ) ),
					Importer::GROUP
				);
				++$retried;
			}
		}

		if ( $retried > 0 ) {
			$order->update_meta_data( Order_Esim_Store::ORDER_STATE, Order_Esim_Store::STATE_ACTIVATING );
			/* translators: %d: number of items. */
			$order->add_order_note( sprintf( __( 'nextSIM: provisioning retried for %d item(s).', 'nextsim-woo' ), $retried ) );
			$order->save();
		}
	}

	/**
	 * Re-queue the provisioning work of every order still marked "activating": items
	 * waiting for their QR resume polling, items never started get a fresh provision
	 * job. Items caught mid-activation are left alone (an activation may have gone
	 * through upstream; the admin "retry" action handles those after the stale window).
	 *
	 * Used on plugin activation, where a paused Action Scheduler queue may have run our
	 * hooks with no listener attached and marked them complete.
	 *
	 * @return int Number of items re-queued.
	 */
	public static function requeue_in_flight(): int {
		if ( ! function_exists( 'wc_get_orders' ) || ! function_exists( 'as_enqueue_async_action' ) ) {
			return 0;
		}

		$order_ids = wc_get_orders(
			array(
				'limit'      => -1,
				'return'     => 'ids',
				'meta_key'   => Order_Esim_Store::ORDER_STATE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => Order_Esim_Store::STATE_ACTIVATING, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$requeued = 0;

		foreach ( (array) $order_ids as $order_id ) {
			$order = wc_get_order( (int) $order_id );
			if ( ! $order instanceof \WC_Order ) {
				continue;
			}

			foreach ( $order->get_items() as $item_id => $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product || ! Order_Esim_Store::is_nextsim_item( $item ) ) {
					continue;
				}

				$status = (string) $item->get_meta( Order_Esim_Store::ITEM_STATUS );

				if ( Order_Esim_Store::ITEM_STATUS_POLLING === $status ) {
					$hook = Provisioner::HOOK_POLL;
					$try  = (int) $item->get_meta( Order_Esim_Store::ITEM_POLL_ATTEMPTS );
				} elseif ( in_array( $status, array( '', Order_Esim_Store::ITEM_STATUS_PENDING ), true ) ) {
					$hook = Provisioner::HOOK_PROVISION;
					$try  = 0;
				} else {
					continue;
				}

				as_enqueue_async_action(
					$hook,
					array( array( 'order_id' => (int) $order_id, 'item_id' => (int) $item_id, 'try' => $try ) ),
					Importer::GROUP
				);
				++$requeued;
			}
		}

		return $requeued;
	}

	/**
	 * @param int $order_id
	 */
	public function on_order_paid( $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		// Idempotency guard: only the first paid transition provisions.
		if ( '' !== (string) $order->get_meta( Order_Esim_Store::ORDER_STATE ) ) {
			return;
		}

		$esim_items = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( $item instanceof \WC_Order_Item_Product && Order_Esim_Store::is_nextsim_item( $item ) ) {
				$esim_items[] = (int) $item_id;
			}
		}

		if ( array() === $esim_items ) {
			return;
		}

		$order->update_meta_data( Order_Esim_Store::ORDER_STATE, Order_Esim_Store::STATE_ACTIVATING );
		$order->add_order_note( __( 'nextSIM: provisioning eSIM(s)…', 'nextsim-woo' ) );
		$order->save();

		foreach ( $esim_items as $item_id ) {
			if ( function_exists( 'as_enqueue_async_action' ) ) {
				as_enqueue_async_action(
					Provisioner::HOOK_PROVISION,
					array( array( 'order_id' => (int) $order_id, 'item_id' => $item_id ) ),
					Importer::GROUP
				);
			}
		}

		$this->logger->info( 'Order provisioning enqueued', array( 'order' => $order_id, 'items' => count( $esim_items ) ) );
	}
}
