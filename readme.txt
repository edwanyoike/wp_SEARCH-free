=== Turbo Search for WooCommerce ===
Contributors:      ozulabs
Tags:              woocommerce, search, product search, live search, ajax search
Requires at least: 6.5
Tested up to:      7.1
Requires PHP:      8.0
Requires Plugins:  woocommerce
Stable tag:        1.7.0
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

= 1.6.4 =
* Fix: activating this Free edition while Pro was already active silently failed with no explanation — WordPress (including `wp plugin activate`) reported the activation as successful, and the plugin then simply showed as inactive again on the next page load. Confirmed live: the warning notice for this case was real code, but could never actually render, because WordPress redirects immediately after processing an activation request, before any admin notice is ever painted on that request — and by the following page load Free had already been deactivated, so its own code wasn't loaded again to show the notice a second time either. Activating now fails immediately with a clear, visible message explaining that Pro is already active.

= 1.6.3 =
* Change: raised the tested-compatible WooCommerce version from 9.4 to 10.8, the version actually verified live (installed, activated, indexed a real catalog, and passed WordPress's official Plugin Check tool with zero findings) rather than bumped on assumption.

See changelog.txt for older releases.
