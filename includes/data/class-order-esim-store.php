<?php
/**
 * The single owner of eSIM order/line-item meta keys. HPOS-safe: always operates
 * through WC_Order / WC_Order_Item objects, never raw post meta.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Data;

defined( 'ABSPATH' ) || exit;

final class Order_Esim_Store {

	// Order-level. ORDER_EMAIL_SENT holds a JSON list of the completed item ids that
	// were included in the last delivery email, so later-completing (retried) items
	// still get their QR emailed as a delta.
	public const ORDER_STATE      = '_nextsim_provisioning_state';
	public const ORDER_EMAIL_SENT = '_nextsim_delivery_email_sent';

	public const STATE_PENDING    = 'pending';
	public const STATE_ACTIVATING = 'activating';
	public const STATE_COMPLETED  = 'completed';
	public const STATE_FAILED     = 'failed';

	// Line-item level (customer input).
	public const ITEM_TOPUP_CODE = '_nextsim_topup_code';
	public const ITEM_QUANTITY   = '_nextsim_esim_quantity';
	public const ITEM_PACKAGE_ID = '_nextsim_package_id';

	// Line-item level (provisioning).
	public const ITEM_ORDER_TOKEN    = '_nextsim_order_token';
	public const ITEM_STATUS         = '_nextsim_activation_status';
	public const ITEM_ACTIVATION     = '_nextsim_activation_code';
	public const ITEM_LPA            = '_nextsim_lpa';
	public const ITEM_APPLE_URL      = '_nextsim_apple_url';
	public const ITEM_ANDROID_URL    = '_nextsim_android_url';
	public const ITEM_SMDP           = '_nextsim_smdp_server';
	public const ITEM_ICCID          = '_nextsim_iccid';
	public const ITEM_MSISDN         = '_nextsim_msisdn';
	public const ITEM_MEMBERS        = '_nextsim_members';
	public const ITEM_ACTIVATED_AT   = '_nextsim_activated_at';
	public const ITEM_EXPIRES_AT     = '_nextsim_expires_at';
	public const ITEM_POLL_ATTEMPTS  = '_nextsim_poll_attempts';
	public const ITEM_LAST_ERROR     = '_nextsim_last_error';
	// Unix time the item entered ACTIVATING — lets the retry action recover a job
	// that died mid-activation (stuck ACTIVATING) once it is provably stale.
	public const ITEM_ACTIVATING_AT  = '_nextsim_activating_at';
	// Number of eSIMs the API actually allocated when it clamped a Multi-eSIM order.
	public const ITEM_ALLOCATED      = '_nextsim_allocated';

	public const ITEM_STATUS_PENDING    = 'pending';
	public const ITEM_STATUS_ACTIVATING = 'activating';
	public const ITEM_STATUS_POLLING    = 'polling';
	public const ITEM_STATUS_COMPLETED  = 'completed';
	public const ITEM_STATUS_FAILED     = 'failed';

	/**
	 * Is this order line item a nextSIM plan?
	 */
	public static function is_nextsim_item( \WC_Order_Item_Product $item ): bool {
		return '' !== (string) $item->get_meta( self::ITEM_PACKAGE_ID );
	}

	/**
	 * Atomically move an item from PENDING to ACTIVATING. Two provision jobs for the
	 * same item (duplicate payment hooks, two queue runners) both read "pending" and
	 * would both call /activate — charging the reseller twice. A conditional UPDATE
	 * lets exactly one of them win.
	 *
	 * @return bool True when this caller now owns the activation.
	 */
	public static function claim_activation( \WC_Order_Item_Product $item ): bool {
		global $wpdb;

		$status = (string) $item->get_meta( self::ITEM_STATUS );

		// Items from before the PENDING marker existed have no status row yet; give
		// them one so the conditional update below has something to flip. Insert-only
		// ($unique = true): if a concurrent job already flipped the row, this is a
		// no-op instead of resetting it back to "pending".
		if ( '' === $status ) {
			add_metadata( 'order_item', $item->get_id(), self::ITEM_STATUS, self::ITEM_STATUS_PENDING, true );
		} elseif ( self::ITEM_STATUS_PENDING !== $status ) {
			return false;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery
		$updated = $wpdb->update(
			$wpdb->prefix . 'woocommerce_order_itemmeta',
			array( 'meta_value' => self::ITEM_STATUS_ACTIVATING ),
			array(
				'order_item_id' => $item->get_id(),
				'meta_key'      => self::ITEM_STATUS,
				'meta_value'    => self::ITEM_STATUS_PENDING,
			),
			array( '%s' ),
			array( '%d', '%s', '%s' )
		);
		// phpcs:enable

		if ( 1 !== $updated ) {
			return false;
		}

		// Keep the in-memory object in step with what the database now says.
		$item->update_meta_data( self::ITEM_STATUS, self::ITEM_STATUS_ACTIVATING );
		$item->update_meta_data( self::ITEM_ACTIVATING_AT, time() );
		$item->save();

		return true;
	}

	/**
	 * Store the provisioning result returned by getOrderInfo onto the line item.
	 *
	 * @param array<string, mixed> $info
	 */
	public static function store_provisioned( \WC_Order_Item_Product $item, array $info ): void {
		$item->update_meta_data( self::ITEM_ACTIVATION, (string) ( $info['esim_activation_code'] ?? '' ) );
		$item->update_meta_data( self::ITEM_LPA, (string) ( $info['esim_url_qr_code'] ?? '' ) );
		$item->update_meta_data( self::ITEM_APPLE_URL, (string) ( $info['esim_apple_install_url'] ?? '' ) );
		$item->update_meta_data( self::ITEM_ANDROID_URL, (string) ( $info['esim_android_install_url'] ?? '' ) );
		$item->update_meta_data( self::ITEM_SMDP, (string) ( $info['esim_smdp_server'] ?? '' ) );
		$item->update_meta_data( self::ITEM_ICCID, (string) ( $info['iccid'] ?? '' ) );
		$item->update_meta_data( self::ITEM_MSISDN, (string) ( $info['msisdn'] ?? '' ) );
		$item->update_meta_data( self::ITEM_ACTIVATED_AT, (string) ( $info['user_activation_date'] ?? '' ) );
		$item->update_meta_data( self::ITEM_EXPIRES_AT, (string) ( $info['user_activation_end_date'] ?? '' ) );

		$members = ( isset( $info['plan_members'] ) && is_array( $info['plan_members'] ) && array() !== $info['plan_members'] )
			? $info['plan_members']
			: array();
		$item->update_meta_data( self::ITEM_MEMBERS, wp_json_encode( $members ) );

		$item->update_meta_data( self::ITEM_STATUS, self::ITEM_STATUS_COMPLETED );
		$item->save();
	}

	/**
	 * Return the eSIMs to render for an item: the Multi-eSIM members, or a single
	 * synthetic entry built from the item's own fields.
	 *
	 * @return array<int, array<string, string>>
	 */
	public static function esims_for_item( \WC_Order_Item_Product $item ): array {
		$raw     = (string) $item->get_meta( self::ITEM_MEMBERS );
		$members = '' !== $raw ? json_decode( $raw, true ) : array();

		if ( is_array( $members ) && array() !== $members ) {
			return array_map(
				static function ( array $m ): array {
					return array(
						'activation_code' => (string) ( $m['esim_activation_code'] ?? '' ),
						'lpa'             => (string) ( $m['esim_url_qr_code'] ?? '' ),
						'apple_url'       => (string) ( $m['esim_apple_install_url'] ?? '' ),
						'android_url'     => (string) ( $m['esim_android_install_url'] ?? '' ),
						'iccid'           => (string) ( $m['iccid'] ?? '' ),
						// Only the creator's code exists in esim_orders, so only it can be
						// used for a consumption/balance lookup (member codes 404 upstream).
						'is_creator'      => ! empty( $m['is_creator'] ) ? '1' : '0',
					);
				},
				$members
			);
		}

		return array(
			array(
				'activation_code' => (string) $item->get_meta( self::ITEM_ACTIVATION ),
				'lpa'             => (string) $item->get_meta( self::ITEM_LPA ),
				'apple_url'       => (string) $item->get_meta( self::ITEM_APPLE_URL ),
				'android_url'     => (string) $item->get_meta( self::ITEM_ANDROID_URL ),
				'iccid'           => (string) $item->get_meta( self::ITEM_ICCID ),
				// A single (non-family) eSIM is the queryable one.
				'is_creator'      => '1',
			),
		);
	}
}
