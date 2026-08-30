<?php
/**
 * Thin wrapper around the WooCommerce logger, scoped to a single source.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo;

defined( 'ABSPATH' ) || exit;

final class Logger {

	private const SOURCE = 'nextsim-woo';

	private ?\WC_Logger_Interface $logger = null;

	private function logger(): ?\WC_Logger_Interface {
		if ( null === $this->logger && function_exists( 'wc_get_logger' ) ) {
			$this->logger = wc_get_logger();
		}

		return $this->logger;
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function info( string $message, array $context = array() ): void {
		$this->log( 'info', $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->log( 'warning', $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( 'error', $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->log( 'debug', $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function log( string $level, string $message, array $context ): void {
		$logger = $this->logger();

		if ( null === $logger ) {
			return;
		}

		if ( array() !== $context ) {
			$message .= ' ' . wp_json_encode( $context );
		}

		$logger->log( $level, $message, array( 'source' => self::SOURCE ) );
	}
}
