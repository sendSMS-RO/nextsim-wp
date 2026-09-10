<?php
/**
 * Orchestrates the paginated, throttled plan import via Action Scheduler.
 *
 * A run walks a list of query "segments" (one per import filter combination). Each
 * page is its own scheduled job, spaced apart to stay under the API rate limit, and a
 * job that runs out of its time budget hands the rest of the page to a follow-up job
 * so a slow host never loses wp-admin to a long-running import. When every segment
 * is exhausted, orphaned products (plans that disappeared) are taken out of stock.
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
	 * Run state (see start_run() for the full shape). A new run overwrites it, which
	 * makes any still-queued jobs from a previous run abort (run id mismatch) — that
	 * is the overlap guard. Segments are snapshotted here so mid-run filter edits
	 * cannot shift or drop segment indexes. Every job re-reads it fresh and only
	 * writes it back when the run id still matches (see persist_state()), so a
	 * Cancel or a superseding run from another request stops the chain at the end
	 * of the current chunk.
	 */
	private const OPT_RUN_STATE        = 'nextsim_woo_sync_run_state';
	private const OPT_APPLIED_INTERVAL = 'nextsim_woo_sync_interval_applied';

	/**
	 * Summary of the last finished run (ok / error / cancelled), the source for the
	 * progress UI once nothing is running any more.
	 */
	public const OPT_LAST_RESULT = 'nextsim_woo_last_sync_result';

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

	/**
	 * Wall-clock budget of one page job, in seconds. A page that takes longer is
	 * continued by a follow-up job (same page, higher offset). Filterable so a fast
	 * host can raise it and a very slow one can lower it.
	 */
	public const FILTER_TIME_BUDGET   = 'nextsim_woo_import_time_budget';
	private const DEFAULT_TIME_BUDGET = 10;

	private const PAGE_SPACING_SECONDS  = 2;
	private const CHUNK_SPACING_SECONDS = 1;
	private const MAX_PAGE_RETRIES      = 5;

	// A running state not touched for this long is reported as stalled (cron/queue
	// not running) rather than silently shown as "in progress" forever.
	private const STALL_AFTER = 5 * MINUTE_IN_SECONDS;

	/** @var callable|null */
	private $direct_lookup_filter = null;

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

		$this->begin_bulk();
		try {
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
		} finally {
			$this->end_bulk();
		}

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
	 *
	 * Only checked on regular admin page views: the check is an Action Scheduler
	 * store query, and running it on every front-end, AJAX and cron request (where
	 * nobody can have changed the setting) is pure overhead.
	 */
	public function ensure_scheduled(): void {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		if ( ! is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
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
	 * Trigger a full import immediately (the "Sync now" button). Starts the run in
	 * this request — no API call is involved — so the progress UI sees a running
	 * state right away instead of an "idle" gap until the queue picks up a trigger.
	 *
	 * @return bool False when the plugin is not configured.
	 */
	public function trigger_now(): bool {
		return $this->start_run();
	}

	/**
	 * Start a run: snapshot the segments, claim the run state, kick off segment 0, page 1.
	 *
	 * Overwriting the run state supersedes any still-running previous run — its queued
	 * jobs abort on the run-id mismatch, so two runs can never interleave their sweeps.
	 *
	 * @return bool False when the plugin is not configured.
	 */
	public function start_run(): bool {
		if ( ! $this->settings->is_configured() ) {
			$this->logger->warning( 'Import skipped: plugin not configured.' );

			return false;
		}

		$run      = time();
		$segments = $this->segments();

		update_option(
			self::OPT_RUN_STATE,
			array(
				'run'           => $run,
				'segments'      => $segments,
				'phase'         => 'importing',
				'started'       => $run,
				'updated'       => $run,
				// Position of the next unit of work.
				'seg'           => 0,
				'seg_count'     => count( $segments ),
				'page'          => 1,
				'last_page'     => 0,
				'offset'        => 0,
				// Per-segment progress (reset when a segment completes).
				'seg_total'     => 0,
				'seg_processed' => 0,
				// Cumulative counters across the run.
				'processed'     => 0,
				'imported'      => 0,
				'created'       => 0,
				'updated_items' => 0,
				'skipped'       => 0,
				'failed_items'  => 0,
				'failed'        => array(),
			),
			false
		);

		$this->logger->info( 'Import run started', array( 'run' => $run, 'segments' => count( $segments ) ) );

		// Due immediately, so the queue runner dispatched at the end of this very
		// request (e.g. the "Sync now" AJAX call) picks it up.
		$this->enqueue_page( 0, 1, $run, 0, 0 );

		return true;
	}

	/**
	 * Import (part of) one page of one segment, then schedule the next unit of work.
	 *
	 * The page is fetched from the API and processed from `offset` on, for at most
	 * the time budget. Whatever is left is handed to a follow-up job for the same
	 * page — refetching it is cheap (one request per chunk, chunks are ≥ 1 s apart,
	 * well inside the 60 req/min limit) and the upsert is idempotent, so a catalog
	 * that shifts between two chunks can at worst re-apply or postpone one plan.
	 *
	 * @param array{seg?: int, page?: int, run?: int, try?: int, offset?: int} $args
	 */
	public function run_page( $args = array() ): void {
		$start  = microtime( true );
		$seg    = (int) ( $args['seg'] ?? 0 );
		$page   = (int) ( $args['page'] ?? 1 );
		$run    = (int) ( $args['run'] ?? 0 );
		$try    = (int) ( $args['try'] ?? 0 );
		$offset = max( 0, (int) ( $args['offset'] ?? 0 ) );

		$state = $this->fresh_state();

		if ( ! is_array( $state ) || (int) ( $state['run'] ?? 0 ) !== $run ) {
			$this->logger->info( 'Import page skipped: superseded or cancelled run', array( 'seg' => $seg, 'page' => $page, 'run' => $run ) );

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
				$this->enqueue_page( $seg, $page, $run, $try + 1, $delay, $offset );

				return;
			}

			// Permanent failure (e.g. a 422 from an invalid filter value): record it,
			// move on to the NEXT segment so one bad filter cannot kill the whole sync,
			// and let finish_run suppress the orphan sweep for this run.
			$this->logger->error( 'Import segment failed permanently', array( 'seg' => $seg, 'page' => $page, 'error' => $e->getMessage() ) );

			$state['failed'][] = sprintf( 'segment %d (page %d): %s', $seg + 1, $page, $e->getMessage() );
			$this->schedule_next( $state, $seg, $page, $run, count( $segments ), true, 0, 0 );

			return;
		}

		$items = array_slice( $result['items'], $offset );

		// One query for the whole page instead of one lookup per plan.
		$map = $this->repository->map_package_ids_to_products(
			array_map( static fn ( $item ): int => (int) ( $item['id'] ?? 0 ), $items )
		);

		/**
		 * Wall-clock budget of one import job, in seconds (see FILTER_TIME_BUDGET).
		 *
		 * @param int|float $seconds Default 10.
		 */
		$budget = (float) apply_filters( 'nextsim_woo_import_time_budget', self::DEFAULT_TIME_BUDGET );
		$counts       = array( 'created' => 0, 'updated' => 0, 'skipped' => 0 );
		$failed_items = 0;
		$processed    = 0;

		$fetched = microtime( true );

		$this->begin_bulk();
		try {
			foreach ( $items as $item ) {
				// Always make progress on at least one plan, otherwise a budget below
				// one item's cost would reschedule the same offset forever.
				if ( $processed > 0 && ( microtime( true ) - $start ) >= $budget ) {
					break;
				}

				// A WC/DB error while mapping one plan must not kill the whole page job and
				// silently stall the catalog — skip that plan, record it, keep going.
				try {
					$plan     = Plan_Data::from_api( $item );
					$existing = $this->existing_product( $map[ $plan->id ] ?? 0 );
					$status   = $this->mapper->upsert_with( $plan, $run, $existing );
					++$counts[ $status ];
				} catch ( \Throwable $e ) {
					++$failed_items;
					$this->logger->error( 'Import: mapping a plan failed, skipping it', array(
						'package_id' => (int) ( $item['id'] ?? 0 ),
						'error'      => $e->getMessage(),
					) );
				}

				++$processed;
			}
		} finally {
			$looped = microtime( true );
			$this->end_bulk();
		}

		$timing = array(
			'fetch_s' => round( $fetched - $start, 2 ),
			'items_s' => round( $looped - $fetched, 2 ),
			'flush_s' => round( microtime( true ) - $looped, 2 ),
		);

		// Any per-item failure suppresses this run's orphan sweep (via finish_run) and
		// surfaces to the admin, matching how a segment-level failure is handled.
		if ( $failed_items > 0 ) {
			$state['failed'][] = sprintf( 'segment %d (page %d): %d plan(s) failed to import', $seg + 1, $page, $failed_items );
		}

		$state['last_page']      = (int) $result['last_page'];
		$state['seg_total']      = (int) $result['total'];
		$state['seg_processed']  = (int) ( $state['seg_processed'] ?? 0 ) + $processed;
		$state['processed']      = (int) ( $state['processed'] ?? 0 ) + $processed;
		$state['imported']       = $state['processed'];
		$state['created']        = (int) ( $state['created'] ?? 0 ) + $counts['created'];
		$state['updated_items']  = (int) ( $state['updated_items'] ?? 0 ) + $counts['updated'];
		$state['skipped']        = (int) ( $state['skipped'] ?? 0 ) + $counts['skipped'];
		$state['failed_items']   = (int) ( $state['failed_items'] ?? 0 ) + $failed_items;

		$done_on_page = $offset + $processed;
		$page_done    = $done_on_page >= count( $result['items'] );

		$this->logger->info(
			$page_done ? 'Import page done' : 'Import page chunk done',
			array( 'seg' => $seg, 'page' => $page, 'of' => $result['last_page'], 'offset' => $offset, 'processed' => $processed ) + $counts + $timing
		);

		$this->schedule_next(
			$state,
			$seg,
			$page,
			$run,
			count( $segments ),
			$page_done && (int) $result['current_page'] >= (int) $result['last_page'],
			$page_done ? 0 : $done_on_page,
			$page_done ? $page + 1 : $page
		);
	}

	/**
	 * Move the run-state position to the next unit of work, persist it, and queue
	 * the job for it — or finish the run when nothing is left. Nothing is queued when
	 * the state could not be persisted (the run was cancelled or superseded).
	 *
	 * @param array<string, mixed> $state
	 * @param bool                 $segment_done True when the current segment has no more pages.
	 * @param int                  $next_offset  Offset to continue the current page at (0 = page complete).
	 * @param int                  $next_page    Page to continue at when the segment is not done.
	 */
	private function schedule_next( array $state, int $seg, int $page, int $run, int $seg_count, bool $segment_done, int $next_offset, int $next_page ): void {
		$state['seg']     = $seg;
		$state['page']    = $page;
		$state['updated'] = time();

		if ( $segment_done ) {
			$finished = ! ( $seg + 1 < $seg_count );

			if ( ! $finished ) {
				$state['seg']           = $seg + 1;
				$state['page']          = 1;
				$state['offset']        = 0;
				$state['last_page']     = 0;
				$state['seg_total']     = 0;
				$state['seg_processed'] = 0;
			}

			if ( ! $this->persist_state( $state ) ) {
				$this->logger->info( 'Import stopped: run cancelled or superseded', array( 'run' => $run ) );

				return;
			}

			if ( $finished ) {
				$this->finish_run( $state );
			} else {
				$this->enqueue_page( $seg + 1, 1, $run, 0 );
			}

			return;
		}

		$continuing_page = $next_offset > 0;

		$state['page']   = $continuing_page ? $page : $next_page;
		$state['offset'] = $next_offset;

		if ( ! $this->persist_state( $state ) ) {
			$this->logger->info( 'Import stopped: run cancelled or superseded', array( 'run' => $run ) );

			return;
		}

		if ( $continuing_page ) {
			$this->enqueue_page( $seg, $page, $run, 0, self::CHUNK_SPACING_SECONDS, $next_offset );
		} else {
			$this->enqueue_page( $seg, $next_page, $run, 0 );
		}
	}

	/**
	 * Resolve a product id from the page lookup map. Mirrors find_by_package_id():
	 * a trashed product is not "ours" any more and gets a fresh import.
	 */
	private function existing_product( int $product_id ): ?\WC_Product {
		if ( $product_id <= 0 ) {
			return null;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product instanceof \WC_Product || 'trash' === $product->get_status() ) {
			return null;
		}

		return $product;
	}

	/**
	 * Cheaper product writes for the duration of a bulk loop:
	 *
	 * - term counts are recomputed once at the end instead of on every save (five
	 *   taxonomies per product, including WooCommerce's product_cat recount);
	 * - WooCommerce's attribute-lookup table is updated inline instead of scheduling
	 *   one Action Scheduler job per saved product (thousands of jobs per full sync
	 *   otherwise, competing with the import in the same queue);
	 * - product transient deletions are batched (WooCommerce ≥ 9.9), guarded because
	 *   the deferrer lives in WooCommerce's internal namespace.
	 */
	private function begin_bulk(): void {
		if ( function_exists( 'wp_defer_term_counting' ) ) {
			wp_defer_term_counting( true );
		}

		if ( null === $this->direct_lookup_filter ) {
			$this->direct_lookup_filter = static fn (): string => 'yes';
			add_filter( 'pre_option_woocommerce_attribute_lookup_direct_updates', $this->direct_lookup_filter );
		}

		$deferrer = $this->transients_deferrer();
		if ( null !== $deferrer ) {
			$deferrer->start_deferring();
		}
	}

	private function end_bulk(): void {
		$deferrer = $this->transients_deferrer();
		if ( null !== $deferrer ) {
			$deferrer->stop_deferring();
		}

		if ( null !== $this->direct_lookup_filter ) {
			remove_filter( 'pre_option_woocommerce_attribute_lookup_direct_updates', $this->direct_lookup_filter );
			$this->direct_lookup_filter = null;
		}

		if ( function_exists( 'wp_defer_term_counting' ) ) {
			wp_defer_term_counting( false );
		}
	}

	private function transients_deferrer(): ?object {
		if ( ! function_exists( 'wc_get_container' ) ) {
			return null;
		}

		$class = 'Automattic\\WooCommerce\\Internal\\Caches\\ProductTransientsDeferrer';
		if ( ! class_exists( $class ) ) {
			return null;
		}

		try {
			$deferrer = wc_get_container()->get( $class );
		} catch ( \Throwable $e ) {
			return null;
		}

		return ( is_object( $deferrer ) && method_exists( $deferrer, 'start_deferring' ) && method_exists( $deferrer, 'stop_deferring' ) )
			? $deferrer
			: null;
	}

	/**
	 * The run state as currently stored, bypassing this request's option cache so a
	 * Cancel or a new run started from another request is seen.
	 *
	 * @return array<string, mixed>|null
	 */
	private function fresh_state(): ?array {
		wp_cache_delete( self::OPT_RUN_STATE, 'options' );

		$state = get_option( self::OPT_RUN_STATE );

		return is_array( $state ) ? $state : null;
	}

	/**
	 * Write the run state back only while it still belongs to the same run.
	 *
	 * @param array<string, mixed> $state
	 */
	private function persist_state( array $state ): bool {
		$current = $this->fresh_state();

		if ( null === $current || (int) ( $current['run'] ?? 0 ) !== (int) ( $state['run'] ?? 0 ) ) {
			return false;
		}

		update_option( self::OPT_RUN_STATE, $state, false );

		return true;
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
	 * Queue one page job. A delay of 0 queues it as an async action (due now).
	 */
	private function enqueue_page( int $seg, int $page, int $run, int $try, int $delay = self::PAGE_SPACING_SECONDS, int $offset = 0 ): void {
		$args = array(
			array(
				'seg'    => $seg,
				'page'   => $page,
				'run'    => $run,
				'try'    => $try,
				'offset' => $offset,
			),
		);

		if ( $delay <= 0 && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK_PAGE, $args, self::GROUP );

			return;
		}

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}

		as_schedule_single_action( time() + max( 1, $delay ), self::HOOK_PAGE, $args, self::GROUP );
	}

	/**
	 * Finish a run: sweep orphans and record the outcome — but never sweep after a
	 * run with failed segments (their products were not touched and would be wrongly
	 * swept) or a run that imported nothing (an API/parsing problem would otherwise
	 * take the entire catalog out of stock). The run state stays in place (phase
	 * "sweeping") until the summary is written, so the progress UI never reports
	 * "idle" while the sweep is still running.
	 *
	 * @param array<string, mixed> $state
	 */
	private function finish_run( array $state ): void {
		$run       = (int) ( $state['run'] ?? 0 );
		$processed = (int) ( $state['processed'] ?? $state['imported'] ?? 0 );
		$failed    = is_array( $state['failed'] ?? null ) ? $state['failed'] : array();

		$state['phase']   = 'sweeping';
		$state['updated'] = time();

		if ( ! $this->persist_state( $state ) ) {
			$this->logger->info( 'Import finish skipped: run cancelled or superseded', array( 'run' => $run ) );

			return;
		}

		$result = $this->result_from_state( $state, 'ok', '' );

		try {
			if ( array() !== $failed ) {
				$result['status']  = 'error';
				$result['message'] = implode( ' | ', $failed );
				update_option( self::OPT_LAST_ERROR, $result['message'], false );
				$this->logger->error( 'Import run finished with failed segments — orphan sweep skipped.', array( 'run' => $run, 'failed' => $failed ) );
			} elseif ( $processed <= 0 ) {
				$result['status']  = 'error';
				$result['message'] = __( 'The last sync imported zero plans — check the API connection and the import filters.', 'nextsim-woo' );
				update_option( self::OPT_LAST_ERROR, $result['message'], false );
				$this->logger->error( 'Import run finished with zero plans — orphan sweep skipped as a safety measure.', array( 'run' => $run ) );
			} else {
				delete_option( self::OPT_LAST_ERROR );
				$result['swept'] = $this->sweep_orphans( $run );
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

				$this->logger->info( 'Import run finished', array( 'run' => $run, 'imported' => $processed, 'swept' => $result['swept'] ) );
			}
		} catch ( \Throwable $e ) {
			$result['status']  = 'error';
			$result['message'] = $e->getMessage();
			update_option( self::OPT_LAST_ERROR, $result['message'], false );
			$this->logger->error( 'Import run failed while finishing', array( 'run' => $run, 'error' => $e->getMessage() ) );
		} finally {
			$result['finished'] = time();
			update_option( self::OPT_LAST_RESULT, $result, false );
			delete_option( self::OPT_RUN_STATE );
		}
	}

	/**
	 * Abort the running import: drop its queued jobs and its state. The chunk that
	 * may be executing right now stops on its own at the end (persist_state fails).
	 */
	public function cancel(): void {
		$state = $this->fresh_state();

		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_PAGE );
			// A queued "Sync now" trigger, but not the recurring sync (args are matched).
			as_unschedule_all_actions( self::HOOK_SYNC, array( array( 'full' => true ) ), self::GROUP );
		}

		if ( null === $state ) {
			return;
		}

		$result             = $this->result_from_state( $state, 'cancelled', __( 'Cancelled by an administrator.', 'nextsim-woo' ) );
		$result['finished'] = time();

		update_option( self::OPT_LAST_RESULT, $result, false );
		delete_option( self::OPT_RUN_STATE );

		$this->logger->info( 'Import run cancelled', array( 'run' => (int) ( $state['run'] ?? 0 ), 'processed' => $result['processed'] ) );
	}

	/**
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 */
	private function result_from_state( array $state, string $status, string $message ): array {
		return array(
			'run'           => (int) ( $state['run'] ?? 0 ),
			'started'       => (int) ( $state['started'] ?? $state['run'] ?? 0 ),
			'finished'      => 0,
			'status'        => $status,
			'message'       => $message,
			'processed'     => (int) ( $state['processed'] ?? $state['imported'] ?? 0 ),
			'created'       => (int) ( $state['created'] ?? 0 ),
			'updated_items' => (int) ( $state['updated_items'] ?? 0 ),
			'skipped'       => (int) ( $state['skipped'] ?? 0 ),
			'failed_items'  => (int) ( $state['failed_items'] ?? 0 ),
			'swept'         => 0,
		);
	}

	/**
	 * Snapshot of the import for the admin UI: the running state if there is one,
	 * otherwise the last finished run, otherwise idle.
	 *
	 * @return array<string, mixed>
	 */
	public function progress(): array {
		$state = $this->fresh_state();

		if ( null !== $state ) {
			return $this->progress_from_state( $state );
		}

		$last = get_option( self::OPT_LAST_RESULT );

		if ( is_array( $last ) ) {
			return $this->progress_from_result( $last );
		}

		return $this->progress_shape(
			array(
				'status'  => 'idle',
				'summary' => __( 'No sync has run yet.', 'nextsim-woo' ),
			)
		);
	}

	/**
	 * @param array<string, mixed> $state
	 * @return array<string, mixed>
	 */
	private function progress_from_state( array $state ): array {
		$now           = time();
		$phase         = (string) ( $state['phase'] ?? 'importing' );
		$seg           = (int) ( $state['seg'] ?? 0 );
		$seg_count     = max( 1, (int) ( $state['seg_count'] ?? 1 ) );
		$page          = (int) ( $state['page'] ?? 1 );
		$last_page     = (int) ( $state['last_page'] ?? 0 );
		$offset        = (int) ( $state['offset'] ?? 0 );
		$seg_total     = (int) ( $state['seg_total'] ?? 0 );
		$seg_processed = (int) ( $state['seg_processed'] ?? 0 );
		$processed     = (int) ( $state['processed'] ?? $state['imported'] ?? 0 );
		$updated       = (int) ( $state['updated'] ?? $state['run'] ?? $now );

		if ( 'sweeping' === $phase ) {
			$percent = 100;
		} elseif ( $seg_total > 0 ) {
			$percent = (int) floor( ( $seg + min( 1.0, $seg_processed / $seg_total ) ) / $seg_count * 100 );
		} elseif ( $last_page > 0 ) {
			$percent = (int) floor( ( $seg + ( ( $page - 1 ) + $offset / 100 ) / $last_page ) / $seg_count * 100 );
		} else {
			$percent = null;
		}

		if ( null !== $percent ) {
			$percent = max( 0, min( 100, $percent ) );
		}

		if ( 'sweeping' === $phase ) {
			$summary = __( 'Finishing: checking for plans that disappeared from the API…', 'nextsim-woo' );
		} else {
			$parts = array();

			if ( $seg_count > 1 ) {
				/* translators: 1: current segment number, 2: number of segments. */
				$parts[] = sprintf( __( 'Segment %1$d/%2$d', 'nextsim-woo' ), $seg + 1, $seg_count );
			}

			if ( $last_page > 0 ) {
				/* translators: 1: current page, 2: number of pages. */
				$parts[] = sprintf( __( 'Page %1$d/%2$d', 'nextsim-woo' ), min( $page, $last_page ), $last_page );
			}

			if ( $seg_total > 0 ) {
				/* translators: 1: plans processed, 2: total plans. */
				$parts[] = sprintf( __( '%1$s / %2$s plans', 'nextsim-woo' ), number_format_i18n( $seg_processed ), number_format_i18n( $seg_total ) );
			} elseif ( $processed > 0 ) {
				/* translators: %s: plans processed. */
				$parts[] = sprintf( __( '%s plans', 'nextsim-woo' ), number_format_i18n( $processed ) );
			}

			if ( null !== $percent ) {
				$parts[] = $percent . '%';
			}

			$summary = array() === $parts ? __( 'Starting…', 'nextsim-woo' ) : implode( ' · ', $parts );
		}

		return $this->progress_shape(
			array(
				'status'        => 'running',
				'phase'         => $phase,
				'percent'       => $percent,
				'seg'           => $seg,
				'seg_count'     => $seg_count,
				'page'          => $page,
				'last_page'     => $last_page,
				'total'         => $seg_total,
				'processed'     => $processed,
				'seg_processed' => $seg_processed,
				'created'       => (int) ( $state['created'] ?? 0 ),
				'updated_items' => (int) ( $state['updated_items'] ?? 0 ),
				'skipped'       => (int) ( $state['skipped'] ?? 0 ),
				'failed_items'  => (int) ( $state['failed_items'] ?? 0 ),
				'started'       => (int) ( $state['started'] ?? $state['run'] ?? 0 ),
				'updated'       => $updated,
				'elapsed'       => max( 0, $now - (int) ( $state['started'] ?? $state['run'] ?? $now ) ),
				'stalled'       => ( $now - $updated ) > self::STALL_AFTER,
				'summary'       => $summary,
			)
		);
	}

	/**
	 * @param array<string, mixed> $last
	 * @return array<string, mixed>
	 */
	private function progress_from_result( array $last ): array {
		$status    = (string) ( $last['status'] ?? 'ok' );
		$processed = (int) ( $last['processed'] ?? 0 );
		$message   = (string) ( $last['message'] ?? '' );

		if ( 'ok' === $status ) {
			/* translators: 1: plans processed, 2: created, 3: updated, 4: unchanged. */
			$summary = sprintf( __( 'Last sync completed: %1$s plans (%2$s new, %3$s updated, %4$s unchanged).', 'nextsim-woo' ), number_format_i18n( $processed ), number_format_i18n( (int) ( $last['created'] ?? 0 ) ), number_format_i18n( (int) ( $last['updated_items'] ?? 0 ) ), number_format_i18n( (int) ( $last['skipped'] ?? 0 ) ) );
			$ui      = 'done';
		} elseif ( 'cancelled' === $status ) {
			/* translators: %s: plans processed before the cancel. */
			$summary = sprintf( __( 'Last sync was cancelled after %s plans.', 'nextsim-woo' ), number_format_i18n( $processed ) );
			$ui      = 'cancelled';
		} else {
			$summary = __( 'Last sync did not complete successfully.', 'nextsim-woo' ) . ( '' !== $message ? ' ' . $message : '' );
			$ui      = 'error';
		}

		$started  = (int) ( $last['started'] ?? 0 );
		$finished = (int) ( $last['finished'] ?? 0 );

		return $this->progress_shape(
			array(
				'status'        => $ui,
				'percent'       => 'ok' === $status ? 100 : null,
				'processed'     => $processed,
				'created'       => (int) ( $last['created'] ?? 0 ),
				'updated_items' => (int) ( $last['updated_items'] ?? 0 ),
				'skipped'       => (int) ( $last['skipped'] ?? 0 ),
				'failed_items'  => (int) ( $last['failed_items'] ?? 0 ),
				'swept'         => (int) ( $last['swept'] ?? 0 ),
				'started'       => $started,
				'finished'      => $finished,
				'elapsed'       => ( $started > 0 && $finished >= $started ) ? $finished - $started : null,
				'message'       => $message,
				'summary'       => $summary,
			)
		);
	}

	/**
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	private function progress_shape( array $values ): array {
		return $values + array(
			'status'        => 'idle',
			'phase'         => null,
			'percent'       => null,
			'seg'           => 0,
			'seg_count'     => 0,
			'page'          => 0,
			'last_page'     => 0,
			'total'         => 0,
			'processed'     => 0,
			'seg_processed' => 0,
			'created'       => 0,
			'updated_items' => 0,
			'skipped'       => 0,
			'failed_items'  => 0,
			'swept'         => 0,
			'started'       => null,
			'updated'       => null,
			'finished'      => null,
			'elapsed'       => null,
			'stalled'       => false,
			'message'       => '',
			'summary'       => '',
		);
	}

	/**
	 * Mark products whose plan disappeared from the API this run as out of stock —
	 * they stay in the catalog but can no longer be purchased, and are restored
	 * automatically when the plan reappears. Only products that carry a nextSIM
	 * package id are ever touched.
	 *
	 * @return int Number of products taken out of stock.
	 */
	private function sweep_orphans( int $run ): int {
		$swept = 0;

		$this->begin_bulk();
		try {
			foreach ( $this->repository->find_orphans_synced_before( $run ) as $product_id ) {
				if ( $this->mark_out_of_stock( (int) $product_id ) ) {
					++$swept;
				}
			}
		} finally {
			$this->end_bulk();
		}

		if ( $swept > 0 ) {
			$this->logger->info( 'Orphan sweep marked products out of stock', array( 'count' => $swept ) );
		}

		return $swept;
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
