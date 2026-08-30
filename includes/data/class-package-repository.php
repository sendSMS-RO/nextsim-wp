<?php
/**
 * Looks up WooCommerce products by their nextSIM package id and finds
 * stale (orphaned) products for the sync sweep.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Data;

defined( 'ABSPATH' ) || exit;

class Package_Repository {

	/**
	 * Find the WC product created for a given nextSIM package id, if any.
	 */
	public function find_by_package_id( int $package_id ): ?\WC_Product {
		$ids = wc_get_products(
			array(
				'status'     => array( 'publish', 'draft', 'private' ),
				'limit'      => 1,
				'return'     => 'ids',
				'meta_key'   => Product_Meta::PACKAGE_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value' => (string) $package_id,     // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			)
		);

		if ( empty( $ids ) ) {
			return null;
		}

		$product = wc_get_product( (int) $ids[0] );

		return $product instanceof \WC_Product ? $product : null;
	}

	/**
	 * Map many nextSIM package ids to their WC product ids in a single query, for the
	 * incremental sync where a delta can reference thousands of packages at once.
	 * Product meta lives in postmeta (HPOS only moves order data), so a direct lookup
	 * is safe and avoids one wc_get_products() call per package.
	 *
	 * @param array<int, int> $package_ids
	 * @return array<int, int> package id => product id
	 */
	public function map_package_ids_to_products( array $package_ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_map( 'intval', $package_ids ) ) );
		if ( array() === $ids ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%s' ) );
		$params       = array_merge( array( Product_Meta::PACKAGE_ID ), array_map( 'strval', $ids ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value AS pkg, post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value IN ($placeholders)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$params
			)
		);

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->pkg ] = (int) $row->post_id;
		}

		return $map;
	}

	/**
	 * Published nextSIM products not touched since the given timestamp — i.e. plans
	 * that disappeared from the API during the last full sync run.
	 *
	 * @return array<int, int> Product ids.
	 */
	public function find_orphans_synced_before( int $timestamp ): array {
		return array_map(
			'intval',
			wc_get_products(
				array(
					'status'       => 'publish',
					'limit'        => -1,
					'return'       => 'ids',
					'meta_key'     => Product_Meta::LAST_SYNCED, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_compare' => '<',
					'meta_value'   => (string) $timestamp, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'meta_type'    => 'NUMERIC',
				)
			)
		);
	}
}
