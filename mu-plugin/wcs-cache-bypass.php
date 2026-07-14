<?php
/*
 * Turbo Search for WooCommerce Cache Bypass
 *
 * Description: Must-Use (MU) plugin companion for Turbo Search for WooCommerce. Intercepts search REST API queries early to bypass the standard WordPress boot process when a cache hit is available.
 * Version:     1.5.0
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

	// ── 3. Normalize via the shared Query_Normalizer — the exact same code
	// path Search_Handler uses, so both sides always compute identical cache
	// keys. If the main plugin is missing (deleted while this MU file
	// survived), skip the fast path entirely and let the REST route 404.
	// $raw_query is already unslashed above — do not unslash twice; queries
	// containing quotes/backslashes would diverge from the REST key.
	$normalizer = WP_PLUGIN_DIR . '/turbo-search-for-woocommerce/includes/class-query-normalizer.php';
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

	// ── 5. Build cache key (identical logic to Search_Handler) ───────────────
	$default_currency = get_option( 'woocommerce_currency', 'USD' );

	// Start from the store default so the variable is always defined, even
	// when neither a GET param nor any switcher cookie is present.
	$currency           = $default_currency;
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

	// ── 6. APCu L1 cache (shared server RAM, ~0.01 ms, no I/O) ──────────────
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

	// ── 7. Transient L2 cache ─────────────────────────────────────────────────
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

	// ── 8. Short-circuit: send cached JSON and exit ───────────────────────────
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
