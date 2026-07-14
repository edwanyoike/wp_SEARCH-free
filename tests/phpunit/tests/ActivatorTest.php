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
}
