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

	public function test_bulk_rebuild_writes_vocabulary_to_the_terms_staging_table(): void {
		$this->post( 1, 'Red Lamp' );
		$this->lookupHandler( array( 1 => $this->lookupRow( 1, 'RL-1' ) ) );

		$this->bulk( array( 1 ) ); // target: staging table

		$vocab = array_values( array_filter( $this->wpdb->queries, static fn( $q ) => str_contains( $q, 'wcs_search_terms_stage' ) ) );
		$this->assertCount( 1, $vocab );
		$this->assertStringContainsString( "'red'", $vocab[0] );
		$this->assertStringContainsString( "'lamp'", $vocab[0] );
		$this->assertStringContainsString( 'ON DUPLICATE KEY UPDATE freq = freq + VALUES(freq)', $vocab[0] );
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
}
