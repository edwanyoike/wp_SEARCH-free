<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WCS\Search\Rate_Limiter;

/**
 * Exercises the DB fallback path (APCu is not loaded in the test CLI,
 * matching hosts without the extension) — the atomic UPSERT against
 * wcs_rate_limits that replaced the old non-atomic transient pair.
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

	public function test_denied_requests_keep_incrementing_the_counter(): void {
		// Unlike the old transient pair (which stopped writing once denied,
		// specifically to avoid extending that key's TTL), the atomic UPSERT
		// always increments in one statement — a read-then-conditionally-write
		// would reintroduce the exact race this replaced the transients to
		// avoid. This is harmless here: the window is bucketed to wall-clock
		// time (see test_window_start_is_bucketed_to_wall_clock_time), not
		// extended by writes, so a flood of denied requests can never keep a
		// window open longer than it would have run anyway.
		Rate_Limiter::allow( 'k', 1, 60 );
		Rate_Limiter::allow( 'k', 1, 60 ); // denied
		Rate_Limiter::allow( 'k', 1, 60 ); // denied
		$this->assertSame( 3, $GLOBALS['wcs_test_rate_limits']['k']['hits'] );
	}

	public function test_window_start_is_bucketed_to_wall_clock_time(): void {
		// Fixed-window semantics: the bucket is floor(time()/window)*window,
		// not "starts at the first request" — this is what makes the atomic
		// UPSERT correct: every concurrent caller for the same key computes
		// the identical window boundary independently, with no coordination.
		Rate_Limiter::allow( 'k', 10, 100 );
		$expected = (int) floor( time() / 100 ) * 100;
		$this->assertSame( $expected, $GLOBALS['wcs_test_rate_limits']['k']['window_start'] );
	}

	public function test_a_new_window_resets_the_counter(): void {
		$GLOBALS['wcs_test_rate_limits']['k'] = array( 'window_start' => 0, 'hits' => 999 );
		Rate_Limiter::allow( 'k', 10, 60 );
		$this->assertSame( 1, $GLOBALS['wcs_test_rate_limits']['k']['hits'] );
	}

	// ── resolved_search_limit() ───────────────────────────────────────────────
	//
	// Single source of truth for both Search_Handler::check_permissions()
	// (the REST route) and the MU cache-bypass fast path — a regression here
	// would silently drift the two paths onto different effective limits
	// again, the exact bug this method was added to close.

	public function test_resolved_search_limit_defaults_to_sixty_per_sixty_seconds(): void {
		$this->assertSame( array( 60, 60 ), Rate_Limiter::resolved_search_limit() );
	}

	public function test_resolved_search_limit_reads_configured_options(): void {
		update_option( 'wcs_rate_limit_requests', 5 );
		update_option( 'wcs_rate_limit_window', 3600 );

		$this->assertSame( array( 5, 3600 ), Rate_Limiter::resolved_search_limit() );
	}

	public function test_resolved_search_limit_clamps_to_a_minimum_of_one(): void {
		// A blank/zero submission must never silently disable rate limiting
		// (max_requests=0 would make Rate_Limiter::allow() always deny, and a
		// window of 0 would divide by zero in the window-bucket calculation).
		update_option( 'wcs_rate_limit_requests', 0 );
		update_option( 'wcs_rate_limit_window', -5 );

		$this->assertSame( array( 1, 1 ), Rate_Limiter::resolved_search_limit() );
	}

	public function test_fails_open_when_the_table_is_missing(): void {
		// A missing wcs_rate_limits table (fresh install mid-upgrade, or
		// "Delete All Data" racing a request) must never block search traffic —
		// it's a temporary gap, not a reason to 403 every visitor.
		global $wpdb;
		$wpdb->handler = static function ( string $sql, string $type ) use ( $wpdb ) {
			if ( 'query' === $type && str_contains( $sql, 'wcs_rate_limits' ) ) {
				$wpdb->last_error = "Table 'wp_wcs_rate_limits' doesn't exist";
				return false;
			}
			return null;
		};

		$this->assertTrue( Rate_Limiter::allow( 'k', 1, 60 ) );
		$this->assertTrue( Rate_Limiter::allow( 'k', 1, 60 ), 'still allowed — the missing table never lets a real count accumulate' );
	}
}
