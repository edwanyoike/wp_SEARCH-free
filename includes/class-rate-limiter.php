<?php
declare(strict_types=1);

/**
 * Shared fixed-window rate limiter.
 *
 * Single implementation used by the search endpoint (60 req/min) and the
 * nonce-refresh endpoint (10 req/min) — previously duplicated in both.
 *
 * APCu is used when available (atomic, sub-ms, no DB I/O). Transients are
 * the fallback on servers without APCu. Note: get_transient + set_transient
 * is non-atomic on MySQL-backed transients — two concurrent workers can both
 * read the same count and each pass the limit check, allowing ~2× the
 * intended rate in the worst case. This is an accepted approximation; use
 * APCu or Redis for a hard rate limit.
 *
 * @package WP_Fast_Search
 */

namespace WCS\Search;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Rate_Limiter {

	/**
	 * Record one hit against $key and report whether it is within the limit.
	 *
	 * Fixed window: the counter's TTL starts at the first request and is not
	 * extended by later hits.
	 *
	 * @param string $key            Fully prefixed counter key (caller includes any IP hash).
	 * @param int    $max_requests   Maximum requests allowed per window.
	 * @param int    $window_seconds Window length in seconds.
	 * @return bool True when this request is allowed, false when over the limit.
	 */
	public static function allow( string $key, int $max_requests, int $window_seconds ): bool {
		if ( function_exists( 'apcu_inc' ) ) {
			$success = false;
			$count   = apcu_inc( $key, 1, $success );
			if ( ! $success ) {
				apcu_store( $key, 1, $window_seconds );
				$count = 1;
			}
			return $count <= $max_requests;
		}

		$count = (int) get_transient( $key );
		if ( $count >= $max_requests ) {
			return false;
		}
		set_transient( $key, $count + 1, $window_seconds );
		return true;
	}
}
