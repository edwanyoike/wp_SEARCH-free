<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WCS\Search\Query_Normalizer;

final class QueryNormalizerTest extends TestCase {

	protected function setUp(): void {
		wcs_tests_reset();
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

	// ── tokenize() ──────────────────────────────────────────────────────────

	public function test_tokenize_splits_and_drops_empties(): void {
		$this->assertSame( array( 'abc', '123' ), Query_Normalizer::tokenize( 'abc 123' ) );
		$this->assertSame( array(), Query_Normalizer::tokenize( '' ) );
	}

	// ── cache_key() ─────────────────────────────────────────────────────────

	public function test_cache_key_shape_and_determinism(): void {
		$key = Query_Normalizer::cache_key( 'abc 123', 'USD', 7 );
		$this->assertSame( 'wcs_v7_USD_' . md5( 'abc 123' ), $key );
		$this->assertSame( $key, Query_Normalizer::cache_key( 'abc 123', 'USD', 7 ) );
	}

	public function test_cache_key_varies_by_query_currency_and_version(): void {
		$base = Query_Normalizer::cache_key( 'abc', 'USD', 1 );
		$this->assertNotSame( $base, Query_Normalizer::cache_key( 'abd', 'USD', 1 ) );
		$this->assertNotSame( $base, Query_Normalizer::cache_key( 'abc', 'EUR', 1 ) );
		$this->assertNotSame( $base, Query_Normalizer::cache_key( 'abc', 'USD', 2 ) );
	}

	// ── Synonyms (Pro feature — always inert in this edition) ────────────────

	public function test_expand_ignores_synonym_option_and_returns_typed_word_only(): void {
		update_option( 'wcs_synonyms', "sofa, couch, settee\ntee, tshirt" );
		Query_Normalizer::flush_synonym_cache();

		$this->assertSame( array( 'sofa' ), Query_Normalizer::expand( 'sofa' ) );
		$this->assertSame( array( 'tshirt' ), Query_Normalizer::expand( 'tshirt' ) );
	}

	public function test_synonym_groups_filter_has_no_effect(): void {
		add_filter( 'wcs_synonym_groups', static function ( array $groups ): array {
			$groups[] = array( 'trousers', 'pants' );
			return $groups;
		} );
		Query_Normalizer::flush_synonym_cache();

		$this->assertSame( array( 'pants' ), Query_Normalizer::expand( 'pants' ) );
	}

	// ── Automatic word variants (Pro feature — always inert in this edition) ─

	public function test_word_variants_always_empty(): void {
		$this->assertSame( array(), Query_Normalizer::word_variants( 'lamps' ) );
		$this->assertSame( array(), Query_Normalizer::word_variants( 'lamp' ) );
		$this->assertSame( array(), Query_Normalizer::word_variants( 'boxes' ) );
		$this->assertSame( array(), Query_Normalizer::word_variants( 'iphone14' ) );
		$this->assertSame( array( 'lamp' ), Query_Normalizer::expand( 'lamp' ) );
	}

	public function test_short_words_and_double_s_words_get_no_variants(): void {
		$this->assertSame( array(), Query_Normalizer::word_variants( 'tv' ) );
		$this->assertSame( array(), Query_Normalizer::word_variants( 'glass' ) );
	}

	public function test_normalize_sku_collapses_punctuation_variants(): void {
		$this->assertSame( 'abc123', Query_Normalizer::normalize_sku( 'ABC-123' ) );
		$this->assertSame( 'abc123', Query_Normalizer::normalize_sku( 'abc 123' ) );
		$this->assertSame( 'abc123', Query_Normalizer::normalize_sku( 'abc123' ) );
		$this->assertSame( 'vlred', Query_Normalizer::normalize_sku( 'VL_RED' ) );
		$this->assertSame( '', Query_Normalizer::normalize_sku( '---' ) );
	}

	public function test_vocabulary_terms_extracts_letterful_tokens_only(): void {
		$this->assertSame(
			array( 'red', 'lamp' ),
			Query_Normalizer::vocabulary_terms( 'Red LAMP X1 123 ab' )
		);
	}

	public function test_vocabulary_terms_do_not_split_across_comma_suffixed_duplicates(): void {
		// Regression (found live on narukistore.com): titles like
		// "ZAHURI African Zulu Necklace, Beaded Tribal necklace" must
		// produce a single "necklace" term, not "necklace" and "necklace,".
		$this->assertSame(
			array( 'necklace', 'beaded', 'tribal', 'necklace' ),
			Query_Normalizer::vocabulary_terms( 'Necklace, Beaded Tribal necklace' )
		);
	}

	public function test_digit_tokens_get_no_boundary_split(): void {
		$this->assertSame( array(), Query_Normalizer::word_variants( 'iphone14' ) );
		$this->assertSame( array(), Query_Normalizer::word_variants( 'mk2' ) );
	}
}
