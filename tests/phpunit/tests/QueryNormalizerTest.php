<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use OTSW\Search\Query_Normalizer;

final class QueryNormalizerTest extends TestCase {

	protected function setUp(): void {
		otsw_tests_reset();
	}

	// ── normalize() ─────────────────────────────────────────────────────────

	public function test_hyphens_split_into_tokens_instead_of_merging(): void {
		$this->assertSame( 'abc 123', Query_Normalizer::normalize( 'ABC-123' ) );
		$this->assertSame( 't shirt', Query_Normalizer::normalize( 't-shirt' ) );
	}

	public function test_apostrophes_split_instead_of_merging(): void {
		$this->assertSame( 'men s t shirt', Query_Normalizer::normalize( "Men's  T-Shirt" ) );
	}

	public function test_fulltext_boolean_operators_are_neutralized(): void {
		$this->assertSame( 'evil boolean op', Query_Normalizer::normalize( '+evil* (boolean) "op"' ) );
		$this->assertSame( 'a b', Query_Normalizer::normalize( 'a~<>@b' ) );
	}

	public function test_sentence_punctuation_splits_into_tokens(): void {
		// Regression: a title like "Necklace, Beaded Tribal necklace" must
		// tokenize "necklace," and "necklace" identically, or vocabulary
		// frequency for the same real word splits across two entries.
		$this->assertSame( 'necklace beaded', Query_Normalizer::normalize( 'Necklace, Beaded' ) );
		$this->assertSame( 'a b', Query_Normalizer::normalize( 'a.b' ) );
		$this->assertSame( 'a b', Query_Normalizer::normalize( 'a:b' ) );
		$this->assertSame( 'a b', Query_Normalizer::normalize( 'a;b' ) );
		$this->assertSame( 'a b', Query_Normalizer::normalize( 'a/b' ) );
		$this->assertSame( 'a b', Query_Normalizer::normalize( 'a\\b' ) );
		$this->assertSame( 'a b', Query_Normalizer::normalize( 'a&b' ) );
		$this->assertSame( 'wow', Query_Normalizer::normalize( 'wow!' ) );
		$this->assertSame( 'really', Query_Normalizer::normalize( 'really?' ) );
	}

	public function test_unicode_lowercasing_and_whitespace_collapse(): void {
		$this->assertSame( 'café été', Query_Normalizer::normalize( '  Café   Été ' ) );
	}

	public function test_length_capped_at_max_length(): void {
		$long = str_repeat( 'a', 500 );
		$this->assertSame( Query_Normalizer::MAX_LENGTH, mb_strlen( Query_Normalizer::normalize( $long ) ) );
	}

	public function test_empty_and_punctuation_only_input_normalizes_to_empty(): void {
		$this->assertSame( '', Query_Normalizer::normalize( '' ) );
		$this->assertSame( '', Query_Normalizer::normalize( '+-*"' ) );
	}

	// ── normalize_title() ───────────────────────────────────────────────────
	// Same punctuation/whitespace/case rules as normalize() (indexed titles
	// must match how a query normalizes to make the exact-title/title-prefix/
	// phrase boosts in Search_Handler work — see class-indexer.php's
	// title_normalized/title_padded columns), but MAX_LENGTH must NOT apply:
	// that cap bounds query/cache-key cost, not stored index content.

	public function test_normalize_title_matches_normalize_for_ordinary_input(): void {
		$this->assertSame( Query_Normalizer::normalize( 'ABC-123' ), Query_Normalizer::normalize_title( 'ABC-123' ) );
		$this->assertSame( Query_Normalizer::normalize( "Men's  T-Shirt" ), Query_Normalizer::normalize_title( "Men's  T-Shirt" ) );
	}

	public function test_normalize_title_splits_hyphens_apostrophes_and_slashes(): void {
		$this->assertSame( 't shirt', Query_Normalizer::normalize_title( 'T-Shirt' ) );
		$this->assertSame( 'men s jacket', Query_Normalizer::normalize_title( "Men's Jacket" ) );
		$this->assertSame( 'model a b', Query_Normalizer::normalize_title( 'Model A/B' ) );
	}

	public function test_normalize_title_collapses_repeated_whitespace(): void {
		$this->assertSame( 'wide gap lamp', Query_Normalizer::normalize_title( "Wide   Gap\tLamp" ) );
	}

	public function test_normalize_title_is_not_length_capped(): void {
		$long = str_repeat( 'a ', 100 ) . 'end'; // 300 chars, well past MAX_LENGTH
		$normalized = Query_Normalizer::normalize_title( $long );
		$this->assertGreaterThan( Query_Normalizer::MAX_LENGTH, mb_strlen( $normalized ) );
		$this->assertStringEndsWith( 'end', $normalized );
	}

	// ── tokenize() ──────────────────────────────────────────────────────────

	public function test_tokenize_splits_and_drops_empties(): void {
		$this->assertSame( array( 'abc', '123' ), Query_Normalizer::tokenize( 'abc 123' ) );
		$this->assertSame( array(), Query_Normalizer::tokenize( '' ) );
	}

	// ── remove_stopwords() ──────────────────────────────────────────────────

	public function test_stopwords_are_dropped_from_multi_word_queries(): void {
		// Regression: every tier treats a word as mandatory, so these used to
		// return nothing at all — confirmed live, "bacon" found a product while
		// "bacon for"/"bacon with"/"the bacon" found none.
		$this->assertSame(
			array( 'bacon' ),
			Query_Normalizer::remove_stopwords( array( 'bacon', 'for' ) )
		);
		$this->assertSame(
			array( 'bacon' ),
			Query_Normalizer::remove_stopwords( array( 'the', 'bacon' ) )
		);
		$this->assertSame(
			array( 'red', 'shoes', 'summer' ),
			Query_Normalizer::remove_stopwords( array( 'red', 'shoes', 'for', 'the', 'summer' ) )
		);
	}

	public function test_stopwords_cover_the_full_default_innodb_ft_list(): void {
		// Regression coverage for Finding C (2026-09-05 algorithm audit):
		// these six were missing from STOPWORDS despite being in MySQL/
		// MariaDB's documented default INNODB_FT_DEFAULT_STOPWORD list
		// (confirmed live via INFORMATION_SCHEMA.INNODB_FT_DEFAULT_STOPWORD).
		// Omitting any of them lets it become a required `+word` BOOLEAN MODE
		// term the FULLTEXT index never tokenized, making an otherwise
		// ordinary multi-word query unmatchable by the strict FULLTEXT pass.
		$this->assertSame(
			array( 'cafe' ),
			Query_Normalizer::remove_stopwords( array( 'cafe', 'de' ) )
		);
		foreach ( array( 'com', 'de', 'en', 'la', 'und', 'www' ) as $word ) {
			$this->assertContains( $word, Query_Normalizer::STOPWORDS, "\"$word\" must be in the default InnoDB FULLTEXT stopword list" );
		}
	}

	public function test_short_non_function_words_are_never_treated_as_stopwords(): void {
		// Brand/size tokens are short but highly selective — dropping them
		// would be far worse than dropping "for".
		$this->assertSame(
			array( 'lg', 'tv' ),
			Query_Normalizer::remove_stopwords( array( 'lg', 'tv' ) )
		);
		$this->assertSame(
			array( '3m', 'tape' ),
			Query_Normalizer::remove_stopwords( array( '3m', 'tape' ) )
		);
	}

	public function test_a_single_word_query_is_never_filtered(): void {
		// "the" alone must still search for "the" — an empty word list reads
		// as "no query" and returns nothing.
		$this->assertSame( array( 'the' ), Query_Normalizer::remove_stopwords( array( 'the' ) ) );
	}

	public function test_an_all_stopword_query_keeps_every_word(): void {
		$this->assertSame(
			array( 'the', 'and' ),
			Query_Normalizer::remove_stopwords( array( 'the', 'and' ) )
		);
	}

	public function test_surviving_word_order_is_preserved_and_reindexed(): void {
		// query_database() wildcards the LAST word (the one still being typed)
		// by numeric index, so gaps or reordering here would misplace it.
		$this->assertSame(
			array( 'blue', 'cotton', 'shirt' ),
			Query_Normalizer::remove_stopwords( array( 'blue', 'and', 'cotton', 'shirt' ) )
		);
	}

	public function test_stopword_list_is_filterable(): void {
		$filter = static fn(): array => array( 'bacon' );
		add_filter( 'otsw_stopwords', $filter );
		$this->assertSame(
			array( 'pieces' ),
			Query_Normalizer::remove_stopwords( array( 'bacon', 'pieces' ) )
		);
		remove_filter( 'otsw_stopwords', $filter );
	}

	// ── cache_key() ─────────────────────────────────────────────────────────

	public function test_cache_key_shape_and_determinism(): void {
		$key = Query_Normalizer::cache_key( 'abc 123', 'USD', 7 );
		$this->assertSame( 'otsw_v7_' . Query_Normalizer::site_scope() . '_USD_' . md5( 'abc 123' ), $key );
		$this->assertSame( $key, Query_Normalizer::cache_key( 'abc 123', 'USD', 7 ) );
	}

	public function test_cache_key_varies_by_query_currency_and_version(): void {
		$base = Query_Normalizer::cache_key( 'abc', 'USD', 1 );
		$this->assertNotSame( $base, Query_Normalizer::cache_key( 'abd', 'USD', 1 ) );
		$this->assertNotSame( $base, Query_Normalizer::cache_key( 'abc', 'EUR', 1 ) );
		$this->assertNotSame( $base, Query_Normalizer::cache_key( 'abc', 'USD', 2 ) );
	}

	/**
	 * Regression: cache_key() feeds Search_Handler's and the MU cache-bypass
	 * plugin's raw apcu_fetch()/apcu_store() calls, which share one flat key
	 * space across every site a PHP-FPM pool serves (see site_scope()'s
	 * docblock) — unlike get_transient(), which WordPress itself namespaces
	 * per blog. Two sites whose cache version, currency, and query happened
	 * to match could otherwise read each other's cached product rows
	 * straight out of shared memory.
	 */
	public function test_cache_key_varies_by_site(): void {
		$GLOBALS['otsw_test_blog_id'] = 1;
		$site1 = Query_Normalizer::cache_key( 'abc', 'USD', 1 );

		$GLOBALS['otsw_test_blog_id'] = 2;
		$site2 = Query_Normalizer::cache_key( 'abc', 'USD', 1 );

		$this->assertNotSame( $site1, $site2 );
	}

	// ── Word expansion (synonyms/variants are a Pro feature — always the exact
	// typed word only in this edition) ────────────────────────────────────────

	public function test_expand_returns_only_the_typed_word(): void {
		$this->assertSame( array( 'sofa' ), Query_Normalizer::expand( 'sofa' ) );
		$this->assertSame( array( 'lamp' ), Query_Normalizer::expand( 'lamp' ) );
		$this->assertSame( array( 'iphone14' ), Query_Normalizer::expand( 'iphone14' ) );
	}

	public function test_normalize_sku_collapses_punctuation_variants(): void {
		$this->assertSame( 'abc123', Query_Normalizer::normalize_sku( 'ABC-123' ) );
		$this->assertSame( 'abc123', Query_Normalizer::normalize_sku( 'abc 123' ) );
		$this->assertSame( 'abc123', Query_Normalizer::normalize_sku( 'abc123' ) );
		$this->assertSame( 'vlred', Query_Normalizer::normalize_sku( 'VL_RED' ) );
		$this->assertSame( '', Query_Normalizer::normalize_sku( '---' ) );
	}

}
