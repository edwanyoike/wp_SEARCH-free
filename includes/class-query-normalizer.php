<?php
declare(strict_types=1);

/**
 * Shared query normalization, cache-key construction, and synonym expansion.
 *
 * This is the single source of truth for how a raw search string becomes a
 * normalized query and a cache key. Both the REST handler
 * (Search_Handler::handle_request) and the MU cache-bypass plugin
 * (wcs-cache-bypass.php) call these methods, so the two paths can never
 * drift and compute different cache keys for the same search — a drift here
 * silently disables the MU fast path.
 *
 * normalize(), tokenize(), and cache_key() are pure (mb_* / preg only) so the
 * MU plugin can use them at plugins_loaded -10 with no plugin bootstrapping.
 * Synonym methods use get_option()/apply_filters(), which are always loaded
 * by that stage too.
 *
 * @package WP_Fast_Search
 */

namespace WCS\Search;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Query_Normalizer {

	/**
	 * Maximum normalized query length. Bounds LIKE-pattern cost and
	 * cache-key cardinality.
	 */
	public const MAX_LENGTH = 100;

	/**
	 * Parsed synonym map, built once per request.
	 *
	 * @var array<string, string[]>|null Map of word => alternatives (word itself first).
	 */
	private static ?array $synonym_map = null;

	/**
	 * Normalize a raw (already sanitize_text_field'd) search string.
	 *
	 * FULLTEXT boolean operators and punctuation are replaced with spaces —
	 * NOT deleted — so hyphenated terms split into their real tokens:
	 * "ABC-123" still matches the stored SKU "ABC-123" via per-word matching,
	 * and "t-shirt" matches the indexed tokens "t" + "shirt".
	 *
	 * This same method tokenizes indexed titles for the vocabulary sidecar
	 * (see vocabulary_terms()), so sentence punctuation matters just as much
	 * as FULLTEXT operators: a title like "Necklace, Beaded" must not leave
	 * "necklace," as a token distinct from "necklace" — that would split one
	 * real word's frequency across two vocabulary entries and degrade typo
	 * correction. Comma/period/colon/semicolon/slash/ampersand/exclamation/
	 * question-mark are stripped for the same reason punctuation is stripped
	 * from queries: it never carries search meaning on either side.
	 *
	 * @param string $query Raw query.
	 * @return string Lowercased, punctuation-split, whitespace-collapsed, length-capped query.
	 */
	public static function normalize( string $query ): string {
		$query = str_replace(
			array( '*', '+', '-', '<', '>', '~', '@', '(', ')', '"', "'", ',', '.', ':', ';', '/', '\\', '&', '!', '?' ),
			' ',
			$query
		);
		$query = (string) preg_replace( '/\s+/u', ' ', $query );
		$query = trim( $query );
		$query = mb_strtolower( $query, 'UTF-8' );

		if ( mb_strlen( $query, 'UTF-8' ) > self::MAX_LENGTH ) {
			$query = mb_substr( $query, 0, self::MAX_LENGTH, 'UTF-8' );
		}

		return $query;
	}

	/**
	 * Split a normalized query into words.
	 *
	 * @param string $normalized Output of normalize().
	 * @return string[]
	 */
	public static function tokenize( string $normalized ): array {
		$words = preg_split( '/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $words ) ? $words : array();
	}

	/**
	 * Build the cache key for a normalized query.
	 *
	 * The key is computed from the PRE-synonym-expansion query, so synonym
	 * configuration changes do not change key shapes (a cache-version bump
	 * invalidates instead).
	 *
	 * @param string $normalized    Output of normalize().
	 * @param string $currency      Validated ISO-4217 currency code.
	 * @param int    $cache_version Current wcs_cache_version.
	 * @return string
	 */
	public static function cache_key( string $normalized, string $currency, int $cache_version ): string {
		return 'wcs_v' . $cache_version . '_' . $currency . '_' . md5( $normalized );
	}

	/**
	 * Expand a query word into itself, its configured synonyms, and its
	 * automatic morphological variants.
	 *
	 * The typed word is always first. Automatic variants cover the two most
	 * common "typed it slightly differently" cases:
	 *   - English plural/singular forms: lamp↔lamps, box↔boxes, buggy↔buggies.
	 *   - Letter↔digit boundaries: "iphone14" also tries the phrase "iphone 14".
	 *
	 * @param string $word Normalized query word.
	 * @return string[]
	 */
	public static function expand( string $word ): array {
		$map  = self::synonym_map();
		$alts = $map[ $word ] ?? array( $word );

		return array_values( array_unique( array_merge( $alts, self::word_variants( $word ) ) ) );
	}

	/**
	 * Automatic plural/singular and letter-digit-boundary matching is a Pro
	 * feature. This edition matches only the exact typed word.
	 *
	 * @param string $word Normalized query word.
	 * @return string[] Always empty in this edition.
	 */
	public static function word_variants( string $word ): array {
		return array();
	}

	/**
	 * Normalize a SKU-ish string for exact/prefix matching: lowercase with
	 * every non-alphanumeric removed, so "ABC-123", "abc 123", and "abc123"
	 * all collapse to "abc123". Applied to both the indexed sku_normalized
	 * column and the query at search time.
	 *
	 * @param string $sku Raw SKU or query string.
	 * @return string
	 */
	public static function normalize_sku( string $sku ): string {
		return (string) preg_replace( '/[^a-z0-9]/', '', mb_strtolower( $sku, 'UTF-8' ) );
	}

	/**
	 * Extract vocabulary terms from indexed text for the typo-correction
	 * sidecar: normalized tokens, 3–64 chars, containing at least one letter
	 * (pure numbers are useless as spelling-correction targets).
	 *
	 * @param string $text Title/SKU text.
	 * @return string[]
	 */
	public static function vocabulary_terms( string $text ): array {
		$terms = array();
		foreach ( self::tokenize( self::normalize( $text ) ) as $token ) {
			$len = mb_strlen( $token, 'UTF-8' );
			if ( $len >= 3 && $len <= 64 && preg_match( '/[a-z]/', $token ) ) {
				$terms[] = $token;
			}
		}
		return $terms;
	}

	/**
	 * Reset the per-request synonym cache (used after option updates and in tests).
	 */
	public static function flush_synonym_cache(): void {
		self::$synonym_map = null;
	}

	/**
	 * Search synonyms are a Pro feature. This edition never expands a word
	 * beyond itself, regardless of the wcs_synonyms option (not registered
	 * or shown in this edition's settings UI, but left inert here too in
	 * case a site migrates data from the Pro edition).
	 *
	 * @return array<string, string[]> Always empty in this edition.
	 */
	private static function synonym_map(): array {
		return array();
	}
}
