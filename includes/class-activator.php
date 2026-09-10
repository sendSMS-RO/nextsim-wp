<?php
/**
 * Runs on plugin activation.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo;

defined( 'ABSPATH' ) || exit;

final class Activator {

	public static function activate(): void {
		add_option( Settings::OPT_API_HOST, 'https://nextsim.eu' );
		add_option( Settings::OPT_ENVIRONMENT, Settings::ENV_LIVE );
		add_option( Settings::OPT_MARKUP_PERCENT, 0 );
		add_option( Settings::OPT_DEFAULT_PRICE_MODE, Settings::PRICE_MODE_AUTO );
		add_option( Settings::OPT_SYNC_INTERVAL, 'daily' );

		if ( class_exists( 'NextSIM\\Woo\\Account\\My_Account' ) ) {
			Account\My_Account::add_endpoints();
		}

		// Orders paid while the plugin was inactive (or whose jobs ran with no listener)
		// would otherwise stay "provisioning" forever.
		if ( class_exists( 'NextSIM\\Woo\\Fulfilment\\Order_Manager' ) ) {
			Fulfilment\Order_Manager::requeue_in_flight();
		}

		flush_rewrite_rules();
	}
}
