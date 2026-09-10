<?php
/**
 * Api_Client double returning canned page payloads keyed by page number.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Tests\Unit;

use NextSIM\Woo\Api\Api_Client;
use NextSIM\Woo\Logger;

final class Fake_Api_Client extends Api_Client {

	/** @var array<int, array<string, mixed>> */
	public array $requests = array();

	/**
	 * @param array<int, array<string, mixed>> $pages
	 */
	public function __construct( private array $pages ) {
		parent::__construct( 'https://example.test', 'token', new Logger() );
	}

	public function get_packages_page( array $query ): array {
		$this->requests[] = $query;

		return $this->pages[ (int) ( $query['page'] ?? 1 ) ] ?? array();
	}
}
