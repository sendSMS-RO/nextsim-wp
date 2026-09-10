<?php
/**
 * Plugin Name:       nextSIM for WooCommerce
 * Plugin URI:        https://nextsim.eu
 * Description:       Sell nextSIM eSIM plans on your WooCommerce store: import plans, automatic QR delivery, top-up and consumption checks.
 * Version:           0.1.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            nextSIM
 * Author URI:        https://nextsim.eu
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       nextsim-woo
 * Domain Path:       /languages
 * WC requires at least: 8.0
 * WC tested up to:   11.1
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo;

defined( 'ABSPATH' ) || exit;

define( 'NEXTSIM_WOO_VERSION', '0.1.0' );
define( 'NEXTSIM_WOO_FILE', __FILE__ );
define( 'NEXTSIM_WOO_PATH', plugin_dir_path( __FILE__ ) );
define( 'NEXTSIM_WOO_URL', plugin_dir_url( __FILE__ ) );
define( 'NEXTSIM_WOO_BASENAME', plugin_basename( __FILE__ ) );

require_once NEXTSIM_WOO_PATH . 'includes/class-autoloader.php';
Autoloader::register();

// Composer autoloader for third-party libraries (e.g. the QR code renderer).
if ( is_readable( NEXTSIM_WOO_PATH . 'vendor/autoload.php' ) ) {
	require_once NEXTSIM_WOO_PATH . 'vendor/autoload.php';
}

/**
 * Declare compatibility with WooCommerce HPOS and Cart/Checkout blocks.
 */
add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( ! class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', NEXTSIM_WOO_FILE, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', NEXTSIM_WOO_FILE, true );
	}
);

register_activation_hook( __FILE__, array( Activator::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Deactivator::class, 'deactivate' ) );

/**
 * Boot the plugin once all plugins are loaded, guarded on WooCommerce being active.
 */
add_action(
	'plugins_loaded',
	static function (): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function (): void {
					echo '<div class="notice notice-error"><p>';
					esc_html_e( 'nextSIM for WooCommerce requires WooCommerce to be installed and active.', 'nextsim-woo' );
					echo '</p></div>';
				}
			);

			return;
		}

		Plugin::instance()->run();
	}
);
