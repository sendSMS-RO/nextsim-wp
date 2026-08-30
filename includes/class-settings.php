<?php
/**
 * Central, read-only accessor for the plugin's stored options.
 *
 * This is the single source of truth for option keys; the admin settings page
 * writes them, everything else reads them through here.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo;

defined( 'ABSPATH' ) || exit;

final class Settings {

	public const OPT_API_HOST            = 'nextsim_woo_api_host';
	public const OPT_API_TOKEN           = 'nextsim_woo_api_token';
	public const OPT_ENVIRONMENT         = 'nextsim_woo_environment';
	public const OPT_MARKUP_PERCENT      = 'nextsim_woo_markup_percent';
	public const OPT_DEFAULT_PRICE_MODE  = 'nextsim_woo_default_price_mode';
	public const OPT_CATEGORY_MARKUP     = 'nextsim_woo_category_markup';
	public const OPT_IMPORT_ROUTES       = 'nextsim_woo_import_routes';
	public const OPT_IMPORT_COUNTRIES    = 'nextsim_woo_import_countries';
	public const OPT_IMPORT_REGIONS      = 'nextsim_woo_import_regions';
	public const OPT_SYNC_INTERVAL       = 'nextsim_woo_sync_interval';
	public const OPT_LOW_BALANCE_ALERT   = 'nextsim_woo_low_balance_alert';
	public const OPT_EXCHANGE_MODE       = 'nextsim_woo_exchange_mode';
	public const OPT_EXCHANGE_RATE       = 'nextsim_woo_exchange_rate';

	public const PRICE_MODE_AUTO   = 'auto';
	public const PRICE_MODE_MANUAL = 'manual';

	public const EXCHANGE_AUTO  = 'auto';
	public const EXCHANGE_FIXED = 'fixed';
	public const EXCHANGE_OFF   = 'off';

	public const ENV_LIVE = 'live';
	public const ENV_TEST = 'test';

	public function api_host(): string {
		$host = (string) get_option( self::OPT_API_HOST, 'https://nextsim.eu' );

		return untrailingslashit( trim( $host ) );
	}

	public function api_token(): string {
		return (string) get_option( self::OPT_API_TOKEN, '' );
	}

	public function environment(): string {
		return self::ENV_TEST === get_option( self::OPT_ENVIRONMENT ) ? self::ENV_TEST : self::ENV_LIVE;
	}

	public function is_configured(): bool {
		return '' !== $this->api_host() && '' !== $this->api_token();
	}

	public function markup_percent(): float {
		return (float) get_option( self::OPT_MARKUP_PERCENT, 0 );
	}

	public function exchange_mode(): string {
		$mode = (string) get_option( self::OPT_EXCHANGE_MODE, self::EXCHANGE_AUTO );

		return in_array( $mode, array( self::EXCHANGE_FIXED, self::EXCHANGE_OFF ), true ) ? $mode : self::EXCHANGE_AUTO;
	}

	/**
	 * Admin-entered value of 1 EUR in the store currency; 0 when unset.
	 */
	public function exchange_fixed_rate(): float {
		return max( 0.0, (float) get_option( self::OPT_EXCHANGE_RATE, 0 ) );
	}

	public function default_price_mode(): string {
		return self::PRICE_MODE_MANUAL === get_option( self::OPT_DEFAULT_PRICE_MODE )
			? self::PRICE_MODE_MANUAL
			: self::PRICE_MODE_AUTO;
	}

	/**
	 * Per-category markup overrides, keyed by product_cat term id.
	 *
	 * @return array<int, float>
	 */
	public function category_markup(): array {
		$raw = get_option( self::OPT_CATEGORY_MARKUP, array() );

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();
		foreach ( $raw as $term_id => $percent ) {
			$out[ (int) $term_id ] = (float) $percent;
		}

		return $out;
	}

	/**
	 * Route codes to import; empty means "all".
	 *
	 * @return array<int, string>
	 */
	public function import_routes(): array {
		return $this->list_option( self::OPT_IMPORT_ROUTES );
	}

	/**
	 * Country codes to import; empty means "all".
	 *
	 * @return array<int, string>
	 */
	public function import_countries(): array {
		return $this->list_option( self::OPT_IMPORT_COUNTRIES );
	}

	/**
	 * Region codes to import; empty means "all".
	 *
	 * @return array<int, string>
	 */
	public function import_regions(): array {
		return $this->list_option( self::OPT_IMPORT_REGIONS );
	}

	/**
	 * Read an option that may be stored as an array (multiselect) or a comma-separated
	 * string (text field), normalized to a clean list.
	 *
	 * @return array<int, string>
	 */
	private function list_option( string $key ): array {
		$raw = get_option( $key, array() );

		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$values = array_map( static fn ( $v ): string => trim( (string) $v ), $raw );

		return array_values( array_filter( $values, static fn ( string $v ): bool => '' !== $v ) );
	}

	public function sync_interval(): string {
		$interval = (string) get_option( self::OPT_SYNC_INTERVAL, 'daily' );

		return in_array( $interval, array( 'hourly', 'twicedaily', 'daily' ), true ) ? $interval : 'daily';
	}

	public function low_balance_alert(): float {
		return (float) get_option( self::OPT_LOW_BALANCE_ALERT, 0 );
	}
}
