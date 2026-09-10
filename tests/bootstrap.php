<?php
/**
 * PHPUnit bootstrap for unit tests.
 *
 * Defines the ABSPATH guard used by plugin files and a minimal set of WordPress
 * function shims so pure-logic classes can be exercised without loading WordPress.
 * Tests that need richer WordPress behaviour use Brain\Monkey.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $value ) {
		return rtrim( $value, '/\\' );
	}
}

if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}

// Constants the plugin expects.
if ( ! defined( 'NEXTSIM_WOO_PATH' ) ) {
	define( 'NEXTSIM_WOO_PATH', dirname( __DIR__ ) . '/' );
}

// WordPress time constants used in class constant expressions.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

require_once dirname( __DIR__ ) . '/includes/import/class-pricing-engine.php';
require_once dirname( __DIR__ ) . '/includes/class-settings.php';
require_once dirname( __DIR__ ) . '/includes/class-exchange-rate.php';
require_once dirname( __DIR__ ) . '/includes/import/class-plan-data.php';
require_once dirname( __DIR__ ) . '/includes/api/class-api-exception.php';
require_once dirname( __DIR__ ) . '/includes/class-logger.php';
require_once dirname( __DIR__ ) . '/includes/api/class-api-client.php';
require_once dirname( __DIR__ ) . '/includes/api/class-package-iterator.php';
require_once dirname( __DIR__ ) . '/includes/data/class-package-repository.php';
require_once dirname( __DIR__ ) . '/includes/import/class-product-mapper.php';
require_once dirname( __DIR__ ) . '/includes/import/class-importer.php';

// Shared test doubles (PHPUnit loads test files alphabetically, so they cannot
// live inside another test file).
require_once __DIR__ . '/unit/Fake_Api_Client.php';
