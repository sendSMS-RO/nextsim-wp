<?php
/**
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Tests\Unit;

use NextSIM\Woo\Import\Plan_Data;
use PHPUnit\Framework\TestCase;

final class PlanDataTest extends TestCase {

	/**
	 * @return array<string, mixed>
	 */
	private function sample(): array {
		return array(
			'id'                   => 123,
			'display_name'         => 'Turkey 5GB 30 days',
			'reseller_price'       => '8.50',
			'rrp_price'            => '12.00',
			'data_limit_gigabytes' => 5,
			'period_days'          => 30,
			'can_top_up'           => true,
			'msisdn_available'     => false,
			'allow_multi_esim'     => true,
			'extra_esim_price'     => 2,
			'max_esims_per_order'  => 5,
			'location_zone_name'   => 'Turkey',
			'route'                => 'ROM003',
			'operators'            => array( array( 'country' => 'TR', 'operator_name' => 'Turkcell' ) ),
		);
	}

	public function test_normalizes_api_payload(): void {
		$plan = Plan_Data::from_api( $this->sample() );

		$this->assertSame( 123, $plan->id );
		$this->assertSame( 'Turkey 5GB 30 days', $plan->display_name );
		$this->assertSame( 8.5, $plan->reseller_price );
		$this->assertSame( 12.0, $plan->rrp_price );
		$this->assertSame( 5.0, $plan->data_gb );
		$this->assertFalse( $plan->is_unlimited );
		$this->assertSame( 30, $plan->period_days );
		$this->assertTrue( $plan->can_top_up );
		$this->assertTrue( $plan->allow_multi_esim );
		$this->assertSame( 5, $plan->max_esims_per_order );
		$this->assertSame( 'ROM003', $plan->route );
	}

	public function test_unlimited_plan_with_daily_cap(): void {
		$raw                         = $this->sample();
		$raw['data_limit_gigabytes'] = 'Unlimited';
		// The backend encodes the daily fair-use cap as a negative figure.
		$raw['data_cap']     = -2;
		$raw['data_cap_per'] = 'day';

		$plan = Plan_Data::from_api( $raw );

		$this->assertTrue( $plan->is_unlimited );
		$this->assertSame( 0.0, $plan->data_gb );
		$this->assertSame( 2.0, $plan->data_cap_gb );
		$this->assertSame( 'day', $plan->data_cap_per );
	}

	public function test_unlimited_plan_without_cap(): void {
		$raw                         = $this->sample();
		$raw['data_limit_gigabytes'] = 'Unlimited';
		$raw['data_cap']             = 0;
		$raw['data_cap_per']         = null;

		$plan = Plan_Data::from_api( $raw );

		$this->assertTrue( $plan->is_unlimited );
		$this->assertSame( 0.0, $plan->data_cap_gb );
		$this->assertSame( '', $plan->data_cap_per );
	}

	public function test_attributes_hash_changes_when_plan_becomes_unlimited(): void {
		$a = Plan_Data::from_api( $this->sample() );

		$raw                         = $this->sample();
		$raw['data_limit_gigabytes'] = 'Unlimited';
		$raw['data_cap']             = -5;
		$raw['data_cap_per']         = 'day';
		$b                           = Plan_Data::from_api( $raw );

		$this->assertNotSame( $a->attributes_hash(), $b->attributes_hash() );
	}

	public function test_falls_back_to_deprecated_cost_field(): void {
		$raw = $this->sample();
		unset( $raw['reseller_price'] );
		$raw['cost'] = '7.25';

		$plan = Plan_Data::from_api( $raw );

		$this->assertSame( 7.25, $plan->reseller_price );
	}

	public function test_attributes_hash_ignores_price_changes(): void {
		$a = Plan_Data::from_api( $this->sample() );

		$raw                   = $this->sample();
		$raw['reseller_price'] = '99.99';
		$b                     = Plan_Data::from_api( $raw );

		$this->assertSame( $a->attributes_hash(), $b->attributes_hash() );
	}

	public function test_attributes_hash_changes_on_attribute_change(): void {
		$a = Plan_Data::from_api( $this->sample() );

		$raw                 = $this->sample();
		$raw['display_name'] = 'Turkey 10GB 30 days';
		$b                   = Plan_Data::from_api( $raw );

		$this->assertNotSame( $a->attributes_hash(), $b->attributes_hash() );
	}
}
