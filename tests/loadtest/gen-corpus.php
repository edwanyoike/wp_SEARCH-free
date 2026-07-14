<?php
/**
 * Turbo Search for WooCommerce — Query Corpus Generator
 *
 * Reads real product titles from wcs_search_index and generates:
 *   tests/loadtest/corpus.json   — structured query list for k6
 *   tests/loadtest/corpus.txt    — one query per line (plain)
 *
 * Usage (run AFTER re-index is complete):
 *   wp eval-file tests/loadtest/gen-corpus.php \
 *       --path=/var/www/zuriancrafts.com \
 *       --allow-root
 */

// phpcs:disable -- load test script
global $wpdb;

$table = $wpdb->prefix . 'wcs_search_index';

// Check table exists
$exists = $wpdb->get_var( "SHOW TABLES LIKE '{$table}'" ); // phpcs:ignore
if ( $exists !== $table ) {
    WP_CLI::error( "Table {$table} not found. Has the plugin been activated and indexed?" );
}

$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore
WP_CLI::line( "Index table has {$count} rows." );

if ( $count === 0 ) {
    WP_CLI::error( 'Index is empty. Wait for re-index to finish first.' );
}

// ── Pull a sample of titles ──────────────────────────────────────────────────
$titles = $wpdb->get_col( // phpcs:ignore
    "SELECT title FROM {$table} ORDER BY RAND() LIMIT 2000"
);

$queries = [];

foreach ( $titles as $title ) {
    $words = preg_split( '/\s+/', trim( $title ) );
    $words = array_filter( $words, fn( $w ) => strlen( $w ) >= 3 );
    $words = array_values( $words );

    if ( empty( $words ) ) continue;

    // Single word (most common cache-warm query)
    $queries[] = [ 'q' => $words[ array_rand( $words ) ], 'type' => 'single' ];

    // Two-word phrase
    if ( count( $words ) >= 2 ) {
        shuffle( $words );
        $queries[] = [ 'q' => $words[0] . ' ' . $words[1], 'type' => 'phrase' ];
    }

    // Full title (exact match)
    if ( mt_rand( 0, 9 ) === 0 ) { // 10% sample
        $queries[] = [ 'q' => $title, 'type' => 'full_title' ];
    }
}

// ── Add stopword / LIKE-fallback triggers ───────────────────────────────────
$stopwords = [ 'a', 'i', 'in', 'by', 'is', 'ok', '1', '99', 'at', 'of' ];
foreach ( $stopwords as $sw ) {
    $queries[] = [ 'q' => $sw, 'type' => 'stopword' ];
}

// ── Add garbage / robustness queries ────────────────────────────────────────
$garbage = [ 'xzqq', '----', '!!!!', '     ', '123456789', 'αβγ' ];
foreach ( $garbage as $g ) {
    $queries[] = [ 'q' => $g, 'type' => 'garbage' ];
}

// ── Deduplicate & shuffle ────────────────────────────────────────────────────
$seen    = [];
$unique  = [];
foreach ( $queries as $entry ) {
    $key = strtolower( trim( $entry['q'] ) );
    if ( ! isset( $seen[ $key ] ) ) {
        $seen[ $key ] = true;
        $unique[]     = $entry;
    }
}
shuffle( $unique );

// ── Write output files ───────────────────────────────────────────────────────
$plugin_dir = dirname( dirname( __DIR__ ) ); // assumes script is in tests/loadtest/
$json_path  = __DIR__ . '/corpus.json';
$txt_path   = __DIR__ . '/corpus.txt';

file_put_contents( $json_path, json_encode( $unique, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) );
file_put_contents( $txt_path, implode( "\n", array_column( $unique, 'q' ) ) );

// ── Stats ───────────────────────────────────────────────────────────────────
$type_counts = array_count_values( array_column( $unique, 'type' ) );

WP_CLI::line( '' );
WP_CLI::success( count( $unique ) . " unique queries written." );
WP_CLI::line( "  single     : " . ( $type_counts['single']     ?? 0 ) );
WP_CLI::line( "  phrase     : " . ( $type_counts['phrase']     ?? 0 ) );
WP_CLI::line( "  full_title : " . ( $type_counts['full_title'] ?? 0 ) );
WP_CLI::line( "  stopword   : " . ( $type_counts['stopword']   ?? 0 ) );
WP_CLI::line( "  garbage    : " . ( $type_counts['garbage']    ?? 0 ) );
WP_CLI::line( '' );
WP_CLI::line( "  JSON → {$json_path}" );
WP_CLI::line( "  TXT  → {$txt_path}" );
WP_CLI::line( '' );
WP_CLI::line( 'Next step — run the load test:' );
WP_CLI::line( '  k6 run -e WP_URL=https://zuriancrafts.com \\' );
WP_CLI::line( '         -e WP_NONCE=$(wp eval \'echo wp_create_nonce("wp_rest");\' --path=/var/www/zuriancrafts.com --allow-root) \\' );
WP_CLI::line( '         tests/loadtest/s1_warm_cache.js' );
