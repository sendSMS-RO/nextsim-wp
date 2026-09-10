<?php
/**
 * Low-level HTTP client for the nextSIM reseller API.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Api;

use NextSIM\Woo\Logger;

defined( 'ABSPATH' ) || exit;

class Api_Client {

	private const TIMEOUT       = 20;
	private const SHORT_TIMEOUT = 8;

	public function __construct(
		private string $host,
		private string $token,
		private Logger $logger
	) {
		$this->host = untrailingslashit( $this->host );
	}

	/**
	 * Verify the token and return the reseller profile.
	 *
	 * @return array<string, mixed>
	 */
	public function me(): array {
		return $this->request( 'GET', '/api/v1/me' )['data'] ?? array();
	}

	/**
	 * Current reseller credit balance (EUR).
	 */
	public function get_balance(): string {
		$data = $this->request( 'GET', '/api/v1/balance/balance' )['data'] ?? array();

		return (string) ( $data['balance'] ?? '0' );
	}

	/**
	 * One page of the eSIM package list.
	 *
	 * Returns the FULL response body. The live API wraps pagination as a Laravel
	 * resource collection: `{ data: [plans], links: {...}, meta: { current_page, ... } }`.
	 *
	 * @param array<string, scalar> $query
	 * @return array<string, mixed>
	 */
	public function get_packages_page( array $query ): array {
		return $this->request( 'GET', '/api/v2/packages/esim', $query );
	}

	/**
	 * Incremental catalog delta since a timestamp (which must be <= 30 days old).
	 * Returns the body's `data`: { since, generated_at, added[], retired[], price_changed[] }.
	 *
	 * @return array<string, mixed>
	 */
	public function get_package_changes( string $since ): array {
		return $this->request( 'GET', '/api/v2/packages/esim/changes', array( 'since' => $since ) )['data'] ?? array();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_countries(): array {
		return $this->request( 'GET', '/api/v2/countries/available' )['data'] ?? array();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function get_regions(): array {
		return $this->request( 'GET', '/api/v1/regions' )['data'] ?? array();
	}

	/**
	 * Create an order or top-up. Async: returns order_token + status, not the QR.
	 *
	 * @param array<string, mixed> $payload
	 * @return array<string, mixed>
	 */
	public function activate( array $payload ): array {
		return $this->request( 'POST', '/api/v2/packages/esim/activate', array(), $payload )['data'] ?? array();
	}

	/**
	 * Fetch eSIM details (QR, install urls) for an order once provisioned.
	 *
	 * @return array<string, mixed>
	 */
	public function get_order_info( string $order_token ): array {
		$path = '/api/v2/packages/esim/order/' . rawurlencode( $order_token ) . '/info';

		return $this->request( 'GET', $path )['data'] ?? array();
	}

	/**
	 * @return array{compatible: bool, reason: ?string}
	 */
	public function check_topup_compatibility( string $activation_code, int $package_id ): array {
		$path = '/api/v2/packages/esim/' . rawurlencode( $activation_code ) . '/compatibility/' . $package_id;
		// Called from add-to-cart in the customer's request: keep the wait short.
		$data = $this->request( 'GET', $path, array(), null, self::SHORT_TIMEOUT )['data'] ?? array();

		return array(
			'compatible' => (bool) ( $data['compatible'] ?? false ),
			'reason'     => isset( $data['reason'] ) ? (string) $data['reason'] : null,
		);
	}

	/**
	 * Consumption / balance for an activation code.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_consumption( string $activation_code ): array {
		$path = '/api/v2/packages/esim/' . rawurlencode( $activation_code ) . '/balance';

		return $this->request( 'GET', $path )['data'] ?? array();
	}

	/**
	 * @param array<string, scalar> $query
	 * @param array<string, mixed>|null $body
	 * @return array<string, mixed>
	 *
	 * @throws Api_Exception On network error or non-2xx response.
	 */
	private function request( string $method, string $path, array $query = array(), ?array $body = null, int $timeout = self::TIMEOUT ): array {
		$url = $this->host . $path;

		if ( array() !== $query ) {
			$url = add_query_arg( array_map( 'rawurlencode', array_map( 'strval', $query ) ), $url );
		}

		// Activation codes and order tokens are path segments; log the route shape only.
		$log_path = preg_replace( array( '#(/esim/)[^/]+#', '#(/order/)[^/]+#' ), array( '$1{code}', '$1{token}' ), $path );

		$args = array(
			'method'  => $method,
			'timeout' => $timeout,
			'headers' => array(
				'Authorization' => 'Bearer ' . $this->token,
				'Accept'        => 'application/json',
			),
		);

		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			$this->logger->error( 'API network error', array( 'path' => $log_path, 'error' => $response->get_error_message() ) );

			throw new Api_Exception( esc_html( $response->get_error_message() ), 0 );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$parsed = json_decode( $raw, true );
		$parsed = is_array( $parsed ) ? $parsed : array();

		if ( $status < 200 || $status >= 300 ) {
			$error_code = isset( $parsed['error_code'] ) ? (string) $parsed['error_code'] : null;
			$message    = $this->extract_error_message( $parsed, $status );

			$this->logger->warning(
				'API error response',
				array( 'path' => $log_path, 'status' => $status, 'error_code' => $error_code )
			);

			// The decoded body is kept for callers that inspect error fields; it is never output as-is.
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			throw new Api_Exception( esc_html( $message ), (int) $status, null === $error_code ? null : esc_html( $error_code ), $parsed );
		}

		return $parsed;
	}

	/**
	 * @param array<string, mixed> $parsed
	 */
	private function extract_error_message( array $parsed, int $status ): string {
		// `reasons` is a field=>messages map on 422s, but a plain string on e.g. 409 esim_expired.
		if ( isset( $parsed['reasons'] ) ) {
			if ( is_string( $parsed['reasons'] ) && '' !== $parsed['reasons'] ) {
				return $parsed['reasons'];
			}

			if ( is_array( $parsed['reasons'] ) ) {
				$first = reset( $parsed['reasons'] );

				if ( is_array( $first ) && isset( $first[0] ) ) {
					return (string) $first[0];
				}

				if ( is_string( $first ) && '' !== $first ) {
					return $first;
				}
			}
		}

		if ( isset( $parsed['message'] ) ) {
			return (string) $parsed['message'];
		}

		/* translators: %d: HTTP status code. */
		return sprintf( __( 'nextSIM API returned HTTP %d.', 'nextsim-woo' ), $status );
	}
}
