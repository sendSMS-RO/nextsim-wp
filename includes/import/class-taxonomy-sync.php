<?php
/**
 * Ensures product_cat terms exist for a plan's location zone (country / region),
 * caching term-id lookups for the duration of a request.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Import;

defined( 'ABSPATH' ) || exit;

class Taxonomy_Sync {

	private const TAXONOMY = 'product_cat';

	/** @var array<string, int> */
	private array $cache = array();

	/**
	 * Get (creating if needed) the product category term id for a location zone name.
	 */
	public function term_id_for_zone( string $zone_name ): ?int {
		$zone_name = trim( $zone_name );

		if ( '' === $zone_name ) {
			return null;
		}

		if ( isset( $this->cache[ $zone_name ] ) ) {
			return $this->cache[ $zone_name ];
		}

		$existing = get_term_by( 'name', $zone_name, self::TAXONOMY );

		if ( $existing instanceof \WP_Term ) {
			return $this->cache[ $zone_name ] = (int) $existing->term_id;
		}

		$created = wp_insert_term( $zone_name, self::TAXONOMY );

		if ( is_wp_error( $created ) ) {
			return null;
		}

		return $this->cache[ $zone_name ] = (int) $created['term_id'];
	}
}
