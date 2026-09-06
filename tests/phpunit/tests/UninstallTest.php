<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Exercises uninstall.php's own runtime logic directly rather than only
 * scanning its source as text (see CleanupCoverageTest for the latter).
 *
 * uninstall.php is a flat script with top-level executable code (drops
 * tables, deletes options) that runs immediately on require, and it
 * declares wcs_uninstall_single_site() at the top level too — both can only
 * happen ONCE per PHP process, since a second require_once is a no-op and a
 * second plain require would fatal on redeclaring the function. So this
 * class requires the file exactly once (in the first test that runs),
 * deliberately with wcs_delete_data_on_uninstall false and Multisite off,
 * so the file's own top-level dispatch is a no-op — then every test calls
 * wcs_uninstall_single_site() directly, as many times as needed, to
 * exercise ITS OWN internal preference gate in isolation.
 */
final class UninstallTest extends TestCase {

	private static bool $loaded = false;

	private Fake_WPDB $wpdb;

	protected function setUp(): void {
		wcs_tests_reset();
		$this->wpdb      = new Fake_WPDB();
		$GLOBALS['wpdb'] = $this->wpdb;

		if ( ! self::$loaded ) {
			update_option( 'wcs_delete_data_on_uninstall', false ); // top-level dispatch must no-op
			$GLOBALS['wcs_test_is_multisite'] = false;
			define( 'WP_UNINSTALL_PLUGIN', true );
			require WCS_PLUGIN_DIR . 'uninstall.php';
			self::$loaded = true;
		}

		// wcs_tests_reset() above already cleared option/query state fresh
		// for this test — the one-time require above ran before that reset
		// on the very first test only, and did nothing destructive anyway
		// (wcs_delete_data_on_uninstall was false), so there's nothing to
		// re-clear here beyond what setUp() already does every time.
	}

	/**
	 * Regression (Finding 3): the deletion preference used to be read ONCE
	 * at the top of this file and applied uniformly via each_network_site()
	 * to every site in the network — a main site's own stored value
	 * deciding every subsite's fate regardless of what that subsite itself
	 * had chosen. wcs_uninstall_single_site() now re-reads the option
	 * itself; since each_network_site() only ever calls it after switching
	 * into a given site's context, this is what makes the check reflect
	 * THAT site's own stored preference in real WordPress, not the one this
	 * test can simulate switching (this test harness's fakes don't model
	 * per-site option storage) — what's verified directly here is the
	 * actual regression: the function no longer performs any destructive
	 * cleanup at all when the current option value is false.
	 */
	public function test_wcs_uninstall_single_site_does_nothing_when_disabled(): void {
		update_option( 'wcs_delete_data_on_uninstall', false );

		wcs_uninstall_single_site();

		$this->assertSame( array(), $this->wpdb->queries, 'no table/transient cleanup may run for a site that has not opted in' );
	}

	public function test_wcs_uninstall_single_site_cleans_up_when_enabled(): void {
		update_option( 'wcs_delete_data_on_uninstall', true );

		wcs_uninstall_single_site();

		$sql = implode( "\n", $this->wpdb->queries );
		$this->assertStringContainsString( 'DROP TABLE', $sql, 'a site that opted in must still be cleaned up' );
	}

	/**
	 * Regression (Finding 4, second half — found on re-verification after
	 * the first pass only protected the shared MU file): wcs_search_index,
	 * wcs_search_index_stage, wcs_rate_limits, and every option in
	 * Activator::PLUGIN_OPTIONS are shared with Pro (see PORTING.md), not
	 * Free-exclusive state. An admin's opt-in to "delete data on uninstall"
	 * is consent to remove FREE's own footprint, not consent to drop the
	 * search index a migrated-to Pro install now depends on. This must be
	 * refused even when the admin explicitly opted in, exactly like the
	 * MU-file removal is refused regardless of that same setting.
	 */
	public function test_wcs_uninstall_single_site_does_nothing_when_pro_is_active(): void {
		update_option( 'wcs_delete_data_on_uninstall', true );
		$GLOBALS['wcs_test_active_plugins'] = array( 'turbo-search-for-woocommerce-pro/turbo-search-for-woocommerce.php' );

		wcs_uninstall_single_site();

		$this->assertSame( array(), $this->wpdb->queries, 'must not touch shared tables/options while Pro is active, even with explicit opt-in' );
	}

	public function test_wcs_delete_notice_dismissals_runs_when_pro_is_not_active(): void {
		wcs_delete_notice_dismissals();

		$sql = implode( "\n", $this->wpdb->queries );
		$this->assertStringContainsString( 'wp_usermeta', $sql );
		$this->assertStringContainsString( 'wcs_notice_mu_bypass_dismissed', $sql );
	}

	/**
	 * Regression: wcs_notice_mu_bypass_dismissed and wcs_notice_no_cache_
	 * dismissed record dismissal of notices about concerns (the shared MU
	 * file, the shared no-persistent-object-cache warning) that apply
	 * identically to a still-active Pro install on this same site. This
	 * block used to run unconditionally whenever wcs_delete_data_on_
	 * uninstall was enabled, with no Pro check at all — unlike every other
	 * shared resource this file protects (tables/options via
	 * wcs_uninstall_single_site(), the MU file itself below it) — so
	 * removing Free after migrating to Pro would silently reset Pro's own
	 * already-dismissed notices back to "not dismissed" for every admin.
	 */
	public function test_wcs_delete_notice_dismissals_does_nothing_when_pro_is_active(): void {
		$GLOBALS['wcs_test_active_plugins'] = array( 'turbo-search-for-woocommerce-pro/turbo-search-for-woocommerce.php' );

		wcs_delete_notice_dismissals();

		$this->assertSame( array(), $this->wpdb->queries, 'must not touch shared notice-dismissal user meta while Pro is active' );
	}
}
