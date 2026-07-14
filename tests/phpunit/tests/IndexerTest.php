<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WCS\Search\Indexer;

final class IndexerTest extends TestCase {

	private Fake_WPDB $wpdb;

	protected function setUp(): void {
		wcs_tests_reset();
		$this->wpdb      = new Fake_WPDB();
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	// ── Epoch state machine ──────────────────────────────────────────────────

	public function test_stale_epoch_batch_is_dropped_without_touching_the_database(): void {
		update_option( 'wcs_rebuild_epoch', 100 );
		update_option( 'wcs_is_indexing', 1 );

		Indexer::process_batch( 0, 99 ); // superseded epoch

		$this->assertSame( array(), $this->wpdb->queries );
		$this->assertSame( array(), $GLOBALS['wcs_test_as_calls'] );
		// The indexing flag belongs to the current rebuild — a stale batch must not clear it.
		$this->assertSame( 1, get_option( 'wcs_is_indexing' ) );
		$messages = array_column( $GLOBALS['wcs_test_logs'], 'message' );
		$this->assertNotEmpty( preg_grep( '/stale/i', $messages ) );
	}

	public function test_missing_staging_table_halts_the_chain_and_clears_the_flag(): void {
		update_option( 'wcs_rebuild_epoch', 100 );
		update_option( 'wcs_is_indexing', 1 );
		// Script the fake wpdb: no staging table exists (SHOW TABLES returns null).
		$this->wpdb->handler = static fn( string $sql, string $type ) => match ( $type ) {
			'col'   => array( 5, 6 ), // batch of product IDs
			'var'   => null,          // SHOW TABLES LIKE ... → missing
			default => null,
		};

		Indexer::process_batch( 0, 100 );

		$this->assertSame( 0, get_option( 'wcs_is_indexing' ) );
		$this->assertSame( array(), $GLOBALS['wcs_test_as_calls'], 'no further batches may be enqueued' );
	}

	// ── Row sanitization (the wcs_indexed_product_data hardening) ────────────

	private function sanitizeRow( array $data, int $product_id = 42 ): array {
		$method = new ReflectionMethod( Indexer::class, 'apply_row_filter_and_sanitize' );
		return $method->invoke( null, $data, $product_id );
	}

	private function validRow(): array {
		return array(
			'product_id'   => 42,
			'title'        => 'Lamp',
			'sku'          => 'L-1',
			'content'      => 'desc',
			'excerpt'      => 'A warm little lamp.',
			'price_min'    => 5.0,
			'price_max'    => 9.0,
			'stock_status' => 'instock',
			'total_sales'  => 3,
			'image_url'    => 'https://example.test/i.jpg',
			'permalink'    => 'https://example.test/?p=42',
			'updated_at'   => '2026-01-01 00:00:00',
		);
	}

	public function test_unknown_keys_added_by_filter_callbacks_are_stripped(): void {
		add_filter( 'wcs_indexed_product_data', static function ( array $data ): array {
			$data['evil_column'] = 'DROP TABLE';
			return $data;
		} );

		$row = $this->sanitizeRow( $this->validRow() );

		$this->assertArrayNotHasKey( 'evil_column', $row );
	}

	public function test_row_keys_stay_in_canonical_column_order_even_if_filter_unsets_keys(): void {
		add_filter( 'wcs_indexed_product_data', static function ( array $data ): array {
			unset( $data['title'], $data['sku'] ); // must not shift $formats alignment
			return $data;
		} );

		$row = $this->sanitizeRow( $this->validRow() );

		$this->assertSame(
			array( 'product_id', 'title', 'sku', 'sku_normalized', 'content', 'excerpt', 'price_min', 'price_max', 'stock_status', 'total_sales', 'sales_30d', 'image_url', 'permalink', 'updated_at' ),
			array_keys( $row )
		);
	}

	public function test_excerpt_is_html_stripped_and_truncated_at_a_word_boundary(): void {
		$row = $this->sanitizeRow( array_merge( $this->validRow(), array(
			'excerpt' => '<b>' . str_repeat( 'lovely ', 30 ) . 'lamp</b>',
		) ) );

		$this->assertStringNotContainsString( '<b>', $row['excerpt'] );
		$this->assertLessThanOrEqual( 151, mb_strlen( $row['excerpt'] ) ); // 150 + ellipsis
		$this->assertStringEndsWith( '…', $row['excerpt'] );
		$this->assertStringEndsNotWith( ' …', $row['excerpt'] ); // truncated at a word boundary, not mid-word
	}

	public function test_short_excerpt_is_returned_unchanged(): void {
		$row = $this->sanitizeRow( $this->validRow() );
		$this->assertSame( 'A warm little lamp.', $row['excerpt'] );
	}

	public function test_malicious_urls_and_markup_from_filters_are_neutralized(): void {
		add_filter( 'wcs_indexed_product_data', static function ( array $data ): array {
			$data['image_url']    = 'javascript:alert(1)';
			$data['title']        = '<script>x</script>Lamp';
			$data['stock_status'] = 'instock; DROP';
			$data['total_sales']  = -5;
			return $data;
		} );

		$row = $this->sanitizeRow( $this->validRow() );

		$this->assertSame( '', $row['image_url'] );
		$this->assertSame( 'xLamp', $row['title'] );
		$this->assertSame( 'instockdrop', $row['stock_status'] );
		$this->assertSame( 0, $row['total_sales'] );
	}

	// ── Per-request dedup flags ──────────────────────────────────────────────

	public function test_cache_bust_is_scheduled_once_per_request(): void {
		Indexer::trigger_cache_bust();
		Indexer::trigger_cache_bust();
		Indexer::trigger_cache_bust();

		$scheduled = array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'wcs_debounce_cache_bust' === $c['hook'] );
		$this->assertCount( 1, $scheduled );
	}

	public function test_same_product_is_queued_once_per_request(): void {
		Indexer::queue_product_update( 7 );
		Indexer::queue_product_update( 7 );
		Indexer::queue_product_update( 8 );

		$queued = array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'wcs_update_single_product' === $c['hook'] );
		$this->assertCount( 2, $queued );
	}

	// ── Synonym change → immediate cache bust ────────────────────────────────

	public function test_synonym_change_bumps_cache_version_immediately(): void {
		update_option( 'wcs_cache_version', 5 );

		Indexer::on_synonyms_changed( 'old', 'new' );

		$this->assertSame( 6, get_option( 'wcs_cache_version' ) );
	}

	public function test_unchanged_synonyms_do_not_bust_the_cache(): void {
		update_option( 'wcs_cache_version', 5 );

		Indexer::on_synonyms_changed( 'same', 'same' );

		$this->assertSame( 5, get_option( 'wcs_cache_version' ) );
	}

	// ── "Last successful index" timestamp (timezone-offset regression) ───────

	public function test_last_indexed_is_a_true_utc_timestamp_on_a_non_utc_site(): void {
		// Regression: found live on a UTC+3 site — a rebuild that had just
		// finished showed "Last successful index: 3 hours ago". Cause:
		// current_time('timestamp') adds the site's UTC offset, but the
		// settings page compares this value with human_time_diff(), whose
		// default comparison point is real time() — mixing the two silently
		// added the site's own UTC offset to the reported age, every time.
		update_option( 'gmt_offset', 3 ); // Africa/Nairobi

		$before = time();
		Indexer::execute_cache_bust();
		$after = time();

		$last_indexed = (int) get_option( 'wcs_last_indexed' );
		$this->assertGreaterThanOrEqual( $before, $last_indexed );
		$this->assertLessThanOrEqual( $after, $last_indexed, 'must be a true UTC timestamp, not shifted by the site UTC offset' );
	}

	// ── Adaptive batch sizing (memory-constrained hosts) ─────────────────────

	private function batchSize(): int {
		$method = new ReflectionMethod( Indexer::class, 'dynamic_batch_size' );
		return $method->invoke( null );
	}

	public function test_idle_load_with_ample_memory_uses_the_top_tier(): void {
		$GLOBALS['wcs_test_loadavg']      = array( 0.1, 0.1, 0.1 );
		$GLOBALS['wcs_test_memory_usage'] = 10 * 1024 * 1024; // 10MB used
		$GLOBALS['wcs_test_memory_limit'] = '512M';

		$this->assertSame( 200, $this->batchSize() );
	}

	public function test_absolute_128mb_limit_caps_at_25_regardless_of_load(): void {
		// The exact scenario found on narukistore.com: idle load (would
		// otherwise pick 200) but only a 128MB worker to run in.
		$GLOBALS['wcs_test_loadavg']      = array( 0.1, 0.1, 0.1 );
		$GLOBALS['wcs_test_memory_usage'] = 5 * 1024 * 1024; // low current usage
		$GLOBALS['wcs_test_memory_limit'] = '128M';

		$this->assertSame( 25, $this->batchSize() );
	}

	public function test_absolute_192mb_limit_caps_at_50(): void {
		$GLOBALS['wcs_test_loadavg']      = array( 0.1, 0.1, 0.1 );
		$GLOBALS['wcs_test_memory_usage'] = 5 * 1024 * 1024;
		$GLOBALS['wcs_test_memory_limit'] = '192M';

		$this->assertSame( 50, $this->batchSize() );
	}

	public function test_absolute_256mb_limit_caps_at_100(): void {
		$GLOBALS['wcs_test_loadavg']      = array( 0.1, 0.1, 0.1 );
		$GLOBALS['wcs_test_memory_usage'] = 5 * 1024 * 1024;
		$GLOBALS['wcs_test_memory_limit'] = '256M';

		$this->assertSame( 100, $this->batchSize() );
	}

	public function test_relative_usage_caps_apply_even_on_a_large_worker(): void {
		$GLOBALS['wcs_test_loadavg']      = array( 0.1, 0.1, 0.1 ); // would pick 200
		$GLOBALS['wcs_test_memory_limit'] = '1024M';                // no absolute cap
		$GLOBALS['wcs_test_memory_usage'] = (int) ( 1024 * 1024 * 1024 * 0.75 ); // 75% used

		$this->assertSame( 25, $this->batchSize() );
	}

	public function test_wcs_batch_size_filter_overrides_everything(): void {
		$GLOBALS['wcs_test_loadavg']      = array( 0.1, 0.1, 0.1 );
		$GLOBALS['wcs_test_memory_usage'] = 5 * 1024 * 1024;
		$GLOBALS['wcs_test_memory_limit'] = '512M';
		add_filter( 'wcs_batch_size', static fn() => 7 );

		$this->assertSame( 7, $this->batchSize() );
	}
}
