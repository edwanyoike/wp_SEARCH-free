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

	// ── Initial rebuild enqueue failure → WP-Cron fallback ────────────────────
	// Regression coverage: Action Scheduler's as_enqueue_async_action()
	// returns 0 (not an exception) when it can't enqueue — confirmed live,
	// this happens when a schema-migrating upgrade's rebuild trigger runs
	// during plugins_loaded before Action Scheduler's own data store has
	// initialized. The call silently no-oped, leaving wcs_is_indexing stuck
	// at 1 forever with nothing driving it and no admin-visible signal.

	public function test_enqueue_failure_falls_back_to_wp_cron_retry(): void {
		$GLOBALS['wcs_test_as_enqueue_fails'] = true;

		Indexer::start_rebuild();

		$epoch = (int) get_option( 'wcs_rebuild_epoch' );
		$this->assertNotSame( 0, $epoch );
		$this->assertSame( 1, get_option( 'wcs_is_indexing' ), 'still marked indexing — the fallback, not a hard failure, owns recovery' );
		$scheduled = array_filter( $GLOBALS['wcs_test_single_events'], static fn( $e ) => 'wcs_retry_rebuild_scheduling' === $e['hook'] );
		$this->assertNotEmpty( $scheduled, 'a WP-Cron retry must be scheduled when the initial enqueue fails' );
		$this->assertSame( array( $epoch, 0 ), array_values( $scheduled )[0]['args'] );
	}

	/**
	 * Regression: wp_schedule_single_event() itself returns false (not an
	 * exception) if WP-Cron can't store the event. Left unchecked, this
	 * would leave a rebuild stranded with literally nothing driving it — no
	 * Action Scheduler action pending (that's what triggered the fallback)
	 * and no cron event pending either — so retry_rebuild_scheduling() would
	 * simply never run and wcs_is_indexing would sit at 1 forever.
	 */
	public function test_wp_cron_itself_refusing_the_retry_event_fails_the_rebuild_immediately(): void {
		$GLOBALS['wcs_test_as_enqueue_fails']    = true;
		$GLOBALS['wcs_test_cron_schedule_fails'] = true;

		Indexer::start_rebuild();

		$this->assertSame( 0, get_option( 'wcs_is_indexing' ), 'nothing can drive this rebuild — it must not report "still indexing"' );
		$this->assertSame( 'schedule_enqueue_failed', get_option( 'wcs_last_rebuild_error' ) );
	}

	public function test_enqueue_success_does_not_schedule_a_retry(): void {
		Indexer::start_rebuild(); // default stub: as_enqueue_async_action succeeds

		$this->assertSame( array(), $GLOBALS['wcs_test_single_events'] );
	}

	public function test_retry_rebuild_scheduling_succeeds_on_a_later_attempt(): void {
		update_option( 'wcs_rebuild_epoch', 555 );
		update_option( 'wcs_is_indexing', 1 );

		Indexer::retry_rebuild_scheduling( 555 );

		$enqueued = array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'wcs_rebuild_index_batch' === ( $c['hook'] ?? '' ) );
		$this->assertNotEmpty( $enqueued, 'a retry must attempt the enqueue again' );
		$this->assertSame( array(), $GLOBALS['wcs_test_single_events'], 'a successful retry must not schedule another one' );
	}

	public function test_retry_rebuild_scheduling_reschedules_itself_on_repeated_failure(): void {
		update_option( 'wcs_rebuild_epoch', 555 );
		$GLOBALS['wcs_test_as_enqueue_fails'] = true;

		Indexer::retry_rebuild_scheduling( 555 );

		$scheduled = array_filter( $GLOBALS['wcs_test_single_events'], static fn( $e ) => 'wcs_retry_rebuild_scheduling' === $e['hook'] );
		$this->assertNotEmpty( $scheduled, 'a still-failing retry must reschedule itself' );
		$this->assertNull( get_option( 'wcs_last_rebuild_error', null ), 'not exhausted yet — no error should be recorded' );
	}

	public function test_retry_reschedule_itself_failing_stops_the_rebuild_instead_of_looping_silently(): void {
		update_option( 'wcs_rebuild_epoch', 555 );
		update_option( 'wcs_is_indexing', 1 );
		$GLOBALS['wcs_test_as_enqueue_fails']    = true;
		$GLOBALS['wcs_test_cron_schedule_fails'] = true;

		Indexer::retry_rebuild_scheduling( 555 );

		$this->assertSame( 'schedule_enqueue_failed', get_option( 'wcs_last_rebuild_error' ) );
		$this->assertSame( 0, get_option( 'wcs_is_indexing' ) );
	}

	public function test_retry_rebuild_scheduling_gives_up_after_five_attempts(): void {
		update_option( 'wcs_rebuild_epoch', 555 );
		update_option( 'wcs_is_indexing', 1 );
		set_transient( 'wcs_schedule_retry_555_0', 5 );
		$GLOBALS['wcs_test_as_enqueue_fails'] = true;

		Indexer::retry_rebuild_scheduling( 555 );

		$this->assertSame( array(), $GLOBALS['wcs_test_as_calls'], 'exhausted retries must not attempt the enqueue again' );
		$this->assertSame( array(), $GLOBALS['wcs_test_single_events'], 'exhausted retries must not reschedule again' );
		$this->assertSame( 'schedule_enqueue_failed', get_option( 'wcs_last_rebuild_error' ) );
		$this->assertSame( 0, get_option( 'wcs_is_indexing' ), 'must stop showing "Indexing..." once retries are exhausted' );
	}

	public function test_retry_rebuild_scheduling_ignores_a_superseded_epoch(): void {
		update_option( 'wcs_rebuild_epoch', 999 ); // a newer rebuild already started
		$GLOBALS['wcs_test_as_enqueue_fails'] = true;

		Indexer::retry_rebuild_scheduling( 555 ); // stale epoch from an earlier rebuild

		$this->assertSame( array(), $GLOBALS['wcs_test_as_calls'] );
		$this->assertSame( array(), $GLOBALS['wcs_test_single_events'] );
	}

	/**
	 * Regression (Finding I): $wpdb->get_col() returns an empty array both
	 * when the product-ID fetch fails and when it genuinely reached the end
	 * of the catalog — the SAME ambiguity Search_Handler::get_rows() has on
	 * the read side. Left unchecked, a transient failure fetching a later
	 * page would look exactly like "reached the end", triggering
	 * finalization and potentially swapping in a staging table missing
	 * every product from the failure point onward. Verifies the failure is
	 * now retried instead, and that retries eventually halt with the live
	 * index preserved rather than looping or swapping forever.
	 */
	public function test_product_id_fetch_failure_is_retried_not_treated_as_end_of_catalog(): void {
		update_option( 'wcs_rebuild_epoch', 100 );
		update_option( 'wcs_is_indexing', 1 );
		$this->wpdb->handler = static function ( string $sql, string $type ) {
			if ( 'col' === $type ) {
				return array(); // simulated failure: looks identical to "no more products"
			}
			return null;
		};
		$this->wpdb->last_error = 'simulated: connection lost';

		Indexer::process_batch( 500, 100 );

		$this->assertStringNotContainsString( 'RENAME TABLE', implode( "\n", $this->wpdb->queries ), 'a failed fetch must never be treated as end-of-catalog and trigger a swap' );
		$this->assertSame( 1, get_option( 'wcs_is_indexing' ), 'still marked indexing — the retry, not a hard failure, owns recovery' );
		$retried = array_values( array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'wcs_rebuild_index_batch' === ( $c['hook'] ?? '' ) ) );
		$this->assertCount( 1, $retried, 'the same cursor must be retried' );
		$this->assertSame( 500, $retried[0]['args']['last_id'] );
	}

	public function test_product_id_fetch_failure_halts_after_five_attempts_preserving_the_live_index(): void {
		update_option( 'wcs_rebuild_epoch', 100 );
		update_option( 'wcs_is_indexing', 1 );
		set_transient( 'wcs_fetch_retry_100_500', 5 );
		$this->wpdb->handler = static fn( string $sql, string $type ) => 'col' === $type ? array() : null;
		$this->wpdb->last_error = 'simulated: connection lost';

		Indexer::process_batch( 500, 100 );

		$this->assertStringNotContainsString( 'RENAME TABLE', implode( "\n", $this->wpdb->queries ) );
		$this->assertSame( array(), $GLOBALS['wcs_test_as_calls'], 'exhausted retries must not enqueue another attempt' );
		$this->assertSame( 0, get_option( 'wcs_is_indexing' ) );
		$this->assertSame( 'batch_fetch_failed', get_option( 'wcs_last_rebuild_error' ) );
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
			array( 'product_id', 'title', 'title_normalized', 'title_padded', 'sku', 'sku_normalized', 'content', 'excerpt', 'price_min', 'price_max', 'stock_status', 'total_sales', 'sales_30d', 'image_url', 'permalink', 'updated_at' ),
			array_keys( $row )
		);
	}

	public function test_title_normalized_and_padded_strip_punctuation_like_a_query_would(): void {
		// Search_Handler compares a normalized query directly against
		// title_normalized/title_padded (never the raw `title` column) for
		// the exact-title/title-prefix/phrase boosts, so these two columns
		// must be normalized exactly the way Query_Normalizer::normalize()
		// normalizes the query side, or a punctuated product name like this
		// one would never earn those boosts even for a shopper query that is
		// an exact match in every sense that matters.
		$row = $this->sanitizeRow( array_merge( $this->validRow(), array(
			'title' => "Men's T-Shirt/Jacket",
		) ) );

		$this->assertSame( "Men's T-Shirt/Jacket", $row['title'], 'raw title keeps its punctuation for FULLTEXT indexing and display' );
		$this->assertSame( 'men s t shirt jacket', $row['title_normalized'] );
		$this->assertSame( ' men s t shirt jacket ', $row['title_padded'] );
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

	/**
	 * Regression (Finding L, refined per follow-up audit): a failed
	 * as_schedule_single_action() call (returns 0, not an exception) used to
	 * leave nothing driving the eventual cache bust except an unrelated
	 * later write in the same request — which might never come, leaving
	 * results stale for the full 24h transient lifetime. It now busts
	 * immediately as a fallback instead, so correctness never depends on
	 * whether anything else happens to write to the index afterward.
	 */
	public function test_failed_cache_bust_schedule_busts_immediately_as_a_fallback(): void {
		update_option( 'wcs_cache_version', 1 );
		$GLOBALS['wcs_test_as_schedule_fails'] = true;

		Indexer::trigger_cache_bust();

		$this->assertSame( 2, get_option( 'wcs_cache_version' ), 'a failed schedule must bust immediately, not depend on a later unrelated write' );
	}

	public function test_failed_cache_bust_schedule_does_not_retry_scheduling_again_this_request(): void {
		update_option( 'wcs_cache_version', 1 );
		$GLOBALS['wcs_test_as_schedule_fails'] = true;

		Indexer::trigger_cache_bust(); // fails, busts immediately
		Indexer::trigger_cache_bust(); // the bust already happened — must not bump the version again

		$this->assertSame( 2, get_option( 'wcs_cache_version' ) );
	}

	public function test_same_product_is_queued_once_per_request(): void {
		Indexer::queue_product_update( 7 );
		Indexer::queue_product_update( 7 );
		Indexer::queue_product_update( 8 );

		$queued = array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'wcs_update_single_product' === $c['hook'] );
		$this->assertCount( 2, $queued );
	}

	/**
	 * Regression (Finding L): as_enqueue_async_action()'s return value was
	 * discarded here — a rejected enqueue meant a saved product's edit
	 * silently never reached the index, staying stale until its next save
	 * or a full rebuild, with no recovery path at all.
	 */
	public function test_product_enqueue_failure_falls_back_to_wp_cron_retry(): void {
		$GLOBALS['wcs_test_as_enqueue_fails'] = true;

		Indexer::queue_product_update( 7 );

		$scheduled = array_values( array_filter( $GLOBALS['wcs_test_single_events'], static fn( $e ) => 'wcs_retry_product_enqueue' === $e['hook'] ) );
		$this->assertNotEmpty( $scheduled, 'a WP-Cron retry must be scheduled when the enqueue fails' );
		$this->assertSame( array( 7 ), $scheduled[0]['args'] );
	}

	public function test_product_enqueue_retry_succeeds_on_a_later_attempt(): void {
		Indexer::retry_product_enqueue( 7 ); // default stub: as_enqueue_async_action succeeds

		$queued = array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'wcs_update_single_product' === ( $c['hook'] ?? '' ) );
		$this->assertCount( 1, $queued );
		$this->assertSame( array(), $GLOBALS['wcs_test_single_events'], 'a successful retry must not schedule another one' );
	}

	public function test_product_enqueue_retry_gives_up_after_five_attempts(): void {
		set_transient( 'wcs_product_retry_7', 5 );
		$GLOBALS['wcs_test_as_enqueue_fails'] = true;

		Indexer::retry_product_enqueue( 7 );

		$this->assertSame( array(), $GLOBALS['wcs_test_single_events'], 'exhausted retries must not reschedule again' );
	}

	/**
	 * Regression (follow-up audit of Finding L, point 1): both
	 * wp_schedule_single_event() calls in this retry chain (the initial
	 * fallback in queue_product_update() and the reschedule in
	 * retry_product_enqueue()) discarded their own return value. If WP-Cron
	 * itself refused the event, no retry existed at all and the product
	 * silently never resynced. There is no per-product admin error state to
	 * set (unlike a whole rebuild), so the fix is a distinct warning log —
	 * verify it fires instead of failing silently.
	 */
	public function test_wp_cron_itself_refusing_the_product_retry_is_logged(): void {
		$GLOBALS['wcs_test_as_enqueue_fails']    = true;
		$GLOBALS['wcs_test_cron_schedule_fails'] = true;

		Indexer::queue_product_update( 7 );

		$messages = array_column( $GLOBALS['wcs_test_logs'], 'message' );
		$this->assertNotEmpty( preg_grep( '/WP-Cron also refused .* product 7/i', $messages ) );
	}

	public function test_wp_cron_itself_refusing_the_product_reschedule_is_logged(): void {
		$GLOBALS['wcs_test_as_enqueue_fails']    = true;
		$GLOBALS['wcs_test_cron_schedule_fails'] = true;

		Indexer::retry_product_enqueue( 7 );

		$messages = array_column( $GLOBALS['wcs_test_logs'], 'message' );
		$this->assertNotEmpty( preg_grep( '/WP-Cron refused to reschedule .* product 7/i', $messages ) );
	}

	/**
	 * Regression (2026-09-05 audit of the response to the Finding L
	 * follow-up): the earlier fix only logged a warning when BOTH Action
	 * Scheduler and its own WP-Cron fallback rejected an enqueue in the same
	 * call — a case the plan document had assumed always needed five
	 * consecutive failures over ~2.5 minutes, but which can actually happen
	 * on the very first attempt, before retry_product_enqueue()'s 5-attempt
	 * chain ever gets a chance to run. A pure log line meant the only update
	 * for that product was silently lost. It must now also be recorded in
	 * wcs_pending_product_updates so drain_pending_product_updates() can
	 * resubmit it later without requiring another product save.
	 */
	public function test_first_attempt_double_rejection_records_a_pending_update(): void {
		$GLOBALS['wcs_test_as_enqueue_fails']    = true;
		$GLOBALS['wcs_test_cron_schedule_fails'] = true;

		Indexer::queue_product_update( 7 );

		$pending = get_option( 'wcs_pending_product_updates' );
		$this->assertIsArray( $pending );
		$this->assertArrayHasKey( 7, $pending );
	}

	/**
	 * Same terminal condition, reached instead through a later attempt of
	 * the bounded retry chain: Action Scheduler rejects the retry AND the
	 * next WP-Cron reschedule also fails.
	 */
	public function test_mid_chain_double_rejection_records_a_pending_update(): void {
		$GLOBALS['wcs_test_as_enqueue_fails']    = true;
		$GLOBALS['wcs_test_cron_schedule_fails'] = true;

		Indexer::retry_product_enqueue( 7 );

		$pending = get_option( 'wcs_pending_product_updates' );
		$this->assertIsArray( $pending );
		$this->assertArrayHasKey( 7, $pending );
	}

	public function test_ordinary_wp_cron_fallback_does_not_record_a_pending_update(): void {
		$GLOBALS['wcs_test_as_enqueue_fails'] = true; // WP-Cron scheduling itself still succeeds

		Indexer::queue_product_update( 7 );

		$this->assertFalse( get_option( 'wcs_pending_product_updates' ), 'a normal, successfully-scheduled WP-Cron retry needs no pending-set fallback' );
	}

	public function test_drain_pending_product_updates_resubmits_and_clears_on_success(): void {
		update_option( 'wcs_pending_product_updates', array( 7 => true, 8 => true ) );

		Indexer::drain_pending_product_updates(); // default stub: as_enqueue_async_action succeeds

		$queued = array_column( array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'wcs_update_single_product' === ( $c['hook'] ?? '' ) ), 'args' );
		$this->assertContains( array( 'product_id' => 7 ), $queued );
		$this->assertContains( array( 'product_id' => 8 ), $queued );
		$this->assertFalse( get_option( 'wcs_pending_product_updates' ), 'every entry enqueued successfully — the option must be cleared, not left as an empty array' );
	}

	public function test_drain_pending_product_updates_leaves_failed_ids_pending(): void {
		update_option( 'wcs_pending_product_updates', array( 7 => true ) );
		$GLOBALS['wcs_test_as_enqueue_fails'] = true;

		Indexer::drain_pending_product_updates();

		$pending = get_option( 'wcs_pending_product_updates' );
		$this->assertIsArray( $pending );
		$this->assertArrayHasKey( 7, $pending, 'a drain attempt that fails again must leave the ID for the next drain' );
	}

	public function test_drain_pending_product_updates_skips_an_id_already_scheduled_elsewhere(): void {
		update_option( 'wcs_pending_product_updates', array( 7 => true ) );
		$GLOBALS['wcs_test_as_has_scheduled'] = true;

		Indexer::drain_pending_product_updates();

		$queued = array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'wcs_update_single_product' === ( $c['hook'] ?? '' ) );
		$this->assertSame( array(), $queued, 'must not enqueue a duplicate action when one is already pending for this product' );
		$this->assertFalse( get_option( 'wcs_pending_product_updates' ), 'already covered by an existing pending action — the record is no longer needed' );
	}

	public function test_drain_pending_product_updates_does_nothing_when_the_set_is_empty(): void {
		Indexer::drain_pending_product_updates();

		$this->assertSame( array(), $GLOBALS['wcs_test_as_calls'] );
	}

	/**
	 * Regression (follow-up audit of Finding L, point 5): retry_product_
	 * enqueue() returned early without clearing wcs_product_retry_{id} when
	 * it found an action already scheduled (e.g. the product was saved
	 * again independently). The stale attempt count then survived its 1h
	 * TTL and could be inherited by a LATER, unrelated failure for the same
	 * product, exhausting that failure's retry budget early.
	 */
	public function test_product_enqueue_retry_clears_stale_counter_when_already_queued_elsewhere(): void {
		set_transient( 'wcs_product_retry_7', 3 );
		$GLOBALS['wcs_test_as_has_scheduled'] = true;

		Indexer::retry_product_enqueue( 7 );

		$this->assertFalse( get_transient( 'wcs_product_retry_7' ), 'the stale counter must not survive to poison a later, unrelated failure' );
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

	// ── Result-affecting setting change → immediate cache bust ───────────────
	// Regression coverage for Finding A (2026-09-05 algorithm audit):
	// wcs_result_count/wcs_show_out_of_stock are not part of the search
	// cache key, so a change must bump wcs_cache_version immediately or a
	// stale payload could keep being served for up to the 24h transient TTL.

	public function test_result_count_change_bumps_cache_version_immediately(): void {
		update_option( 'wcs_cache_version', 5 );

		Indexer::on_result_affecting_setting_changed( 6, 10 );

		$this->assertSame( 6, get_option( 'wcs_cache_version' ) );
	}

	public function test_show_out_of_stock_change_bumps_cache_version_immediately(): void {
		update_option( 'wcs_cache_version', 5 );

		Indexer::on_result_affecting_setting_changed( 1, 0 );

		$this->assertSame( 6, get_option( 'wcs_cache_version' ) );
	}

	public function test_unchanged_result_affecting_setting_does_not_bust_the_cache(): void {
		update_option( 'wcs_cache_version', 5 );

		Indexer::on_result_affecting_setting_changed( 6, 6 );

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
