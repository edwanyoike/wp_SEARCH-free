<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WCS\Search\Search_Handler;

/**
 * Drives Search_Handler::query_database() against the fake wpdb and asserts
 * on the SQL it constructs: tier selection, AND semantics, parser-aware
 * FULLTEXT gating, synonym expansion, ranking weights, and stock filtering.
 */
final class SearchHandlerQueryTest extends TestCase {

	private Fake_WPDB $wpdb;

	protected function setUp(): void {
		wcs_tests_reset();
		$this->wpdb      = new Fake_WPDB();
		$GLOBALS['wpdb'] = $this->wpdb;

		update_option( 'wcs_result_count', 6 );
		update_option( 'wcs_show_out_of_stock', 1 );
		update_option( 'wcs_ft_parser', 'default' );
	}

	/** @return array Rows returned by query_database(). */
	private function search( string $normalized_query ): array {
		$method = new ReflectionMethod( Search_Handler::class, 'query_database' );
		return $method->invoke( null, $normalized_query );
	}

	private function fakeRow( int $id ): array {
		return array(
			'product_id'   => $id,
			'title'        => "Product $id",
			'price_min'    => '10.00',
			'price_max'    => '10.00',
			'image_url'    => '',
			'permalink'    => "https://example.test/?p=$id",
			'stock_status' => 'instock',
		);
	}

	// ── Tier selection: default parser gates FULLTEXT at 4 chars ────────────

	public function test_short_word_skips_fulltext_and_uses_prefix_pass_first(): void {
		$this->search( 'ab' );

		$this->assertStringNotContainsString( 'MATCH', $this->wpdb->queries[0] );
		$this->assertStringContainsString( "title LIKE 'ab%'", $this->wpdb->queries[0] );
		$this->assertStringContainsString( "sku LIKE 'ab%'", $this->wpdb->queries[0] );
		// Prefix pass must not contain a leading-wildcard scan pattern.
		$this->assertStringNotContainsString( "'%ab%'", $this->wpdb->queries[0] );
	}

	public function test_every_tier_selects_the_excerpt_column(): void {
		// LIKE prefix tier (default path for this query).
		$this->search( 'ab' );
		$this->assertStringContainsString( 'SELECT product_id, title, excerpt,', $this->wpdb->queries[0] );

		// FULLTEXT tier.
		$this->wpdb->queries = array();
		$this->wpdb->handler = fn( string $sql, string $type ) => 'results' === $type ? array( $this->fakeRow( 1 ) ) : null;
		$this->search( 'hazina' );
		$this->assertStringContainsString( 'SELECT product_id, title, excerpt,', $this->wpdb->queries[0] );
	}

	public function test_substring_fill_runs_only_when_prefix_pass_comes_up_short(): void {
		$rows = array( $this->fakeRow( 1 ), $this->fakeRow( 2 ) );
		$this->wpdb->handler = function ( string $sql, string $type ) use ( $rows ) {
			if ( 'results' !== $type ) {
				return null;
			}
			// Prefix pass returns 2 rows; substring fill returns nothing.
			return str_contains( $sql, "'%ab%'" ) ? array() : $rows;
		};

		$results = $this->search( 'ab' );

		$this->assertCount( 2, $this->wpdb->queries );
		$fill = $this->wpdb->queries[1];
		$this->assertStringContainsString( "'%ab%'", $fill );
		$this->assertStringContainsString( 'NOT IN (1,2)', $fill );
		$this->assertStringContainsString( 'LIMIT 4', $fill );
		$this->assertCount( 2, $results );
	}

	public function test_substring_fill_also_matches_content(): void {
		// Regression: content is the only column any tier other than FULLTEXT
		// (Tier 1) can search, and Tier 1 is skipped entirely for an
		// all-short-words query. A word that exists only in a product's
		// description/taxonomy terms — never its title or SKU — must still be
		// findable via the last-resort substring-fill tier.
		$this->wpdb->handler = function ( string $sql, string $type ) {
			if ( 'results' !== $type ) {
				return null;
			}
			// Prefix pass (title/sku only) finds nothing; substring fill does.
			return str_contains( $sql, "'%ab%'" ) ? array( $this->fakeRow( 1 ) ) : array();
		};

		$results = $this->search( 'ab' );

		$this->assertCount( 2, $this->wpdb->queries );
		$fill = $this->wpdb->queries[1];
		$this->assertStringContainsString( "content LIKE '%ab%'", $fill );
		$this->assertCount( 1, $results );
	}

	public function test_no_substring_fill_when_prefix_pass_fills_the_limit(): void {
		$rows = array_map( fn( $i ) => $this->fakeRow( $i ), range( 1, 6 ) );
		$this->wpdb->handler = fn( string $sql, string $type ) => 'results' === $type ? $rows : null;

		$results = $this->search( 'ab' );

		$this->assertCount( 1, $this->wpdb->queries );
		$this->assertCount( 6, $results );
	}

	// ── AND semantics across words ───────────────────────────────────────────

	public function test_multiword_like_uses_and_across_words(): void {
		$this->search( 'red cap' );

		$sql = preg_replace( '/\s+/', ' ', $this->wpdb->queries[0] );
		// Each word forms one OR-group (title/sku); groups are ANDed so every
		// word must match somewhere. Plural-variant expansion is a Pro
		// feature, so only the exact typed word appears here.
		$this->assertStringContainsString( "title LIKE 'red%' OR sku LIKE 'red%'", $sql );
		$this->assertStringContainsString( ") AND (", $sql );
		$this->assertStringContainsString( "title LIKE 'cap%'", $sql );
	}

	// ── FULLTEXT tier ────────────────────────────────────────────────────────

	public function test_long_words_use_fulltext_with_wildcard_on_last_word_only(): void {
		$this->wpdb->handler = fn( string $sql, string $type ) => 'results' === $type ? array( $this->fakeRow( 1 ) ) : null;

		$this->search( 'hazina lamp' );

		$sql = $this->wpdb->queries[0];
		$this->assertStringContainsString( 'MATCH(title, sku, content)', $sql );
		// Plural-variant expansion is a Pro feature — only the typed words
		// appear, wildcard on the last word only.
		$this->assertStringContainsString( '+hazina +lamp*', $sql );
	}

	public function test_fulltext_falls_back_to_like_when_it_returns_nothing(): void {
		$this->search( 'hazina' );

		$this->assertGreaterThanOrEqual( 2, count( $this->wpdb->queries ) );
		$this->assertStringContainsString( 'MATCH', $this->wpdb->queries[0] );
		$this->assertStringNotContainsString( 'MATCH', $this->wpdb->queries[1] );
		$this->assertStringContainsString( "'hazina%'", $this->wpdb->queries[1] );
	}

	public function test_hybrid_query_ands_short_words_onto_fulltext_candidates(): void {
		$this->wpdb->handler = fn( string $sql, string $type ) => 'results' === $type ? array( $this->fakeRow( 1 ) ) : null;

		$this->search( 'tv hazina' );

		$sql = preg_replace( '/\s+/', ' ', $this->wpdb->queries[0] );
		// Long word required in boolean query, with wildcard (it is the last token).
		$this->assertStringContainsString( '+hazina*', $sql );
		// Short word becomes an ANDed LIKE group over the candidate set.
		$this->assertStringContainsString( "AND (title LIKE 'tv%' OR sku LIKE 'tv%' OR title LIKE '%tv%' OR sku LIKE '%tv%')", $sql );
	}

	public function test_ngram_parser_lowers_gate_to_two_chars_and_drops_wildcard(): void {
		update_option( 'wcs_ft_parser', 'ngram' );
		$this->wpdb->handler = fn( string $sql, string $type ) => 'results' === $type ? array( $this->fakeRow( 1 ) ) : null;

		$this->search( 'tv' );

		$sql = $this->wpdb->queries[0];
		$this->assertStringContainsString( 'MATCH', $sql );
		$this->assertStringContainsString( '+tv', $sql );
		$this->assertStringNotContainsString( '+tv*', $sql );
	}

	// ── Ranking ──────────────────────────────────────────────────────────────

	public function test_ranking_expression_includes_weighted_terms(): void {
		$this->wpdb->handler = fn( string $sql, string $type ) => 'results' === $type ? array( $this->fakeRow( 1 ) ) : null;

		$this->search( 'hazina' );

		$sql = $this->wpdb->queries[0];
		$this->assertStringContainsString( 'MATCH(title) AGAINST', $sql );
		$this->assertStringContainsString( "IF(title = 'hazina', 10, 0)", $sql );
		$this->assertStringContainsString( "IF(sku = 'hazina', 20, 0)", $sql );
		$this->assertStringContainsString( "IF(stock_status = 'instock', 0.5, 0)", $sql );
		$this->assertStringContainsString( 'LEAST(LOG(1 + total_sales), 3)', $sql );
	}

	public function test_ranking_weights_filter_overrides_defaults(): void {
		add_filter( 'wcs_ranking_weights', static function ( array $w ): array {
			$w['exact_sku'] = 99.5;
			return $w;
		} );
		$this->wpdb->handler = fn( string $sql, string $type ) => 'results' === $type ? array( $this->fakeRow( 1 ) ) : null;

		$this->search( 'hazina' );

		$this->assertStringContainsString( "IF(sku = 'hazina', 99.5, 0)", $this->wpdb->queries[0] );
	}

	public function test_like_tiers_prioritize_exact_and_prefix_intent_before_popularity(): void {
		$this->search( 'ab' );

		$this->assertStringContainsString( "ORDER BY IF(title = 'ab', 100, 0)", $this->wpdb->queries[0] );
		$this->assertStringContainsString( "IF(sku = 'ab', 120, 0)", $this->wpdb->queries[0] );
		$this->assertStringContainsString( "IF(title = 'ab' OR title LIKE 'ab %', 20, 0)", $this->wpdb->queries[0] );
		$this->assertStringContainsString( 'total_sales DESC, title ASC', $this->wpdb->queries[0] );
	}

	// ── Synonym expansion in SQL ─────────────────────────────────────────────

	public function test_synonyms_option_has_no_effect_on_the_fulltext_query(): void {
		update_option( 'wcs_synonyms', 'sofa, couch, settee' );
		\WCS\Search\Query_Normalizer::flush_synonym_cache();
		$this->wpdb->handler = fn( string $sql, string $type ) => 'results' === $type ? array( $this->fakeRow( 1 ) ) : null;

		$this->search( 'sofa' );

		$this->assertStringContainsString( '+sofa*', $this->wpdb->queries[0] );
		$this->assertStringNotContainsString( 'couch', $this->wpdb->queries[0] );
	}

	public function test_sku_probe_intercepts_digit_queries_before_fulltext(): void {
		$this->wpdb->handler = fn( string $sql, string $type ) =>
			( 'results' === $type && str_contains( $sql, 'sku_normalized LIKE' ) ) ? array( $this->fakeRow( 7 ) ) : null;

		$results = $this->search( 'abc 123' );

		$this->assertSame( 7, $results[0]['product_id'] );
		$this->assertCount( 1, $this->wpdb->queries, 'a probe hit must skip all other tiers' );
		$this->assertStringContainsString( "sku_normalized LIKE 'abc123%'", $this->wpdb->queries[0] );
	}

	public function test_sku_probe_is_skipped_for_letter_only_queries(): void {
		$this->search( 'hazina' );

		$this->assertStringNotContainsString( 'sku_normalized LIKE', $this->wpdb->queries[0] );
	}

	public function test_ranking_includes_title_prefix_and_phrase_boosts(): void {
		$this->wpdb->handler = fn( string $sql, string $type ) => 'results' === $type ? array( $this->fakeRow( 1 ) ) : null;

		$this->search( 'hazina' );

		$sql = $this->wpdb->queries[0];
		$this->assertStringContainsString( "IF(title = 'hazina' OR title LIKE 'hazina %', 3, 0)", $sql );
		$this->assertStringContainsString( "IF(CONCAT(' ', title, ' ') LIKE '% hazina %', 4, 0)", $sql );
	}

	/**
	 * Regression test: searching "dog" must not give a title-prefix boost to
	 * a product titled "Dogo ..." just because the raw characters happen to
	 * line up — that product is a different, unrelated word, not a shopper
	 * typing a prefix of "dog". The boost requires the match be followed by
	 * a word boundary (nothing, or a space), not just any character.
	 *
	 * "dog" is 3 characters — below the default FULLTEXT minimum word
	 * length, so (like the real report this test is guarding against) it
	 * lands on the Tier 2 LIKE-fallback path, not Tier 1's FULLTEXT scoring.
	 */
	public function test_title_prefix_boost_requires_a_word_boundary(): void {
		$this->wpdb->handler = fn( string $sql, string $type ) => 'results' === $type ? array( $this->fakeRow( 1 ) ) : null;

		$this->search( 'dog' );

		$sql = $this->wpdb->queries[0];
		$this->assertStringContainsString( "IF(title = 'dog' OR title LIKE 'dog %', 20, 0)", $sql );
		$this->assertStringNotContainsString( "IF(title LIKE 'dog%', 20, 0)", $sql );
	}

	/**
	 * Regression test: the Tier 3 substring boost had the identical raw-LIKE
	 * flaw as title_prefix, just one tier further down — confirmed live on
	 * narukistore.com after the title_prefix fix alone still ranked "Dogo
	 * African Choker Necklace" first for "dog" over genuine dog-collar
	 * titles, because both then tied at 0 on title_prefix and the phrase
	 * boost (a plain '%dog%' substring match) still credited "Dogo" too,
	 * leaving an unrelated total_sales/alphabetical tiebreak to decide it.
	 */
	public function test_substring_boost_requires_a_word_boundary(): void {
		// Empty prefix pass forces the fall-through to Tier 3.
		$this->wpdb->handler = fn( string $sql, string $type ) =>
			'results' === $type ? ( str_contains( $sql, 'title_padded' ) ? array( $this->fakeRow( 1 ) ) : array() ) : null;

		$this->search( 'dog' );

		$this->assertCount( 2, $this->wpdb->queries );
		$fill = $this->wpdb->queries[1];
		$this->assertStringContainsString( "IF(title_padded LIKE '% dog %', 6, 0)", $fill );
		$this->assertStringNotContainsString( "IF(title LIKE '%dog%', 6, 0)", $fill );
	}

	public function test_synonyms_option_has_no_effect_on_like_groups(): void {
		update_option( 'wcs_synonyms', 'tee, top' );
		\WCS\Search\Query_Normalizer::flush_synonym_cache();

		$this->search( 'tee' );

		$sql = preg_replace( '/\s+/', ' ', $this->wpdb->queries[0] );
		$this->assertStringContainsString( "title LIKE 'tee%'", $sql );
		$this->assertStringNotContainsString( "title LIKE 'top%'", $sql );
	}

	// ── Stock filter ─────────────────────────────────────────────────────────

	public function test_out_of_stock_filter_adds_stock_clause_to_every_tier(): void {
		update_option( 'wcs_show_out_of_stock', 0 );

		$this->search( 'hazina' );

		// Every index-table query carries the clause (the vocabulary lookup
		// for typo correction is term-level and has no stock concept).
		foreach ( $this->wpdb->queries as $sql ) {
			if ( str_contains( $sql, 'wcs_search_index' ) ) {
				$this->assertStringContainsString( "stock_status = 'instock'", $sql );
			}
		}
	}

	// ── Result filter contract ───────────────────────────────────────────────

	public function test_wcs_search_results_filter_is_applied(): void {
		add_filter( 'wcs_search_results', static function ( array $results ): array {
			return array_slice( $results, 0, 1 );
		} );
		$rows = array( $this->fakeRow( 1 ), $this->fakeRow( 2 ) );
		$this->wpdb->handler = fn( string $sql, string $type ) => 'results' === $type ? $rows : null;

		$results = $this->search( 'hazina' );

		$this->assertCount( 1, $results );
	}

	// ── Robustness ───────────────────────────────────────────────────────────

	public function test_empty_query_returns_empty_without_touching_the_database(): void {
		$this->assertSame( array(), $this->search( '' ) );
		$this->assertSame( array(), $this->wpdb->queries );
	}

	public function test_like_metacharacters_in_query_are_escaped(): void {
		$this->search( '50% off' );

		// esc_like escapes % so it cannot act as a wildcard inside the pattern.
		$this->assertStringContainsString( '50\\\\%', implode( "\n", $this->wpdb->queries ) );
	}

	// ── Typo correction ──────────────────────────────────────────────────────

	public function test_zero_results_never_trigger_a_vocabulary_correction(): void {
		// Typo correction is a Pro feature — a zero-result query stays empty,
		// with no vocabulary lookup or re-run.
		$results = $this->search( 'lampp' );

		$this->assertSame( array(), $results );
		$vocab = array_filter( $this->wpdb->queries, static fn( $q ) => str_contains( $q, 'wcs_search_terms' ) );
		$this->assertSame( array(), $vocab );
	}


	// ── Tier fill, per-word ranking, and relaxation ─────────────────────────

	public function test_prefix_pass_tops_up_a_partial_fulltext_result_set(): void {
		// Regression: the prefix tier used to run only when FULLTEXT returned
		// NOTHING, so a FULLTEXT pass yielding 1 row against a requested 6
		// threw away five slots that real prefix matches could have filled.
		$this->wpdb->handler = function ( string $sql, string $type ) {
			if ( 'results' !== $type ) {
				return null;
			}
			return str_contains( $sql, 'MATCH' )
				? array( $this->fakeRow( 1 ) )
				: array( $this->fakeRow( 2 ), $this->fakeRow( 3 ) );
		};

		$results = $this->search( 'hazina' );

		$this->assertGreaterThanOrEqual( 2, count( $this->wpdb->queries ) );
		$fill = $this->wpdb->queries[1];
		$this->assertStringNotContainsString( 'MATCH', $fill );
		// Only the unfilled slots are requested, and rows already found are excluded.
		$this->assertStringContainsString( 'LIMIT 5', $fill );
		$this->assertStringContainsString( 'NOT IN (1)', $fill );
		// FULLTEXT rows keep their (better-ranked) position ahead of the fill.
		$this->assertSame( array( 1, 2, 3 ), array_column( $results, 'product_id' ) );
	}

	public function test_scanning_tier_stays_gated_on_fulltext_finding_nothing(): void {
		// The substring tier is the only one that scans. Tier 2 tops up partial
		// sets now, but letting this one do the same would turn a rare fallback
		// into a routine cost on a large catalog — so a partial FULLTEXT result
		// must never reach it.
		$this->wpdb->handler = function ( string $sql, string $type ) {
			if ( 'results' !== $type ) {
				return null;
			}
			return str_contains( $sql, 'MATCH' ) ? array( $this->fakeRow( 1 ) ) : array();
		};

		$this->search( 'hazina' );

		foreach ( $this->wpdb->queries as $sql ) {
			$this->assertStringNotContainsString( "content LIKE '%hazina%'", $sql );
		}
	}

	public function test_like_tiers_score_each_word_before_falling_back_to_popularity(): void {
		// Regression: every boost in these tiers was a WHOLE-QUERY one, so a
		// multi-word query where no product matched the entire string scored
		// every row identically and ordered by "total_sales DESC, title ASC" —
		// popularity and the alphabet rather than relevance. A query made
		// entirely of words below the FULLTEXT gate is ranked here and nowhere
		// else, so this was the only ranking those queries ever got.
		$this->search( 'lg tv' );

		$sql = $this->wpdb->queries[0];
		// A whole-word title hit outranks a mere title-prefix hit, per word.
		$this->assertStringContainsString( "IF(title_padded LIKE '% lg %', 8, 0)", $sql );
		$this->assertStringContainsString( "IF(title LIKE 'lg%', 4, 0)", $sql );
		$this->assertStringContainsString( "IF(title_padded LIKE '% tv %', 8, 0)", $sql );
		$this->assertStringContainsString( "IF(title LIKE 'tv%', 4, 0)", $sql );
		// Popularity still only breaks ties left over after relevance.
		$this->assertStringContainsString( 'DESC,', $sql );
		$this->assertStringContainsString( 'total_sales DESC, title ASC', $sql );
	}

	public function test_per_word_scoring_escapes_like_metacharacters(): void {
		$this->search( '50% off' );

		foreach ( $this->wpdb->queries as $sql ) {
			$this->assertStringNotContainsString( "LIKE '% 50% %'", $sql, 'an unescaped % would turn the score into a wildcard match' );
		}
	}

	public function test_zero_result_multiword_query_retries_with_any_word_matching(): void {
		// Regression: every tier requires EVERY word, so one word the catalog
		// does not contain took the whole query down with it — "hazina zzzz"
		// returned nothing even though "hazina" is a real product.
		// The boolean string is what distinguishes the two passes — "+" also
		// appears in every ranking expression, so it can't be the discriminator.
		$is_relaxed = static fn( string $sql ): bool =>
			str_contains( $sql, 'IN BOOLEAN MODE' ) && ! str_contains( $sql, "('+" );

		$this->wpdb->handler = function ( string $sql, string $type ) use ( $is_relaxed ) {
			if ( 'results' !== $type ) {
				return null;
			}
			return $is_relaxed( $sql ) ? array( $this->fakeRow( 1 ) ) : array();
		};

		$results = $this->search( 'hazina zzzz' );

		$this->assertCount( 1, $results );
		$relaxed = array_values( array_filter( $this->wpdb->queries, $is_relaxed ) );
		$this->assertNotEmpty( $relaxed );
		// Same ranking as the strict pass — a relaxed match is scored on the
		// same scale, it simply isn't required to match everything.
		$this->assertStringContainsString( 'MATCH(title) AGAINST', $relaxed[0] );
		$this->assertStringContainsString( 'LEAST(LOG(1 + total_sales), 3)', $relaxed[0] );
	}

	public function test_relaxation_is_skipped_when_the_strict_pass_found_anything(): void {
		$this->wpdb->handler = fn( string $sql, string $type ) => 'results' === $type ? array( $this->fakeRow( 1 ) ) : null;

		$this->search( 'hazina lamp' );

		foreach ( $this->wpdb->queries as $sql ) {
			if ( str_contains( $sql, 'IN BOOLEAN MODE' ) ) {
				$this->assertStringContainsString( "('+", $sql, 'the relaxed pass must not run when results already exist' );
			}
		}
	}

	public function test_relaxation_is_skipped_for_a_single_word_query(): void {
		// Relaxing one required word to optional changes nothing — it would be
		// a duplicate query for an identical result set.
		$this->search( 'hazina' );

		$fulltext = array_filter(
			$this->wpdb->queries,
			static fn( string $sql ): bool => str_contains( $sql, 'MATCH' )
		);
		$this->assertCount( 1, $fulltext );
	}


	public function test_any_word_like_relaxation_rescues_an_all_short_word_query(): void {
		// Regression: the FULLTEXT relaxation above is keyed off $boolean_parts,
		// which only exists inside the "if ( ! empty( $ft_words ) )" block — a
		// query made ENTIRELY of words below the parser's gate skips Tier 1
		// outright, so that relaxation never fires. Confirmed live on a real
		// catalog: "egg mix" (a product titled "Egg ...", another titled
		// "... Mix ...", but none containing both words) returned zero even
		// though products matching either word plainly existed.
		update_option( 'wcs_ft_parser', 'default' ); // gate = 4 chars; "ab"/"cd" both sit below it
		$this->wpdb->handler = function ( string $sql, string $type ) {
			if ( 'results' !== $type ) {
				return null;
			}
			// Only the OR-joined relaxation pass (word groups joined by OR, not
			// AND) finds anything.
			return str_contains( $sql, "') OR (" ) ? array( $this->fakeRow( 1 ) ) : array();
		};

		$results = $this->search( 'ab cd' );

		$this->assertCount( 1, $results );
		$relaxed = array_values( array_filter(
			$this->wpdb->queries,
			static fn( string $sql ): bool => str_contains( $sql, "') OR (" )
		) );
		$this->assertNotEmpty( $relaxed );
		$this->assertStringNotContainsString( 'MATCH', $relaxed[0], 'this is the LIKE-tier fallback, not FULLTEXT' );
		// Same per-word scoring as tiers 2/3, so a fuller match still outranks a partial one.
		$this->assertStringContainsString( "IF(title_padded LIKE '% ab %', 8, 0)", $relaxed[0] );
		$this->assertStringContainsString( "IF(title_padded LIKE '% cd %', 8, 0)", $relaxed[0] );
	}

	public function test_like_relaxation_is_skipped_when_an_earlier_tier_found_anything(): void {
		$this->wpdb->handler = fn( string $sql, string $type ) => 'results' === $type ? array( $this->fakeRow( 1 ) ) : null;

		$this->search( 'ab cd' );

		foreach ( $this->wpdb->queries as $sql ) {
			$this->assertStringNotContainsString( "') OR (", $sql, 'the OR-relaxation must not run once results already exist' );
		}
	}

	public function test_like_relaxation_is_skipped_for_a_single_word_query(): void {
		$this->search( 'ab' );

		foreach ( $this->wpdb->queries as $sql ) {
			$this->assertStringNotContainsString( "') OR (", $sql );
		}
	}

	public function test_like_relaxation_searches_content_so_short_words_are_not_stranded(): void {
		// The strict tiers already do this (Tier 3), but the relaxed OR pass
		// rebuilds its own WHERE clause from scratch — easy to accidentally
		// drop the content column and silently lose the one thing this pass
		// exists for: a short word that only ever appears in a description.
		update_option( 'wcs_ft_parser', 'default' );
		$this->wpdb->handler = fn( string $sql, string $type ) => 'results' === $type ? array() : null;

		$this->search( 'ab cd' );

		$relaxed = array_values( array_filter(
			$this->wpdb->queries,
			static fn( string $sql ): bool => str_contains( $sql, "') OR (" )
		) );
		$this->assertNotEmpty( $relaxed );
		$this->assertStringContainsString( "content LIKE '%ab%'", $relaxed[0] );
		$this->assertStringContainsString( "content LIKE '%cd%'", $relaxed[0] );
	}

	// ── Expensive-fallback-tier guard ────────────────────────────────────────

	/**
	 * A handler that makes every search-index tier find nothing, so
	 * query_database() always reaches the expensive fallback phase — while
	 * still delegating rate-limiter SQL to the real stateful simulation
	 * (Fake_WPDB::defaultRun()), so the guard's own allow/deny logic is
	 * exercised for real rather than assumed.
	 */
	private function alwaysEmptyHandler(): callable {
		return static function ( string $sql, string $type ) {
			if ( str_contains( $sql, 'wcs_rate_limits' ) ) {
				return Fake_WPDB::defaultRun( $sql, $type );
			}
			return 'results' === $type ? array() : null;
		};
	}

	public function test_expensive_fallback_runs_within_budget(): void {
		update_option( 'wcs_fallback_rate_limit_requests', 10 );
		$this->wpdb->handler = $this->alwaysEmptyHandler();

		$this->search( 'hazina lamp' ); // two FULLTEXT-eligible words, nothing matches

		$relaxed = array_filter(
			$this->wpdb->queries,
			static fn( string $sql ): bool => str_contains( $sql, 'IN BOOLEAN MODE' ) && ! str_contains( $sql, "('+" )
		);
		$this->assertNotEmpty( $relaxed, 'relaxation should run while the fallback budget is not spent' );
	}

	public function test_expensive_fallback_is_skipped_once_the_budget_is_spent(): void {
		// Regression target: a two-word garbage query that matches nothing
		// triggers the FULLTEXT relaxation pass AND the LIKE OR-relaxation
		// pass. That's exactly the shape a cache-busted abuse flood would send
		// (a fresh random string every request, so the 24h result cache never
		// helps). This guard caps how many times one visitor can trigger that
		// phase per window, separately from — and much stricter than — the
		// main per-IP limit.
		update_option( 'wcs_fallback_rate_limit_requests', 1 );
		update_option( 'wcs_fallback_rate_limit_window', 60 );
		$this->wpdb->handler = $this->alwaysEmptyHandler();

		$this->search( 'hazina lamp' ); // spends the one-request budget
		$this->wpdb->queries = array();
		$this->search( 'hazina lamp' ); // budget already spent

		foreach ( $this->wpdb->queries as $sql ) {
			// Tier 1's normal strict pass ('+...') always runs regardless of
			// this guard — only the RELAXED (no leading "+") pass is gated.
			$is_relaxed_fulltext = str_contains( $sql, 'IN BOOLEAN MODE' ) && ! str_contains( $sql, "('+" );
			$this->assertFalse( $is_relaxed_fulltext, 'no FULLTEXT relaxation pass once the budget is spent' );
			$this->assertStringNotContainsString( "') OR (", $sql, 'no LIKE OR-relaxation pass once the budget is spent' );
		}
	}

	public function test_expensive_fallback_guard_never_touches_the_normal_search_path(): void {
		// The guard is computed unconditionally near the top of the fallback
		// section, but must cost nothing extra for the overwhelmingly common
		// case: a query that resolves in tiers 0-3 and never reaches it.
		update_option( 'wcs_fallback_rate_limit_requests', 1 );
		$this->wpdb->handler = fn( string $sql, string $type ) => 'results' === $type ? array( $this->fakeRow( 1 ) ) : null;

		$this->search( 'hazina' );

		foreach ( $this->wpdb->queries as $sql ) {
			$this->assertStringNotContainsString( 'wcs_rate_limits', $sql, 'a resolved search should never consult the fallback-tier limiter at all' );
		}
	}

	public function test_expensive_fallback_guard_is_keyed_per_client_ip(): void {
		update_option( 'wcs_fallback_rate_limit_requests', 1 );
		$this->wpdb->handler = $this->alwaysEmptyHandler();

		$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
		$this->search( 'hazina lamp' ); // spends 203.0.113.5's budget

		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->wpdb->queries    = array();
		$this->search( 'hazina lamp' ); // a different visitor — budget untouched

		unset( $_SERVER['REMOTE_ADDR'] );

		$relaxed = array_filter(
			$this->wpdb->queries,
			static fn( string $sql ): bool => str_contains( $sql, 'IN BOOLEAN MODE' ) && ! str_contains( $sql, "('+" )
		);
		$this->assertNotEmpty( $relaxed, 'a different IP must have its own, unspent budget' );
	}
}
