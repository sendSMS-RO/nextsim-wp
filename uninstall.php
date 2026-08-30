<?php
/**
 * Uninstall cleanup: remove plugin options and scheduled actions.
 *
 * Products and order data are intentionally preserved.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$nextsim_woo_options = array(
	'nextsim_woo_api_host',
	'nextsim_woo_api_token',
	'nextsim_woo_environment',
	'nextsim_woo_markup_percent',
	'nextsim_woo_default_price_mode',
	'nextsim_woo_category_markup',
	'nextsim_woo_import_routes',
	'nextsim_woo_import_countries',
	'nextsim_woo_import_regions',
	'nextsim_woo_sync_interval',
	'nextsim_woo_low_balance_alert',
	'nextsim_woo_sync_run_state',
	'nextsim_woo_sync_interval_applied',
	'nextsim_woo_sync_cursor',
	'nextsim_woo_last_full_sync',
	'nextsim_woo_last_sync_error',
	'nextsim_woo_exchange_mode',
	'nextsim_woo_exchange_rate',
	'nextsim_woo_eur_rate_last',
);

foreach ( $nextsim_woo_options as $nextsim_woo_option ) {
	delete_option( $nextsim_woo_option );
}

delete_transient( 'nextsim_woo_balance' );
delete_transient( 'nextsim_woo_balance_fail' );
delete_transient( 'nextsim_woo_eur_rate' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
	as_unschedule_all_actions( '', array(), 'nextsim-woo' );
}
