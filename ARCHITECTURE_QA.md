# WP Fast Search — Architecture Review: 50 Questions Answered

---

## Cache Strategy

**Q1. What is the rationale for delaying cache invalidation by 5 minutes after an index update?**

The index row is updated immediately and synchronously (`$wpdb->replace()`). The 5-minute delay applies only to the **cache version bump**, not the data itself. The reason: ERP systems and inventory sync tools can update hundreds of products per minute. Without debouncing, each update would reset `wcs_cache_version`, making the cache permanently cold. The deduplication guard (`as_next_scheduled_action()`) ensures only one bust job exists at a time regardless of how many products change.

**Trade-off acknowledged:** Cached JSON responses may reflect pre-update data for up to 5 min + 120s TTL (~7 minutes total). The index itself is always fresh. This is an explicit availability-over-consistency decision.

---

**Q2. How is search result freshness guaranteed during the invalidation window?**

It is not guaranteed — this is by design. The freshness model is:
- **Index data:** Always fresh (synchronous row upsert on every product change)
- **Cached responses:** May lag up to ~7 minutes (5min defer + 120s TTL)
- **New search terms never seen before:** Always fresh (no cached entry exists, goes straight to DB)

For stores where price/stock accuracy within minutes is critical, the admin can reduce the debounce window via a constant `WCS_CACHE_BUST_DELAY` (default 300s, minimum 0 for immediate busting).

---

**Q3. Under what conditions could users receive stale information?**

| Scenario | Max staleness |
|---|---|
| Normal product edit via admin | ~7 minutes (5min debounce + 120s TTL) |
| ERP/WP All Import bulk update | Up to 24h (until reconciliation worker at 2 AM) |
| Action Scheduler bust job fails | Until next successful bust or reconciliation |
| Redis OOM (evicts live cache keys) | Until next search request regenerates entry |
| Product deleted via raw SQL | Until reconciliation worker runs |

---

**Q4. Why global cache versioning instead of granular per-product invalidation?**

Granular invalidation requires knowing which cached search terms contain a given product — this is a **reverse index problem**. Solving it requires either: (a) storing a `term → [product_ids]` mapping alongside the cache (doubles storage complexity), or (b) scanning all cache keys on every product change (expensive). Global versioning with debouncing achieves ~95% of the freshness benefit at ~5% of the complexity.

---

**Q5. What is the impact of a cache version bump on large stores?**

All cached terms go cold simultaneously. On a store with 10,000 unique monthly search terms, a version bump cold-starts all 10,000 entries. Patch 13 (dogpile lock) ensures only **one request per unique term** hits the database. The remaining concurrent requests wait 100ms and read from the freshly populated cache. Worst case: 10,000 sequential FULLTEXT queries across multiple minutes as terms are searched organically. Average query time ~6ms, so this is spread across normal traffic and invisible to users.

---

**Q6. How are cache stampedes prevented?**

Patch 13 — transient mutex lock:
1. First request on a cold key acquires `wcs_lock_{key}` transient (5s TTL)
2. Runs the FULLTEXT query, writes result to L1+L2+L3
3. Releases the lock
4. All concurrent requests that see the lock wait 100ms, then retry cache
5. If cache is still not populated after 100ms, they run their own query (final fallback)

This limits simultaneous FULLTEXT queries for any given term to 1 under normal conditions.

---

**Q7. What happens when APCu is unavailable?**

Detected at runtime via `extension_loaded('apcu')`. If unavailable, L2 is silently skipped. The hierarchy degrades to: L1 (WP Object Cache) → L3 (Transients) → L4 (FULLTEXT). No configuration change required. On hosts with no persistent object cache and no APCu, L3 Transients provide cross-request caching via `wp_options` (indexed key lookup, ~1ms). Performance degrades slightly but remains functional.

---

## Search Engine & Relevance

**Q8. How is relevance scoring calculated?**

- **FULLTEXT (Tier 1):** MySQL computes a relevance score via `MATCH()...AGAINST() IN BOOLEAN MODE`. Score is based on term frequency (TF) and inverse document frequency (IDF) within the `title`, `keywords`, and `trigrams` columns. Results ordered by `score DESC`.
- **Trigram LIKE (Tier 2):** No scoring. Results ordered by `id ASC` (insertion order). This is a known weakness — fallback results have no relevance ranking.

---

**Q9. How are ranking inconsistencies between tiers handled?**

They are not fully harmonized in V1. FULLTEXT returns relevance-ranked results; LIKE fallback returns ID-ordered results. The UI experience difference: FULLTEXT feels "smart" (most relevant first), LIKE fallback feels "random" (arbitrary order). Mitigation: LIKE fallback only activates for edge cases (terms < 3 chars, stopword-only queries). A future improvement would apply a secondary scoring pass to LIKE results based on string similarity.

---

**Q10. What search quality benchmarks were used?**

None formally defined in the architecture phase. This is an honest gap. Validation will be manual: test a sample product catalog, verify that common search patterns (exact match, prefix, misspelling, short brand names, special chars) produce expected results. A formal benchmark suite would require real store product data.

---

**Q11. How are misspellings and partial names handled?**

Via pre-stored Unicode trigrams. "runing" → trigrams: `run`, `uni`, `nin`, `ing`. "running" → trigrams: `run`, `unn`, `nni`, `nin`, `ing`. Shared tokens: `run`, `nin`, `ing` — likely to produce a match. Effectiveness degrades as the error rate increases. A 1-character transposition is well-handled; a 4-character misspelling may not match. This is not a Levenshtein/edit-distance system — it is trigram overlap matching.

---

**Q12. What happens when a search produces no FULLTEXT matches?**

1. Tier 2 (LIKE on `trigrams` column) automatically runs
2. If still empty: server returns `[]` (explicitly cached per Patch 14a)
3. JS renders a "No products found" message
4. The empty result is cached for 120s — repeated identical junk queries never reach the DB

---

**Q13. How is consistent ordering ensured?**

Within a single cache TTL window: consistent (same cached JSON returned). Across cache boundaries: FULLTEXT order is deterministic for the same query against the same index state. If the index changes between requests (product update), a different score distribution may produce different ordering — this is correct behavior, not an inconsistency.

---

## Indexing

**Q14. Why `$wpdb->replace()` instead of alternatives?**

`REPLACE INTO` is `DELETE + INSERT`. Benefits: idempotent (safe to run multiple times for the same product ID), atomic per row, simpler code than `INSERT ... ON DUPLICATE KEY UPDATE`. Tradeoff: resets the entire row rather than patching specific columns. Since we always rebuild the full row from the product object, this is fine. The auto-increment concern doesn't apply because `id` (the product post ID) is explicitly provided as the primary key.

---

**Q15. What happens if a batch job fails midway?**

Action Scheduler retries failed jobs up to 3 times by default. Since indexing is offset-based and `$wpdb->replace()` is idempotent, a retry of the same batch is safe — already-indexed products get re-indexed (harmless). Products indexed before the failure point are committed to the table. The batch resumes from the same offset on retry.

---

**Q16. How is indexing resumed after a server restart or outage?**

Action Scheduler persists all jobs to `wp_actionscheduler_actions` (MySQL table). A server restart does not lose queued jobs. When WordPress next loads (via any request or real cron), the queued `wcs_index_batch` jobs execute. No manual intervention required.

---

**Q17. How are concurrent indexing jobs prevented from processing the same records?**

Action Scheduler can run actions concurrently on high-traffic sites. However, `$wpdb->replace()` on `wcs_search_index` is idempotent — two concurrent jobs writing the same row produce the same result with no corruption (InnoDB row locking handles the write contention). The only risk is wasted CPU from duplicate work. To minimize this: each batch job checks `as_next_scheduled_action('wcs_index_batch')` before scheduling the next batch.

---

**Q18. What happens if an administrator starts multiple rebuild operations?**

The rebuild button must: (1) call `as_unschedule_all_actions('wcs_index_batch', [], 'wcs-indexer')` to cancel any pending batches, (2) NOT truncate the table (to preserve search availability), (3) schedule a fresh `wcs_index_batch` at offset 0. The button should be disabled in the UI while any `wcs_index_batch` action is pending, using `as_next_scheduled_action()` to check state.

**Gap identified:** The current architecture description says "clears table, reschedules full batch." This should be revised — clearing the table causes a zero-result window. The correct approach is overwriting in-place.

---

**Q19. How are race conditions handled when products are updated during a full reindex?**

If a product is edited while the batch is running:
1. The live hook fires → synchronous `$wpdb->replace()` writes the fresh row
2. Later, the batch reaches that product's offset → writes the batch-fetched version (potentially older)
3. The batch version overwrites the live update — brief regression

**Mitigation:** The reconciliation worker (Patch 12) at 2 AM catches this by comparing `idx.updated_at < p.post_modified`. This is an accepted edge case in V1. A V2 fix: the batch indexer skips rows where `updated_at` is newer than `batch_start_time`.

---

**Q20. Is there a mechanism to verify index integrity after batch processing completes?**

The admin status widget shows: `COUNT(wcs_search_index)` vs `COUNT(wp_posts WHERE post_type='product' AND post_status='publish')`. A count mismatch indicates incomplete indexing. The reconciliation worker (Patch 12) performs a deeper check: LEFT JOIN to find missing or stale rows. No row-level checksum exists. Future improvement: store a hash of `post_modified` in the index row and compare during reconciliation.

---

## Database Design

**Q21. What testing was performed to estimate index table growth?**

Theoretical estimates based on schema column max widths (Patch 17). No empirical testing on real product catalogs was performed during the architecture phase. This is a gap — estimates should be validated on a sample catalog before release.

---

**Q22. Expected storage requirements by catalog size?**

Based on Patch 17's hard caps (max ~5.5KB/row worst case; ~1.5KB/row typical):

| Catalog size | Worst case | Typical |
|---|---|---|
| 10k products | 55 MB | 15 MB |
| 50k products | 275 MB | 75 MB |
| 100k products | 550 MB | 150 MB |
| 500k products | 2.75 GB | 750 MB |

MariaDB hosts (manual trigrams, up to 2000 chars): add ~15-20% to typical estimates. MySQL ngram hosts: `trigrams` column is empty, subtract ~10%.

---

**Q23. Why is ngram disabled on MariaDB?**

MariaDB does not ship the ngram FULLTEXT parser in standard builds. It requires the Mroonga third-party storage engine, which is never installed on shared hosting and rarely on VPS setups. Attempting to use `WITH PARSER ngram` on MariaDB causes a fatal table creation error. Since we cannot rely on it being present, we always fall back to PHP trigrams on MariaDB — which produces identical searchability.

---

**Q24. Is ngram detected via feature test or vendor check?**

Vendor check: `stripos($wpdb->get_var('SELECT VERSION()'), 'mariadb') !== false`. A feature test (attempt `CREATE TABLE ... WITH PARSER ngram`, catch errors) would be more robust but risks error logs and leftover broken tables. The vendor+version check is simple and reliable for the vast majority of real deployments. Edge case missed: a MariaDB fork with ngram support. Acceptable risk for V1.

---

**Q25. At what catalog size does performance degrade significantly?**

| Catalog size | Expected FULLTEXT query time | Notes |
|---|---|---|
| < 50k products | < 10ms | Comfortable on any shared host |
| 50k–200k | 10–30ms | Fine if `innodb_buffer_pool_size` is adequate |
| 200k–500k | 30–100ms | Requires tuned MySQL (dedicated server) |
| 500k+ | > 100ms | FULLTEXT index may not fit in buffer pool; consider Elasticsearch |

The primary limiting factor is whether MySQL can keep the FULLTEXT index in RAM (buffer pool). On shared hosting with 256MB total RAM, the limit is practical at ~50k products.

---

**Q26. How does the architecture behave on lower-tier hosting?**

- **128MB PHP memory:** Batch size auto-reduces to min 10 via Patch 5
- **Slow MySQL I/O:** FULLTEXT queries take longer; L3 Transient cache becomes more important
- **No APCu:** L2 skipped; L3 carries the load
- **No Redis:** L1 per-request only; L3 provides cross-request persistence
- **Limited MySQL connections (10-20 on shared hosts):** Each search is one read-only query, released immediately. Should be fine under typical shared hosting concurrency.

---

## REST API

**Q27. What protections exist against endpoint abuse?**

Three layers (Patch 18): (1) `wp_verify_nonce()` — bots without a valid session get 403 before any processing. (2) IP transient rate limiter — 60 requests/IP/minute. (3) Input sanitization — boolean operators, SQL wildcards, glob characters stripped.

---

**Q28. Are there limits on query length, request frequency, or concurrent requests?**

- **Query length:** Hard cap at 100 UTF-8 characters (Patch 8)
- **Request frequency:** 60/IP/minute (Patch 18)
- **Concurrent requests:** No explicit limit — WordPress/MySQL handles concurrency. Dogpile lock (Patch 13) prevents DB overload on simultaneous cold requests.

---

**Q29. How are malformed or extremely large requests handled?**

Processing pipeline (in order):
1. `wcs_sanitize_search_term()` — strips operators, wildcards, XSS vectors
2. `mb_substr($term, 0, 100, 'UTF-8')` — truncates at 100 chars
3. `sanitize_text_field()` — WordPress sanitization
4. Length check: if < 2 chars after sanitization, return empty immediately
5. Nonce check: if invalid, return 403 immediately

No oversized input ever reaches `$wpdb->prepare()`.

---

**Q30. What measures prevent automated catalog scraping?**

The nonce requirement is the primary defense: a valid `wcs_search` nonce requires a browser session that loaded the plugin's footer script. Headless bots without JavaScript execution cannot obtain a valid nonce. For bots with JavaScript (Playwright, Puppeteer), the rate limiter (60/min/IP) limits the enumeration rate to ~3,600 queries/hour — scraping a 50k product catalog would take 14+ hours per IP, making it impractical.

---

## Frontend

**Q31. Does the session cache have an eviction strategy or size limit?**

**Gap identified:** The current design does not implement a size limit on the JS `Map()`. This needs to be added:

```javascript
const MAX_CACHE_ENTRIES = 100;
function setCacheEntry(term, results) {
    if (sessionCache.size >= MAX_CACHE_ENTRIES) {
        // Delete the oldest entry (Map preserves insertion order)
        const firstKey = sessionCache.keys().next().value;
        sessionCache.delete(firstKey);
    }
    sessionCache.set(term, results);
}
```

This caps memory usage at approximately 100 entries × ~500 bytes average = ~50KB. Negligible on any device.

---

**Q32. What happens during long sessions with hundreds of unique search terms?**

Without the fix above: the Map grows unbounded. Each entry is ~200–800 bytes (JSON results). 1,000 entries ≈ 200KB–800KB. This is low relative to typical page memory usage (~50–100MB), but it is wasteful. The MAX_CACHE_ENTRIES cap (Q31) makes this a non-issue.

---

**Q33. How is memory usage controlled in the frontend cache?**

With the `MAX_CACHE_ENTRIES = 100` LRU eviction (Q31): maximum Map memory ≈ 50KB. The JS module itself is < 7KB minified. Total frontend memory footprint under 100KB — negligible on any device including low-end mobile.

---

**Q34. What testing has been performed on mobile devices with limited memory?**

None during the architecture phase. Manual testing on real devices is required before release. Key things to verify: dropdown positioning with virtual keyboard visible (`visualViewport` API), touch event handling, scroll behavior with fixed-position dropdown. The `visualViewport` resize listener (Patch 21) handles keyboard appearance.

---

## Action Scheduler & Background Jobs

**Q35. What guarantees exist for scheduled job execution?**

Action Scheduler provides **at-least-once delivery** with built-in retry (3 attempts by default). It does not guarantee exactly-once delivery. The idempotent design (`$wpdb->replace()`) means duplicate execution is harmless. On sites with real server cron (`WP_CRON_DISABLE = true` + system crontab), execution is near-real-time. On sites with WordPress cron only, execution requires a page load to trigger — on low-traffic sites, this may delay jobs by minutes to hours.

---

**Q36. How are failed jobs monitored and retried?**

Action Scheduler automatically retries failed jobs (up to 3 times by default). After 3 failures, the action moves to `failed` status and stops retrying. Failed `wcs_*` jobs are visible in WooCommerce → Tools → Scheduled Actions (filter by group `wcs-indexer`). The admin status widget should surface a warning if any `wcs_*` actions are in `failed` state.

---

**Q37. What visibility does an administrator have into background jobs?**

- WooCommerce → Tools → Scheduled Actions: all pending, running, complete, and failed `wcs_*` actions
- Admin status widget: "X of Y products indexed", "Last indexed: N minutes ago"
- `WP_DEBUG` logging: GC decisions logged (Patch 23)
- No email alerting built into V1. Future improvement: email admin if a `wcs_*` action enters `failed` state.

---

**Q38. What happens if the cache-busting job fails repeatedly?**

The cache version never increments. Cached search results remain at the old version. The index itself is still fresh (row upserts happen synchronously before the bust job is queued). Users see slightly stale cached results (up to whatever the TTL allows). The reconciliation worker at 2 AM provides a backstop — it updates stale index rows but does NOT bump the cache version. A future improvement: if `wcs_deferred_cache_bust` enters `failed` state, trigger an immediate synchronous cache bust as a fallback.

---

## Admin & Operations

**Q39. What happens to search availability during a full index rebuild?**

**Revised approach:** The rebuild does NOT truncate the table before starting. It overwrites rows in-place as the batch processes. Users continue seeing search results from the existing index throughout the rebuild. The only limitation: products added after the original index but before the rebuild begins may briefly show stale data until the batch reaches their ID range.

---

**Q40. Can administrators serve search requests during a rebuild?**

Yes. The batch writes new rows using `$wpdb->replace()`, which takes an InnoDB row-level lock only on the specific row being written. Concurrent reads to other rows are unaffected. Search queries run against the table throughout the rebuild.

---

**Q41. How is rebuild progress calculated and reported?**

```php
$indexed = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wcs_search_index");
$total   = $wpdb->get_var(
    "SELECT COUNT(*) FROM {$wpdb->posts}
     WHERE post_type = 'product' AND post_status = 'publish'"
);
$progress = $total > 0 ? round(($indexed / $total) * 100) : 0;
```

**Gap:** During a re-index (not truncated), the count is always ≈ 100% since old rows persist. A separate `wcs_reindex_progress` option should track the current batch offset vs total, reset to 0 when rebuild starts.

---

**Q42. Is there a rollback strategy if a rebuild produces bad data?**

No automated rollback in V1. Manual options:
1. Trigger another rebuild (overwrites bad data)
2. Admin can run `TRUNCATE TABLE wp_wcs_search_index` and rebuild from scratch via the settings panel
3. The original WooCommerce search remains available if this plugin is deactivated

A V2 improvement: shadow table rebuild pattern (`wcs_search_index_temp` → atomic `RENAME TABLE`).

---

**Q43. What operational metrics are available?**

| Metric | Where |
|---|---|
| Products indexed / total | Admin settings widget |
| Last indexed timestamp | Admin settings widget (reads `MAX(updated_at)`) |
| Cache version | Admin settings widget |
| Failed background jobs | WC → Tools → Scheduled Actions |
| Index table row count | Admin settings widget |
| Cache GC status | `WP_DEBUG` log |

Not available in V1: query latency metrics, cache hit rate, search term frequency.

---

## Scalability & Production Readiness

**Q44. What scale targets was this architecture designed for?**

- **Primary target:** 1k–100k products on standard shared/VPS hosting
- **Comfortable range:** Up to ~50k products on any modern shared host
- **Stretched range:** 100k–500k products on a properly tuned VPS (MySQL buffer pool > 1GB)
- **Out of scope:** 500k+ products — at this scale, external search (Elasticsearch, Typesense) is the appropriate solution

---

**Q45. What load-testing results support those scale targets?**

None. This is an honest gap. Load testing with simulated concurrent searches against a seeded catalog of 10k, 50k, and 100k products is required before claiming production readiness. Target benchmarks: FULLTEXT query < 10ms at p95 under 100 concurrent requests, for a 50k-product catalog.

---

**Q46. Expected response times under high concurrency?**

| Cache state | Concurrency | Expected p95 latency |
|---|---|---|
| L1/L2 hit | Any | < 5ms (PHP + network overhead) |
| L3 Transient hit | 100 concurrent | < 10ms |
| Cold (FULLTEXT) | 1 | 5–10ms |
| Cold (FULLTEXT) | 100 simultaneous | 10–50ms (dogpile lock serializes DB hits) |
| Cold (LIKE fallback) | 1 | 10–30ms |

These are estimates. Actual numbers depend on MySQL hardware, buffer pool configuration, and network latency.

---

**Q47. Which components become bottlenecks first as catalog size grows?**

In order:
1. **MySQL FULLTEXT index** — grows with catalog; eventually doesn't fit in buffer pool → slow reads
2. **`wp_options` transient table** — on high-traffic sites, many concurrent transient reads/writes cause lock contention
3. **PHP workers** — if the search handler loads too much of WordPress, PHP worker saturation limits concurrency
4. **Action Scheduler tables** — if log bloat is not controlled (Patch 25), queries against `actionscheduler_actions` slow down

---

**Q48. How does the architecture behave without object caching?**

L1 (WP Object Cache) becomes per-request memory only — no cross-request persistence. L2 (APCu) may or may not be available. L3 (Transients) provides cross-request caching via `wp_options`. Search performance on a cold cache degrades from < 1ms to ~6-10ms (FULLTEXT query time). The architecture remains functional — just slower than with Redis. The dogpile lock (Patch 13) prevents DB overload even without a persistent cache.

---

**Q49. What hosting assumptions are being made?**

| Assumption | Minimum | Notes |
|---|---|---|
| PHP version | 7.4+ | `match` expression, typed properties |
| MySQL / MariaDB | 5.6+ / 10.0+ | InnoDB FULLTEXT support |
| PHP memory_limit | 64MB | Adaptive batching handles lower limits |
| WordPress version | 5.0+ | Block editor hooks, REST API v2 |
| WooCommerce version | 4.0+ | `WC_Product_Query`, Action Scheduler |
| Storage engine | InnoDB | Forced explicitly (Patch 20) |
| WP-Cron or real cron | Either | Action Scheduler works with both |
| Shell access | Not required | All operations via HTTP/DB |

---

**Q50. What failure scenarios were explicitly considered during design?**

All 26 patches represent explicitly considered failure modes:

| Category | Patches |
|---|---|
| Cache correctness | P1, P2, P6, P13, P14, P23 |
| Database integrity | P19, P20, P22, P26 |
| MySQL engine traps | P4, P6, P7, P8 |
| WooCommerce data model | P9, P10, P11, P12, P22 |
| Infrastructure variability | P3, P5, P17, P25 |
| Multi-currency / pricing | P15 |
| Bulk operations & webhooks | P16, P24 |
| Security / abuse | P18 |
| Theme compatibility | P21 |
| Multisite | P19 |
| Storage bloat | P17, P25 |

**Scenarios NOT yet fully mitigated (honest gaps):**
- Reindex race condition during concurrent product updates (Q19) — reconciliation worker is the backstop
- Trigram LIKE results have no relevance scoring (Q9) — accepted V1 limitation
- No formal load test results (Q45) — testing required before production claims
- Session Map cache has no size limit (Q31) — needs the eviction code added
- Admin rebuild button can truncate table causing zero-result window (Q18) — needs revised to in-place overwrite
- No alerting for failed background jobs (Q38) — admin must manually check WC dashboard

---

## Critical Follow-Up Review — Round 2

---

### F2. Why can ERP/import updates remain stale for up to 24 hours?

**The difference is fundamental — not just a degree of staleness:**

For a normal admin edit: the **index row** is fresh immediately; only the **cached JSON** lags ~7 minutes.

For WP All Import / ERP SQL: both the **index row AND the cache** are wrong simultaneously. The 24h figure is the reconciliation window, not just a cache delay.

**Fixes required:**
1. Run reconciliation every 4 hours (not daily): `as_schedule_recurring_action(time(), 4 * HOUR_IN_SECONDS, 'wcs_reconciliation_worker')`
2. Hook into WP All Import's completion: `add_action('pmxi_after_xml_import', fn() => as_schedule_single_action(time()+60, 'wcs_reconciliation_worker'))`
3. Admin "Reconcile Now" button as a manual safety valve

| Scenario | Max staleness (with 4h reconciliation) |
|---|---|
| Normal admin edit | ~7 minutes |
| ERP / WP All Import | ~4 hours (reduced from 24h) |
| Direct SQL update | ~4 hours |

---

### F3. What percentage of updates could be lost during reindex, and is this acceptable?

**Calculated exposure:**
A 10k-product catalog at batch_size=50 takes ~17 minutes (200 batches × ~5s each). At 10 product updates/minute during that window: ~170 products could be overwritten by the batch — approximately **1.7% of catalog shows stale data**.

This is NOT acceptable without a fix. The fix is simple and must ship in V1:

```php
// Record start time before first batch
update_option('wcs_reindex_started_at', current_time('mysql'));

// In batch processor: skip products where live hook already ran
function wcs_batch_should_skip(int $product_id): bool {
    $started = get_option('wcs_reindex_started_at');
    if (!$started) return false;
    $post = get_post($product_id);
    return $post && $post->post_modified > $started; // live hook was fresher — skip
}
```

This reduces the race window to effectively zero. Do not defer to V2.

---

### F4. If a bot retrieves a valid nonce from the frontend, what additional protections exist?

**The nonce claim was overstated.** Correction:

A nonce blocks **stateless HTTP bots only**. A headless browser (Puppeteer, Playwright) can load the page, extract `window.wcs_nonce` (valid for 12 hours by default in WordPress), and enumerate freely.

**Enumeration math with current protections:**
- 26 + 676 + 17,576 = ~18,278 prefix queries to enumerate full catalog
- At 60 req/min rate limit: 305 minutes per IP
- With 10 rotating IPs: ~30 minutes total

**Additional protections needed:**
1. **Enumeration pattern detection:** if an IP searches `a`, `b`, `c`, `d` in sequence within 10s, block for 1 hour
2. **Short nonce TTL:** override to 15-minute validity
3. **Natural limit:** 6 results per query makes full-catalog extraction require thousands of queries across many prefix combinations

**Honest assessment:** A determined scraper with rotating proxies can enumerate the catalog. This is true of every public search API. The defenses create meaningful friction but not a guarantee.

---

### F5. Has a version bump been tested under real traffic to verify no DB spike?

**No. This is an untested assumption.** The "organically distributed cold-start" scenario is theoretical.

**The realistic risk:** 500 popular search terms going cold simultaneously, each dogpile-locked to 1 DB query, could still generate 500 concurrent FULLTEXT queries (~3,000ms total MySQL load burst).

**Required before production claims:**
- Load test: simulate version bump with 500 concurrent shoppers
- Measure: MySQL connection count, query latency p95, CPU spike duration

**Interim mitigation to add:** Proactively pre-warm the top 50 most-searched terms (tracked via a `wcs_popular_terms` counter) immediately after a version bump via a background Action Scheduler job.

---

### F6. What actual catalog data was used to validate the storage estimates?

**None.** The numbers in Q22 are derived from schema column caps, not empirical measurement. The "55 MB for 10k products" figure is a worst-case ceiling calculation.

A realistic average based on typical WooCommerce product data (shorter titles, fewer tags) is closer to **8–15 MB for 10k products** — but this is still an estimate.

**Required before publishing numbers:** Export 1,000 products from a real store, run the indexer, measure `AVG(LENGTH(keywords)), AVG(LENGTH(trigrams))` in the resulting table.

---

### F7. What user-facing impact occurs if jobs are delayed for hours?

| Delayed job | User-facing impact | Severity |
|---|---|---|
| `wcs_index_batch` (initial) | **Zero search results** for all queries | Critical |
| `wcs_async_upsert_product` | Specific products show wrong price/stock | Medium |
| `wcs_deferred_cache_bust` | Cached results slightly more stale than designed | Low |
| `wcs_reconciliation_worker` | ERP changes invisible in search | Medium |

**Most severe case:** Initial indexing on a zero-traffic site. No visitors = WP-Cron never fires = empty search for hours after activation.

**Required fix:** Trigger the first batch synchronously on activation (with a 10s timeout guard), and display a storefront notice while `wcs_index_complete` is not set:
```
"Search results are being prepared. Full results available shortly."
```

---

### F8. What measurable criteria define a successful search result?

**No criteria were defined during architecture. Required before release:**

| Metric | Minimum target |
|---|---|
| Exact title match appears in top 3 | 100% |
| Prefix match (4+ chars) appears | ≥ 95% |
| SKU exact match appears in top 3 | 100% |
| 2-char brand name (LG, HP) appears | ≥ 90% |
| Stopword product ("The IT Bag") appears | 100% |
| 1-char transposition typo → match | ≥ 70% |
| Zero false negatives on exact match | 0% |
| p95 latency under 50 concurrent requests | < 15ms |

**Validation method:** Seed a test store with 500+ products, run 200 search queries across all categories, measure hit rate. Automate as a regression test suite.

---

### F9. How can administrators determine if a rebuild is progressing or stalled?

The count-based approach (`COUNT(index) / COUNT(posts)`) is always near 100% during a non-truncating rebuild. This is misleading.

**Required tracking:**
```php
// On rebuild start:
update_option('wcs_reindex_total',     $total_products);
update_option('wcs_reindex_processed', 0);
update_option('wcs_reindex_started_at', current_time('mysql'));
update_option('wcs_reindex_last_batch', current_time('mysql'));

// After each batch:
update_option('wcs_reindex_processed', $processed + $batch_size);
update_option('wcs_reindex_last_batch', current_time('mysql'));
```

**Stall detection:** If `last_batch` > 10 minutes ago AND `processed < total`, display:
```
⚠️ Stalled — last activity 14 minutes ago. [Check Scheduled Actions] [Retry Rebuild]
```

---

### F10. What production environments have demonstrated acceptable performance beyond 50k products?

**None. No production testing has been performed.**

| Catalog size | Honest claim |
|---|---|
| < 10k | Confident — standard FULLTEXT well-documented at this scale |
| 10k–50k | Reasonable — flat indexed table handles this on shared hosting |
| 50k–100k | Conditional — requires InnoDB buffer pool ≥ 2GB, SSD storage |
| 100k–500k | Speculative — requires dedicated DB server with tuned configuration |
| 500k+ | Out of scope |

**Revised public claim:** "Validated for up to 50k products on standard hosting. Performance beyond 50k is plausible on a tuned dedicated server but has not been benchmarked."

---

## Action Items Before Build

| # | Issue | Severity | Action |
|---|---|---|---|
| F2 | ERP staleness up to 24h | High | 4-hour reconciliation; WP All Import hook |
| F3 | Reindex race overwrites live updates | High | Implement `wcs_reindex_started_at` skip-guard — V1, not V2 |
| F4 | Nonce doesn't block headless scrapers | Medium | Enumeration pattern detection; shorter nonce TTL |
| F5 | Version bump spike untested | Medium | Load test; add popular-term pre-warming |
| F6 | Storage estimates unvalidated | Low | Empirical measurement against real catalog |
| F7 | Initial index delay = zero results | High | Synchronous first batch on activation; storefront notice |
| F8 | No search quality criteria | High | Define and run test suite before release |
| F9 | Rebuild progress inaccurate | Medium | Implement `wcs_reindex_processed` tracking |
| F10 | 100k+ claim unvalidated | Medium | Revise claim to 50k verified; 100k conditional |

