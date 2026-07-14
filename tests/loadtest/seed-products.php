<?php
// phpcs:disable -- load test script, not part of plugin
/**
 * Turbo Search for WooCommerce — Load Test Product Seeder
 *
 * Usage:
 *   wp eval-file tests/loadtest/seed-products.php \
 *       --path=/var/www/zuriancrafts.com \
 *       --allow-root \
 *       [--target=10000] [--batch=100] [--dry-run]
 *
 * Flags (passed as WP-CLI --user-data via extra args):
 *   TARGET  - how many total published products you want (default 10000)
 *   BATCH   - insert chunk size per iteration (default 100)
 *   DRY_RUN - print plan only, do not insert
 */

// ── Config ──────────────────────────────────────────────────────────────────
$TARGET  = (int) ( $args[0] ?? 10000 );   // wp eval-file ... 10000
$BATCH   = (int) ( $args[1] ?? 100  );
$DRY_RUN = isset( $args[2] ) && $args[2] === 'dry';

// ── Vocabulary ───────────────────────────────────────────────────────────────
$adjectives = [
    'Handcrafted', 'Artisan', 'Premium', 'Organic', 'Natural', 'Elegant',
    'Rustic', 'Vintage', 'Modern', 'Classic', 'Luxury', 'Bohemian',
    'Minimalist', 'Heritage', 'Bespoke', 'Woven', 'Hand-painted', 'Carved',
    'Beaded', 'Embroidered', 'Recycled', 'Sustainable', 'Fair-trade',
    'Kenyan', 'African', 'Traditional', 'Contemporary', 'Exclusive',
    'Limited', 'Small-batch', 'Signed', 'Numbered', 'One-of-a-kind',
];

$materials = [
    'Sisal', 'Leather', 'Soapstone', 'Mahogany', 'Teak', 'Bamboo',
    'Cotton', 'Linen', 'Silk', 'Wool', 'Jute', 'Raffia', 'Ebony',
    'Bronze', 'Brass', 'Copper', 'Ceramic', 'Terracotta', 'Glass',
    'Resin', 'Acacia', 'Olive wood', 'Coconut shell', 'Bone', 'Horn',
    'Beads', 'Wire', 'Recycled plastic', 'Banana fibre', 'Palm leaf',
];

$products = [
    'Basket', 'Bag', 'Tote', 'Clutch', 'Wallet', 'Purse',
    'Bowl', 'Plate', 'Vase', 'Pot', 'Platter', 'Cup', 'Mug',
    'Figurine', 'Sculpture', 'Mask', 'Carving', 'Statue', 'Totem',
    'Necklace', 'Bracelet', 'Earrings', 'Ring', 'Anklet', 'Pendant',
    'Cushion', 'Throw', 'Runner', 'Placemat', 'Coaster', 'Mat',
    'Frame', 'Mirror', 'Lamp', 'Candle holder', 'Key holder', 'Wall art',
    'Diary', 'Notebook', 'Box', 'Tray', 'Rack', 'Stand',
    'Sandals', 'Slippers', 'Belt', 'Hat', 'Scarf', 'Wrap',
    'Toy', 'Doll', 'Game board', 'Puzzle', 'Mobile', 'Wind chime',
];

$colours = [
    'Red', 'Blue', 'Green', 'Yellow', 'Brown', 'Black', 'White',
    'Orange', 'Purple', 'Teal', 'Navy', 'Terracotta', 'Indigo',
    'Ochre', 'Cream', 'Sand', 'Charcoal', 'Gold', 'Silver',
    'Multi-colour', 'Earth-tone', 'Pastel', 'Monochrome',
];

$categories_map = [
    'Home Decor'     => 0,
    'Jewellery'      => 0,
    'Bags & Purses'  => 0,
    'Clothing'       => 0,
    'Kitchen'        => 0,
    'Art & Sculpture'=> 0,
    'Toys & Games'   => 0,
    'Stationery'     => 0,
];

// ── Helpers ──────────────────────────────────────────────────────────────────
function rand_price( float $min, float $max ): float {
    return round( mt_rand( (int)( $min * 100 ), (int)( $max * 100 ) ) / 100, 2 );
}

function pick( array $arr ): string {
    return $arr[ array_rand( $arr ) ];
}

function make_title( array $adj, array $mat, array $prod, array $col ): string {
    $pattern = mt_rand( 0, 3 );
    switch ( $pattern ) {
        case 0: return pick($adj) . ' ' . pick($mat) . ' ' . pick($prod);
        case 1: return pick($col) . ' ' . pick($mat) . ' ' . pick($prod);
        case 2: return pick($adj) . ' ' . pick($col) . ' ' . pick($prod);
        default: return pick($adj) . ' ' . pick($mat) . ' ' . pick($col) . ' ' . pick($prod);
    }
}

// ── Ensure categories exist ───────────────────────────────────────────────────
foreach ( array_keys( $categories_map ) as $cat_name ) {
    $term = get_term_by( 'name', $cat_name, 'product_cat' );
    if ( $term ) {
        $categories_map[ $cat_name ] = (int) $term->term_id;
    } else {
        $result = wp_insert_term( $cat_name, 'product_cat' );
        $categories_map[ $cat_name ] = is_wp_error( $result ) ? 0 : (int) $result['term_id'];
    }
}
$cat_ids = array_values( array_filter( $categories_map ) );

// ── Count existing products ───────────────────────────────────────────────────
$existing = (int) wp_count_posts( 'product' )->publish;
$to_seed  = max( 0, $TARGET - $existing );

WP_CLI::line( "┌─ Turbo Search for WooCommerce — Product Seeder ─────────────────────" );
WP_CLI::line( "│  Existing published products : {$existing}" );
WP_CLI::line( "│  Target                      : {$TARGET}" );
WP_CLI::line( "│  Will seed                   : {$to_seed}" );
WP_CLI::line( "│  Batch size                  : {$BATCH}" );
WP_CLI::line( "│  Dry run                     : " . ( $DRY_RUN ? 'YES' : 'NO' ) );
WP_CLI::line( "└───────────────────────────────────────────────────────" );

if ( $DRY_RUN || $to_seed <= 0 ) {
    $to_seed <= 0
        ? WP_CLI::success( "Already at or above target. Nothing to seed." )
        : WP_CLI::success( "Dry run complete. No products inserted." );
    exit( 0 );
}

// ── Disable hooks that slow down bulk inserts ─────────────────────────────────
// Temporarily unhook WCS indexer so it doesn't fire on every save;
// we'll trigger a bulk re-index at the end instead.
remove_all_actions( 'save_post_product' );
remove_all_actions( 'woocommerce_update_product' );
remove_all_actions( 'woocommerce_new_product' );

// ── Seed loop ────────────────────────────────────────────────────────────────
$inserted   = 0;
$failed     = 0;
$batches    = (int) ceil( $to_seed / $BATCH );
$sku_prefix = 'LT-'; // loadtest prefix — easy to identify & delete later

$progress = WP_CLI\Utils\make_progress_bar(
    "Seeding {$to_seed} products",
    $to_seed
);

for ( $b = 0; $b < $batches; $b++ ) {
    $batch_count = min( $BATCH, $to_seed - $inserted );

    for ( $i = 0; $i < $batch_count; $i++ ) {
        $title    = make_title( $adjectives, $materials, $products, $colours );
        $sku      = $sku_prefix . strtoupper( substr( md5( uniqid( '', true ) ), 0, 8 ) );
        $price    = rand_price( 150, 25000 );          // KES 150 – 25,000
        $sale     = mt_rand( 0, 4 ) === 0              // 20% have a sale price
                    ? rand_price( $price * 0.6, $price * 0.85 )
                    : '';
        $stock    = mt_rand( 0, 9 ) < 8 ? 'instock' : 'outofstock'; // 80/20
        $cat_id   = $cat_ids[ array_rand( $cat_ids ) ];

        $product = new WC_Product_Simple();
        $product->set_name( $title );
        $product->set_sku( $sku );
        $product->set_regular_price( (string) $price );
        if ( $sale !== '' ) {
            $product->set_sale_price( (string) $sale );
        }
        $product->set_manage_stock( false );
        $product->set_stock_status( $stock );
        $product->set_status( 'publish' );
        $product->set_category_ids( [ $cat_id ] );
        $product->set_description(
            "A {$title} crafted with care. SKU: {$sku}. Perfect for gifting or home use."
        );
        $product->set_short_description( "Quality {$title} made by Kenyan artisans." );

        $id = $product->save();

        if ( $id && ! is_wp_error( $id ) ) {
            $inserted++;
        } else {
            $failed++;
        }

        $progress->tick();
    }

    // Yield to DB — prevents connection timeout on large seeds
    usleep( 5000 ); // 5 ms pause per batch
}

$progress->finish();

WP_CLI::line( '' );
WP_CLI::success( "Inserted {$inserted} products. Failed: {$failed}." );

// ── Trigger WCS full re-index ─────────────────────────────────────────────────
WP_CLI::line( '' );
WP_CLI::line( '── Scheduling Turbo Search for WooCommerce re-index... ──' );

if ( function_exists( 'as_schedule_single_action' ) ) {
    // Kick off from offset 0; Action Scheduler will chain subsequent batches
    as_schedule_single_action( time(), 'wcs_process_batch', [ 'offset' => 0 ], 'turbo-search-for-woocommerce' );
    WP_CLI::success( 'Re-index job queued in Action Scheduler.' );
    WP_CLI::line( '  Monitor progress: wp action-scheduler list --status=pending --group=turbo-search-for-woocommerce --path=/var/www/zuriancrafts.com --allow-root' );
    WP_CLI::line( '  Or check admin: WooCommerce → Status → Scheduled Actions' );
} else {
    WP_CLI::warning( 'Action Scheduler not found. Trigger re-index manually from Admin → Turbo Search for WooCommerce.' );
}

WP_CLI::line( '' );
WP_CLI::line( '── When re-index finishes, generate the query corpus: ──' );
WP_CLI::line( "  wp eval-file tests/loadtest/gen-corpus.php --path=/var/www/zuriancrafts.com --allow-root" );
