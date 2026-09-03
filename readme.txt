=== Turbo Search for WooCommerce ===
Contributors:      ozulabs
Tags:              woocommerce, search, product search, live search, ajax search
Requires at least: 6.5
Tested up to:      7.1
Requires PHP:      8.0
Requires Plugins:  woocommerce
Stable tag:        1.6.0
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

= 1.5.1 =
* Fix: 1.5.0's new rate-limit table wasn't actually created on an in-place upgrade (only on a brand-new install) — the schema-version marker that triggers table creation on update wasn't bumped alongside it. Caught before it caused any visible problem (the plugin fails open — allows every request — when this table is missing, exactly to avoid blocking search traffic over an internal gap like this), but the atomic rate limiting from 1.5.0 wasn't actually active on any site that upgraded rather than installed fresh until now.

= 1.5.0 =
* Improvement: search abuse protection is now self-contained instead of leaning on your server's configuration. Previously, the per-visitor request limit was only exact on a host with the APCu extension — without it, two requests arriving at the same instant could both slip through the check before either was recorded, letting roughly double the intended rate through under real concurrent load (confirmed directly: 15 genuinely simultaneous requests with no coordination at all). It's now enforced exactly either way, with no extension or external cache required.
* Feature: a second, much stricter limit specifically for searches that find nothing and fall through every fallback the plugin tries (broadened matching) — the most expensive kind of request, and the shape a scripted flood of random search terms would use to run up load without ever repeating a query the result cache could serve cheaply. A normal shopper never notices it; it only engages once a search would already have come back empty.
* Improvement: both limits — the general one and the new stricter one — are configurable on the Settings tab (New: Rate Limiting), rather than fixed in code.

See changelog.txt for older releases.
