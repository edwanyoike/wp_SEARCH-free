=== Turbo Search for WooCommerce ===
Contributors:      ozulabs
Tags:              woocommerce, search, product search, live search, ajax search
Requires at least: 6.5
Tested up to:      7.1
Requires PHP:      8.0
Requires Plugins:  woocommerce
Stable tag:        1.10.2
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Instant live product search for WooCommerce using native MySQL FULLTEXT indexing.

== Description ==

Turbo Search for WooCommerce replaces WooCommerce's default slow search with a dedicated, FULLTEXT-indexed search engine that returns results as customers type.

A Pro edition adds typo tolerance, synonyms, category/brand suggestions, ranking-weight tuning,
sales-weighted ranking, zero-result search analytics, and multi-currency price support for
serious/high-volume stores — see https://ozulabs.com/plugins/turbo-search/.

**How it works:**

* Builds a dedicated search index table containing only published products — title, SKU, description, and categories.
* Uses native MySQL/MariaDB FULLTEXT indexing for fast, relevance-ranked results.
* Rebuilds happen in the background (via Action Scheduler) so shoppers never see an empty search box.
* Each product update is synced automatically — no manual rebuilds needed.
* Results are cached across multiple layers (object cache → APCu → WordPress transients) so repeat queries cost nothing.

**Requirements:**

* WordPress 6.5+
* WooCommerce 8.0+
* PHP 8.0+

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen.
3. Go to **Turbo Search** (its own top-level menu item) and click **Rebuild Index**.
4. Done. The live search dropdown will appear on your store's search fields automatically.

== Frequently Asked Questions ==

= Do I need to rebuild the index manually? =

Only once, after installation. After that the index updates automatically whenever you save, delete, or import a product.

= What triggers a full rebuild? =

Changing which fields are indexed (title, SKU, description, categories) or renaming a category/tag with many products will trigger a full background rebuild automatically.

= Does it work with multi-currency plugins? =

Multi-currency price conversion (CURCY / WOOCS / WooCommerce Multilingual) is a Pro feature — see https://ozulabs.com/plugins/turbo-search/. The free edition always shows prices in your store's default currency.

= Is there a limit on how many products get indexed? =

No. This free edition indexes your entire published catalog, however large.

= What does the Pro edition add? =

Typo tolerance, search synonyms, category/brand suggestions in the dropdown, ranking-weight tuning, sales-weighted ranking, search merchandising (pin/bury/exclude/redirect), behavioral ranking, click/conversion analytics, Quick Add to Cart, and multi-currency price support. See https://ozulabs.com/plugins/turbo-search/.

= Does it work on WordPress Multisite? =

Yes. Each site in the network gets its own search index table.

= Will deleting the plugin remove my data? =

By default, no. Enable "Delete data on uninstall" in the plugin settings before deleting if you want a clean removal.

== Screenshots ==

1. Live search dropdown showing instant results as a customer types, on a real store selling in KES.
2. The same instant-results dropdown on a real store selling in USD, with product images, prices, and descriptions.

== External services ==

This plugin periodically requests an optional promotional announcement from OzuPay's API at
`https://ozupay.com/wp-json/ozls/v1/promo`. The request is made when an administrator visits the
Turbo Search settings screen and the locally cached response has expired. The only application data
sent is the static plugin-edition identifier `turbo_search_free`; the request never includes
administrator details, product data, search queries, or usage telemetry. As with any outbound
WordPress HTTP API request, the standard `User-Agent` header (which includes this site's URL) is
present, since it is not overridden. Successful responses are cached for 12 hours and failed
responses for 1 hour. The service is provided by OzuLabs: [service website](https://ozupay.com/)
and [privacy policy](https://ozupay.com/privacy-policy/).

== Changelog ==

= 1.10.2 =
* Fix: if a product could not be removed from search after several quick retries (a removed, hidden, or password-protected product), it previously stayed in the index indefinitely unless something else happened to touch that exact product again. It's now retried automatically in the background until it succeeds.
* Fix: a product successfully removed, hidden, or password-protected could still appear in an existing cached search result for up to 5 minutes, since cache refresh for that case waited on the same short delay used for ordinary product edits. Removal now refreshes the cache immediately.
* Fix: if the background signal that refreshes search's cache failed to schedule, stale results could persist for up to 24 hours unless an unrelated product happened to be saved afterward. It's now refreshed immediately in that case instead of depending on later activity.
* Fix: two narrower background-job scheduling gaps (a single product's update retry, and its removal retry, both occasionally not getting rescheduled) are now verified the same way other scheduling in this plugin already is.

= 1.10.1 =
* Fix: a temporary database error while a background rebuild was reading the product list could look identical to "finished reading the whole catalog", causing the rebuild to finish early and publish an index missing every product after that point. The read is now verified and retried the same way other rebuild steps already were.
* Fix: if the database failed to prepare a clean staging area before a rebuild started, or failed the final switch to the newly built index, the rebuild could silently proceed (in the first case) or report success while still serving the old index (in the second). Both are now verified, retried, and — if they keep failing — reported with a specific, actionable error instead of a false success or a silent skip.
* Fix: a product removed, hidden, or password-protected on your store could occasionally remain findable through search if the database briefly failed to remove it from the index. Removal is now retried automatically.
* Fix: a handful of narrower background-job scheduling gaps (a rejected retry-of-a-retry during a rebuild, a single product's update occasionally not reaching the index, a cache-refresh signal occasionally not going out) are now verified and retried the same way the main rebuild scheduling already was.
* Fix: on a temporary search error, the live search dropdown could keep showing that same incomplete result to a shopper for the rest of their visit instead of trying again, because the browser's own short-term cache didn't know the result was incomplete.

= 1.10.0 =
* Improvement: exact-title, "starts with", and "contains as a phrase" ranking boosts now recognize a product title as a match even when it contains punctuation the search box normalizes away — for example searching "t shirt" now gets full ranking credit against a product titled "T-Shirt", the same credit it already got against a title with no punctuation. Triggers a one-time background rebuild.
* Fix: a product with variations (size, color, etc.) could show stale variation SKUs, price range, or stock status in search results after editing a variation directly — WooCommerce fires separate events for variation changes that this plugin wasn't listening for. Creating, editing, changing stock on, deleting, or restoring a variation now refreshes its parent product's search entry.
* Fix: restoring a trashed product (or one of its variations) from the Trash could leave it unsearchable until an unrelated edit or a full rebuild, because WordPress's restore action doesn't go through the same code path as a normal save.
* Fix: a temporary database hiccup during a search (a brief lock wait, a mid-upgrade schema mismatch) could be cached as "no products found" for up to 24 hours, since a failed query and a genuine empty result looked identical internally. A failed query is no longer cached either way.
* Fix: if the background rebuild's job queue rejected a batch partway through a large catalog (the same rare timing issue 1.9.1 fixed for the very first batch), the rebuild could stall indefinitely with no automatic recovery. Every batch is now verified and retried the same way.
* Hardening: if one or more products fail to write into a rebuilt index, the rebuild now still completes with everything else that worked and shows a specific, actionable notice — instead of either silently reporting success or (for a batch where every product failed) leaving the previous, working index in place.

= 1.9.1 =
* Fix: on some hosts, the one-time index rebuild that runs automatically right after an update could silently fail to start — the background job queue wasn't ready yet at that exact moment, so the request to schedule the rebuild was dropped with no error, leaving the admin dashboard showing "Indexing..." indefinitely. This is now detected and retried automatically; if it still can't be scheduled after several attempts, a clear error is now shown on the Turbo Search settings page instead of an indefinite spinner.

= 1.9.0 =
* Improvement: search ranking now narrows to a bounded set of FULLTEXT candidates before applying the full relevance formula, instead of scoring every row a broad query matches. Reduces database work for common single-word searches on large catalogs without changing top-result ranking.
* Improvement: the title matching used in ranking is now precomputed once when a product is indexed instead of being recalculated on every search request.
* Fix: changing the "Number of results" or "Show out-of-stock products" setting could keep showing search results computed under the previous setting for up to 24 hours, because neither setting was part of the search result cache key. Both now refresh cached results immediately.
* Fix: a search term that happened to be a prefix of a product's SKU (for example "ABC1" against a SKU of "ABC10") could return only SKU matches and hide a genuine, stronger match on a completely different product's title or description. Only an exact SKU match now takes priority this way — a mere prefix match no longer suppresses a real result.
* Fix: a handful of words MySQL/MariaDB's default full-text configuration treats as noise (com, de, en, la, und, www) were missing from this plugin's own list of ignored words, which could make an otherwise ordinary multi-word search (for example "cafe de") return nothing.
* Hardening: activation now detects if the required InnoDB database storage engine is unavailable and shows a clear, specific error instead of a confusing raw database error. This plugin has always required InnoDB; virtually every MySQL/MariaDB host has it available by default.

= 1.8.1 =
* Fix: 1.8.0's one-batch-per-check rebuild driver released the batch it claimed only on the success path — if processing that batch failed for any reason, the claim was never released, leaving it stuck until Action Scheduler's own timeout (several minutes) eventually force-released it. It's now always released, success or failure.
* Fix: `[turbo_search_button]` (1.7.0's alias for `[turbo_search]`) always reported itself to WordPress as `turbo_search` for the purpose of the `shortcode_atts_{tag}` customization filter, regardless of which tag a shopper's page actually used — so a site trying to hook `shortcode_atts_turbo_search_button` specifically to customize the alias was silently never called. Each tag now reports itself correctly.

= 1.8.0 =
* Feature: the index rebuild status check now advances the rebuild itself, one step at a time, instead of only watching it — so a rebuild keeps moving even on a host where background scheduling (WP-Cron) is slow to pick up new work. Bounded to exactly one step per check: confirmed live in the Pro edition that an unbounded version of this (processing everything due, not just one step) could drain an entire 2000-product rebuild in a single check, making the progress bar sit at 0% and then jump straight to 100% instead of climbing smoothly.

= 1.7.0 =
* Improvement: `[turbo_search_button]` — the Pro edition's shortcode tag — now also works here, rendering the same search widget as this edition's own `[turbo_search]`. A site's shortcode keeps working either way if it ever switches between editions.

= 1.6.4 =
* Fix: activating this Free edition while Pro was already active silently failed with no explanation — WordPress (including `wp plugin activate`) reported the activation as successful, and the plugin then simply showed as inactive again on the next page load. Confirmed live: the warning notice for this case was real code, but could never actually render, because WordPress redirects immediately after processing an activation request, before any admin notice is ever painted on that request — and by the following page load Free had already been deactivated, so its own code wasn't loaded again to show the notice a second time either. Activating now fails immediately with a clear, visible message explaining that Pro is already active.

= 1.6.3 =
* Change: raised the tested-compatible WooCommerce version from 9.4 to 10.8, the version actually verified live (installed, activated, indexed a real catalog, and passed WordPress's official Plugin Check tool with zero findings) rather than bumped on assumption.

See changelog.txt for older releases.
