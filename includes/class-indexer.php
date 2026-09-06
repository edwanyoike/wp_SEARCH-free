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
	 * Cap for the wcs_pending_product_updates option (see
	 * add_pending_product_update()) — bounds worst-case autoloaded-option
	 * growth if a site somehow keeps hitting the "both schedulers rejected
	 * this enqueue" gap repeatedly, rather than letting it grow unbounded.
	 */
	private const PENDING_UPDATE_CAP = 500;

	/**
	 * Product IDs already queued for update within this request, keyed by ID.
	 * Prevents multiple as_has_scheduled_action() DB queries for the same product
	 * when several hooks fire on the same product save.
	 *
	 * @var array<int, true>
	 */
	private static array $queued_ids = array();

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

		// The indexed parent row carries variation SKUs and the variable
		// product's price range, so a variation change must refresh the
		// parent — but WooCommerce fires distinct hooks for variations
		// rather than reusing the product ones above. Confirmed live against
		// WooCommerce's own data store (class-wc-product-variation-data-
		// store-cpt.php, class-wc-product-data-store-cpt.php): a variation
		// save fires woocommerce_update_product_variation /
		// woocommerce_new_product_variation (never woocommerce_update_product
		// or save_post_product — that post type doesn't match 'product'),
		// and a variation stock change fires woocommerce_variation_set_stock_
		// status, never woocommerce_product_set_stock_status (the two are
		// mutually exclusive in core, gated on is_type( 'variation' )). All
		// three pass the variation ID first, so one callback covers them.
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'queue_variation_update' ), 10, 1 );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'queue_variation_update' ), 10, 1 );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'queue_variation_update' ), 10, 1 );
		// Restoring either a variation or its parent from the Trash doesn't
		// go through WC_Product::save() (wp_untrash_post() is a raw core
		// operation), so neither of the hooks above nor woocommerce_update_
		// product fires — without this, a restored product/variation stays
		// unsearchable until an unrelated save or a full rebuild.
		add_action( 'untrashed_post', array( __CLASS__, 'on_product_untrash' ), 10, 1 );

		// ── WooCommerce CSV importer hooks ────────────────────────────────────
		// The built-in WC importer does not fire woocommerce_update_product, so
		// without these hooks bulk-imported products are invisible until the next
		// manual rebuild. Each product is queued individually for an incremental
		// index update — no full rebuild triggered.
		add_action( 'woocommerce_product_import_inserted_product_object', array( __CLASS__, 'queue_product_update_from_import' ), 10, 1 );
		add_action( 'woocommerce_product_import_updated_product_object', array( __CLASS__, 'queue_product_update_from_import' ), 10, 1 );

		// ── Event-driven delete hooks (Recommendation 3) ──────────────────────
		// Remove the product from the index immediately — no batch cycle needed.
		// wp_trash_post fires when a post is moved to the Trash.
		// before_delete_post fires just before a post is permanently deleted.
		add_action( 'wp_trash_post', array( __CLASS__, 'on_product_trash' ), 10, 1 );
		add_action( 'before_delete_post', array( __CLASS__, 'on_product_delete' ), 10, 1 );

		// ── Action Scheduler hooks ────────────────────────────────────────────
		add_action( 'wcs_rebuild_index_batch', array( __CLASS__, 'process_batch' ), 10, 2 );
		add_action( 'wcs_optimize_index', array( __CLASS__, 'run_optimize' ) );
		add_action( 'wcs_update_single_product', array( __CLASS__, 'index_single_product' ), 10, 1 );
		add_action( 'wcs_debounce_cache_bust', array( __CLASS__, 'execute_cache_bust' ) );
		// Reset the indexing flag when AS marks a rebuild batch as permanently failed
		// so the UI never stays stuck in "Indexing..." with no running job behind it.
		add_action( 'action_scheduler_failed_action', array( __CLASS__, 'on_batch_action_failed' ), 10, 1 );
		// WP-Cron fallback for when a rebuild batch enqueue never made it into
		// Action Scheduler at all — see enqueue_batch_with_retry()'s docblock.
		add_action( 'wcs_retry_rebuild_scheduling', array( __CLASS__, 'retry_rebuild_scheduling' ), 10, 2 );
		// Same fallback for a single incremental product-update enqueue —
		// see queue_product_update()'s comment for why this can fail too.
		add_action( 'wcs_retry_product_enqueue', array( __CLASS__, 'retry_product_enqueue' ), 10, 1 );
		// Same fallback for a product removal — see delete_with_retry()'s comment.
		add_action( 'wcs_retry_product_delete', array( __CLASS__, 'retry_product_delete' ), 10, 1 );

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

		// ── Result-affecting settings ─────────────────────────────────────────
		// wcs_result_count and wcs_show_out_of_stock change what a search
		// request returns without touching index data (no rebuild needed,
		// same as synonyms above) — but neither participates in the search
		// cache key (Query_Normalizer::cache_key() is query+currency+
		// cache_version only), so without this hook a change here would keep
		// serving results computed under the old setting for up to the full
		// 24h transient TTL (or 5 min via APCu on top of that).
		foreach ( array( 'wcs_result_count', 'wcs_show_out_of_stock' ) as $opt ) {
			add_action( "update_option_{$opt}", array( __CLASS__, 'on_result_affecting_setting_changed' ), 10, 2 );
		}

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
	 * Bust the result cache when wcs_result_count or wcs_show_out_of_stock
	 * changes — both affect what a search request returns but neither is
	 * part of the cache key, so a stale cached payload would otherwise
	 * survive until it naturally expires.
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $new_value New option value.
	 */
	public static function on_result_affecting_setting_changed( $old_value, $new_value ): void {
		if ( $old_value === $new_value ) {
			return;
		}
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

		if ( as_has_scheduled_action( 'wcs_update_single_product', array( 'product_id' => $product_id ) ) ) {
			return;
		}

		$action_id = as_enqueue_async_action( 'wcs_update_single_product', array( 'product_id' => $product_id ), 'turbo-search-for-woocommerce' );
		if ( $action_id ) {
			return;
		}

		// as_enqueue_async_action() returns 0 (not an exception) on failure —
		// left unchecked, this product's edit silently never reaches the
		// index and stays stale until its next save or a full rebuild. WP-
		// Cron fallback, same shape as the rebuild-batch enqueue retries.
		self::log( sprintf( 'Incremental update enqueue failed for product %d — falling back to WP-Cron retry', $product_id ) );
		if ( ! wp_next_scheduled( 'wcs_retry_product_enqueue', array( $product_id ) ) ) {
			// wp_schedule_single_event() itself can also return false (not an
			// exception). Unlike a rebuild — a single trackable operation
			// with its own status this plugin can mark failed — there is no
			// equivalent per-product admin state to set here, so this stays
			// a logged warning: the product still resyncs on its next save
			// or a full rebuild, same as if this whole fallback didn't exist.
			if ( ! wp_schedule_single_event( time() + 30, 'wcs_retry_product_enqueue', array( $product_id ) ) ) {
				Logger::log( sprintf( 'WP-Cron also refused to schedule the incremental-update retry for product %d — it will resync on its next save or a full rebuild', $product_id ), 'warning' );
				self::add_pending_product_update( $product_id );
			}
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
	 * Queue a variation's parent product for incremental re-indexing.
	 *
	 * Shared callback for every WooCommerce variation hook registered in
	 * init() (update, create, stock-status change) — all three pass the
	 * variation ID first. Uses wp_get_post_parent_id() rather than
	 * wc_get_product() so this stays a cheap lookup, not a full product load,
	 * on what can be a high-frequency hook (saving the variations admin UI
	 * fires one of these per changed row).
	 *
	 * @param int $variation_id Variation post ID.
	 */
	public static function queue_variation_update( int $variation_id ): void {
		$parent_id = wp_get_post_parent_id( $variation_id );
		if ( $parent_id ) {
			self::queue_product_update( $parent_id );
		}
	}

	/**
	 * Reindex a product or variation restored from the Trash.
	 *
	 * Fires on the core untrashed_post hook, which wp_untrash_post() fires
	 * for any post type — it does not go through WC_Product::save(), so
	 * neither woocommerce_update_product nor the variation hooks above ever
	 * fire for a restore. A restored parent product is queued directly; a
	 * restored variation queues its parent via queue_variation_update().
	 *
	 * @param int $post_id Restored post ID.
	 */
	public static function on_product_untrash( int $post_id ): void {
		$post_type = get_post_type( $post_id );
		if ( 'product' === $post_type ) {
			self::queue_product_update( $post_id );
			return;
		}
		if ( 'product_variation' === $post_type ) {
			self::queue_variation_update( $post_id );
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
		$post_type = get_post_type( $post_id );
		if ( 'product' === $post_type ) {
			self::delete_single_product( $post_id );
			return;
		}
		// A trashed variation is never itself an index row (only parents
		// are indexed), but its SKU/price/stock must disappear from the
		// parent's indexed row immediately.
		if ( 'product_variation' === $post_type ) {
			self::queue_variation_update( $post_id );
		}
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
		$post_type = get_post_type( $post_id );
		if ( 'product' === $post_type ) {
			self::delete_single_product( $post_id );
			return;
		}
		if ( 'product_variation' === $post_type ) {
			self::queue_variation_update( $post_id );
		}
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

			if ( $already_retried ) {
				self::log( sprintf( 'FAIL retry exhausted last_id=%d — halting', $last_id ) );
				update_option( 'wcs_is_indexing', 0, false );
				return;
			}

			// Recorded before attempting the enqueue — this budget is "retry
			// once per cursor", independent of whether that one retry attempt
			// itself lands cleanly. What used to be unconditional here was the
			// raw as_enqueue_async_action() call below with its return value
			// discarded: if Action Scheduler rejected THIS retry too (returns
			// 0, not an exception), no job existed to ever fail again and
			// re-trigger this callback, so wcs_is_indexing sat at 1 forever —
			// the same failure class enqueue_batch_with_retry() already
			// guards every other batch enqueue in this file against. Routing
			// through it here closes the one remaining call site that still
			// called Action Scheduler directly.
			set_transient( $retry_key, 1, HOUR_IN_SECONDS );
			self::enqueue_batch_with_retry( $last_id, $epoch );
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
		$fetch_limit = self::dynamic_batch_size();
		// Excludes anything the merchant explicitly hid from search (WooCommerce's
		// "Catalog visibility: Hidden"/"Shop only" settings — the exclude-from-search
		// product_visibility term) and password-protected products. The public REST
		// search endpoint has no capability check, so an unfiltered index would let
		// any anonymous visitor search their way to content the merchant deliberately
		// hid or password-gated.
		$products = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"SELECT p.ID FROM {$wpdb->posts} p
			 WHERE p.post_type = 'product'
			   AND p.post_status = 'publish'
			   AND p.post_password = ''
			   AND p.ID > %d
			   AND NOT EXISTS (
			       SELECT 1 FROM {$wpdb->term_relationships} tr
			       INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
			       INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
			       WHERE tr.object_id = p.ID
			         AND tt.taxonomy = 'product_visibility'
			         AND t.slug = 'exclude-from-search'
			   )
			 ORDER BY p.ID ASC
			 LIMIT %d",
			$last_id,
			$fetch_limit
		) );

		// $wpdb->get_col() returns an empty array both when the query fails
		// and when it genuinely matched nothing — identical to the ambiguity
		// Search_Handler::get_rows() has on the read side (see that method's
		// docblock). Here that ambiguity is far more consequential: an empty
		// result feeds straight into the empty($products) check below, which
		// means "reached the end of the catalog, swap staging in" — so a
		// transient failure fetching THIS page would look exactly like a
		// complete rebuild and could swap in a staging table missing every
		// product from here to the end of the catalog. Bounded retry, same
		// shape as the batch-enqueue retries elsewhere in this file: give it
		// 5 attempts via the existing verified-enqueue/WP-Cron path before
		// halting and preserving the live index.
		if ( '' !== $wpdb->last_error ) {
			$fetch_error  = (string) $wpdb->last_error;
			$attempts_key = 'wcs_fetch_retry_' . $epoch . '_' . $last_id;
			$attempts     = (int) get_transient( $attempts_key );

			if ( $attempts >= 5 ) {
				self::log( sprintf( 'Product-ID fetch failed 5 times at last_id=%d — halting, old live index preserved: %s', $last_id, $fetch_error ) );
				update_option( 'wcs_last_rebuild_error', 'batch_fetch_failed', false );
				update_option( 'wcs_is_indexing', 0, false );
				delete_option( 'wcs_rebuild_phase' );
				delete_transient( $attempts_key );
				return;
			}

			set_transient( $attempts_key, $attempts + 1, HOUR_IN_SECONDS );
			self::log( sprintf( 'Product-ID fetch failed at last_id=%d (attempt %d/5) — retrying: %s', $last_id, $attempts + 1, $fetch_error ) );
			self::enqueue_batch_with_retry( $last_id, $epoch );
			return;
		}

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
			$old_table = $wpdb->prefix . 'wcs_search_index_old';
			// SELECT 1 LIMIT 1 is O(1) — sufficient to guard against an empty staging
			// table without paying the cost of a full COUNT(*) scan on large catalogs.
			$stage_has_rows = (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 1', $stage_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			self::log( sprintf( 'SWAP last_id=%d epoch=%d stage_has_rows=%d', $last_id, $epoch, (int) $stage_has_rows ) );

			if ( $stage_has_rows ) {
				update_option( 'wcs_rebuild_phase', 'swapping', false );
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $old_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
				$rename_ok = false !== $wpdb->query( $wpdb->prepare( 'RENAME TABLE %i TO %i, %i TO %i', $main_table, $old_table, $stage_table, $main_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

				if ( ! $rename_ok ) {
					// MySQL's multi-table RENAME is atomic for one statement
					// — either both renames happen or neither does — so a
					// failure here leaves $main_table (still the pre-rebuild
					// data) and $stage_table (still the fully-built new
					// data) exactly as they were; $old_table never came into
					// existence. Proceeding to report success anyway — as
					// this used to, silently — would clear wcs_is_indexing,
					// bump wcs_last_indexed to "just now", and fire
					// wcs_index_rebuild_complete while the site is still
					// serving the OLD index: actively misleading, since the
					// Settings page would show a fresh "Last successful
					// index" timestamp for a swap that never happened.
					// Retry through the same verified path as every other
					// enqueue in this file, bounded the same way.
					$attempts_key = 'wcs_swap_retry_' . $epoch;
					$attempts     = (int) get_transient( $attempts_key );

					if ( $attempts >= 5 ) {
						Logger::log( sprintf( 'RENAME TABLE failed 5 times at swap (epoch=%d) — halting, old live index preserved: %s', $epoch, $wpdb->last_error ), 'warning' );
						update_option( 'wcs_last_rebuild_error', 'swap_failed', false );
						update_option( 'wcs_is_indexing', 0, false );
						delete_option( 'wcs_rebuild_phase' );
						delete_transient( $attempts_key );
						return;
					}

					set_transient( $attempts_key, $attempts + 1, HOUR_IN_SECONDS );
					self::log( sprintf( 'RENAME TABLE failed at swap (epoch=%d, attempt %d/5) — retrying: %s', $epoch, $attempts + 1, $wpdb->last_error ) );
					self::enqueue_batch_with_retry( $last_id, $epoch );
					return;
				}

				delete_transient( 'wcs_swap_retry_' . $epoch );
				$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $old_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

				// Typo-correction vocabulary (wcs_search_terms*) is a Pro-only
				// feature — this edition never creates those tables, so there is
				// nothing to swap here (see PORTING.md).

				// OPTIMIZE TABLE can take minutes on large catalogs — dispatch it as a
				// separate async action so it never runs inside this FPM request.
				update_option( 'wcs_rebuild_phase', 'optimizing', false );
				if ( function_exists( 'as_enqueue_async_action' ) ) {
					// $unique=true, $priority=10 — the trailing args were
					// previously (0, true), which cast true to priority 1
					// instead of the intended 10, and left unique false when
					// only one queued optimize job is ever needed.
					as_enqueue_async_action( 'wcs_optimize_index', array(), 'turbo-search-for-woocommerce', true, 10 );
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

			// A rebuild that produced rows but also hit unresolved per-product
			// write failures along the way (tracked by
			// increment_rebuild_failure_count()) still swaps in — see that
			// method's docblock for why — but must not report a silent,
			// unqualified success: the admin has no other way to learn that
			// some products didn't make it into the new index.
			$failed_count = (int) get_option( 'wcs_rebuild_failed_count', 0 );
			if ( $failed_count > 0 ) {
				update_option( 'wcs_last_rebuild_error', 'partial_failure', false );
				self::log( sprintf( 'SWAP completed with %d failed product write(s) — see earlier log lines for IDs', $failed_count ) );
			} else {
				delete_option( 'wcs_last_rebuild_error' );
			}
			delete_option( 'wcs_rebuild_failed_count' );

			// A full rebuild just reindexed every product, including any that
			// were sitting in wcs_pending_product_updates because both
			// schedulers rejected their incremental-update enqueue — those
			// entries are now moot and would otherwise wait for the next
			// drain to enqueue a redundant update for an already-current row.
			delete_option( 'wcs_pending_product_updates' );

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
					// Up to 3 attempts before counting this product as a real
					// failure. A single retry (falling back from the bulk
					// write to this per-product path) is already thin
					// protection against a genuinely transient error — a lock
					// wait timeout or a momentary connection blip can just as
					// easily hit the immediate retry too. This does not
					// change the swap decision below: a product that still
					// fails after 3 attempts is presumed to have a real,
					// non-transient problem (bad data, a broken filter
					// callback), not bad luck, and is counted exactly as
					// before.
					$attempt = 0;
					do {
						try {
							self::do_index_single_product( $product_id, $stage_table );
							continue 2; // succeeded — next product in the chunk
						} catch ( \Throwable $e ) {
							++$attempt;
							$last_exception = $e;
						}
					} while ( $attempt < 3 );

					++$batch_failures;
					self::increment_rebuild_failure_count();
					self::log( sprintf( 'product %d failed after %d attempts — %s', $product_id, $attempt, $last_exception->getMessage() ) );
				}
			}
			$processed_in_batch += count( $chunk );
			$chunk_last_id       = (int) end( $chunk );

			if ( ( microtime( true ) - $batch_start ) >= $time_budget ) {
				$processed = (int) get_option( 'wcs_reindex_processed', 0 );
				update_option( 'wcs_reindex_processed', $processed + $processed_in_batch, false );
				self::log( sprintf( 'BUDGET last_id=%d done=%d elapsed=%.1fs', $chunk_last_id, $processed + $processed_in_batch, microtime( true ) - $batch_start ) );
				self::enqueue_batch_with_retry( $chunk_last_id, $epoch );
				return;
			}
		}

		if ( $batch_failures === $processed_in_batch ) {
			self::log( sprintf( 'ALL FAILED last_id=%d — halting chain, old live index preserved', $last_id ) );
			update_option( 'wcs_last_rebuild_error', 'batch_write_failed', false );
			update_option( 'wcs_is_indexing', 0, false );
			delete_option( 'wcs_rebuild_phase' );
			delete_option( 'wcs_rebuild_failed_count' );
			return;
		}

		$processed    = (int) get_option( 'wcs_reindex_processed', 0 );
		$new_total    = $processed + $processed_in_batch;
		$next_last_id = (int) end( $products );
		update_option( 'wcs_reindex_processed', $new_total, false );
		self::log( sprintf( 'DONE last_id=%d next=%d total=%d elapsed=%.1fs', $last_id, $next_last_id, $new_total, microtime( true ) - $batch_start ) );

		self::enqueue_batch_with_retry( $next_last_id, $epoch );
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

		// Honor a merchant's "Catalog visibility: Hidden"/"Shop only" choice and
		// password-protected products — the public REST search endpoint has no
		// capability check, so an indexed-but-hidden product would still be
		// findable by anyone.
		$post = get_post( $product_id );
		if ( ( $post && '' !== ( $post->post_password ?? '' ) ) || has_term( 'exclude-from-search', 'product_visibility', $product_id ) ) {
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
			$prices    = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
			// Recent-sales-weighted ranking is a Pro feature; the weight is
			// always pinned to 0.0 in Search_Handler regardless of this
			// value, so it's never computed here — a real aggregate query
			// against wc_order_product_lookup on every single product save
			// for a number that can never affect a ranking isn't worth the cost.
			'sales_30d'      => 0,
			'image_url'      => $image_url,
			'permalink'      => $product->get_permalink(),
			'updated_at'     => current_time( 'mysql' ),
		);

		$data = self::apply_row_filter_and_sanitize( $data, $product_id );
		// product_id, title, title_normalized, title_padded, sku, sku_normalized,
		// content, excerpt, price_min, price_max, stock_status, total_sales,
		// sales_30d, image_url, permalink, updated_at — positional, must match
		// apply_row_filter_and_sanitize()'s return array order exactly.
		$formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%d', '%d', '%s', '%s', '%s' );

		if ( empty( $table_name ) ) {
			$table_name = $wpdb->prefix . 'wcs_search_index';

			// If a full rebuild is active, also duplicate live edits to staging to maintain parity
			if ( get_option( 'wcs_is_indexing', false ) ) {
				$stage_table = $wpdb->prefix . 'wcs_search_index_stage';
				// Same bounded retry as do_process_batch()'s per-product
				// fallback (see that loop's comment): this write runs inside
				// the async wcs_update_single_product action, not the
				// admin's original save request, so retrying costs nothing
				// user-facing — and without it, a transient blip here (a
				// lock wait, a momentary connection drop) would leave this
				// product's staging row silently stale for the rest of the
				// rebuild, with no later batch pass ever revisiting an ID
				// the cursor has already scanned past.
				$stage_attempt = 0;
				$stage_written = false;
				do {
					$stage_written = ( false !== $wpdb->replace( $stage_table, $data, $formats ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					++$stage_attempt;
				} while ( ! $stage_written && $stage_attempt < 3 );

				if ( ! $stage_written ) {
					self::log( sprintf( 'product %d — staging write failed after %d attempts: %s', $product_id, $stage_attempt, $wpdb->last_error ) );
					self::increment_rebuild_failure_count();
				}
			}
		}

		// $wpdb->replace() returns false (not an exception) on a write failure —
		// a bad connection, a lock wait timeout, or data a column genuinely
		// rejects. Left unchecked this method returns as if the row was
		// written: an incremental update silently leaves the live index
		// stale, and during a rebuild the row is simply missing from staging
		// with nothing to show for it. Throwing here lets both callers'
		// existing failure handling actually see it — do_process_batch()'s
		// per-product fallback loop already counts a Throwable as a batch
		// failure, and a plain incremental update surfaces as a failed
		// action in Action Scheduler's own admin log instead of vanishing.
		if ( false === $wpdb->replace( $table_name, $data, $formats ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$db_error = (string) $wpdb->last_error;
			self::log( sprintf( 'product %d — write to %s failed: %s', $product_id, $table_name, $db_error ) );
			// Deliberately NOT counted here even when $table_name is the
			// staging table: do_process_batch()'s per-product fallback calls
			// this method up to 3 times for the same product before giving
			// up (see that loop's comment), and counting on every attempt
			// would triple-count one product as three failures. That loop
			// increments the shared counter itself, exactly once, only
			// after retries are exhausted.
			throw new \RuntimeException( 'Index write failed for product ' . esc_html( (string) $product_id ) . ': ' . esc_html( $db_error ) );
		}

		self::trigger_cache_bust();
	}

	/**
	 * Count distinct products that failed to reach the staging table during
	 * the rebuild currently in progress, after do_process_batch()'s bounded
	 * per-product retry gave up on them. Read back at the final swap in that
	 * same method so a rebuild that finished with unresolved failures still
	 * swaps in the (mostly complete) new index — discarding it would let one
	 * pathological product block every future rebuild — but surfaces a
	 * specific, admin-visible warning instead of silently reporting success.
	 * Reset to 0 at the start of every rebuild in schedule_full_rebuild().
	 */
	private static function increment_rebuild_failure_count(): void {
		$count = (int) get_option( 'wcs_rebuild_failed_count', 0 );
		update_option( 'wcs_rebuild_failed_count', $count + 1, false );
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
		$title            = wp_strip_all_tags( (string) ( $data['title'] ?? '' ) );
		$title_normalized = Query_Normalizer::normalize_title( $title );
		return array(
			'product_id'       => (int) ( $data['product_id'] ?? $product_id ),
			'title'            => $title,
			// Punctuation-normalized title, precomputed once at index time so
			// the query layer's exact-title/title-prefix boosts (Search_
			// Handler) can compare it directly against a normalized query —
			// see Query_Normalizer::normalize_title()'s docblock for why the
			// raw `title` column can't serve that comparison correctly.
			'title_normalized' => $title_normalized,
			// Word-boundary padding of the NORMALIZED title, precomputed at
			// index time so the query layer can match
			// `title_padded LIKE '% word %'` directly instead of evaluating
			// CONCAT(' ', title_normalized, ' ') fresh on every candidate row
			// for every query word (Search_Handler's phrase/word-score
			// boosts). Padding the normalized form rather than the raw title
			// is what makes those boosts match "T-Shirt" against the
			// normalized query word "shirt" in the first place.
			'title_padded'     => ' ' . $title_normalized . ' ',
			'sku'              => (string) ( $data['sku'] ?? '' ),
			'sku_normalized'   => Query_Normalizer::normalize_sku( (string) ( $data['sku_normalized'] ?? $data['sku'] ?? '' ) ),
			'content'          => wp_strip_all_tags( (string) ( $data['content'] ?? '' ) ),
			'excerpt'          => self::make_excerpt( (string) ( $data['excerpt'] ?? '' ) ),
			'price_min'        => (float) ( $data['price_min'] ?? 0 ),
			'price_max'        => (float) ( $data['price_max'] ?? 0 ),
			'stock_status'     => sanitize_key( (string) ( $data['stock_status'] ?? '' ) ),
			'total_sales'      => max( 0, (int) ( $data['total_sales'] ?? 0 ) ),
			'sales_30d'        => max( 0, (int) ( $data['sales_30d'] ?? 0 ) ),
			'image_url'        => esc_url_raw( (string) ( $data['image_url'] ?? '' ) ),
			'permalink'        => esc_url_raw( (string) ( $data['permalink'] ?? '' ) ),
			'updated_at'       => (string) ( $data['updated_at'] ?? current_time( 'mysql' ) ),
		);
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

		$rows = array();
		foreach ( $product_ids as $pid ) {
			$post = get_post( $pid );
			if ( ! $post || 'publish' !== $post->post_status ) {
				self::delete_single_product( $pid, $table_name );
				continue;
			}

			// Defense in depth: the batch cursor query already excludes these,
			// but this method has no other caller-side guarantee against a
			// future call site that skips that query.
			if ( '' !== ( $post->post_password ?? '' ) || has_term( 'exclude-from-search', 'product_visibility', $pid ) ) {
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
				'sales_30d'      => 0,
				'image_url'      => $image_url,
				'permalink'      => (string) get_permalink( $post ),
				'updated_at'     => $now,
			);

			$row    = self::apply_row_filter_and_sanitize( $data, $pid );
			$rows[] = $row;
		}

		if ( empty( $rows ) ) {
			return;
		}

		// Single multi-row REPLACE for the whole chunk.
		$columns      = array( 'product_id', 'title', 'title_normalized', 'title_padded', 'sku', 'sku_normalized', 'content', 'excerpt', 'price_min', 'price_max', 'stock_status', 'total_sales', 'sales_30d', 'image_url', 'permalink', 'updated_at' );
		$row_pattern  = '(%d,%s,%s,%s,%s,%s,%s,%s,%f,%f,%s,%d,%d,%s,%s,%s)';
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
				self::delete_with_retry( $stage_table, $product_id );
			}
		}

		$removed = self::delete_with_retry( $table_name, $product_id );

		// A successful removal here is security/privacy-sensitive — a
		// trashed, deleted, hidden, or newly password-protected product —
		// unlike an ordinary content edit, so it gets an immediate cache
		// bust instead of the usual 5-minute debounce: an existing cached
		// result could otherwise keep surfacing the removed product to the
		// public REST search endpoint (no capability check) for up to that
		// whole window even though the row is already gone. A failed
		// removal still gets the debounced bust as a same safety net as any
		// other write, pending delete_with_retry()'s own follow-up retry.
		if ( $removed ) {
			self::execute_cache_bust();
		} else {
			self::trigger_cache_bust();
		}
	}

	/**
	 * Delete one product's row, retrying a bounded number of times before
	 * falling back to a WP-Cron follow-up retry. $wpdb->delete() returns
	 * false (not an exception) on a real write failure — left unchecked, a
	 * trashed, deleted, hidden, or newly password-protected product's row
	 * could survive in the index, remaining findable through the public
	 * REST search endpoint (which has no capability check) even after the
	 * merchant removed it, for as long as nothing else happens to touch
	 * that specific product again. This runs synchronously on
	 * wp_trash_post/before_delete_post for the live-table case, so the
	 * immediate attempts are bounded low enough to add negligible latency
	 * to that request; the WP-Cron follow-up (if needed) does not block it.
	 *
	 * @param string $table_name Target table.
	 * @param int    $product_id Product ID to remove.
	 * @return bool Whether the row was confirmed removed (immediately).
	 */
	private static function delete_with_retry( string $table_name, int $product_id ): bool {
		global $wpdb;

		$attempt = 0;
		do {
			if ( false !== $wpdb->delete( $table_name, array( 'product_id' => $product_id ), array( '%d' ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return true;
			}
			++$attempt;
		} while ( $attempt < 3 );

		self::log( sprintf( 'product %d — delete from %s failed after %d attempts: %s', $product_id, $table_name, $attempt, $wpdb->last_error ) );

		// Deliberately does NOT schedule the retry against this specific
		// $table_name — see retry_product_delete()'s docblock for why a
		// table name captured now can be wrong by the time a 30s-delayed
		// WP-Cron event fires. schedule_delete_retry() re-resolves the
		// correct target(s) fresh instead.
		self::schedule_delete_retry( $product_id );

		return false;
	}

	/**
	 * Schedule (or no-op if already pending) a WP-Cron follow-up that
	 * retries a product's removal. Keyed by product ID only — not by
	 * table — because retry_product_delete() re-resolves its own targets;
	 * see that method's docblock.
	 *
	 * @param int $product_id Product to retry removing.
	 */
	private static function schedule_delete_retry( int $product_id ): void {
		if ( wp_next_scheduled( 'wcs_retry_product_delete', array( $product_id ) ) ) {
			return;
		}
		if ( ! wp_schedule_single_event( time() + 30, 'wcs_retry_product_delete', array( $product_id ) ) ) {
			Logger::log( sprintf( 'WP-Cron also refused to schedule a removal retry for product %d — it may still be searchable; check manually or run a full rebuild', $product_id ), 'warning' );
		}
	}

	/**
	 * WP-Cron callback: retry a product removal that failed all of
	 * delete_with_retry()'s immediate attempts. Bounded to 5 attempts
	 * (~2.5 minutes); beyond that this is logged at 'warning' — the
	 * closest thing to an admin-visible error this plugin has for a
	 * single-product failure (see WooCommerce → Status → Logs) — since
	 * there is no per-product state in the Settings page to set a durable
	 * error against, unlike a whole rebuild.
	 *
	 * Deliberately takes no $table_name argument and re-resolves both
	 * possible targets fresh on every attempt, exactly like
	 * delete_single_product() itself does. A table name captured at the
	 * time of the original failure and carried into a 30-second-delayed
	 * WP-Cron event can go stale in the meantime: if the original failure
	 * was on the staging table, a rebuild's atomic RENAME can swap that
	 * very table into the live position before this callback runs — at
	 * which point retrying against the frozen staging name would either
	 * hit a table that no longer exists, or (if a newer, unrelated rebuild
	 * had already recreated staging) silently touch that DIFFERENT
	 * rebuild's in-progress data instead of the table actually serving
	 * public search. Re-resolving fresh here is immune to that by
	 * construction — confirmed against the live deploy history in this
	 * document, where a full rebuild completed in well under 30 seconds.
	 *
	 * @param int $product_id Product to remove.
	 */
	public static function retry_product_delete( int $product_id ): void {
		global $wpdb;

		$attempts_key = 'wcs_delete_retry_' . $product_id;
		$attempts     = (int) get_transient( $attempts_key );

		if ( $attempts >= 5 ) {
			Logger::log( sprintf( 'Removal of product %d could not be confirmed after repeated retries — it may still be searchable; check manually or run a full rebuild', $product_id ), 'warning' );
			delete_transient( $attempts_key );
			return;
		}

		$live_table = $wpdb->prefix . 'wcs_search_index';
		$live_ok    = false !== $wpdb->delete( $live_table, array( 'product_id' => $product_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

		$stage_ok = true;
		if ( get_option( 'wcs_is_indexing', false ) ) {
			$stage_table = $wpdb->prefix . 'wcs_search_index_stage';
			$stage_ok    = false !== $wpdb->delete( $stage_table, array( 'product_id' => $product_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}

		if ( $live_ok && $stage_ok ) {
			delete_transient( $attempts_key );
			self::execute_cache_bust(); // same immediate-bust reasoning as delete_single_product()'s own success path
			return;
		}

		set_transient( $attempts_key, $attempts + 1, HOUR_IN_SECONDS );
		if ( ! wp_schedule_single_event( time() + 30, 'wcs_retry_product_delete', array( $product_id ) ) ) {
			Logger::log( sprintf( 'WP-Cron refused to reschedule a removal retry for product %d (attempt %d/5) — it may still be searchable; check manually or run a full rebuild', $product_id, $attempts + 1 ), 'warning' );
		}
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
		//
		// Both results are checked: a rebuild must never start against a
		// staging table that might not actually be empty. If TRUNCATE fails
		// (a lock, a permissions issue) while the table already held rows
		// from an earlier abandoned rebuild, this rebuild's batch writes are
		// REPLACE-based and only ever touch currently-eligible product IDs —
		// a stale row for a product deleted since that abandoned run would
		// never be touched, then get shipped live at the swap, resurrecting
		// a deleted product in search. Bailing out here instead leaves the
		// current live index untouched and the failure visible.
		$create_ok   = false !== $wpdb->query( $wpdb->prepare( 'CREATE TABLE IF NOT EXISTS %i LIKE %i', $stage_table, $main_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$truncate_ok = false !== $wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $stage_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange

		if ( ! $create_ok || ! $truncate_ok ) {
			Logger::log( sprintf( 'Rebuild setup failed for %s (create_ok=%d truncate_ok=%d): %s', $stage_table, (int) $create_ok, (int) $truncate_ok, $wpdb->last_error ), 'warning' );
			update_option( 'wcs_last_rebuild_error', 'rebuild_setup_failed', false );
			return;
		}

		// Typo-correction vocabulary (wcs_search_terms*) is a Pro-only feature —
		// this edition never creates those tables, so there is nothing to reset
		// here (see PORTING.md). Regression: this used to unconditionally run
		// CREATE TABLE ... LIKE wp_wcs_search_terms / TRUNCATE against tables
		// that never exist in Free, throwing a real SQL error on every rebuild.

		// Millisecond precision so two rebuilds triggered within the same
		// second (e.g. a settings save plus a term edit) get distinct epochs.
		$epoch = (int) ( microtime( true ) * 1000 );
		update_option( 'wcs_rebuild_epoch', $epoch, false );
		update_option( 'wcs_is_indexing', 1, false );
		update_option( 'wcs_reindex_processed', 0, false );
		delete_option( 'wcs_last_rebuild_error' );
		delete_option( 'wcs_rebuild_failed_count' );
		self::log( sprintf( 'NEW REBUILD epoch=%d', $epoch ) );
		self::enqueue_batch_with_retry( 0, $epoch );
	}

	/**
	 * Enqueue a rebuild batch (initial or continuation) for $epoch/$last_id,
	 * verifying it actually landed rather than assuming success.
	 *
	 * as_enqueue_async_action() returns 0 (not an error/exception) when it
	 * fails. This was first observed for the *initial* batch: this method's
	 * caller from Activator::init()'s migration path runs during
	 * plugins_loaded, and Action Scheduler initializes its own data store on
	 * a hook too — under some bootstrap orderings this plugin's migration
	 * code can run first. Confirmed live: a WP-CLI command (`wp cache
	 * flush`) run immediately after a schema-migrating upgrade logged
	 * "as_enqueue_async_action() was called before the Action Scheduler data
	 * store was initialized" and silently dropped the call — no action row,
	 * no error option, wcs_is_indexing stuck at 1 forever with nothing
	 * driving it. The same call, with the same failure mode, is also made
	 * from do_process_batch() for every *continuation* batch after the
	 * first — a transient Action Scheduler hiccup there would strand a
	 * rebuild mid-catalog just as silently, so both call sites route through
	 * here rather than calling as_enqueue_async_action() directly.
	 *
	 * WP-Cron doesn't share that race: it's core WordPress, always
	 * available, and a retry dispatched through it runs on its own later
	 * request, by which point Action Scheduler will have had a normal
	 * bootstrap. Capped at 5 attempts (~2.5 minutes) so a fundamentally
	 * broken/absent Action Scheduler surfaces as a recorded error instead of
	 * retrying forever.
	 *
	 * @param int $last_id Cursor this batch should start from (0 = first batch).
	 * @param int $epoch   Rebuild epoch this batch belongs to.
	 */
	private static function enqueue_batch_with_retry( int $last_id, int $epoch ): void {
		// $unique=false, $priority=10 — the trailing args were previously
		// (0, true), which cast true to priority 1 instead of the intended 10.
		$action_id = as_enqueue_async_action( 'wcs_rebuild_index_batch', array(
			'last_id' => $last_id,
			'epoch'   => $epoch,
		), 'turbo-search-for-woocommerce', false, 10 );

		if ( $action_id ) {
			return;
		}

		self::log( sprintf( 'Batch enqueue failed (last_id=%d epoch=%d) — falling back to WP-Cron retry', $last_id, $epoch ) );
		if ( ! wp_next_scheduled( 'wcs_retry_rebuild_scheduling', array( $epoch, $last_id ) ) ) {
			self::schedule_retry_or_fail( $epoch, $last_id );
		}
	}

	/**
	 * Schedule the WP-Cron retry for a failed batch enqueue — and, unlike a
	 * bare wp_schedule_single_event() call, actually check whether WP-Cron
	 * accepted it. wp_schedule_single_event() returns false (not an
	 * exception) if the event can't be stored. Left unchecked, that failure
	 * would leave a rebuild stranded with absolutely nothing driving it: no
	 * Action Scheduler action pending (that's what got us here) AND no cron
	 * event pending either, so retry_rebuild_scheduling() would simply never
	 * run and wcs_is_indexing would sit at 1 forever with no path to the
	 * eventual 'schedule_enqueue_failed' error this whole retry chain exists
	 * to produce. Failing the rebuild immediately here at least gives the
	 * admin the same actionable error retry exhaustion would have, instead
	 * of a silent, unrecoverable hang.
	 *
	 * @param int $epoch   Rebuild epoch.
	 * @param int $last_id Cursor the retried batch should resume from.
	 */
	private static function schedule_retry_or_fail( int $epoch, int $last_id ): void {
		if ( wp_schedule_single_event( time() + 30, 'wcs_retry_rebuild_scheduling', array( $epoch, $last_id ) ) ) {
			return;
		}

		Logger::log( sprintf( 'WP-Cron refused to schedule the rebuild retry (epoch=%d last_id=%d) — nothing left to drive this rebuild, halting', $epoch, $last_id ), 'warning' );
		update_option( 'wcs_last_rebuild_error', 'schedule_enqueue_failed', false );
		update_option( 'wcs_is_indexing', 0, false );
	}

	/**
	 * WP-Cron callback: retry enqueueing a rebuild batch after the enqueue in
	 * enqueue_batch_with_retry() failed. See that method's docblock for why
	 * this can happen and why WP-Cron (not another Action Scheduler call) is
	 * the retry mechanism.
	 *
	 * @param int $epoch   Rebuild epoch to retry. Ignored if a newer rebuild
	 *                     has already superseded it.
	 * @param int $last_id Cursor the retried batch should start from.
	 */
	public static function retry_rebuild_scheduling( int $epoch, int $last_id = 0 ): void {
		if ( (int) get_option( 'wcs_rebuild_epoch', 0 ) !== $epoch ) {
			return; // Superseded by a newer rebuild — this retry is stale.
		}
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		$attempts_key = 'wcs_schedule_retry_' . $epoch . '_' . $last_id;
		$attempts     = (int) get_transient( $attempts_key );

		if ( $attempts >= 5 ) {
			Logger::log( sprintf( 'Rebuild scheduling retries exhausted (epoch=%d last_id=%d) — halting', $epoch, $last_id ), 'warning' );
			update_option( 'wcs_last_rebuild_error', 'schedule_enqueue_failed', false );
			update_option( 'wcs_is_indexing', 0, false );
			delete_transient( $attempts_key );
			return;
		}

		$action_id = as_enqueue_async_action( 'wcs_rebuild_index_batch', array(
			'last_id' => $last_id,
			'epoch'   => $epoch,
		), 'turbo-search-for-woocommerce', false, 10 );

		if ( $action_id ) {
			delete_transient( $attempts_key );
			self::log( sprintf( 'Retry enqueue succeeded (epoch=%d last_id=%d, attempt %d)', $epoch, $last_id, $attempts + 1 ) );
			return;
		}

		set_transient( $attempts_key, $attempts + 1, HOUR_IN_SECONDS );
		Logger::log( sprintf( 'Retry enqueue failed again (epoch=%d last_id=%d, attempt %d/5)', $epoch, $last_id, $attempts + 1 ), 'warning' );
		self::schedule_retry_or_fail( $epoch, $last_id );
	}

	/**
	 * WP-Cron callback: retry enqueueing a single product's incremental
	 * update after the enqueue in queue_product_update() failed. Bounded to
	 * 5 attempts (~2.5 minutes); beyond that the product simply stays stale
	 * until its next save or a full rebuild, same as it always could if a
	 * merchant never touched it again — this only shortens that window for
	 * the common case where Action Scheduler was just having a moment.
	 *
	 * @param int $product_id Product to retry.
	 */
	public static function retry_product_enqueue( int $product_id ): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		$attempts_key = 'wcs_product_retry_' . $product_id;

		if ( as_has_scheduled_action( 'wcs_update_single_product', array( 'product_id' => $product_id ) ) ) {
			// Already queued since — e.g. the product was saved again, which
			// enqueues its own fresh action independently of this retry
			// chain. Clear the counter here too, not just on this chain's
			// own success below: without it, a stale count from THIS failed
			// attempt could survive (the transient's 1h TTL) and be
			// inherited by a later, unrelated failure for the same product,
			// exhausting that failure's retry budget early.
			delete_transient( $attempts_key );
			return;
		}

		$attempts = (int) get_transient( $attempts_key );

		if ( $attempts >= 5 ) {
			Logger::log( sprintf( 'Incremental update enqueue retries exhausted for product %d — giving up; it will resync on its next save or a full rebuild', $product_id ), 'warning' );
			delete_transient( $attempts_key );
			return;
		}

		$action_id = as_enqueue_async_action( 'wcs_update_single_product', array( 'product_id' => $product_id ), 'turbo-search-for-woocommerce' );
		if ( $action_id ) {
			delete_transient( $attempts_key );
			return;
		}

		set_transient( $attempts_key, $attempts + 1, HOUR_IN_SECONDS );
		if ( ! wp_schedule_single_event( time() + 30, 'wcs_retry_product_enqueue', array( $product_id ) ) ) {
			Logger::log( sprintf( 'WP-Cron refused to reschedule the incremental-update retry for product %d (attempt %d/5) — it will resync on its next save or a full rebuild', $product_id, $attempts + 1 ), 'warning' );
			self::add_pending_product_update( $product_id );
		}
	}

	/**
	 * Record a product whose incremental-update enqueue was just rejected by
	 * BOTH Action Scheduler and its own WP-Cron fallback in the same call —
	 * queue_product_update() and retry_product_enqueue() each hit this when
	 * as_enqueue_async_action() returns 0 AND the wp_schedule_single_event()
	 * meant to retry it also returns false. Neither scheduler's own bounded
	 * retry chain ever gets a chance to run in that case, so without this the
	 * product silently stays stale until its next save or a full rebuild.
	 *
	 * Drained by drain_pending_product_updates(), which runs from
	 * run_transient_gc() — already wired to both the daily
	 * wcs_daily_transient_gc cron and every cache-bust (including a
	 * successful full rebuild's own execute_cache_bust() call) — rather than
	 * a new dedicated schedule.
	 *
	 * @param int $product_id Product to remember.
	 */
	private static function add_pending_product_update( int $product_id ): void {
		$pending = get_option( 'wcs_pending_product_updates', array() );
		if ( ! is_array( $pending ) ) {
			$pending = array();
		}

		// Re-inserting moves the key to the end — PHP arrays preserve
		// insertion order, so the first key is always the longest-pending
		// entry, which is what the cap below trims.
		unset( $pending[ $product_id ] );
		$pending[ $product_id ] = true;

		while ( count( $pending ) > self::PENDING_UPDATE_CAP ) {
			reset( $pending );
			unset( $pending[ key( $pending ) ] );
		}

		update_option( 'wcs_pending_product_updates', $pending, false );
	}

	/**
	 * Resubmit every product recorded by add_pending_product_update() that
	 * isn't already scheduled. Called from run_transient_gc().
	 */
	public static function drain_pending_product_updates(): void {
		if ( ! function_exists( 'as_enqueue_async_action' ) || ! function_exists( 'as_has_scheduled_action' ) ) {
			return;
		}

		$pending = get_option( 'wcs_pending_product_updates', array() );
		if ( ! is_array( $pending ) || empty( $pending ) ) {
			return;
		}

		$remaining = $pending;
		foreach ( array_keys( $pending ) as $product_id ) {
			$product_id = (int) $product_id;

			if ( as_has_scheduled_action( 'wcs_update_single_product', array( 'product_id' => $product_id ) ) ) {
				unset( $remaining[ $product_id ] );
				continue;
			}

			if ( as_enqueue_async_action( 'wcs_update_single_product', array( 'product_id' => $product_id ), 'turbo-search-for-woocommerce' ) ) {
				unset( $remaining[ $product_id ] );
			}
			// Left in $remaining on failure — picked up again at the next drain.
		}

		if ( $remaining === $pending ) {
			return;
		}

		if ( empty( $remaining ) ) {
			delete_option( 'wcs_pending_product_updates' );
		} else {
			update_option( 'wcs_pending_product_updates', $remaining, false );
		}
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

		if ( as_has_scheduled_action( 'wcs_debounce_cache_bust' ) ) {
			self::$bust_queued = true;
			return;
		}

		// as_schedule_single_action() returns 0 (not an exception) on
		// failure. Leaving $bust_queued false lets a later trigger_cache_
		// bust() call THIS SAME request (another product write in a bulk
		// operation, say) try scheduling again instead of this one failure
		// suppressing every attempt for the rest of the request — but that
		// alone does not guarantee a later call ever happens: if this was
		// the request's only index write, cached results would otherwise
		// stay stale for the full 24h transient lifetime with nothing left
		// to retry the schedule at all. So bust immediately here instead as
		// the fallback, trading a touch more cache churn (only on an actual
		// AS scheduling failure, not the common path) for a bound on
		// staleness that doesn't depend on unrelated future activity.
		$action_id = as_schedule_single_action( time() + 300, 'wcs_debounce_cache_bust', array(), 'turbo-search-for-woocommerce' );
		if ( $action_id ) {
			self::$bust_queued = true;
			return;
		}

		self::log( 'Cache-bust scheduling failed — busting immediately as a fallback instead of risking indefinite staleness' );
		self::execute_cache_bust();
		self::$bust_queued = true; // the bust already happened; no need for a later call this request to retry scheduling it
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

		self::drain_pending_product_updates();

		// Prune stale rate-limit rows. Shared by both editions — search abuse
		// protection isn't a Pro feature. Each key is upserted in place (never
		// re-inserted), so the table doesn't grow with request volume, only
		// with unique-visitor count over the site's lifetime; this bounds that
		// slow growth. A generous 1-day cutoff is safe regardless of how any
		// individual limiter's own window is configured — a row that hasn't
		// been touched in a day is not mid-window for any sane window length.
		$rl_table    = $wpdb->prefix . 'wcs_rate_limits';
		$rl_suppress = $wpdb->suppress_errors( true );
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			'DELETE FROM %i WHERE window_start < %d',
			$rl_table,
			time() - DAY_IN_SECONDS
		) );
		$wpdb->suppress_errors( $rl_suppress );

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
