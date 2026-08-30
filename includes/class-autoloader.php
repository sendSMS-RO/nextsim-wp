<?php
/**
 * PSR-4-ish autoloader mapping the NextSIM\Woo namespace to WordPress-style
 * `class-*.php` files under includes/.
 *
 * Example: NextSIM\Woo\Api\Api_Client -> includes/api/class-api-client.php
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo;

defined( 'ABSPATH' ) || exit;

final class Autoloader {

	private const NAMESPACE_PREFIX = 'NextSIM\\Woo\\';

	public static function register(): void {
		spl_autoload_register( array( self::class, 'autoload' ) );
	}

	public static function autoload( string $class ): void {
		if ( ! str_starts_with( $class, self::NAMESPACE_PREFIX ) ) {
			return;
		}

		$relative = substr( $class, strlen( self::NAMESPACE_PREFIX ) );
		$parts    = explode( '\\', $relative );
		$class_nm = array_pop( $parts );

		$dir = '';
		foreach ( $parts as $segment ) {
			$dir .= strtolower( str_replace( '_', '-', $segment ) ) . '/';
		}

		$file = 'class-' . strtolower( str_replace( '_', '-', $class_nm ) ) . '.php';
		$path = NEXTSIM_WOO_PATH . 'includes/' . $dir . $file;

		if ( is_readable( $path ) ) {
			require_once $path;
		}
	}
}
