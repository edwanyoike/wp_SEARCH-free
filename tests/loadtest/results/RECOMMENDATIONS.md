# WP Fast Search — Architectural & Performance Recommendations

Following the v1.0.9 load testing results, the plugin's query processing and caching models proved robust. However, under high-concurrency scenarios, system performance is constrained by core WordPress bootstrap overhead and database read/write concurrency. 

The following recommendations outline code-level enhancements to address these bottlenecks.

---

## 1. Short-Circuiting WordPress Bootstrap (Cached Search Bypass)

### Problem
Even when a search query is cached in Redis, the request goes through the standard `wp-json/wcs/v1/search` REST API route. This forces WordPress to boot fully, load all active plugins, parse the active theme, and run routing rules, introducing **~110ms–190ms** of overhead before returning the cached response.

### Solution
Implement an early interceptor hook (or Must-Use Plugin script) that checks Redis directly and exits before the full WordPress application bootstraps.

### Conceptual Implementation
Add the following logic early in the execution lifecycle (e.g., in a custom loader or hooked into `setup_theme` / `plugins_loaded` at priority 0):

```php
/**
 * Short-circuits the WordPress lifecycle for cached search requests.
 */
function wcs_short_circuit_cached_search() {
    // Check if it is a search API request
    if ( ! isset( $_GET['wcs_fast_search'] ) || empty( $_GET['q'] ) ) {
        return;
    }

    $query = sanitize_text_field( $_GET['q'] );
    $query = str_replace( array( '*', '+', '-', '<', '>', '~', '@', '(', ')', '"', "'" ), '', $query );
    $query = trim( $query );

    if ( empty( $query ) ) {
        wp_send_json( array() );
    }

    // Leverage the object cache directly if available
    $cache_version = (int) get_option( 'wcs_cache_version', 1 );
    $cache_key     = 'wcs_v' . $cache_version . '_' . md5( $query );

    // Check transient directly bypassing full rest server load
    $results = get_transient( $cache_key );
    if ( false !== $results ) {
        header( 'Content-Type: application/json; charset=utf-8' );
        echo wp_json_encode( $results );
        exit;
    }
}
add_action( 'plugins_loaded', 'wcs_short_circuit_cached_search', 0 );
```

---

## 2. Mutex Caching (Stampede / Thundering Herd Protection)

### Problem
During Scenario 3 (Thundering Herd), a sudden spike in traffic for a cold query causes multiple concurrent requests to detect a cache miss at the same millisecond. This sends hundreds of duplicate queries to the MySQL server before the first request finishes and caches the response.

### Solution
Implement a mutex lock using a short-lived key in the object cache. Only the thread that successfully acquires the lock queries the database; other threads wait and retrieve the result from the cache.

### Conceptual Implementation
Modify the search request handler:

```php
public static function handle_request( \WP_REST_Request $request ): \WP_REST_Response {
    $query = $request->get_param( 'q' );
    // [Sanitization Logic...]

    $cache_version = (int) get_option( 'wcs_cache_version', 1 );
    $cache_key     = 'wcs_v' . $cache_version . '_' . md5( $query );
    
    // Attempt to get from Cache
    $results = get_transient( $cache_key );
    if ( false !== $results ) {
        return rest_ensure_response( $results );
    }

    // Cache miss — acquire a temporary lock
    $lock_key = 'wcs_lock_' . md5( $query );
    $is_locked = wp_cache_add( $lock_key, '1', 'wcs_search', 5 ); // Lock for 5 seconds

    if ( ! $is_locked ) {
        // Lock acquisition failed — another request is building the cache.
        // Wait and poll the transient cache for up to 1 second (10 attempts)
        for ( $i = 0; $i < 10; $i++ ) {
            usleep( 100000 ); // Sleep 100ms
            $results = get_transient( $cache_key );
            if ( false !== $results ) {
                return rest_ensure_response( $results );
            }
        }
        // Fallback: if the builder fails or times out, proceed to query database directly
    }

    // Current request is the builder
    $results = self::query_database( $query );
    set_transient( $cache_key, $results, DAY_IN_SECONDS );

    // Release lock
    wp_cache_delete( $lock_key, 'wcs_search' );

    return rest_ensure_response( $results );
}
```

---

## 3. Event-Driven Incremental Indexing

### Problem
Currently, the search database table is rebuilt using batch updates via cron schedules or Action Scheduler. Under concurrent search traffic, database writes from the indexer add a measurable latency overhead.

### Solution
Reduce write pressure by indexing products in real-time as they are created, updated, or deleted, eliminating the need for periodic full rebuilds.

### Conceptual Implementation
Hook into WooCommerce-specific and WordPress post lifecycle actions:

```php
class Indexer_Hooks {
    public static function init() {
        // Run on product save/update
        add_action( 'woocommerce_update_product', array( __CLASS__, 'on_product_save' ), 10, 1 );
        add_action( 'save_post_product', array( __CLASS__, 'on_post_save' ), 10, 3 );
        
        // Run on product deletion
        add_action( 'wp_trash_post', array( __CLASS__, 'on_product_trash' ), 10, 1 );
        add_action( 'before_delete_post', array( __CLASS__, 'on_product_delete' ), 10, 1 );
    }

    public static function on_product_save( $product_id ) {
        // Trigger single row updates directly in the index table
        WCS\Search\Indexer::index_single_product( (int) $product_id );
    }

    public static function on_product_trash( $post_id ) {
        if ( 'product' === get_post_type( $post_id ) ) {
            WCS\Search\Indexer::remove_single_product( (int) $post_id );
        }
    }
}
```

---

## 4. LIKE Fallback Safeguards

### Problem
When the FULLTEXT search finds no matches (often due to short terms or stopwords), the query falls back to standard double-wildcard `LIKE '%word%'` queries. These result in full table scans on the index table, causing high disk I/O and query time spikes.

### Solution
* Enforce a minimum word length (e.g., 3 characters) before triggering wildcard LIKE fallbacks.
* Use trailing-only wildcards (e.g., `LIKE 'word%'`) which allow MySQL to traverse index ranges.
