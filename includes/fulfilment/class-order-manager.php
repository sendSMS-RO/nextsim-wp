<?php
/**
 * Hooks order payment and enqueues one provisioning job per eSIM line item.
 * Idempotent: guarded by an order-level provisioning state flag plus an atomic
 * per-item claim in the provisioning job itself.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Fulfilment;

use NextSIM\Woo\Data\Order_Esim_Store;
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

		add_action( 'woocommerce_order_status_cancelled', array( $this, 'on_order_void' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'on_order_void' ) );

		add_filter( 'woocommerce_order_actions', array( $this, 'add_order_actions' ) );
		add_action( 'woocommerce_order_action_nextsim_retry_provisioning', array( $this, 'retry_provisioning' ) );
		add_action( 'woocommerce_order_action_nextsim_resend_delivery_email', array( $this, 'resend_delivery_email' ) );
	}

	/**
	 * @param array<string, string> $actions
	 * @return array<string, string>
	 */
	public function add_order_actions( array $actions ): array {
		$actions['nextsim_retry_provisioning']    = __( 'nextSIM: retry eSIM provisioning', 'nextsim-woo' );
		$actions['nextsim_resend_delivery_email'] = __( 'nextSIM: resend eSIM delivery email', 'nextsim-woo' );

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
				$stale   = 0 === $started || ( time() - $started ) > self::STUCK_ACTIVATING_SECONDS;

				if ( ! $stale ) {
					continue;
				}

				$order->add_order_note( __( 'nextSIM: an eSIM was stuck activating and is being retried — the previous attempt may have charged the reseller account, so verify it before assuming a double charge.', 'nextsim-woo' ) );
			}

			// If a token exists the upstream order may already be provisioning — resume
			// polling instead of re-activating (which would charge the reseller again).
			$has_token = '' !== (string) $item->get_meta( Order_Esim_Store::ITEM_ORDER_TOKEN );

			// A clamped Multi-eSIM pack: the API allocated fewer eSIMs than ordered and
			// the reseller was charged for those. Retrying delivers what was allocated;
			// the shop refunds the difference to the customer.
			$ordered   = max( 1, (int) $item->get_meta( Order_Esim_Store::ITEM_QUANTITY ) );
			$allocated = (int) $item->get_meta( Order_Esim_Store::ITEM_ALLOCATED );
			if ( $has_token && $allocated > 0 && $allocated < $ordered ) {
				$item->update_meta_data( Order_Esim_Store::ITEM_QUANTITY, $allocated );
				$order->add_order_note(
					sprintf(
						/* translators: 1: eSIMs allocated upstream, 2: eSIMs ordered. */
						__( 'nextSIM: delivering the %1$d eSIM(s) that were allocated out of %2$d ordered — refund the difference to the customer.', 'nextsim-woo' ),
						$allocated,
						$ordered
					)
				);
			}

			$item->update_meta_data(
				Order_Esim_Store::ITEM_STATUS,
				$has_token ? Order_Esim_Store::ITEM_STATUS_POLLING : Order_Esim_Store::ITEM_STATUS_PENDING
			);
			$item->update_meta_data( Order_Esim_Store::ITEM_LAST_ERROR, '' );
			$item->update_meta_data( Order_Esim_Store::ITEM_POLL_ATTEMPTS, 0 );
			$item->save();

			if ( $has_token ) {
				Provisioner::enqueue_poll_now( $order->get_id(), (int) $item_id, 0 );
			} else {
				Provisioner::enqueue_provision_now( $order->get_id(), (int) $item_id );
			}
			++$retried;
		}

		if ( $retried > 0 ) {
			$order->update_meta_data( Order_Esim_Store::ORDER_STATE, Order_Esim_Store::STATE_ACTIVATING );
			/* translators: %d: number of items. */
			$order->add_order_note( sprintf( __( 'nextSIM: provisioning retried for %d item(s).', 'nextsim-woo' ), $retried ) );
			$order->save();
		}
	}

	/**
	 * Admin action: send the delivery email again (all delivered eSIMs of the order).
	 */
	public function resend_delivery_email( \WC_Order $order ): void {
		$has_delivered = false;

		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product
				&& Order_Esim_Store::is_nextsim_item( $item )
				&& Order_Esim_Store::ITEM_STATUS_COMPLETED === (string) $item->get_meta( Order_Esim_Store::ITEM_STATUS )
			) {
				$has_delivered = true;
				break;
			}
		}

		if ( ! $has_delivered ) {
			$order->add_order_note( __( 'nextSIM: no delivered eSIM on this order yet — nothing to email.', 'nextsim-woo' ) );
			$order->save();

			return;
		}

		if ( function_exists( 'WC' ) ) {
			WC()->mailer(); // Make sure the email classes (and their listeners) are loaded.
		}

		do_action( 'nextsim_woo_order_esims_ready', $order->get_id() );
	}

	/**
	 * Re-queue the provisioning work of every order still marked "activating" or
	 * "failed": items waiting for their QR resume polling, items never started get
	 * a fresh provision job. Items caught mid-activation are left alone (an activation
	 * may have gone through upstream; the admin "retry" action handles those after
	 * the stale window), and items that already have a pending job are skipped.
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
				'meta_value' => array( Order_Esim_Store::STATE_ACTIVATING, Order_Esim_Store::STATE_FAILED ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		$requeued = 0;

		foreach ( (array) $order_ids as $order_id ) {
			$order = wc_get_order( (int) $order_id );
			if ( ! $order instanceof \WC_Order || $order->has_status( array( 'cancelled', 'refunded', 'failed', 'trash' ) ) ) {
				continue;
			}

			foreach ( $order->get_items() as $item_id => $item ) {
				if ( ! $item instanceof \WC_Order_Item_Product || ! Order_Esim_Store::is_nextsim_item( $item ) ) {
					continue;
				}

				$status = (string) $item->get_meta( Order_Esim_Store::ITEM_STATUS );

				if ( Order_Esim_Store::ITEM_STATUS_POLLING === $status ) {
					$poll = true;
				} elseif ( in_array( $status, array( '', Order_Esim_Store::ITEM_STATUS_PENDING ), true ) ) {
					$poll = false;
				} else {
					continue;
				}

				if ( array() !== Provisioner::pending_job_ids( (int) $order_id, (int) $item_id ) ) {
					continue; // Its job survived; do not start a second chain.
				}

				if ( $poll ) {
					Provisioner::enqueue_poll_now( (int) $order_id, (int) $item_id, (int) $item->get_meta( Order_Esim_Store::ITEM_POLL_ATTEMPTS ) );
				} else {
					$item->update_meta_data( Order_Esim_Store::ITEM_STATUS, Order_Esim_Store::ITEM_STATUS_PENDING );
					$item->save();
					Provisioner::enqueue_provision_now( (int) $order_id, (int) $item_id );
				}
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

		// Idempotency guard: only the first paid transition provisions. (Two transitions
		// racing through this read are caught by the per-item claim in the job.)
		if ( '' !== (string) $order->get_meta( Order_Esim_Store::ORDER_STATE ) ) {
			return;
		}

		$esim_items = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( $item instanceof \WC_Order_Item_Product && Order_Esim_Store::is_nextsim_item( $item ) ) {
				// PENDING from the first moment, so the thank-you page shows "being
				// provisioned" before the job runs, and the job's claim has a row to flip.
				$item->update_meta_data( Order_Esim_Store::ITEM_STATUS, Order_Esim_Store::ITEM_STATUS_PENDING );
				$item->save();
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
			Provisioner::enqueue_provision_now( (int) $order_id, $item_id );
		}

		$this->logger->info( 'Order provisioning enqueued', array( 'order' => $order_id, 'items' => count( $esim_items ) ) );
	}

	/**
	 * The shop cancelled or refunded the order: drop every queued provisioning job so
	 * nothing is activated (or delivered) for it any more.
	 *
	 * @param int $order_id
	 */
	public function on_order_void( $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order || '' === (string) $order->get_meta( Order_Esim_Store::ORDER_STATE ) ) {
			return;
		}

		$cancelled = 0;
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( $item instanceof \WC_Order_Item_Product && Order_Esim_Store::is_nextsim_item( $item ) ) {
				$cancelled += Provisioner::cancel_pending_jobs( (int) $order_id, (int) $item_id );
			}
		}

		if ( $cancelled > 0 ) {
			$order->add_order_note( __( 'nextSIM: pending eSIM provisioning jobs cancelled with the order. If an upstream order was already created, check the reseller account.', 'nextsim-woo' ) );
			$order->save();
		}
	}
}
