<?php
/**
 * Typed exception for nextSIM API failures.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Api;

defined( 'ABSPATH' ) || exit;

class Api_Exception extends \Exception {

	/**
	 * @param array<string, mixed> $context Decoded response body / extra fields.
	 */
	public function __construct(
		string $message,
		private int $http_status = 0,
		private ?string $error_code = null,
		private array $context = array()
	) {
		parent::__construct( $message );
	}

	public function get_http_status(): int {
		return $this->http_status;
	}

	public function get_error_code(): ?string {
		return $this->error_code;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function get_context(): array {
		return $this->context;
	}

	public function is_rate_limited(): bool {
		return 429 === $this->http_status;
	}

	public function is_unauthorized(): bool {
		return 401 === $this->http_status;
	}

	/**
	 * A network/timeout error or a 5xx — worth retrying via the job scheduler.
	 */
	public function is_retryable(): bool {
		return 0 === $this->http_status || 429 === $this->http_status || $this->http_status >= 500;
	}

	/**
	 * Top-up targeted an eSIM whose profile has expired; no credit was charged.
	 */
	public function is_esim_expired(): bool {
		return 409 === $this->http_status && 'esim_expired' === $this->error_code;
	}

	/**
	 * The requested package was retired upstream between catalog sync and payment;
	 * no credit was charged. A replacement package id may be offered.
	 */
	public function is_package_retired(): bool {
		return 410 === $this->http_status && 'package_retired' === $this->error_code;
	}

	public function get_buy_new_package_id(): ?int {
		$id = $this->context['buy_new_package_id'] ?? null;

		return null === $id ? null : (int) $id;
	}

	public function get_replaced_by(): ?int {
		$id = $this->context['replaced_by'] ?? null;

		return null === $id ? null : (int) $id;
	}
}
