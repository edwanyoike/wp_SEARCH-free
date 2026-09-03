=== Turbo Search for WooCommerce ===
Contributors:      ozulabs
Tags:              woocommerce, search, product search, live search, ajax search
Requires at least: 6.5
Tested up to:      7.1
Requires PHP:      8.0
Requires Plugins:  woocommerce
Stable tag:        1.5.0
License:           GPLv2 or later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Instant live product search for WooCommerce using native MySQL FULLTEXT indexing.

== Description ==

Turbo Search for WooCommerce replaces WooCommerce's default slow search with a dedicated, FULLTEXT-indexed search engine that returns results as customers type.

This free edition indexes up to 100 products. A Pro edition removes that limit and adds typo
tolerance, synonyms, category/brand suggestions, ranking-weight tuning, sales-weighted ranking,
zero-result search analytics, and multi-currency price support for serious/high-volume stores —
see https://ozulabs.com.

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

Multi-currency price conversion (CURCY / WOOCS / WooCommerce Multilingual) is a Pro feature — see https://ozulabs.com. The free edition always shows prices in your store's default currency.

= Is there a limit on how many products get indexed? =

Yes — this free edition indexes up to 100 published products (chosen by lowest product ID first). Existing indexed products keep updating normally; products beyond the 100th are simply not searchable until you upgrade. Upgrading to Pro removes the limit entirely. A notice appears on the Settings tab whenever your catalog exceeds the limit.

= What does the Pro edition add? =

No 100-product indexing limit, plus typo tolerance, search synonyms, category/brand suggestions in the dropdown, ranking-weight tuning, sales-weighted ranking, search merchandising (pin/bury/exclude/redirect), behavioral ranking, click/conversion analytics, Quick Add to Cart, and multi-currency price support. See https://ozulabs.com.

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

= 1.5.0 =
* Improvement: search abuse protection is now self-contained instead of leaning on your server's configuration. Previously, the per-visitor request limit was only exact on a host with the APCu extension — without it, two requests arriving at the same instant could both slip through the check before either was recorded, letting roughly double the intended rate through under real concurrent load (confirmed directly: 15 genuinely simultaneous requests with no coordination at all). It's now enforced exactly either way, with no extension or external cache required.
* Feature: a second, much stricter limit specifically for searches that find nothing and fall through every fallback the plugin tries (broadened matching) — the most expensive kind of request, and the shape a scripted flood of random search terms would use to run up load without ever repeating a query the result cache could serve cheaply. A normal shopper never notices it; it only engages once a search would already have come back empty.
* Improvement: both limits — the general one and the new stricter one — are configurable on the Settings tab (New: Rate Limiting), rather than fixed in code.

= 1.4.1 =
* Fix: 1.4.0's "one word missing from the catalog still finds the rest" relaxation had a gap — it only covered queries with at least one longer, FULLTEXT-eligible word. A query made entirely of short words (each under 4 characters) skipped that entirely, so it could still come back empty even when products matching either word plainly existed — confirmed live: "egg mix" returned nothing despite an egg product and a "mix" product both being in the catalog. A matching fallback now covers this case too.

= 1.4.0 =
* Fix: a search containing an ordinary connecting word returned nothing at all. Every word in a query has to match, so "for", "with", "the" and the like had to appear in a product's own title or SKU — confirmed live on a real store, where "bacon" found a product and "bacon for", "bacon with" and "the bacon" each found none. Those words are now dropped from a multi-word search (short, genuinely selective terms like "LG", "HP" or "3M" are kept, and a search made up entirely of such words still searches for them).
* Fix: a search whose FULLTEXT pass found only one or two products stopped there instead of filling the rest of the dropdown. Prefix matches now top up a partial result set the way the substring pass already did, so a search asking for 6 results no longer shows 1 when 5 more genuinely matched. The stronger FULLTEXT matches keep their positions at the top.
* Improvement: searches that skip the FULLTEXT index (queries made up of short words, like "lg tv") previously had no relevance ranking at all — they were ordered by total sales, then alphabetically. Each word now scores against the product title, so a product matching more of what was typed ranks above one matching less, with popularity only breaking the ties that remain.
* Improvement: a multi-word search where one word matches nothing in the catalog no longer comes back empty. As a last resort — and only when the alternative is an empty dropdown — the search re-runs allowing any of the words to match, ranked so that products matching more of the query come first.

= 1.3.1 =
* Improvement: the divider line between result rows is a bit more visible.

= 1.3.0 =
* Improvement: Recent Searches (the last few searches offered again on an empty search box) is now configurable on the Settings tab — turn it off entirely, or change how many are remembered (1–10, default 5, same as before). Previously always on with a fixed count of 5.

= 1.2.4 =
* Change: the Settings tab now shows a disabled Quick Add to Cart field with an upsell to Turbo Search Pro, matching the existing pattern for other Pro-only fields.

= 1.2.3 =
* Fix: the 1.2.1 title-prefix fix wasn't enough on its own — confirmed live on a real store. The "title contains query as a phrase" boost had the identical raw-character flaw (searching "dog" still credited "Dogo African Choker Necklace" via a plain '%dog%' substring match), which was enough to tie it with genuine "... dog ..." titles and hand the win to an unrelated total_sales/alphabetical tiebreak. The phrase boost now requires "dog" to appear as a genuine whole word too, the same as the prefix boost already did.
* Fix: the result dropdown's width was set to exactly match the search box it's attached to, with no minimum — a theme with a compact header search box (a couple hundred px) forced an equally cramped, hard-to-read dropdown. Now has a sensible minimum width, capped so it can never overflow past the edge of the screen.

= 1.2.2 =
* Fix: result titles in the dropdown were forced onto a single line and cut off with "…" as soon as they ran out of room — on a narrow mobile screen this could leave a title reading as just its first word or two, hard to scan and easy to mistake for a shorter, differently-named product. Titles now wrap onto 2 lines.

= 1.2.1 =
* Fix: the "title starts with query" ranking boost matched on raw characters, not whole words — so searching "dog" could rank an unrelated product like "Dogo African Choker Necklace" above genuine dog collars, since "Dogo" also starts with the letters "d-o-g". The boost now requires the match end at a word boundary (the query alone, or the query followed by a space), so a short word that happens to prefix a longer, different word no longer gets an unearned edge over products that actually contain that word.

= 1.2.0 =
* Feature: recent searches — the last 5 searches are remembered per browser and offered again when the search box is focused, with a one-click "Clear."
* Improvement: exact title/SKU matches and title-prefix matches now get a stronger built-in ranking boost.
* Improvement: the search dropdown shows a "Searching products…" state immediately instead of staying blank while a request is in flight, and a clear "Search is temporarily unavailable — please try again" message if the request fails.
* Improvement: the no-results message now includes a helpful hint ("Try another spelling or a shorter search.").
* Change: the Settings tab now shows a disabled Search Merchandising field with an upsell to Turbo Search Pro, matching the existing pattern for other Pro-only fields.

= 1.1.2 =
* Feature: the App Data tab and the Settings tab's Pro summary now mention Export/Import Settings, a Pro-only feature for moving configuration between sites as a JSON file — matches the disabled-stub-plus-upsell-copy pattern already used for other Pro-only fields.

= 1.0.5 =
* Change: "Delete Data on Uninstall" and the "Delete All Plugin Data Now" danger-zone action moved off the Settings tab into their own new "App Data" tab (parity fix — this shipped for the Pro edition in 1.3.40/1.3.41 but was missed here).
* Feature: the Settings tab now shows what Pro adds — an "Unlock More With Turbo Search Pro" summary, plus a disabled Search Synonyms field and a Ranking Weights explainer, each linking to ozulabs.com.

= 1.0.4 =
* Change: Turbo Search now has its own top-level admin menu item instead of living under Settings, so it's visible directly in the main sidebar.

= 1.0.3 =
* Feature: the Settings page can now show an occasional dismissible promo/announcement banner, sourced from ozupay.com and configured centrally — no plugin update needed to change it. Dismissing it is permanent per admin; editing the banner's content automatically re-shows it once, even to admins who dismissed an earlier version.

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
