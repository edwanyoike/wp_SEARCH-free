<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WCS\Search\Activator;
use WCS\Search\Admin_Settings;

final class AdminSettingsTest extends TestCase {

	private Fake_WPDB $wpdb;

	protected function setUp(): void {
		wcs_tests_reset();
		$this->wpdb      = new Fake_WPDB();
		$GLOBALS['wpdb'] = $this->wpdb;
	}

	/** Invoke an AJAX handler and return its captured JSON response. */
	private function ajax( callable $handler ): WCS_Test_JSON_Response {
		try {
			$handler();
		} catch ( WCS_Test_JSON_Response $r ) {
			return $r;
		}
		$this->fail( 'handler did not send a JSON response' );
	}

	// ── Capability / nonce gates ─────────────────────────────────────────────

	public function test_all_handlers_reject_non_admins(): void {
		$GLOBALS['wcs_test_can'] = false;
		foreach ( array( 'ajax_dismiss_notice', 'ajax_rebuild_index', 'ajax_get_index_status', 'ajax_delete_all_data' ) as $handler ) {
			$r = $this->ajax( array( Admin_Settings::class, $handler ) );
			$this->assertFalse( $r->success, "$handler must be admin-only" );
			$this->assertSame( 403, $r->status, "$handler must return 403" );
		}
	}

	public function test_all_handlers_require_a_valid_nonce(): void {
		$GLOBALS['wcs_test_referer_ok'] = false;
		foreach ( array( 'ajax_dismiss_notice', 'ajax_rebuild_index', 'ajax_get_index_status', 'ajax_delete_all_data' ) as $handler ) {
			$r = $this->ajax( array( Admin_Settings::class, $handler ) );
			$this->assertFalse( $r->success, "$handler must verify the nonce" );
		}
	}

	// ── Notice dismissal ─────────────────────────────────────────────────────

	public function test_dismissal_is_stored_per_user_for_allowed_notices_only(): void {
		$_POST['notice_id'] = 'wcs_notice_no_cache';
		$r = $this->ajax( array( Admin_Settings::class, 'ajax_dismiss_notice' ) );
		$this->assertTrue( $r->success );
		$this->assertSame( '1', get_user_meta( 1, 'wcs_notice_no_cache_dismissed', true ) );

		$_POST['notice_id'] = 'arbitrary_meta_key';
		$r = $this->ajax( array( Admin_Settings::class, 'ajax_dismiss_notice' ) );
		$this->assertFalse( $r->success, 'unknown notice ids must be rejected (user_meta injection guard)' );
		unset( $_POST['notice_id'] );
	}

	// ── Rebuild trigger ──────────────────────────────────────────────────────

	public function test_rebuild_sets_state_and_enqueues_the_first_batch(): void {
		$r = $this->ajax( array( Admin_Settings::class, 'ajax_rebuild_index' ) );

		$this->assertTrue( $r->success );
		$this->assertSame( 1, get_option( 'wcs_is_indexing' ) );
		$this->assertSame( 0, get_option( 'wcs_reindex_processed' ) );
		$this->assertGreaterThan( 0, get_option( 'wcs_rebuild_epoch' ) );

		$batches = array_values( array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'wcs_rebuild_index_batch' === $c['hook'] && 'enqueue_async' === $c['fn'] ) );
		$this->assertCount( 1, $batches );
		$this->assertSame( 0, $batches[0]['args']['last_id'] );
		$this->assertSame( get_option( 'wcs_rebuild_epoch' ), $batches[0]['args']['epoch'] );

		$sql = implode( "\n", $this->wpdb->queries );
		$this->assertStringContainsString( 'CREATE TABLE IF NOT EXISTS', $sql );
		$this->assertStringContainsString( 'TRUNCATE TABLE', $sql );
	}

	// ── Delete all data ──────────────────────────────────────────────────────

	public function test_delete_all_data_drops_tables_and_deletes_only_listed_options(): void {
		update_option( 'wcs_cache_version', 9 );
		update_option( 'wcs_result_count', 6 );
		// A foreign wcs_-prefixed option (e.g. WooCommerce Subscriptions) must survive.
		update_option( 'wcs_report_cache_foreign', 'precious' );

		$r = $this->ajax( array( Admin_Settings::class, 'ajax_delete_all_data' ) );

		$this->assertTrue( $r->success );
		$this->assertFalse( get_option( 'wcs_cache_version' ) );
		$this->assertFalse( get_option( 'wcs_result_count' ) );
		$this->assertSame( 'precious', get_option( 'wcs_report_cache_foreign' ), 'foreign wcs_ options must never be deleted' );

		$sql = implode( "\n", $this->wpdb->queries );
		$this->assertStringContainsString( 'DROP TABLE IF EXISTS `wp_wcs_search_index`', $sql );
		$this->assertStringContainsString( 'DROP TABLE IF EXISTS `wp_wcs_search_index_stage`', $sql );

		$unscheduled = array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'unschedule_all' === $c['fn'] );
		$this->assertNotEmpty( $unscheduled );
	}

	// ── Status endpoint ──────────────────────────────────────────────────────

	public function test_status_reports_idle_state(): void {
		$GLOBALS['wcs_test_publish_count'] = 42;
		update_option( 'wcs_reindex_processed', 42 );

		$r = $this->ajax( array( Admin_Settings::class, 'ajax_get_index_status' ) );

		$this->assertTrue( $r->success );
		$this->assertFalse( $r->payload['is_indexing'] );
		$this->assertSame( 42, $r->payload['processed'] );
		$this->assertSame( 42, $r->payload['total'] );
	}

	public function test_status_caps_processed_at_total(): void {
		$GLOBALS['wcs_test_publish_count'] = 10;
		update_option( 'wcs_reindex_processed', 25 ); // retried batches double-count

		$r = $this->ajax( array( Admin_Settings::class, 'ajax_get_index_status' ) );

		$this->assertSame( 10, $r->payload['processed'] );
	}

	public function test_status_clears_stuck_flag_when_no_batch_is_pending(): void {
		update_option( 'wcs_is_indexing', 1 );
		// Handler: no in-progress row, no pending/in-progress rows at all.
		$this->wpdb->handler = static fn( string $sql, string $type ) => null;

		$r = $this->ajax( array( Admin_Settings::class, 'ajax_get_index_status' ) );

		$this->assertFalse( $r->payload['is_indexing'] );
		$this->assertSame( 0, get_option( 'wcs_is_indexing' ) );
	}

	public function test_status_recovers_a_dead_batch_via_the_as_store_api(): void {
		update_option( 'wcs_is_indexing', 1 );
		update_option( 'wcs_rebuild_epoch', 555 );
		$this->wpdb->handler = static function ( string $sql, string $type ) {
			if ( 'row' === $type && str_contains( $sql, "status = 'in-progress'" ) ) {
				return (object) array(
					'action_id' => 77,
					'args'      => json_encode( array( 'last_id' => 300, 'epoch' => 555 ) ),
					'age_s'     => 400, // > 300s: FPM killed it
				);
			}
			if ( 'var' === $type && str_contains( $sql, "IN ('pending','in-progress')" ) ) {
				return 1; // re-enqueued batch counts as active
			}
			return null;
		};

		$r = $this->ajax( array( Admin_Settings::class, 'ajax_get_index_status' ) );

		$this->assertSame( array( 77 ), $GLOBALS['wcs_test_marked_failed'], 'must fail the action via the AS store API' );
		$this->assertTrue( $r->payload['recovering'] );
		$this->assertSame( 300, $r->payload['cursor'] );
		$requeued = array_values( array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'wcs_rebuild_index_batch' === $c['hook'] && 'enqueue_async' === $c['fn'] ) );
		$this->assertCount( 1, $requeued );
		$this->assertSame( 300, $requeued[0]['args']['last_id'] );
	}

	public function test_status_does_not_requeue_when_mark_failure_is_refused(): void {
		update_option( 'wcs_is_indexing', 1 );
		update_option( 'wcs_rebuild_epoch', 555 );
		$GLOBALS['wcs_test_mark_failure_throws'] = true;
		$this->wpdb->handler = static function ( string $sql, string $type ) {
			if ( 'row' === $type && str_contains( $sql, "status = 'in-progress'" ) ) {
				return (object) array( 'action_id' => 77, 'args' => json_encode( array( 'last_id' => 300, 'epoch' => 555 ) ), 'age_s' => 400 );
			}
			if ( 'var' === $type ) {
				return 1;
			}
			return null;
		};

		$r = $this->ajax( array( Admin_Settings::class, 'ajax_get_index_status' ) );

		$requeued = array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'enqueue_async' === $c['fn'] );
		$this->assertCount( 0, $requeued, 'a still-claimed action must not be raced by a duplicate chain' );
		$this->assertFalse( $r->payload['recovering'] );
	}

	// ── Self-healing: rebuild whose first batch never got dispatched ─────────
	//
	// Reproduces the exact failure found on narukistore.com: schedule_full_rebuild()
	// sets the epoch/is_indexing/cursor options and logs, but the
	// as_enqueue_async_action() call itself never lands (e.g. the request ran
	// out of memory right after). No batch action — pending, in-progress, or
	// complete — ever exists for that epoch.

	private function scriptNoActionsExistForAnyEpoch(): void {
		$this->wpdb->handler = static function ( string $sql, string $type ) {
			if ( 'row' === $type && str_contains( $sql, "status = 'in-progress'" ) ) {
				return null; // nothing ever ran, so nothing is stuck "in-progress" either
			}
			if ( 'var' === $type && str_contains( $sql, "IN ('pending','in-progress')" ) ) {
				return null; // zero active actions for the hook, any epoch
			}
			return null;
		};
	}

	public function test_status_resumes_from_the_cursor_when_no_batch_was_ever_dispatched(): void {
		update_option( 'wcs_is_indexing', 1 );
		update_option( 'wcs_rebuild_epoch', 999 );
		update_option( 'wcs_rebuild_cursor', 450 );
		$this->scriptNoActionsExistForAnyEpoch();

		$r = $this->ajax( array( Admin_Settings::class, 'ajax_get_index_status' ) );

		$this->assertTrue( $r->payload['is_indexing'], 'still indexing — a resume was dispatched, not a surrender' );
		$this->assertTrue( $r->payload['recovering'] );
		$this->assertSame( 450, $r->payload['cursor'] );
		$requeued = array_values( array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'wcs_rebuild_index_batch' === $c['hook'] && 'enqueue_async' === $c['fn'] ) );
		$this->assertCount( 1, $requeued );
		$this->assertSame( 450, $requeued[0]['args']['last_id'] );
		$this->assertSame( 999, $requeued[0]['args']['epoch'] );
	}

	public function test_status_retries_up_to_three_times_before_giving_up(): void {
		update_option( 'wcs_is_indexing', 1 );
		update_option( 'wcs_rebuild_epoch', 999 );
		$this->scriptNoActionsExistForAnyEpoch();

		// Three polls resume automatically...
		for ( $i = 0; $i < 3; $i++ ) {
			$r = $this->ajax( array( Admin_Settings::class, 'ajax_get_index_status' ) );
			$this->assertTrue( $r->payload['recovering'], "attempt $i should still be recovering" );
		}

		// ...the fourth gives up and surfaces a real error instead of retrying forever.
		$r = $this->ajax( array( Admin_Settings::class, 'ajax_get_index_status' ) );

		$this->assertFalse( $r->payload['is_indexing'] );
		$this->assertFalse( $r->payload['recovering'] );
		$this->assertSame( 0, get_option( 'wcs_is_indexing' ) );
		$this->assertSame( 'stuck_no_batch_dispatched', get_option( 'wcs_last_rebuild_error' ) );
		$this->assertSame( 'stuck_no_batch_dispatched', $r->payload['last_error'] );
	}

	public function test_no_last_error_reported_while_still_indexing(): void {
		update_option( 'wcs_is_indexing', 1 );
		update_option( 'wcs_last_rebuild_error', 'stuck_no_batch_dispatched' ); // stale, from an earlier failure
		update_option( 'wcs_rebuild_epoch', 999 );
		$this->wpdb->handler = static fn( string $sql, string $type ) =>
			( 'var' === $type && str_contains( $sql, "IN ('pending','in-progress')" ) ) ? 1 : null; // an action is active

		$r = $this->ajax( array( Admin_Settings::class, 'ajax_get_index_status' ) );

		$this->assertTrue( $r->payload['is_indexing'] );
		$this->assertSame( '', $r->payload['last_error'], 'an in-progress rebuild must not show a stale error from before' );
	}

	public function test_rebuild_trigger_clears_a_stale_error(): void {
		update_option( 'wcs_last_rebuild_error', 'stuck_no_batch_dispatched' );

		$this->ajax( array( Admin_Settings::class, 'ajax_rebuild_index' ) );

		$this->assertFalse( get_option( 'wcs_last_rebuild_error' ) );
	}

	// ── Settings registration ────────────────────────────────────────────────

	public function test_min_chars_sanitizer_clamps_to_valid_range(): void {
		Admin_Settings::register_settings();
		$sanitize = $GLOBALS['wcs_test_registered_settings']['wcs_min_chars']['sanitize_callback'];

		$this->assertSame( 1, $sanitize( 0 ) );
		$this->assertSame( 3, $sanitize( -3 ) ); // absint() takes the absolute value
		$this->assertSame( 10, $sanitize( 50 ) );
		$this->assertSame( 3, $sanitize( 3 ) );
	}

	public function test_every_registered_setting_is_in_the_cleanup_list(): void {
		Admin_Settings::register_settings();
		$missing = array_diff( array_keys( $GLOBALS['wcs_test_registered_settings'] ), Activator::PLUGIN_OPTIONS );
		$this->assertSame( array(), array_values( $missing ) );
	}

	// ── Page rendering (views) ───────────────────────────────────────────────

	public function test_settings_tab_renders_status_form_and_danger_zone(): void {
		update_option( 'wcs_last_indexed', time() - 300 );
		$_GET['tab'] = 'settings';

		ob_start();
		Admin_Settings::render_settings_page();
		$html = ob_get_clean();
		unset( $_GET['tab'] );

		$this->assertStringContainsString( 'id="wcs-rebuild-btn"', $html );
		$this->assertStringContainsString( 'id="wcs-status-wrapper"', $html );
		$this->assertStringContainsString( 'Status: Idle / Complete', $html );
		$this->assertStringContainsString( '5 mins ago', $html );
		$this->assertStringNotContainsString( 'name="wcs_synonyms"', $html );
		$this->assertStringContainsString( 'id="wcs-delete-data-btn"', $html );
		$this->assertStringContainsString( 'settings_fields:wcs_settings_group', $html );
	}

	public function test_indexing_state_disables_the_rebuild_button(): void {
		update_option( 'wcs_is_indexing', 1 );

		ob_start();
		Admin_Settings::render_settings_page();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Status: Indexing', $html );
		$this->assertStringContainsString( "disabled='disabled'", $html );
	}

	public function test_docs_tab_renders_hook_documentation(): void {
		$_GET['tab'] = 'docs';

		ob_start();
		Admin_Settings::render_settings_page();
		$html = ob_get_clean();
		unset( $_GET['tab'] );

		$this->assertStringContainsString( 'wcs_indexed_product_data', $html );
		$this->assertStringContainsString( 'wcs_ranking_weights', $html );
		$this->assertStringContainsString( 'wcs_synonym_groups', $html );
		$this->assertStringContainsString( '[turbo_search]', $html );
	}

	public function test_unknown_tab_falls_back_to_settings(): void {
		$_GET['tab'] = '../../evil';

		ob_start();
		Admin_Settings::render_settings_page();
		$html = ob_get_clean();
		unset( $_GET['tab'] );

		$this->assertStringContainsString( 'id="wcs-rebuild-btn"', $html );
	}

	public function test_render_is_blocked_for_non_admins(): void {
		$GLOBALS['wcs_test_can'] = false;

		ob_start();
		Admin_Settings::render_settings_page();
		$this->assertSame( '', ob_get_clean() );
	}

	// ── Admin notices ────────────────────────────────────────────────────────

	public function test_schema_error_notice_is_shown_and_escaped(): void {
		update_option( 'wcs_schema_error', "BLOB <b>column</b> can't have a default" );

		ob_start();
		Admin_Settings::render_admin_notices();
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Search Index Table Could Not Be Created', $html );
		$this->assertStringContainsString( '&lt;b&gt;column&lt;/b&gt;', $html );
	}

	public function test_notices_only_render_on_our_own_screen(): void {
		update_option( 'wcs_schema_error', 'boom' );
		$GLOBALS['wcs_test_screen_id'] = 'edit-post';

		ob_start();
		Admin_Settings::render_admin_notices();
		$this->assertSame( '', ob_get_clean() );
	}

	public function test_dismissed_mu_notice_stays_hidden(): void {
		update_user_meta( 1, 'wcs_notice_mu_bypass_dismissed', '1' );

		ob_start();
		Admin_Settings::render_admin_notices();
		$html = ob_get_clean();

		$this->assertStringNotContainsString( 'wcs_notice_mu_bypass', $html );
	}

	// ── Asset enqueueing ─────────────────────────────────────────────────────

	public function test_admin_assets_enqueue_only_on_our_screen_with_config(): void {
		Admin_Settings::enqueue_admin_assets( 'edit.php' );
		$this->assertArrayNotHasKey( 'script', $GLOBALS['wcs_test_enqueued'] );

		Admin_Settings::enqueue_admin_assets( 'settings_page_wcs-fast-search' );
		$this->assertContains( 'wcs-admin-js', $GLOBALS['wcs_test_enqueued']['script'] );

		$config = json_decode( substr( $GLOBALS['wcs_test_inline_js']['wcs-admin-js'][0], strlen( 'const wcsAdmin = ' ), -1 ), true );
		$this->assertSame( 'nonce-wcs_status', $config['nonces']['status'] );
		$this->assertSame( 'nonce-wcs_rebuild', $config['nonces']['rebuild'] );
		$this->assertArrayHasKey( 'confirmDelete', $config['i18n'] );
	}
}
