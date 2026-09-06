<?php
/*
 * Turbo Search for WooCommerce Cache Bypass
 *
 * Description: Must-Use (MU) plugin companion for Turbo Search for WooCommerce. Intercepts search REST API queries early to bypass the standard WordPress boot process when a cache hit is available.
 * Version:     1.11.6
 * Author:      Ozulabs
 * Author URI:  https://ozulabs.com
 * License:     GPLv2 or later
 * Text Domain: turbo-search-for-woocommerce
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
function wcs_mu_is_plugin_active( string $basename ): bool {
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
function wcs_mu_resolve_active_edition(): ?array {
	$free_active = wcs_mu_is_plugin_active( 'turbo-search-for-woocommerce/turbo-search-for-woocommerce.php' );
	$pro_active  = wcs_mu_is_plugin_active( 'turbo-search-for-woocommerce-pro/turbo-search-for-woocommerce.php' );
	if ( $free_active === $pro_active ) {
		return null;
	}
	return array(
		'dir'    => WP_PLUGIN_DIR . '/' . ( $pro_active ? 'turbo-search-for-woocommerce-pro' : 'turbo-search-for-woocommerce' ),
		'is_pro' => $pro_active,
	);
}

/**
 * Early intercept — runs at plugins_loaded priority -10, before any other
 * plugin (including WooCommerce) has a chance to execute.
 */
function wcs_cache_bypass_intercept(): void {

	// ── 1. Gate: only handle our specific REST path ───────────────────────────
	$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- used only in strpos check, not output

	// Must contain the search segment — avoids any overhead on other requests.
	if ( false === strpos( $request_uri, '/wcs/v1/search' ) ) {
		return;
	}

	// ── 2. Require query parameter ────────────────────────────────────────────
	$raw_query = isset( $_GET['q'] ) ? wp_unslash( $_GET['q'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via sanitize_text_field() below
	if ( '' === $raw_query ) {
		return;
	}

	// ── 3. Determine which edition is actually active. This one MU file is
	// shared byte-for-byte between both editions (each
	// Activator::install_mu_plugin() copies the same source) — see
	// wcs_mu_resolve_active_edition()'s own docblock for why this can't be
	// inferred from directory/file existence.
	$edition = wcs_mu_resolve_active_edition();
	if ( null === $edition ) {
		return;
	}
	$is_pro_edition = $edition['is_pro'];
	$edition_dir    = $edition['dir'];

	// The active edition's own files must still exist — e.g. a race where a
	// plugin was just deleted but the active_plugins option hasn't been
	// cleaned up yet. Skip rather than fatal.
	$normalizer = $edition_dir . '/includes/class-query-normalizer.php';
	if ( ! file_exists( $normalizer ) ) {
		return;
	}
	require_once $normalizer;

	$query = \WCS\Search\Query_Normalizer::normalize( sanitize_text_field( $raw_query ) );

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
	$client_ip                      = (string) apply_filters( 'wcs_get_client_ip', $client_ip );
	[ $wcs_rl_max, $wcs_rl_window ] = \WCS\Search\Rate_Limiter::resolved_search_limit();
	if ( ! \WCS\Search\Rate_Limiter::allow( 'wcs_rl_' . md5( $client_ip ), $wcs_rl_max, $wcs_rl_window ) ) {
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
	$default_currency = get_option( 'woocommerce_currency', 'USD' );

	// Start from the store default so the variable is always defined, even
	// when neither a GET param nor any switcher cookie is present.
	$currency = $default_currency;

	// Multi-currency price conversion is a Pro-only feature — Free's own
	// Search_Handler::handle_request() ignores the `currency` REST param
	// entirely and always serves prices in the store's default currency (see
	// its own comment to that effect). This fast path must compute the exact
	// same currency Free's REST route would, or the two paths silently drift
	// onto different cache keys for any shopper using a currency switcher —
	// the fast path storing under `wcs_v1_<CODE>_<hash>` while the REST route
	// only ever writes `wcs_v1_<default>_<hash>`, so the fast path's cache
	// entry is never read and the "skip WP's boot entirely" optimization this
	// whole file exists for silently stops engaging for those shoppers.
	if ( $is_pro_edition ) {
		$requested_currency = isset( $_GET['currency'] ) ? wp_unslash( $_GET['currency'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via sanitize_text_field() below

		if ( '' !== $requested_currency ) {
			$currency = sanitize_text_field( wp_unslash( $requested_currency ) );
		} else {
			// Common currency switcher cookies — checked in priority order.
			$currency_cookies = array(
				'wmc_current_currency',         // Villatheme / CURCY Multi Currency
				'woocs_current_currency',       // WOOCS (WooCommerce Currency Switcher)
				'woocommerce_current_currency', // Official WooCommerce Multi-Currency
				'_wpml_active_currency',        // WPML / WooCommerce Multilingual
			);

			foreach ( $currency_cookies as $cookie_name ) {
				if ( isset( $_COOKIE[ $cookie_name ] ) ) {
					$currency = sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) );
					break;
				}
			}
		}

		$currency = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', $currency ), 0, 3 ) );
		if ( empty( $currency ) ) {
			$currency = $default_currency;
		}

		// Validate any non-default currency — whether it came from the GET param or
		// a switcher cookie — against the store's configured currency list. This
		// mirrors Search_Handler::get_known_currencies() (including its filter) so
		// both paths always compute the same cache key. Unknown codes fall back to
		// the store default rather than fabricating per-code cache entries.
		if ( $currency !== $default_currency ) {
			$known_currencies = array();

			$wmc = get_option( 'woo_multi_currency_params', array() );
			if ( is_array( $wmc ) && ! empty( $wmc['currency'] ) && is_array( $wmc['currency'] ) ) {
				$known_currencies = array_merge( $known_currencies, $wmc['currency'] );
			}

			$woocs = get_option( 'woocs_currencies', array() );
			if ( is_array( $woocs ) ) {
				$known_currencies = array_merge( $known_currencies, array_keys( $woocs ) );
			}

			$wcml = get_option( 'wcml_exchange_rates', array() );
			if ( is_array( $wcml ) ) {
				$known_currencies = array_merge( $known_currencies, array_keys( $wcml ) );
			}

			/** This filter is documented in includes/class-search-handler.php */
			$known_currencies = (array) apply_filters( 'wcs_known_currencies', $known_currencies );
			$known_currencies = array_map( 'strtoupper', array_filter( $known_currencies, 'is_string' ) );

			if ( ! in_array( $currency, $known_currencies, true ) ) {
				$currency = $default_currency;
			}
		}
	}

	$cache_version = (int) get_option( 'wcs_cache_version', 1 );
	$cache_key     = \WCS\Search\Query_Normalizer::cache_key( $query, $currency, $cache_version );

	// Cached values are wrapped as ['__wcs_payload' => true, 'results' => ...,
	// 'corrected' => ...] by Search_Handler so the corrected query (when typo
	// correction fired) survives a cache hit. A value without the marker key
	// is a plain rows array written by a pre-1.3.30 version of the plugin —
	// still valid for the remainder of its 24h TTL after an upgrade.
	$unwrap = static function ( $cached ): array {
		if ( is_array( $cached ) && ! empty( $cached['__wcs_payload'] ) ) {
			return array( (array) ( $cached['results'] ?? array() ), $cached['corrected'] ?? null );
		}
		return array( is_array( $cached ) ? $cached : array(), null );
	};

	// ── 7. APCu L1 cache (shared server RAM, ~0.01 ms, no I/O) ──────────────
	// APCu is a PHP extension available at any boot stage — no WordPress
	// bootstrap required.  Checking it here before the transient read means
	// the fast path never touches the database at all.
	if ( function_exists( 'apcu_fetch' ) ) {
		$apcu_result = apcu_fetch( $cache_key, $apcu_hit );
		if ( true === $apcu_hit ) {
			list( $rows, $corrected ) = $unwrap( $apcu_result );
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Cache-Control: no-store' );
			header( 'X-WCS-Cache: APCU-HIT' );
			if ( ! empty( $corrected ) ) {
				header( 'X-WCS-Corrected-Query: ' . $corrected );
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo wp_json_encode( $rows );
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

	list( $rows, $corrected ) = $unwrap( $cached );

	// ── 9. Short-circuit: send cached JSON and exit ───────────────────────────
	// Emit only the bare-minimum headers needed by the JavaScript client.
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Cache-Control: no-store' );   // Prevent intermediate proxy caching.
	header( 'X-WCS-Cache: HIT' );          // Useful for debugging / k6 checks.
	if ( ! empty( $corrected ) ) {
		header( 'X-WCS-Corrected-Query: ' . $corrected );
	}

	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	echo wp_json_encode( $rows );
	exit;
}

// Run at priority -10 so we fire before other plugins at plugins_loaded.
add_action( 'plugins_loaded', 'wcs_cache_bypass_intercept', -10 );
