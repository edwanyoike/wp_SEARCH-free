<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use OTSW\Search\Frontend;

final class FrontendTest extends TestCase {

	protected function setUp(): void {
		otsw_tests_reset();
		update_option( 'woocommerce_currency', 'USD' );
	}

	// ── enqueue_assets ───────────────────────────────────────────────────────

	public function test_assets_are_enqueued_with_inline_config(): void {
		update_option( 'otsw_min_chars', 4 );

		Frontend::enqueue_assets();

		$this->assertContains( 'otsw-search-css', $GLOBALS['otsw_test_enqueued']['style'] );
		$this->assertContains( 'otsw-search-js', $GLOBALS['otsw_test_enqueued']['script'] );

		$inline = $GLOBALS['otsw_test_inline_js']['otsw-search-js'][0];
		$this->assertStringStartsWith( 'const otsw_config = ', $inline );

		$config = json_decode( substr( $inline, strlen( 'const otsw_config = ' ), -1 ), true );
		$this->assertSame( 4, $config['min_chars'] );
		$this->assertSame( 'USD', $config['currency']['code'] );
		$this->assertArrayHasKey( 'index_building', $config['i18n'] );
		$this->assertStringContainsString( '/otsw/v1/search', $config['api_url'] );
		$this->assertNotEmpty( $config['nonce'] );
	}

	public function test_recent_searches_config_defaults_and_is_configurable(): void {
		Frontend::enqueue_assets();
		$config = json_decode( substr( $GLOBALS['otsw_test_inline_js']['otsw-search-js'][0], strlen( 'const otsw_config = ' ), -1 ), true );
		$this->assertTrue( $config['recent_searches']['enabled'], 'on by default, matching existing behavior before this was configurable' );
		$this->assertSame( 5, $config['recent_searches']['count'] );

		otsw_tests_reset();
		update_option( 'otsw_enable_recent_searches', false );
		update_option( 'otsw_recent_searches_count', 3 );
		Frontend::enqueue_assets();
		$config = json_decode( substr( $GLOBALS['otsw_test_inline_js']['otsw-search-js'][0], strlen( 'const otsw_config = ' ), -1 ), true );
		$this->assertFalse( $config['recent_searches']['enabled'] );
		$this->assertSame( 3, $config['recent_searches']['count'] );
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

		$inline = $GLOBALS['otsw_test_inline_js']['otsw-search-js'][0];
		$config = json_decode( substr( $inline, strlen( 'const otsw_config = ' ), -1 ), true );

		$this->assertSame( 'View all results for "%s"', $config['i18n']['view_all'] );
		$this->assertStringNotContainsString( '&quot;', $config['i18n']['view_all'] );

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
		} catch ( OTSW_Test_JSON_Response $r ) {
			$this->assertTrue( $r->success );
			$this->assertSame( 'nonce-wp_rest', $r->payload['nonce'] );
		}
	}

	public function test_nonce_refresh_is_rate_limited_to_ten_per_minute(): void {
		for ( $i = 0; $i < 10; $i++ ) {
			try {
				Frontend::ajax_refresh_nonce();
			} catch ( OTSW_Test_JSON_Response $r ) {
				$this->assertTrue( $r->success, "request $i should pass" );
			}
		}
		try {
			Frontend::ajax_refresh_nonce();
			$this->fail( 'expected JSON response' );
		} catch ( OTSW_Test_JSON_Response $r ) {
			$this->assertFalse( $r->success );
			$this->assertSame( 429, $r->status );
		}
	}

	// ── [otsw_search] shortcode ───────────────────────────────────────────────

	public function test_legacy_tags_are_registered_as_aliases_of_the_primary_tag(): void {
		Frontend::init();
		$this->assertTrue( shortcode_exists( 'otsw_search' ) );
		$this->assertTrue( shortcode_exists( 'turbo_search' ) );
		$this->assertTrue( shortcode_exists( 'turbo_search_button' ) );
		$this->assertSame(
			$GLOBALS['otsw_test_shortcodes']['otsw_search'],
			$GLOBALS['otsw_test_shortcodes']['turbo_search']
		);
		$this->assertSame(
			$GLOBALS['otsw_test_shortcodes']['otsw_search'],
			$GLOBALS['otsw_test_shortcodes']['turbo_search_button']
		);
	}

	public function test_shortcode_renders_product_search_form(): void {
		$html = Frontend::render_shortcode( array() );

		$this->assertStringContainsString( 'role="search"', $html );
		$this->assertStringContainsString( 'name="s"', $html );
		$this->assertStringContainsString( 'name="post_type" value="product"', $html );
		$this->assertStringContainsString( 'class="otsw-form-wrap"', $html );
	}

	public function test_default_tag_is_the_owner_prefixed_primary_shortcode(): void {
		Frontend::render_shortcode( array() );

		$this->assertSame( array( 'otsw_search' ), $GLOBALS['otsw_test_shortcode_atts_tags'] );
	}

	public function test_each_alias_uses_its_own_attribute_filter_context(): void {
		Frontend::render_shortcode( array(), '', 'turbo_search_button' );

		$this->assertSame( array( 'turbo_search_button' ), $GLOBALS['otsw_test_shortcode_atts_tags'] );
	}

	public function test_shortcode_attributes_are_applied_and_escaped(): void {
		$html = Frontend::render_shortcode( array(
			'placeholder' => 'Find "it"…',
			'class'       => 'my wrap<script>',
		) );

		$this->assertStringContainsString( 'placeholder="Find &quot;it&quot;…"', $html );
		// sanitize_html_class strips spaces and markup from the extra class.
		$this->assertStringContainsString( 'class="otsw-form-wrap mywrapscript"', $html );
	}

	public function test_shortcode_enqueues_assets_when_missing(): void {
		Frontend::render_shortcode( array() );
		$this->assertContains( 'otsw-search-js', $GLOBALS['otsw_test_enqueued']['script'] );
	}

	// ── dropdown portal ──────────────────────────────────────────────────────

	public function test_dropdown_portal_is_injected(): void {
		ob_start();
		Frontend::inject_dropdown_container();
		$this->assertSame( '<div id="otsw-dropdown-portal"></div>', ob_get_clean() );
	}
}
