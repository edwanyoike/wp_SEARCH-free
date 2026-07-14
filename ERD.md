# WP Fast Search — ERD & System Architecture

---

## 1. Database Entity Relationship Diagram

```mermaid
erDiagram
    WC_PRODUCTS {
        bigint      ID              PK
        varchar     post_title
        varchar     post_status
        varchar     post_type
    }

    WC_POSTMETA {
        bigint      meta_id         PK
        bigint      post_id         FK
        varchar     meta_key
        longtext    meta_value
    }

    WP_TERMS {
        bigint      term_id         PK
        varchar     name
        varchar     slug
    }

    WP_TERM_TAXONOMY {
        bigint      term_taxonomy_id    PK
        bigint      term_id             FK
        varchar     taxonomy
    }

    WP_TERM_RELATIONSHIPS {
        bigint      object_id           FK
        bigint      term_taxonomy_id    FK
    }

    WCS_SEARCH_INDEX {
        bigint      product_id      PK  "WC product post ID"
        text        title               "Product name (FULLTEXT: search_data + ft_title)"
        varchar     sku                 "Parent SKU (idx_sku B-tree + FULLTEXT)"
        longtext    content             "Short desc + cat/tag/brand/attribute terms + variation SKUs (FULLTEXT)"
        decimal     price_min           "Simple price / variation min"
        decimal     price_max           "Simple price / variation max"
        varchar     stock_status        "instock / outofstock / onbackorder (indexed)"
        bigint      total_sales         "Lifetime sales — popularity ranking signal"
        varchar     image_url           "Thumbnail URL"
        varchar     permalink           "Product page URL"
        datetime    updated_at          "Last indexed timestamp"
    }

    WP_OPTIONS {
        bigint      option_id       PK
        varchar     option_name         "wcs_cache_version, wcs_settings, wcs_*"
        longtext    option_value
        varchar     autoload
    }

    WP_OPTIONS ||--o{ WCS_SEARCH_INDEX : "cache_version keys bust index cache"
    WC_PRODUCTS ||--|| WCS_SEARCH_INDEX : "1 product → 1 index row (upserted)"
    WC_PRODUCTS ||--o{ WC_POSTMETA : "has many meta (price, stock, SKU)"
    WC_PRODUCTS ||--o{ WP_TERM_RELATIONSHIPS : "belongs to tags + categories"
    WP_TERM_RELATIONSHIPS }o--|| WP_TERM_TAXONOMY : "via taxonomy"
    WP_TERM_TAXONOMY }o--|| WP_TERMS : "resolves to term"
```

> **Key design point:** `wcs_search_index` is the ONLY table touched at search time.
> All other tables (postmeta, terms, relationships) are read **only at index time**, never at query time.

---

## 2. System Component Diagram

```mermaid
graph TD
    subgraph BROWSER["🌐 Shopper Browser"]
        INPUT["Search Input\n[type=search]"]
        DEBOUNCE["Debounce\n280ms"]
        ABORT["AbortController\nCancel stale requests"]
        SESSION_CACHE["Session Map Cache\nterm → results"]
        DROPDOWN["Dropdown UI\nimage · title · price"]
        RAF["requestAnimationFrame\nbatched DOM writes"]
    end

    subgraph FRONTEND["📦 Frontend Module (search.js)"]
        LAZY["Lazy Init\non focusin event"]
        JS_CACHE_CHECK{"L0: Session\nCache hit?"}
    end

    subgraph WORDPRESS["⚙️ WordPress / PHP"]
        REST["REST Endpoint\n/wp-json/wcs/v1/search"]

        subgraph CACHE_HIERARCHY["🗄️ Cache Hierarchy"]
            L1["L1: APCu\nShared server RAM\n~0.01ms — checked first"]
            L2["L2: WP Transients\nObject cache (Redis/Memcached)\nor wp_options\n~1ms"]
            L3["L3: Stampede mutex\nthen FULLTEXT DB query"]
        end

        subgraph QUERY_ENGINE["🔍 Query Engine"]
            FT["FULLTEXT Query\nBoolean Mode + prefix *\n5–8ms"]
            LIKE_FB["LIKE Fallback\ntrigrams LIKE '%xyz%'\nfor short terms"]
        end

        subgraph INDEXER["🗂️ Indexer Module"]
            BATCH["Batch Indexer\nWP-Cron · 50/30s"]
            LIVE["Live Updater\nWC product hooks"]
            TRIGRAM_GEN["wcs_trigrams()\nmb_substr · UTF-8\nany language"]
            NGRAM_PROBE["MySQL Version Probe\n≥5.7.6 → ngram parser\nelse manual trigrams"]
        end

        CACHE_BUST["Cache Buster\nIncrement wcs_cache_version"]
    end

    subgraph DB["🗃️ MySQL"]
        INDEX_TABLE[("wcs_search_index\nFULLTEXT idx_ft\ntitle · keywords · trigrams")]
        OPTIONS[("wp_options\nTransients\nSettings\nCache version")]
        WC_DATA[("wp_posts\nwp_postmeta\nwp_terms\n(read at index time only)")]
    end

    subgraph WC_EVENTS["🛒 WooCommerce Events"]
        SAVE["save_post_product"]
        DELETE["before_delete_post"]
        STOCK["woocommerce_product_set_stock_status"]
        PRICE["woocommerce_variation_set_price"]
    end

    %% Frontend flow
    INPUT --> LAZY
    LAZY --> DEBOUNCE
    DEBOUNCE --> ABORT
    ABORT --> JS_CACHE_CHECK
    JS_CACHE_CHECK -- "Hit" --> DROPDOWN
    JS_CACHE_CHECK -- "Miss" --> REST
    REST --> L1
    L1 -- "Hit" --> BROWSER
    L1 -- "Miss" --> L2
    L2 -- "Hit" --> L1
    L2 -- "Miss" --> L3
    L3 -- "Hit" --> L2
    L3 -- "Miss" --> FT
    FT -- "0 results" --> LIKE_FB
    FT --> INDEX_TABLE
    LIKE_FB --> INDEX_TABLE
    INDEX_TABLE --> L3
    L3 --> SESSION_CACHE
    SESSION_CACHE --> RAF
    RAF --> DROPDOWN

    %% Indexer flow
    BATCH --> NGRAM_PROBE
    LIVE --> NGRAM_PROBE
    NGRAM_PROBE --> TRIGRAM_GEN
    TRIGRAM_GEN --> INDEX_TABLE
    WC_DATA -- "read at index time" --> BATCH

    %% WC Events → Indexer + Cache bust
    SAVE --> LIVE
    DELETE --> LIVE
    STOCK --> LIVE
    PRICE --> LIVE
    LIVE --> CACHE_BUST
    CACHE_BUST --> OPTIONS
```

---

## 3. Cache Degradation Flow

```mermaid
flowchart TD
    REQ(["Search Request\nGET /wp-json/wcs/v1/search?q=term"])

    REQ --> VER["Build cache key\nwcs_v{version}_{md5}"]

    VER --> L1_CHK{"L1: wp_cache_get\nRedis/Memcached?"}
    L1_CHK -- "✅ Hit" --> RESP
    L1_CHK -- "❌ Miss" --> L2_CHK

    L2_CHK{"L2: apcu_fetch\nAPCu loaded?"}
    L2_CHK -- "✅ Hit" --> WARM_L1["Warm L1"]
    WARM_L1 --> RESP
    L2_CHK -- "❌ Miss / not available" --> L3_CHK

    L3_CHK{"L3: get_transient\nwp_options lookup ~1ms"}
    L3_CHK -- "✅ Hit" --> WARM_L12["Warm L1 + L2"]
    WARM_L12 --> RESP
    L3_CHK -- "❌ Miss (cold / expired)" --> QUERY

    QUERY["L4: FULLTEXT Query\nwcs_search_index\n5–8ms"]
    QUERY --> STORE["Store → L1 + L2 + L3"]
    STORE --> RESP(["JSON Response\n{id, title, price, image, url}"])
```

---

## 4. Product Lifecycle & Index Sync

```mermaid
sequenceDiagram
    actor Admin
    participant WC as WooCommerce
    participant Indexer as Indexer Module
    participant DB as wcs_search_index
    participant Cache as Cache Layer

    Admin->>WC: Save / Edit product
    WC->>Indexer: save_post_product hook
    Indexer->>Indexer: build_index_row(product)\n+ wcs_trigrams(title)
    Indexer->>DB: $wpdb->replace() — upsert 1 row
    Indexer->>Cache: increment wcs_cache_version
    Note over Cache: Old transients orphaned\nExpire within 120s TTL

    Admin->>WC: Delete product
    WC->>Indexer: before_delete_post hook
    Indexer->>DB: DELETE WHERE id = product_id
    Indexer->>Cache: increment wcs_cache_version

    Admin->>WC: Change stock status
    WC->>Indexer: woocommerce_product_set_stock_status
    Indexer->>DB: UPDATE SET in_stock = ? WHERE id = ?
    Indexer->>Cache: increment wcs_cache_version
```

---

## 5. Initial Batch Indexing Flow

```mermaid
flowchart TD
    ACT(["Plugin Activated"])
    ACT --> PROBE["Probe MySQL version\n≥5.7.6 → store 'use_ngram=1'\nelse 'use_ngram=0'"]
    PROBE --> CREATE["dbDelta() — create\nwcs_search_index table\n(ngram FULLTEXT if supported)"]
    CREATE --> SCHED["Schedule first batch\nwp_schedule_single_event\noffset=0, batch_size=50"]

    SCHED --> CRON(["WP-Cron fires\nevery 30s"])
    CRON --> MEM{"memory_get_usage()\n> 80% of limit?"}
    MEM -- "Yes → slow down" --> HALF["Halve batch_size\nmin floor: 10"]
    MEM -- "No" --> FETCH
    HALF --> FETCH

    FETCH["WC_Product_Query\nfetch N products\noffset=current"]
    FETCH --> BUILD["foreach product\nbuild_index_row()\nwcs_trigrams()"]
    BUILD --> UPSERT["$wpdb->replace()\nbatch insert/update"]
    UPSERT --> MORE{"More products?"}
    MORE -- "Yes" --> SCHED2["Schedule next batch\noffset += batch_size"]
    MORE -- "No" --> DONE(["✅ Index complete\nUpdate admin status widget"])
```

---

## 6. File Structure

```
wp-fast-search/
│
├── wp-fast-search.php              # Plugin header, constants, bootstrap
│
├── includes/
│   ├── class-activator.php         # Activation, MySQL probe, table creation, cron schedule
│   ├── class-indexer.php           # Batch + live indexing, trigram generator
│   ├── class-search-handler.php    # REST endpoint, 4-level cache, query tiers
│   ├── class-frontend.php          # Enqueue scripts/styles, inline wcs_config JS object
│   └── class-admin-settings.php   # WP Settings API, index status widget
│
├── assets/
│   ├── js/search.js                # Vanilla JS: debounce, AbortController, Map cache, rAF
│   └── css/search.css              # Scoped .wcs-dropdown styles + CSS custom properties
│
├── languages/
│   └── wp-fast-search.pot          # i18n strings
│
└── uninstall.php                   # DROP table, DELETE options, clear transients
```

---

## 7. Performance Targets Summary

| Metric | Target | Mechanism |
|---|---|---|
| Cold search (no cache) | < 8ms | FULLTEXT on flat table |
| Warm search (L3 transient) | ~1ms | wp_options indexed lookup |
| Warm search (L1/L2) | < 1ms | Redis / APCu |
| Repeat search same session | < 1ms | JS session Map() |
| Page load overhead | 0ms | Lazy JS init on focusin |
| JS bundle size | < 7KB min | No framework, no jQuery |
| Requests per keystroke | ≤ 1/280ms | Debounce + AbortController |
| Index build (1000 products) | ~10 min | 50/batch × 30s interval |
| Memory per search request | < 2MB | Raw $wpdb, no WP template |
| Concurrent searches | Unlimited | Stateless, read-only |
