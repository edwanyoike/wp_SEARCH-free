<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * wcs_mu_resolve_active_edition() — which edition (if any) the MU
 * cache-bypass fast path is allowed to load.
 *
 * Regression coverage for a real bug: the fast path used to pick whichever
 * edition's directory existed on disk (preferring Pro whenever both were
 * present), regardless of which one WordPress actually had active. A site
 * with Pro installed-but-deactivated and Free active would silently execute
 * Pro's inactive code with Pro's currency cache-key logic, diverging from
 * the active Free REST route. These tests exercise the resolver directly —
 * it only returns directory/edition info, it never requires any file — so
 * both a Free-active and a Pro-active scenario can be asserted in the same
 * process without the two editions' identically-named classes colliding.
 */
final class MuEditionResolutionTest extends TestCase {

	private const FREE_BASENAME = 'turbo-search-for-woocommerce/turbo-search-for-woocommerce.php';
	private const PRO_BASENAME  = 'turbo-search-for-woocommerce-pro/turbo-search-for-woocommerce.php';

	protected function setUp(): void {
		wcs_tests_reset();
		require_once WCS_PLUGIN_DIR . 'mu-plugin/wcs-cache-bypass.php';
	}

	public function test_free_active_only_resolves_to_the_free_directory(): void {
		$GLOBALS['wcs_test_active_plugins'] = array( self::FREE_BASENAME );

		$edition = wcs_mu_resolve_active_edition();

		$this->assertNotNull( $edition );
		$this->assertFalse( $edition['is_pro'] );
		$this->assertSame( WP_PLUGIN_DIR . '/turbo-search-for-woocommerce', $edition['dir'] );
	}

	public function test_pro_active_only_resolves_to_the_pro_directory(): void {
		// Pro need not actually exist on disk for the resolver itself — it
		// only reports where Pro's files *would* be; the caller separately
		// checks file_exists() before requiring anything (see
		// test_selected_edition_missing_its_files_skips_fast_path_without_fatal).
		$GLOBALS['wcs_test_active_plugins'] = array( self::PRO_BASENAME );

		$edition = wcs_mu_resolve_active_edition();

		$this->assertNotNull( $edition );
		$this->assertTrue( $edition['is_pro'] );
		$this->assertSame( WP_PLUGIN_DIR . '/turbo-search-for-woocommerce-pro', $edition['dir'] );
	}

	public function test_both_editions_active_is_unresolved(): void {
		// A narrow same-request window the mutual-exclusion guard's deferred
		// 'shutdown' deactivation hasn't closed yet — must never guess.
		$GLOBALS['wcs_test_active_plugins'] = array( self::FREE_BASENAME, self::PRO_BASENAME );

		$this->assertNull( wcs_mu_resolve_active_edition() );
	}

	public function test_neither_edition_active_is_unresolved(): void {
		$GLOBALS['wcs_test_active_plugins'] = array( 'some-other-plugin/some-other-plugin.php' );

		$this->assertNull( wcs_mu_resolve_active_edition() );
	}

	public function test_network_active_free_on_multisite_resolves_to_free(): void {
		$GLOBALS['wcs_test_is_multisite']  = true;
		$GLOBALS['wcs_test_active_plugins'] = array(); // not site-active
		$GLOBALS['wcs_test_site_options']   = array(
			'active_sitewide_plugins' => array( self::FREE_BASENAME => time() ),
		);

		$edition = wcs_mu_resolve_active_edition();

		$this->assertNotNull( $edition );
		$this->assertFalse( $edition['is_pro'] );
	}

	public function test_network_active_pro_on_multisite_resolves_to_pro(): void {
		$GLOBALS['wcs_test_is_multisite']  = true;
		$GLOBALS['wcs_test_active_plugins'] = array();
		$GLOBALS['wcs_test_site_options']   = array(
			'active_sitewide_plugins' => array( self::PRO_BASENAME => time() ),
		);

		$edition = wcs_mu_resolve_active_edition();

		$this->assertNotNull( $edition );
		$this->assertTrue( $edition['is_pro'] );
	}

	public function test_network_option_is_ignored_when_not_multisite(): void {
		// Guards against ever trusting active_sitewide_plugins on a single-site
		// install, where it has no meaning.
		$GLOBALS['wcs_test_is_multisite']  = false;
		$GLOBALS['wcs_test_active_plugins'] = array();
		$GLOBALS['wcs_test_site_options']   = array(
			'active_sitewide_plugins' => array( self::FREE_BASENAME => time() ),
		);

		$this->assertNull( wcs_mu_resolve_active_edition() );
	}

	// ── End-to-end via the real intercept ────────────────────────────────────

	public function test_neither_active_skips_the_fast_path_even_though_frees_own_files_exist_on_disk(): void {
		// Proves there is no directory-scan fallback: Free's real files exist
		// at WP_PLUGIN_DIR/turbo-search-for-woocommerce (the test symlink), but
		// with nothing active, the fast path must still skip entirely.
		$GLOBALS['wcs_test_active_plugins'] = array();
		$_SERVER['REQUEST_URI']             = '/wp-json/wcs/v1/search';
		$_GET                                = array( 'q' => 'lamp', '_wpnonce' => 'test-nonce' );

		wcs_cache_bypass_intercept();

		$this->assertSame( array(), $GLOBALS['wcs_test_transients']['reads'] ?? array(), 'no transient lookup means the fast path never engaged' );
	}

	public function test_selected_edition_missing_its_files_skips_fast_path_without_fatal(): void {
		// Pro is "active" per WordPress's own state, but no
		// turbo-search-for-woocommerce-pro directory exists in this test
		// environment (Pro is a separate sibling repo) — the intercept must
		// return quietly rather than fatal on the missing require target.
		$GLOBALS['wcs_test_active_plugins'] = array( self::PRO_BASENAME );
		$_SERVER['REQUEST_URI']             = '/wp-json/wcs/v1/search';
		$_GET                                = array( 'q' => 'lamp', '_wpnonce' => 'test-nonce' );

		wcs_cache_bypass_intercept(); // must not throw/fatal

		$this->assertSame( array(), $GLOBALS['wcs_test_transients']['reads'] ?? array() );
	}
}
