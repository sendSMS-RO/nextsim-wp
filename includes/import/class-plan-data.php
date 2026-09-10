<?php
/**
 * Normalized representation of a nextSIM plan from the API list payload.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Import;

defined( 'ABSPATH' ) || exit;

final class Plan_Data {

	/**
	 * @param array<int, array<string, mixed>> $operators
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $display_name,
		public readonly float $reseller_price,
		public readonly float $rrp_price,
		public readonly float $data_gb,
		public readonly bool $is_unlimited,
		public readonly float $data_cap_gb,
		public readonly string $data_cap_per,
		public readonly int $period_days,
		public readonly bool $can_top_up,
		public readonly bool $msisdn_available,
		public readonly bool $allow_multi_esim,
		public readonly float $extra_esim_price,
		public readonly int $max_esims_per_order,
		public readonly string $location_zone_name,
		public readonly string $route,
		public readonly array $operators
	) {}

	/**
	 * @param array<string, mixed> $raw
	 */
	public static function from_api( array $raw ): self {
		// `data_limit_gigabytes` is the string "Unlimited" for unlimited plans; the
		// fair-use cap then lives in `data_cap` (+ `data_cap_per`, e.g. "day"). The
		// backend currently sends the cap as a negative figure, hence abs().
		$data_gb      = $raw['data_limit_gigabytes'] ?? 0;
		$is_unlimited = ! is_numeric( $data_gb );
		$data_gb      = $is_unlimited ? 0.0 : (float) $data_gb;

		return new self(
			(int) ( $raw['id'] ?? 0 ),
			(string) ( $raw['display_name'] ?? '' ),
			(float) ( $raw['reseller_price'] ?? $raw['cost'] ?? 0 ),
			(float) ( $raw['rrp_price'] ?? 0 ),
			$data_gb,
			$is_unlimited,
			abs( (float) ( $raw['data_cap'] ?? 0 ) ),
			(string) ( $raw['data_cap_per'] ?? '' ),
			(int) ( $raw['period_days'] ?? 0 ),
			(bool) ( $raw['can_top_up'] ?? false ),
			(bool) ( $raw['msisdn_available'] ?? false ),
			(bool) ( $raw['allow_multi_esim'] ?? false ),
			(float) ( $raw['extra_esim_price'] ?? 0 ),
			(int) ( $raw['max_esims_per_order'] ?? 1 ),
			(string) ( $raw['location_zone_name'] ?? '' ),
			(string) ( $raw['route'] ?? '' ),
			is_array( $raw['operators'] ?? null ) ? $raw['operators'] : array()
		);
	}

	/**
	 * Unique upstream country codes this plan covers, derived from its operators.
	 *
	 * @return array<int, string>
	 */
	public function countries(): array {
		$codes = array();

		foreach ( $this->operators as $operator ) {
			$code = strtoupper( trim( (string) ( $operator['country'] ?? '' ) ) );
			if ( '' !== $code ) {
				$codes[ $code ] = true;
			}
		}

		return array_keys( $codes );
	}

	/**
	 * A stable hash of the non-price attributes, to skip no-op product saves.
	 */
	public function attributes_hash(): string {
		return md5(
			wp_json_encode(
				array(
					$this->display_name,
					$this->data_gb,
					$this->is_unlimited,
					$this->data_cap_gb,
					$this->data_cap_per,
					$this->period_days,
					$this->can_top_up,
					$this->msisdn_available,
					$this->allow_multi_esim,
					$this->extra_esim_price,
					$this->max_esims_per_order,
					$this->location_zone_name,
					$this->route,
					$this->countries(),
				)
			) ?: ''
		);
	}
}
