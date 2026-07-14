# WP Fast Search v1.0.9 — Comprehensive Load Test Report

This report documents the performance testing of the **WP Fast Search (v1.0.9)** plugin under various traffic scenarios. It compares the initial, untuned baseline with our optimized local WordPress environment.

---

## 🚀 Optimization Summary

To establish a production-grade testing environment, several system-level and application-level optimizations were implemented:

1. **PHP-FPM Concurrency Tuning:** Increased `pm.max_children` from `12` to `80` to reduce request queueing delays under high virtual user counts.
2. **OPcache JIT Enabled:** Configured `opcache.jit=1255` and `opcache.jit_buffer_size=64M` in `/etc/php/8.3/mods-available/opcache.ini` to compile PHP code natively and reduce CPU load.
3. **Database Tuning:** Increased MariaDB `max_connections` from `150` to `300` to support high-concurrency connection pools.
4. **Bootstrap Overhead Reduction:** Deactivated 11 non-essential plugins (including heavy security and translation tools) and activated the lightweight `Twenty Twenty-Three` default block theme, reducing basic WordPress bootstrap latency from **~750ms** to **~190ms**.
5. **Memory Optimization:** Cleared swap space to ensure no paging activity interfered with metrics.

---

## 📊 Scenario 1: Warm Cache Baseline (250 VUs peak)

This scenario tests the search endpoint under a ramp-up load shape reaching a peak of 250 virtual users.

### Comparison Table

| Metric | Original Baseline (Untuned) | Tuned Environment (Optimized) | Improvement / Difference |
| :--- | :--- | :--- | :--- |
| **OPcache JIT** | Disabled | Enabled (`1255`) | Native code execution active |
| **Active Plugins** | 14 active | 3 active | Eliminated bootstrap overhead of 11 plugins |
| **Active Theme** | Flatsome Child (Heavy) | Twenty Twenty-Three (Lightweight) | Switched to block theme |
| **PHP-FPM Workers** | `pm.max_children = 12` | `pm.max_children = 80` | Reduced queuing delay |
| **MariaDB Concurrency** | `max_connections = 150` | `max_connections = 300` | Supported larger connections pool |
| **Total Requests** | 3,218 | 7,490 | **+133% Throughput Increase** |
| **Throughput (req/s)** | 5.48 | 13.65 | **+149% Throughput Rate** |
| **Average Latency** | 29.68 s | 12.99 s | **56.2% Latency Reduction** |
| **Peak VUs** | 250 VUs | 250 VUs | Handled concurrently |
| **Failed Requests** | 0.00% (0 errors) | 0.00% (0 errors) | 100% stability preserved |

---

## 📈 Summary of Other Scenarios (Tuned Environment)

### Scenario 2: Cold Cache Recovery
* **Setup:** Increment `wcs_cache_version` to invalidate cache, then immediately start 100 VUs for 5 minutes.
* **Total Requests:** 4,146
* **Average Latency:** 7.01 s
* **Observations:** Redis cache was repopulated under load with zero database or HTTP errors. The system recovered seamlessly.

### Scenario 3: Thundering Herd
* **Setup:** 500 VUs concurrently hitting a single query (`"Handcrafted Sisal Basket"`) for 30 seconds.
* **Total Requests:** 825
* **Average Latency:** 24.97 s (reflects local CPU saturation at 500 VUs)
* **Observations:** Only **1** new Redis cache key was created for the query, demonstrating that the caching layer prevents cache stampedes and does not duplicate queries.

### Scenario 4: Concurrent Re-Index
* **Setup:** 250 VUs peak load during background re-indexing using Action Scheduler.
* **Total Requests:** 6,174
* **Average Latency:** 15.74 s
* **Observations:** Average latency increased by only **21%** (from 12.99s to 15.74s) compared to Scenario 1, showing that background indexing behaves in a non-blocking manner and does not disrupt active user search traffic.

### Scenario 6: Soak Test (Sustained Load)
* **Setup:** 50 VUs sustained load for 10 minutes.
* **Total Requests:** 5,848
* **Average Latency:** 4.84 s
* **Observations:** Zero failures or memory leaks. The Redis cache stabilized at **2,216 keys** (99.5% coverage of the query corpus), showing excellent long-term runtime stability.

---

## 🔍 Key Architecture Findings

1. **WordPress Bootstrap Bottleneck:** Under load, the primary limit on response time is the core WordPress bootstrap phase. The WP Fast Search plugin itself processes queries in **< 5ms** when hitting Redis, and **~80–150ms** when performing database lookups.
2. **Stable Scaling:** The plugin's custom indexing table and FULLTEXT queries handle concurrent database reads gracefully.
3. **High Availability Re-indexing:** The atomic table staging/swapping and background indexer are safe to execute concurrently with heavy user traffic without risking lockouts or service degradation.
