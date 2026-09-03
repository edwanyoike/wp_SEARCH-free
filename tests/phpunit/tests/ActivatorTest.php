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

	/** Script the fake wpdb so create_tables() sees healthy tables. */
	private function healthyTables( string $create_suffix = '' ): void {
		$this->wpdb->handler = static function ( string $sql, string $type ) use ( $create_suffix ) {
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

	public function test_current_mu_version_skips_file_operations(): void {
		$GLOBALS['wcs_test_is_admin'] = true;
		update_option( 'wcs_mu_version', WCS_VERSION );
		update_option( 'wcs_db_version', '99.0.0' );
		$this->healthyTables();

		Activator::init();

		$this->assertFileDoesNotExist( WPMU_PLUGIN_DIR . '/wcs-cache-bypass.php' );
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
