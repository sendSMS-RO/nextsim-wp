<?php
/**
 * Orchestrates the paginated, throttled plan import via Action Scheduler.
 *
 * A run walks a list of query "segments" (one per import filter combination). Each
 * page is its own scheduled job, spaced apart to stay under the API rate limit. When
 * every segment is exhausted, orphaned products (plans that disappeared) are drafted.
 *
 * @package NextSIM\Woo
 */

declare(strict_types=1);

namespace NextSIM\Woo\Import;

use NextSIM\Woo\Api\Api_Client;
use NextSIM\Woo\Api\Api_Exception;
use NextSIM\Woo\Api\Package_Iterator;
use NextSIM\Woo\Data\Package_Repository;
use NextSIM\Woo\Logger;
use NextSIM\Woo\Settings;

defined( 'ABSPATH' ) || exit;

class Importer {

	public const HOOK_SYNC = 'nextsim_woo_sync';
	public const HOOK_PAGE = 'nextsim_woo_sync_page';
	public const GROUP     = 'nextsim-woo';

	/**
	 * Run state: { run: int, segments: array, imported: int }. A new run overwrites it,
	 * which makes any still-queued jobs from a previous run abort (run id mismatch) —
	 * that is the overlap guard. Segments are snapshotted here so mid-run filter edits
	 * cannot shift or drop segment indexes.
	 */
	private const OPT_RUN_STATE        = 'nextsim_woo_sync_run_state';
	private const OPT_APPLIED_INTERVAL = 'nextsim_woo_sync_interval_applied';

	/**
	 * Incremental sync bookkeeping. OPT_SYNC_CURSOR is the server `generated_at` to send
	 * as the next `/changes?since=`; OPT_LAST_FULL_SYNC is the unix time of the last full
	 * walk (the reconciliation safety net).
	 */
	private const OPT_SYNC_CURSOR    = 'nextsim_woo_sync_cursor';
	private const OPT_LAST_FULL_SYNC = 'nextsim_woo_last_full_sync';

	// A cursor older than this can no longer be used with /changes (30-day cap, kept
	// well under it) → re-baseline with a full walk. Plus a periodic full walk that
	// reconciles anything /changes cannot express (metadata-only edits, non-retired
	// departures) even while everything else stays incremental.
	private const FULL_SYNC_MAX_AGE  = 25 * DAY_IN_SECONDS;
	private const FULL_SYNC_INTERVAL = 7 * DAY_IN_SECONDS;

	/**
	 * Human-readable description of the last failed run, shown as an admin notice.
	 * Cleared by the next fully successful run.
	 */
	public const OPT_LAST_ERROR = 'nextsim_woo_last_sync_error';

	private const PAGE_SPACING_SECONDS = 2;
	private const MAX_PAGE_RETRIES     = 5;

	public function __construct(
		private Api_Client $client,
		private Product_Mapper $mapper,
		private Package_Repository $repository,
		private Settings $settings,
		private Logger $logger
	) {}

	public function register(): void {
		add_action( self::HOOK_SYNC, array( $this, 'run_scheduled' ) );
		add_action( self::HOOK_PAGE, array( $this, 'run_page' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	/**
	 * Scheduled entry point: pick incremental (/changes delta) or a full walk.
	 *
	 * @param array{full?: bool} $args
	 */
	public function run_scheduled( $args = array() ): void {
		if ( ! empty( $args['full'] ) || $this->should_full_sync() ) {
			$this->start_run();

			return;
		}

		$this->run_incremental();
	}

	/**
	 * A full walk is needed when: filters are configured (/changes cannot filter
	 * server-side), no cursor exists yet (bootstrap), the cursor has aged past the
	 * /changes window, or the weekly reconciliation is due.
	 */
	private function should_full_sync(): bool {
		if ( $this->has_filters() ) {
			return true;
		}

		$cursor = (string) get_option( self::OPT_SYNC_CURSOR, '' );
		if ( '' === $cursor ) {
			return true;
		}

		$cursor_ts = strtotime( $cursor );
		if ( false === $cursor_ts || ( time() - $cursor_ts ) > self::FULL_SYNC_MAX_AGE ) {
			return true;
		}

		$last_full = (int) get_option( self::OPT_LAST_FULL_SYNC, 0 );

		return ( time() - $last_full ) > self::FULL_SYNC_INTERVAL;
	}

	private function has_filters(): bool {
		return array() !== $this->settings->import_routes()
			|| array() !== $this->settings->import_countries()
			|| array() !== $this->settings->import_regions();
	}

	/**
	 * Apply one /changes delta: upsert added plans, reprice changed ones, and take
	 * retired ones out of stock — no full walk, no orphan sweep. Retirements propagate
	 * within one cycle, so a package pulled upstream stops being purchasable fast.
	 */
	public function run_incremental(): void {
		if ( ! $this->settings->is_configured() ) {
			return;
		}

		$since = (string) get_option( self::OPT_SYNC_CURSOR, '' );
		if ( '' === $since ) {
			$this->start_run();

			return;
		}

		try {
			$changes = $this->client->get_package_changes( $since );
		} catch ( Api_Exception $e ) {
			// The cursor aged out or was rejected: re-baseline with a full walk.
			if ( 422 === $e->get_http_status() ) {
				$this->logger->warning( 'Incremental since rejected — falling back to full sync', array( 'since' => $since ) );
				$this->start_run();

				return;
			}

			// Transient error: keep the cursor and try again next cycle.
			$this->logger->warning( 'Incremental sync failed', array( 'error' => $e->getMessage() ) );

			return;
		}

		$run     = time();
		$added   = is_array( $changes['added'] ?? null ) ? $changes['added'] : array();
		$priced  = is_array( $changes['price_changed'] ?? null ) ? $changes['price_changed'] : array();
		$retired = is_array( $changes['retired'] ?? null ) ? $changes['retired'] : array();

		$counts = array( 'added' => 0, 'repriced' => 0, 'retired' => 0, 'failed' => 0 );

		foreach ( $added as $item ) {
			try {
				$this->mapper->upsert( Plan_Data::from_api( $item ), $run );
				++$counts['added'];
			} catch ( \Throwable $e ) {
				++$counts['failed'];
				$this->logger->error( 'Incremental: adding a plan failed', array( 'package_id' => (int) ( $item['id'] ?? 0 ), 'error' => $e->getMessage() ) );
			}
		}

		$this->apply_price_changes( $priced, $counts );
		$this->apply_retirements( $retired, $counts );

		// Advance the cursor to the server's own clock so the next window has no gap
		// and no timezone skew of our making.
		$generated_at = (string) ( $changes['generated_at'] ?? '' );
		if ( '' !== $generated_at ) {
			update_option( self::OPT_SYNC_CURSOR, $generated_at, false );
		}

		delete_option( self::OPT_LAST_ERROR );
		$this->logger->info( 'Incremental sync done', $counts );
	}

	/**
	 * @param array<int, array<string, mixed>> $priced
	 * @param array<string, int>               $counts
	 */
	private function apply_price_changes( array $priced, array &$counts ): void {
		if ( array() === $priced ) {
			return;
		}

		$map = $this->repository->map_package_ids_to_products( array_map( static fn ( $p ): int => (int) ( $p['id'] ?? 0 ), $priced ) );

		foreach ( $priced as $item ) {
			$product_id = $map[ (int) ( $item['id'] ?? 0 ) ] ?? 0;
			if ( 0 === $product_id ) {
				continue; // Not a package we carry.
			}

			$product = wc_get_product( $product_id );
			if ( ! $product instanceof \WC_Product ) {
				continue;
			}

			try {
				if ( $this->mapper->apply_price_change( $product, (float) ( $item['new_reseller_price'] ?? 0 ), (float) ( $item['new_rrp_price'] ?? 0 ) ) ) {
					++$counts['repriced'];
				}
			} catch ( \Throwable $e ) {
				++$counts['failed'];
				$this->logger->error( 'Incremental: repricing failed', array( 'package_id' => (int) ( $item['id'] ?? 0 ), 'error' => $e->getMessage() ) );
			}
		}
	}

	/**
	 * @param array<int, array<string, mixed>> $retired
	 * @param array<string, int>               $counts
	 */
	private function apply_retirements( array $retired, array &$counts ): void {
		if ( array() === $retired ) {
			return;
		}

		$map = $this->repository->map_package_ids_to_products( array_map( static fn ( $r ): int => (int) ( $r['id'] ?? 0 ), $retired ) );

		foreach ( $retired as $item ) {
			$product_id = $map[ (int) ( $item['id'] ?? 0 ) ] ?? 0;
			if ( 0 !== $product_id && $this->mark_out_of_stock( $product_id ) ) {
				++$counts['retired'];
			}
		}
	}

	/**
	 * Make sure the recurring sync is scheduled at the configured cadence,
	 * rescheduling when the admin changes the frequency setting.
	 */
	public function ensure_scheduled(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		$setting = $this->settings->sync_interval();

		if ( as_has_scheduled_action( self::HOOK_SYNC ) && get_option( self::OPT_APPLIED_INTERVAL ) === $setting ) {
			return;
		}

		as_unschedule_all_actions( self::HOOK_SYNC );

		$intervals = array(
			'hourly'     => HOUR_IN_SECONDS,
			'twicedaily' => 12 * HOUR_IN_SECONDS,
			'daily'      => DAY_IN_SECONDS,
		);

		$interval = $intervals[ $setting ] ?? DAY_IN_SECONDS;

		// $unique=true is safe ONLY here: the flag is hook-wide/args-blind, and the
		// recurring sync is the single hook that must exist at most once.
		as_schedule_recurring_action( time() + $interval, $interval, self::HOOK_SYNC, array(), self::GROUP, true );
		update_option( self::OPT_APPLIED_INTERVAL, $setting, false );
	}

	/**
	 * Trigger an import immediately (used by the "Sync now" button).
	 */
	public function trigger_now(): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			// "Sync now" is a deliberate full refresh, not an incremental delta.
			as_enqueue_async_action( self::HOOK_SYNC, array( array( 'full' => true ) ), self::GROUP );
		}
	}

	/**
	 * Start a run: snapshot the segments, claim the run state, kick off segment 0, page 1.
	 *
	 * Overwriting the run state supersedes any still-running previous run — its queued
	 * jobs abort on the run-id mismatch, so two runs can never interleave their sweeps.
	 */
	public function start_run(): void {
		if ( ! $this->settings->is_configured() ) {
			$this->logger->warning( 'Import skipped: plugin not configured.' );

			return;
		}

		$run      = time();
		$segments = $this->segments();

		update_option(
			self::OPT_RUN_STATE,
			array(
				'run'      => $run,
				'segments' => $segments,
				'imported' => 0,
				'failed'   => array(),
			),
			false
		);

		$this->logger->info( 'Import run started', array( 'run' => $run, 'segments' => count( $segments ) ) );
		$this->enqueue_page( 0, 1, $run, 0 );
	}

	/**
	 * Import one page of one segment, then schedule the next unit of work.
	 *
	 * @param array{seg?: int, page?: int, run?: int, try?: int} $args
	 */
	public function run_page( $args = array() ): void {
		$seg  = (int) ( $args['seg'] ?? 0 );
		$page = (int) ( $args['page'] ?? 1 );
		$run  = (int) ( $args['run'] ?? 0 );
		$try  = (int) ( $args['try'] ?? 0 );

		$state = get_option( self::OPT_RUN_STATE );

		if ( ! is_array( $state ) || (int) ( $state['run'] ?? 0 ) !== $run ) {
			$this->logger->info( 'Import page skipped: superseded run', array( 'seg' => $seg, 'page' => $page, 'run' => $run ) );

			return;
		}

		$segments = is_array( $state['segments'] ?? null ) ? $state['segments'] : array();

		if ( ! isset( $segments[ $seg ] ) ) {
			$this->finish_run( $state );

			return;
		}

		$iterator = new Package_Iterator( $this->client, $segments[ $seg ] );

		try {
			$result = $iterator->fetch_page( $page );
		} catch ( Api_Exception $e ) {
			if ( $e->is_retryable() && $try < self::MAX_PAGE_RETRIES ) {
				$delay = self::PAGE_SPACING_SECONDS * ( 2 ** $try );
				$this->logger->warning( 'Import page retry', array( 'seg' => $seg, 'page' => $page, 'try' => $try, 'delay' => $delay ) );
				$this->enqueue_page( $seg, $page, $run, $try + 1, $delay );

				return;
			}

			// Permanent failure (e.g. a 422 from an invalid filter value): record it,
			// move on to the NEXT segment so one bad filter cannot kill the whole sync,
			// and let finish_run suppress the orphan sweep for this run.
			$this->logger->error( 'Import segment failed permanently', array( 'seg' => $seg, 'page' => $page, 'error' => $e->getMessage() ) );

			$state['failed'][] = sprintf( 'segment %d (page %d): %s', $seg + 1, $page, $e->getMessage() );
			update_option( self::OPT_RUN_STATE, $state, false );

			if ( isset( $segments[ $seg + 1 ] ) ) {
				$this->enqueue_page( $seg + 1, 1, $run, 0 );
			} else {
				$this->finish_run( $state );
			}

			return;
		}

		$counts       = array( 'created' => 0, 'updated' => 0, 'skipped' => 0 );
		$failed_items = 0;
		foreach ( $result['items'] as $item ) {
			// A WC/DB error while mapping one plan must not kill the whole page job and
			// silently stall the catalog — skip that plan, record it, keep going.
			try {
				$plan   = Plan_Data::from_api( $item );
				$status = $this->mapper->upsert( $plan, $run );
				++$counts[ $status ];
			} catch ( \Throwable $e ) {
				++$failed_items;
				$this->logger->error( 'Import: mapping a plan failed, skipping it', array(
					'package_id' => (int) ( $item['id'] ?? 0 ),
					'error'      => $e->getMessage(),
				) );
			}
		}

		// Any per-item failure suppresses this run's orphan sweep (via finish_run) and
		// surfaces to the admin, matching how a segment-level failure is handled.
		if ( $failed_items > 0 ) {
			$state['failed'][] = sprintf( 'segment %d (page %d): %d plan(s) failed to import', $seg + 1, $page, $failed_items );
		}

		$state['imported'] = (int) ( $state['imported'] ?? 0 ) + count( $result['items'] );
		update_option( self::OPT_RUN_STATE, $state, false );

		$this->logger->info( 'Import page done', array( 'seg' => $seg, 'page' => $page, 'of' => $result['last_page'] ) + $counts );

		if ( $result['current_page'] < $result['last_page'] ) {
			$this->enqueue_page( $seg, $page + 1, $run, 0 );
		} elseif ( isset( $segments[ $seg + 1 ] ) ) {
			$this->enqueue_page( $seg + 1, 1, $run, 0 );
		} else {
			$this->finish_run( $state );
		}
	}

	/**
	 * Build the list of query segments from the import filters.
	 *
	 * @return array<int, array<string, scalar>>
	 */
	private function segments(): array {
		$routes    = $this->settings->import_routes();
		$countries = $this->settings->import_countries();
		$regions   = $this->settings->import_regions();

		$base = array();

		if ( array() !== $routes ) {
			foreach ( $routes as $route ) {
				$base[] = array( 'route' => $route );
			}
		} else {
			$base[] = array();
		}

		$location_filters = array();
		foreach ( $countries as $country ) {
			$location_filters[] = array( 'country' => $country );
		}
		foreach ( $regions as $region ) {
			$location_filters[] = array( 'regionCode' => $region );
		}

		if ( array() === $location_filters ) {
			return $base;
		}

		$segments = array();
		foreach ( $base as $b ) {
			foreach ( $location_filters as $loc ) {
				$segments[] = $b + $loc;
			}
		}

		return $segments;
	}

	/**
	 * @param array<string, scalar> $extra
	 */
	private function enqueue_page( int $seg, int $page, int $run, int $try, int $delay = self::PAGE_SPACING_SECONDS ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		as_schedule_single_action(
			time() + max( 1, $delay ),
			self::HOOK_PAGE,
			array(
				array(
					'seg'  => $seg,
					'page' => $page,
					'run'  => $run,
					'try'  => $try,
				),
			),
			self::GROUP
		);
	}

	/**
	 * Finish a run: release the run state and sweep orphans — but never sweep after a
	 * run with failed segments (their products were not touched and would be wrongly
	 * swept) or a run that imported nothing (an API/parsing problem would otherwise
	 * take the entire catalog out of stock).
	 *
	 * @param array{run?: int, imported?: int, failed?: array<int, string>} $state
	 */
	private function finish_run( array $state ): void {
		$run      = (int) ( $state['run'] ?? 0 );
		$imported = (int) ( $state['imported'] ?? 0 );
		$failed   = is_array( $state['failed'] ?? null ) ? $state['failed'] : array();

		delete_option( self::OPT_RUN_STATE );

		if ( array() !== $failed ) {
			update_option( self::OPT_LAST_ERROR, implode( ' | ', $failed ), false );
			$this->logger->error( 'Import run finished with failed segments — orphan sweep skipped.', array( 'run' => $run, 'failed' => $failed ) );

			return;
		}

		if ( $imported <= 0 ) {
			update_option( self::OPT_LAST_ERROR, __( 'The last sync imported zero plans — check the API connection and the import filters.', 'nextsim-woo' ), false );
			$this->logger->error( 'Import run finished with zero plans — orphan sweep skipped as a safety measure.', array( 'run' => $run ) );

			return;
		}

		delete_option( self::OPT_LAST_ERROR );
		$this->sweep_orphans( $run );
		update_option( self::OPT_LAST_FULL_SYNC, $run, false );

		// Baseline the incremental cursor only when there is no fresh one — on bootstrap
		// or after it aged out. A running incremental keeps the cursor at the server's
		// own clock (its `generated_at`), so the weekly reconciliation walk must not
		// reset it back to a minted value and force a redundant re-apply window. The
		// one-hour margin here absorbs clock skew between this server and the API;
		// re-applying a change is idempotent, so overlap is harmless while a gap could
		// miss a retirement.
		$cursor    = (string) get_option( self::OPT_SYNC_CURSOR, '' );
		$cursor_ts = '' !== $cursor ? strtotime( $cursor ) : false;
		if ( '' === $cursor || false === $cursor_ts || ( time() - $cursor_ts ) > self::FULL_SYNC_MAX_AGE ) {
			update_option( self::OPT_SYNC_CURSOR, gmdate( 'c', $run - HOUR_IN_SECONDS ), false );
		}

		$this->logger->info( 'Import run finished', array( 'run' => $run, 'imported' => $imported ) );
	}

	/**
	 * Mark products whose plan disappeared from the API this run as out of stock —
	 * they stay in the catalog but can no longer be purchased, and are restored
	 * automatically when the plan reappears. Only products that carry a nextSIM
	 * package id are ever touched.
	 */
	private function sweep_orphans( int $run ): void {
		$swept = 0;
		foreach ( $this->repository->find_orphans_synced_before( $run ) as $product_id ) {
			if ( $this->mark_out_of_stock( (int) $product_id ) ) {
				++$swept;
			}
		}

		if ( $swept > 0 ) {
			$this->logger->info( 'Orphan sweep marked products out of stock', array( 'count' => $swept ) );
		}
	}

	/**
	 * Take one of our products out of stock (kept in the catalog, unpurchasable,
	 * auto-restored if the plan returns). Never touches a product that is not ours.
	 *
	 * @return bool True if the product was changed.
	 */
	private function mark_out_of_stock( int $product_id ): bool {
		$product = wc_get_product( $product_id );

		if ( ! $product instanceof \WC_Product ) {
			return false;
		}

		if ( '' === (string) $product->get_meta( \NextSIM\Woo\Data\Product_Meta::PACKAGE_ID ) ) {
			return false;
		}

		if ( 'outofstock' === $product->get_stock_status() ) {
			return false;
		}

		$product->set_stock_status( 'outofstock' );
		$product->save();

		return true;
	}
}
