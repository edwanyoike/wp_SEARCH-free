# WP Fast Search — Load Test Runbook
## 4,296 Product Test on zuriancrafts.com (Local)

> **Note:** Target was 10,000 products. Redis ObjectCacheException crashed the WP-CLI seeder
> at 4,296 (537 real + 3,759 seeded with LT- SKU prefix). Tests were run at this catalogue size.

Run these steps **in order**. Each step gives you a confirmation before you move to the next.

---

## 🖥️ Test Machine Specification

> All results in this test run were recorded on the following machine. This is a **developer laptop / local server** — not a cloud VPS. Results reflect a realistic self-hosted environment.

| Component | Detail |
|-----------|--------|
| **Machine type** | Developer laptop (local server) |
| **OS** | Ubuntu 24.04.4 LTS (Noble Numbat) — Kernel 6.17.0-35-generic |
| **CPU** | Intel Core i7-4600U @ 2.10 GHz (2 cores / 4 threads, max 3.3 GHz) |
| **RAM** | 16 GB total — ~6.5 GB available during test |
| **Swap** | 4 GB (note: 3.9 GB used at baseline — swap pressure exists) |
| **Disk** | 233 GB SSD, 116 GB free |
| **Web server** | Nginx 1.24.0 |
| **PHP** | 8.3.6 (OPcache ON, JIT **disabled**) |
| **Database** | MariaDB 10.11.14 (`max_connections = 150`) |
| **Object Cache** | Redis 7.0.15 + Object Cache Pro (drop-in) |
| **APCu** | **Not installed** |
| **WordPress** | 7.0 |
| **WooCommerce** | 10.8.1 |
| **WP Fast Search** | 1.0.9 |
| **Load tool** | k6 (install before running — see Step 0) |
| **Test date** | _(fill in when run)_ |

### ⚠️ Known Hardware Constraints
- **Swap nearly full (3.9 / 4.0 GB used)** at baseline — heavy swap activity will artificially inflate latency. Monitor with `vmstat 2` during tests.
- **JIT disabled** — enabling OPcache JIT (`opcache.jit=1255`) in `/etc/php/8.3/fpm/php.ini` would reduce PHP overhead by ~15–20%. Results without JIT are a conservative baseline.
- **Single machine** — load generator (k6) and server run on the same CPU. At high VU counts (250+) k6 itself consumes cores. Treat peak RPS figures as a lower bound vs. a dedicated test machine.

---

## Step 0 — Install k6 (one-time)

```bash
sudo gpg --no-default-keyring \
  --keyring /usr/share/keyrings/k6-archive-keyring.gpg \
  --keyserver hkp://keyserver.ubuntu.com:80 \
  --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69

echo "deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] \
  https://dl.k6.io/deb stable main" \
  | sudo tee /etc/apt/sources.list.d/k6.list

sudo apt-get update && sudo apt-get install k6

k6 version  # confirm
```

---

## Step 1 — Seed 10,000 Products

```bash
wp eval-file tests/loadtest/seed-products.php \
    --path=/var/www/zuriancrafts.com \
    --allow-root \
    10000 100
```

Expected output:
```
Existing published products : 537
Target                      : 10000
Will seed                   : 9463
✓ Inserted 9463 products. Failed: 0.
✓ Re-index job queued in Action Scheduler.
```

**Time estimate:** ~8–12 minutes for 9,463 products at batch=100.

---

## Step 2 — Wait for WCS Re-Index to Complete

```bash
# Watch the Action Scheduler queue drain
watch -n 5 "wp action-scheduler list \
    --status=pending \
    --group=wp-fast-search \
    --path=/var/www/zuriancrafts.com \
    --allow-root \
    --format=count"
```

Done when count reaches **0**. Then verify:

```bash
# Should be close to 10,000
mysql -u root zuriancrafts_db -e \
  "SELECT COUNT(*) FROM wp_wcs_search_index;"

# Or via WP-CLI
wp option get wcs_index_complete \
    --path=/var/www/zuriancrafts.com \
    --allow-root
```

---

## Step 3 — Generate the Query Corpus

```bash
wp eval-file tests/loadtest/gen-corpus.php \
    --path=/var/www/zuriancrafts.com \
    --allow-root
```

Expected output:
```
Index table has 10000 rows.
✓ 2847 unique queries written.
  single     : 1980
  phrase     : 819
  full_title : 32
  stopword   : 10
  garbage    : 6
```

Confirms two files exist:
- `tests/loadtest/corpus.json`
- `tests/loadtest/corpus.txt`

---

## Step 4 — Get a Fresh Nonce

```bash
export WP_NONCE=$(wp eval \
    'echo wp_create_nonce("wp_rest");' \
    --path=/var/www/zuriancrafts.com \
    --allow-root)
echo "Nonce: $WP_NONCE"
```

> ⚠️ WordPress nonces expire after **24 hours**. Re-run this command if you pause overnight.

---

## Step 5 — Smoke Test (1 VU, 10 requests)

Before full load, confirm the endpoint works:

```bash
k6 run \
  -e WP_URL=https://zuriancrafts.com \
  -e WP_NONCE=$WP_NONCE \
  --vus 1 --iterations 10 \
  tests/loadtest/s1_warm_cache.js
```

All checks must pass before proceeding.

---

## Step 6 — Scenario 1: Warm Cache Baseline

```bash
mkdir -p tests/loadtest/results

k6 run \
  -e WP_URL=https://zuriancrafts.com \
  -e WP_NONCE=$WP_NONCE \
  --out json=tests/loadtest/results/s1_warm_cache.json \
  tests/loadtest/s1_warm_cache.js
```

**Watch Redis during test (separate terminal):**
```bash
watch -n 2 "redis-cli info stats | grep -E 'keyspace_hits|keyspace_misses'"
```

Expected: hit ratio > 98% after first 60s.

---

## Step 7 — Scenario 2: Cold Cache Recovery

```bash
# Bust the cache (bump version key — works with Redis)
wp option update wcs_cache_version \
    $(( $(wp option get wcs_cache_version \
        --path=/var/www/zuriancrafts.com --allow-root) + 1 )) \
    --path=/var/www/zuriancrafts.com \
    --allow-root

# Immediately start load (no pause — measure cold recovery)
k6 run \
  -e WP_URL=https://zuriancrafts.com \
  -e WP_NONCE=$WP_NONCE \
  --vus 100 --duration 5m \
  --out json=tests/loadtest/results/s2_cold_cache.json \
  tests/loadtest/s1_warm_cache.js
```

---

## Step 8 — Scenario 3: Thundering Herd

```bash
# All 500 VUs hit one single query at once
k6 run \
  -e WP_URL=https://zuriancrafts.com \
  -e WP_NONCE=$WP_NONCE \
  -e FIXED_QUERY="Handcrafted Sisal Basket" \
  --vus 500 --duration 30s \
  --out json=tests/loadtest/results/s3_herd.json \
  tests/loadtest/s1_warm_cache.js
```

After: `redis-cli --scan --pattern "*wcs_v*" | wc -l` should be = 1 (one key for that query).

---

## Step 9 — Scenario 4: Concurrent Re-Index

```bash
# Terminal 1 — trigger a full re-index
wp eval \
    'as_schedule_single_action(time(),"wcs_process_batch",["offset"=>0],"wp-fast-search");' \
    --path=/var/www/zuriancrafts.com \
    --allow-root

# Terminal 2 — run load test simultaneously
k6 run \
  -e WP_URL=https://zuriancrafts.com \
  -e WP_NONCE=$WP_NONCE \
  --out json=tests/loadtest/results/s4_concurrent_index.json \
  tests/loadtest/s1_warm_cache.js
```

Compare p99 against Scenario 1 results. Target: < 15% increase.

---

## Step 10 — Scenario 6: 30-min Soak

```bash
k6 run \
  -e WP_URL=https://zuriancrafts.com \
  -e WP_NONCE=$WP_NONCE \
  --vus 50 --duration 30m \
  --out json=tests/loadtest/results/s6_soak.json \
  tests/loadtest/s1_warm_cache.js
```

**Soak monitors (run every 5 min in a separate terminal):**
```bash
watch -n 300 "
echo '--- $(date) ---';
redis-cli info memory | grep used_memory_human;
redis-cli --scan --pattern '*wcs_v*' | wc -l;
mysql -u root zuriancrafts_db -se 'SHOW STATUS LIKE \"Threads_connected\";'
"
```

---

## Step 11 — Cleanup Seeded Data (After Testing)

```bash
# Dry run first — see what will be deleted
wp eval-file tests/loadtest/cleanup-seed.php \
    --path=/var/www/zuriancrafts.com \
    --allow-root \
    dry

# Then actually delete
wp eval-file tests/loadtest/cleanup-seed.php \
    --path=/var/www/zuriancrafts.com \
    --allow-root
```

---

## Quick Reference

| Command | Purpose |
|---------|---------|
| `wp action-scheduler list --status=pending --group=wp-fast-search` | Check indexer queue |
| `redis-cli info stats \| grep keyspace` | Cache hit/miss ratio |
| `redis-cli --scan --pattern "*wcs_v*" \| wc -l` | Count WCS cache keys |
| `redis-cli info memory \| grep used_memory_human` | Redis memory during soak |
| `wp option get wcs_cache_version` | Current cache version |
| `wp cache flush` | Full Redis flush (nuclear option) |

---

## 🗺️ What's Next — After the Test

### Immediate (fill in results)
1. **Fill in the results table** in `LOADTEST_PLAN.md` §8 (the reporting statement for the docs/README).
2. **Archive raw k6 JSON** — commit `tests/loadtest/results/*.json` to a `loadtest/v1.0.9` git branch so results are reproducible.
3. **Fill in test date** in the machine spec table above.

### Based on What You Find

| If you see... | Action |
|---------------|--------|
| p99 > 150 ms on warm cache | Investigate Redis hit ratio — may need `wp cache flush` before S1 to force a clean warm-up |
| HTTP 5xx during thundering herd (S3) | Add a `wp_cache_add()` mutex guard in `Search_Handler::handle_request()` |
| p99 spike > 500 ms during cold cache (S2) | MariaDB FULLTEXT cold path is slow — consider a query cache or index pre-warm cron |
| Latency degrades in soak (S6) | Check PHP-FPM pool — may need `pm.max_children` increased in `/etc/php/8.3/fpm/pool.d/www.conf` |
| Redis memory grows in soak | Cache TTL or GC issue — check `wcs_daily_transient_gc` scheduled action exists |
| MariaDB `Threads_connected` hits 150 | Hit `max_connections` ceiling — raise to 300 in `/etc/mysql/mariadb.conf.d/50-server.cnf` |

### Before Publishing Results
- [ ] Note that JIT was **disabled** — mention results are a conservative baseline
- [ ] Note swap pressure (3.9 / 4.0 GB) — results on a clean-boot machine would be better
- [ ] Consider a second run with `swapoff -a` temporarily to eliminate swap noise
- [ ] Add a one-liner to the plugin README pointing to this plan

### Optional: Tighten the Hardware for Retest
```bash
# 1. Enable OPcache JIT (edit /etc/php/8.3/fpm/php.ini)
opcache.jit=1255
opcache.jit_buffer_size=64M

# 2. Free up swap
sudo swapoff -a && sudo swapon -a

# 3. Restart services cleanly before each scenario
sudo systemctl restart php8.3-fpm nginx

# 4. Re-run S1 with JIT enabled and compare p99 delta
```
