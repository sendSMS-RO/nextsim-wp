<?php
/**
 * Importer: time-boxed page chunks, run-state bookkeeping, progress, cancel.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use NextSIM\Woo\Data\Package_Repository;
use NextSIM\Woo\Import\Importer;
use NextSIM\Woo\Import\Plan_Data;
use NextSIM\Woo\Import\Product_Mapper;
use NextSIM\Woo\Logger;
use NextSIM\Woo\Settings;
use PHPUnit\Framework\TestCase;

/**
 * Product_Mapper double: records the plans it was asked to upsert.
 */
final class Stub_Mapper extends Product_Mapper {

	/** @var array<int, int> */
	public array $calls = array();

	/** @var array<int, string> package id => result / 'throw' */
	public array $results = array();

	/** @var callable|null Invoked before each upsert (to simulate outside interference). */
	public $before = null;

	// phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found
	public function __construct() {}

	public function upsert_with( Plan_Data $plan, int $run_timestamp, ?\WC_Product $product ): string {
		if ( null !== $this->before ) {
			( $this->before )( $plan );
		}

		$this->calls[] = $plan->id;
		$result        = $this->results[ $plan->id ] ?? self::RESULT_CREATED;

		if ( 'throw' === $result ) {
			throw new \RuntimeException( 'boom' );
		}

		return $result;
	}
}

/**
 * Package_Repository double: nothing is carried yet, nothing is orphaned.
 */
final class Stub_Repository extends Package_Repository {

	public function map_package_ids_to_products( array $package_ids ): array {
		return array();
	}

	public function find_orphans_synced_before( int $timestamp ): array {
		return array();
	}
}

final class ImporterTest extends TestCase {

	use MockeryPHPUnitIntegration;

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<int, array<string, mixed>> */
	private array $scheduled = array();

	/** @var array<int, array<int, mixed>> */
	private array $unscheduled = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		$this->options     = array(
			Settings::OPT_API_HOST  => 'https://example.test',
			Settings::OPT_API_TOKEN => 'token',
		);
		$this->scheduled   = array();
		$this->unscheduled = array();

		Functions\when( 'get_option' )->alias( fn ( string $key, $default = false ) => $this->options[ $key ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( string $key, $value ): bool {
				$this->options[ $key ] = $value;

				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( string $key ): bool {
				unset( $this->options[ $key ] );

				return true;
			}
		);
		Functions\when( 'wp_cache_delete' )->justReturn( true );
		Functions\when( 'wp_defer_term_counting' )->justReturn( false );
		Functions\when( 'wc_get_product' )->justReturn( false );
		Functions\when( 'number_format_i18n' )->alias( fn ( $n ) => number_format( (float) $n ) );
		Functions\when( 'as_schedule_single_action' )->alias(
			function ( int $ts, string $hook, array $args, string $group = '' ): int {
				$this->scheduled[] = array( 'hook' => $hook, 'args' => $args[0], 'group' => $group, 'async' => false, 'ts' => $ts );

				return count( $this->scheduled );
			}
		);
		Functions\when( 'as_enqueue_async_action' )->alias(
			function ( string $hook, array $args, string $group = '' ): int {
				$this->scheduled[] = array( 'hook' => $hook, 'args' => $args[0], 'group' => $group, 'async' => true );

				return count( $this->scheduled );
			}
		);
		Functions\when( 'as_unschedule_all_actions' )->alias(
			function ( string $hook, array $args = array(), string $group = '' ): void {
				$this->unscheduled[] = array( $hook, $args, $group );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<int, array<string, mixed>> $pages page number => list of plan ids on it
	 */
	private function importer( array $pages, ?Stub_Mapper $mapper = null ): Importer {
		$payloads  = array();
		$last_page = count( $pages );
		$total     = array_sum( array_map( 'count', $pages ) );

		foreach ( $pages as $number => $ids ) {
			$payloads[ $number ] = array(
				'data' => array_map( fn ( int $id ): array => $this->plan( $id ), $ids ),
				'meta' => array( 'current_page' => $number, 'last_page' => $last_page, 'total' => $total ),
			);
		}

		return new Importer(
			new Fake_Api_Client( $payloads ),
			$mapper ?? new Stub_Mapper(),
			new Stub_Repository(),
			new Settings(),
			new Logger()
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function plan( int $id ): array {
		return array(
			'id'                   => $id,
			'display_name'         => "Plan $id",
			'reseller_price'       => '1.00',
			'rrp_price'            => '2.00',
			'data_limit_gigabytes' => 1,
			'period_days'          => 7,
			'location_zone_name'   => 'Turkey',
			'route'                => 'ROM001',
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function state(): array {
		return is_array( $this->options['nextsim_woo_sync_run_state'] ?? null ) ? $this->options['nextsim_woo_sync_run_state'] : array();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function last_scheduled(): array {
		return $this->scheduled[ array_key_last( $this->scheduled ) ];
	}

	public function test_start_run_claims_state_and_queues_first_page_immediately(): void {
		$importer = $this->importer( array( 1 => array( 1 ) ) );

		$this->assertTrue( $importer->trigger_now() );

		$state = $this->state();
		$this->assertSame( 'importing', $state['phase'] );
		$this->assertSame( 0, $state['processed'] );
		$this->assertSame( 1, $state['seg_count'] );

		$job = $this->last_scheduled();
		$this->assertTrue( $job['async'] );
		$this->assertSame( Importer::HOOK_PAGE, $job['hook'] );
		$this->assertSame( array( 'seg' => 0, 'page' => 1, 'run' => $state['run'], 'try' => 0, 'offset' => 0 ), $job['args'] );
	}

	public function test_start_run_refuses_when_not_configured(): void {
		unset( $this->options[ Settings::OPT_API_TOKEN ] );

		$this->assertFalse( $this->importer( array( 1 => array( 1 ) ) )->trigger_now() );
		$this->assertSame( array(), $this->state() );
		$this->assertSame( array(), $this->scheduled );
	}

	public function test_exhausted_budget_reschedules_same_page_with_offset(): void {
		Filters\expectApplied( Importer::FILTER_TIME_BUDGET )->andReturn( 0 );

		$mapper   = new Stub_Mapper();
		$importer = $this->importer( array( 1 => array( 1, 2, 3 ) ), $mapper );
		$importer->start_run();
		$run = $this->state()['run'];

		$importer->run_page( array( 'seg' => 0, 'page' => 1, 'run' => $run, 'try' => 0, 'offset' => 0 ) );

		// At least one plan always makes it through, then the rest is handed over.
		$this->assertSame( array( 1 ), $mapper->calls );

		$job = $this->last_scheduled();
		$this->assertFalse( $job['async'] );
		$this->assertSame( array( 'seg' => 0, 'page' => 1, 'run' => $run, 'try' => 0, 'offset' => 1 ), $job['args'] );

		$state = $this->state();
		$this->assertSame( 1, $state['offset'] );
		$this->assertSame( 1, $state['processed'] );
		$this->assertSame( 1, $state['seg_processed'] );
		$this->assertSame( 3, $state['seg_total'] );
		$this->assertSame( 1, $state['last_page'] );
	}

	public function test_offset_continues_page_then_moves_to_next_page(): void {
		$mapper   = new Stub_Mapper();
		$importer = $this->importer( array( 1 => array( 1, 2, 3 ), 2 => array( 4 ) ), $mapper );
		$importer->start_run();
		$run = $this->state()['run'];

		$importer->run_page( array( 'seg' => 0, 'page' => 1, 'run' => $run, 'try' => 0, 'offset' => 2 ) );

		$this->assertSame( array( 3 ), $mapper->calls );
		$this->assertSame( array( 'seg' => 0, 'page' => 2, 'run' => $run, 'try' => 0, 'offset' => 0 ), $this->last_scheduled()['args'] );

		$state = $this->state();
		$this->assertSame( 2, $state['page'] );
		$this->assertSame( 0, $state['offset'] );
	}

	public function test_last_page_finishes_run_with_ok_result(): void {
		$mapper          = new Stub_Mapper();
		$mapper->results = array( 2 => Product_Mapper::RESULT_UPDATED );
		$importer        = $this->importer( array( 1 => array( 1, 2 ) ), $mapper );
		$importer->start_run();
		$run = $this->state()['run'];

		$importer->run_page( array( 'seg' => 0, 'page' => 1, 'run' => $run, 'try' => 0, 'offset' => 0 ) );

		$this->assertSame( array(), $this->state(), 'run state is released' );

		$result = $this->options[ Importer::OPT_LAST_RESULT ];
		$this->assertSame( 'ok', $result['status'] );
		$this->assertSame( 2, $result['processed'] );
		$this->assertSame( 1, $result['created'] );
		$this->assertSame( 1, $result['updated_items'] );
		$this->assertSame( $run, $this->options['nextsim_woo_last_full_sync'] );
		$this->assertArrayNotHasKey( Importer::OPT_LAST_ERROR, $this->options );

		$progress = $importer->progress();
		$this->assertSame( 'done', $progress['status'] );
		$this->assertSame( 100, $progress['percent'] );
	}

	public function test_failed_plan_yields_error_result_and_no_sweep(): void {
		$mapper          = new Stub_Mapper();
		$mapper->results = array( 2 => 'throw' );
		$importer        = $this->importer( array( 1 => array( 1, 2, 3 ) ), $mapper );
		$importer->start_run();
		$run = $this->state()['run'];

		$importer->run_page( array( 'seg' => 0, 'page' => 1, 'run' => $run, 'try' => 0, 'offset' => 0 ) );

		$this->assertSame( array( 1, 2, 3 ), $mapper->calls, 'one failure does not stop the page' );

		$result = $this->options[ Importer::OPT_LAST_RESULT ];
		$this->assertSame( 'error', $result['status'] );
		$this->assertSame( 1, $result['failed_items'] );
		$this->assertSame( 3, $result['processed'] );
		$this->assertArrayNotHasKey( 'nextsim_woo_last_full_sync', $this->options );
		$this->assertStringContainsString( '1 plan(s) failed', $this->options[ Importer::OPT_LAST_ERROR ] );
		$this->assertSame( 'error', $importer->progress()['status'] );
	}

	public function test_superseded_job_does_nothing(): void {
		$mapper   = new Stub_Mapper();
		$importer = $this->importer( array( 1 => array( 1 ) ), $mapper );
		$importer->start_run();
		$queued = count( $this->scheduled );

		$importer->run_page( array( 'seg' => 0, 'page' => 1, 'run' => 12345, 'try' => 0, 'offset' => 0 ) );

		$this->assertSame( array(), $mapper->calls );
		$this->assertCount( $queued, $this->scheduled );
	}

	public function test_cancel_during_a_chunk_stops_the_chain(): void {
		$mapper   = new Stub_Mapper();
		$importer = $this->importer( array( 1 => array( 1, 2 ), 2 => array( 3 ) ), $mapper );
		$importer->start_run();
		$run    = $this->state()['run'];
		$queued = count( $this->scheduled );

		// Another request cancels the run while this chunk is mid-way.
		$mapper->before = function ( Plan_Data $plan ) use ( $importer ): void {
			if ( 2 === $plan->id ) {
				$importer->cancel();
			}
		};

		$importer->run_page( array( 'seg' => 0, 'page' => 1, 'run' => $run, 'try' => 0, 'offset' => 0 ) );

		$this->assertSame( array( 1, 2 ), $mapper->calls );
		$this->assertCount( $queued, $this->scheduled, 'no follow-up job after a cancel' );
		$this->assertSame( array(), $this->state(), 'the cancelled state is not resurrected' );
		$this->assertSame( 'cancelled', $this->options[ Importer::OPT_LAST_RESULT ]['status'] );
	}

	public function test_cancel_drops_queued_jobs_and_records_result(): void {
		$importer = $this->importer( array( 1 => array( 1 ) ) );
		$importer->start_run();

		$importer->cancel();

		$this->assertSame(
			array(
				array( Importer::HOOK_PAGE, array(), '' ),
				array( Importer::HOOK_SYNC, array( array( 'full' => true ) ), Importer::GROUP ),
			),
			$this->unscheduled
		);
		$this->assertSame( array(), $this->state() );

		$progress = $importer->progress();
		$this->assertSame( 'cancelled', $progress['status'] );
		$this->assertStringContainsString( 'cancelled', $progress['summary'] );
	}

	public function test_progress_percent_and_stall_detection(): void {
		$importer = $this->importer( array() );
		$now      = time();

		$this->options['nextsim_woo_sync_run_state'] = array(
			'run'           => $now,
			'started'       => $now - 90,
			'updated'       => $now,
			'phase'         => 'importing',
			'seg'           => 1,
			'seg_count'     => 2,
			'page'          => 3,
			'last_page'     => 10,
			'offset'        => 0,
			'seg_total'     => 100,
			'seg_processed' => 50,
			'processed'     => 250,
			'created'       => 250,
		);

		$progress = $importer->progress();
		$this->assertSame( 'running', $progress['status'] );
		$this->assertSame( 75, $progress['percent'] );
		$this->assertFalse( $progress['stalled'] );
		$this->assertSame( 90, $progress['elapsed'] );
		$this->assertSame( 'Segment 2/2 · Page 3/10 · 50 / 100 plans · 75%', $progress['summary'] );

		// Total not known yet and no page count: no percentage, indeterminate bar.
		$this->options['nextsim_woo_sync_run_state']['seg_total'] = 0;
		$this->options['nextsim_woo_sync_run_state']['last_page'] = 0;
		$this->assertNull( $importer->progress()['percent'] );

		// Nothing persisted for a long time: flagged as stalled.
		$this->options['nextsim_woo_sync_run_state']['updated'] = $now - 600;
		$this->assertTrue( $importer->progress()['stalled'] );

		// Sweeping phase reads as complete.
		$this->options['nextsim_woo_sync_run_state']['phase'] = 'sweeping';
		$this->assertSame( 100, $importer->progress()['percent'] );
	}

	public function test_progress_is_idle_before_any_run(): void {
		$progress = $this->importer( array() )->progress();

		$this->assertSame( 'idle', $progress['status'] );
		$this->assertNull( $progress['percent'] );
	}

	public function test_ensure_scheduled_skips_non_admin_requests(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\expect( 'as_has_scheduled_action' )->never();

		$this->importer( array() )->ensure_scheduled();
	}
}
