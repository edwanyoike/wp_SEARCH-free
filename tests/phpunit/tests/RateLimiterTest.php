<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WCS\Search\Rate_Limiter;

/**
 * Exercises the transient fallback path (APCu is not loaded in the test CLI,
 * matching hosts without the extension).
 */
final class RateLimiterTest extends TestCase {

	protected function setUp(): void {
		wcs_tests_reset();
	}

	public function test_allows_up_to_max_then_denies(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$this->assertTrue( Rate_Limiter::allow( 'k', 5, 60 ), "request $i should be allowed" );
		}
		$this->assertFalse( Rate_Limiter::allow( 'k', 5, 60 ) );
		$this->assertFalse( Rate_Limiter::allow( 'k', 5, 60 ) );
	}

	public function test_keys_are_independent(): void {
		$this->assertTrue( Rate_Limiter::allow( 'a', 1, 60 ) );
		$this->assertFalse( Rate_Limiter::allow( 'a', 1, 60 ) );
		$this->assertTrue( Rate_Limiter::allow( 'b', 1, 60 ) );
	}

	public function test_denied_request_does_not_extend_the_counter(): void {
		Rate_Limiter::allow( 'k', 1, 60 );
		Rate_Limiter::allow( 'k', 1, 60 ); // denied
		$this->assertSame( 1, $GLOBALS['wcs_test_transients']['data']['k'] );
	}

	public function test_window_is_passed_to_the_transient(): void {
		Rate_Limiter::allow( 'k', 10, 123 );
		$this->assertSame( 123, $GLOBALS['wcs_test_transients']['expiry']['k'] );
	}
}
