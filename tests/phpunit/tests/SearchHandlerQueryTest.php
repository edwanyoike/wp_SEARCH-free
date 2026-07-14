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

		$this->assertCount( 1, $this->wpdb->queries );
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

	public function test_like_tiers_order_by_popularity_then_title(): void {
		$this->search( 'ab' );

		$this->assertStringContainsString( 'ORDER BY total_sales DESC, title ASC', $this->wpdb->queries[0] );
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
		$this->assertStringContainsString( "IF(title LIKE 'hazina%', 3, 0)", $sql );
		$this->assertStringContainsString( "IF(title LIKE '%hazina%', 4, 0)", $sql );
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

}
