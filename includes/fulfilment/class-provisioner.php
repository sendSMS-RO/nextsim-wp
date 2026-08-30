<?php
/**
 * Action Scheduler worker that provisions eSIMs: calls /activate, then polls
 * getOrderInfo until the QR is ready, storing the result on the order line item.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Fulfilment;

use NextSIM\Woo\Api\Api_Client;
use NextSIM\Woo\Api\Api_Exception;
use NextSIM\Woo\Data\Order_Esim_Store;
use NextSIM\Woo\Import\Importer;
use NextSIM\Woo\Logger;

defined( 'ABSPATH' ) || exit;

class Provisioner {

	public const HOOK_PROVISION = 'nextsim_woo_provision_item';
	public const HOOK_POLL      = 'nextsim_woo_poll_order';

	private const MAX_ACTIVATE_RETRIES = 4;
	private const MAX_POLL_ATTEMPTS    = 20;

	public function __construct(
		private Api_Client $client,
		private Topup_Service $topup,
		private Logger $logger
	) {}

	public function register(): void {
		add_action( self::HOOK_PROVISION, array( $this, 'provision_item' ) );
		add_action( self::HOOK_POLL, array( $this, 'poll_order' ) );
	}

	/**
	 * @param array{order_id?: int, item_id?: int, try?: int} $args
	 */
	public function provision_item( $args = array() ): void {
		$order_id = (int) ( $args['order_id'] ?? 0 );
		$item_id  = (int) ( $args['item_id'] ?? 0 );
		$try      = (int) ( $args['try'] ?? 0 );

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$item = $order->get_item( $item_id );
		if ( ! $item instanceof \WC_Order_Item_Product || ! Order_Esim_Store::is_nextsim_item( $item ) ) {
			return;
		}

		// An existing token means an upstream order already exists — activating again
		// would charge the reseller twice. The polling chain / retry action own it.
		if ( '' !== (string) $item->get_meta( Order_Esim_Store::ITEM_ORDER_TOKEN ) ) {
			$this->logger->warning( 'Provision job skipped: item already has an order token', array( 'order' => $order_id, 'item' => $item_id ) );

			return;
		}

		// A fresh (try=0) job may only start an activation from a clean slate; anything
		// else is a duplicate enqueue (double-clicked retry, stray job). Backoff retries
		// (try>0) legitimately resume the in-flight state they set below.
		$item_status = (string) $item->get_meta( Order_Esim_Store::ITEM_STATUS );
		if ( 0 === $try && ! in_array( $item_status, array( '', Order_Esim_Store::ITEM_STATUS_PENDING ), true ) ) {
			return;
		}

		$item->update_meta_data( Order_Esim_Store::ITEM_STATUS, Order_Esim_Store::ITEM_STATUS_ACTIVATING );
		$item->update_meta_data( Order_Esim_Store::ITEM_ACTIVATING_AT, time() );
		$item->save();

		$package_id = (int) $item->get_meta( Order_Esim_Store::ITEM_PACKAGE_ID );
		$topup_code = (string) $item->get_meta( Order_Esim_Store::ITEM_TOPUP_CODE );
		$quantity   = (int) $item->get_meta( Order_Esim_Store::ITEM_QUANTITY );

		if ( '' !== $topup_code ) {
			$compat = $this->topup->check( $topup_code, $package_id );

			if ( ! $compat['compatible'] ) {
				// A transient error (429/5xx/network) must not permanently fail a paid
				// order — retry with backoff, mirroring the activate() 429 path.
				if ( ! empty( $compat['retryable'] ) && $try < self::MAX_ACTIVATE_RETRIES ) {
					$this->reenqueue( self::HOOK_PROVISION, $order_id, $item_id, $try + 1, 5 * ( 2 ** $try ) );

					return;
				}

				$reason = 'expired' === $compat['reason']
					? __( 'the eSIM has expired and can no longer be topped up', 'nextsim-woo' )
					: (string) $compat['reason'];
				$this->fail_item( $order, $item, sprintf(
					/* translators: %s: reason. */
					__( 'Top-up not possible: %s.', 'nextsim-woo' ),
					$reason
				) );

				return;
			}
		}

		$payload = array(
			'packageId'   => $package_id,
			'callbackUrl' => $this->callback_url( $order_id, $item_id ),
		);

		if ( '' !== $topup_code ) {
			$payload['activationCode'] = $topup_code;
		} elseif ( $quantity > 1 ) {
			$payload['quantity'] = $quantity;
		}

		try {
			$created = $this->client->activate( $payload );
		} catch ( Api_Exception $e ) {
			if ( $e->is_esim_expired() ) {
				$new = $e->get_buy_new_package_id();
				$this->fail_item( $order, $item, $new
					? sprintf(
						/* translators: %d: package id. */
						__( 'The eSIM has expired; no charge was made. Sell package #%d as a replacement.', 'nextsim-woo' ),
						$new
					)
					: __( 'The eSIM has expired; no charge was made.', 'nextsim-woo' )
				);

				return;
			}

			// Package retired upstream between catalog sync and payment; no charge was
			// made. Surface the replacement so the shop can offer it.
			if ( $e->is_package_retired() ) {
				$repl = $e->get_replaced_by();
				$this->fail_item( $order, $item, $repl
					? sprintf(
						/* translators: %d: package id. */
						__( 'This plan was retired by the provider; no charge was made. It is replaced by package #%d.', 'nextsim-woo' ),
						$repl
					)
					: __( 'This plan was retired by the provider; no charge was made.', 'nextsim-woo' )
				);

				return;
			}

			// Only a 429 is known-safe to retry: the request was rejected before any
			// processing. A network error or 5xx may have reached the backend and
			// charged the reseller (the API has no idempotency key), so those go to
			// manual review instead of a blind re-activation.
			if ( $e->is_rate_limited() && $try < self::MAX_ACTIVATE_RETRIES ) {
				$this->reenqueue( self::HOOK_PROVISION, $order_id, $item_id, $try + 1, 5 * ( 2 ** $try ) );

				return;
			}

			$message = sprintf(
				/* translators: %s: error message. */
				__( 'Activation failed: %s', 'nextsim-woo' ),
				$e->getMessage()
			);

			if ( $e->is_retryable() ) {
				$message .= ' ' . __( 'The request may have reached the provider — check the reseller account before retrying (credit may have been charged).', 'nextsim-woo' );
			}

			$this->fail_item( $order, $item, $message );

			return;
		}

		$token = (string) ( $created['order_token'] ?? '' );

		if ( '' === $token ) {
			$this->fail_item( $order, $item, __( 'Activation was accepted but no order token was returned — check the reseller account manually before retrying (credit may have been charged).', 'nextsim-woo' ) );

			return;
		}

		// Multi-eSIM: the backend silently clamps quantity to 1 when the package's
		// multi-eSIM flag was turned off upstream after the product was imported. The
		// customer paid for N eSIMs, so hold the order for review rather than deliver
		// fewer than ordered. The token is kept so the reseller order is reconcilable.
		if ( $quantity > 1 && isset( $created['plan_size'] ) && is_numeric( $created['plan_size'] ) && (int) $created['plan_size'] < $quantity ) {
			$item->update_meta_data( Order_Esim_Store::ITEM_ORDER_TOKEN, $token );
			$item->save();
			$this->fail_item( $order, $item, sprintf(
				/* translators: 1: ordered count, 2: allocated count, 3: upstream order token. */
				__( 'Ordered %1$d eSIMs but the provider allocated %2$d (upstream order %3$s). Order held for review.', 'nextsim-woo' ),
				$quantity,
				(int) $created['plan_size'],
				$token
			) );

			return;
		}

		$item->update_meta_data( Order_Esim_Store::ITEM_ORDER_TOKEN, $token );
		$item->update_meta_data( Order_Esim_Store::ITEM_STATUS, Order_Esim_Store::ITEM_STATUS_POLLING );
		$item->save();

		$this->reenqueue( self::HOOK_POLL, $order_id, $item_id, 0, 10 );
	}

	/**
	 * @param array{order_id?: int, item_id?: int, try?: int} $args
	 */
	public function poll_order( $args = array() ): void {
		$order_id = (int) ( $args['order_id'] ?? 0 );
		$item_id  = (int) ( $args['item_id'] ?? 0 );
		$attempt  = (int) ( $args['try'] ?? 0 );

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$item = $order->get_item( $item_id );
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}

		// Poll only items actively awaiting provisioning. A stray poll for an unpaid,
		// completed, or failed item (e.g. via the public webhook) must be a no-op.
		if ( Order_Esim_Store::ITEM_STATUS_POLLING !== (string) $item->get_meta( Order_Esim_Store::ITEM_STATUS ) ) {
			return;
		}

		$token = (string) $item->get_meta( Order_Esim_Store::ITEM_ORDER_TOKEN );
		if ( '' === $token ) {
			$this->fail_item( $order, $item, __( 'Missing order token while polling.', 'nextsim-woo' ) );

			return;
		}

		try {
			$info = $this->client->get_order_info( $token );
		} catch ( Api_Exception $e ) {
			if ( $e->is_retryable() && $attempt < self::MAX_POLL_ATTEMPTS ) {
				$this->reenqueue( self::HOOK_POLL, $order_id, $item_id, $attempt + 1, $this->poll_delay( $attempt ) );

				return;
			}

			$this->fail_item( $order, $item, sprintf(
				/* translators: %s: error message. */
				__( 'Could not fetch eSIM details: %s', 'nextsim-woo' ),
				$e->getMessage()
			) );

			return;
		}

		// Newer backends expose a machine-readable status on /info — fail fast on a
		// canceled/refunded upstream order instead of polling to the timeout. When the
		// field is absent (older backend), fall through to the QR-fields readiness check.
		$status_key = (string) ( $info['status_key'] ?? '' );

		if ( in_array( $status_key, array( 'canceled', 'refunded' ), true ) ) {
			$this->fail_item( $order, $item, sprintf(
				/* translators: %s: upstream order status. */
				__( 'The eSIM order was %s upstream — no eSIM will be delivered. Check the reseller account.', 'nextsim-woo' ),
				$status_key
			) );

			return;
		}

		$ready = '' !== (string) ( $info['esim_url_qr_code'] ?? '' ) || '' !== (string) ( $info['esim_activation_code'] ?? '' );

		// Multi-eSIM: the backend flips the upstream order to "completed" as soon as
		// the CREATOR's eSIM is allocated, while member eSIMs may still be pending —
		// and a member failure later cancels/refunds the whole pack upstream. Deliver
		// only when every ordered eSIM has its QR; keep polling for a partial pack,
		// so an upstream failure is caught by the canceled/refunded check above
		// instead of leaving a paid-and-"completed" Woo order with dead eSIMs.
		if ( $ready ) {
			$expected = max( 1, (int) $item->get_meta( Order_Esim_Store::ITEM_QUANTITY ) );

			if ( $expected > 1 ) {
				$members = is_array( $info['plan_members'] ?? null ) ? $info['plan_members'] : array();
				$with_qr = count( array_filter(
					$members,
					static fn ( $m ): bool => is_array( $m ) && (
						'' !== (string) ( $m['esim_url_qr_code'] ?? '' )
						|| '' !== (string) ( $m['esim_activation_code'] ?? '' )
					)
				) );

				// plan_members includes the creator, so a full pack has $expected entries.
				$ready = $with_qr >= $expected;
			}
		}

		if ( ! $ready ) {
			if ( $attempt >= self::MAX_POLL_ATTEMPTS ) {
				$this->fail_item( $order, $item, __( 'Timed out waiting for the eSIM to be provisioned.', 'nextsim-woo' ) );

				return;
			}

			$item->update_meta_data( Order_Esim_Store::ITEM_POLL_ATTEMPTS, $attempt + 1 );
			$item->save();
			$this->reenqueue( self::HOOK_POLL, $order_id, $item_id, $attempt + 1, $this->poll_delay( $attempt ) );

			return;
		}

		Order_Esim_Store::store_provisioned( $item, $info );
		$order->add_order_note( __( 'nextSIM: eSIM provisioned and ready.', 'nextsim-woo' ) );
		$order->save();

		$this->maybe_complete_order( $order );
	}

	private function maybe_complete_order( \WC_Order $order ): void {
		// Re-fetch so sibling items completed by a concurrent poll job in another
		// process are seen here, rather than the snapshot this job started with.
		$fresh = wc_get_order( $order->get_id() );
		if ( $fresh instanceof \WC_Order ) {
			$order = $fresh;
		}

		$completed_ids = array();
		$all_completed = true;
		$has_non_esim  = false;

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product || ! Order_Esim_Store::is_nextsim_item( $item ) ) {
				if ( $item instanceof \WC_Order_Item_Product ) {
					$has_non_esim = true;
				}
				continue;
			}

			$status = (string) $item->get_meta( Order_Esim_Store::ITEM_STATUS );

			if ( Order_Esim_Store::ITEM_STATUS_COMPLETED === $status ) {
				$completed_ids[] = (int) $item_id;
			} elseif ( Order_Esim_Store::ITEM_STATUS_FAILED === $status ) {
				$all_completed = false;
			} else {
				// An item is still activating/polling — not a terminal state yet.
				return;
			}
		}

		// Every item is terminal. Send the delivery email when there are completed items
		// not yet included in a previous email (e.g. a retried item that succeeded later).
		// The claim is written BEFORE sending to keep at-most-once semantics per delta.
		$emailed = json_decode( (string) $order->get_meta( Order_Esim_Store::ORDER_EMAIL_SENT ), true );
		$emailed = is_array( $emailed ) ? array_map( 'intval', $emailed ) : array();
		$unsent  = array_diff( $completed_ids, $emailed );

		if ( array() !== $unsent ) {
			$order->update_meta_data(
				Order_Esim_Store::ORDER_EMAIL_SENT,
				wp_json_encode( array_values( array_unique( array_merge( $emailed, $completed_ids ) ) ) )
			);
			$order->save();

			// Ensure the email classes are loaded so the delivery email's listener is registered.
			if ( function_exists( 'WC' ) && WC()->mailer() ) {
				do_action( 'nextsim_woo_order_esims_ready', $order->get_id() );
			}
		}

		if ( ! $all_completed ) {
			// A failed sibling already put the order on hold with a note — leave it for review.
			return;
		}

		$order->update_meta_data( Order_Esim_Store::ORDER_STATE, Order_Esim_Store::STATE_COMPLETED );
		$order->save();

		// Only auto-complete a pure-eSIM order. A mixed order still has physical/other
		// items to fulfil, so leave its status to the shop and just note the delivery.
		if ( $has_non_esim ) {
			$order->add_order_note( __( 'nextSIM: all eSIMs delivered. Order left open for the remaining (non-eSIM) items.', 'nextsim-woo' ) );
			$order->save();
		} elseif ( ! $order->has_status( 'completed' ) ) {
			$order->update_status( 'completed', __( 'All eSIMs delivered.', 'nextsim-woo' ) );
		}
	}

	private function fail_item( \WC_Order $order, \WC_Order_Item_Product $item, string $message ): void {
		$item->update_meta_data( Order_Esim_Store::ITEM_STATUS, Order_Esim_Store::ITEM_STATUS_FAILED );
		$item->update_meta_data( Order_Esim_Store::ITEM_LAST_ERROR, $message );
		$item->save();

		$order->update_meta_data( Order_Esim_Store::ORDER_STATE, Order_Esim_Store::STATE_FAILED );
		/* translators: %s: failure message. */
		$order->add_order_note( sprintf( __( 'nextSIM error: %s', 'nextsim-woo' ), $message ) );

		if ( ! $order->has_status( 'on-hold' ) ) {
			$order->update_status( 'on-hold' );
		}

		$order->save();

		$this->logger->error( 'Provisioning failed', array( 'order' => $order->get_id(), 'item' => $item->get_id(), 'message' => $message ) );

		// A failure can be the order's LAST terminal transition — without this, the
		// delivery email for already-completed sibling items would never be sent.
		$this->maybe_complete_order( $order );
	}

	private function reenqueue( string $hook, int $order_id, int $item_id, int $try, int $delay ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		as_schedule_single_action(
			time() + max( 1, $delay ),
			$hook,
			array( array( 'order_id' => $order_id, 'item_id' => $item_id, 'try' => $try ) ),
			Importer::GROUP
		);
	}

	private function poll_delay( int $attempt ): int {
		return min( 120, 15 * ( $attempt + 1 ) );
	}

	private function callback_url( int $order_id, int $item_id ): string {
		return rest_url( sprintf( 'nextsim-woo/v1/callback/order/%d/item/%d', $order_id, $item_id ) );
	}
}
