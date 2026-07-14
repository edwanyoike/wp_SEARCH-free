<?php
declare(strict_types=1);

/**
 * Namespaced shadows of PHP built-ins.
 *
 * Plugin classes live in WCS\Search and call these functions unqualified, so
 * PHP resolves them here first — letting tests observe (and skip) real sleeps
 * in the cache-stampede poller without patching production code.
 */

namespace WCS\Search;

function usleep( int $microseconds ): void {
	$GLOBALS['wcs_test_usleeps'][] = $microseconds;
}

/**
 * Deterministic overrides for dynamic_batch_size()'s inputs. Each falls
 * through to the real global-namespace function when the corresponding test
 * global isn't set, so most tests are unaffected and only ones that
 * specifically opt in (via wcs_test_loadavg / wcs_test_memory_usage /
 * wcs_test_memory_limit) get controlled values.
 */
function sys_getloadavg() {
	if ( array_key_exists( 'wcs_test_loadavg', $GLOBALS ) ) {
		return $GLOBALS['wcs_test_loadavg'];
	}
	return \sys_getloadavg();
}

function memory_get_usage( bool $real_usage = false ) {
	if ( array_key_exists( 'wcs_test_memory_usage', $GLOBALS ) ) {
		return $GLOBALS['wcs_test_memory_usage'];
	}
	return \memory_get_usage( $real_usage );
}

function ini_get( string $option ) {
	if ( 'memory_limit' === $option && array_key_exists( 'wcs_test_memory_limit', $GLOBALS ) ) {
		return $GLOBALS['wcs_test_memory_limit'];
	}
	return \ini_get( $option );
}
