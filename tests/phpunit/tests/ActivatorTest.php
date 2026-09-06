<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WCS\Search\Activator;

final class ActivatorTest extends TestCase {

	private Fake_WPDB $wpdb;

	protected function setUp(): void {
		wcs_tests_reset();
		$this->wpdb      = new Fake_WPDB();
		$GLOBALS['wpdb'] = $this->wpdb;

		// Remove any MU file a previous test copied.
		$mu = WPMU_PLUGIN_DIR . '/wcs-cache-bypass.php';
		if ( file_exists( $mu ) ) {
			unlink( $mu );
		}
	}

	/** A `SHOW ENGINES` result set with a healthy, enabled InnoDB row. */
	private function innodbAvailableRows(): array {
		return array(
			array( 'Engine' => 'InnoDB', 'Support' => 'DEFAULT' ),
			array( 'Engine' => 'MyISAM', 'Support' => 'YES' ),
		);
	}

	/** Script the fake wpdb so create_tables() sees healthy tables. */
	private function healthyTables( string $create_suffix = '' ): void {
		$this->wpdb->handler = function ( string $sql, string $type ) use ( $create_suffix ) {
			if ( 'results' === $type && str_contains( $sql, 'SHOW ENGINES' ) ) {
				return $this->innodbAvailableRows();
			}
			if ( 'var' === $type && str_contains( $sql, 'SHOW TABLES' ) ) {
				// Echo back the requested table name → "exists".
				preg_match( "/LIKE '([^']+)'/", $sql, $m );
				return $m[1] ?? null;
			}
			if ( 'var' === $type && str_contains( $sql, 'VERSION()' ) ) {
				return '10.11.0-MariaDB';
			}
			if ( 'row' === $type && str_contains( $sql, 'SHOW CREATE TABLE' ) ) {
				return array( 'wp_wcs_search_index', 'CREATE TABLE ... FULLTEXT KEY search_data (title,sku,content)' . $create_suffix );
			}
			if ( 'row' === $type && str_contains( $sql, 'SHOW INDEX' ) ) {
				return null; // index missing → ALTER runs
			}
			return 'query' === $type ? 1 : null;
		};
	}

	// ── Schema migration on init ─────────────────────────────────────────────

	public function test_version_bump_runs_migration_and_stores_new_version(): void {
		update_option( 'wcs_db_version', '1.0.0' );
		update_option( 'wcs_mu_version', WCS_VERSION ); // MU already current
		$this->healthyTables();

		Activator::init();

		$this->assertNotEmpty( $GLOBALS['wcs_test_dbdelta'], 'dbDelta must run on version bump' );
		$this->assertNotSame( '1.0.0', get_option( 'wcs_db_version' ) );
	}

	public function test_wcs_rate_limits_table_is_created_on_migration(): void {
		// Backs Rate_Limiter's atomic DB fallback (used on any host without
		// APCu) — shared by both editions, since search abuse protection
		// isn't a Pro feature. Missing this table silently falls open (every
		// request allowed) rather than erroring, so it's worth confirming it
		// actually gets created rather than relying on that fail-open path.
		update_option( 'wcs_db_version', '1.0.0' );
		update_option( 'wcs_mu_version', WCS_VERSION );
		$this->healthyTables();

		Activator::init();

		$statements = implode( "\n", $GLOBALS['wcs_test_dbdelta'] );
		$this->assertStringContainsString( 'wcs_rate_limits', $statements );
		$this->assertStringContainsString( 'PRIMARY KEY  (rl_key)', $statements );
	}

	public function test_current_version_skips_migration_and_table_probe_on_frontend(): void {
		// Simulate an up-to-date install: read the version Activator would store.
		$this->healthyTables();
		update_option( 'wcs_db_version', '0' );
		Activator::init(); // first run migrates and records the current version
		$current = get_option( 'wcs_db_version' );

		$GLOBALS['wcs_test_dbdelta'] = array();
		$this->wpdb->queries         = array();
		update_option( 'wcs_mu_version', WCS_VERSION );

		Activator::init(); // frontend request (is_admin false), version current

		$this->assertSame( array(), $GLOBALS['wcs_test_dbdelta'] );
		$this->assertSame( array(), $this->wpdb->queries, 'no SHOW TABLES probe on frontend requests' );
		$this->assertSame( $current, get_option( 'wcs_db_version' ) );
	}

	public function test_upgrade_from_pre_150_triggers_one_full_rebuild(): void {
		update_option( 'wcs_db_version', '1.4.1' );
		update_option( 'wcs_mu_version', WCS_VERSION );
		$this->healthyTables();

		Activator::init();

		$batches = array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'wcs_rebuild_index_batch' === $c['hook'] && 'enqueue_async' === $c['fn'] );
		$this->assertCount( 1, $batches );
	}

	public function test_fresh_activation_marker_does_not_trigger_init_rebuild(): void {
		update_option( 'wcs_db_version', '0' ); // never installed before... but stored '0' means fresh
		update_option( 'wcs_mu_version', WCS_VERSION );
		$this->healthyTables();

		Activator::init();

		$batches = array_filter( $GLOBALS['wcs_test_as_calls'], static fn( $c ) => 'wcs_rebuild_index_batch' === $c['hook'] && 'enqueue_async' === $c['fn'] );
		$this->assertCount( 0, $batches, 'activate() schedules the initial build; init() must not double it' );
	}

	// ── FULLTEXT parser detection ────────────────────────────────────────────

	public function test_parser_recorded_as_ngram_when_index_uses_ngram(): void {
		update_option( 'wcs_db_version', '1.0.0' );
		update_option( 'wcs_mu_version', WCS_VERSION );
		$this->healthyTables( ' WITH PARSER `ngram`' );

		Activator::init();

		$this->assertSame( 'ngram', get_option( 'wcs_ft_parser' ) );
	}

	public function test_parser_recorded_as_default_without_ngram(): void {
		update_option( 'wcs_db_version', '1.0.0' );
		update_option( 'wcs_mu_version', WCS_VERSION );
		$this->healthyTables();

		Activator::init();

		$this->assertSame( 'default', get_option( 'wcs_ft_parser' ) );
	}

	// ── Schema failure surfacing ─────────────────────────────────────────────

	public function test_failed_table_creation_records_schema_error(): void {
		update_option( 'wcs_db_version', '1.0.0' );
		update_option( 'wcs_mu_version', WCS_VERSION );
		// SHOW TABLES always misses → table never created.
		$this->wpdb->handler = static fn( string $sql, string $type ) => 'query' === $type ? 1 : null;
		$this->wpdb->last_error = 'BLOB, TEXT, GEOMETRY or JSON column can\'t have a default value';

		Activator::init();

		$this->assertNotEmpty( get_option( 'wcs_schema_error' ) );
	}

	public function test_successful_creation_clears_a_previous_schema_error(): void {
		update_option( 'wcs_schema_error', 'old failure' );
		update_option( 'wcs_db_version', '1.0.0' );
		update_option( 'wcs_mu_version', WCS_VERSION );
		$this->healthyTables();

		Activator::init();

		$this->assertFalse( get_option( 'wcs_schema_error' ) );
	}

	// ── InnoDB availability (defense only — see is_innodb_available()) ───────

	public function test_missing_innodb_records_a_clear_error_and_skips_create_table(): void {
		update_option( 'wcs_db_version', '1.0.0' );
		update_option( 'wcs_mu_version', WCS_VERSION );
		$this->wpdb->handler = static fn( string $sql, string $type ) =>
			( 'results' === $type && str_contains( $sql, 'SHOW ENGINES' ) )
				? array( array( 'Engine' => 'MyISAM', 'Support' => 'YES' ) ) // no InnoDB row at all
				: null;

		Activator::init();

		$error = (string) get_option( 'wcs_schema_error' );
		$this->assertStringContainsString( 'InnoDB', $error );
		$this->assertSame( array(), $GLOBALS['wcs_test_dbdelta'], 'CREATE TABLE must never be attempted when InnoDB is unavailable' );
	}

	public function test_innodb_support_disabled_records_a_clear_error(): void {
		update_option( 'wcs_db_version', '1.0.0' );
		update_option( 'wcs_mu_version', WCS_VERSION );
		$this->wpdb->handler = static fn( string $sql, string $type ) =>
			( 'results' === $type && str_contains( $sql, 'SHOW ENGINES' ) )
				? array( array( 'Engine' => 'InnoDB', 'Support' => 'DISABLED' ) )
				: null;

		Activator::init();

		$this->assertStringContainsString( 'InnoDB', (string) get_option( 'wcs_schema_error' ) );
		$this->assertSame( array(), $GLOBALS['wcs_test_dbdelta'] );
	}

	public function test_inconclusive_engine_check_fails_open_and_still_creates_tables(): void {
		// SHOW ENGINES itself unavailable/erroring must never block real table
		// creation on its own — the CREATE TABLE attempt is the authoritative
		// signal either way.
		update_option( 'wcs_db_version', '1.0.0' );
		update_option( 'wcs_mu_version', WCS_VERSION );
		$this->healthyTables();
		$inner_handler        = $this->wpdb->handler;
		$this->wpdb->handler = function ( string $sql, string $type ) use ( $inner_handler ) {
			if ( 'results' === $type && str_contains( $sql, 'SHOW ENGINES' ) ) {
				return null; // query failed
			}
			return $inner_handler( $sql, $type );
		};

		Activator::init();

		$this->assertNotEmpty( $GLOBALS['wcs_test_dbdelta'], 'table creation must proceed when the engine check itself is inconclusive' );
		$this->assertFalse( get_option( 'wcs_schema_error' ) );
	}

	// ── MU plugin self-update ────────────────────────────────────────────────

	public function test_outdated_mu_version_reinstalls_the_mu_file_on_admin_requests(): void {
		$GLOBALS['wcs_test_is_admin'] = true;
		update_option( 'wcs_mu_version', 'old-version' );
		update_option( 'wcs_db_version', '99.0.0' ); // no migration noise
		$this->healthyTables();

		Activator::init();

		$this->assertFileExists( WPMU_PLUGIN_DIR . '/wcs-cache-bypass.php' );
		$this->assertSame( WCS_VERSION, get_option( 'wcs_mu_version' ) );
		$this->assertFileEquals( WCS_PLUGIN_DIR . 'mu-plugin/wcs-cache-bypass.php', WPMU_PLUGIN_DIR . '/wcs-cache-bypass.php' );
	}

	/**
	 * Regression: a version-only check can't tell "the file needs no work"
	 * apart from "the file is gone but wcs_mu_version happens to still
	 * match" — remove_mu_plugin() deliberately only checks the CURRENT
	 * site's own is_pro_edition_active() and admits it does not scan the
	 * rest of a Multisite network for another site still depending on the
	 * shared file (see its docblock). So Free's own deactivation on one
	 * site can delete the file out from under a different site whose own
	 * wcs_mu_version option never changed, and the old version-only check
	 * would never notice or repair it. Mirrors $table_missing's identical
	 * reasoning for the DB table two lines above this check in init().
	 */
	public function test_current_mu_version_still_reinstalls_a_missing_file(): void {
		$GLOBALS['wcs_test_is_admin'] = true;
		update_option( 'wcs_mu_version', WCS_VERSION ); // matches — version alone would wrongly skip
		update_option( 'wcs_db_version', '99.0.0' );
		$this->healthyTables();

		Activator::init();

		$this->assertFileExists( WPMU_PLUGIN_DIR . '/wcs-cache-bypass.php', 'a missing file must be repaired even when the stored version already matches' );
	}

	public function test_current_mu_version_and_present_file_leaves_it_untouched(): void {
		$GLOBALS['wcs_test_is_admin'] = true;
		update_option( 'wcs_mu_version', 'old-version' );
		update_option( 'wcs_db_version', '99.0.0' );
		$this->healthyTables();
		Activator::init(); // installs the file once
		update_option( 'wcs_mu_version', WCS_VERSION );

		Activator::init(); // steady state: version current, file already present

		$this->assertFileEquals( WCS_PLUGIN_DIR . 'mu-plugin/wcs-cache-bypass.php', WPMU_PLUGIN_DIR . '/wcs-cache-bypass.php' );
	}

	public function test_frontend_requests_do_not_repair_a_missing_mu_file(): void {
		$GLOBALS['wcs_test_is_admin'] = false;
		update_option( 'wcs_mu_version', WCS_VERSION );
		update_option( 'wcs_db_version', '99.0.0' );
		$this->healthyTables();

		Activator::init();

		$this->assertFileDoesNotExist( WPMU_PLUGIN_DIR . '/wcs-cache-bypass.php', 'the file-existence probe is admin-only, same as the version check it augments' );
	}

	public function test_frontend_requests_never_touch_the_mu_file(): void {
		$GLOBALS['wcs_test_is_admin'] = false;
		update_option( 'wcs_mu_version', 'old-version' );
		update_option( 'wcs_db_version', '99.0.0' );
		$this->healthyTables();

		Activator::init();

		$this->assertFileDoesNotExist( WPMU_PLUGIN_DIR . '/wcs-cache-bypass.php' );
	}

	// ── Cron bootstrap ───────────────────────────────────────────────────────

	public function test_daily_gc_is_scheduled_once(): void {
		update_option( 'wcs_db_version', '99.0.0' );
		update_option( 'wcs_mu_version', WCS_VERSION );
		$this->healthyTables();

		Activator::init();
		$first = $GLOBALS['wcs_test_cron']['wcs_daily_transient_gc'] ?? null;
		Activator::init();

		$this->assertNotNull( $first );
		$this->assertSame( $first, $GLOBALS['wcs_test_cron']['wcs_daily_transient_gc'] );
	}

	// ── Dynamic (per-product/per-rebuild) WP-Cron cleanup ────────────────────

	/**
	 * Regression: the three WP-Cron retry hooks added across the rebuild-
	 * reliability work (wcs_retry_rebuild_scheduling, wcs_retry_product_
	 * enqueue, wcs_retry_product_delete) are all scheduled with dynamic,
	 * per-call arguments (a product ID, or a rebuild epoch/cursor pair) —
	 * unlike wcs_daily_transient_gc, wp_next_scheduled()/wp_unschedule_
	 * event() can't clear "every pending instance" of them without already
	 * knowing each instance's exact args, and any number of distinct
	 * instances (different products, different rebuilds) can be pending at
	 * once. Deactivation and uninstall used to leave these all behind.
	 */
	public function test_clear_dynamic_cron_hooks_removes_every_pending_instance_regardless_of_args(): void {
		wp_schedule_single_event( time() + 30, 'wcs_retry_product_enqueue', array( 7 ) );
		wp_schedule_single_event( time() + 30, 'wcs_retry_product_enqueue', array( 8 ) );
		wp_schedule_single_event( time() + 30, 'wcs_retry_product_delete', array( 9 ) );
		wp_schedule_single_event( time() + 45, 'wcs_retry_rebuild_scheduling', array( 42, 500 ) );

		Activator::clear_dynamic_cron_hooks();

		$this->assertSame( array(), _get_cron_array(), 'every pending instance across every dynamic hook and timestamp must be cleared' );
	}

	public function test_clear_dynamic_cron_hooks_leaves_unrelated_hooks_alone(): void {
		update_option( 'wcs_db_version', '99.0.0' );
		update_option( 'wcs_mu_version', WCS_VERSION );
		$this->healthyTables();
		Activator::init(); // schedules wcs_daily_transient_gc
		wp_schedule_single_event( time() + 30, 'wcs_retry_product_enqueue', array( 7 ) );

		Activator::clear_dynamic_cron_hooks();

		$this->assertNotNull( $GLOBALS['wcs_test_cron']['wcs_daily_transient_gc'] ?? null, 'an unrelated recurring hook must survive' );
		$remaining = _get_cron_array();
		foreach ( $remaining as $hooks_at_timestamp ) {
			$this->assertArrayNotHasKey( 'wcs_retry_product_enqueue', $hooks_at_timestamp );
		}
	}

	// ── Mutual exclusivity with the Pro edition ──────────────────────────────

	public function test_pro_edition_not_detected_when_absent(): void {
		$this->assertFalse( Activator::is_pro_edition_active() );
	}

	public function test_pro_edition_detected_when_active(): void {
		$GLOBALS['wcs_test_active_plugins'] = array( 'turbo-search-for-woocommerce-pro/turbo-search-for-woocommerce.php' );

		$this->assertTrue( Activator::is_pro_edition_active() );
	}

	public function test_unrelated_active_plugins_do_not_trigger_detection(): void {
		$GLOBALS['wcs_test_active_plugins'] = array( 'woocommerce/woocommerce.php' );

		$this->assertFalse( Activator::is_pro_edition_active() );
	}

	/**
	 * Regression: wp-content/mu-plugins/ is a single, network-wide directory
	 * — not per-site — and Free/Pro both install and use the exact same
	 * wcs-cache-bypass.php file. deactivate() used to remove it
	 * unconditionally, so migrating from Free to Pro on the same site (a
	 * supported, expected path — Free's own activation guard refuses to run
	 * alongside Pro) silently broke Pro's fast-path cache-bypass the moment
	 * an admin deactivated the now-redundant Free copy.
	 */
	public function test_deactivate_does_not_remove_the_shared_mu_file_when_pro_is_still_active(): void {
		$mu = WPMU_PLUGIN_DIR . '/wcs-cache-bypass.php';
		file_put_contents( $mu, '<?php // placeholder' );
		$GLOBALS['wcs_test_active_plugins'] = array( 'turbo-search-for-woocommerce-pro/turbo-search-for-woocommerce.php' );

		Activator::deactivate();

		$this->assertFileExists( $mu, 'must not delete the MU file a still-active Pro install needs' );
	}

	public function test_deactivate_removes_the_mu_file_when_pro_is_not_active(): void {
		$mu = WPMU_PLUGIN_DIR . '/wcs-cache-bypass.php';
		file_put_contents( $mu, '<?php // placeholder' );

		Activator::deactivate();

		$this->assertFileDoesNotExist( $mu, 'a genuine removal (no other edition active) must still clean up' );
	}

	// ── Network site iteration (each_network_site) ───────────────────────────
	//
	// Regression coverage for the removed 1,000-site hard cap: activate()/
	// deactivate()/uninstall.php used to run a single `LIMIT 1000` query, so a
	// network above that size silently skipped every later site. Tested here
	// directly against each_network_site() rather than through activate()/
	// deactivate() themselves, which stay @codeCoverageIgnore (they need a
	// real multisite activation context) — this is the one piece of that path
	// that's meaningfully unit-testable in isolation.

	public function test_each_network_site_processes_every_site_across_multiple_pages(): void {
		$page_size                        = ( new ReflectionClass( Activator::class ) )->getConstant( 'NETWORK_SITE_PAGE_SIZE' );
		$GLOBALS['wcs_test_all_site_ids'] = range( 1, $page_size + 5 ); // more than one page

		$visited = array();
		Activator::each_network_site( function () use ( &$visited ) {
			$visited[] = end( $GLOBALS['wcs_test_current_blog_stack'] );
		} );

		$this->assertSame( range( 1, $page_size + 5 ), $visited, 'every site across every page must be visited, not just the first page' );
	}

	public function test_each_network_site_restores_blog_context_after_every_site(): void {
		$GLOBALS['wcs_test_all_site_ids'] = array( 5, 6, 7 );

		Activator::each_network_site( static function () {} );

		$this->assertSame( array( 5, 6, 7 ), $GLOBALS['wcs_test_switched_blogs'] );
		$this->assertSame( 3, $GLOBALS['wcs_test_restored_blogs_count'], 'restore_current_blog() must run once per switch_to_blog()' );
		$this->assertSame( array(), $GLOBALS['wcs_test_current_blog_stack'], 'no blog context should remain switched after the loop finishes' );
	}

	public function test_each_network_site_restores_blog_context_even_when_callback_throws(): void {
		$GLOBALS['wcs_test_all_site_ids'] = array( 1, 2, 3 );

		try {
			Activator::each_network_site( static function () {
				throw new \RuntimeException( 'simulated per-site failure' );
			} );
			$this->fail( 'expected the exception to propagate' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'simulated per-site failure', $e->getMessage() );
		}

		// The very first site's callback already threw, but restore_current_blog()
		// must still have run for it before the exception propagated.
		$this->assertSame( 1, $GLOBALS['wcs_test_restored_blogs_count'] );
		$this->assertSame( array(), $GLOBALS['wcs_test_current_blog_stack'] );
	}

	public function test_each_network_site_handles_an_empty_network(): void {
		$GLOBALS['wcs_test_all_site_ids'] = array();

		Activator::each_network_site( function () {
			$this->fail( 'callback must never run when there are no sites' );
		} );

		$this->assertSame( array(), $GLOBALS['wcs_test_switched_blogs'] );
	}
}
