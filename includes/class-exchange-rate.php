<?php
/**
 * EUR → store-currency exchange rate used by automatic pricing.
 *
 * Modes: automatic (ECB daily reference rate, cached 12h, last good rate kept as
 * a permanent fallback), fixed (admin-entered rate), or off (no conversion, the
 * pre-existing behaviour). Always 1.0 when the store currency is EUR.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo;

defined( 'ABSPATH' ) || exit;

class Exchange_Rate {

	public const ECB_URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

	public const OPT_LAST_KNOWN = 'nextsim_woo_eur_rate_last';

	private const TRANSIENT = 'nextsim_woo_eur_rate';
	private const TTL       = 12 * HOUR_IN_SECONDS;

	public function __construct(
		private Settings $settings,
		private Logger $logger
	) {}

	/**
	 * The multiplier applied to EUR reseller costs before markup.
	 *
	 * Returns 1.0 whenever no conversion applies: EUR store, mode "off", or no
	 * usable rate could be resolved (which the admin is warned about separately).
	 */
	public function rate(): float {
		$currency = $this->store_currency();

		if ( 'EUR' === $currency ) {
			return 1.0;
		}

		$mode = $this->settings->exchange_mode();

		if ( Settings::EXCHANGE_OFF === $mode ) {
			return 1.0;
		}

		if ( Settings::EXCHANGE_FIXED === $mode ) {
			$fixed = $this->settings->exchange_fixed_rate();

			return $fixed > 0 ? $fixed : 1.0;
		}

		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) && $currency === ( $cached['currency'] ?? '' ) && (float) ( $cached['rate'] ?? 0 ) > 0 ) {
			return (float) $cached['rate'];
		}

		$fetched = $this->fetch_ecb_rate( $currency );
		if ( null !== $fetched ) {
			set_transient( self::TRANSIENT, array( 'currency' => $currency, 'rate' => $fetched ), self::TTL );
			update_option( self::OPT_LAST_KNOWN, array( 'currency' => $currency, 'rate' => $fetched ), false );

			return $fetched;
		}

		// Fetch failed: last good rate, then the fixed-rate field, then no conversion.
		$last = get_option( self::OPT_LAST_KNOWN );
		if ( is_array( $last ) && $currency === ( $last['currency'] ?? '' ) && (float) ( $last['rate'] ?? 0 ) > 0 ) {
			return (float) $last['rate'];
		}

		$fixed = $this->settings->exchange_fixed_rate();

		return $fixed > 0 ? $fixed : 1.0;
	}

	/**
	 * Whether EUR costs are actually being converted for this store. False for a
	 * non-EUR store means prices are written unconverted and the admin should act.
	 */
	public function is_converting(): bool {
		return 'EUR' === $this->store_currency() || 1.0 !== $this->rate();
	}

	private function store_currency(): string {
		return function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'EUR';
	}

	private function fetch_ecb_rate( string $currency ): ?float {
		$response = wp_remote_get( self::ECB_URL, array( 'timeout' => 10 ) );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			$this->logger->warning(
				'ECB exchange rate fetch failed',
				array( 'error' => is_wp_error( $response ) ? $response->get_error_message() : wp_remote_retrieve_response_code( $response ) )
			);

			return null;
		}

		$rate = self::parse_ecb_rate( (string) wp_remote_retrieve_body( $response ), $currency );

		if ( null === $rate ) {
			$this->logger->warning( 'Store currency not present in the ECB reference feed', array( 'currency' => $currency ) );
		}

		return $rate;
	}

	/**
	 * Extract one currency's rate from the ECB daily reference XML. Public and
	 * static so the parsing is unit-testable without WordPress.
	 */
	public static function parse_ecb_rate( string $xml, string $currency ): ?float {
		$pattern = '/currency=[\'"]' . preg_quote( strtoupper( $currency ), '/' ) . '[\'"]\s+rate=[\'"]([0-9.]+)[\'"]/';

		if ( 1 !== preg_match( $pattern, $xml, $matches ) ) {
			return null;
		}

		$rate = (float) $matches[1];

		return $rate > 0 ? $rate : null;
	}
}
