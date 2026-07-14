<?php
/**
 * Turbo Search for WooCommerce — Seed Cleanup
 *
 * Deletes all products with SKU prefix "LT-" (inserted by seed-products.php).
 * Safe to run multiple times.
 *
 * Usage:
 *   wp eval-file tests/loadtest/cleanup-seed.php \
 *       --path=/var/www/zuriancrafts.com \
 *       --allow-root \
 *       [dry]          ← pass 'dry' as first arg to preview only
 */

// phpcs:disable -- load test script
$DRY_RUN = isset( $args[0] ) && $args[0] === 'dry';
$SKU_PREFIX = 'LT-';

global $wpdb;

// Find all post IDs whose SKU starts with LT-
$ids = $wpdb->get_col( $wpdb->prepare( // phpcs:ignore
    "SELECT post_id FROM {$wpdb->postmeta}
     WHERE meta_key = '_sku' AND meta_value LIKE %s",
    $wpdb->esc_like( $SKU_PREFIX ) . '%'
) );

$count = count( $ids );
WP_CLI::line( "Found {$count} seeded products (SKU prefix: {$SKU_PREFIX})." );

if ( $DRY_RUN ) {
    WP_CLI::success( 'Dry run — no products deleted.' );
    exit( 0 );
}

if ( $count === 0 ) {
    WP_CLI::success( 'Nothing to clean up.' );
    exit( 0 );
}

$progress = WP_CLI\Utils\make_progress_bar( "Deleting {$count} products", $count );
$deleted  = 0;

foreach ( $ids as $post_id ) {
    // wp_delete_post( id, true ) permanently deletes (no trash)
    if ( wp_delete_post( (int) $post_id, true ) ) {
        $deleted++;
    }
    $progress->tick();
}

$progress->finish();
WP_CLI::success( "Deleted {$deleted} seeded products." );

// Trigger a re-index to sync
if ( function_exists( 'as_schedule_single_action' ) ) {
    as_schedule_single_action( time(), 'wcs_process_batch', [ 'offset' => 0 ], 'turbo-search-for-woocommerce' );
    WP_CLI::success( 'Re-index queued to sync the search index.' );
}
