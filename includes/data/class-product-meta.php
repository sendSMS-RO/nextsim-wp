<?php
/**
 * Central registry of the product meta keys the plugin writes.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Data;

defined( 'ABSPATH' ) || exit;

final class Product_Meta {

	public const PACKAGE_ID        = '_nextsim_package_id';
	public const RESELLER_PRICE    = '_nextsim_reseller_price';
	public const RRP_PRICE         = '_nextsim_rrp_price';
	public const PRICE_MODE        = '_nextsim_price_mode';
	public const LAST_SYNCED       = '_nextsim_last_synced';
	public const META_HASH         = '_nextsim_meta_hash';

	// Attribute mirrors used by checkout / fulfilment logic.
	public const DATA_GB           = '_nextsim_data_gb';
	public const IS_UNLIMITED      = '_nextsim_is_unlimited';
	public const DATA_CAP_GB       = '_nextsim_data_cap_gb';
	public const DATA_CAP_PER      = '_nextsim_data_cap_per';
	public const PERIOD_DAYS       = '_nextsim_period_days';
	public const CAN_TOP_UP        = '_nextsim_can_top_up';
	public const MSISDN_AVAILABLE  = '_nextsim_msisdn_available';
	public const ALLOW_MULTI_ESIM  = '_nextsim_allow_multi_esim';
	public const EXTRA_ESIM_PRICE  = '_nextsim_extra_esim_price';
	public const MAX_ESIMS         = '_nextsim_max_esims_per_order';
	public const ROUTE             = '_nextsim_route';
	public const PROVIDER          = '_nextsim_provider';
	public const LOCATION_ZONE     = '_nextsim_location_zone';
	// JSON list of upstream country codes the plan covers (from its operators).
	public const COVERAGE          = '_nextsim_coverage';
}
