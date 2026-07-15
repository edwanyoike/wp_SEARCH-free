<?php
declare(strict_types=1);

/**
 * Core Indexing Engine using Action Scheduler.
 *
 * @package WP_Fast_Search
 */

namespace WCS\Search;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Indexer {

	/**
	 * Default batch size — used as fallback when resource sampling is unavailable.
	 */
	private const BATCH_SIZE = 50;

	/**
	 * Minimum and maximum batch sizes when dynamic sizing is active.
	 * Products are indexed in bulk (a handful of queries per chunk, not ~15 per
	 * product), so 200 products complete in a few seconds. The time budget
	 * below — not the batch size — is the FPM-timeout guard.
	 */
	private const BATCH_MIN = 10;
	private const BATCH_MAX = 200;

	/**
	 * Chunk size for bulk index writes within a batch. The time budget is
	 * checked between chunks, so this bounds how far a batch can overshoot it.
	 */
	private const BULK_CHUNK = 50;

	/**
	 * Time budget per batch in seconds. Must be comfortably below the server's
	 * PHP-FPM request_terminate_timeout (commonly 180 s) so we can enqueue the
	 * next batch before FPM sends SIGTERM and silently kills the process.
	 * Override for stricter hosts: add_filter( 'wcs_batch_time_budget', fn() => 45 );
	 */
	private const BATCH_TIME_BUDGET = 120;

	/**
	 * Free edition: maximum number of products kept in the live search
	 * index. Existing indexed products keep updating normally; products
	 * beyond the cap are simply never indexed (they don't appear in search
	 * results) until upgrading to Pro, which has no limit.
	 */
	const FREE_PRODUCT_CAP = 100;

	/**
	 * Product IDs already queued for update within this request, keyed by ID.
	 * Prevents multiple as_has_scheduled_action() DB queries for the same product
	 * when several hooks fire on the same product save.
	 *
	 * @var array<int, true>
	 */
	private static array $queued_ids = [];

	/**
	 * Whether a cache-bust action has already been scheduled this request.
	 */
	private static bool $bust_queued = false;

	/**
	 * Whether a full rebuild has already been scheduled this request.
	 * Prevents multiple settings changes in one form submission from
	 * queuing duplicate wcs_rebuild_index_batch actions.
	 */
	private static bool $rebuild_queued = false;

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		// Block outbound HTTP for the entire request when Action Scheduler is running
		// an async queue — this fires at plugins_loaded, before admin_init, so it
		// intercepts WordPress's background update checkers and plugin HTTP calls
		// before they can steal the FPM time budget.
		if ( wp_doing_ajax() && isset( $_POST['action'] ) && 'as_async_request_queue_runner' === sanitize_key( wp_unslash( $_POST['action'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			add_filter( 'pre_http_request', array( __CLASS__, 'block_http_during_batch' ), PHP_INT_MAX, 3 );
		}

		// ── Live product save / update hooks ─────────────────────────────────
		// These queue an async Action Scheduler job so the indexer never blocks
		// the saving request (admin or REST).
		add_action( 'woocommerce_update_product', array( __CLASS__, 'queue_product_update' ), 10, 1 );
		add_action( 'save_post_product', array( __CLASS__, 'queue_product_update_from_post' ), 10, 2 );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'queue_product_update' ), 10, 1 );

		// ── WooCommerce CSV importer hooks ────────────────────────────────────
		// The built-in WC importer does not fire woocommerce_update_product, so
		// without these hooks bulk-imported products are invisible until the next
		// manual rebuild. Each product is queued individually for an incremental
		// index update — no full rebuild triggered.
		add_action( 'woocommerce_product_import_inserted_product_object', array( __CLASS__, 'queue_product_update_from_import' ), 10, 1 );
		add_action( 'woocommerce_product_import_updated_product_object',  array( __CLASS__, 'queue_product_update_from_import' ), 10, 1 );

		// ── Event-driven delete hooks (Recommendation 3) ──────────────────────
		// Remove the product from the index immediately — no batch cycle needed.
		// wp_trash_post fires when a post is moved to the Trash.
		// before_delete_post fires just before a post is permanently deleted.
		add_action( 'wp_trash_post', array( __CLASS__, 'on_product_trash' ), 10, 1 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_product_delete' ), 10, 1 );

		// ── Action Scheduler hooks ────────────────────────────────────────────
		add_action( 'wcs_rebuild_index_batch', array( __CLASS__, 'process_batch' ), 10, 2 );
		add_action( 'wcs_optimize_index',      array( __CLASS__, 'run_optimize' ) );
		add_action( 'wcs_update_single_product', array( __CLASS__, 'index_single_product' ), 10, 1 );
		add_action( 'wcs_debounce_cache_bust', array( __CLASS__, 'execute_cache_bust' ) );
		// Reset the indexing flag when AS marks a rebuild batch as permanently failed
		// so the UI never stays stuck in "Indexing..." with no running job behind it.
		add_action( 'action_scheduler_failed_action', array( __CLASS__, 'on_batch_action_failed' ), 10, 1 );

		// ── Product taxonomy changes ──────────────────────────────────────────
		// Renaming a category or tag makes the stored term name stale in every
		// product's content column. Queue all products in that term for an
		// incremental reindex so the new name is searchable immediately.
		add_action( 'edited_term', array( __CLASS__, 'on_term_edited' ), 10, 3 );

		// ── Index field settings changes ──────────────────────────────────────
		// Toggling wcs_search_title/sku/content/taxonomy changes which fields
		// are written into the index. Every existing row is built under the old
		// config, so a full rebuild is required to reflect the new structure.
		foreach ( array( 'wcs_search_title', 'wcs_search_sku', 'wcs_search_content', 'wcs_search_taxonomy' ) as $opt ) {
			add_action( "update_option_{$opt}", array( __CLASS__, 'on_index_field_setting_changed' ), 10, 2 );
		}

		// ── Scheduled sale prices (WC 6 and older safety net) ─────────────────
		// WC 7+ calls $product->save() during wc_scheduled_sales(), which fires
		// woocommerce_update_product and is already covered. On older installs
		// that wrote _price meta directly, this hook re-queues on-sale products
		// so price_min/price_max do not stay stale after a scheduled sale fires.
		add_action( 'woocommerce_scheduled_sales', array( __CLASS__, 'on_scheduled_sales' ) );

		// ── Synonym changes ──────────────────────────────────────────────────
		// Synonyms are applied at query time (no index data changes), so no
		// rebuild is needed — but cached results were computed under the old
		// synonym config, so the cache version is bumped immediately.
		add_action( 'update_option_wcs_synonyms', array( __CLASS__, 'on_synonyms_changed' ), 10, 2 );

		// ── WP-Cron GC ───────────────────────────────────────────────────────
		add_action( 'wcs_daily_transient_gc', array( __CLASS__, 'run_transient_gc' ) );
	}

	/**
	 * Bust the result cache when the synonym configuration changes.
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $new_value New option value.
	 */
	public static function on_synonyms_changed( $old_value, $new_value ): void {
		if ( $old_value === $new_value ) {
			return;
		}
		Query_Normalizer::flush_synonym_cache();
		self::execute_cache_bust();
	}

	/**
	 * Queue single product for update to prevent webhook blocking.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function queue_product_update( int $product_id ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		// Short-circuit within the same request without a DB query — multiple hooks
		// (woocommerce_update_product, save_post_product, etc.) can fire for the
		// same product in one request; only the first needs to check Action Scheduler.
		if ( isset( self::$queued_ids[ $product_id ] ) ) {
			return;
		}
		self::$queued_ids[ $product_id ] = true;

		if ( ! as_has_scheduled_action( 'wcs_update_single_product', array( 'product_id' => $product_id ) ) ) {
			as_enqueue_async_action( 'wcs_update_single_product', array( 'product_id' => $product_id ), 'turbo-search-for-woocommerce' );
		}
	}

	/**
	 * Queue product update from post save.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public static function queue_product_update_from_post( int $post_id, \WP_Post $post ): void {
		if ( 'product' === $post->post_type ) {
			self::queue_product_update( $post_id );
		}
	}

	/**
	 * Queue a single product for incremental re-indexing after a CSV import row.
	 *
	 * Handles woocommerce_product_import_inserted_product_object and
	 * woocommerce_product_import_updated_product_object. Both hooks pass the
	 * fully-saved WC_Product object as the first argument, so we can queue the
	 * product by ID without any additional DB lookup.
	 *
	 * @param \WC_Product $product Imported product object.
	 */
	public static function queue_product_update_from_import( \WC_Product $product ): void {
		self::queue_product_update( $product->get_id() );
	}

	/**
	 * Event-driven handler: product trashed → remove from search index immediately.
	 *
	 * Fires on the wp_trash_post hook. Does NOT wait for the next batch rebuild.
	 * If a full reindex is in progress the row is also removed from the staging
	 * table via delete_single_product().
	 *
	 * @param int $post_id Post ID being trashed.
	 */
	public static function on_product_trash( int $post_id ): void {
		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}
		self::delete_single_product( $post_id );
	}

	/**
	 * Event-driven handler: product permanently deleted → remove from search index.
	 *
	 * Fires on the before_delete_post hook (before WP removes the post row so
	 * get_post_type() still works).
	 *
	 * @param int $post_id Post ID being permanently deleted.
	 */
	public static function on_product_delete( int $post_id ): void {
		if ( 'product' !== get_post_type( $post_id ) ) {
			return;
		}
		self::delete_single_product( $post_id );
	}

	/**
	 * Called by Action Scheduler when a batch action is permanently failed.
	 * Resets wcs_is_indexing so the UI does not stay stuck in "Indexing..." forever.
	 *
	 * @param int $action_id AS action ID.
	 */
	public static function on_batch_action_failed( int $action_id ): void {
		if ( ! class_exists( '\ActionScheduler' ) ) {
			return;
		}
		try {
			$action = \ActionScheduler::store()->fetch_action( $action_id );
			if ( ! ( $action instanceof \ActionScheduler_Action ) || 'wcs_rebuild_index_batch' !== $action->get_hook() ) {
				return;
			}

			$args    = $action->get_args();
			$last_id = (int) ( $args['last_id'] ?? 0 );
			$epoch   = (int) ( $args['epoch'] ?? 0 );

			// If this batch belongs to a superseded rebuild, just drop it.
			$current_epoch = (int) get_option( 'wcs_rebuild_epoch', 0 );
			if ( $epoch !== $current_epoch ) {
				self::log( sprintf( 'FAIL ignored — stale epoch=%d (current=%d) last_id=%d', $epoch, $current_epoch, $last_id ) );
				return;
			}

			self::log( sprintf( 'FAIL last_id=%d epoch=%d — scheduling retry', $last_id, $epoch ) );

			// Auto-retry once per cursor per epoch. Including the epoch prevents a
			// failed batch from a previous rebuild poisoning the retry slot for the
			// same cursor position in a future rebuild run.
			$retry_key       = 'wcs_batch_retry_' . $epoch . '_' . $last_id;
			$already_retried = (bool) get_transient( $retry_key );

			if ( ! $already_retried && function_exists( 'as_enqueue_async_action' ) ) {
				set_transient( $retry_key, 1, HOUR_IN_SECONDS );
				as_enqueue_async_action( 'wcs_rebuild_index_batch', array( 'last_id' => $last_id, 'epoch' => $epoch ), 'turbo-search-for-woocommerce', 0, true );
			} else {
				self::log( sprintf( 'FAIL retry exhausted last_id=%d — halting', $last_id ) );
				update_option( 'wcs_is_indexing', 0, false );
			}
		} catch ( \Throwable $e ) {
			update_option( 'wcs_is_indexing', 0, false );
		}
	}

	/**
	 * Process a full batch of products using cursor-based pagination.
	 *
	 * Uses the last processed product ID as a cursor instead of an offset so
	 * that concurrent product saves during a reindex do not cause rows to be
	 * skipped or indexed twice (a common failure with LIMIT … OFFSET on an
	 * actively written table).
	 *
	 * @param int $last_id Highest product ID processed so far (0 to start).
	 */
	/**
	 * Blocks external HTTP during a batch to prevent WordPress's update checkers
	 * and plugin API calls from consuming the FPM time budget.
	 * Loopback requests (Action Scheduler async dispatch, same-site REST) are
	 * allowed through so the queue chain keeps firing immediately.
	 *
	 * @param mixed  $preempt Existing pre-empt value.
	 * @param mixed  $args    Request args (unused).
	 * @param string $url     Request URL.
	 * @return mixed Original $preempt for loopback; WP_Error for external URLs.
	 */
	public static function block_http_during_batch( $preempt, $args, string $url ) {
		if ( str_starts_with( $url, home_url() ) || str_starts_with( $url, site_url() ) ) {
			return $preempt; // allow loopback — AS needs this to dispatch the next batch
		}
		return new \WP_Error( 'wcs_http_blocked', 'External HTTP blocked during index batch' );
	}

	public static function process_batch( int $last_id = 0, int $epoch = 0 ): void {
		add_filter( 'pre_http_request', array( __CLASS__, 'block_http_during_batch' ), PHP_INT_MAX, 3 );
		try {
			self::do_process_batch( $last_id, $epoch );
		} catch ( \Throwable $e ) {
			// Do NOT clear wcs_is_indexing here — on_batch_action_failed fires next
			// and will either schedule a retry (keeping the flag at 1) or give up and
			// clear it. Clearing here would show "Idle" while the retry is in flight.
			self::log( sprintf( 'FATAL last_id=%d epoch=%d — %s', $last_id, $epoch, $e->getMessage() ) );
			throw $e;
		} finally {
			remove_filter( 'pre_http_request', array( __CLASS__, 'block_http_during_batch' ), PHP_INT_MAX );
		}
	}

	/**
	 * Runs OPTIMIZE TABLE on the live index after a rebuild swap.
	 * Dispatched as a separate AS async action so it never runs inside the
	 * final batch request — on large tables it can exceed FPM's kill timeout.
	 */
	public static function run_optimize(): void {
		global $wpdb;
		$main_table = $wpdb->prefix . 'wcs_search_index';
		self::log( 'OPTIMIZE start' );
		$wpdb->query( $wpdb->prepare( 'OPTIMIZE TABLE %i', $main_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		self::log( 'OPTIMIZE done' );
		delete_option( 'wcs_rebuild_phase' );
	}

	private static function log( string $message ): void {
		// Routed through wc_get_logger() (source: turbo-search-for-woocommerce):
		// WooCommerce log files are visible under WooCommerce → Status → Logs
		// and rotate automatically, unlike raw error_log() spam in php error logs.
		Logger::log( $message, 'info' );
	}

	private static function do_process_batch( int $last_id, int $epoch ): void {
		global $wpdb;

		// Stale-chain guard: if this batch belongs to a previous rebuild (e.g. an
		// auto-retry or a schedule_full_rebuild() that ran mid-chain), abort silently
		// rather than racing the current rebuild and triggering a premature swap.
		$current_epoch = (int) get_option( 'wcs_rebuild_epoch', 0 );
		if ( $epoch !== $current_epoch ) {
			self::log( sprintf( 'Dropping stale batch last_id=%d epoch=%d (current=%d)', $last_id, $epoch, $current_epoch ) );
			return;
		}

		$batch_start = microtime( true );
		self::log( sprintf( 'START last_id=%d epoch=%d', $last_id, $epoch ) );
		// Track cursor and phase so the admin status endpoint can show meaningful
		// progress messages without doing a separate DB query per poll.
		update_option( 'wcs_rebuild_cursor', $last_id, false );
		update_option( 'wcs_rebuild_phase', 'batching', false );

		// Fetch the next batch of published product IDs strictly after $last_id.
		// Direct SQL is used because wc_get_products() does not expose a
		// "WHERE ID > ?" cursor; only offset-based pagination is available there.
		//
		// Free edition cap: never fetch more than the remaining capacity, so a
		// rebuild on a catalog bigger than FREE_PRODUCT_CAP simply stops filling
		// (LIMIT 0 below yields an empty $products, which the "no more rows"
		// branch already treats as "rebuild complete" — no other change needed
		// to finish and swap in whatever got indexed).
		$batch_size    = self::dynamic_batch_size();
		$already_total = (int) get_option( 'wcs_reindex_processed', 0 );
		$remaining_cap = max( 0, self::FREE_PRODUCT_CAP - $already_total );
		$fetch_limit   = min( $batch_size, $remaining_cap );
		$products      = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type = 'product'
			   AND post_status = 'publish'
			   AND ID > %d
			 ORDER BY ID ASC
			 LIMIT %d",
			$last_id,
			$fetch_limit
		) );

		$main_table  = $wpdb->prefix . 'wcs_search_index';
		$stage_table = $wpdb->prefix . 'wcs_search_index_stage';

		// Guard: if the staging table was dropped (e.g. by a concurrent rebuild that
		// already finished and swapped), stop the chain rather than looping forever.
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $stage_table ) ) !== $stage_table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::log( sprintf( 'ABORT last_id=%d — staging table missing', $last_id ) );
			update_option( 'wcs_is_indexing', 0, false );
			return;
		}

		if ( empty( $products ) ) {
			$old_table   = $wpdb->prefix . 'wcs_search_index_old';
			// SELECT 1 LIMIT 1 is O(1) — sufficient to guard against an empty staging
			// table without paying the cost of a full COUNT(*) scan on large catalogs.
			$stage_has_rows = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', $stage_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			self::log( sprintf( 'SWAP last_id=%d epoch=%d stage_has_rows=%d', $last_id, $epoch, (int) $stage_has_rows ) );

			if ( $stage_has_rows ) {
				update_option( 'wcs_rebuild_phase', 'swapping', false );
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $old_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i, %i TO %i', $main_table, $old_table, $stage_table, $main_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $old_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

				// Swap the typo-correction vocabulary alongside the index. Guarded:
				// the staging table may not exist mid-upgrade from a pre-1.7 chain.
				$terms_table       = $wpdb->prefix . 'wcs_search_terms';
				$terms_stage_table = $wpdb->prefix . 'wcs_search_terms_stage';
				$terms_old_table   = $wpdb->prefix . 'wcs_search_terms_old';
				if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $terms_stage_table ) ) === $terms_stage_table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $terms_old_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
					$wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i, %i TO %i', $terms_table, $terms_old_table, $terms_stage_table, $terms_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
					$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $terms_old_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
				}

				// OPTIMIZE TABLE can take minutes on large catalogs — dispatch it as a
				// separate async action so it never runs inside this FPM request.
				update_option( 'wcs_rebuild_phase', 'optimizing', false );
				if ( function_exists( 'as_enqueue_async_action' ) ) {
					as_enqueue_async_action( 'wcs_optimize_index', array(), 'turbo-search-for-woocommerce', 0, true );
				}
			} else {
				// Staging table existed but was empty — this means the rebuild produced
				// no rows (all products draft/private, or staging was truncated mid-run).
				// Keep the old live index in place and surface a recoverable error state.
				self::log( 'SWAP aborted — staging table is empty; old live index preserved' );
				update_option( 'wcs_last_rebuild_error', 'staging_empty', false );
				update_option( 'wcs_is_indexing', 0, false );
				delete_option( 'wcs_rebuild_phase' );
				return;
			}

			update_option( 'wcs_is_indexing', 0, false );
			delete_option( 'wcs_rebuild_phase' );
			delete_option( 'wcs_last_rebuild_error' );
			self::update_cap_reached_flag();
			self::execute_cache_bust();
			do_action( 'wcs_index_rebuild_complete' );
			return;
		}

		$time_budget = (int) apply_filters( 'wcs_batch_time_budget', self::BATCH_TIME_BUDGET );

		// Index in bulk chunks. Each chunk is a handful of queries (cache
		// priming + one meta_lookup read + one multi-row REPLACE) instead of
		// ~15 queries per product. If a chunk's bulk write fails, fall back to
		// the per-product path for that chunk so one bad row cannot sink the
		// other 49.
		$batch_failures     = 0;
		$processed_in_batch = 0;
		foreach ( array_chunk( array_map( 'intval', $products ), self::BULK_CHUNK ) as $chunk ) {
			try {
				self::index_products_bulk( $chunk, $stage_table );
			} catch ( \Throwable $bulk_e ) {
				self::log( sprintf( 'bulk chunk failed (%s) — retrying per-product', $bulk_e->getMessage() ) );
				foreach ( $chunk as $product_id ) {
					try {
						self::do_index_single_product( $product_id, $stage_table );
					} catch ( \Throwable $e ) {
						++$batch_failures;
						self::log( sprintf( 'product %d failed — %s', $product_id, $e->getMessage() ) );
					}
				}
			}
			$processed_in_batch += count( $chunk );
			$chunk_last_id       = (int) end( $chunk );

			if ( ( microtime( true ) - $batch_start ) >= $time_budget ) {
				$processed = (int) get_option( 'wcs_reindex_processed', 0 );
				update_option( 'wcs_reindex_processed', $processed + $processed_in_batch, false );
				self::log( sprintf( 'BUDGET last_id=%d done=%d elapsed=%.1fs', $chunk_last_id, $processed + $processed_in_batch, microtime( true ) - $batch_start ) );
				if ( function_exists( 'as_enqueue_async_action' ) ) {
					as_enqueue_async_action( 'wcs_rebuild_index_batch', array( 'last_id' => $chunk_last_id, 'epoch' => $epoch ), 'turbo-search-for-woocommerce', 0, true );
				}
				return;
			}
		}

		if ( $batch_failures === $processed_in_batch ) {
			self::log( sprintf( 'ALL FAILED last_id=%d — halting chain', $last_id ) );
			update_option( 'wcs_is_indexing', 0, false );
			return;
		}

		$processed    = (int) get_option( 'wcs_reindex_processed', 0 );
		$new_total    = $processed + $processed_in_batch;
		$next_last_id = (int) end( $products );
		update_option( 'wcs_reindex_processed', $new_total, false );
		self::log( sprintf( 'DONE last_id=%d next=%d total=%d elapsed=%.1fs', $last_id, $next_last_id, $new_total, microtime( true ) - $batch_start ) );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'wcs_rebuild_index_batch', array( 'last_id' => $next_last_id, 'epoch' => $epoch ), 'turbo-search-for-woocommerce', 0, true );
		}
	}

	/**
	 * Number of rows currently in the live search index table.
	 */
	private static function live_index_count(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'wcs_search_index';
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Refresh the flag the admin UI reads to show the "upgrade to Pro for
	 * unlimited products" notice — true whenever the catalog has more
	 * published products than this edition indexes.
	 */
	private static function update_cap_reached_flag(): void {
		$counts = wp_count_posts( 'product' );
		$total  = isset( $counts->publish ) ? (int) $counts->publish : 0;
		if ( $total > self::FREE_PRODUCT_CAP ) {
			update_option( 'wcs_free_cap_reached', 1, false );
		} else {
			delete_option( 'wcs_free_cap_reached' );
		}
	}

	/**
	 * Index a single product. Action Scheduler hook callback — receives only product_id.
	 *
	 * @param int $product_id Product ID.
	 */
	public static function index_single_product( int $product_id ): void {
		self::do_index_single_product( $product_id, '' );
	}

	/**
	 * Core implementation: index a product into the specified table.
	 * When $table_name is empty the live index table is used (plus staging if a
	 * full rebuild is active). Called directly by process_batch() with the
	 * staging table so that the public API cannot be used to write to arbitrary tables.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $table_name Target table. Empty = auto-select live (+ staging).
	 */
	private static function do_index_single_product( int $product_id, string $table_name ): void {
		$product = wc_get_product( $product_id );
		if ( ! $product || 'publish' !== $product->get_status() ) {
			self::delete_single_product( $product_id, $table_name );
			return;
		}

		// Skip variations directly; they are handled by parent
		if ( $product->is_type( 'variation' ) ) {
			$parent_id = $product->get_parent_id();
			if ( $parent_id ) {
				self::queue_product_update( $parent_id );
			}
			return;
		}

		global $wpdb;

		$price_min = 0.00;
		$price_max = 0.00;

		if ( $product->is_type( 'variable' ) ) {
			// get_variation_price() loads every variation object — O(N) on large catalogs.
			// A direct aggregate query is orders of magnitude faster for products with
			// many variations and returns the same min/max _price values.
			$prices = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"SELECT MIN(pm.meta_value+0) AS price_min, MAX(pm.meta_value+0) AS price_max
				 FROM {$wpdb->postmeta} pm
				 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				 WHERE p.post_parent = %d
				   AND p.post_type   = 'product_variation'
				   AND p.post_status = 'publish'
				   AND pm.meta_key   = '_price'
				   AND pm.meta_value != ''",
				$product_id
			) );
			$price_min = $prices ? (float) $prices->price_min : 0.00;
			$price_max = $prices ? (float) $prices->price_max : 0.00;
		} else {
			$price_min = (float) $product->get_price();
			$price_max = $price_min;
		}

		// No placeholder URL is stored — theme placeholders change on theme
		// switch and would go stale in the index. The frontend JS renders its
		// own neutral placeholder when image_url is empty.
		$image_id  = $product->get_image_id();
		$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
		$image_url = $image_url ? $image_url : '';

		// Read weighted configuration
		$search_title    = (bool) get_option( 'wcs_search_title', 1 );
		$search_sku      = (bool) get_option( 'wcs_search_sku', 1 );
		$search_content  = (bool) get_option( 'wcs_search_content', 1 );
		$search_taxonomy = (bool) get_option( 'wcs_search_taxonomy', 1 );

		$title_val = $search_title ? wp_strip_all_tags( $product->get_title() ) : '';
		$sku_val   = $search_sku ? $product->get_sku() : '';

		// Gather taxonomy terms if enabled — categories, tags, brands, and
		// global attribute terms (see indexed_taxonomies()).
		$terms_string = '';
		if ( $search_taxonomy ) {
			$terms        = wp_get_post_terms( $product_id, self::indexed_taxonomies(), array( 'fields' => 'names' ) );
			$terms_string = ( ! is_wp_error( $terms ) && is_array( $terms ) ) ? implode( ' ', $terms ) : '';
		}

		// Variation SKUs go into content so searching a child SKU finds the parent.
		$variation_skus = '';
		if ( $search_sku && $product->is_type( 'variable' ) ) {
			$sku_map        = self::get_variation_skus( array( $product_id ) );
			$variation_skus = $sku_map[ $product_id ] ?? '';
		}

		$desc    = $search_content ? $product->get_short_description() : '';
		$content = trim( $desc . ' ' . $terms_string . ' ' . $variation_skus );

		$data = array(
			'product_id'     => $product_id,
			'title'          => $title_val,
			'sku'            => $sku_val,
			'sku_normalized' => Query_Normalizer::normalize_sku( $sku_val ),
			'content'        => wp_strip_all_tags( $content ),
			'excerpt'        => self::make_excerpt( $desc ),
			'price_min'      => $price_min,
			'price_max'      => $price_max,
			'stock_status'   => $product->get_stock_status(),
			'total_sales'    => (int) $product->get_total_sales(),
			'sales_30d'      => self::get_sales_30d( array( $product_id ) )[ $product_id ] ?? 0,
			'image_url'      => $image_url,
			'permalink'      => $product->get_permalink(),
			'updated_at'     => current_time( 'mysql' ),
		);

		$data    = self::apply_row_filter_and_sanitize( $data, $product_id );
		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%d', '%d', '%s', '%s', '%s' );

		if ( empty( $table_name ) ) {
			$table_name = $wpdb->prefix . 'wcs_search_index';

			// Free edition cap: a live create/update hook (product saved, imported,
			// etc.) firing outside a full rebuild must not let the live index grow
			// past the cap one product at a time. Updating an already-indexed
			// product is always allowed; only a brand-new row is blocked.
			$already_indexed = (bool) $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"SELECT 1 FROM {$table_name} WHERE product_id = %d",
				$product_id
			) );
			if ( ! $already_indexed && self::live_index_count() >= self::FREE_PRODUCT_CAP ) {
				update_option( 'wcs_free_cap_reached', 1, false );
				return;
			}

			// If a full rebuild is active, also duplicate live edits to staging to maintain parity
			if ( get_option( 'wcs_is_indexing', false ) ) {
				$stage_table = $wpdb->prefix . 'wcs_search_index_stage';
				$wpdb->replace( $stage_table, $data, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}

		$wpdb->replace( $table_name, $data, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		self::trigger_cache_bust();
	}

	/**
	 * Taxonomies whose term names are written into the searchable content field.
	 *
	 * Defaults cover categories, tags, the core brand taxonomy, and every
	 * global attribute taxonomy (pa_color, pa_material, …) so searches like
	 * "leather" or a brand name match. Filterable:
	 *
	 *   add_filter( 'wcs_indexed_taxonomies', fn( $tax ) => array_diff( $tax, array( 'product_tag' ) ) );
	 *
	 * @return string[] Registered taxonomy slugs.
	 */
	private static function indexed_taxonomies(): array {
		$taxonomies = array( 'product_cat', 'product_tag', 'product_brand' );
		if ( function_exists( 'wc_get_attribute_taxonomy_names' ) ) {
			$taxonomies = array_merge( $taxonomies, wc_get_attribute_taxonomy_names() );
		}

		/**
		 * Filters which taxonomies are indexed into the searchable content field.
		 *
		 * @param string[] $taxonomies Taxonomy slugs.
		 */
		$taxonomies = (array) apply_filters( 'wcs_indexed_taxonomies', $taxonomies );

		return array_values( array_filter( array_unique( $taxonomies ), 'taxonomy_exists' ) );
	}

	/**
	 * Fetch published variation SKUs for a set of parent product IDs.
	 *
	 * Variation SKUs are appended to the searchable content field so that
	 * searching a child SKU finds the parent product. One indexed query for
	 * the whole set — no per-variation object loads.
	 *
	 * @param int[] $parent_ids Parent product IDs.
	 * @return array<int, string> Map of parent ID → space-separated variation SKUs.
	 */
	private static function get_variation_skus( array $parent_ids ): array {
		global $wpdb;

		$parent_ids = array_map( 'intval', $parent_ids );
		if ( empty( $parent_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $parent_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is built from %d placeholders only
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT p.post_parent AS parent_id, ml.sku
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->prefix}wc_product_meta_lookup ml ON ml.product_id = p.ID
			 WHERE p.post_parent IN ({$placeholders})
			   AND p.post_type   = 'product_variation'
			   AND p.post_status = 'publish'
			   AND ml.sku       != ''",
			...$parent_ids
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		$map = array();
		foreach ( (array) $rows as $row ) {
			$parent         = (int) $row->parent_id;
			$map[ $parent ] = isset( $map[ $parent ] ) ? $map[ $parent ] . ' ' . $row->sku : (string) $row->sku;
		}
		return $map;
	}

	/**
	 * Trim raw description text to a clean, display-ready excerpt.
	 *
	 * Deliberately separate from the `content` column: content is a search-only
	 * blob (description + taxonomy terms + variation SKUs concatenated) that
	 * would look like word salad if shown to a shopper. This returns just the
	 * description, tag-stripped and cut at a whole-word boundary.
	 *
	 * @param string $text  Raw short description.
	 * @param int    $max_len Maximum character length before truncation.
	 * @return string
	 */
	private static function make_excerpt( string $text, int $max_len = 150 ): string {
		$text = trim( wp_strip_all_tags( $text ) );
		if ( '' === $text || mb_strlen( $text, 'UTF-8' ) <= $max_len ) {
			return $text;
		}

		$truncated  = mb_substr( $text, 0, $max_len, 'UTF-8' );
		$last_space = mb_strrpos( $truncated, ' ', 0, 'UTF-8' );
		if ( false !== $last_space && $last_space > 0 ) {
			$truncated = mb_substr( $truncated, 0, $last_space, 'UTF-8' );
		}
		return $truncated . '…';
	}

	/**
	 * Apply the wcs_indexed_product_data filter and re-sanitize the row.
	 *
	 * Shared by the single-product path and the bulk rebuild path so both
	 * honour the same developer contract and the same post-filter hardening.
	 *
	 * @param array $data       Index row.
	 * @param int   $product_id Product ID.
	 * @return array Sanitized row with exactly the allowed keys.
	 */
	private static function apply_row_filter_and_sanitize( array $data, int $product_id ): array {
		/**
		 * Filters the product data array before it is written to the search index.
		 *
		 * Allows themes and plugins to add, remove, or transform fields. Only the
		 * listed keys are stored — extra keys added by callbacks are stripped after
		 * the filter. URL fields are run through esc_url_raw(), text fields through
		 * wp_strip_all_tags(), and stock_status through sanitize_key() so that a
		 * compromised third-party callback cannot persist malicious markup or URLs.
		 *
		 * @param array $data       Associative array: product_id, title, sku, content,
		 *                          excerpt, price_min, price_max, stock_status,
		 *                          total_sales, sales_30d, image_url, permalink, updated_at.
		 * @param int   $product_id The WooCommerce product ID.
		 */
		$data = (array) apply_filters( 'wcs_indexed_product_data', $data, $product_id );

		// Strip unknown keys and re-sanitize critical columns after filtering.
		// Prevents a compromised third-party plugin from persisting malicious URLs
		// or markup into the search index via this filter. Rebuilt in canonical
		// column order: wpdb->replace() applies $formats positionally, so a
		// filter callback that unset a key must not be able to shift alignment.
		return array(
			'product_id'     => (int) ( $data['product_id'] ?? $product_id ),
			'title'          => wp_strip_all_tags( (string) ( $data['title'] ?? '' ) ),
			'sku'            => (string) ( $data['sku'] ?? '' ),
			'sku_normalized' => Query_Normalizer::normalize_sku( (string) ( $data['sku_normalized'] ?? $data['sku'] ?? '' ) ),
			'content'        => wp_strip_all_tags( (string) ( $data['content'] ?? '' ) ),
			'excerpt'        => self::make_excerpt( (string) ( $data['excerpt'] ?? '' ) ),
			'price_min'      => (float) ( $data['price_min'] ?? 0 ),
			'price_max'      => (float) ( $data['price_max'] ?? 0 ),
			'stock_status'   => sanitize_key( (string) ( $data['stock_status'] ?? '' ) ),
			'total_sales'    => max( 0, (int) ( $data['total_sales'] ?? 0 ) ),
			'sales_30d'      => max( 0, (int) ( $data['sales_30d'] ?? 0 ) ),
			'image_url'      => esc_url_raw( (string) ( $data['image_url'] ?? '' ) ),
			'permalink'      => esc_url_raw( (string) ( $data['permalink'] ?? '' ) ),
			'updated_at'     => (string) ( $data['updated_at'] ?? current_time( 'mysql' ) ),
		);
	}

	/**
	 * Units sold in the last 30 days per product, from WooCommerce's order
	 * lookup table. One aggregate query for the whole set. Values refresh on
	 * every product save and full rebuild — a few days of staleness between
	 * rebuilds is fine for a ranking signal.
	 *
	 * @param int[] $product_ids Product IDs.
	 * @return array<int, int> Map of product ID → units sold (missing = 0).
	 */
	private static function get_sales_30d( array $product_ids ): array {
		global $wpdb;

		$product_ids = array_map( 'intval', $product_ids );
		if ( empty( $product_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
		$suppress     = $wpdb->suppress_errors( true ); // lookup table may not exist on exotic installs
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is built from %d placeholders only
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT product_id, SUM(product_qty) AS qty
			 FROM {$wpdb->prefix}wc_order_product_lookup
			 WHERE product_id IN ({$placeholders})
			   AND date_created >= DATE_SUB(NOW(), INTERVAL 30 DAY)
			 GROUP BY product_id",
			...$product_ids
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->suppress_errors( $suppress );

		$map = array();
		foreach ( (array) $rows as $row ) {
			$map[ (int) $row->product_id ] = (int) $row->qty;
		}
		return $map;
	}

	/**
	 * Upsert vocabulary term counts into the typo-correction sidecar's
	 * staging table (swapped live together with the index).
	 *
	 * @param array<string, int> $term_counts Map of term → occurrences.
	 */
	private static function write_vocabulary( array $term_counts ): void {
		global $wpdb;

		if ( empty( $term_counts ) ) {
			return;
		}

		$values = array();
		$params = array();
		foreach ( $term_counts as $term => $count ) {
			$values[] = '(%s, %d)';
			$params[] = (string) $term;
			$params[] = (int) $count;
		}

		$suppress = $wpdb->suppress_errors( true ); // stage table absent on pre-1.7 installs mid-upgrade
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $values is built from literal placeholders only
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"INSERT INTO {$wpdb->prefix}wcs_search_terms_stage (term, freq) VALUES " . implode( ',', $values ) .
			' ON DUPLICATE KEY UPDATE freq = freq + VALUES(freq)',
			...$params
		) );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$wpdb->suppress_errors( $suppress );
	}

	/**
	 * Index a chunk of products into $table_name using set-based reads.
	 *
	 * Replaces the per-product wc_get_product() path during full rebuilds:
	 * instead of ~15 queries per product it primes the post/term/meta caches
	 * for the whole chunk (3-4 queries), reads price/stock/SKU for every
	 * product from WooCommerce's wc_product_meta_lookup table in one indexed
	 * query (WooCommerce aggregates variable products' min/max into the parent
	 * row, so no variation queries are needed), then writes all rows in one
	 * multi-row REPLACE. Net effect: ~8 queries per 50 products instead of ~750.
	 *
	 * Products missing a lookup row (lookup table mid-regeneration) fall back
	 * to the accurate single-product path.
	 *
	 * @param int[]  $product_ids Chunk of product IDs (post_type=product, publish).
	 * @param string $table_name  Target table (staging during rebuilds).
	 * @throws \RuntimeException When the bulk write fails.
	 */
	private static function index_products_bulk( array $product_ids, string $table_name ): void {
		global $wpdb;

		$product_ids = array_map( 'intval', $product_ids );
		if ( empty( $product_ids ) ) {
			return;
		}

		// Prime post rows, object term caches, and post meta for the whole
		// chunk — everything the loop below reads comes from these caches.
		_prime_post_caches( $product_ids, true, true );

		$ids_placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $ids_placeholders is built from %d placeholders only
		$lookup_rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT product_id, sku, min_price, max_price, stock_status, total_sales
			 FROM {$wpdb->prefix}wc_product_meta_lookup
			 WHERE product_id IN ({$ids_placeholders})",
			...$product_ids
		), OBJECT_K );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$lookup_rows = is_array( $lookup_rows ) ? $lookup_rows : array();

		// Collect thumbnail attachment IDs (from primed meta) and prime those
		// attachments' rows + meta so URL generation below is cache-only.
		$thumb_ids = array();
		foreach ( $product_ids as $pid ) {
			$thumb = (int) get_post_thumbnail_id( $pid );
			if ( $thumb ) {
				$thumb_ids[ $pid ] = $thumb;
			}
		}
		if ( $thumb_ids ) {
			_prime_post_caches( array_values( array_unique( $thumb_ids ) ), false, true );
		}

		$search_title    = (bool) get_option( 'wcs_search_title', 1 );
		$search_sku      = (bool) get_option( 'wcs_search_sku', 1 );
		$search_content  = (bool) get_option( 'wcs_search_content', 1 );
		$search_taxonomy = (bool) get_option( 'wcs_search_taxonomy', 1 );
		$now             = current_time( 'mysql' );
		$taxonomies      = $search_taxonomy ? self::indexed_taxonomies() : array();
		$variation_skus  = $search_sku ? self::get_variation_skus( $product_ids ) : array();
		$sales_30d       = self::get_sales_30d( $product_ids );
		$is_stage        = ( $table_name === $wpdb->prefix . 'wcs_search_index_stage' );
		$vocab           = array();

		$rows = array();
		foreach ( $product_ids as $pid ) {
			$post = get_post( $pid );
			if ( ! $post || 'publish' !== $post->post_status ) {
				self::delete_single_product( $pid, $table_name );
				continue;
			}

			$lookup = $lookup_rows[ $pid ] ?? null;
			if ( ! $lookup ) {
				// No lookup row — rare; use the accurate per-product path.
				self::do_index_single_product( $pid, $table_name );
				continue;
			}

			$terms_string = '';
			if ( $taxonomies ) {
				$names = array();
				foreach ( $taxonomies as $taxonomy ) {
					$terms = get_the_terms( $pid, $taxonomy );
					if ( is_array( $terms ) ) {
						foreach ( $terms as $term ) {
							$names[] = $term->name;
						}
					}
				}
				$terms_string = implode( ' ', $names );
			}

			// Empty when no thumbnail — the frontend JS renders its own neutral
			// placeholder, so theme placeholder URLs never go stale in the index.
			$image_url = '';
			if ( isset( $thumb_ids[ $pid ] ) ) {
				$url       = wp_get_attachment_image_url( $thumb_ids[ $pid ], 'thumbnail' );
				$image_url = $url ? $url : '';
			}

			$desc = $search_content ? $post->post_excerpt : '';

			$sku_val = $search_sku ? (string) $lookup->sku : '';

			$data = array(
				'product_id'     => $pid,
				'title'          => $search_title ? wp_strip_all_tags( $post->post_title ) : '',
				'sku'            => $sku_val,
				'sku_normalized' => Query_Normalizer::normalize_sku( $sku_val ),
				'content'        => wp_strip_all_tags( trim( $desc . ' ' . $terms_string . ' ' . ( $variation_skus[ $pid ] ?? '' ) ) ),
				'excerpt'        => self::make_excerpt( $desc ),
				'price_min'      => (float) $lookup->min_price,
				'price_max'      => (float) $lookup->max_price,
				'stock_status'   => (string) $lookup->stock_status,
				'total_sales'    => (int) $lookup->total_sales,
				'sales_30d'      => $sales_30d[ $pid ] ?? 0,
				'image_url'      => $image_url,
				'permalink'      => (string) get_permalink( $post ),
				'updated_at'     => $now,
			);

			$row    = self::apply_row_filter_and_sanitize( $data, $pid );
			$rows[] = $row;

			// Accumulate typo-correction vocabulary during rebuilds only —
			// per-save updates would drift frequencies with no way to decrement.
			if ( $is_stage ) {
				foreach ( Query_Normalizer::vocabulary_terms( $row['title'] . ' ' . $row['sku'] ) as $term ) {
					$vocab[ $term ] = ( $vocab[ $term ] ?? 0 ) + 1;
				}
			}
		}

		if ( empty( $rows ) ) {
			return;
		}

		// Single multi-row REPLACE for the whole chunk.
		$columns      = array( 'product_id', 'title', 'sku', 'sku_normalized', 'content', 'excerpt', 'price_min', 'price_max', 'stock_status', 'total_sales', 'sales_30d', 'image_url', 'permalink', 'updated_at' );
		$row_pattern  = '(%d,%s,%s,%s,%s,%s,%f,%f,%s,%d,%d,%s,%s,%s)';
		$placeholders = implode( ',', array_fill( 0, count( $rows ), $row_pattern ) );

		$values = array();
		foreach ( $rows as $row ) {
			foreach ( $columns as $col ) {
				$values[] = $row[ $col ];
			}
		}

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared -- $placeholders is built from literal format patterns; column list is a fixed literal
		$sql = $wpdb->prepare(
			'REPLACE INTO %i (' . implode( ',', $columns ) . ") VALUES {$placeholders}",
			...array_merge( array( $table_name ), $values )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.NotPrepared

		$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( false === $result ) {
			throw new \RuntimeException( 'Bulk index write failed: ' . esc_html( $wpdb->last_error ) );
		}

		if ( $is_stage ) {
			self::write_vocabulary( $vocab );
		}

		self::trigger_cache_bust();
	}

	/**
	 * Delete a single product row from the search index.
	 *
	 * If a full reindex is in progress the row is also removed from the staging
	 * table to keep both tables in parity.
	 *
	 * @param int    $product_id Product ID.
	 * @param string $table_name Target table name. Defaults to the live index table.
	 */
	private static function delete_single_product( int $product_id, string $table_name = '' ): void {
		global $wpdb;

		if ( empty( $table_name ) ) {
			$table_name = $wpdb->prefix . 'wcs_search_index';
			if ( get_option( 'wcs_is_indexing', false ) ) {
				$stage_table = $wpdb->prefix . 'wcs_search_index_stage';
				$wpdb->delete( $stage_table, array( 'product_id' => $product_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		}

		$wpdb->delete( $table_name, array( 'product_id' => $product_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		self::update_cap_reached_flag();
		self::trigger_cache_bust();
	}

	/**
	 * Public facade for external callers and tests.
	 *
	 * Alias of delete_single_product() targeting only the live index table.
	 *
	 * @param int $product_id Product ID to remove.
	 */
	public static function remove_single_product( int $product_id ): void {
		self::delete_single_product( $product_id );
	}

	/**
	 * Incrementally reindex all products that belong to an edited taxonomy term.
	 *
	 * Fires on edited_term. A category or tag rename leaves every product in
	 * that term with the old name in its content column. Queuing each product
	 * individually keeps the update incremental — no full table rebuild needed.
	 *
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID (unused).
	 * @param string $taxonomy Taxonomy slug.
	 */
	public static function on_term_edited( int $term_id, int $tt_id, string $taxonomy ): void {
		if ( ! in_array( $taxonomy, self::indexed_taxonomies(), true ) ) {
			return;
		}

		$product_ids = get_objects_in_term( $term_id, $taxonomy );
		if ( is_wp_error( $product_ids ) || empty( $product_ids ) ) {
			return;
		}

		// For large terms, queuing every product individually costs more than a
		// full rebuild (200 batches of 50 vs. N individual AS jobs). Fall back to
		// a full rebuild above twice the batch size.
		if ( count( $product_ids ) > self::BATCH_SIZE * 2 ) {
			if ( ! self::$rebuild_queued ) {
				self::$rebuild_queued = true;
				self::schedule_full_rebuild();
			}
			return;
		}

		foreach ( $product_ids as $product_id ) {
			self::queue_product_update( (int) $product_id );
		}
	}

	/**
	 * Trigger a full rebuild when an index field setting is toggled.
	 *
	 * Fires on update_option_{wcs_search_title|sku|content|taxonomy}. Changing
	 * which fields are indexed makes every existing row stale — incremental
	 * updates are not sufficient because the rows were built under the old config.
	 * The $rebuild_queued flag prevents duplicate rebuilds when multiple settings
	 * change in a single form submission.
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $new_value New option value.
	 */
	public static function on_index_field_setting_changed( $old_value, $new_value ): void {
		if ( $old_value === $new_value || self::$rebuild_queued ) {
			return;
		}
		self::$rebuild_queued = true;
		self::schedule_full_rebuild();
	}

	/**
	 * Reindex products that are currently on sale.
	 *
	 * Safety net for WooCommerce 6 and older, which processed scheduled sale
	 * prices via direct update_post_meta calls without firing
	 * woocommerce_update_product. WC 7+ already fires that hook, so on modern
	 * installs this is a no-op for products whose prices haven't changed.
	 */
	public static function on_scheduled_sales(): void {
		$on_sale_ids = wc_get_product_ids_on_sale();
		foreach ( $on_sale_ids as $product_id ) {
			self::queue_product_update( (int) $product_id );
		}
	}

	/**
	 * Queue a full catalog rebuild via Action Scheduler.
	 *
	 * Truncates the staging table, sets the indexing flag, and enqueues the
	 * first batch. Subsequent batches are self-scheduled by process_batch().
	 */
	/**
	 * Public entry point for triggering a full rebuild (e.g. from Activator).
	 */
	public static function start_rebuild(): void {
		self::schedule_full_rebuild();
	}

	private static function schedule_full_rebuild(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		// Cancel every pending/in-progress batch so no stale chain races the new one.
		as_unschedule_all_actions( 'wcs_rebuild_index_batch', array(), 'turbo-search-for-woocommerce' );

		global $wpdb;
		$main_table  = $wpdb->prefix . 'wcs_search_index';
		$stage_table = $wpdb->prefix . 'wcs_search_index_stage';

		// Ensure the staging table exists before TRUNCATE. schedule_full_rebuild() is
		// called from term/setting-change hooks, not the AJAX button — those paths do
		// not go through the CREATE TABLE IF NOT EXISTS step in ajax_rebuild_index().
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE IF NOT EXISTS %i LIKE %i', $stage_table, $main_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $stage_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange

		// Reset the vocabulary staging table too — rebuilt alongside the index.
		$terms_table       = $wpdb->prefix . 'wcs_search_terms';
		$terms_stage_table = $wpdb->prefix . 'wcs_search_terms_stage';
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE IF NOT EXISTS %i LIKE %i', $terms_stage_table, $terms_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $terms_stage_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange

		// Millisecond precision so two rebuilds triggered within the same
		// second (e.g. a settings save plus a term edit) get distinct epochs.
		$epoch = (int) ( microtime( true ) * 1000 );
		update_option( 'wcs_rebuild_epoch', $epoch, false );
		update_option( 'wcs_is_indexing', 1, false );
		update_option( 'wcs_reindex_processed', 0, false );
		delete_option( 'wcs_last_rebuild_error' );
		self::log( sprintf( 'NEW REBUILD epoch=%d', $epoch ) );
		as_enqueue_async_action( 'wcs_rebuild_index_batch', array( 'last_id' => 0, 'epoch' => $epoch ), 'turbo-search-for-woocommerce', 0, true );
	}

	/**
	 * Debounced cache invalidation.
	 */
	public static function trigger_cache_bust(): void {
		if ( ! function_exists( 'as_schedule_single_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		// Avoid the AS DB query when we already scheduled a bust this request
		// (e.g. multiple products saved in one bulk operation).
		if ( self::$bust_queued ) {
			return;
		}

		if ( ! as_has_scheduled_action( 'wcs_debounce_cache_bust' ) ) {
			as_schedule_single_action( time() + 300, 'wcs_debounce_cache_bust', array(), 'turbo-search-for-woocommerce' );
		}
		self::$bust_queued = true;
	}

	/**
	 * Actually increment the cache version.
	 */
	public static function execute_cache_bust(): void {
		$current = (int) get_option( 'wcs_cache_version', 1 );
		// autoload=true so every subsequent request gets this from WordPress's
		// initial batch options query instead of a separate SELECT.
		update_option( 'wcs_cache_version', $current + 1, true );
		// True UTC time(), NOT current_time('timestamp'): the latter adds the
		// site's UTC offset (e.g. +3h for Africa/Nairobi), but the settings
		// page compares this value with human_time_diff(), whose default
		// comparison point IS time() — mixing the two made "Last successful
		// index" always overstate elapsed time by roughly the site's own UTC
		// offset (a real rebuild that just finished showed "3 hours ago" on a
		// UTC+3 site). current_time() is for *display formatting* only; any
		// value compared against time() must itself come from time().
		update_option( 'wcs_last_indexed', time(), false );
		self::run_transient_gc();
	}

	/**
	 * Choose a batch size based on current CPU load and PHP memory pressure.
	 *
	 * Load-ratio tiers (1-minute load average ÷ logical CPUs):
	 *   < 0.5  → 200 products/batch (idle)
	 *   < 1.0  → 100 products/batch (normal)
	 *   < 1.5  →  50 products/batch (busy)
	 *   ≥ 1.5  →  25 products/batch (heavy load)
	 *
	 * Two independent memory checks then cap the result further:
	 *   - Relative: current usage vs. this worker's own limit (catches a
	 *     request that's already accumulated a lot of memory before reaching
	 *     the rebuild code).
	 *   - Absolute: the worker's total memory_limit itself. A 200-product
	 *     batch — building one multi-row REPLACE holding every product's
	 *     title/content/excerpt simultaneously — is proportionate on a 512MB+
	 *     worker but risks tipping a 128MB one over, especially with many
	 *     other plugins already resident from the same request's bootstrap.
	 *     The relative check alone misses this: a worker can show low *current*
	 *     usage right before the allocation that finally exhausts a small
	 *     absolute ceiling.
	 *
	 * Falls back to BATCH_SIZE when sys_getloadavg() is unavailable. The
	 * per-batch time budget remains the hard FPM-timeout guard regardless of
	 * the size chosen here.
	 */
	private static function dynamic_batch_size(): int {
		$size = self::BATCH_SIZE;

		if ( function_exists( 'sys_getloadavg' ) ) {
			$load  = sys_getloadavg();
			$cpus  = self::cpu_count();
			$ratio = $cpus > 0 ? $load[0] / $cpus : $load[0];
			if ( $ratio < 0.5 ) {
				$size = 200;
			} elseif ( $ratio < 1.0 ) {
				$size = 100;
			} elseif ( $ratio < 1.5 ) {
				$size = 50;
			} else {
				$size = 25;
			}
		}

		$limit = self::memory_limit_bytes();
		if ( $limit > 0 ) {
			// Absolute cap: bounds worst-case allocation size regardless of
			// how much headroom currently looks free.
			if ( $limit <= 128 * 1024 * 1024 ) {
				$size = min( $size, 25 );
			} elseif ( $limit <= 192 * 1024 * 1024 ) {
				$size = min( $size, 50 );
			} elseif ( $limit <= 256 * 1024 * 1024 ) {
				$size = min( $size, 100 );
			}

			// Relative cap: current usage vs. this worker's own limit.
			$usage = memory_get_usage( true ) / $limit;
			if ( $usage > 0.70 ) {
				$size = min( $size, 25 );
			} elseif ( $usage > 0.50 ) {
				$size = min( $size, 50 );
			}
		}

		$size = max( self::BATCH_MIN, min( self::BATCH_MAX, $size ) );

		/**
		 * Filters the final adaptive rebuild batch size.
		 *
		 * Use this to hard-cap batches on a memory-constrained host — e.g.
		 * managed hosting with a `php_admin_value` memory_limit that
		 * WordPress cannot override via WP_MEMORY_LIMIT:
		 *
		 *   add_filter( 'wcs_batch_size', fn() => 25 );
		 *
		 * @param int $size Computed batch size (10–200 by default).
		 */
		return (int) apply_filters( 'wcs_batch_size', $size );
	}

	/**
	 * Count logical CPUs from /proc/cpuinfo; returns 1 when unavailable.
	 */
	private static function cpu_count(): int {
		if ( is_readable( '/proc/cpuinfo' ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			return max( 1, substr_count( (string) file_get_contents( '/proc/cpuinfo' ), 'processor' ) );
		}
		return 1;
	}

	/**
	 * Parse PHP memory_limit into bytes; returns 0 when limit is unlimited (-1).
	 */
	private static function memory_limit_bytes(): int {
		$limit = (string) ini_get( 'memory_limit' );
		if ( '-1' === $limit ) {
			return 0;
		}
		$unit  = strtolower( substr( $limit, -1 ) );
		$value = (int) $limit;
		switch ( $unit ) {
			case 'g':
				return $value * 1024 * 1024 * 1024;
			case 'm':
				return $value * 1024 * 1024;
			case 'k':
				return $value * 1024;
			default:
				return $value;
		}
	}

	/**
	 * Garbage collect orphaned transients.
	 */
	public static function run_transient_gc(): void {
		global $wpdb;

		// Search analytics logging (and its retention prune) is a Pro
		// feature — this edition never creates wcs_search_log, so there is
		// nothing to prune here.

		if ( wp_using_ext_object_cache() ) {
			return; // Redis/Memcached handle their own transient TTL eviction.
		}

		$current_version = (int) get_option( 'wcs_cache_version', 1 );

		// Delete timeout rows for old versions. Both conditions use a literal
		// prefix so MySQL can use the option_name index on both sides.
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE %s
			   AND option_name NOT LIKE %s",
			$wpdb->esc_like( '_transient_timeout_wcs_v' ) . '%',
			$wpdb->esc_like( "_transient_timeout_wcs_v{$current_version}_" ) . '%'
		) );

		// Delete value rows for old versions. After the query above, old timeout
		// rows are gone, so old value rows are now orphaned. A direct version
		// comparison avoids the non-sargable REPLACE()-in-JOIN that prevented
		// MySQL from using the option_name index on the joined side.
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE %s
			   AND option_name NOT LIKE %s",
			$wpdb->esc_like( '_transient_wcs_v' ) . '%',
			$wpdb->esc_like( "_transient_wcs_v{$current_version}_" ) . '%'
		) );
	}
}
