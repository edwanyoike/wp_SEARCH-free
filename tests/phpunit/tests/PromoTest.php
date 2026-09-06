<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WCS\Search\Promo;

/**
 * Promo::get() — remote fetch, caching, and fail-open behaviour. No real
 * network call: wp_remote_get() is scripted via $GLOBALS['wcs_test_http_response'].
 */
final class PromoTest extends TestCase {

	protected function setUp(): void {
		wcs_tests_reset();
		// Promo::get() is off by default (WordPress.org Guideline 7: no
		// external-server contact without explicit, authorized consent) —
		// every test below except the gate tests themselves is exercising
		// the fetch/cache/sanitize behavior once an admin has opted in.
		update_option( 'wcs_show_promo', true );
	}

	/**
	 * Regression: this is the actual point of the opt-in gate, not
	 * incidental setup for the other tests. Confirmed no HTTP call is even
	 * attempted, and that an already-cached promo from before the setting
	 * was disabled does not keep showing.
	 */
	public function test_disabled_by_default_never_contacts_the_service(): void {
		update_option( 'wcs_show_promo', false );
		$GLOBALS['wcs_test_http_response'] = $this->http_ok( array(
			'active'     => true,
			'dismiss_id' => 'abc123',
			'message'    => 'Hello',
			'link_url'   => '',
			'link_text'  => '',
		) );

		$this->assertNull( Promo::get() );
	}

	public function test_disabling_after_a_promo_was_cached_stops_showing_it(): void {
		update_option( 'wcs_show_promo', true );
		$GLOBALS['wcs_test_http_response'] = $this->http_ok( array(
			'active'     => true,
			'dismiss_id' => 'abc123',
			'message'    => 'Hello',
			'link_url'   => '',
			'link_text'  => '',
		) );
		$this->assertNotNull( Promo::get() ); // fetched and cached while enabled

		update_option( 'wcs_show_promo', false );

		$this->assertNull( Promo::get(), 'turning the setting off must stop showing an already-cached promo, not just stop new fetches' );
	}

	private function http_ok( array $body ): array {
		return array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( $body ),
		);
	}

	public function test_active_promo_is_returned_and_cached(): void {
		$GLOBALS['wcs_test_http_response'] = $this->http_ok( array(
			'active'     => true,
			'dismiss_id' => 'abc123',
			'message'    => 'Hello',
			'link_url'   => 'https://ozulabs.com',
			'link_text'  => 'Learn more',
		) );

		$promo = Promo::get();

		$this->assertNotNull( $promo );
		$this->assertSame( 'abc123', $promo['dismiss_id'] );
		$this->assertSame( 'Hello', $promo['message'] );

		// Second call must not re-fetch — remove the scripted response and
		// confirm the cached value is still served.
		$GLOBALS['wcs_test_http_response'] = null;
		$this->assertSame( $promo, Promo::get() );
	}

	public function test_inactive_response_yields_null_and_is_cached(): void {
		$GLOBALS['wcs_test_http_response'] = $this->http_ok( array( 'active' => false ) );

		$this->assertNull( Promo::get() );

		$GLOBALS['wcs_test_http_response'] = $this->http_ok( array(
			'active' => true, 'dismiss_id' => 'x', 'message' => 'm', 'link_url' => '', 'link_text' => '',
		) );
		// Still null — the "no promo" result itself is cached, so this new
		// scripted response must not be fetched again within the cache window.
		$this->assertNull( Promo::get() );
	}

	public function test_network_error_fails_open_to_null(): void {
		$GLOBALS['wcs_test_http_response'] = new WP_Error( 'http_request_failed', 'timeout' );
		$this->assertNull( Promo::get() );
	}

	public function test_non_200_response_fails_open_to_null(): void {
		$GLOBALS['wcs_test_http_response'] = array( 'response' => array( 'code' => 500 ), 'body' => '' );
		$this->assertNull( Promo::get() );
	}

	public function test_malformed_json_fails_open_to_null(): void {
		$GLOBALS['wcs_test_http_response'] = array( 'response' => array( 'code' => 200 ), 'body' => 'not json' );
		$this->assertNull( Promo::get() );
	}

	public function test_missing_dismiss_id_or_message_fails_open_to_null(): void {
		$GLOBALS['wcs_test_http_response'] = $this->http_ok( array(
			'active' => true, 'dismiss_id' => '', 'message' => 'Hello',
		) );
		$this->assertNull( Promo::get() );
	}

	public function test_link_url_outside_allowed_hosts_is_dropped_not_the_whole_promo(): void {
		$GLOBALS['wcs_test_http_response'] = $this->http_ok( array(
			'active'     => true,
			'dismiss_id' => 'abc123',
			'message'    => 'Hello',
			'link_url'   => 'https://evil.example.com/phish',
			'link_text'  => 'Click me',
		) );

		$promo = Promo::get();

		$this->assertNotNull( $promo, 'an untrusted link_url must not sink the whole promo' );
		$this->assertSame( '', $promo['link_url'] );
	}

	public function test_link_url_with_tracking_param_is_dropped(): void {
		$GLOBALS['wcs_test_http_response'] = $this->http_ok( array(
			'active'     => true,
			'dismiss_id' => 'abc123',
			'message'    => 'Hello',
			'link_url'   => 'https://ozulabs.com/plugins/turbo-search/?utm_source=promo',
			'link_text'  => 'Click me',
		) );

		$promo = Promo::get();

		$this->assertSame( '', $promo['link_url'] );
	}

	public function test_non_https_link_url_is_dropped(): void {
		$GLOBALS['wcs_test_http_response'] = $this->http_ok( array(
			'active'     => true,
			'dismiss_id' => 'abc123',
			'message'    => 'Hello',
			'link_url'   => 'http://ozulabs.com',
			'link_text'  => 'Click me',
		) );

		$promo = Promo::get();

		$this->assertSame( '', $promo['link_url'] );
	}

	public function test_allowed_host_link_url_is_kept(): void {
		$GLOBALS['wcs_test_http_response'] = $this->http_ok( array(
			'active'     => true,
			'dismiss_id' => 'abc123',
			'message'    => 'Hello',
			'link_url'   => 'https://ozulabs.com/plugins/turbo-search/',
			'link_text'  => 'Click me',
		) );

		$promo = Promo::get();

		$this->assertSame( 'https://ozulabs.com/plugins/turbo-search/', $promo['link_url'] );
	}
}
