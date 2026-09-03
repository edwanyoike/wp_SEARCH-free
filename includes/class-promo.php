<?php
declare(strict_types=1);

/**
 * Fetches the optional promo banner from the OzLS license server
 * (GET /v1/promo) and caches it locally. This edition has no license/session
 * concept at all, so this is a plain wp_remote_get() — no SDK involved.
 *
 * Fails open everywhere: any network error, non-200, or malformed response
 * is treated as "no promo" and cached briefly so a server outage can't cause
 * repeated requests on every admin page load.
 *
 * @package WP_Fast_Search
 */

namespace WCS\Search;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Promo {

	const ENDPOINT  = 'https://ozupay.com/wp-json/ozls/v1/promo';
	const CACHE_KEY = 'wcs_promo_cache';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;
	const FAIL_TTL  = HOUR_IN_SECONDS;
	// Distinct from Pro's 'turbo_search_pro' — lets the server target promos
	// (e.g. an upgrade-to-Pro upsell) at only one edition or the other.
	const PLUGIN_SLUG = 'turbo_search_free';

	/**
	 * Hostnames the remote promo's link_url is allowed to point at. The
	 * message/link content is server-controlled, so this bounds it to
	 * OzuLabs' own properties rather than trusting the endpoint to never be
	 * compromised or repurposed for third-party advertising.
	 */
	const ALLOWED_LINK_HOSTS = array( 'ozulabs.com', 'ozupay.com' );

	/**
	 * Query-string keys that mark a URL as carrying referral/affiliate/click
	 * tracking rather than a plain page link.
	 */
	const TRACKING_PARAM_PREFIXES = array( 'utm_', 'ref', 'affiliate', 'click_id', 'fbclid', 'gclid' );

	/**
	 * Validate a remotely-supplied link_url against the first-party
	 * hostname allow-list and reject any tracking query parameters.
	 *
	 * @param string $url Raw link_url from the promo payload.
	 * @return string The URL if it passes, or '' if it doesn't (never linked).
	 */
	private static function sanitize_link_url( string $url ): string {
		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) || empty( $parts['scheme'] ) || 'https' !== $parts['scheme'] ) {
			return '';
		}

		$host = strtolower( $parts['host'] );
		if ( ! in_array( $host, self::ALLOWED_LINK_HOSTS, true ) ) {
			return '';
		}

		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query_args );
			foreach ( array_keys( $query_args ) as $key ) {
				$key = strtolower( $key );
				foreach ( self::TRACKING_PARAM_PREFIXES as $prefix ) {
					if ( str_starts_with( $key, $prefix ) ) {
						return '';
					}
				}
			}
		}

		return esc_url_raw( $url );
	}

	/**
	 * Current promo payload, or null if none is active. Cached in a
	 * transient; fetches at most once per CACHE_TTL (or FAIL_TTL after a
	 * failure) regardless of how many admin pages are viewed in between.
	 *
	 * @return array{dismiss_id:string,message:string,link_url:string,link_text:string}|null
	 */
	public static function get(): ?array {
		try {
			// get_transient() returns `false` for BOTH "never cached" and "value
			// IS false" — so the cached payload always stores an array (with
			// 'active' => false for "no promo") rather than storing `false`
			// directly, which would be indistinguishable from a cache miss.
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return ! empty( $cached['active'] ) ? $cached : null;
			}

			$response = wp_remote_get( add_query_arg( array(
				'plugin_slug' => self::PLUGIN_SLUG,
			), self::ENDPOINT ), array( 'timeout' => 5 ) );

			if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
				set_transient( self::CACHE_KEY, array( 'active' => false ), self::FAIL_TTL );
				return null;
			}

			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $body ) || empty( $body['active'] ) ) {
				set_transient( self::CACHE_KEY, array( 'active' => false ), self::CACHE_TTL );
				return null;
			}

			$promo = array(
				'active'     => true,
				'dismiss_id' => sanitize_key( (string) ( $body['dismiss_id'] ?? '' ) ),
				'message'    => (string) ( $body['message'] ?? '' ),
				'link_url'   => self::sanitize_link_url( (string) ( $body['link_url'] ?? '' ) ),
				'link_text'  => (string) ( $body['link_text'] ?? '' ),
			);

			if ( '' === $promo['dismiss_id'] || '' === $promo['message'] ) {
				set_transient( self::CACHE_KEY, array( 'active' => false ), self::CACHE_TTL );
				return null;
			}

			set_transient( self::CACHE_KEY, $promo, self::CACHE_TTL );
			return $promo;
		} catch ( \Throwable $e ) {
			// Must never break admin page render over a promo banner.
			return null;
		}
	}
}
