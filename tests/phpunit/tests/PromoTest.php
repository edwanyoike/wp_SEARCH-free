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
}
