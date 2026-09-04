=== Turbo Search for WooCommerce ===
Contributors:      ozulabs
Tags:              woocommerce, search, product search, live search, ajax search
Requires at least: 6.5
Tested up to:      7.1
Requires PHP:      8.0
Requires Plugins:  woocommerce
Stable tag:        1.6.1
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

= 1.6.1 =
* Fix: the MU cache-bypass fast path (which serves a cached search result before WordPress finishes booting) ignored the Rate Limiting settings on the Settings tab and always enforced a fixed 60 requests/minute, so a cached and an uncached search from the same visitor were governed by two different effective limits. Both paths now read the same configured value.
* Fix: on a site with both editions installed and only one actually active, the same fast path could pick whichever edition merely had a folder present (favoring Pro) instead of the one WordPress had active — for example, running Pro's currency-conversion logic against a request the active Free edition would have served in the store's default currency. It now checks WordPress's own active-plugin state and serves the real REST route instead whenever that can't be resolved to exactly one edition.
* Fix: "Delete All Plugin Data Now" no longer flushes the entire WordPress object cache — that could drop WooCommerce's and other plugins' cached data too on a large store. It now relies on this plugin's own option cleanup, which already invalidates its own cache entries correctly.
* Fix: activating, deactivating, or uninstalling network-wide on a Multisite install stopped processing after the first 1,000 sites. It now pages through every site regardless of network size.
* Housekeeping: the bundled translation template (languages/turbo-search-for-woocommerce.pot) was regenerated — it had been stale since 1.3.0 and was missing several newer strings.

= 1.6.0 =
* Change: removed the free edition's 100-product indexing limit — it now indexes the entire published catalog, with no cap. Existing per-store data is unaffected; the removed cap only stopped new products beyond the 100th from ever being indexed.
* Fix: the coding-standards lint gate (composer lint) failed with 36 findings — an array-formatting issue and one SQL query that built a table name by string interpolation instead of a prepared placeholder. Both are fixed; no query behavior changed.
* Change: the Settings and App Data tabs no longer show several separate disabled Pro-only fields (Search Merchandising, Quick Add to Cart, Search Synonyms, Ranking Weights, Export/Import Settings) or a large promotional card above the working settings. A single compact "Turbo Search Pro" card now appears once, below the complete set of free settings.
* Change: the optional promotional notice's link is now restricted to OzuLabs' own domains and rejects tracking parameters, so a compromised or repurposed endpoint could not point shoppers anywhere else.
* Housekeeping: moved older changelog history out of readme.txt into a separate changelog.txt file.

See changelog.txt for older releases.
