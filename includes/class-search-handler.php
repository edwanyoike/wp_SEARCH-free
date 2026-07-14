<?php
declare(strict_types=1);

/**
 * REST API Endpoint and Query logic.
 *
 * @package WP_Fast_Search
 */

namespace WCS\Search;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Search_Handler {

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Register the REST API route.
	 */
	public static function register_routes(): void {
		register_rest_route( 'wcs/v1', '/search', array(
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'handle_request' ),
			'permission_callback' => array( __CLASS__, 'check_permissions' ),
			'args'                => array(
				'q' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'_wpnonce' => array(
					'required' => true,
					'type'     => 'string',
				),
				'currency' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );
	}

	/**
	 * Check permissions / validate nonce to block basic scraping.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool|\WP_Error
	 */
	public static function check_permissions( \WP_REST_Request $request ) {
		$nonce = $request->get_param( '_wpnonce' );
		if ( ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new \WP_Error( 'rest_forbidden', esc_html__( 'Invalid nonce.', 'turbo-search-for-woocommerce' ), array( 'status' => 403 ) );
		}

		// Per-IP rate limiting: max 60 requests per minute.
		$ip = self::get_client_ip();
		if ( ! Rate_Limiter::allow( 'wcs_rl_' . md5( $ip ), 60, MINUTE_IN_SECONDS ) ) {
			return new \WP_Error( 'rest_too_many_requests', esc_html__( 'Too many requests.', 'turbo-search-for-woocommerce' ), array( 'status' => 429 ) );
		}

		return true;
	}

	/**
	 * Handle the search request.
	 *
	 * Cache hierarchy (fastest to slowest):
	 *   1. APCu   — shared server RAM, ~0.01 ms, no I/O.
	 *   2. Transient — WP object cache (Redis/Memcached) or wp_options DB row.
	 *   3. Mutex  — wp_cache_add() stampede guard (only with persistent cache).
	 *   4. DB     — FULLTEXT query against wcs_search_index, then LIKE fallback.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public static function handle_request( \WP_REST_Request $request ): \WP_REST_Response {
		$query = $request->get_param( 'q' );
		if ( empty( $query ) ) {
			return rest_ensure_response( array() );
		}

		// Normalize via the shared class — the MU cache-bypass plugin calls the
		// same method, guaranteeing both paths compute identical cache keys.
		$query = Query_Normalizer::normalize( $query );

		if ( empty( $query ) ) {
			return rest_ensure_response( array() );
		}

		// Server-side min_chars enforcement — mirrors the client-side JS check so
		// bots bypassing the frontend cannot trigger DB queries with 1-char terms.
		$min_chars = max( 1, (int) get_option( 'wcs_min_chars', 2 ) );
		if ( mb_strlen( $query, 'UTF-8' ) < $min_chars ) {
			return rest_ensure_response( array() );
		}

		// Multi-currency price conversion is a Pro feature — this edition always
		// serves prices in the store's default currency.
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : get_option( 'woocommerce_currency', 'USD' );

		$cache_version = (int) get_option( 'wcs_cache_version', 1 );
		$cache_key     = Query_Normalizer::cache_key( $query, $currency, $cache_version );

		// ── 1. APCu L1 cache (shared server memory, ~0.01 ms, no I/O) ────────
		// Architecture specifies APCu as L2; as the fastest layer it is checked
		// first. TTL of 5 minutes keeps per-server memory pressure low while still
		// serving the vast majority of repeated searches from RAM.
		// Stale entries are invalidated implicitly: a cache-version bump changes
		// the key prefix, so old APCu entries are never found and expire naturally.
		if ( function_exists( 'apcu_fetch' ) ) {
			$apcu_result = apcu_fetch( $cache_key, $apcu_hit );
			if ( true === $apcu_hit ) {
				[$cached_rows, $cached_corrected] = self::unwrap_cached( $apcu_result );
				return self::build_response( $cached_rows, $cached_corrected );
			}
		}

		// ── 2. Transient (WP object cache / wp_options) ───────────────────────
		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			// Warm APCu so subsequent requests on this server skip the transient read.
			if ( function_exists( 'apcu_store' ) ) {
				apcu_store( $cache_key, $cached, 300 );
			}
			[$cached_rows, $cached_corrected] = self::unwrap_cached( $cached );
			return self::build_response( $cached_rows, $cached_corrected );
		}

		// ── 3. Mutex: prevent cache stampedes under a persistent object cache ──
		// wp_cache_add() is atomic only when a shared backend (Redis, Memcached)
		// is present. Without one every PHP worker has its own in-process cache
		// and wp_cache_add() always returns true, making the mutex a no-op.
		// In that case skip the poller path entirely — workers run concurrent DB
		// queries which is the same behaviour as before and is safe (idempotent).
		$lock_key   = '';
		$lock_group = 'wcs_search';
		if ( wp_using_ext_object_cache() ) {
			$lock_key   = 'wcs_lock_' . $currency . '_' . md5( $query );
			$is_builder = wp_cache_add( $lock_key, '1', $lock_group, 5 );

			if ( ! $is_builder ) {
				// ── 3. Poller path: wait briefly for the builder to populate the
				// cache. Capped at 3 × 150 ms — each poller parks a PHP-FPM worker,
				// so long waits under a real herd starve the pool. The DB query is
				// idempotent; falling through early is always safe.
				for ( $i = 0; $i < 3; $i++ ) {
					usleep( 150000 ); // 150 ms
					$cached = get_transient( $cache_key );
					if ( false !== $cached ) {
						[$cached_rows, $cached_corrected] = self::unwrap_cached( $cached );
						return self::build_response( $cached_rows, $cached_corrected );
					}
				}
				// Builder timed out or failed — fall through and query DB directly.
			}
		}

		// ── 4. DB: run the search query ──────────────────────────────────────
		self::$last_corrected_query = null;
		$results         = self::query_database( $query );
		$corrected_query = self::$last_corrected_query;

		// First-run window: until the initial index build completes, an empty
		// result set means "not indexed yet", not "no matching products".
		// Signal the frontend via header and skip caching so results appear
		// the moment the build finishes — no stale empty entries linger.
		if ( empty( $results ) && 0 === (int) get_option( 'wcs_last_indexed', 0 ) ) {
			if ( $lock_key ) {
				wp_cache_delete( $lock_key, $lock_group );
			}
			$response = rest_ensure_response( array() );
			$response->header( 'X-WCS-Indexing', '1' );
			return $response;
		}

		// Cache for 24 hours. GC handles orphaned transients on version bump.
		// Wrapped with the corrected query (if typo correction fired) so cache
		// hits also get the X-WCS-Corrected-Query header, not just this request.
		$payload = self::wrap_for_cache( $results, $corrected_query );
		set_transient( $cache_key, $payload, DAY_IN_SECONDS );

		// Warm APCu so subsequent requests on this server are served from RAM.
		if ( function_exists( 'apcu_store' ) ) {
			apcu_store( $cache_key, $payload, 300 );
		}

		// Release the mutex so pollers that still haven't given up can proceed.
		if ( $lock_key ) {
			wp_cache_delete( $lock_key, $lock_group );
		}

		return self::build_response( $results, $corrected_query );
	}

	/**
	 * Marker key on cached payloads, distinguishing the wrapped
	 * {results, corrected} shape from a plain array of result rows. Needed so
	 * that transients/APCu entries written by a pre-upgrade version of this
	 * plugin (plain result arrays, no wrapper) are still read correctly during
	 * their remaining TTL after an upgrade — see unwrap_cached().
	 */
	private const CACHE_PAYLOAD_MARKER = '__wcs_payload';

	/**
	 * Wrap results plus the (optional) typo-corrected query into the shape
	 * stored in the transient/APCu cache.
	 *
	 * @param array       $results   Result rows (already through the
	 *                                wcs_search_results filter).
	 * @param string|null $corrected The corrected query, if correction fired.
	 * @return array
	 */
	private static function wrap_for_cache( array $results, ?string $corrected ): array {
		return array(
			self::CACHE_PAYLOAD_MARKER => true,
			'results'                  => $results,
			'corrected'                => $corrected,
		);
	}

	/**
	 * Unwrap a cache read back into [rows, corrected_query_or_null].
	 *
	 * Falls back to treating the whole value as a plain rows array when the
	 * marker key is absent — the shape written by any plugin version prior to
	 * this one, still valid for up to 24h of transient TTL after an upgrade.
	 *
	 * @param mixed $cached Value read from get_transient()/apcu_fetch().
	 * @return array{0: array, 1: string|null}
	 */
	private static function unwrap_cached( $cached ): array {
		if ( is_array( $cached ) && ! empty( $cached[ self::CACHE_PAYLOAD_MARKER ] ) ) {
			return array( (array) ( $cached['results'] ?? array() ), $cached['corrected'] ?? null );
		}
		return array( is_array( $cached ) ? $cached : array(), null );
	}

	/**
	 * Build the REST response from result rows, attaching the
	 * X-WCS-Corrected-Query header when typo correction changed the query —
	 * lets the frontend highlight the terms actually matched instead of the
	 * shopper's original (misspelled) input.
	 *
	 * @param array       $results   Result rows.
	 * @param string|null $corrected The corrected query, if any.
	 * @return \WP_REST_Response
	 */
	private static function build_response( array $results, ?string $corrected ): \WP_REST_Response {
		$response = rest_ensure_response( $results );
		if ( ! empty( $corrected ) ) {
			$response->header( 'X-WCS-Corrected-Query', $corrected );
		}
		return $response;
	}

	/**
	 * Query the custom search index table.
	 *
	 * Three-tier strategy:
	 *   1. FULLTEXT boolean mode — hybrid: words long enough for the index's
	 *      parser go into MATCH(); shorter words become ANDed LIKE filters that
	 *      only run against the already-narrowed FULLTEXT candidate set.
	 *   2. Prefix LIKE — title/sku starts with each word (index-served via
	 *      idx_title_prefix + idx_sku; no table scan).
	 *   3. Substring LIKE — fill pass, only when tier 2 returned fewer than
	 *      the requested limit. This is the only scanning query and most
	 *      searches never reach it.
	 *
	 * The FULLTEXT word-length gate depends on the parser recorded at index
	 * creation (wcs_ft_parser): ngram indexes token at 2 chars; the default
	 * InnoDB parser is only reliable from 4 chars up (innodb_ft_min_token_size
	 * boundary quirks make "+haz*" return zero rows on some setups).
	 *
	 * @param string $query Sanitized, lowercased search query.
	 * @return array
	 */
	/**
	 * Base relevance-ranking weights before the wcs_ranking_weights filter runs.
	 * Ranking-weight tuning from the Settings tab is a Pro feature — this
	 * edition always uses these built-in defaults.
	 *
	 * @return array<string, float>
	 */
	private static function default_ranking_weights(): array {
		return array(
			'title'        => 5.0,
			'all_fields'   => 1.0,
			'exact_title'  => 10.0,
			'exact_sku'    => 20.0,
			'title_prefix' => 3.0,
			'phrase'       => 4.0,
			'instock'      => 0.5,
			'sales'        => 0.3,
			// Recent-sales-weighted ranking is a Pro feature; the indexer never
			// populates sales_30d in this edition, so this weight is inert either
			// way, but pinned to 0 here for clarity rather than relying on that.
			'recent_sales' => 0.0,
		);
	}

	private static function query_database( string $query ): array {
		global $wpdb;
		$table_name = $wpdb->prefix . 'wcs_search_index';

		$limit    = (int) get_option( 'wcs_result_count', 6 );
		$show_oos = (bool) get_option( 'wcs_show_out_of_stock', 1 );

		// Tokenize — collapses multiple spaces and strips empty tokens.
		$words = Query_Normalizer::tokenize( $query );
		if ( empty( $words ) ) {
			return array();
		}

		// ── Stock filter ─────────────────────────────────────────────────────────
		// Two literal shapes so MySQL can use the stock_status index directly.
		$stock_clause = $show_oos ? '' : "AND stock_status = 'instock'";

		// ── Tier 0: normalized-SKU probe ─────────────────────────────────────────
		// Digit-containing queries are usually SKUs typed verbatim. The
		// sku_normalized column collapses "ABC-123", "abc 123", and "abc123"
		// to one form, so a single indexed prefix lookup finds the product no
		// matter how the shopper (or the catalog) punctuates it. A hit here is
		// deterministic intent — return immediately, shortest SKU first.
		$sku_norm = Query_Normalizer::normalize_sku( $query );
		if ( strlen( $sku_norm ) >= 4 && preg_match( '/\d/', $sku_norm ) ) {
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $stock_clause is a fixed SQL literal
			$probe = self::get_rows( $wpdb->prepare(
				"SELECT product_id, title, excerpt, price_min, price_max, image_url, permalink, stock_status
				 FROM %i
				 WHERE sku_normalized LIKE %s
				 {$stock_clause}
				 ORDER BY CHAR_LENGTH(sku_normalized) ASC, total_sales DESC
				 LIMIT %d",
				$table_name,
				$wpdb->esc_like( $sku_norm ) . '%',
				$limit
			) );
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( ! empty( $probe ) ) {
				/** This filter is documented below. */
				return (array) apply_filters( 'wcs_search_results', $probe, $query );
			}
		}

		// Split words by FULLTEXT eligibility for this index's parser.
		$parser  = (string) get_option( 'wcs_ft_parser', 'default' );
		$ft_gate = ( 'ngram' === $parser ) ? 2 : 4;

		$last_idx   = count( $words ) - 1;
		$ft_words   = array();
		$like_words = array();
		foreach ( $words as $i => $word ) {
			if ( mb_strlen( $word ) >= $ft_gate ) {
				$ft_words[ $i ] = $word;
			} else {
				$like_words[] = $word;
			}
		}

		$results = array();

		// ── Tier 1: FULLTEXT hybrid with weighted ranking ────────────────────────
		// Eligible words use exact required matching (+word). Only the token the
		// user is still typing (the last one) gets a prefix wildcard, and only
		// with the default parser — ngram matches partial words inherently and
		// ignores the wildcard operator. Words below the gate are ANDed on as
		// LIKE groups: they filter the small FULLTEXT candidate set, not the
		// whole table.
		//
		// Ranking: candidates come from the combined (title, sku, content) index
		// for recall; ordering is a weighted score computed only for those
		// candidates. Title matches (via the dedicated ft_title index) count 5×
		// a content match, exact title/SKU equality gets a fixed boost so typing
		// a full SKU always puts that product first, in-stock rows edge out
		// out-of-stock ones, and log-capped lifetime sales break the remaining
		// ties so popular products surface. All weights are filterable.
		if ( ! empty( $ft_words ) ) {
			// Each word expands to itself + configured synonyms. A word with
			// synonyms becomes a required OR-group: "+(sofa* couch settee)" —
			// at least one alternative must match. The prefix wildcard stays on
			// the typed word only. Synonyms too short for this index's parser
			// are dropped from the FULLTEXT group (the LIKE tiers still cover them).
			$boolean_parts = array();
			foreach ( $ft_words as $i => $word ) {
				$use_wildcard = ( $i === $last_idx && 'ngram' !== $parser );
				$alts         = array_values( array_filter(
					Query_Normalizer::expand( $word ),
					static fn( string $alt ): bool => mb_strlen( $alt ) >= $ft_gate
				) );
				if ( count( $alts ) <= 1 ) {
					$boolean_parts[] = '+' . $word . ( $use_wildcard ? '*' : '' );
					continue;
				}
				$terms = array();
				foreach ( $alts as $j => $alt ) {
					// Multi-word alternatives (digit-boundary splits like
					// "iphone 14", multi-word synonyms) become quoted phrases —
					// unquoted they would decay into independent OR words.
					if ( str_contains( $alt, ' ' ) ) {
						$terms[] = '"' . $alt . '"';
					} else {
						$terms[] = $alt . ( $use_wildcard && 0 === $j ? '*' : '' );
					}
				}
				$boolean_parts[] = '+(' . implode( ' ', $terms ) . ')';
			}
			$boolean_query = implode( ' ', $boolean_parts );

			$short_sql    = '';
			$short_params = array();
			foreach ( $like_words as $word ) {
				$conds = array();
				foreach ( Query_Normalizer::expand( $word ) as $alt ) {
					$escaped = $wpdb->esc_like( $alt );
					$conds[] = 'title LIKE %s';
					$conds[] = 'sku LIKE %s';
					$conds[] = 'title LIKE %s';
					$conds[] = 'sku LIKE %s';
					array_push( $short_params, $escaped . '%', $escaped . '%', '%' . $escaped . '%', '%' . $escaped . '%' );
				}
				$short_sql .= ' AND (' . implode( ' OR ', $conds ) . ')';
			}

			/**
			 * Filters the relevance-ranking weights.
			 *
			 * @param array $weights {
			 *     @type float $title        Multiplier for the title-only FULLTEXT score.
			 *     @type float $all_fields   Multiplier for the combined title/sku/content score.
			 *     @type float $exact_title  Fixed boost when the title equals the whole query.
			 *     @type float $exact_sku    Fixed boost when the SKU equals the whole query.
			 *     @type float $title_prefix Fixed boost when the title starts with the query.
			 *     @type float $phrase       Fixed boost when the title contains the query as a phrase.
			 *     @type float $instock      Fixed boost for in-stock products.
			 *     @type float $sales        Multiplier for LEAST(LOG(1 + total_sales), 3).
			 * }
			 */
			$weights = (array) apply_filters( 'wcs_ranking_weights', self::default_ranking_weights() );

			// Recent-sales-weighted ranking is a Pro feature; sales_30d is never
			// populated in this edition, so pin the weight to zero regardless of
			// what a filter tries to set it to.
			$weights['recent_sales'] = 0.0;

			$escaped_query = $wpdb->esc_like( $query );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $stock_clause is a fixed SQL literal; $short_sql is built from %s placeholders; %i handles the table
			$sql = $wpdb->prepare(
				"SELECT product_id, title, excerpt, price_min, price_max, image_url, permalink, stock_status
				 FROM %i
				 WHERE MATCH(title, sku, content) AGAINST (%s IN BOOLEAN MODE)
				 {$short_sql}
				 {$stock_clause}
				 ORDER BY (
					   %f * MATCH(title) AGAINST (%s IN BOOLEAN MODE)
					 + %f * MATCH(title, sku, content) AGAINST (%s IN BOOLEAN MODE)
					 + IF(title = %s, %f, 0)
					 + IF(sku = %s, %f, 0)
					 + IF(title LIKE %s, %f, 0)
					 + IF(title LIKE %s, %f, 0)
					 + IF(stock_status = 'instock', %f, 0)
					 + %f * LEAST(LOG(1 + total_sales), 3)
					 + %f * LEAST(LOG(1 + sales_30d), 3)
				 ) DESC
				 LIMIT %d",
				...array_merge(
					array( $table_name, $boolean_query ),
					$short_params,
					array(
						(float) ( $weights['title'] ?? 5.0 ),
						$boolean_query,
						(float) ( $weights['all_fields'] ?? 1.0 ),
						$boolean_query,
						$query,
						(float) ( $weights['exact_title'] ?? 10.0 ),
						$query,
						(float) ( $weights['exact_sku'] ?? 20.0 ),
						$escaped_query . '%',
						(float) ( $weights['title_prefix'] ?? 3.0 ),
						'%' . $escaped_query . '%',
						(float) ( $weights['phrase'] ?? 4.0 ),
						(float) ( $weights['instock'] ?? 0.5 ),
						(float) ( $weights['sales'] ?? 0.3 ),
						(float) ( $weights['recent_sales'] ?? 1.0 ),
						$limit,
					)
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

			$results = self::get_rows( $sql );
		}

		// ── Tier 2: prefix LIKE (index-served, AND across words) ─────────────────
		// utf8mb4_*_ci collations make LIKE case-insensitive without LOWER(), so
		// these conditions use idx_title_prefix / idx_sku directly.
		if ( empty( $results ) ) {
			$groups = array();
			$params = array();
			foreach ( $words as $word ) {
				$conds = array();
				foreach ( Query_Normalizer::expand( $word ) as $alt ) {
					$escaped = $wpdb->esc_like( $alt );
					$conds[] = 'title LIKE %s';
					$conds[] = 'sku LIKE %s';
					array_push( $params, $escaped . '%', $escaped . '%' );
				}
				$groups[] = '(' . implode( ' OR ', $conds ) . ')';
			}
			$where_sql = '(' . implode( ' AND ', $groups ) . ') ' . $stock_clause; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where_sql is built from %s placeholders and fixed literals
			$sql = $wpdb->prepare(
				"SELECT product_id, title, excerpt, price_min, price_max, image_url, permalink, stock_status
				 FROM %i
				 WHERE {$where_sql}
				 ORDER BY total_sales DESC, title ASC
				 LIMIT %d",
				...array_merge( array( $table_name ), $params, array( $limit ) )
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

			$results = self::get_rows( $sql );

			// ── Tier 3: substring fill ────────────────────────────────────────────
			// Only when prefix matching came up short. Catches words mid-title and
			// mid-SKU — the SKU alternative is what lets the tokenized "abc 123"
			// still find SKU "ABC-123". Excludes rows tier 2 already returned.
			$remaining = $limit - count( $results );
			if ( $remaining > 0 ) {
				$groups = array();
				$params = array();
				foreach ( $words as $word ) {
					$conds = array();
					foreach ( Query_Normalizer::expand( $word ) as $alt ) {
						$escaped = $wpdb->esc_like( $alt );
						$conds[] = 'title LIKE %s';
						$conds[] = 'sku LIKE %s';
						array_push( $params, '%' . $escaped . '%', '%' . $escaped . '%' );
					}
					$groups[] = '(' . implode( ' OR ', $conds ) . ')';
				}
				$where_sql = '(' . implode( ' AND ', $groups ) . ') ' . $stock_clause; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared

				$found_ids = array_map( 'intval', array_column( $results, 'product_id' ) );
				if ( $found_ids ) {
					$where_sql .= ' AND product_id NOT IN (' . implode( ',', array_fill( 0, count( $found_ids ), '%d' ) ) . ')';
					$params     = array_merge( $params, $found_ids );
				}

				// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $where_sql is built from %s/%d placeholders and fixed literals
				$sql = $wpdb->prepare(
					"SELECT product_id, title, excerpt, price_min, price_max, image_url, permalink, stock_status
					 FROM %i
					 WHERE {$where_sql}
					 ORDER BY total_sales DESC, title ASC
					 LIMIT %d",
					...array_merge( array( $table_name ), $params, array( $remaining ) )
				);
				// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

				$results = array_merge( $results, self::get_rows( $sql ) );
			}
		}

		// Typo correction (fuzzy-matching a zero-result query against the
		// catalog vocabulary) is a Pro feature — this edition returns zero
		// results as-is rather than guessing a correction.

		/**
		 * Filters the raw search results before they are cached and returned.
		 *
		 * Each item is an associative array with keys: product_id, title,
		 * excerpt, price_min, price_max, image_url, permalink, stock_status.
		 *
		 * @param array  $results Array of product row arrays.
		 * @param string $query   The sanitized search query.
		 */
		return (array) apply_filters( 'wcs_search_results', $results, $query );
	}

	private static function get_rows( string $sql ): array {
		global $wpdb;
		$suppress = $wpdb->suppress_errors( true );
		$rows     = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $sql is built by callers via $wpdb->prepare(); this helper never receives raw user input
		$wpdb->suppress_errors( $suppress );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Set by query_database() when typo correction changed the query. Typo
	 * correction is a Pro feature — this edition never sets it — but the
	 * property stays so wrap_for_cache()/handle_request()'s cache-payload
	 * shape (which always carries a "corrected" slot) doesn't need its own
	 * Free-only branch.
	 */
	private static ?string $last_corrected_query = null;

	/**
	 * Reset per-request memoization (used by the test suite).
	 */
	public static function flush_runtime_cache(): void {
		self::$last_corrected_query = null;
	}

	private static function get_client_ip(): string {
		$ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) );
		return (string) apply_filters( 'wcs_get_client_ip', $ip );
	}
}
