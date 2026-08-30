<?php
/**
 * WooCommerce settings tab: Connection / Pricing / Sync.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Admin;

use NextSIM\Woo\Api\Api_Client;
use NextSIM\Woo\Api\Api_Exception;
use NextSIM\Woo\Import\Importer;
use NextSIM\Woo\Logger;
use NextSIM\Woo\Settings;

defined( 'ABSPATH' ) || exit;

class Settings_Page {

	private const TAB = 'nextsim';

	public function __construct(
		private Settings $settings,
		private Api_Client $client,
		private Importer $importer
	) {}

	public function register(): void {
		add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_tab' ), 50 );
		add_action( 'woocommerce_settings_' . self::TAB, array( $this, 'output' ) );
		add_action( 'woocommerce_update_options_' . self::TAB, array( $this, 'save' ) );
		add_action( 'woocommerce_sections_' . self::TAB, array( $this, 'output_sections' ) );

		add_action( 'wp_ajax_nextsim_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_nextsim_sync_now', array( $this, 'ajax_sync_now' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * @param array<string, string> $tabs
	 * @return array<string, string>
	 */
	public function add_tab( array $tabs ): array {
		$tabs[ self::TAB ] = __( 'nextSIM', 'nextsim-woo' );

		return $tabs;
	}

	public function output_sections(): void {
		global $current_section;

		$sections = array(
			''        => __( 'Connection', 'nextsim-woo' ),
			'pricing' => __( 'Pricing', 'nextsim-woo' ),
			'sync'    => __( 'Sync', 'nextsim-woo' ),
		);

		echo '<ul class="subsubsub">';
		$last = array_key_last( $sections );
		foreach ( $sections as $id => $label ) {
			$url   = admin_url( 'admin.php?page=wc-settings&tab=' . self::TAB . ( '' === $id ? '' : '&section=' . $id ) );
			$class = ( $current_section === $id ) ? 'current' : '';
			printf(
				'<li><a href="%s" class="%s">%s</a> %s</li>',
				esc_url( $url ),
				esc_attr( $class ),
				esc_html( $label ),
				$id === $last ? '' : '|'
			);
		}
		echo '</ul><br class="clear" />';
	}

	public function output(): void {
		global $current_section;

		\WC_Admin_Settings::output_fields( $this->get_settings( (string) $current_section ) );

		if ( '' === (string) $current_section ) {
			$this->render_connection_actions();
		} elseif ( 'sync' === $current_section ) {
			$this->render_sync_actions();
		}
	}

	public function save(): void {
		global $current_section;

		\WC_Admin_Settings::save_fields( $this->get_settings( (string) $current_section ) );

		if ( 'sync' === (string) $current_section ) {
			$this->warn_about_invalid_filters();
		}
	}

	/**
	 * Best-effort validation of the saved import filters against the API: the backend
	 * rejects unknown values with a 422, which would abort that sync segment, so warn
	 * the admin about typos right away. Skipped silently when the API is unreachable.
	 */
	private function warn_about_invalid_filters(): void {
		if ( ! $this->settings->is_configured() ) {
			return;
		}

		$invalid = array();

		try {
			$countries = $this->settings->import_countries();
			if ( array() !== $countries ) {
				$valid = array_map(
					static fn ( array $c ): string => strtoupper( (string) ( $c['country_code'] ?? '' ) ),
					$this->client->get_countries()
				);

				foreach ( $countries as $code ) {
					if ( ! in_array( strtoupper( $code ), $valid, true ) ) {
						$invalid[] = $code;
					}
				}
			}

			$regions = $this->settings->import_regions();
			if ( array() !== $regions ) {
				$valid = array_map(
					static fn ( array $r ): string => (string) ( $r['region_code'] ?? '' ),
					$this->client->get_regions()
				);

				foreach ( $regions as $code ) {
					if ( ! in_array( $code, $valid, true ) ) {
						$invalid[] = $code;
					}
				}
			}
		} catch ( Api_Exception $e ) {
			return;
		}

		// Route codes have no lookup endpoint; check the format only.
		foreach ( $this->settings->import_routes() as $route ) {
			if ( 1 !== preg_match( '/^[A-Z]{3}\d{3}$/', $route ) ) {
				$invalid[] = $route;
			}
		}

		if ( array() !== $invalid ) {
			\WC_Admin_Settings::add_error(
				sprintf(
					/* translators: %s: comma-separated list of filter values. */
					__( 'nextSIM: these import filter values look invalid and their sync segments will fail: %s', 'nextsim-woo' ),
					implode( ', ', array_map( 'sanitize_text_field', $invalid ) )
				)
			);
		}
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function get_settings( string $section ): array {
		return match ( $section ) {
			'pricing' => $this->pricing_settings(),
			'sync'    => $this->sync_settings(),
			default   => $this->connection_settings(),
		};
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function connection_settings(): array {
		return array(
			array(
				'title' => __( 'Connection', 'nextsim-woo' ),
				'type'  => 'title',
				'desc'  => __( 'Enter the nextSIM reseller API host and your API token. A reseller account is required.', 'nextsim-woo' ),
				'id'    => 'nextsim_woo_connection',
			),
			array(
				'title'    => __( 'API host', 'nextsim-woo' ),
				'id'       => Settings::OPT_API_HOST,
				'type'     => 'url',
				'default'  => 'https://nextsim.eu',
				'desc_tip' => __( 'e.g. https://nextsim.eu', 'nextsim-woo' ),
			),
			array(
				'title' => __( 'API token', 'nextsim-woo' ),
				'id'    => Settings::OPT_API_TOKEN,
				'type'  => 'password',
			),
			array(
				'title'   => __( 'Environment', 'nextsim-woo' ),
				'id'      => Settings::OPT_ENVIRONMENT,
				'type'    => 'select',
				'default' => Settings::ENV_LIVE,
				'options' => array(
					Settings::ENV_LIVE => __( 'Live', 'nextsim-woo' ),
					Settings::ENV_TEST => __( 'Test', 'nextsim-woo' ),
				),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'nextsim_woo_connection',
			),
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function pricing_settings(): array {
		return array(
			array(
				'title' => __( 'Pricing', 'nextsim-woo' ),
				'type'  => 'title',
				'desc'  => __( 'Retail price = reseller price × (1 + markup%). Products switched to manual keep their price on every import.', 'nextsim-woo' ),
				'id'    => 'nextsim_woo_pricing',
			),
			array(
				'title'   => __( 'Default price mode', 'nextsim-woo' ),
				'id'      => Settings::OPT_DEFAULT_PRICE_MODE,
				'type'    => 'select',
				'default' => Settings::PRICE_MODE_AUTO,
				'options' => array(
					Settings::PRICE_MODE_AUTO   => __( 'Automatic (markup)', 'nextsim-woo' ),
					Settings::PRICE_MODE_MANUAL => __( 'Manual per product', 'nextsim-woo' ),
				),
			),
			array(
				'title'             => __( 'Markup (%)', 'nextsim-woo' ),
				'id'                => Settings::OPT_MARKUP_PERCENT,
				'type'              => 'number',
				'default'          => 0,
				'custom_attributes' => array(
					'step' => '0.01',
					'min'  => '0',
				),
			),
			array(
				'title'    => __( 'EUR exchange rate', 'nextsim-woo' ),
				'id'       => Settings::OPT_EXCHANGE_MODE,
				'type'     => 'select',
				'default'  => Settings::EXCHANGE_AUTO,
				'desc_tip' => __( 'nextSIM costs are in EUR. Choose how they are converted to the store currency before the markup is applied. Ignored when the store currency is EUR.', 'nextsim-woo' ),
				'options'  => array(
					Settings::EXCHANGE_AUTO  => __( 'Automatic (ECB daily rate)', 'nextsim-woo' ),
					Settings::EXCHANGE_FIXED => __( 'Fixed rate', 'nextsim-woo' ),
					Settings::EXCHANGE_OFF   => __( 'Off (no conversion)', 'nextsim-woo' ),
				),
			),
			array(
				'title'             => __( 'Fixed rate (1 EUR =)', 'nextsim-woo' ),
				'id'                => Settings::OPT_EXCHANGE_RATE,
				'type'              => 'number',
				'desc_tip'          => __( 'Value of 1 EUR in the store currency, e.g. 5.07 for RON. Used in Fixed mode, and as a fallback if the automatic rate has never been fetched.', 'nextsim-woo' ),
				'custom_attributes' => array(
					'step' => '0.0001',
					'min'  => '0',
				),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'nextsim_woo_pricing',
			),
		);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function sync_settings(): array {
		return array(
			array(
				'title' => __( 'Sync', 'nextsim-woo' ),
				'type'  => 'title',
				'desc'  => __( 'Control the automatic plan import. Use the filters to import only the plans you want. Note: plans that disappear from the API (or fall outside narrowed filters) are marked "out of stock" on the next sync, and restored automatically if they come back.', 'nextsim-woo' ),
				'id'    => 'nextsim_woo_sync',
			),
			array(
				'title'   => __( 'Sync frequency', 'nextsim-woo' ),
				'id'      => Settings::OPT_SYNC_INTERVAL,
				'type'    => 'select',
				'default' => 'daily',
				'options' => array(
					'hourly'     => __( 'Hourly', 'nextsim-woo' ),
					'twicedaily' => __( 'Twice daily', 'nextsim-woo' ),
					'daily'      => __( 'Daily', 'nextsim-woo' ),
				),
			),
			array(
				'title'    => __( 'Routes filter', 'nextsim-woo' ),
				'id'       => Settings::OPT_IMPORT_ROUTES,
				'type'     => 'text',
				'desc_tip' => __( 'Comma-separated route codes (e.g. VDF001). Leave empty to import all.', 'nextsim-woo' ),
			),
			array(
				'title'    => __( 'Countries filter', 'nextsim-woo' ),
				'id'       => Settings::OPT_IMPORT_COUNTRIES,
				'type'     => 'text',
				'desc_tip' => __( 'Comma-separated ISO country codes (e.g. RO,ES,FR). Leave empty to import all.', 'nextsim-woo' ),
			),
			array(
				'title'    => __( 'Regions filter', 'nextsim-woo' ),
				'id'       => Settings::OPT_IMPORT_REGIONS,
				'type'     => 'text',
				'desc_tip' => __( 'Comma-separated region codes (e.g. EU_GB). Leave empty to import all.', 'nextsim-woo' ),
			),
			array(
				'title'             => __( 'Low balance alert (EUR)', 'nextsim-woo' ),
				'id'                => Settings::OPT_LOW_BALANCE_ALERT,
				'type'              => 'number',
				'default'           => 0,
				'custom_attributes' => array(
					'step' => '0.01',
					'min'  => '0',
				),
				'desc'              => __( 'Show an admin warning when the reseller balance drops below this. 0 disables.', 'nextsim-woo' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'nextsim_woo_sync',
			),
		);
	}

	private function render_connection_actions(): void {
		printf(
			'<p><button type="button" class="button" id="nextsim-test-connection">%s</button> <span id="nextsim-test-result"></span></p>',
			esc_html__( 'Test connection', 'nextsim-woo' )
		);
	}

	private function render_sync_actions(): void {
		printf(
			'<p><button type="button" class="button button-primary" id="nextsim-sync-now">%s</button> <span id="nextsim-sync-result"></span></p>',
			esc_html__( 'Sync now', 'nextsim-woo' )
		);
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( self::TAB !== ( $_GET['tab'] ?? '' ) ) {
			return;
		}

		wp_enqueue_script(
			'nextsim-woo-admin',
			NEXTSIM_WOO_URL . 'assets/js/admin.js',
			array( 'jquery' ),
			NEXTSIM_WOO_VERSION,
			true
		);

		wp_localize_script(
			'nextsim-woo-admin',
			'nextsimWoo',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'testNonce'    => wp_create_nonce( 'nextsim_test_connection' ),
				'syncNonce'    => wp_create_nonce( 'nextsim_sync_now' ),
				'testingText'  => __( 'Testing…', 'nextsim-woo' ),
				'syncingText'  => __( 'Scheduling…', 'nextsim-woo' ),
			)
		);
	}

	public function ajax_test_connection(): void {
		check_ajax_referer( 'nextsim_test_connection', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nextsim-woo' ) ), 403 );
		}

		// Test the values currently typed in the form, not the saved options — the
		// admin expects the button to check what they see on screen, saved or not.
		// Fall back to the saved client when the fields are not on the page.
		$client = $this->client;

		if ( isset( $_POST['host'] ) || isset( $_POST['token'] ) ) {
			$host  = esc_url_raw( wp_unslash( (string) ( $_POST['host'] ?? '' ) ) );
			$token = sanitize_text_field( wp_unslash( (string) ( $_POST['token'] ?? '' ) ) );

			if ( '' === $host || '' === $token ) {
				wp_send_json_error(
					array( 'message' => __( 'Enter the API host and token first.', 'nextsim-woo' ) )
				);
			}

			$client = new Api_Client( $host, $token, new Logger() );
		}

		try {
			$me = $client->me();
		} catch ( Api_Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}

		wp_send_json_success(
			array(
				/* translators: %s: reseller email. */
				'message' => sprintf( __( 'Connected as %s', 'nextsim-woo' ), (string) ( $me['email'] ?? '' ) ),
			)
		);
	}

	public function ajax_sync_now(): void {
		check_ajax_referer( 'nextsim_sync_now', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nextsim-woo' ) ), 403 );
		}

		$this->importer->trigger_now();

		wp_send_json_success( array( 'message' => __( 'Import scheduled. It will run in the background.', 'nextsim-woo' ) ) );
	}
}
