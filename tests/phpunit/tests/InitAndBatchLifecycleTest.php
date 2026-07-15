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
			'wp_trash_post',
			'before_delete_post',
			'wcs_rebuild_index_batch',
			'update_option_wcs_synonyms',
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
		$this->assertFalse( $route['args']['currency']['required'] );
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

	// ── Free edition product cap ─────────────────────────────────────────────

	public function test_batch_fetch_is_clamped_to_remaining_free_cap(): void {
		update_option( 'wcs_rebuild_epoch', 42 );
		update_option( 'wcs_is_indexing', 1 );
		update_option( 'wcs_reindex_processed', 95 ); // 5 remaining under the 100 cap
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
		$this->assertMatchesRegularExpression( '/ORDER BY ID ASC\s+LIMIT 5\b/', $sql, 'fetch must be clamped to the 5 slots remaining before the 100-product cap' );
	}

	public function test_rebuild_completion_flags_cap_reached_when_catalog_exceeds_cap(): void {
		update_option( 'wcs_rebuild_epoch', 42 );
		update_option( 'wcs_is_indexing', 1 );
		update_option( 'wcs_cache_version', 1 );
		$GLOBALS['wcs_test_publish_count'] = 150; // catalog bigger than the 100 cap
		$this->endOfCatalogHandler( true );

		Indexer::process_batch( 9999, 42 );

		$this->assertSame( 1, get_option( 'wcs_free_cap_reached' ) );
	}

	public function test_rebuild_completion_clears_cap_reached_flag_when_catalog_within_cap(): void {
		update_option( 'wcs_rebuild_epoch', 42 );
		update_option( 'wcs_is_indexing', 1 );
		update_option( 'wcs_cache_version', 1 );
		update_option( 'wcs_free_cap_reached', 1 );
		$GLOBALS['wcs_test_publish_count'] = 50; // catalog within the 100 cap
		$this->endOfCatalogHandler( true );

		Indexer::process_batch( 9999, 42 );

		$this->assertFalse( get_option( 'wcs_free_cap_reached', false ) );
	}

	public function test_new_product_indexed_live_once_cap_reached_is_blocked(): void {
		$GLOBALS['wcs_test_products'][20] = new Fake_Product( array( 'id' => 20, 'title' => 'New', 'price' => '9.99' ) );
		$this->wpdb->handler = static function ( string $sql, string $type ) {
			if ( 'var' === $type && str_contains( $sql, 'WHERE product_id' ) ) {
				return null; // product 20 is not already indexed
			}
			if ( 'var' === $type && str_contains( $sql, 'SELECT COUNT(*)' ) ) {
				return 100; // live index already at the cap
			}
			return 'query' === $type ? 1 : null;
		};

		Indexer::index_single_product( 20 );

		$sql = implode( "\n", $this->wpdb->queries );
		$this->assertStringNotContainsString( 'REPLACE INTO', $sql, 'a brand-new product must not be written once the live index is at the free cap' );
		$this->assertSame( 1, get_option( 'wcs_free_cap_reached' ) );
	}

	public function test_existing_product_still_updates_live_once_cap_reached(): void {
		$GLOBALS['wcs_test_products'][21] = new Fake_Product( array( 'id' => 21, 'title' => 'Existing', 'price' => '9.99' ) );
		$this->wpdb->handler = static function ( string $sql, string $type ) {
			if ( 'var' === $type && str_contains( $sql, 'WHERE product_id' ) ) {
				return 1; // product 21 is already indexed
			}
			if ( 'var' === $type && str_contains( $sql, 'SELECT COUNT(*)' ) ) {
				return 100; // live index already at the cap
			}
			return 'query' === $type ? 1 : null;
		};

		Indexer::index_single_product( 21 );

		$sql = implode( "\n", $this->wpdb->queries );
		$this->assertStringContainsString( 'REPLACE INTO', $sql, 'updating an already-indexed product must still be allowed at the cap' );
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
