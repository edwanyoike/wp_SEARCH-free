<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WCS\Search\Query_Normalizer;

/**
 * REST ↔ MU-plugin cache-key parity.
 *
 * Executes the real MU intercept (mu-plugin/wcs-cache-bypass.php) with
 * fabricated request state and captures the transient key it looks up. That
 * key must equal the one the REST handler would write for the same input —
 * a drift here silently disables the fast path on every request (this
 * exact bug shipped once, as a double-unslash in the MU plugin).
 */
final class CacheKeyParityTest extends TestCase {

	protected function setUp(): void {
		wcs_tests_reset();
		require_once WCS_PLUGIN_DIR . 'mu-plugin/wcs-cache-bypass.php';

		update_option( 'woocommerce_currency', 'USD' );
		update_option( 'wcs_cache_version', 3 );

		$_SERVER['REQUEST_URI'] = '/wp-json/wcs/v1/search';
		$_GET                   = array( '_wpnonce' => 'test-nonce' );
		$_COOKIE                = array();
	}

	protected function tearDown(): void {
		$_GET    = array();
		$_COOKIE = array();
		unset( $_SERVER['REQUEST_URI'] );
	}

	/** Run the MU intercept and return the transient key it looked up (or null). */
	private function interceptedKey(): ?string {
		wcs_cache_bypass_intercept();
		$reads = $GLOBALS['wcs_test_transients']['reads'] ?? array();
		return $reads ? end( $reads ) : null;
	}

	/** The key the REST handler computes for the same raw (slashed) input. */
	private function restKey( string $raw_query, string $currency ): string {
		// REST params arrive unslashed; the route's sanitize_callback applies
		// sanitize_text_field before handle_request normalizes.
		$normalized = Query_Normalizer::normalize( sanitize_text_field( wp_unslash( $raw_query ) ) );
		return Query_Normalizer::cache_key( $normalized, $currency, 3 );
	}

	public function test_plain_query_produces_identical_keys(): void {
		$_GET['q'] = 'hazina lamp';
		$this->assertSame( $this->restKey( 'hazina lamp', 'USD' ), $this->interceptedKey() );
	}

	public function test_quotes_and_slashes_produce_identical_keys(): void {
		// Simulate PHP magic-quoting as WP does for $_GET: slashes added.
		$_GET['q'] = addslashes( "Men's T-Shirt" );
		$this->assertSame( $this->restKey( addslashes( "Men's T-Shirt" ), 'USD' ), $this->interceptedKey() );
	}

	public function test_hyphenated_sku_produces_identical_keys(): void {
		$_GET['q'] = 'ABC-123';
		$key       = $this->interceptedKey();
		$this->assertSame( $this->restKey( 'ABC-123', 'USD' ), $key );
		// And the normalized form is the tokenized one.
		$this->assertStringContainsString( md5( 'abc 123' ), (string) $key );
	}

	public function test_unknown_currency_falls_back_to_default_on_both_paths(): void {
		$_GET['q']        = 'lamp';
		$_GET['currency'] = 'ZZZ'; // Not configured by any switcher.
		$key              = $this->interceptedKey();
		$this->assertStringContainsString( '_USD_', (string) $key );
	}

	public function test_configured_currency_is_used_in_the_key(): void {
		update_option( 'woocs_currencies', array( 'EUR' => array( 'rate' => 1.1 ) ) );
		$_GET['q']        = 'lamp';
		$_GET['currency'] = 'EUR';
		$key              = $this->interceptedKey();
		$this->assertStringContainsString( '_EUR_', (string) $key );
		$this->assertSame( Query_Normalizer::cache_key( 'lamp', 'EUR', 3 ), $key );
	}

	public function test_cookie_sourced_currency_is_validated_against_known_list(): void {
		$_GET['q']                          = 'lamp';
		$_COOKIE['woocs_current_currency']  = 'XXX'; // fabricated, not configured
		$key                                = $this->interceptedKey();
		$this->assertStringContainsString( '_USD_', (string) $key );
	}

	public function test_invalid_nonce_skips_the_fast_path_entirely(): void {
		$GLOBALS['wcs_test_nonce_valid'] = false;
		$_GET['q']                       = 'lamp';
		$this->assertNull( $this->interceptedKey() );
	}

	public function test_non_search_request_is_untouched(): void {
		$_SERVER['REQUEST_URI'] = '/wp-json/wp/v2/posts';
		$_GET['q']              = 'lamp';
		$this->assertNull( $this->interceptedKey() );
	}
}
