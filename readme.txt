=== OzuLabs Turbo Search for WooCommerce ===
Contributors:      fearofbug
Tags:              woocommerce, search, product search, live search, ajax search
Requires at least: 6.5
Tested up to:      7.1
Requires PHP:      8.0
Requires Plugins:  woocommerce
Stable tag:        1.11.17
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

= Does the search box remember what a shopper searched for? =

Optionally, yes — "Recent Searches" (on by default, adjustable or disable-able in the plugin's settings) keeps a shopper's own last few searches so the dropdown can suggest them again next time. This list lives only in that shopper's own browser (`localStorage`), is never sent to your server or to OzuLabs, is not shared between shoppers or devices, and each shopper can clear their own list any time from the search dropdown. Turning the setting off stops new searches from being remembered; it does not retroactively clear what a shopper's browser already stored.

== Screenshots ==

1. Live search dropdown showing instant results as a customer types, on a real store selling in KES.
2. The same instant-results dropdown on a real store selling in USD, with product images, prices, and descriptions.

== Changelog ==

= 1.11.17 =
* Housekeeping: simplified how this plugin keeps its MU companion file current. It previously tracked "is the file up to date" with a separate stored flag, checked once per version; three separate bugs across the last two releases came from that flag drifting out of sync with the actual file on disk. The plugin now just checks the real file directly on every admin page load instead — cheap, and impossible to drift. Also extended the existing "MU file needs attention" admin notice to cover a file that exists but is outdated, not just one that's missing entirely.

= 1.11.16 =
* Fix: if this plugin's MU companion file already existed but was outdated (an interrupted previous update, a permissions issue), and replacing it failed, that failure was recorded as a success — so nothing ever retried, and the site kept running the outdated copy indefinitely with no visible warning. A replacement is now only recorded as done once the file on disk is actually confirmed to match; otherwise the next admin visit tries again.

See changelog.txt for older releases.
