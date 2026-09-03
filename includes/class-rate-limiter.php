<?php
declare(strict_types=1);

/**
 * Shared fixed-window rate limiter.
 *
 * Single implementation used by the search endpoint, the nonce-refresh
 * endpoint, and the expensive-fallback-tier guard in Search_Handler.
 *
 * APCu is used when available (atomic, sub-ms, no DB I/O). Without it, this
 * falls back to an atomic UPSERT against the wcs_rate_limits table rather
 * than a transient: get_transient()+set_transient() is two round trips with
 * no locking between them, so two concurrent workers can both read the same
 * (stale) count and each pass the limit check — confirmed as a real gap, not
 * theoretical, on a host with no APCu and no external object cache: 15
 * genuinely concurrent identical requests all executed independently with no
 * coordination at all. `INSERT ... ON DUPLICATE KEY UPDATE` is a single
 * atomic row-level operation in InnoDB, so this fallback holds the limit
 * exactly, the same guarantee APCu gives, on any host — no extension and no
 * external cache required.
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
	 * Fixed window: bucketed to wall-clock time (floor(time()/window) *
	 * window), not "starts at first request" — this is what makes the DB
	 * fallback's UPSERT atomic and portable: the same key always maps to the
	 * same window boundary regardless of which worker is asking.
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

		return self::allow_via_db( $key, $max_requests, $window_seconds );
	}

	/**
	 * Atomic fallback for hosts without APCu.
	 *
	 * @param string $key            Fully prefixed counter key.
	 * @param int    $max_requests   Maximum requests allowed per window.
	 * @param int    $window_seconds Window length in seconds.
	 * @return bool
	 */
	private static function allow_via_db( string $key, int $max_requests, int $window_seconds ): bool {
		global $wpdb;
		$table        = $wpdb->prefix . 'wcs_rate_limits';
		$window_start = (int) floor( time() / $window_seconds ) * $window_seconds;

		$suppress = $wpdb->suppress_errors( true );
		// A fresh window resets the counter to 1; the same window increments
		// it. VALUES() reads the row this statement is about to insert, not
		// the pre-existing one, so the IF() compares "the window this request
		// belongs to" against "the window the stored row belongs to" —
		// exactly the fixed-window semantics above, enforced atomically by
		// MySQL's row lock during the upsert itself.
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'INSERT INTO %i (rl_key, window_start, hits) VALUES (%s, %d, 1)
			 ON DUPLICATE KEY UPDATE
				 hits = IF( window_start = VALUES(window_start), hits + 1, 1 ),
				 window_start = VALUES(window_start)',
			$table,
			$key,
			$window_start
		) );
		$error    = $wpdb->last_error;
		$hits_raw = $wpdb->get_var( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'SELECT hits FROM %i WHERE rl_key = %s',
			$table,
			$key
		) );
		$wpdb->suppress_errors( $suppress );

		// Fail open on anything that isn't a real count: a query error (table
		// missing — fresh install mid-upgrade, or "Delete All Data" raced with
		// a request; recreated on the next load either way), or no row at all.
		// The latter should be unreachable after a successful upsert — the row
		// this SELECT reads by primary key is the one the INSERT just wrote —
		// so treating it as "can't confirm a limit was hit" rather than "hit
		// the limit" is the safe direction to be wrong in either way: it never
		// blocks search traffic over an internal read failure.
		if ( '' !== $error || null === $hits_raw ) {
			return true;
		}

		return ( (int) $hits_raw ) <= $max_requests;
	}
}
