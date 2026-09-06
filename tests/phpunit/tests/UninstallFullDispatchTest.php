<?php
declare(strict_types=1);

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * Direct end-to-end reproduction of the exact gap an external recheck
 * reported against uninstall.php: calling wcs_delete_notice_dismissals()
 * (or wcs_uninstall_single_site()) in isolation, as UninstallTest does,
 * proves each function's OWN gate works — but not that uninstall.php's
 * actual top-level dispatch (the code path a real WordPress uninstall
 * runs) wires that gate in correctly. That gap was real: the notice-
 * dismissal usermeta DELETE queries used to sit directly in top-level code
 * gated only by $delete_data, with no Pro check at all, so this exact
 * scenario (Pro active, delete-data opted in) still ran them even though
 * wcs_uninstall_single_site() itself correctly refused.
 *
 * This class's one test requires uninstall.php completely fresh in its own
 * isolated PHP process — a separate file/class (PHPUnit here resolves a
 * test file to the one class matching its filename, so a second class
 * co-located in UninstallTest.php is silently never discovered) so it
 * never shares UninstallTest's setUp(), which does its own one-time
 * require with different (delete_data=false) state.
 */
final class UninstallFullDispatchTest extends TestCase {

	#[RunInSeparateProcess]
	public function test_full_uninstall_dispatch_touches_nothing_when_pro_is_active(): void {
		wcs_tests_reset();
		$wpdb            = new Fake_WPDB();
		$GLOBALS['wpdb'] = $wpdb;

		update_option( 'wcs_delete_data_on_uninstall', true );
		$GLOBALS['wcs_test_is_multisite']   = false;
		$GLOBALS['wcs_test_active_plugins'] = array( 'turbo-search-for-woocommerce-pro/turbo-search-for-woocommerce.php' );
		define( 'WP_UNINSTALL_PLUGIN', true );

		require WCS_PLUGIN_DIR . 'uninstall.php';

		$this->assertSame( array(), $wpdb->queries, 'the real top-level uninstall dispatch — not just its individual helpers — must not touch shared tables/options/usermeta while Pro is active' );
	}

	/**
	 * Regression: the notice-dismissal usermeta cleanup and the MU-file
	 * removal both used to check only is_pro_edition_active() — same-site
	 * only. WordPress's users/usermeta tables (and wp-content/mu-plugins/)
	 * are network-wide on Multisite, so Pro running on a DIFFERENT site
	 * than the one being uninstalled was invisible to that check. Both call
	 * sites now go through Activator::is_shared_network_resource_still_
	 * needed(), which scans every site on Multisite.
	 *
	 * Site 1 (the one this uninstall run is scoped to) has neither edition
	 * active and opted in to data deletion, so its OWN per-site tables must
	 * still be dropped as normal — that part is unaffected and unrelated to
	 * this regression. Site 2 has Pro active — set via
	 * wcs_test_active_plugins_by_site, which the is_plugin_active() fake
	 * checks against whichever site switch_to_blog() last switched into,
	 * so this genuinely simulates "a DIFFERENT site has Pro", not just the
	 * one running uninstall (a flat global active-plugins list can't tell
	 * those apart, which is why the original version of this test could
	 * not actually have caught this specific regression).
	 */
	#[RunInSeparateProcess]
	public function test_full_uninstall_dispatch_protects_shared_network_resources_when_pro_is_active_on_a_different_site(): void {
		wcs_tests_reset();
		$wpdb            = new Fake_WPDB();
		$GLOBALS['wpdb'] = $wpdb;

		update_option( 'wcs_delete_data_on_uninstall', true );
		$GLOBALS['wcs_test_is_multisite']           = true;
		$GLOBALS['wcs_test_all_site_ids']           = array( 1, 2 );
		$GLOBALS['wcs_test_active_plugins']         = array(); // site 1 / initiating context: neither edition active
		$GLOBALS['wcs_test_active_plugins_by_site'] = array(
			2 => array( 'turbo-search-for-woocommerce-pro/turbo-search-for-woocommerce.php' ), // Pro active on site 2 only
		);
		define( 'WP_UNINSTALL_PLUGIN', true );

		require WCS_PLUGIN_DIR . 'uninstall.php';

		$sql = implode( "\n", $wpdb->queries );
		$this->assertStringContainsString( 'DROP TABLE', $sql, "site 1 has no Pro active and opted in — its own per-site tables must still be cleaned up" );
		$this->assertStringNotContainsString( 'usermeta', $sql, 'notice-dismissal usermeta is network-wide — must be protected because Pro is active on site 2, even though site 1 itself has no Pro' );
	}
}
