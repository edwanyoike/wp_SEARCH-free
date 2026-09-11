<?php
/*
 * Turbo Search for WooCommerce Cache Bypass
 *
 * Description: Must-Use (MU) plugin companion for Turbo Search for WooCommerce. Intercepts search REST API queries early to bypass the standard WordPress boot process when a cache hit is available.
 * Version:     1.11.14
 * Author:      Ozulabs
 * Author URI:  https://ozulabs.com
 * License:     GPLv2 or later
 * Text Domain: ozulabs-turbo-search-for-woocommerce
 */


declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether $basename is active, site-wide or network-wide — without loading
 * wp-admin/includes/plugin.php. WordPress core's own is_plugin_active() does
 * exactly these two option reads internally; this file runs at
 * plugins_loaded -10 on every front-end search request, so it reimplements
 * that check against already-loaded option data rather than pulling in an
 * admin-only file for it.
 */
function otsw_mu_is_plugin_active( string $basename ): bool {
	if ( in_array( $basename, (array) get_option( 'active_plugins', array() ), true ) ) {
		return true;
	}
	return is_multisite() && array_key_exists( $basename, (array) get_site_option( 'active_sitewide_plugins', array() ) );
}

/**
 * Resolve which edition is active and where its files live — WordPress's own
 * active-plugin state only, never directory/file existence. It is entirely
 * normal for a site to have both editions' directories present with only one
 * truly active (Pro purchased then deactivated for troubleshooting, a
 * leftover install, etc.); selecting whichever edition merely HAS a
 * directory on disk could execute an inactive plugin's code, diverge its
 * cache-key logic from the REST route the active edition actually
 * dispatches, or run either edition against a mismatched database schema.
 *
 * Returns null when the state can't be resolved unambiguously — neither
 * edition active, or (a narrow same-request window before the mutual-
 * exclusion guard's deferred 'shutdown' deactivation runs) both somehow
 * active — in which case the caller must skip the fast path and let
 * WordPress dispatch the normal REST route, which uses core's own
 * already-correct resolution.
 *
 * @return array{dir: string, is_pro: bool}|null
 */
function otsw_mu_resolve_active_edition(): ?array {
	$free_active = otsw_mu_is_plugin_active( 'ozulabs-turbo-search-for-woocommerce/turbo-search-for-woocommerce.php' );
	$pro_active  = otsw_mu_is_plugin_active( 'turbo-search-for-woocommerce-pro/turbo-search-for-woocommerce.php' );
	if ( $free_active === $pro_active ) {
		return null;
	}
	return array(
		'dir'    => WP_PLUGIN_DIR . '/' . ( $pro_active ? 'turbo-search-for-woocommerce-pro' : 'ozulabs-turbo-search-for-woocommerce' ),
		'is_pro' => $pro_active,
	);
}

/**
 * Early intercept — runs at plugins_loaded priority -10, before any other
 * plugin (including WooCommerce) has a chance to execute.
 */
function otsw_cache_bypass_intercept(): void {

	// ── 1. Gate: only handle our specific REST path ───────────────────────────
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- used only in strpos check, not output

	// Must contain the search segment — avoids any overhead on other requests.
	if ( false === strpos( $request_uri, '/otsw/v1/search' ) ) {
		return;
	}

	// ── 2. Require query parameter ────────────────────────────────────────────
	$raw_query = isset( $_GET['q'] ) ? wp_unslash( $_GET['q'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via sanitize_text_field() below
	if ( '' === $raw_query ) {
		return;
	}

	// ── 3. Determine which edition is actually active. wp-content/mu-plugins/
	// is a single, network-wide directory — either edition's own
	// Activator::install_mu_plugin() can be the one that most recently wrote
	// this file, so it cannot assume it's still the active edition just
	// because it's the one physically installed (see
	// otsw_mu_resolve_active_edition()'s own docblock for why this can't be
	// inferred from directory/file existence either). This (Free's) copy
	// only knows how to correctly serve Free's own request — always the
	// store's default currency, with no multi-currency-switcher awareness at
	// all, since that price-conversion logic is a Pro-only feature. If Pro
	// is the one actually active, this file must not try to serve the
	// request itself: guessing at Pro's currency-aware cache key would be
	// wrong and could poison the cache namespace both copies write into.
	// Pro installs and uses its own capable copy of this file when it's
	// active (see wp_search's own Activator::install_mu_plugin()) — bailing
	// here just falls through to the normal REST route, which always uses
	// whichever edition's PHP is actually loaded and is always correct.
	$edition = otsw_mu_resolve_active_edition();
	if ( null === $edition || $edition['is_pro'] ) {
		return;
	}
	$edition_dir = $edition['dir'];

	// The active edition's own files must still exist — e.g. a race where a
	// plugin was just deleted but the active_plugins option hasn't been
	// cleaned up yet. Skip rather than fatal.
	$normalizer = $edition_dir . '/includes/class-query-normalizer.php';
	if ( ! file_exists( $normalizer ) ) {
		return;
	}
	require_once $normalizer;

	$query = \OTSW\Search\Query_Normalizer::normalize( sanitize_text_field( $raw_query ) );

	if ( '' === $query ) {
		return; // Let WP handle the empty-query case via the REST route.
	}

	// ── 4. Nonce validation ───────────────────────────────────────────────────
	// wp_verify_nonce() is available at this stage because pluggable.php has
	// already been loaded.  We verify the standard 'wp_rest' nonce.
	$nonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) );
	if ( '' === $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
		return; // Invalid nonce — fall through to the REST route's 403 handler.
	}

	// ── 5. Rate limiting ──────────────────────────────────────────────────────
	// Search_Handler::check_permissions() applies the same administrator-
	// configured per-IP limit to the real REST route, but this fast path runs
	// before that route is ever dispatched — without a matching check here, a
	// cache-warm query could be flooded with no rate limit applied at all,
	// since the nonce above is the shared, non-secret guest 'wp_rest' nonce
	// and proves nothing about the requester. Same key format (so a request
	// denied here and one denied by the REST route share one counter, not
	// two) and the same bounds, read via Rate_Limiter::resolved_search_limit()
	// so this can never drift back onto a hardcoded default while the REST
	// route honors whatever an administrator configured.
	require_once $edition_dir . '/includes/class-rate-limiter.php';
	$client_ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized on this line
	/** This filter is documented in includes/class-search-handler.php */
	$client_ip                        = (string) apply_filters( 'otsw_get_client_ip', $client_ip );
	[ $otsw_rl_max, $otsw_rl_window ] = \OTSW\Search\Rate_Limiter::resolved_search_limit();
	if ( ! \OTSW\Search\Rate_Limiter::allow( 'otsw_rl_' . md5( $client_ip ), $otsw_rl_max, $otsw_rl_window ) ) {
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		http_response_code( 429 );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wp_json_encode( array(
			'code'    => 'rest_too_many_requests',
			'message' => 'Too many requests.',
			'data'    => array( 'status' => 429 ),
		) );
		exit;
	}

	// ── 6. Build cache key (identical logic to Search_Handler) ───────────────
	// Free always serves prices in the store's default currency — see
	// Search_Handler::handle_request()'s own comment to the same effect.
	// Multi-currency price conversion is a Pro-only feature; this file
	// never reads a currency param or any third-party switcher cookie at
	// all (see the edition bail-out above for why that logic now lives only
	// in Pro's own copy of this file, not here).
	$currency      = get_option( 'woocommerce_currency', 'USD' );
	$cache_version = (int) get_option( 'otsw_cache_version', 1 );
	$cache_key     = \OTSW\Search\Query_Normalizer::cache_key( $query, $currency, $cache_version );

	// Versions before 1.11.12 wrapped cached values as ['__otsw_payload' =>
	// true, 'results' => ..., 'corrected' => ...] so a typo-corrected query
	// (a Pro-only feature this edition never actually produced) could survive
	// a cache hit. This edition no longer writes that shape or emits a
	// corrected-query header at all, but a transient/APCu entry written by
	// the previous version can still be live for up to its remaining 24h TTL
	// right after an upgrade — this just pulls the rows back out of either
	// shape so that entry isn't wastefully treated as a cache miss.
	$unwrap_rows = static function ( $cached ): array {
		if ( is_array( $cached ) && ! empty( $cached['__otsw_payload'] ) ) {
			return (array) ( $cached['results'] ?? array() );
		}
		return is_array( $cached ) ? $cached : array();
	};

	// ── 7. APCu L1 cache (shared server RAM, ~0.01 ms, no I/O) ──────────────
	// APCu is a PHP extension available at any boot stage — no WordPress
	// bootstrap required.  Checking it here before the transient read means
	// the fast path never touches the database at all.
	if ( function_exists( 'apcu_fetch' ) ) {
		$apcu_result = apcu_fetch( $cache_key, $apcu_hit );
		if ( true === $apcu_hit ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Cache-Control: no-store' );
			header( 'X-OTSW-Cache: APCU-HIT' );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo wp_json_encode( $unwrap_rows( $apcu_result ) );
			exit;
		}
	}

	// ── 8. Transient L2 cache ─────────────────────────────────────────────────
	$cached = get_transient( $cache_key );
	if ( false === $cached ) {
		// Cache miss — fall through so the REST handler runs the DB query.
		return;
	}

	// Warm APCu so future requests on this server skip the transient read.
	if ( function_exists( 'apcu_store' ) ) {
		apcu_store( $cache_key, $cached, 300 );
	}

	// ── 9. Short-circuit: send cached JSON and exit ───────────────────────────
	// Emit only the bare-minimum headers needed by the JavaScript client.
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Cache-Control: no-store' );   // Prevent intermediate proxy caching.
	header( 'X-OTSW-Cache: HIT' );          // Useful for debugging / k6 checks.

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo wp_json_encode( $unwrap_rows( $cached ) );
	exit;
}

// Run at priority -10 so we fire before other plugins at plugins_loaded.
add_action( 'plugins_loaded', 'otsw_cache_bypass_intercept', -10 );
