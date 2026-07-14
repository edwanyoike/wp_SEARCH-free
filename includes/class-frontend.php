<?php
declare(strict_types=1);

/**
 * Frontend script/style loading and component injection.
 *
 * @package WP_Fast_Search
 */

namespace WCS\Search;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Frontend {

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_footer', array( __CLASS__, 'inject_dropdown_container' ) );
		add_action( 'wp_ajax_wcs_refresh_nonce',        array( __CLASS__, 'ajax_refresh_nonce' ) );
		add_action( 'wp_ajax_nopriv_wcs_refresh_nonce', array( __CLASS__, 'ajax_refresh_nonce' ) );
		add_shortcode( 'turbo_search', array( __CLASS__, 'render_shortcode' ) );
	}

	/**
	 * Enqueue scripts and styles.
	 */
	public static function enqueue_assets(): void {
		// Load on every page: themes like Woodmart place their product search bar
		// in the global header, so restricting to WooCommerce pages would leave the
		// search input un-enhanced on blog posts, static pages, etc.

		$version = WCS_VERSION;
		if ( WP_DEBUG ) {
			$version = (string) time(); // Cache bust in dev
		}

		wp_enqueue_style( 'wcs-search-css', WCS_PLUGIN_URL . 'assets/css/search.css', array(), $version );
		wp_enqueue_script( 'wcs-search-js', WCS_PLUGIN_URL . 'assets/js/search.js', array(), $version, true );

		$config = array(
			'api_url'          => esc_url_raw( rest_url( 'wcs/v1/search' ) ),
			'nonce'            => wp_create_nonce( 'wp_rest' ),
			'nonce_refresh_url' => esc_url_raw( admin_url( 'admin-ajax.php?action=wcs_refresh_nonce' ) ),
			'version'          => WCS_VERSION,
			'min_chars' => (int) get_option( 'wcs_min_chars', 2 ),
			// Plain __(), not esc_html__(): these strings are JSON-encoded into
			// a JS object and rendered via .textContent (search.js), which does
			// not decode HTML entities. esc_html__() would leave literal
			// "&quot;" etc. visible in the dropdown instead of the real
			// character — exactly what happened to 'view_all' before this fix,
			// since it's the only string here containing quote characters.
			'i18n'      => array(
				'no_results'     => __( 'No products found.', 'turbo-search-for-woocommerce' ),
				'out_of_stock'   => __( 'Out of Stock', 'turbo-search-for-woocommerce' ),
				'index_building' => __( 'Search is being set up — please try again in a minute.', 'turbo-search-for-woocommerce' ),
				'category'       => __( 'Category', 'turbo-search-for-woocommerce' ),
				'brand'          => __( 'Brand', 'turbo-search-for-woocommerce' ),
				/* translators: %d: number of products in the category/brand */
				'products_count' => __( '%d products', 'turbo-search-for-woocommerce' ),
				/* translators: %s: the search query */
				'view_all'       => __( 'View all results for "%s"', 'turbo-search-for-woocommerce' ),
				/* translators: %s: the corrected search term actually used */
				'showingResultsFor' => __( 'Showing results for "%s"', 'turbo-search-for-woocommerce' ),
			),
			'currency'  => array(
				'code'         => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : get_option( 'woocommerce_currency', 'USD' ),
				'symbol'       => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : '',
				'position'     => get_option( 'woocommerce_currency_pos', 'left' ),
				'thousand_sep' => get_option( 'woocommerce_price_thousand_sep', ',' ),
				'decimal_sep'  => get_option( 'woocommerce_price_decimal_sep', '.' ),
				'decimals'     => (int) get_option( 'woocommerce_price_num_decimals', 2 ),
			),
		);

		wp_add_inline_script( 'wcs-search-js', 'const wcs_config = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Return a fresh wp_rest nonce.
	 *
	 * Called by the frontend JS when a 403 signals that the baked-in nonce has
	 * expired (common after leaving a tab open longer than 12 hours). The endpoint
	 * is read-only — it creates a nonce, not consumes one — so no nonce guard is
	 * required on the handler itself.
	 */
	public static function ajax_refresh_nonce(): void {
		// Throttle to 10 refreshes per minute per IP — prevents bots using this
		// endpoint as a free nonce dispenser that bypasses the search rate limit.
		// Apply the same wcs_get_client_ip filter used by Search_Handler so that
		// proxy-header overrides affect both endpoints consistently. Defaults to
		// REMOTE_ADDR, which is safe; site owners who override via the filter must
		// guard against X-Forwarded-For spoofing (see Search_Handler::get_client_ip).
		$ip = (string) apply_filters( 'wcs_get_client_ip', sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ) );
		if ( ! Rate_Limiter::allow( 'wcs_nr_' . md5( $ip ), 10, MINUTE_IN_SECONDS ) ) {
			wp_send_json_error( null, 429 );
			return;
		}
		wp_send_json_success( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
	}

	/**
	 * Render a standalone product search form.
	 *
	 * Usage: [turbo_search]
	 *        [turbo_search placeholder="Find a product…" button="Go" class="my-wrap"]
	 *
	 * The form submits to the native WooCommerce search results page as a fallback
	 * when JavaScript is disabled. When JS is active, the live dropdown intercepts
	 * keystrokes exactly as it does for theme search bars.
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render_shortcode( $atts ): string {
		$atts = shortcode_atts(
			array(
				'placeholder' => esc_attr__( 'Search products…', 'turbo-search-for-woocommerce' ),
				'button'      => esc_attr__( 'Search', 'turbo-search-for-woocommerce' ),
				'class'       => '',
			),
			$atts,
			'turbo_search'
		);

		// Ensure assets are on the page even if the shortcode is used on a page
		// that somehow skipped wp_enqueue_scripts (e.g. a widget loaded late).
		if ( ! wp_script_is( 'wcs-search-js', 'enqueued' ) ) {
			wp_enqueue_style( 'wcs-search-css' );
			wp_enqueue_script( 'wcs-search-js' );
		}

		$wrapper_class = 'wcs-form-wrap';
		if ( $atts['class'] ) {
			$wrapper_class .= ' ' . sanitize_html_class( $atts['class'] );
		}

		$search_icon = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></svg>';

		return sprintf(
			'<form role="search" method="get" class="%s" action="%s">
				<input type="search" class="wcs-form-input" name="s"
					placeholder="%s" value="%s" autocomplete="off"
					aria-label="%s" />
				<input type="hidden" name="post_type" value="product" />
				<button type="submit" class="wcs-form-btn" aria-label="%s">%s</button>
			</form>',
			esc_attr( $wrapper_class ),
			esc_url( home_url( '/' ) ),
			esc_attr( $atts['placeholder'] ),
			esc_attr( get_search_query() ),
			esc_attr__( 'Search products', 'turbo-search-for-woocommerce' ),
			esc_attr__( 'Search', 'turbo-search-for-woocommerce' ),
			$search_icon
		);
	}

	/**
	 * Inject empty container at bottom of body to escape overflow/z-index issues.
	 */
	public static function inject_dropdown_container(): void {
		echo '<div id="wcs-dropdown-portal"></div>';
	}
}
