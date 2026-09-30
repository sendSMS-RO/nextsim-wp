<?php
/**
 * Dashboard widget showing the reseller credit balance, plus a low-balance admin notice.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Admin;

use NextSIM\Woo\Api\Api_Exception;
use NextSIM\Woo\Api\Api_Client;
use NextSIM\Woo\Exchange_Rate;
use NextSIM\Woo\Import\Importer;
use NextSIM\Woo\Logger;
use NextSIM\Woo\Settings;

defined( 'ABSPATH' ) || exit;

class Balance_Widget {

	private const TRANSIENT      = 'nextsim_woo_balance';
	private const TTL            = 10 * MINUTE_IN_SECONDS;
	private const FAIL_TRANSIENT = 'nextsim_woo_balance_fail';
	private const FAIL_TTL       = 2 * MINUTE_IN_SECONDS;

	public function __construct(
		private Settings $settings,
		private Api_Client $client,
		private Logger $logger,
		private ?Exchange_Rate $exchange = null,
		private ?Importer $importer = null
	) {}

	public function register(): void {
		add_action( 'wp_dashboard_setup', array( $this, 'add_widget' ) );
		add_action( 'admin_notices', array( $this, 'maybe_low_balance_notice' ) );
		add_action( 'admin_notices', array( $this, 'maybe_health_notices' ) );
	}

	/**
	 * Configuration/health warnings, shown on the dashboard and WooCommerce settings
	 * screens only (to avoid nagging on every admin page).
	 */
	public function maybe_health_notices(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! $this->is_notice_screen() ) {
			return;
		}

		$last_error = (string) get_option( Importer::OPT_LAST_ERROR, '' );
		if ( '' !== $last_error ) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p><strong>nextSIM:</strong> %s %s</p></div>',
				esc_html__( 'The last plan sync did not complete successfully:', 'nextsim-woo' ),
				esc_html( $last_error )
			);
		}

		// The Sync section shows the full progress block itself.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$on_nextsim_tab = isset( $_GET['tab'] ) && 'nextsim' === sanitize_key( wp_unslash( $_GET['tab'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( null !== $this->importer && ! $on_nextsim_tab ) {
			$progress = $this->importer->progress();

			if ( 'running' === $progress['status'] ) {
				printf(
					'<div class="notice notice-info is-dismissible"><p><strong>nextSIM:</strong> %s %s <a href="%s">%s</a></p></div>',
					esc_html__( 'Plan sync in progress:', 'nextsim-woo' ),
					esc_html( (string) $progress['summary'] ),
					esc_url( admin_url( 'admin.php?page=wc-settings&tab=nextsim&section=sync' ) ),
					esc_html__( 'View progress', 'nextsim-woo' )
				);
			}
		}

		if ( $this->settings->is_configured()
			&& function_exists( 'get_woocommerce_currency' )
			&& 'EUR' !== get_woocommerce_currency()
			&& ( null === $this->exchange || ! $this->exchange->is_converting() )
		) {
			printf(
				'<div class="notice notice-warning is-dismissible"><p><strong>nextSIM:</strong> %s</p></div>',
				sprintf(
					/* translators: %s: store currency code. */
					esc_html__( 'nextSIM reseller prices are in EUR, but this store uses %s and no EUR conversion is active. Pick an exchange rate mode under WooCommerce > Settings > nextSIM > Pricing (or enter a fixed rate). With conversion turned off, EUR figures are written as store-currency prices unconverted; without a usable rate, the sync does not run.', 'nextsim-woo' ),
					esc_html( get_woocommerce_currency() )
				)
			);
		}

		if ( ! class_exists( \BaconQrCode\Writer::class ) ) {
			printf(
				'<div class="notice notice-info is-dismissible"><p><strong>nextSIM:</strong> %s</p></div>',
				esc_html__( 'The bundled QR code library could not be loaded — reinstall the plugin. eSIM delivery still works via activation codes and install links, but no QR images will be shown.', 'nextsim-woo' )
			);
		}
	}

	public function add_widget(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		wp_add_dashboard_widget( 'nextsim_woo_balance', __( 'nextSIM balance', 'nextsim-woo' ), array( $this, 'render_widget' ) );
	}

	public function render_widget(): void {
		$balance = $this->get_balance();

		if ( null === $balance ) {
			echo '<p>' . esc_html__( 'Balance unavailable — check your connection settings.', 'nextsim-woo' ) . '</p>';

			return;
		}

		printf(
			'<p style="font-size:1.4em;font-weight:600;">%s EUR</p>',
			esc_html( $balance )
		);
	}

	public function maybe_low_balance_notice(): void {
		$threshold = $this->settings->low_balance_alert();

		if ( $threshold <= 0 || ! current_user_can( 'manage_woocommerce' ) || ! $this->is_notice_screen() ) {
			return;
		}

		$balance = $this->get_balance();

		if ( null === $balance || (float) $balance >= $threshold ) {
			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
			sprintf(
				/* translators: %s: current balance. */
				esc_html__( 'nextSIM reseller balance is low (%s EUR). Top up your reseller account to keep fulfilling orders.', 'nextsim-woo' ),
				esc_html( $balance )
			)
		);
	}

	/**
	 * Notices are limited to the dashboard and the WooCommerce settings screens.
	 */
	private function is_notice_screen(): bool {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}

		$screen = get_current_screen();

		return null !== $screen && in_array( $screen->id, array( 'dashboard', 'woocommerce_page_wc-settings' ), true );
	}

	private function get_balance(): ?string {
		if ( ! $this->settings->is_configured() ) {
			return null;
		}

		$cached = get_transient( self::TRANSIENT );

		if ( false !== $cached ) {
			return (string) $cached;
		}

		// Negative cache: after a failure, stop re-issuing the 20s-timeout HTTP call on
		// every admin page load for a short window, so an API outage cannot make the
		// whole of wp-admin crawl.
		if ( false !== get_transient( self::FAIL_TRANSIENT ) ) {
			return null;
		}

		try {
			$balance = $this->client->get_balance();
		} catch ( Api_Exception $e ) {
			$this->logger->warning( 'Balance fetch failed', array( 'error' => $e->getMessage() ) );
			set_transient( self::FAIL_TRANSIENT, 1, self::FAIL_TTL );

			return null;
		}

		set_transient( self::TRANSIENT, $balance, self::TTL );

		return $balance;
	}
}
