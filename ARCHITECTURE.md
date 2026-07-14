# WP Fast Search — Architecture Diagram

---

## System Layers

```mermaid
block-beta
  columns 5

  block:SHOPPER["🛍️ Shopper (Browser)"]:5
    columns 5
    A["⌨️ Search Input"]
    B["⏱️ Debounce 280ms"]
    C["🚫 AbortController"]
    D["🗺️ Session Map Cache"]
    E["📋 Dropdown UI"]
  end

  space:5

  block:PLUGIN["📦 WP Fast Search Plugin"]:5
    columns 5

    block:FRONT["Frontend Layer"]:2
      columns 1
      F1["search.js\nLazy · Debounce · rAF"]
      F2["search.css\nCSS Custom Properties"]
    end

    block:API["API Layer"]:1
      columns 1
      G1["REST Endpoint\n/wp-json/wcs/v1/search"]
    end

    block:CACHE["Cache Layer"]:2
      columns 1
      H1["L1 APCu (checked first)"]
      H2["L2 WP Transients (object cache or wp_options)"]
      H3["L3 Stampede mutex + DB"]
    end
  end

  space:5

  block:CORE["⚙️ WordPress Core"]:5
    columns 5

    block:SEARCH["Search Engine"]:2
      columns 1
      I1["FULLTEXT Query\nBoolean Mode"]
      I2["Trigram LIKE\nFallback"]
    end

    block:INDEXER["Indexer"]:2
      columns 1
      J1["Batch Indexer
Action Scheduler"]
      J2["Live Updater
WC Hooks + Debounced Bust"]
    end

    block:ADMIN["Admin Panel"]:1
      columns 1
      K1["Settings API\nColor · Count"]
      K2["Status Widget\nProgress · Rebuild"]
    end
  end

  space:5

  block:DATA["🗃️ Data Layer"]:5
    columns 5
    L1[("wcs_search_index\nFULLTEXT indexed")]
    L2[("wp_options\nTransients · Settings")]
    L3[("wp_posts\nwp_postmeta")]
    L4[("wp_terms\nwp_term_relationships")]
    L5[("MySQL\nInnoDB · utf8mb4")]
  end

  A --> B
  B --> C
  C --> D
  D --> G1
  G1 --> H1
  H1 --> H2
  H2 --> H3
  H3 --> I1
  I1 --> I2
  I1 --> L1
  I2 --> L1
  J1 --> L1
  J2 --> L1
  J1 --> L3
  J1 --> L4
  H3 --> L2
  K1 --> L2
```

---

## 4. Product Lifecycle & Index Sync

```mermaid
sequenceDiagram
    actor Admin
    participant WC as WooCommerce
    participant Indexer as Indexer Module
    participant AS as Action Scheduler
    participant DB as wcs_search_index
    participant Cache as Cache Layer
    participant OPT as wp_options

    Admin->>WC: Save / Edit product
    WC->>Indexer: save_post_product hook
    Indexer->>Indexer: build_index_row(product)
    Indexer->>DB: $wpdb->replace() — upsert 1 row IMMEDIATELY
    Note over DB: Index is fresh instantly
    Note over Cache: Cache stays WARM during this

    Indexer->>AS: wcs_schedule_deferred_bust()
    AS->>AS: as_next_scheduled_action('wcs_deferred_cache_bust')?
    alt Bust already queued
        AS-->>Indexer: Skip — deduplication guard
    else No bust pending
        AS-->>Indexer: Queued for T+5 minutes
    end

    Note over AS: 5 minutes later...
    AS->>OPT: update wcs_cache_version v3 → v4
    AS->>OPT: wcs_run_transient_gc()
    Note over OPT: DELETE _transient_timeout_wcs_v3_*
    Note over OPT: No wp_options bloat

    Admin->>WC: Delete product
    WC->>Indexer: before_delete_post hook
    Indexer->>DB: DELETE WHERE id = product_id
    Indexer->>AS: wcs_schedule_deferred_bust()

    Note over AS: Daily at 03:00...
    AS->>OPT: wcs_daily_transient_gc()
    Note over OPT: Sweep any remaining orphaned rows
```

---

## 5. Initial Batch Indexing Flow

```mermaid
flowchart TD
    ACT(["Plugin Activated"])
    ACT --> PROBE["wcs_probe_db_capabilities()
SELECT VERSION()"]

    PROBE --> MARIADB{"MariaDB detected?
stripos VERSION mariadb"}
    MARIADB -- "Yes (always)" --> MANUAL["use_ngram = false
PHP trigrams always"]
    MARIADB -- "No (MySQL)" --> VER_CHK{"MySQL ≥ 5.7.6?"}
    VER_CHK -- "Yes" --> NGRAM["use_ngram = true
FULLTEXT WITH PARSER ngram"]
    VER_CHK -- "No" --> MANUAL

    MANUAL & NGRAM --> STORE["Store wcs_db_caps
in wp_options"]
    STORE --> CREATE["dbDelta() — create
wcs_search_index table"]
    CREATE --> QUEUE["as_schedule_single_action()
offset=0 — first batch"]

    QUEUE --> AS_FIRE(["Action Scheduler fires
independent of traffic
built-in retry on failure"])
    AS_FIRE --> MEM["wcs_safe_batch_size()
ini_get memory_limit"]

    MEM --> UNLIMITED{"memory_limit = -1?"}
    UNLIMITED -- "Yes" --> FIXED["batch_size = 100
skip % check"]
    UNLIMITED -- "No" --> PCT{"usage > 80% of limit?"}
    PCT -- "Yes" --> HALF["halve batch_size
floor at 10"]
    PCT -- "No" --> DEFAULT["batch_size = 50 default"]

    FIXED & HALF & DEFAULT --> FETCH["wcs_fetch_product_batch(offset, size)"]
    FETCH --> BUILD["foreach product
build_index_row()
wcs_trigrams() UTF-8"]
    BUILD --> UPSERT["$wpdb->replace() batch"]
    UPSERT --> MORE{"More products?"}
    MORE -- "Yes" --> NEXT["as_schedule_single_action()
offset += batch_size
no traffic dependency"]
    NEXT --> AS_FIRE
    MORE -- "No" --> DONE["✅ Index complete
update_option wcs_index_complete"]
    DONE --> SCHED_GC["as_schedule_recurring_action()
wcs_daily_transient_gc at 03:00"]
```

---

## 3. Cache Invalidation Strategy

```mermaid
stateDiagram-v2
    [*] --> WARM : product indexed / cache populated

    WARM : Cache WARM
    WARM : wcs_v3_abc123 exists in L1 · L2 · L3
    WARM : shoppers get sub-ms results

    WARM --> ROW_UPDATE : Product saved / repriced / stock changed

    ROW_UPDATE : wcs_search_index row upserted IMMEDIATELY
    ROW_UPDATE : Cache still WARM — shoppers unaffected
    ROW_UPDATE : wcs_schedule_deferred_bust() called

    ROW_UPDATE --> QUEUED : No bust job pending
    ROW_UPDATE --> WARM : Bust job already queued
    note right of WARM : Deduplication — 500 product
    note right of WARM : updates = 1 queued job

    QUEUED : Action Scheduler job queued
    QUEUED : Fires in 5 minutes
    QUEUED : Cache still WARM during wait

    QUEUED --> BUST : 5-minute timer fires

    BUST : wcs_cache_version v3 → v4
    BUST : wcs_run_transient_gc() cleans ALL v3 rows
    BUST : from wp_options immediately

    BUST --> COLD : Next search request (new v4 key)

    COLD : L1 miss · L2 miss · L3 miss
    COLD : FULLTEXT query runs once
    COLD : Result stored in L1 + L2 + L3 under v4 key

    COLD --> WARM : Cache warmed with fresh data

    WARM --> GC : Daily Action Scheduler job at 03:00
    GC : wcs_daily_transient_gc runs
    GC : Sweeps any remaining orphaned
    GC : _transient_wcs_v* rows from wp_options
    GC --> WARM
```

---

## Plugin Internal Architecture

```mermaid
classDiagram
    class WPFastSearch {
        +string VERSION
        +string TABLE_NAME
        +boot() void
        +define_constants() void
        +load_dependencies() void
    }

    class Activator {
        +run() void
        -probe_db_capabilities() array
        -is_mariadb(version_string: string) bool
        -create_table(use_ngram: bool) void
        -schedule_initial_batch() void
        +deactivate() void
        +uninstall() void
    }

    class Indexer {
        -int BATCH_SIZE
        +process_batch(offset: int) void
        +upsert_product(id: int) void
        +delete_product(id: int) void
        +update_stock(id: int, status: string) void
        -build_index_row(product: WC_Product) array
        -wcs_trigrams(text: string) string
        -wcs_safe_batch_size() int
        -wcs_parse_memory_limit(val: string) int
        -schedule_deferred_bust() void
        +run_transient_gc() void
    }

    class SearchHandler {
        +register_rest_route() void
        +handle_search(request: WP_REST_Request) WP_REST_Response
        -build_cache_key(term: string) string
        -get_from_cache(key: string) array|false
        -store_in_cache(key: string, data: array) void
        -try_apcu(key: string) array|false
        -query_fulltext(term: string) array
        -query_like_fallback(term: string) array
        -format_results(rows: array) array
    }

    class AdminSettings {
        +register_settings() void
        +render_page() void
        +render_status_widget() void
        -get_index_progress() array
        +handle_rebuild() void
    }

    class Frontend {
        +enqueue_assets() void
        -get_script_version() string
        +inline_config() void
    }

    WPFastSearch --> Activator : uses
    WPFastSearch --> Indexer : registers hooks
    WPFastSearch --> SearchHandler : registers REST
    WPFastSearch --> AdminSettings : registers menu
    WPFastSearch --> Frontend : enqueues on init
    Indexer --> SearchHandler : bust_cache on change
```

---

## Technology Stack

```mermaid
mindmap
  root((WP Fast Search))
    Backend
      PHP 7.4+
        WordPress Hooks API
        WP REST API
        WP-Cron
        wpdb::prepare
        WP Transients API
        WP Object Cache API
      MySQL / MariaDB
        InnoDB Engine
        FULLTEXT Index
        ngram Parser
        utf8mb4_unicode_ci
    Frontend
      Vanilla JavaScript ES6+
        AbortController
        Fetch API
        Map Session Cache
        requestAnimationFrame
        visualViewport API
        MutationObserver
      CSS3
        Custom Properties
        position fixed
        Scoped namespace
    Compatibility
      WordPress 5.0+
      WooCommerce 4.0+
      MySQL 5.6+ and 5.7.6+ ngram
      MariaDB 10.0+
      PHP 7.4+
      All major themes
      Mobile responsive
```
