<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WCS\Search\Frontend;
use WCS\Search\Indexer;
use WCS\Search\Search_Handler;
use WCS\Search\Admin_Settings;

/**
 * Hook registration for every module, the REST route contract, and the
 * batch lifecycle paths not covered elsewhere: the atomic swap, the
 * empty-staging abort, and the retry-once failure handler.
 */
final class InitAndBatchLifecycleTest extends TestCase {

	private Fake_WPDB $wpdb;

	protected function setUp(): void {
		wcs_tests_reset();
		$this->wpdb      = new Fake_WPDB();
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	// ── init() wiring ────────────────────────────────────────────────────────

	public function test_all_modules_register_their_hooks(): void {
		Search_Handler::init();
		Indexer::init();
		Frontend::init();
		Admin_Settings::init();

		foreach ( array(
			'rest_api_init',
			'woocommerce_update_product',
			'save_post_product',
			'woocommerce_update_product_variation',
			'woocommerce_new_product_variation',
			'woocommerce_variation_set_stock_status',
			'untrashed_post',
			'wp_trash_post',
			'before_delete_post',
			'wcs_rebuild_index_batch',
			'update_option_wcs_synonyms',
			'update_option_wcs_result_count',
			'update_option_wcs_show_out_of_stock',
			'wp_enqueue_scripts',
			'wp_ajax_wcs_refresh_nonce',
			'admin_menu',
			'admin_enqueue_scripts',
			'wp_ajax_wcs_rebuild_index',
		) as $hook ) {
			$this->assertArrayHasKey( $hook, $GLOBALS['wcs_test_filters'], "hook $hook must be registered" );
		}
	}

	public function test_rest_route_declares_required_q_and_nonce_params(): void {
		Search_Handler::register_routes();

		$route = $GLOBALS['wcs_test_rest_routes']['wcs/v1/search'];
		$this->assertTrue( $route['args']['q']['required'] );
		$this->assertTrue( $route['args']['_wpnonce']['required'] );
		// No `currency` arg in this edition — multi-currency is Pro-only and
		// this edition never reads it (see Search_Handler::register_routes()).
		$this->assertArrayNotHasKey( 'currency', $route['args'] );
		$this->assertNotEmpty( $route['permission_callback'], 'route must not be permission-less' );
	}

	public function test_post_save_hook_queues_products_only(): void {
		Indexer::queue_product_update_from_post( 5, new WP_Post( array( 'ID' => 5, 'post_type' => 'product' ) ) );
		Indexer::queue_product_update_from_post( 6, new WP_Post( array( 'ID' => 6, 'post_type' => 'page' ) ) );

		$queued = array_values( array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'wcs_update_single_product' === $c['hook'] ) );
		$this->assertCount( 1, $queued );
		$this->assertSame( 5, $queued[0]['args']['product_id'] );
	}

	public function test_trash_hooks_remove_products_but_ignore_other_post_types(): void {
		$GLOBALS['wcs_test_posts'][5] = (object) array( 'ID' => 5, 'post_type' => 'product', 'post_status' => 'publish' );
		$GLOBALS['wcs_test_posts'][6] = (object) array( 'ID' => 6, 'post_type' => 'page', 'post_status' => 'publish' );

		Indexer::on_product_trash( 5 );
		Indexer::on_product_delete( 6 );

		$sql = implode( "\n", $this->wpdb->queries );
		$this->assertStringContainsString( '{"product_id":5}', $sql );
		$this->assertStringNotContainsString( '{"product_id":6}', $sql );
	}

	/**
	 * Regression (Finding E): the indexed parent row carries variation SKUs
	 * and the variable product's price range, but this plugin only listened
	 * for hooks WooCommerce fires on the *parent* product — never the
	 * distinct hooks it fires for a variation (confirmed live against
	 * WooCommerce's own data stores: woocommerce_update_product_variation /
	 * woocommerce_new_product_variation / woocommerce_variation_set_stock_
	 * status are mutually exclusive with the parent-product hooks, never
	 * both). Editing, creating, or changing stock on a variation must queue
	 * its parent for reindexing, same as editing the parent directly.
	 */
	public function test_variation_hooks_queue_the_parent_not_the_variation(): void {
		$GLOBALS['wcs_test_posts'][20] = (object) array( 'ID' => 20, 'post_type' => 'product_variation', 'post_parent' => 3 );

		Indexer::queue_variation_update( 20 );

		$queued = array_values( array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'wcs_update_single_product' === $c['hook'] ) );
		$this->assertCount( 1, $queued );
		$this->assertSame( 3, $queued[0]['args']['product_id'] );
	}

	public function test_variation_with_no_parent_is_a_no_op(): void {
		$GLOBALS['wcs_test_posts'][20] = (object) array( 'ID' => 20, 'post_type' => 'product_variation', 'post_parent' => 0 );

		Indexer::queue_variation_update( 20 );

		$this->assertSame( array(), $GLOBALS['wcs_test_as_calls'] );
	}

	public function test_trashing_or_deleting_a_variation_queues_its_parent_for_reindex(): void {
		$GLOBALS['wcs_test_posts'][21] = (object) array( 'ID' => 21, 'post_type' => 'product_variation', 'post_parent' => 4 );
		$GLOBALS['wcs_test_posts'][22] = (object) array( 'ID' => 22, 'post_type' => 'product_variation', 'post_parent' => 5 );

		Indexer::on_product_trash( 21 );
		Indexer::on_product_delete( 22 );

		$queued = array_column(
			array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'wcs_update_single_product' === $c['hook'] ),
			'args'
		);
		$this->assertContains( array( 'product_id' => 4 ), $queued );
		$this->assertContains( array( 'product_id' => 5 ), $queued );
		// A variation is never itself an index row — no delete should be issued for it.
		$this->assertStringNotContainsString( '{"product_id":21}', implode( "\n", $this->wpdb->queries ) );
		$this->assertStringNotContainsString( '{"product_id":22}', implode( "\n", $this->wpdb->queries ) );
	}

	public function test_untrashing_a_product_or_its_variation_reindexes_the_right_thing(): void {
		$GLOBALS['wcs_test_posts'][6]  = (object) array( 'ID' => 6, 'post_type' => 'product', 'post_status' => 'publish' );
		$GLOBALS['wcs_test_posts'][23] = (object) array( 'ID' => 23, 'post_type' => 'product_variation', 'post_parent' => 7 );

		Indexer::on_product_untrash( 6 );
		Indexer::on_product_untrash( 23 );

		$queued = array_column(
			array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'wcs_update_single_product' === $c['hook'] ),
			'args'
		);
		$this->assertContains( array( 'product_id' => 6 ), $queued, 'restoring a product must queue itself' );
		$this->assertContains( array( 'product_id' => 7 ), $queued, 'restoring a variation must queue its parent' );
	}

	// ── Batch lifecycle: swap / abort / retry ────────────────────────────────

	/** Handler for a batch run that has consumed all products (cursor at end). */
	private function endOfCatalogHandler( bool $stage_has_rows ): void {
		$this->wpdb->handler = static function ( string $sql, string $type ) use ( $stage_has_rows ) {
			if ( 'col' === $type ) {
				return array(); // no products after cursor → finalize
			}
			if ( 'var' === $type && str_contains( $sql, 'SHOW TABLES' ) ) {
				preg_match( "/LIKE '([^']+)'/", $sql, $m );
				return $m[1] ?? null; // staging exists
			}
			if ( 'var' === $type && str_contains( $sql, 'SELECT 1 FROM' ) ) {
				return $stage_has_rows ? 1 : null;
			}
			return 'query' === $type ? 1 : null;
		};
	}

	public function test_final_batch_swaps_tables_atomically_and_finishes(): void {
		update_option( 'wcs_rebuild_epoch', 42 );
		update_option( 'wcs_is_indexing', 1 );
		update_option( 'wcs_cache_version', 1 );
		$this->endOfCatalogHandler( true );

		$fired = 0;
		add_filter( 'wcs_index_rebuild_complete', static function () use ( &$fired ) {
			++$fired;
			return null;
		} );

		Indexer::process_batch( 9999, 42 );

		$sql = implode( "\n", $this->wpdb->queries );
		$this->assertStringContainsString( 'RENAME TABLE', $sql );
		$this->assertSame( 0, get_option( 'wcs_is_indexing' ) );
		$this->assertSame( 2, get_option( 'wcs_cache_version' ), 'swap must bust the result cache' );
		$this->assertSame( 1, $fired, 'wcs_index_rebuild_complete must fire exactly once' );

		$optimize = array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'wcs_optimize_index' === $c['hook'] );
		$this->assertCount( 1, $optimize, 'OPTIMIZE must be dispatched async, never inline' );
	}

	public function test_empty_staging_aborts_the_swap_and_preserves_the_live_index(): void {
		update_option( 'wcs_rebuild_epoch', 42 );
		update_option( 'wcs_is_indexing', 1 );
		$this->endOfCatalogHandler( false );

		Indexer::process_batch( 9999, 42 );

		$sql = implode( "\n", $this->wpdb->queries );
		$this->assertStringNotContainsString( 'RENAME TABLE', $sql );
		$this->assertSame( 'staging_empty', get_option( 'wcs_last_rebuild_error' ) );
		$this->assertSame( 0, get_option( 'wcs_is_indexing' ) );
	}

	/**
	 * Regression: a rebuild that hit unresolved per-product write failures
	 * along the way (tracked via increment_rebuild_failure_count(), see
	 * do_index_single_product()) used to swap in the new index silently —
	 * $wpdb->replace() returning false was never even checked, so the admin
	 * had no way to learn some products didn't make it in. The swap must
	 * still happen (discarding an otherwise-good rebuild over one bad
	 * product is worse than surfacing the gap), but wcs_last_rebuild_error
	 * must now record it instead of reporting an unqualified success.
	 */
	public function test_swap_with_unresolved_write_failures_still_swaps_but_records_partial_failure(): void {
		update_option( 'wcs_rebuild_epoch', 42 );
		update_option( 'wcs_is_indexing', 1 );
		update_option( 'wcs_cache_version', 1 );
		update_option( 'wcs_rebuild_failed_count', 2 );
		$this->endOfCatalogHandler( true );

		Indexer::process_batch( 9999, 42 );

		$sql = implode( "\n", $this->wpdb->queries );
		$this->assertStringContainsString( 'RENAME TABLE', $sql, 'a partially-failed rebuild must still swap in' );
		$this->assertSame( 0, get_option( 'wcs_is_indexing' ) );
		$this->assertSame( 'partial_failure', get_option( 'wcs_last_rebuild_error' ) );
		$this->assertFalse( get_option( 'wcs_rebuild_failed_count', false ), 'the counter must be cleared for the next rebuild' );
	}

	public function test_swap_with_no_failures_clears_any_stale_error(): void {
		update_option( 'wcs_rebuild_epoch', 42 );
		update_option( 'wcs_is_indexing', 1 );
		update_option( 'wcs_cache_version', 1 );
		update_option( 'wcs_last_rebuild_error', 'staging_empty' ); // stale, from a previous attempt
		$this->endOfCatalogHandler( true );

		Indexer::process_batch( 9999, 42 );

		$this->assertFalse( get_option( 'wcs_last_rebuild_error', false ) );
	}

	public function test_mid_catalog_batch_enqueues_the_next_batch_with_advanced_cursor(): void {
		update_option( 'wcs_rebuild_epoch', 42 );
		update_option( 'wcs_is_indexing', 1 );
		$GLOBALS['wcs_test_posts'][10] = (object) array( 'ID' => 10, 'post_status' => 'publish', 'post_title' => 'A', 'post_excerpt' => '', 'post_type' => 'product' );
		$GLOBALS['wcs_test_posts'][11] = (object) array( 'ID' => 11, 'post_status' => 'publish', 'post_title' => 'B', 'post_excerpt' => '', 'post_type' => 'product' );
		$this->wpdb->handler = static function ( string $sql, string $type ) {
			if ( 'col' === $type ) {
				return array( 10, 11 );
			}
			if ( 'var' === $type && str_contains( $sql, 'SHOW TABLES' ) ) {
				preg_match( "/LIKE '([^']+)'/", $sql, $m );
				return $m[1] ?? null;
			}
			if ( 'results' === $type && str_contains( $sql, 'wc_product_meta_lookup' ) && ! str_contains( $sql, 'post_parent' ) ) {
				return array(
					10 => (object) array( 'product_id' => 10, 'sku' => '', 'min_price' => '1', 'max_price' => '1', 'stock_status' => 'instock', 'total_sales' => 0 ),
					11 => (object) array( 'product_id' => 11, 'sku' => '', 'min_price' => '1', 'max_price' => '1', 'stock_status' => 'instock', 'total_sales' => 0 ),
				);
			}
			if ( 'results' === $type ) {
				return array();
			}
			return 'query' === $type ? 1 : null;
		};

		Indexer::process_batch( 0, 42 );

		$this->assertSame( 2, get_option( 'wcs_reindex_processed' ) );
		$next = array_values( array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'wcs_rebuild_index_batch' === $c['hook'] && 'enqueue_async' === $c['fn'] ) );
		$this->assertCount( 1, $next );
		$this->assertSame( 11, $next[0]['args']['last_id'] );
		$this->assertSame( 42, $next[0]['args']['epoch'] );
	}

	/**
	 * Regression: the initial rebuild batch's own enqueue was already
	 * verified (1.9.1) and falls back to a WP-Cron retry when Action
	 * Scheduler rejects it, but every *continuation* enqueue after the first
	 * batch still called as_enqueue_async_action() directly with the return
	 * value discarded — a transient failure there would strand a rebuild
	 * mid-catalog with wcs_is_indexing stuck at 1 and nothing driving it,
	 * identically to the bug 1.9.1 fixed for the first batch. Both
	 * continuation call sites in do_process_batch() now route through the
	 * same enqueue_batch_with_retry() helper as the first batch.
	 */
	public function test_continuation_enqueue_failure_falls_back_to_wp_cron_retry(): void {
		update_option( 'wcs_rebuild_epoch', 42 );
		update_option( 'wcs_is_indexing', 1 );
		$GLOBALS['wcs_test_posts'][10] = (object) array( 'ID' => 10, 'post_status' => 'publish', 'post_title' => 'A', 'post_excerpt' => '', 'post_type' => 'product' );
		$GLOBALS['wcs_test_posts'][11] = (object) array( 'ID' => 11, 'post_status' => 'publish', 'post_title' => 'B', 'post_excerpt' => '', 'post_type' => 'product' );
		$this->wpdb->handler = static function ( string $sql, string $type ) {
			if ( 'col' === $type ) {
				return array( 10, 11 );
			}
			if ( 'var' === $type && str_contains( $sql, 'SHOW TABLES' ) ) {
				preg_match( "/LIKE '([^']+)'/", $sql, $m );
				return $m[1] ?? null;
			}
			if ( 'results' === $type && str_contains( $sql, 'wc_product_meta_lookup' ) && ! str_contains( $sql, 'post_parent' ) ) {
				return array(
					10 => (object) array( 'product_id' => 10, 'sku' => '', 'min_price' => '1', 'max_price' => '1', 'stock_status' => 'instock', 'total_sales' => 0 ),
					11 => (object) array( 'product_id' => 11, 'sku' => '', 'min_price' => '1', 'max_price' => '1', 'stock_status' => 'instock', 'total_sales' => 0 ),
				);
			}
			if ( 'results' === $type ) {
				return array();
			}
			return 'query' === $type ? 1 : null;
		};
		$GLOBALS['wcs_test_as_enqueue_fails'] = true;

		Indexer::process_batch( 0, 42 );

		$this->assertSame( 1, get_option( 'wcs_is_indexing' ), 'still marked indexing — the WP-Cron fallback owns recovery' );
		$scheduled = array_values( array_filter( $GLOBALS['wcs_test_single_events'], static fn( $e ) => 'wcs_retry_rebuild_scheduling' === $e['hook'] ) );
		$this->assertNotEmpty( $scheduled, 'a WP-Cron retry must be scheduled when the continuation enqueue fails' );
		$this->assertSame( array( 42, 11 ), $scheduled[0]['args'], 'the retry must carry the cursor the batch actually reached, not restart at 0' );
	}

	/**
	 * Regression: when the bulk write fails, do_process_batch() falls back
	 * to writing each product in the chunk individually — but that fallback
	 * silently discarded a false return from $wpdb->replace() too, so a
	 * batch where every product's write genuinely failed still advanced the
	 * cursor and, on a small enough catalog, could reach the final swap.
	 * With the write-failure check in place, every product in the one-item
	 * chunk here fails and the chain halts before ever reaching a swap,
	 * preserving the existing live index and recording a specific error.
	 */
	public function test_batch_where_every_product_write_fails_halts_without_swapping(): void {
		update_option( 'wcs_rebuild_epoch', 42 );
		update_option( 'wcs_is_indexing', 1 );
		$GLOBALS['wcs_test_posts'][10]    = (object) array( 'ID' => 10, 'post_status' => 'publish', 'post_title' => 'A', 'post_excerpt' => '', 'post_type' => 'product' );
		$GLOBALS['wcs_test_products'][10] = new Fake_Product( array( 'id' => 10, 'title' => 'A' ) );
		$this->wpdb->handler = static function ( string $sql, string $type ) {
			if ( 'col' === $type ) {
				return array( 10 );
			}
			if ( 'var' === $type && str_contains( $sql, 'SHOW TABLES' ) ) {
				preg_match( "/LIKE '([^']+)'/", $sql, $m );
				return $m[1] ?? null;
			}
			if ( 'results' === $type ) {
				return array(); // no lookup rows either way
			}
			// The bulk multi-row REPLACE goes through $wpdb->query(), not
			// replace() — fail it so do_process_batch() falls back to the
			// per-product path, where replaceFails (below) fails every write.
			return 'query' === $type ? false : null;
		};
		$this->wpdb->replaceFails = true;

		Indexer::process_batch( 0, 42 );

		$sql = implode( "\n", $this->wpdb->queries );
		$this->assertStringNotContainsString( 'RENAME TABLE', $sql, 'a fully-failed batch must never swap in' );
		$this->assertSame( 0, get_option( 'wcs_is_indexing' ) );
		$this->assertSame( 'batch_write_failed', get_option( 'wcs_last_rebuild_error' ) );
		$this->assertFalse( get_option( 'wcs_rebuild_failed_count', false ) );
	}

	/**
	 * Regression: a single retry (falling back from the bulk write to the
	 * per-product path) is thin protection against a genuinely transient
	 * failure — a lock wait timeout can just as easily hit that one retry
	 * too. do_process_batch()'s per-product fallback now attempts each
	 * product up to 3 times before giving up, so a failure that clears
	 * within a couple of attempts never counts against the product or shows
	 * up as a partial_failure at all.
	 */
	public function test_transient_write_failure_recovers_within_retries_without_being_counted(): void {
		update_option( 'wcs_rebuild_epoch', 42 );
		update_option( 'wcs_is_indexing', 1 );
		$GLOBALS['wcs_test_posts'][10]    = (object) array( 'ID' => 10, 'post_status' => 'publish', 'post_title' => 'A', 'post_excerpt' => '', 'post_type' => 'product' );
		$GLOBALS['wcs_test_products'][10] = new Fake_Product( array( 'id' => 10, 'title' => 'A' ) );
		$this->wpdb->handler = static function ( string $sql, string $type ) {
			if ( 'col' === $type ) {
				return array( 10 );
			}
			if ( 'var' === $type && str_contains( $sql, 'SHOW TABLES' ) ) {
				preg_match( "/LIKE '([^']+)'/", $sql, $m );
				return $m[1] ?? null;
			}
			if ( 'results' === $type ) {
				return array();
			}
			return 'query' === $type ? false : null; // force the bulk path to fail, entering the per-product fallback
		};
		$calls = 0;
		$this->wpdb->replaceFails = static function () use ( &$calls ): bool {
			++$calls;
			return $calls < 3; // fails attempts 1 and 2, succeeds on attempt 3
		};

		Indexer::process_batch( 0, 42 );

		$this->assertSame( 0, (int) get_option( 'wcs_rebuild_failed_count', 0 ), 'a failure that resolved within retries must not be counted' );
		$this->assertSame( 1, get_option( 'wcs_is_indexing' ), 'the batch succeeded — rebuild continues normally, not halted' );
	}

	/**
	 * Regression: increment_rebuild_failure_count() used to be called from
	 * inside do_index_single_product() itself, which the 3-attempt retry
	 * above now calls up to 3 times for the SAME product — counting a single
	 * permanently-failing product as three separate failures. The count must
	 * reflect distinct failed products, not raw write attempts.
	 */
	public function test_a_permanently_failing_product_is_counted_once_not_once_per_attempt(): void {
		update_option( 'wcs_rebuild_epoch', 42 );
		update_option( 'wcs_is_indexing', 1 );
		$GLOBALS['wcs_test_posts'][10]    = (object) array( 'ID' => 10, 'post_status' => 'publish', 'post_title' => 'Good', 'post_excerpt' => '', 'post_type' => 'product' );
		$GLOBALS['wcs_test_posts'][11]    = (object) array( 'ID' => 11, 'post_status' => 'publish', 'post_title' => 'Bad', 'post_excerpt' => '', 'post_type' => 'product' );
		$GLOBALS['wcs_test_products'][10] = new Fake_Product( array( 'id' => 10, 'title' => 'Good' ) );
		$GLOBALS['wcs_test_products'][11] = new Fake_Product( array( 'id' => 11, 'title' => 'Bad' ) );
		$this->wpdb->handler = static function ( string $sql, string $type ) {
			if ( 'col' === $type ) {
				return array( 10, 11 );
			}
			if ( 'var' === $type && str_contains( $sql, 'SHOW TABLES' ) ) {
				preg_match( "/LIKE '([^']+)'/", $sql, $m );
				return $m[1] ?? null;
			}
			if ( 'results' === $type ) {
				return array();
			}
			return 'query' === $type ? false : null;
		};
		// Only product 11's row fails, and it fails every attempt.
		$this->wpdb->replaceFails = static fn( string $table, array $data ): bool => 11 === (int) ( $data['product_id'] ?? 0 );

		Indexer::process_batch( 0, 42 );

		$this->assertSame( 1, (int) get_option( 'wcs_rebuild_failed_count', 0 ), 'one bad product across 3 attempts must count as one failure, not three' );
		// Only 1 of 2 products failed, not the whole batch — the chain continues rather than halting.
		$this->assertSame( 1, get_option( 'wcs_is_indexing' ) );
	}

	public function test_batch_fetch_query_excludes_hidden_and_password_protected_products(): void {
		update_option( 'wcs_rebuild_epoch', 42 );
		update_option( 'wcs_is_indexing', 1 );
		$this->wpdb->handler = static function ( string $sql, string $type ) {
			if ( 'col' === $type ) {
				return array();
			}
			return 'query' === $type ? 1 : null;
		};

		Indexer::process_batch( 0, 42 );

		$sql = implode( "\n", $this->wpdb->queries );
		$this->assertStringContainsString( "p.post_password = ''", $sql );
		$this->assertStringContainsString( "tt.taxonomy = 'product_visibility'", $sql );
		$this->assertStringContainsString( "t.slug = 'exclude-from-search'", $sql );
	}

	// ── Free edition indexes the full catalog, no product cap ───────────────

	public function test_batch_fetch_is_not_capped_by_previously_processed_count(): void {
		update_option( 'wcs_rebuild_epoch', 42 );
		update_option( 'wcs_is_indexing', 1 );
		// Under the old 100-product cap this would have zeroed the remaining
		// allowance (100 - 500 clamped to 0) and starved every subsequent
		// fetch. The free edition has no such cap any more.
		update_option( 'wcs_reindex_processed', 500 );
		$this->wpdb->handler = static function ( string $sql, string $type ) {
			if ( 'col' === $type ) {
				return array();
			}
			if ( 'var' === $type && str_contains( $sql, 'SHOW TABLES' ) ) {
				preg_match( "/LIKE '([^']+)'/", $sql, $m );
				return $m[1] ?? null;
			}
			return 'query' === $type ? 1 : null;
		};

		Indexer::process_batch( 0, 42 );

		$sql = implode( "\n", $this->wpdb->queries );
		$this->assertMatchesRegularExpression( '/ORDER BY p\.ID ASC\s+LIMIT (\d+)/', $sql );
		preg_match( '/ORDER BY p\.ID ASC\s+LIMIT (\d+)/', $sql, $matches );
		$this->assertGreaterThanOrEqual( 10, (int) $matches[1], 'fetch limit must be a real batch size, never zeroed by a removed cap' );
	}

	public function test_rebuild_completes_on_a_catalog_over_a_hundred_products_with_no_cap_flag(): void {
		update_option( 'wcs_rebuild_epoch', 42 );
		update_option( 'wcs_is_indexing', 1 );
		update_option( 'wcs_cache_version', 1 );
		$GLOBALS['wcs_test_publish_count'] = 250; // catalog well past the old 100-product cap
		$this->endOfCatalogHandler( true );

		Indexer::process_batch( 9999, 42 );

		$this->assertSame( 0, get_option( 'wcs_is_indexing' ), 'rebuild must complete normally past 100 products' );
		$this->assertSame( 'not-set', get_option( 'wcs_free_cap_reached', 'not-set' ), 'no cap-reached flag should ever be written any more' );
	}

	public function test_new_product_is_indexed_live_regardless_of_existing_index_size(): void {
		$GLOBALS['wcs_test_products'][20] = new Fake_Product( array( 'id' => 20, 'title' => 'New', 'price' => '9.99' ) );
		$this->wpdb->handler = static function ( string $sql, string $type ) {
			if ( 'var' === $type && str_contains( $sql, 'WHERE product_id' ) ) {
				return null; // product 20 is not already indexed
			}
			return 'query' === $type ? 1 : null;
		};

		Indexer::index_single_product( 20 );

		$sql = implode( "\n", $this->wpdb->queries );
		$this->assertStringContainsString( 'REPLACE INTO', $sql, 'a brand-new product must always be written — this edition has no live-index size limit' );
		$this->assertSame( 'not-set', get_option( 'wcs_free_cap_reached', 'not-set' ) );
	}

	// ── Failure handler: retry once per cursor per epoch ─────────────────────

	public function test_failed_batch_is_retried_once_then_halts(): void {
		update_option( 'wcs_rebuild_epoch', 42 );
		update_option( 'wcs_is_indexing', 1 );
		$GLOBALS['wcs_test_as_actions'][7] = new ActionScheduler_Action(
			'wcs_rebuild_index_batch',
			array( 'last_id' => 500, 'epoch' => 42 )
		);

		// First failure → retry enqueued, flag stays up.
		Indexer::on_batch_action_failed( 7 );
		$retries = array_values( array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'wcs_rebuild_index_batch' === $c['hook'] && 'enqueue_async' === $c['fn'] ) );
		$this->assertCount( 1, $retries );
		$this->assertSame( 500, $retries[0]['args']['last_id'] );
		$this->assertSame( 1, get_option( 'wcs_is_indexing' ) );

		// Second failure at the same cursor → retry exhausted, flag cleared.
		Indexer::on_batch_action_failed( 7 );
		$retries = array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'wcs_rebuild_index_batch' === $c['hook'] && 'enqueue_async' === $c['fn'] );
		$this->assertCount( 1, $retries, 'no second retry for the same cursor+epoch' );
		$this->assertSame( 0, get_option( 'wcs_is_indexing' ) );
	}

	public function test_failed_batch_from_a_superseded_epoch_is_ignored(): void {
		update_option( 'wcs_rebuild_epoch', 43 ); // a newer rebuild started
		update_option( 'wcs_is_indexing', 1 );
		$GLOBALS['wcs_test_as_actions'][7] = new ActionScheduler_Action(
			'wcs_rebuild_index_batch',
			array( 'last_id' => 500, 'epoch' => 42 )
		);

		Indexer::on_batch_action_failed( 7 );

		$this->assertSame( array(), array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'enqueue_async' === $c['fn'] ) );
		$this->assertSame( 1, get_option( 'wcs_is_indexing' ), 'the newer rebuild must keep running' );
	}

	public function test_failed_unrelated_action_is_ignored(): void {
		$GLOBALS['wcs_test_as_actions'][8] = new ActionScheduler_Action( 'some_other_plugin_hook', array() );

		Indexer::on_batch_action_failed( 8 );

		$this->assertSame( array(), $GLOBALS['wcs_test_as_calls'] );
	}

	// ── Async OPTIMIZE ───────────────────────────────────────────────────────

	public function test_run_optimize_optimizes_and_clears_the_phase(): void {
		update_option( 'wcs_rebuild_phase', 'optimizing' );

		Indexer::run_optimize();

		$this->assertStringContainsString( 'OPTIMIZE TABLE', implode( ' ', $this->wpdb->queries ) );
		$this->assertFalse( get_option( 'wcs_rebuild_phase' ) );
	}
}
