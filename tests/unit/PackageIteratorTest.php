<?php
/**
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Tests\Unit;

use NextSIM\Woo\Api\Api_Exception;
use NextSIM\Woo\Api\Package_Iterator;
use PHPUnit\Framework\TestCase;

final class PackageIteratorTest extends TestCase {

	public function test_parses_resource_collection_shape_with_top_level_meta(): void {
		$client = new Fake_Api_Client(
			array(
				1 => array(
					'data' => array( array( 'id' => 1 ), array( 'id' => 2 ) ),
					'links' => array( 'next' => 'https://example.test/api?page=2' ),
					'meta' => array( 'current_page' => 1, 'last_page' => 3, 'total' => 25 ),
				),
			)
		);

		$page = ( new Package_Iterator( $client ) )->fetch_page( 1 );

		$this->assertCount( 2, $page['items'] );
		$this->assertSame( 1, $page['current_page'] );
		$this->assertSame( 3, $page['last_page'] );
		$this->assertSame( 25, $page['total'] );
	}

	public function test_parses_nested_paginator_shape(): void {
		$client = new Fake_Api_Client(
			array(
				1 => array(
					'data' => array(
						'current_page' => 1,
						'last_page'    => 2,
						'total'        => 12,
						'data'         => array( array( 'id' => 7 ) ),
					),
				),
			)
		);

		$page = ( new Package_Iterator( $client ) )->fetch_page( 1 );

		$this->assertCount( 1, $page['items'] );
		$this->assertSame( 2, $page['last_page'] );
		$this->assertSame( 12, $page['total'] );
	}

	public function test_all_traverses_every_page(): void {
		$client = new Fake_Api_Client(
			array(
				1 => array(
					'data' => array( array( 'id' => 1 ) ),
					'meta' => array( 'current_page' => 1, 'last_page' => 2, 'total' => 2 ),
				),
				2 => array(
					'data' => array( array( 'id' => 2 ) ),
					'meta' => array( 'current_page' => 2, 'last_page' => 2, 'total' => 2 ),
				),
			)
		);

		$ids = array();
		foreach ( ( new Package_Iterator( $client ) )->all() as $plan ) {
			$ids[] = $plan['id'];
		}

		$this->assertSame( array( 1, 2 ), $ids );
	}

	public function test_empty_catalogue_yields_no_items(): void {
		$client = new Fake_Api_Client(
			array(
				1 => array(
					'data' => array(),
					'meta' => array( 'current_page' => 1, 'last_page' => 1, 'total' => 0 ),
				),
			)
		);

		$page = ( new Package_Iterator( $client ) )->fetch_page( 1 );

		$this->assertSame( array(), $page['items'] );
		$this->assertSame( 1, $page['last_page'] );
	}

	/**
	 * A 2xx whose body is not a catalogue page (maintenance HTML decodes to an empty
	 * array) must fail, not read as "empty last page" — that would end the walk early
	 * and let the orphan sweep take the unseen plans out of stock.
	 */
	public function test_response_without_data_list_is_a_retryable_error(): void {
		$client = new Fake_Api_Client( array( 1 => array() ) );

		try {
			( new Package_Iterator( $client ) )->fetch_page( 1 );
			$this->fail( 'Expected an Api_Exception.' );
		} catch ( Api_Exception $e ) {
			$this->assertTrue( $e->is_retryable() );
		}
	}
}
