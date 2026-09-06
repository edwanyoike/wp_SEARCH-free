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
}
