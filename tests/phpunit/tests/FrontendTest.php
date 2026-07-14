<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WCS\Search\Frontend;

final class FrontendTest extends TestCase {

	protected function setUp(): void {
		wcs_tests_reset();
		update_option( 'woocommerce_currency', 'USD' );
	}

	// ── enqueue_assets ───────────────────────────────────────────────────────

	public function test_assets_are_enqueued_with_inline_config(): void {
		update_option( 'wcs_min_chars', 4 );

		Frontend::enqueue_assets();

		$this->assertContains( 'wcs-search-css', $GLOBALS['wcs_test_enqueued']['style'] );
		$this->assertContains( 'wcs-search-js', $GLOBALS['wcs_test_enqueued']['script'] );

		$inline = $GLOBALS['wcs_test_inline_js']['wcs-search-js'][0];
		$this->assertStringStartsWith( 'const wcs_config = ', $inline );

		$config = json_decode( substr( $inline, strlen( 'const wcs_config = ' ), -1 ), true );
		$this->assertSame( 4, $config['min_chars'] );
		$this->assertSame( 'USD', $config['currency']['code'] );
		$this->assertArrayHasKey( 'index_building', $config['i18n'] );
		$this->assertStringContainsString( '/wcs/v1/search', $config['api_url'] );
		$this->assertNotEmpty( $config['nonce'] );
	}

	public function test_i18n_strings_are_not_html_escaped(): void {
		// Regression: these strings are JSON-encoded and rendered via
		// .textContent in search.js, which does not decode HTML entities.
		// esc_html__() would leave a literal "&quot;" visible in the dropdown
		// instead of a real double-quote — exactly what shipped in 'view_all'
		// before this test existed. Plain __() must be used for every string
		// here, not just this one, since any of them could later gain a
		// quote/ampersand/angle-bracket and silently reproduce the bug.
		Frontend::enqueue_assets();

		$inline = $GLOBALS['wcs_test_inline_js']['wcs-search-js'][0];
		$config = json_decode( substr( $inline, strlen( 'const wcs_config = ' ), -1 ), true );

		$this->assertSame( 'View all results for "%s"', $config['i18n']['view_all'] );
		$this->assertStringNotContainsString( '&quot;', $config['i18n']['view_all'] );

		$this->assertSame( 'Showing results for "%s"', $config['i18n']['showingResultsFor'] );
		$this->assertStringNotContainsString( '&quot;', $config['i18n']['showingResultsFor'] );

		foreach ( $config['i18n'] as $key => $string ) {
			$this->assertStringNotContainsString( '&amp;', $string, "i18n.$key must not be HTML-entity-escaped" );
			$this->assertStringNotContainsString( '&#039;', $string, "i18n.$key must not be HTML-entity-escaped" );
		}
	}

	// ── ajax_refresh_nonce ───────────────────────────────────────────────────

	public function test_nonce_refresh_returns_fresh_nonce(): void {
		try {
			Frontend::ajax_refresh_nonce();
			$this->fail( 'expected JSON response' );
		} catch ( WCS_Test_JSON_Response $r ) {
			$this->assertTrue( $r->success );
			$this->assertSame( 'nonce-wp_rest', $r->payload['nonce'] );
		}
	}

	public function test_nonce_refresh_is_rate_limited_to_ten_per_minute(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			try {
				Frontend::ajax_refresh_nonce();
			} catch ( WCS_Test_JSON_Response $r ) {
				$this->assertTrue( $r->success, "request $i should pass" );
			}
		}
		try {
			Frontend::ajax_refresh_nonce();
			$this->fail( 'expected JSON response' );
		} catch ( WCS_Test_JSON_Response $r ) {
			$this->assertFalse( $r->success );
			$this->assertSame( 429, $r->status );
		}
	}

	// ── [turbo_search] shortcode ─────────────────────────────────────────────

	public function test_shortcode_renders_product_search_form(): void {
		$html = Frontend::render_shortcode( array() );

		$this->assertStringContainsString( 'role="search"', $html );
		$this->assertStringContainsString( 'name="s"', $html );
		$this->assertStringContainsString( 'name="post_type" value="product"', $html );
		$this->assertStringContainsString( 'class="wcs-form-wrap"', $html );
	}

	public function test_shortcode_attributes_are_applied_and_escaped(): void {
		$html = Frontend::render_shortcode( array(
			'placeholder' => 'Find "it"…',
			'class'       => 'my wrap<script>',
		) );

		$this->assertStringContainsString( 'placeholder="Find &quot;it&quot;…"', $html );
		// sanitize_html_class strips spaces and markup from the extra class.
		$this->assertStringContainsString( 'class="wcs-form-wrap mywrapscript"', $html );
	}

	public function test_shortcode_enqueues_assets_when_missing(): void {
		Frontend::render_shortcode( array() );
		$this->assertContains( 'wcs-search-js', $GLOBALS['wcs_test_enqueued']['script'] );
	}

	// ── dropdown portal ──────────────────────────────────────────────────────

	public function test_dropdown_portal_is_injected(): void {
		ob_start();
		Frontend::inject_dropdown_container();
		$this->assertSame( '<div id="wcs-dropdown-portal"></div>', ob_get_clean() );
	}
}
