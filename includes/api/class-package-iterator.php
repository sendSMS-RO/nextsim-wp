<?php
/**
 * Traverses the paginated /v2/packages/esim endpoint.
 *
 * Used two ways:
 *  - fetch_page() for Action-Scheduler-batched imports (one page per job).
 *  - all() for small in-memory traversals and tests.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Api;

defined( 'ABSPATH' ) || exit;

class Package_Iterator {

	private const DEFAULT_PER_PAGE = 100;

	/**
	 * @param array<string, scalar> $base_query Filters (country, regionCode, route, perPage, ...).
	 */
	public function __construct(
		private Api_Client $client,
		private array $base_query = array()
	) {
		if ( ! isset( $this->base_query['perPage'] ) ) {
			$this->base_query['perPage'] = self::DEFAULT_PER_PAGE;
		}
	}

	/**
	 * Fetch a single page.
	 *
	 * Handles both pagination shapes the backend may produce:
	 *  - Resource collection (live v2): `{ data: [plans], meta: { current_page, last_page, total } }`
	 *  - Raw paginator nested under data: `{ data: { data: [plans], current_page, last_page, total } }`
	 *
	 * @return array{items: array<int, array<string, mixed>>, current_page: int, last_page: int, total: int}
	 */
	public function fetch_page( int $page ): array {
		$query         = $this->base_query;
		$query['page'] = $page;

		$payload = $this->client->get_packages_page( $query );
		$data    = isset( $payload['data'] ) && is_array( $payload['data'] ) ? $payload['data'] : array();

		if ( isset( $payload['meta'] ) && is_array( $payload['meta'] ) ) {
			$meta = $payload['meta'];

			return array(
				'items'        => array_is_list( $data ) ? $data : array(),
				'current_page' => (int) ( $meta['current_page'] ?? $page ),
				'last_page'    => (int) ( $meta['last_page'] ?? $page ),
				'total'        => (int) ( $meta['total'] ?? 0 ),
			);
		}

		if ( isset( $data['data'] ) && is_array( $data['data'] ) ) {
			return array(
				'items'        => $data['data'],
				'current_page' => (int) ( $data['current_page'] ?? $page ),
				'last_page'    => (int) ( $data['last_page'] ?? $page ),
				'total'        => (int) ( $data['total'] ?? 0 ),
			);
		}

		return array(
			'items'        => array_is_list( $data ) ? $data : array(),
			'current_page' => $page,
			'last_page'    => $page,
			'total'        => count( $data ),
		);
	}

	/**
	 * Yield every plan across all pages. Suitable for small catalogs / tests only.
	 *
	 * @return \Generator<int, array<string, mixed>>
	 */
	public function all(): \Generator {
		$page = 1;

		do {
			$result = $this->fetch_page( $page );

			foreach ( $result['items'] as $item ) {
				yield $item;
			}

			$page = $result['current_page'] + 1;
		} while ( $result['current_page'] < $result['last_page'] );
	}
}
