=== Turbo Search for WooCommerce ===
Contributors:      ozulabs
Tags:              woocommerce, search, product search, live search, ajax search
Requires at least: 6.5
Tested up to:      7.1
Requires PHP:      8.0
Requires Plugins:  woocommerce
Stable tag:        1.11.1
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

== External services ==

This plugin can optionally request a promotional announcement from OzuPay's API at
`https://ozupay.com/wp-json/ozls/v1/promo`. **This is off by default and never contacts the service
until an administrator turns on "Occasional Announcements" on the Settings tab.** Once enabled, the
request is made when an administrator visits the Turbo Search settings screen and the locally cached
response has expired. The only application data sent is the static plugin-edition identifier
`turbo_search_free`; the request never includes administrator details, product data, search queries,
or usage telemetry. As with any outbound WordPress HTTP API request, the standard `User-Agent` header
(which includes this site's URL) is present, since it is not overridden. Successful responses are
cached for 12 hours and failed responses for 1 hour. Turning the setting back off immediately stops
further requests and stops showing any already-cached announcement. The service is provided by
OzuLabs: [service website](https://ozupay.com/) and [privacy policy](https://ozupay.com/privacy-policy/).

== Changelog ==

= 1.11.1 =
* Fix: if a product's search update couldn't be scheduled at all — both the normal background queue and its own retry safety-net were rejected in the same moment, a rare double failure — the update could previously be lost until that product was saved again or a full rebuild ran. It's now remembered and automatically resubmitted by the plugin's existing daily maintenance task.

= 1.11.0 =
* Security: on a server that hosts more than one WordPress site with APCu enabled (common on shared hosting, and on any Multisite network), two different sites could occasionally see each other's cached search results — product titles, prices, links, images — if their settings and search term happened to match, because the shared search-result cache was not kept separate per site. Same fix applied to the per-visitor search rate limit, which could previously also be shared across sites on the same server. Both are now kept strictly separate per site.
* Change: the optional "check for announcements" feature (an occasional dismissible notice on the Settings page) is now off by default and only ever contacts OzuLabs' server after an administrator turns it on. Turning it off again immediately stops further checks and clears any notice already shown.
* Fix: on a Multisite network, deleting the plugin with "Delete data on uninstall" enabled removed every site's search data based only on whichever site's own copy of that setting WordPress happened to check — even a site that had explicitly left the setting off. Each site's own choice is now respected individually.
* Fix: switching from this Free edition to Pro on the same site (or on a Multisite network where another site still uses either edition) could break Pro's fast-path search caching, because deactivating or removing Free unconditionally deleted a small companion file both editions share. It's now left in place whenever another active edition still needs it.
* Fix: deactivating or uninstalling the plugin could leave a small number of background scheduling tasks behind — retries for an in-progress rebuild or a single product's search update/removal that hadn't finished yet. These are now always cleared, the same way other background tasks already were.
* Housekeeping: documented that "Recent Searches" (on by default) stores a shopper's own past searches only in their own browser, never on the server — see the FAQ.

= 1.10.2 =
* Fix: if a product could not be removed from search after several quick retries (a removed, hidden, or password-protected product), it previously stayed in the index indefinitely unless something else happened to touch that exact product again. It's now retried automatically in the background until it succeeds.
* Fix: a product successfully removed, hidden, or password-protected could still appear in an existing cached search result for up to 5 minutes, since cache refresh for that case waited on the same short delay used for ordinary product edits. Removal now refreshes the cache immediately.
* Fix: if the background signal that refreshes search's cache failed to schedule, stale results could persist for up to 24 hours unless an unrelated product happened to be saved afterward. It's now refreshed immediately in that case instead of depending on later activity.
* Fix: two narrower background-job scheduling gaps (a single product's update retry, and its removal retry, both occasionally not getting rescheduled) are now verified the same way other scheduling in this plugin already is.

See changelog.txt for older releases.
