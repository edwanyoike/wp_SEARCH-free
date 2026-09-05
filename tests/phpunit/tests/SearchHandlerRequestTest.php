<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WCS\Search\Query_Normalizer;
use WCS\Search\Search_Handler;

/**
 * Full handle_request() flow: permission checks, cache hierarchy, currency
 * conversion (rate and product-object paths), the first-run indexing signal,
 * and the stampede mutex/poller.
 */
final class SearchHandlerRequestTest extends TestCase {

	private Fake_WPDB $wpdb;

	protected function setUp(): void {
		wcs_tests_reset();
		$GLOBALS['wcs_test_usleeps'] = array();
		$this->wpdb                  = new Fake_WPDB();
		$GLOBALS['wpdb']             = $this->wpdb;

		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcs_cache_version', 1 );
		update_option( 'wcs_min_chars', 2 );
		update_option( 'wcs_result_count', 6 );
		update_option( 'wcs_show_out_of_stock', 1 );
		update_option( 'wcs_ft_parser', 'default' );
		update_option( 'wcs_last_indexed', 1234567890 ); // index built
	}

	private function request( array $params ): WP_REST_Response {
		return Search_Handler::handle_request( new WP_REST_Request( $params ) );
	}

	private function scriptRows( array $rows ): void {
		// Serve product rows only for index-table queries — the vocabulary,
		// suggestion, and zero-log queries get the default empty result.
		//
		// Honors the NOT IN exclusion the way the real database would: a tier
		// that tops up a partial result set excludes what earlier tiers already
		// returned, so a fake that replayed the same rows for every tier would
		// hand back duplicates no real query could produce.
		$this->wpdb->handler = static function ( string $sql, string $type ) use ( $rows ) {
			if ( 'results' !== $type || ! str_contains( $sql, 'wcs_search_index' ) ) {
				return null;
			}
			if ( preg_match( '/NOT IN \(([\d,]+)\)/', $sql, $m ) ) {
				$excluded = array_map( 'intval', explode( ',', $m[1] ) );
				return array_values( array_filter(
					$rows,
					static fn( array $row ): bool => ! in_array( (int) $row['product_id'], $excluded, true )
				) );
			}
			return $rows;
		};
	}

	private function row( int $id, string $price = '100.00' ): array {
		return array(
			'product_id'   => $id,
			'title'        => "Product $id",
			'price_min'    => $price,
			'price_max'    => $price,
			'image_url'    => '',
			'permalink'    => "https://example.test/?p=$id",
			'stock_status' => 'instock',
		);
	}

	// ── Permissions ──────────────────────────────────────────────────────────

	public function test_invalid_nonce_is_rejected_with_403(): void {
		$GLOBALS['wcs_test_nonce_valid'] = false;
		$result = Search_Handler::check_permissions( new WP_REST_Request( array( '_wpnonce' => 'x' ) ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_forbidden', $result->get_error_code() );
	}

	public function test_rate_limit_returns_429_after_sixty_requests(): void {
		$req = new WP_REST_Request( array( '_wpnonce' => 'x' ) );
		for ( $i = 0; $i < 60; $i++ ) {
			$this->assertTrue( Search_Handler::check_permissions( $req ) );
		}
		$result = Search_Handler::check_permissions( $req );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'rest_too_many_requests', $result->get_error_code() );
	}

	// ── Input gates ──────────────────────────────────────────────────────────

	public function test_empty_query_returns_empty(): void {
		$this->assertSame( array(), $this->request( array( 'q' => '' ) )->data );
		$this->assertSame( array(), $this->request( array( 'q' => '+-*' ) )->data );
	}

	public function test_min_chars_is_enforced_server_side(): void {
		update_option( 'wcs_min_chars', 3 );
		$this->assertSame( array(), $this->request( array( 'q' => 'ab' ) )->data );
		$this->assertSame( array(), $this->wpdb->queries, 'short queries must not reach the database' );
	}

	// ── Cache hierarchy ──────────────────────────────────────────────────────

	public function test_cache_miss_queries_db_then_stores_transient(): void {
		$this->scriptRows( array( $this->row( 1 ) ) );

		$response = $this->request( array( 'q' => 'lamp' ) );

		$this->assertCount( 1, $response->data );
		$key = Query_Normalizer::cache_key( 'lamp', 'USD', 1 );
		$this->assertArrayHasKey( $key, $GLOBALS['wcs_test_transients']['data'] );
		$this->assertSame( DAY_IN_SECONDS, $GLOBALS['wcs_test_transients']['expiry'][ $key ] );
	}

	public function test_transient_hit_skips_the_database(): void {
		$key = Query_Normalizer::cache_key( 'lamp', 'USD', 1 );
		set_transient( $key, array( $this->row( 9 ) ), DAY_IN_SECONDS );

		$response = $this->request( array( 'q' => 'lamp' ) );

		$this->assertSame( 9, $response->data[0]['product_id'] );
		$this->assertSame( array(), $this->wpdb->queries );
	}

	public function test_cache_version_bump_invalidates_old_entries(): void {
		set_transient( Query_Normalizer::cache_key( 'lamp', 'USD', 1 ), array( $this->row( 9 ) ), DAY_IN_SECONDS );
		update_option( 'wcs_cache_version', 2 );
		$this->scriptRows( array( $this->row( 1 ) ) );

		$response = $this->request( array( 'q' => 'lamp' ) );

		$this->assertSame( 1, $response->data[0]['product_id'] );
		$this->assertNotEmpty( $this->wpdb->queries );
	}

	// ── Stampede mutex / poller ──────────────────────────────────────────────

	public function test_poller_waits_then_serves_builder_result_from_cache(): void {
		$GLOBALS['wcs_test_ext_cache'] = true;  // mutex only active with a shared cache
		$GLOBALS['wcs_test_cache_add'] = false; // someone else holds the lock
		$key = Query_Normalizer::cache_key( 'lamp', 'USD', 1 );

		// Simulate the concurrent builder finishing while this worker polls:
		// reads of the key miss (initial read + first poll), then the value
		// appears on the second poll.
		$reads = 0;
		$GLOBALS['wcs_test_transient_read_hook'] = static function ( string $read_key ) use ( $key, &$reads ): void {
			if ( $read_key === $key && 3 === ++$reads ) {
				$GLOBALS['wcs_test_transients']['data'][ $key ] = array( array( 'product_id' => 77 ) );
			}
		};

		$response = $this->request( array( 'q' => 'lamp' ) );

		$this->assertSame( 77, $response->data[0]['product_id'] );
		$this->assertNotEmpty( $GLOBALS['wcs_test_usleeps'], 'poller must have slept at least once' );
		$this->assertSame( array(), $this->wpdb->queries, 'poller path must not run its own query' );
	}

	public function test_poller_gives_up_and_queries_directly(): void {
		$GLOBALS['wcs_test_ext_cache'] = true;
		$GLOBALS['wcs_test_cache_add'] = false;
		$this->scriptRows( array( $this->row( 3 ) ) );

		$response = $this->request( array( 'q' => 'lamp' ) );

		$this->assertSame( 3, $response->data[0]['product_id'] );
		$this->assertCount( 3, $GLOBALS['wcs_test_usleeps'], 'poller is capped at 3 sleeps' );
		$this->assertNotEmpty( $this->wpdb->queries );
	}

	// ── Currency conversion (Pro feature — always inert in this edition) ─────

	public function test_currency_param_is_ignored_prices_stay_store_default(): void {
		update_option( 'woocs_currencies', array( 'EUR' => array( 'rate' => 0.5 ) ) );
		$this->scriptRows( array( $this->row( 1, '100.00' ) ) );

		$response = $this->request( array( 'q' => 'lamp', 'currency' => 'EUR' ) );

		$this->assertSame( '100.00', $response->data[0]['price_min'] );
		// Cached under the store-default (USD) key, not an EUR-specific one.
		$key = Query_Normalizer::cache_key( 'lamp', 'USD', 1 );
		$this->assertSame( '100.00', $GLOBALS['wcs_test_transients']['data'][ $key ]['results'][0]['price_min'] );
	}

	public function test_unknown_currency_falls_back_to_store_default(): void {
		$this->scriptRows( array( $this->row( 1, '100.00' ) ) );

		$response = $this->request( array( 'q' => 'lamp', 'currency' => 'ZZZ' ) );

		$this->assertSame( '100.00', $response->data[0]['price_min'] );
		$this->assertArrayHasKey( Query_Normalizer::cache_key( 'lamp', 'USD', 1 ), $GLOBALS['wcs_test_transients']['data'] );
	}

	// ── First-run window ─────────────────────────────────────────────────────

	public function test_first_run_empty_results_signal_indexing_and_are_not_cached(): void {
		update_option( 'wcs_last_indexed', 0 ); // never built

		$response = $this->request( array( 'q' => 'lamp' ) );

		$this->assertSame( array(), $response->data );
		$this->assertSame( '1', $response->headers['X-WCS-Indexing'] ?? null );
		$this->assertSame( array(), $GLOBALS['wcs_test_transients']['data'] ?? array() );
	}

	public function test_after_first_build_empty_results_are_cached_normally(): void {
		$response = $this->request( array( 'q' => 'lamp' ) );

		$this->assertSame( array(), $response->data );
		$this->assertArrayNotHasKey( 'X-WCS-Indexing', $response->headers );
		$this->assertArrayHasKey( Query_Normalizer::cache_key( 'lamp', 'USD', 1 ), $GLOBALS['wcs_test_transients']['data'] );
	}

	/**
	 * Regression (Finding F): $wpdb itself does not distinguish "the query
	 * failed" from "the query succeeded and matched nothing" — get_results()
	 * returns an empty array either way, and get_rows() previously reported
	 * that straight to query_database() with no way to tell the two apart.
	 * A transient database error (lock-wait timeout, mid-migration schema
	 * mismatch, a malformed query from a wcs_indexed_taxonomies-style
	 * filter) would therefore get cached as a confident "no products found"
	 * for up to 24h, with no admin-visible signal since the error was
	 * suppressed. A failed query must now be excluded from both cache
	 * layers and flagged on the response instead.
	 */
	public function test_database_error_is_not_cached_as_a_genuine_empty_result(): void {
		$this->wpdb->handler = function ( string $sql, string $type ) {
			$this->wpdb->last_error = 'simulated: lock wait timeout exceeded';
			return 'results' === $type ? array() : null;
		};

		$response = $this->request( array( 'q' => 'lamp' ) );

		$this->assertSame( array(), $response->data );
		$this->assertSame( '1', $response->headers['X-WCS-Query-Error'] ?? null );
		$this->assertSame( array(), $GLOBALS['wcs_test_transients']['data'] ?? array(), 'a failed query must never be cached as a real empty result' );
	}

	/**
	 * Regression: the first version of this fix only skipped caching when
	 * the FINAL result set was also empty. A query error in one tier (e.g. a
	 * corrupted FULLTEXT index failing every MATCH() query) does not stop a
	 * later, unrelated tier (plain title/sku LIKE recall) from still
	 * returning real rows — and that recovered set is exactly as
	 * incomplete/mis-ranked as the failure made it. It must still be shown
	 * to the shopper (better than nothing) but must not be cached for 24h,
	 * since caching it would let a degraded response outlive the outage.
	 */
	public function test_partial_results_after_a_tier_failure_are_returned_but_never_cached(): void {
		$this->wpdb->handler = function ( string $sql, string $type ) {
			if ( 'results' !== $type || ! str_contains( $sql, 'wcs_search_index' ) ) {
				return null;
			}
			if ( str_contains( $sql, 'MATCH(' ) ) {
				$this->wpdb->last_error = 'simulated: corrupted FULLTEXT index';
				return array();
			}
			// Same NOT-IN-aware behavior as scriptRows() above, so the
			// second LIKE-based tier that tops up an empty set doesn't
			// return the same already-found row again as a fake duplicate.
			if ( preg_match( '/NOT IN \(([\d,]+)\)/', $sql, $m ) ) {
				$excluded = array_map( 'intval', explode( ',', $m[1] ) );
				return in_array( 1, $excluded, true ) ? array() : array( $this->row( 1 ) );
			}
			return array( $this->row( 1 ) );
		};

		$response = $this->request( array( 'q' => 'lamp' ) );

		$this->assertCount( 1, $response->data, 'the shopper still sees whatever a later, unaffected tier actually found' );
		$this->assertSame( '1', $response->headers['X-WCS-Query-Error'] ?? null );
		$this->assertSame( array(), $GLOBALS['wcs_test_transients']['data'] ?? array(), 'a degraded result must not outlive the outage in the cache' );
	}

	public function test_genuine_zero_results_are_still_cached_normally_without_a_query_error_header(): void {
		$response = $this->request( array( 'q' => 'lamp' ) );

		$this->assertSame( array(), $response->data );
		$this->assertArrayNotHasKey( 'X-WCS-Query-Error', $response->headers );
		$this->assertArrayHasKey( Query_Normalizer::cache_key( 'lamp', 'USD', 1 ), $GLOBALS['wcs_test_transients']['data'] );
	}

	// ── Zero-result logging (Pro feature — always inert in this edition) ────

	public function test_zero_result_searches_are_never_logged(): void {
		$this->request( array( 'q' => 'unfindable thing' ) );

		$log = array_values( array_filter( $this->wpdb->queries, static fn( $q ) => str_contains( $q, 'wcs_search_log' ) ) );
		$this->assertSame( array(), $log );
	}

	public function test_successful_searches_are_not_logged(): void {
		$this->scriptRows( array( $this->row( 1 ) ) );

		$this->request( array( 'q' => 'lamp' ) );

		$this->assertSame( array(), array_filter( $this->wpdb->queries, static fn( $q ) => str_contains( $q, 'wcs_search_log' ) ) );
	}

	public function test_first_run_empty_results_are_not_logged(): void {
		update_option( 'wcs_last_indexed', 0 );

		$this->request( array( 'q' => 'lamp' ) );

		$this->assertSame( array(), array_filter( $this->wpdb->queries, static fn( $q ) => str_contains( $q, 'wcs_search_log' ) ) );
	}

	// ── Taxonomy suggestions (Pro feature — always inert in this edition) ───

	public function test_taxonomy_suggestions_are_never_queried(): void {
		$this->wpdb->handler = fn( string $sql, string $type ) => match ( true ) {
			'results' === $type && str_contains( $sql, 'term_taxonomy' )
				=> array( (object) array( 'term_id' => 9, 'name' => 'Lamps', 'taxonomy' => 'product_cat', 'count' => 12 ) ),
			'results' === $type && str_contains( $sql, 'wcs_search_index' )
				=> array( $this->row( 1 ) ),
			default => null,
		};

		$response = $this->request( array( 'q' => 'lamp' ) );

		$this->assertSame( 1, $response->data[0]['product_id'] );
		$this->assertArrayNotHasKey( 'type', $response->data[0] );
	}

	public function test_suggestions_can_be_disabled_by_filter(): void {
		add_filter( 'wcs_taxonomy_suggestions_count', static fn() => 0 );
		$this->scriptRows( array( $this->row( 1 ) ) );

		$this->request( array( 'q' => 'lamp' ) );

		$this->assertSame( array(), array_filter( $this->wpdb->queries, static fn( $q ) => str_contains( $q, 'term_taxonomy' ) ) );
	}

	public function test_zero_result_logging_can_be_disabled_by_filter(): void {
		add_filter( 'wcs_log_zero_results', '__return_false' );

		$this->request( array( 'q' => 'unfindable' ) );

		$this->assertSame( array(), array_filter( $this->wpdb->queries, static fn( $q ) => str_contains( $q, 'wcs_search_log' ) ) );
	}

	// ── Corrected-query header (typo correction is a Pro feature) ───────────

	public function test_no_corrected_query_header_when_nothing_was_corrected(): void {
		$this->scriptRows( array( $this->row( 1 ) ) );

		$response = $this->request( array( 'q' => 'lamp' ) );

		$this->assertArrayNotHasKey( 'X-WCS-Corrected-Query', $response->headers );
	}

	public function test_zero_result_query_never_gets_a_corrected_header(): void {
		$response = $this->request( array( 'q' => 'lampp' ) );

		$this->assertSame( array(), $response->data );
		$this->assertArrayNotHasKey( 'X-WCS-Corrected-Query', $response->headers );
	}

	public function test_pre_upgrade_plain_array_cache_entries_still_read_correctly(): void {
		// Simulates a transient written by a plugin version before the
		// {results, corrected} cache wrapper existed (still valid for up to
		// 24h of TTL after an upgrade) — a bare array of result rows.
		$key = Query_Normalizer::cache_key( 'lamp', 'USD', 1 );
		set_transient( $key, array( $this->row( 5 ) ), DAY_IN_SECONDS );

		$response = $this->request( array( 'q' => 'lamp' ) );

		$this->assertSame( 5, $response->data[0]['product_id'] );
		$this->assertArrayNotHasKey( 'X-WCS-Corrected-Query', $response->headers );
	}
}
