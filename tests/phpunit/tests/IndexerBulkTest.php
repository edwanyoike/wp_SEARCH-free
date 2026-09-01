<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WCS\Search\Indexer;

/**
 * The set-based bulk indexing path (index_products_bulk) and the
 * single-product path, driven through scripted lookup rows.
 */
final class IndexerBulkTest extends TestCase {

	private Fake_WPDB $wpdb;

	protected function setUp(): void {
		wcs_tests_reset();
		$this->wpdb      = new Fake_WPDB();
		$GLOBALS['wpdb'] = $this->wpdb;

		update_option( 'wcs_search_title', 1 );
		update_option( 'wcs_search_sku', 1 );
		update_option( 'wcs_search_content', 1 );
		update_option( 'wcs_search_taxonomy', 1 );
	}

	private function bulk( array $ids, string $table = 'wp_wcs_search_index_stage' ): void {
		$method = new ReflectionMethod( Indexer::class, 'index_products_bulk' );
		$method->invoke( null, $ids, $table );
	}

	private function post( int $id, string $title, string $status = 'publish', string $excerpt = '' ): void {
		$GLOBALS['wcs_test_posts'][ $id ] = (object) array(
			'ID'           => $id,
			'post_status'  => $status,
			'post_title'   => $title,
			'post_excerpt' => $excerpt,
			'post_type'    => 'product',
		);
	}

	private function lookupHandler( array $lookup_rows, array $variation_rows = array() ): void {
		$this->wpdb->handler = static function ( string $sql, string $type ) use ( $lookup_rows, $variation_rows ) {
			if ( 'results' === $type && str_contains( $sql, 'wc_order_product_lookup' ) ) {
				return array(); // no recent sales in these fixtures
			}
			if ( 'results' === $type && str_contains( $sql, 'wc_product_meta_lookup' ) && str_contains( $sql, 'post_parent' ) ) {
				return $variation_rows;
			}
			if ( 'results' === $type && str_contains( $sql, 'wc_product_meta_lookup' ) ) {
				return $lookup_rows;
			}
			return 'query' === $type ? 1 : null;
		};
	}

	private function lookupRow( int $id, string $sku = '', string $min = '10.00', string $max = '20.00', int $sales = 7 ): object {
		return (object) array(
			'product_id'   => $id,
			'sku'          => $sku,
			'min_price'    => $min,
			'max_price'    => $max,
			'stock_status' => 'instock',
			'total_sales'  => $sales,
		);
	}

	public function test_bulk_never_queries_wc_order_product_lookup(): void {
		// Regression: sales_30d used to be a real aggregate query against
		// wc_order_product_lookup on every save and rebuild chunk, but its
		// weight is always pinned to 0.0 in Search_Handler in this edition
		// (recent-sales ranking is Pro-only) — the value can never affect a
		// ranking, so it must always be written as a literal 0, not computed.
		$this->post( 1, 'Red Lamp' );
		$this->lookupHandler( array( 1 => $this->lookupRow( 1 ) ) );

		$this->bulk( array( 1 ) );

		$sql = implode( ' ', $this->wpdb->queries );
		$this->assertStringNotContainsString( 'wc_order_product_lookup', $sql );
		$replace = array_values( array_filter( $this->wpdb->queries, static fn( $q ) => str_starts_with( $q, 'REPLACE INTO' ) ) );
		// Columns are ...,total_sales,sales_30d,... — lookupRow()'s default
		// total_sales is 7, so ',7,0,' unambiguously pins sales_30d to 0.
		$this->assertStringContainsString( ',7,0,', $replace[0], 'sales_30d column must be the literal 0' );
	}

	public function test_bulk_writes_one_multirow_replace_from_lookup_data(): void {
		$this->post( 1, 'Red Lamp', 'publish', 'warm light' );
		$this->post( 2, 'Blue Lamp' );
		$GLOBALS['wcs_test_terms'][1]['product_cat'] = array( (object) array( 'name' => 'Lighting' ) );
		$GLOBALS['wcs_test_thumbs'][1]               = 55;
		$this->lookupHandler( array( 1 => $this->lookupRow( 1, 'RL-1' ), 2 => $this->lookupRow( 2, 'BL-2' ) ) );

		$this->bulk( array( 1, 2 ) );

		$replace = array_values( array_filter( $this->wpdb->queries, static fn( $q ) => str_starts_with( $q, 'REPLACE INTO' ) ) );
		$this->assertCount( 1, $replace, 'whole chunk must be one REPLACE statement' );
		$sql = $replace[0];
		$this->assertStringContainsString( 'Red Lamp', $sql );
		$this->assertStringContainsString( 'Blue Lamp', $sql );
		$this->assertStringContainsString( 'RL-1', $sql );
		$this->assertStringContainsString( "'rl1'", $sql ); // normalized SKU column
		$this->assertStringContainsString( 'sku_normalized', $sql );
		$this->assertStringContainsString( 'sales_30d', $sql );
		$this->assertStringContainsString( 'warm light Lighting', $sql );        // excerpt + term names
		$this->assertStringContainsString( 'https://example.test/img/55.jpg', $sql ); // primed thumbnail
		$this->assertStringContainsString( '10,20', $sql ); // lookup min/max prices
	}

	public function test_bulk_rebuild_never_writes_a_vocabulary_sidecar(): void {
		// Typo-correction vocabulary (wcs_search_terms*) is a Pro-only feature —
		// PORTING.md lists it as a region Free's indexer must never touch, and
		// Activator never creates those tables here. Regression: this used to
		// assert the opposite (Free wrote to wcs_search_terms_stage during a
		// rebuild), which meant every real full rebuild threw a "table doesn't
		// exist" SQL error that only this test's mocked $wpdb didn't catch.
		$this->post( 1, 'Red Lamp' );
		$this->lookupHandler( array( 1 => $this->lookupRow( 1, 'RL-1' ) ) );

		$this->bulk( array( 1 ) ); // target: staging table

		$this->assertSame( array(), array_filter( $this->wpdb->queries, static fn( $q ) => str_contains( $q, 'wcs_search_terms' ) ) );
	}

	public function test_live_single_updates_do_not_touch_the_vocabulary(): void {
		$this->post( 1, 'Red Lamp' );
		$this->lookupHandler( array( 1 => $this->lookupRow( 1 ) ) );

		$this->bulk( array( 1 ), 'wp_wcs_search_index' ); // live table target

		$this->assertSame( array(), array_filter( $this->wpdb->queries, static fn( $q ) => str_contains( $q, 'wcs_search_terms' ) ) );
	}

	public function test_bulk_appends_variation_skus_to_content(): void {
		$this->post( 1, 'Variable Lamp' );
		$this->lookupHandler(
			array( 1 => $this->lookupRow( 1, 'VL' ) ),
			array(
				(object) array( 'parent_id' => 1, 'sku' => 'VL-RED' ),
				(object) array( 'parent_id' => 1, 'sku' => 'VL-BLUE' ),
			)
		);

		$this->bulk( array( 1 ) );

		$replace = implode( ' ', $this->wpdb->queries );
		$this->assertStringContainsString( 'VL-RED VL-BLUE', $replace );
	}

	public function test_bulk_respects_disabled_field_settings(): void {
		update_option( 'wcs_search_sku', 0 );
		update_option( 'wcs_search_content', 0 );
		update_option( 'wcs_search_taxonomy', 0 );
		$this->post( 1, 'Lamp', 'publish', 'secret excerpt' );
		$GLOBALS['wcs_test_terms'][1]['product_cat'] = array( (object) array( 'name' => 'SecretCat' ) );
		$this->lookupHandler( array( 1 => $this->lookupRow( 1, 'SECRET-SKU' ) ) );

		$this->bulk( array( 1 ) );

		$sql = implode( ' ', $this->wpdb->queries );
		$this->assertStringNotContainsString( 'SECRET-SKU', $sql );
		$this->assertStringNotContainsString( 'secret excerpt', $sql );
		$this->assertStringNotContainsString( 'SecretCat', $sql );
	}

	public function test_bulk_deletes_unpublished_products_from_the_target_table(): void {
		$this->post( 1, 'Gone Lamp', 'draft' );
		$this->lookupHandler( array( 1 => $this->lookupRow( 1 ) ) );

		$this->bulk( array( 1 ) );

		$sql = implode( ' ', $this->wpdb->queries );
		$this->assertStringContainsString( 'DELETE FROM wp_wcs_search_index_stage', $sql );
		$this->assertStringNotContainsString( 'REPLACE INTO', $sql );
	}

	public function test_bulk_skips_products_excluded_from_search(): void {
		$this->post( 1, 'Hidden Lamp' );
		$GLOBALS['wcs_test_search_excluded_ids'][] = 1;
		$this->lookupHandler( array( 1 => $this->lookupRow( 1 ) ) );

		$this->bulk( array( 1 ) );

		$sql = implode( ' ', $this->wpdb->queries );
		$this->assertStringContainsString( 'DELETE FROM wp_wcs_search_index_stage', $sql );
		$this->assertStringNotContainsString( 'REPLACE INTO', $sql );
	}

	public function test_bulk_skips_password_protected_products(): void {
		$this->post( 1, 'Secret Lamp' );
		$GLOBALS['wcs_test_posts'][1]->post_password = 'shh';
		$this->lookupHandler( array( 1 => $this->lookupRow( 1 ) ) );

		$this->bulk( array( 1 ) );

		$sql = implode( ' ', $this->wpdb->queries );
		$this->assertStringContainsString( 'DELETE FROM wp_wcs_search_index_stage', $sql );
		$this->assertStringNotContainsString( 'REPLACE INTO', $sql );
	}

	public function test_bulk_throws_when_the_write_fails_so_the_chunk_can_fall_back(): void {
		$this->post( 1, 'Lamp' );
		$this->wpdb->handler = static function ( string $sql, string $type ) {
			if ( 'results' === $type && ( str_contains( $sql, 'post_parent' ) || str_contains( $sql, 'wc_order_product_lookup' ) ) ) {
				return array(); // no variations, no recent sales
			}
			if ( 'results' === $type ) {
				return array( 1 => (object) array( 'product_id' => 1, 'sku' => '', 'min_price' => '1', 'max_price' => '1', 'stock_status' => 'instock', 'total_sales' => 0 ) );
			}
			return 'query' === $type ? false : null; // REPLACE fails
		};

		$this->expectException( RuntimeException::class );
		$this->bulk( array( 1 ) );
	}

	// ── Single-product path ──────────────────────────────────────────────────

	public function test_single_product_missing_or_unpublished_is_deleted(): void {
		// No product registered → wc_get_product returns false.
		Indexer::index_single_product( 99 );

		$sql = implode( ' ', $this->wpdb->queries );
		$this->assertStringContainsString( 'DELETE FROM wp_wcs_search_index', $sql );
	}

	public function test_single_product_excluded_from_search_is_not_indexed(): void {
		// A merchant's "Catalog visibility: Hidden"/"Shop only" choice must be
		// honored — the public REST search endpoint has no capability check.
		$GLOBALS['wcs_test_products'][8]           = new Fake_Product( array( 'id' => 8, 'title' => 'Hidden Lamp' ) );
		$GLOBALS['wcs_test_search_excluded_ids'][] = 8;

		Indexer::index_single_product( 8 );

		$sql = implode( ' ', $this->wpdb->queries );
		$this->assertStringContainsString( 'DELETE FROM wp_wcs_search_index', $sql );
		$this->assertStringNotContainsString( 'REPLACE', $sql );
	}

	public function test_single_password_protected_product_is_not_indexed(): void {
		$GLOBALS['wcs_test_products'][9] = new Fake_Product( array( 'id' => 9, 'title' => 'Secret Lamp' ) );
		$GLOBALS['wcs_test_posts'][9]    = (object) array( 'ID' => 9, 'post_password' => 'shh' );

		Indexer::index_single_product( 9 );

		$sql = implode( ' ', $this->wpdb->queries );
		$this->assertStringContainsString( 'DELETE FROM wp_wcs_search_index', $sql );
		$this->assertStringNotContainsString( 'REPLACE', $sql );
	}

	public function test_variation_queues_its_parent_instead_of_indexing_itself(): void {
		$GLOBALS['wcs_test_products'][5] = new Fake_Product( array( 'id' => 5, 'type' => 'variation', 'parent_id' => 3 ) );

		Indexer::index_single_product( 5 );

		$queued = array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'wcs_update_single_product' === $c['hook'] );
		$this->assertCount( 1, $queued );
		$this->assertSame( array( 'product_id' => 3 ), array_values( $queued )[0]['args'] );
		$this->assertStringNotContainsString( 'REPLACE', implode( ' ', $this->wpdb->queries ) );
	}

	public function test_single_simple_product_is_written_with_live_mirror_to_staging_during_rebuild(): void {
		update_option( 'wcs_is_indexing', 1 );
		$GLOBALS['wcs_test_products'][7] = new Fake_Product( array(
			'id'    => 7,
			'title' => 'Live Lamp',
			'sku'   => 'LL-7',
			'price' => '9.99',
		) );

		Indexer::index_single_product( 7 );

		$sql = implode( "\n", $this->wpdb->queries );
		$this->assertStringContainsString( 'REPLACE INTO wp_wcs_search_index_stage', $sql );
		$this->assertStringContainsString( 'REPLACE INTO wp_wcs_search_index ', $sql );
	}

	// ── Hooks around indexing ────────────────────────────────────────────────

	public function test_term_edit_on_unindexed_taxonomy_is_ignored(): void {
		$GLOBALS['wcs_test_objects_in_term'] = array( 1, 2 );

		Indexer::on_term_edited( 10, 10, 'post_tag' );

		$this->assertSame( array(), $GLOBALS['wcs_test_as_calls'] );
	}

	public function test_term_edit_queues_each_product_for_small_terms(): void {
		$GLOBALS['wcs_test_objects_in_term'] = array( 1, 2, 3 );

		Indexer::on_term_edited( 10, 10, 'product_cat' );

		$queued = array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'wcs_update_single_product' === $c['hook'] );
		$this->assertCount( 3, $queued );
	}

	public function test_term_edit_falls_back_to_full_rebuild_for_large_terms(): void {
		$GLOBALS['wcs_test_objects_in_term'] = range( 1, 150 ); // > 2 × BATCH_SIZE

		Indexer::on_term_edited( 10, 10, 'product_cat' );

		$batch = array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'wcs_rebuild_index_batch' === $c['hook'] && 'enqueue_async' === $c['fn'] );
		$this->assertCount( 1, $batch );
		$single = array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'wcs_update_single_product' === $c['hook'] );
		$this->assertCount( 0, $single );
	}

	public function test_scheduled_sales_queues_on_sale_products(): void {
		$GLOBALS['wcs_test_on_sale_ids'] = array( 4, 5 );

		Indexer::on_scheduled_sales();

		$queued = array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'wcs_update_single_product' === $c['hook'] );
		$this->assertCount( 2, $queued );
	}

	public function test_http_blocker_allows_loopback_and_blocks_external(): void {
		$this->assertFalse( Indexer::block_http_during_batch( false, array(), 'https://example.test/wp-cron.php' ) );
		$blocked = Indexer::block_http_during_batch( false, array(), 'https://api.wordpress.org/updates' );
		$this->assertInstanceOf( WP_Error::class, $blocked );
	}

	public function test_index_field_setting_change_triggers_one_rebuild(): void {
		Indexer::on_index_field_setting_changed( 1, 0 );
		Indexer::on_index_field_setting_changed( 1, 0 ); // second change same request
		Indexer::on_index_field_setting_changed( 1, 1 ); // unchanged value

		$batch = array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'wcs_rebuild_index_batch' === $c['hook'] && 'enqueue_async' === $c['fn'] );
		$this->assertCount( 1, $batch );
	}

	public function test_start_rebuild_clears_a_stale_error_from_a_previous_attempt(): void {
		update_option( 'wcs_last_rebuild_error', 'stuck_no_batch_dispatched' );

		Indexer::start_rebuild();

		$this->assertFalse( get_option( 'wcs_last_rebuild_error' ) );
	}

	public function test_start_rebuild_never_uses_the_args_blind_unique_flag(): void {
		// Regression: every wcs_rebuild_index_batch enqueue in this file used
		// to pass (0, true) for the trailing (unique, priority) args — under
		// the real Action Scheduler API that's unique=false/priority=1, not
		// the intended priority=10, and the test bootstrap's own stub had
		// the same two params swapped, so nothing here was actually verified
		// before. A unique enqueue would silently collide with a still
		// in-progress batch from a previous rebuild and never get queued at
		// all (see Indexer::enqueue_next_batch()'s docblock in the Pro
		// sibling for the full mechanics).
		Indexer::start_rebuild();

		$batch = array_values( array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'wcs_rebuild_index_batch' === $c['hook'] && 'enqueue_async' === $c['fn'] ) );
		$this->assertCount( 1, $batch );
		$this->assertFalse( $batch[0]['unique'] );
	}
}
