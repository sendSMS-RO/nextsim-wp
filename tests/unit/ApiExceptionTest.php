<?php
/**
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Tests\Unit;

use NextSIM\Woo\Api\Api_Exception;
use PHPUnit\Framework\TestCase;

final class ApiExceptionTest extends TestCase {

	public function test_detects_rate_limit(): void {
		$e = new Api_Exception( 'slow down', 429 );

		$this->assertTrue( $e->is_rate_limited() );
		$this->assertTrue( $e->is_retryable() );
	}

	public function test_network_error_is_retryable(): void {
		$e = new Api_Exception( 'timeout', 0 );

		$this->assertTrue( $e->is_retryable() );
	}

	public function test_server_error_is_retryable(): void {
		$this->assertTrue( ( new Api_Exception( 'boom', 500 ) )->is_retryable() );
		$this->assertTrue( ( new Api_Exception( 'gateway', 503 ) )->is_retryable() );
	}

	public function test_client_error_is_not_retryable(): void {
		$this->assertFalse( ( new Api_Exception( 'bad request', 422 ) )->is_retryable() );
		$this->assertFalse( ( new Api_Exception( 'not found', 404 ) )->is_retryable() );
	}

	public function test_detects_expired_esim_with_replacement_package(): void {
		$e = new Api_Exception(
			'expired',
			409,
			'esim_expired',
			array( 'buy_new_package_id' => 456 )
		);

		$this->assertTrue( $e->is_esim_expired() );
		$this->assertSame( 456, $e->get_buy_new_package_id() );
	}

	public function test_409_without_error_code_is_not_expired(): void {
		$e = new Api_Exception( 'conflict', 409 );

		$this->assertFalse( $e->is_esim_expired() );
		$this->assertNull( $e->get_buy_new_package_id() );
	}

	public function test_detects_retired_package_with_replacement(): void {
		$e = new Api_Exception(
			'retired',
			410,
			'package_retired',
			array( 'replaced_by' => 789 )
		);

		$this->assertTrue( $e->is_package_retired() );
		$this->assertSame( 789, $e->get_replaced_by() );
		$this->assertFalse( $e->is_retryable() );
	}

	public function test_410_without_error_code_is_not_retired(): void {
		$e = new Api_Exception( 'gone', 410 );

		$this->assertFalse( $e->is_package_retired() );
		$this->assertNull( $e->get_replaced_by() );
	}
}
