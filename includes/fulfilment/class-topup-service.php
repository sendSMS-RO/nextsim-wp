<?php
/**
 * Validates a customer-supplied top-up activation code against a package before
 * an activation is attempted.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Fulfilment;

use NextSIM\Woo\Api\Api_Client;
use NextSIM\Woo\Api\Api_Exception;

defined( 'ABSPATH' ) || exit;

class Topup_Service {

	public function __construct( private Api_Client $client ) {}

	/**
	 * `retryable` flags a transient failure (429/5xx/network) so the caller can retry
	 * instead of permanently failing a paid order on a momentary API hiccup.
	 *
	 * @return array{compatible: bool, reason: ?string, retryable: bool}
	 */
	public function check( string $activation_code, int $package_id ): array {
		try {
			$result              = $this->client->check_topup_compatibility( $activation_code, $package_id );
			$result['retryable'] = false;

			return $result;
		} catch ( Api_Exception $e ) {
			return array(
				'compatible' => false,
				'reason'     => $e->getMessage(),
				'retryable'  => $e->is_retryable(),
			);
		}
	}
}
