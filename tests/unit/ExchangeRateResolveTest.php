<?php
/**
 * Exchange_Rate: what counts as a usable rate, and the failed-fetch negative cache.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use NextSIM\Woo\Exchange_Rate;
use NextSIM\Woo\Logger;
use NextSIM\Woo\Settings;
use PHPUnit\Framework\TestCase;

final class ExchangeRateResolveTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<string, mixed> */
	private array $transients = array();

	private int $fetches = 0;

	private string $currency = 'RON';

	/** @var string|null Body the fake ECB returns; null = HTTP 503. */
	private ?string $ecb_body = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options    = array();
		$this->transients = array();
		$this->fetches    = 0;
		$this->currency   = 'RON';
		$this->ecb_body   = null;

		Functions\when( 'get_woocommerce_currency' )->alias( fn (): string => $this->currency );
		Functions\when( 'get_option' )->alias( fn ( string $key, $default = false ) => $this->options[ $key ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ): bool {
				$this->options[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'get_transient' )->alias( fn ( string $key ) => $this->transients[ $key ] ?? false );
		Functions\when( 'set_transient' )->alias(
			function ( string $key, $value ): bool {
				$this->transients[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'wp_remote_get' )->alias(
			function () {
				++$this->fetches;

				return null === $this->ecb_body ? array( 'code' => 503 ) : array( 'code' => 200, 'body' => $this->ecb_body );
			}
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'wp_remote_retrieve_response_code' )->alias( static fn ( $response ): int => (int) $response['code'] );
		Functions\when( 'wp_remote_retrieve_body' )->alias( static fn ( $response ): string => (string) ( $response['body'] ?? '' ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function exchange(): Exchange_Rate {
		return new Exchange_Rate( new Settings(), new Logger() );
	}

	public function test_eur_store_needs_no_rate(): void {
		$this->currency = 'EUR';

		$this->assertTrue( $this->exchange()->is_resolved() );
		$this->assertSame( 1.0, $this->exchange()->rate() );
		$this->assertSame( 0, $this->fetches );
	}

	public function test_auto_mode_uses_the_fetched_rate(): void {
		$this->ecb_body = '<Cube currency="RON" rate="5.0731"/>';

		$this->assertTrue( $this->exchange()->is_resolved() );
		$this->assertSame( 5.0731, $this->exchange()->rate() );
		$this->assertSame( 1, $this->fetches, 'the rate is cached after the first fetch' );
	}

	public function test_failed_fetch_without_fallback_is_unresolved_and_not_refetched(): void {
		$exchange = $this->exchange();

		$this->assertFalse( $exchange->is_resolved() );
		$this->assertSame( 1.0, $exchange->rate() );
		$this->assertFalse( $exchange->is_resolved() );
		$this->assertSame( 1, $this->fetches, 'a failed fetch is negative-cached' );
	}

	public function test_currency_missing_from_the_feed_is_unresolved(): void {
		$this->currency = 'AED';
		$this->ecb_body = '<Cube currency="RON" rate="5.0731"/>';

		$this->assertFalse( $this->exchange()->is_resolved() );
	}

	public function test_failed_fetch_falls_back_to_the_last_known_rate(): void {
		$this->options[ Exchange_Rate::OPT_LAST_KNOWN ] = array( 'currency' => 'RON', 'rate' => 4.97 );

		$this->assertTrue( $this->exchange()->is_resolved() );
		$this->assertSame( 4.97, $this->exchange()->rate() );
	}

	public function test_fixed_mode_without_a_rate_is_unresolved(): void {
		$this->options[ Settings::OPT_EXCHANGE_MODE ] = Settings::EXCHANGE_FIXED;

		$this->assertFalse( $this->exchange()->is_resolved() );
		$this->assertSame( 0, $this->fetches );

		$this->options[ Settings::OPT_EXCHANGE_RATE ] = '5';

		$this->assertTrue( $this->exchange()->is_resolved() );
		$this->assertSame( 5.0, $this->exchange()->rate() );
	}

	public function test_mode_off_is_an_explicit_choice(): void {
		$this->options[ Settings::OPT_EXCHANGE_MODE ] = Settings::EXCHANGE_OFF;

		$this->assertTrue( $this->exchange()->is_resolved() );
		$this->assertSame( 1.0, $this->exchange()->rate() );
		$this->assertSame( 0, $this->fetches );
	}
}
