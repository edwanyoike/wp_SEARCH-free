=== OzuLabs Turbo Search for WooCommerce ===
Contributors:      fearofbug
Tags:              woocommerce, search, product search, live search, ajax search
Requires at least: 6.5
Tested up to:      7.1
Requires PHP:      8.0
Requires Plugins:  woocommerce
Stable tag:        1.11.14
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

= 1.11.14 =
* Fix: 1.11.13's protection against installing this plugin's MU companion file alongside a leftover copy from an old intermediate build only covered normal updates — activating the plugin fresh on a site with that leftover copy still present could install a second, conflicting copy and make every page fail to load. The same protection now applies everywhere this file gets installed, including activation.
* Housekeeping: reworded a historical changelog entry (1.11.10) that described a specific real-world symptom more definitively than was actually verified at the time.

= 1.11.13 =
* Fix: a site updating through an old intermediate build could end up with two copies of this plugin's MU companion file installed at once, which made every page on the site fail to load. Removing the old copy no longer depends on a one-time migration step that may have already run; the new copy is never installed unless the old one is confirmed gone first.
* Housekeeping: removed the remaining Pro-only multi-currency detection code from this edition's MU companion file — it never had any effect here (this edition always prices in your store's own currency), but it shouldn't have shipped in this edition's files at all.
* Housekeeping: the Settings tab's shortcode example now shows [otsw_search], matching the Documentation tab and the plugin's own primary tag.

= 1.11.12 =
* Housekeeping: removed the remaining backend plumbing for typo-corrected search queries — a Pro-only feature this edition never actually produced results for. The always-empty property, the cache wrapper it used, and the response header it triggered are gone; 1.11.10's changelog entry described the frontend half of this removal, this finishes the backend half.
* Housekeeping: renamed the remaining internal HTML/CSS/JavaScript identifiers (previously a 3-letter prefix) to the plugin's distinctive prefix, for compliance with WordPress.org's plugin identifier guidelines. This is an internal rename with no effect on how the plugin looks or behaves; a custom theme/CSS snippet that specifically targeted the plugin's old class names would need updating to the new ones.
* Added: [otsw_search] is now the primary search-form shortcode. The existing [turbo_search] shortcode keeps working exactly as before.

= 1.11.11 =
* Fix: on some mobile browsers, the search dropdown's recent-searches and suggestion rows could render with a much larger font than specified, making those rows look oversized. Caused by the browser's automatic text-size boosting, which is now explicitly disabled for the dropdown so its sizing always renders as designed.

= 1.11.10 =
* Renamed: this plugin is now "OzuLabs Turbo Search for WooCommerce" (slug: ozulabs-turbo-search-for-woocommerce). If you're updating from an earlier version, existing settings and your search index carry over automatically.
* Fix: removed code that read a shopper's selected currency and could apply its symbol to search-result prices without converting the underlying amount. This edition doesn't convert currency, so it now always shows the store's own currency and symbol together, matching what this readme has always said.
* Fix: when a search hit an internal rate limit, the resulting incomplete result could get cached and served to every other shopper searching the same term for up to 24 hours. That kind of result is no longer cached.
* Fix: a background indexing request could briefly block outbound connections needed by unrelated plugins or payment/webhook calls running in the same batch. That block is now scoped to only this plugin's own indexing work.
* Housekeeping: removed several inactive, Pro-only code paths that had no effect in this Free edition (ranking by recent sales, synonym matching, corrected-query and category-suggestion UI).
* Housekeeping: internal identifiers were renamed for WordPress.org compliance. Sites updating from an earlier version have their settings migrated automatically and their search index rebuilt once, in the background.

= 1.11.9 =
* Fix: on longer result lists (more common on mobile, where the dropdown has less vertical space to work with), rows near the bottom of the visible area could be squeezed shorter than their own title/price/excerpt content, causing that content to visually spill into the row below it instead of the dropdown scrolling as intended. 1.11.8's fix addressed a different, real-but-unrelated rendering issue and did not fix this one.

See changelog.txt for older releases.
