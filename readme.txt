=== Turbo Search for WooCommerce ===
Contributors:      ozulabs
Tags:              woocommerce, search, product search, live search, ajax search
Requires at least: 6.5
Tested up to:      7.0
Requires PHP:      8.0
Requires Plugins:  woocommerce
Stable tag:        1.0.2
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Instant live product search for WooCommerce using native MySQL FULLTEXT indexing.

A Pro edition adds typo tolerance, synonyms, category/brand suggestions, ranking-weight tuning,
sales-weighted ranking, zero-result search analytics, and multi-currency price support for
serious/high-volume stores — see https://ozulabs.com.

== Description ==

Turbo Search for WooCommerce replaces WooCommerce's default slow search with a dedicated, FULLTEXT-indexed search engine that returns results as customers type.

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
3. Go to **Settings → Turbo Search** and click **Rebuild Index**.
4. Done. The live search dropdown will appear on your store's search fields automatically.

== Frequently Asked Questions ==

= Do I need to rebuild the index manually? =

Only once, after installation. After that the index updates automatically whenever you save, delete, or import a product.

= What triggers a full rebuild? =

Changing which fields are indexed (title, SKU, description, categories) or renaming a category/tag with many products will trigger a full background rebuild automatically.

= Does it work with multi-currency plugins? =

Multi-currency price conversion (CURCY / WOOCS / WooCommerce Multilingual) is a Pro feature — see https://ozulabs.com. The free edition always shows prices in your store's default currency.

= What does the Pro edition add? =

Typo tolerance, search synonyms, category/brand suggestions in the dropdown, ranking-weight tuning, sales-weighted ranking, zero-result search analytics, and multi-currency price support. See https://ozulabs.com.

= Does it work on WordPress Multisite? =

Yes. Each site in the network gets its own search index table.

= Will deleting the plugin remove my data? =

By default, no. Enable "Delete data on uninstall" in the plugin settings before deleting if you want a clean removal.

== Screenshots ==

1. Live search dropdown showing instant results as the customer types.
2. Plugin settings and index status with rebuild button.
3. Documentation tab showing triggers and uninstall info.

== Changelog ==

= 1.0.2 =
* Fix: removed the Plugin Name header from the companion MU cache-bypass file
  to prevent WordPress from listing it as a separate plugin or generating
  incorrect activation links during install.

= 1.0.1 =
* Fix: running the Free and Pro editions simultaneously on the same site is now
  blocked — activating Free while Pro is already active shows a clear "Plugin
  Conflict" error instead of silently double-registering the REST route, daily
  GC cron, and search index tables.
* Fix: garbage-collection cron no longer references the analytics log table
  (`wcs_search_log`), which this edition never creates; the prune block was
  unreachable dead code inherited during the initial port.

= 1.0.0 =
* Initial WordPress.org release — the free core edition of Turbo Search for WooCommerce. Live product search using native MySQL/MariaDB FULLTEXT indexing, background indexing via Action Scheduler, live index sync on product save/delete/stock change, multi-layer result caching (object cache / APCu / transients), search across title/SKU/content/categories, and full WooCommerce Multisite support.
