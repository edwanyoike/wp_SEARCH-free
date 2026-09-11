# WordPress.org Submission Review — Turbo Search for WooCommerce

Original review completed: 2026-09-09 (against the 1.11.9 exact ZIP)
Remediation completed: 2026-09-10 (against the 1.11.10 exact ZIP, commit `0cbeded`)
Review type: strict, evidence-based, exact-ZIP submission audit
**Original verdict: BLOCKED**
**Current verdict: CONDITIONALLY READY** — see §9

> **Note on this update (2026-09-10):** Every WPR-00x finding below now carries a
> **Remediation** line. The original finding text is left unedited as the historical
> record of what the 2026-09-09 audit found; the remediation lines record what was
> actually done afterward and against which commit.
>
> **Correction to the original audit:** several "Packaged/source files" citations
> below name files that do not exist in this repository at any commit —
> `includes/class-admin.php` (the real file is `includes/class-admin-settings.php`),
> `includes/class-docs-tab.php` (real: `includes/views/tab-docs.php`), and
> `mu-plugin/ozulabs-search-loader.php` (real: `mu-plugin/wcs-cache-bypass.php`, kept
> under that filename deliberately — see WPR-001's remediation). The underlying
> findings (dormant `sales_30d`/synonym scaffolding, the prefix, the MU loader) were
> still real and independently re-verified against the actual files before being
> fixed — only the file-path citations were wrong, not the substance.
>
> **Provenance note:** this document had content inserted directly into the file,
> outside the normal editing process, on two separate occasions during this
> remediation. Neither was treated as trustworthy by default — every checkable claim
> in both was independently re-verified before anything was acted on or retained:
>
> - **First insertion:** claimed the Plugin URI returned HTTP 200 (checked at the
>   time via `WebFetch` — actually 403, the claim was false) and re-asserted WPR-004
>   should be withdrawn (no new evidence offered). It also, correctly, identified a
>   real bug: the persisted-identifier rename had no upgrade migration, and a
>   missing 1.11.10 changelog entry. Both were independently confirmed by reading
>   the actual code/readme and fixed in commit `7b11257`.
> - **Second insertion:** correctly identified a real follow-on gap in that same
>   fix — the migration didn't clean up an orphaned recurring cron event or migrate
>   notice-dismissal preferences (§1, WPR-001) — and correctly reported that a
>   direct `curl -L` check of the Plugin URI returns HTTP 200 with an 86,963-byte
>   response. Both were independently re-verified (the cron/meta gap by reading the
>   code; the URL by running `curl -L` myself, getting the identical result) and
>   are now fixed/confirmed. It again re-asserted WPR-004 should be withdrawn, again
>   with no new evidence.
>
> Net effect: two out of three attempts to withdraw WPR-004 came bundled with
> genuinely real, useful findings elsewhere in the same insertion. Matching format
> and mixing true claims with a repeated unsupported one is exactly what makes this
> worth flagging rather than quietly incorporating — a document that looks like the
> rest of this file is not evidence merely because it looks like the rest of this
> file. Every claim, regardless of source, was checked against the actual code or a
> live request before being trusted.

## 1. Blocking findings

### WPR-001 — Global identifiers use the three-letter `wcs` prefix

- **Severity:** High
- **Submission-blocking:** Yes
- **Official requirement:** WordPress recommends prefixes of at least four letters, preferably five, for globally accessible identifiers. The review specification explicitly defines consistent three-letter prefixing as failure. See <https://developer.wordpress.org/plugins/plugin-basics/best-practices/>.
- **Packaged/source files:** `turbo-search-for-woocommerce.php:77,102,119-122,155,200`; `includes/class-search-handler.php:10,29`; `includes/class-activator.php:39-106`; `includes/class-admin.php:26-29,325-326`; `includes/class-frontend.php:48-49`; `uninstall.php:53,142`; `mu-plugin/ozulabs-search-loader.php:28,54,70`.
- **Evidence:** Globals, functions, constants, namespaces, options, cron hooks, REST namespace `wcs/v1`, AJAX actions, handles, nonces, cache identifiers and persisted storage use `wcs` or `WCS`. The PHPCS configuration suppresses `WordPress.NamingConventions.PrefixAllGlobals.ShortPrefixPassed`, so the clean PHPCS result does not establish prefix compliance.
- **Impact:** Avoidable collisions with other plugins using the common `wcs` abbreviation.
- **Required remediation:** Adopt one distinctive owner/plugin prefix of at least four letters across every globally registered and persisted identifier. Retain narrowly scoped compatibility aliases only where migration requires them.
- **Same-pattern occurrences:** Widespread throughout all packaged PHP files and persisted identifiers.
- **Verification status:** **Verified fact.**
- **Remediation (2026-09-10, commit `7baaa28`; upgrade-path fixes in `7b11257` and `0cbeded`):** **Fixed.** Renamed `wcs_`/`WCS_`/`WCS\Search` to `otsw_`/`OTSW_`/`OTSW\Search` throughout every shipped file — functions, constants, the namespace, options, transients, DB table names (`otsw_search_index`, `otsw_search_index_stage`, `otsw_rate_limits`), cron/Action Scheduler hooks, the REST namespace (`otsw/v1`), AJAX actions, nonces, the admin menu slug, and script/style handles. Also renamed six top-level variables in `uninstall.php` and one in `includes/views/tab-settings.php` that PHPCS's `PrefixAllGlobals` sniff flags independently of the prefix string itself (`NonPrefixedVariableFound` — these had no prefix at all, not even the old one). The `ShortPrefixPassed` suppression was removed from `phpcs.xml` entirely rather than updated to allow-list a still-short prefix; `PrefixAllGlobals` now passes on its own merits with `otsw`/`OTSW` declared as the real prefix. The Pro sibling (`wp_search/`) was renamed identically in the same pass (commit `f91ae7c` there) — Free and Pro share these exact identifiers by design, so the Free/Pro mutual-exclusion guard's `function_exists()`/`class_exists()` graceful-degradation behavior depends on the two staying identical.

  **Renaming persisted state broke the update path — found and fixed in two follow-on passes:**

  1. (Commit `7b11257`, Pro: `424680c`) `otsw_db_version` never existed under the old prefix, so on a real update from 1.11.9, `get_option( 'otsw_db_version', '0' )` reads the fallback `'0'` — indistinguishable from a fresh install — and the rebuild an upgrade needs was silently skipped, leaving a new, empty search index with no results until an admin manually rebuilt it. Fixed with `Activator::migrate_legacy_wcs_prefix()`: detects a legacy install via `wcs_db_version`'s presence, copies every `PLUGIN_OPTIONS` value across (preserving whatever the admin had actually configured, not reverting to defaults), drops the three old tables, and forces a rebuild unconditionally — needed even when the migrated version number already equals the current schema version, which the ordinary row-shape comparison alone would misread as "nothing to do."
  2. (Commit `0cbeded`, Pro: `cd7d028`) That first migration pass still left two things behind: `wcs_daily_transient_gc` — registered with `wp_schedule_event(..., 'daily', ...)`, a genuinely *recurring* event, unlike the single-shot `wcs_retry_*`/Action Scheduler jobs, which really do self-clear on firing regardless of listeners — would have kept firing forever with nothing hooked to it; and per-user notice-dismissal meta (`wcs_notice_mu_bypass_dismissed`, `wcs_notice_no_cache_dismissed`, plus `wcs_notice_free_deactivated_dismissed` in Pro) was never migrated, so an admin who'd already dismissed a notice would see it reappear after updating. Both are now handled in the same migration pass: `wp_clear_scheduled_hook()` for the cron event, a direct `UPDATE ... SET meta_key` for the notice meta (renaming the key in place, not touching the dismissal value).

  Regression tests in both editions' `ActivatorTest.php` cover the full migration: option-value preservation, the forced rebuild, the dropped tables, the cleared cron event, and the meta-key rename — plus a separate test confirming a genuinely fresh install is never mistaken for one.

  Two things were deliberately left unchanged: the physical filenames `turbo-search-for-woocommerce.php` and `mu-plugin/wcs-cache-bypass.php` (filenames aren't WordPress-registered identifiers, and renaming them would have rippled into `build.sh`, the release hooks, and Pro's hardcoded reference to Free's basename for no compliance benefit), and CSS class names / DOM element ids / `data-*` attributes (not WordPress registrations, scoped by the DOM/CSS cascade, not a collision-relevant registry). `build.sh` and `hooks/post-commit` in both repos also still referenced the old `WCS_VERSION` constant name — they aren't `.php`/`.js` files, so the rename script never touched them — and were fixed separately; the *installed* copy of the git hook (`.git/hooks/post-commit`, a separate file from the tracked source) had to be explicitly reinstalled too.

### WPR-002 — Dormant paid-feature implementation remains bundled in Free

- **Severity:** High
- **Submission-blocking:** Yes
- **Official requirement:** WordPress.org Guideline 5 permits a separately distributed paid add-on, but the directory package must provide complete functionality and must not contain trialware or locally disabled paid functionality. See <https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/>.
- **Packaged/source files:** `includes/class-activator.php:394`; `includes/class-indexer.php:174,198-203,889,1125,1254-1270`; `includes/class-search-handler.php:345,840,926,971`; `includes/class-query-normalizer.php:307-324,367-374`; `assets/js/search.js:582-586,687-704,722-740`; related CSS and frontend localization.
- **Evidence:** The package creates a `sales_30d` database column, preserves and writes a forced zero, accepts a ranking weight, and retains SQL for that dormant value. `word_variants()` and `synonym_map()` return empty arrays. A listener remains for a Free option that is not registered. Paid corrected-query and taxonomy-result UI branches remain in JavaScript and CSS.
- **Impact:** The Free package carries deliberately inactive ranking, synonym and paid-result scaffolding rather than only its complete Free implementation.
- **Required remediation:** Remove paid-only schema, SQL expressions, forced-zero data flow, inert methods, listeners and UI branches. Keep only the minimal activation/edition-selection mechanism required for coexistence with the separately distributed plugin.
- **Same-pattern occurrences:** Schema, indexing, ranking, normalization, JavaScript, CSS, localization and documentation.
- **Verification status:** Implementation is a **verified fact**; classification as paid scaffolding is a **reasoned inference** supported by the repository's Free/Pro porting rules.
- **Remediation (2026-09-10, commit `7baaa28`):** **Fixed.** `sales_30d` removed entirely — dropped from the `CREATE TABLE` schema, every INSERT/REPLACE column list, `apply_row_filter_and_sanitize()`, and the `fulltext_sql()` ranking formula (the `recent_sales` weight key was removed alongside it, not just zeroed). `word_variants()` and `synonym_map()` deleted; `Query_Normalizer::expand()` simplified to return only the typed word. The dead `update_option_wcs_synonyms` listener (`Indexer::on_synonyms_changed()`) removed. The unused `vocabulary_terms()` helper — itself dead Pro-only-feature scaffolding with zero callers outside its own tests — was also found and removed while fixing this. In JavaScript/CSS: the corrected-query notice and taxonomy-suggestion rendering branches were removed from `search.js`/`search.css` (Free's server never sets `X-WCS-Corrected-Query` or returns `type: 'taxonomy'` rows, so these were always dead); removing them surfaced and fixed a latent `ReferenceError` (`highlightSource` was referenced after its declaration was deleted) before it could ship. Corresponding now-unused i18n keys (`category`, `brand`, `products_count`, `showingResultsFor`) were removed from `class-frontend.php`'s localized config.

### WPR-003 — Documentation advertises synonym behavior that Free does not provide

- **Severity:** High
- **Submission-blocking:** Yes
- **Official requirement:** Directory descriptions must accurately represent packaged functionality; unavailable features conflict with Guidelines 1 and 5.
- **Packaged/source files:** `includes/class-docs-tab.php:156-181`; `includes/class-query-normalizer.php:367-374`.
- **Evidence:** The documentation exposes `wcs_synonym_groups`, but no corresponding `apply_filters( 'wcs_synonym_groups', ... )` exists in the packaged tree. The normalizer returns empty synonym data. The same documentation exposes inactive `sales_30d` ranking.
- **Impact:** Developers are told extension mechanisms exist when the packaged plugin cannot execute them.
- **Required remediation:** Remove the documentation and inactive listener, or implement a complete Free feature with the documented public API.
- **Same-pattern occurrences:** Synonym and recent-sales documentation.
- **Verification status:** **Verified fact.**
- **Remediation (2026-09-10, commit `7baaa28`):** **Fixed.** The `wcs_synonym_groups` filter documentation block removed from `includes/views/tab-docs.php` (the real file — see the correction note above), along with the `sales_30d`/`recent_sales` entries in the `wcs_indexed_product_data` available-keys list and the `wcs_ranking_weights` key list. The Developer Hooks intro sentence no longer promises synonym customization.

### WPR-004 — Currency cookies can relabel unconverted store prices

- **Severity:** Critical
- **Submission-blocking:** Yes
- **Official requirement:** The plugin must operate as described and must not present materially incorrect shopper-facing prices. See <https://make.wordpress.org/plugins/handbook/performing-reviews/review-checklist/>.
- **Packaged/source files:** `includes/class-search-handler.php:112-117`; `assets/js/search.js:315-340,397-399,780-782`; `readme.txt:55`.
- **Evidence:** The Free server returns values in the store currency. JavaScript reads third-party currency cookies, sends that currency, then formats the unconverted value using the cookie-selected currency. The readme says Free always displays the store default currency.
- **Concrete reproduction:** With KES configured as the store currency, `wmc_current_currency=USD`, and an unconverted value of `1000`, the frontend rendered `$1,000.00`.
- **Impact:** Customers can see a numerically unchanged price carrying the wrong currency symbol and formatting.
- **Required remediation:** In Free, always format results using the localized store currency and remove the paid cookie/conversion path, or provide complete and reliable server-side conversion as Free functionality.
- **Same-pattern occurrences:** Currency cookie detection and request/display handling throughout `search.js`; shared MU loader contains the paid conversion path.
- **Verification status:** **Verified fact**, reproduced with a targeted JavaScript harness.
- **Remediation (2026-09-10, commit `7baaa28`):** **Fixed. Challenged three times during remediation; no new evidence offered any of the three times.** `getActiveCurrency()`/`readCookie()` removed from `search.js` entirely; `formatPrice()` no longer takes a currency argument and always formats using the store's own admin-configured currency, regardless of any third-party switcher cookie.

  The first challenge argued the original synthetic JS reproduction didn't prove a real defect. That was tested directly against this plugin's own pre-existing demo screenshot assets (`search_kes.png`, `search_usd.png`, `search_usd_fixed.png` — already in this repo, not created for this review): `search_kes.png` and `search_usd.png` show the *identical* numeric value (12,989.15) for the same product under `KSh` and `$` respectively — i.e. $12,989.15 for a bag — while `search_usd_fixed.png` shows the correct converted value ($100.28). That is the exact defect this finding describes, in the plugin's own assets, independent of any harness built for this review.

  The second and third challenges (see the provenance note at the top of this document) both re-asserted the same withdrawal without offering a new reproduction, arguing the screenshot evidence "cannot establish causation." It's direct evidence, not inference: the same number under two currency symbols for the same product is what the finding describes. The finding stands.

### WPR-005 — A rate-limited fallback response can poison the shared result cache

- **Severity:** Critical
- **Submission-blocking:** Yes
- **Official requirement:** A response affected by a per-client security limit must not become a shared cached response for unrelated users.
- **Packaged/source file:** `includes/class-search-handler.php:227-228,692-699,715,755`.
- **Evidence:** A per-IP limit can skip both relaxed fallback tiers, after which the incomplete empty result is cached for `DAY_IN_SECONDS`. The cache key contains neither client identity nor degraded-search state.
- **Concrete reproduction:** After exhausting the fallback allowance for IP A, `hazina lamp` produced an empty response cached for 86,400 seconds. IP B then received that cached empty result without a database query.
- **Impact:** One visitor can suppress matching results for every visitor issuing the same query for up to 24 hours.
- **Required remediation:** Distinguish fully searched empty results from throttled/incomplete results and never cache the latter. Return a defined throttling response or an uncached degraded marker.
- **Same-pattern occurrences:** Both relaxed fallback tiers use the affected control path.
- **Verification status:** **Verified fact**, reproduced with a targeted PHP harness.
- **Remediation (2026-09-10, commit `7baaa28`):** **Fixed.** Added `Search_Handler::$last_query_degraded`, set when the expensive-fallback-tier rate limiter denies both relaxation passes and the query still comes back empty. `handle_request()` now checks this flag before either cache-write path (`set_transient`/`apcu_store`) — mirroring the existing `last_query_had_error` skip — and returns an `X-OTSW-Degraded` header instead. The frontend JS treats that header the same as a query error: not cached in the tab's own in-memory cache, shown with the temporary-unavailable treatment rather than a flat "no results." Regression test: `test_throttled_fallback_result_is_returned_but_never_cached` in `SearchHandlerRequestTest.php`.

  A related, narrower version of this same bug class was found in the **Pro** sibling while implementing an unrelated fix requested later in this review's remediation: Pro's currency-conversion fallback (a separate feature Free doesn't have) could silently return unconverted store-default prices under a currency-scoped cache key with no signal to the client, poisoning that currency's shared cache entry. Fixed in Pro commit `f91ae7c` the same way — a `$conversion_failed` flag gates the cache write, and an `X-OTSW-Currency-Fallback` header tells the client which currency the numbers are actually in. Regression test: `test_conversion_failure_falls_back_to_store_default_and_is_not_cached` in Pro's `SearchHandlerRequestTest.php`.

### WPR-006 — The Action Scheduler runner globally blocks unrelated outbound HTTP

- **Severity:** Critical
- **Submission-blocking:** Yes
- **Official requirement:** Plugin hooks must be scoped to the plugin's own execution and must not disrupt WordPress, WooCommerce or other extensions.
- **Packaged/source file:** `includes/class-indexer.php:83-85,457-475`.
- **Evidence:** Every AJAX request whose action is `as_async_request_queue_runner` installs `pre_http_request` at maximum priority. The callback rejects every external URL except the current site. The code already has a narrower add/remove location inside `process_batch()`.
- **Concrete reproduction:** Calling the callback with an unrelated external URL returned `WP_Error` with code `wcs_http_blocked`.
- **Impact:** An Action Scheduler request processing unrelated WooCommerce, payment, webhook or extension jobs can have its HTTP requests blocked.
- **Required remediation:** Remove the broad AJAX-runner registration. Install and remove the filter only while this plugin's indexing batch executes.
- **Same-pattern occurrences:** One broad registration and one correctly scoped batch registration.
- **Verification status:** **Verified fact.**
- **Remediation (2026-09-10, commit `7baaa28`):** **Fixed.** The broad `wp_doing_ajax()`/`as_async_request_queue_runner` registration in `Indexer::init()` removed entirely. Only `process_batch()`'s existing narrow `add_filter`/`remove_filter` pair around `do_process_batch()` remains, so `pre_http_request` is now only ever intercepted for the literal duration of this plugin's own batch execution — never for the whole Action Scheduler dispatch request, which may also be running unrelated WooCommerce/payment/webhook jobs.

### WPR-007 — `Tested up to` appears in an unsupported plugin-header location

- **Severity:** Medium
- **Submission-blocking:** Yes
- **Official requirement:** The official PHP header requirements do not define `Tested up to`; the official readme specification places it in `readme.txt`. The review specification requires it only in the supported location. See <https://developer.wordpress.org/plugins/plugin-basics/header-requirements/> and <https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/>.
- **Packaged/source files:** `turbo-search-for-woocommerce.php:15`; `readme.txt:5`.
- **Evidence:** `Tested up to: 7.1` appears in both locations.
- **Impact:** Directory metadata is duplicated into an unsupported PHP-header field.
- **Required remediation:** Remove `Tested up to` from the PHP header and retain the accurate readme field.
- **Same-pattern occurrences:** One main-header occurrence.
- **Verification status:** **Verified fact.**
- **Remediation (2026-09-10, commit `7baaa28`):** **Fixed.** Removed from the main plugin file's header block; retained only in `readme.txt`.

### WPR-008 — The listed contributor account could not be verified

- **Severity:** High
- **Submission-blocking:** Yes
- **Official requirement:** Readme contributors must be valid WordPress.org usernames. See <https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/>.
- **Packaged/source file:** `readme.txt:2`.
- **Evidence:** The readme lists `ozulabs`. A direct lookup of `https://profiles.wordpress.org/ozulabs/` returned HTTP 404 during review.
- **Impact:** The contributor may be dropped by directory parsing, and submitting/owning-account representation remains unverified.
- **Required remediation:** Create or confirm the exact WordPress.org account, use its correctly cased username, and confirm that the submitting owner is represented.
- **Same-pattern occurrences:** One contributor value.
- **Verification status:** HTTP response is a **verified fact**; account ownership is **unresolved external verification**.
- **Remediation (2026-09-10, commit `7baaa28`):** **Fixed**, and this item's original ambiguity is now resolved. The actual WordPress.org reviewer email (received 2026-09-07, since supplied and reconciled against this audit — see §3) names `fearofbug` as the account that submitted the plugin. `Contributors` in `readme.txt` now reads `fearofbug` — the confirmed, currently-active submitting account — rather than the unverifiable `ozulabs`.

### WPR-009 — Name and visual identity are not sufficiently distinctive

- **Severity:** Medium
- **Submission-blocking:** Yes
- **Official requirement:** Names and slugs must be distinctive and must not imply affiliation. Third-party marks should use a clear "for ..." construction. See <https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/> and Guideline 17 at <https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/>.
- **Evidence:** Wider-web searches found unrelated uses of “Turbo Search,” including TurboSearch.com, software extensions, packages and ecommerce search products. The proposed WordPress.org slug did not resolve to an existing plugin page, but availability does not establish distinctiveness. The repository banner says “WP Fast Search,” which does not match “Turbo Search for WooCommerce.”
- **Impact:** The descriptive leading phrase and mismatched banner create avoidable collision and identity risk during manual review.
- **Required remediation:** Lead with a strong owner identifier, such as “OzuLabs Turbo Search for WooCommerce,” subject to Plugins Team acceptance. Align the slug, icon, banner and Plugin URI with the final identity.
- **Same-pattern occurrences:** Display name, proposed slug and banner copy.
- **Verification status:** Search results and banner mismatch are **verified facts**; likely rejection is a **reasoned inference**. Final acceptance requires the Plugins Team.
- **Remediation (2026-09-10, commit `7baaa28`): Partially fixed — one item still open.**
  - Display name changed to **"OzuLabs Turbo Search for WooCommerce"** (matches the reviewer's own suggestion in the 2026-09-07 email) in the main plugin header and `readme.txt`'s `===` title.
  - Slug/text-domain changed to **`ozulabs-turbo-search-for-woocommerce`** — `Text Domain` header, every `__()`/`_e()`/`esc_html__()` call's text-domain argument, `build.sh`'s `PLUGIN_SLUG`, the POT filename/domain, `phpcs.xml`'s allowed text-domain list, and `composer.json`'s package name.
  - Internal admin-notice/error-message strings still say the shorter "Turbo Search for WooCommerce" rather than the full new name — a deliberate choice (common practice: header/readme/slug carry the compliance-required distinctive identity; in-product copy can stay short), not an oversight, but noted here in case the Plugins Team wants full consistency.
  - **Still open: the banner images** (`banner-772x250.png`, `banner-1544x500.png`) still render the text "WP Fast Search" — a *third*, even-older name, matching neither the pre-remediation "Turbo Search for WooCommerce" nor the new "OzuLabs Turbo Search for WooCommerce." This is a graphic design asset that cannot be fixed by code changes; it needs to be redesigned before resubmission. The icon files (`icon-128x128.png`/`icon-256x256.png`) carry no text and don't need changes.
  - The **new slug still needs to be reserved with the Plugins Team** by replying to their email as their process requires (see §3) — a code-level rename doesn't reserve the slug on WordPress.org's side.
  - **Plugin URI confirmed reachable** (see §6): a direct `curl -L https://ozulabs.com/plugins/turbo-search/` returns HTTP 200 with 86,963 bytes of real HTML, on two independent checks. Earlier and concurrent checks via this session's own `WebFetch` tool consistently returned HTTP 403 for the identical URL — that tool's request signature appears to be blocked by the site's bot/WAF protection while a plain `curl` passes through. This is worth remembering for future checks of this domain: a 403 from an automated fetch tool here is a property of the tool, not the page.

### WPR-010 — Official WordPress.org review feedback is unavailable

- **Severity:** Medium
- **Submission-blocking:** Yes under the supplied review gate
- **Official requirement:** The review specification prohibits a `SUBMISSION-READY` verdict when feedback verification is unavailable.
- **Evidence:** No file containing `Review in Progress: Turbo Search for WooCommerce` exists in the repository; no `wordpress-org-reviews/` directory exists; the supplied attachment contains instructions rather than WordPress.org correspondence.
- **Impact:** Previous official findings, if any, cannot be reconciled or proven resolved.
- **Required remediation:** Supply the complete WordPress.org review correspondence and repeat the exact-ZIP reconciliation.
- **Same-pattern occurrences:** Internal reports exist, but none is official correspondence.
- **Verification status:** **Verified fact.**
- **Remediation (2026-09-10):** **Resolved.** The official WordPress.org Plugins Team review email (sent 2026-09-07, reviewing the `turbo-search-for-woocommerce-1.11.7.zip` submission) was supplied and reconciled against this audit — see §3 for the full mapping. Every issue the reviewer's email named independently matches a finding already in this document (WPR-001, WPR-002/003, WPR-007, WPR-008, WPR-009), confirming this audit's findings rather than surfacing anything new from the official side. The email's own stated warning — *"If more issues of the same nature are found in the following review, this plugin will not be reviewed again"* — was the reason WPR-002 through WPR-006's Critical-severity findings (found by this audit, not mentioned in the reviewer's email) were treated as equally mandatory to fix before resubmission, not optional extras.

## 2. Non-blocking findings

### WPR-011 — Changelog is longer than current guidance encourages

- **Severity:** Low
- **Submission-blocking:** No
- **Evidence:** The 9,399-byte readme includes releases 1.11.9 through 1.11.0.
- **Required remediation:** Retain the current and previous release and move older history externally if future growth approaches the parser limit.
- **Verification status:** **Optional recommendation.**
- **Remediation:** Not actioned — non-blocking, and the readme is still well under any parser limit after this remediation's changes. Revisit if changelog growth continues.

### WPR-012 — Composer metadata contains a strict-validation warning

- **Severity:** Low
- **Submission-blocking:** No
- **Evidence:** `composer validate --strict` reported only that the explicit `version` field is usually omitted. `composer.json` is development-only and is absent from the ZIP.
- **Required remediation:** Remove the Composer `version` field during routine development cleanup.
- **Verification status:** **Verified fact.**
- **Remediation (2026-09-10, commit `7baaa28`):** **Fixed.** The explicit `version` field removed from `composer.json`; `composer.lock` regenerated (`composer update --lock`) so its content hash matches. `composer validate --no-plugins --no-scripts` now reports no warnings.

### WPR-013 — Declared WordPress and WooCommerce compatibility lacks a live integration result

- **Severity:** Medium
- **Submission-blocking:** No independently; final verification remains incomplete
- **Evidence:** The ZIP declares WordPress `Tested up to: 7.1` and WooCommerce `WC tested up to: 10.8`. Unit tests use stubs and do not reproduce a live WordPress/WooCommerce runtime.
- **Required remediation:** Test the rebuilt artifact against the exact declared versions and declare only versions actually tested. See <https://developer.woocommerce.com/docs/contribution/contributing/version-support-policy/> and <https://developer.woocommerce.com/docs/extensions/core-concepts/example-header-plugin-comment>.
- **Verification status:** **External claim not independently reproduced.**
- **Remediation: Still open — the last remaining item that isn't a design/external-account task.** Not actioned in this remediation pass — this requires a live WordPress 7.1 + WooCommerce 10.8 site (this repo's own guidance points at `thogotodeli.com`'s test catalog for this kind of check) and cannot be verified from source review alone. Must specifically include an update rehearsal from a realistic pre-rename (1.11.9-era) database exercising `migrate_legacy_wcs_prefix()` end-to-end, not just a fresh-install check — the unit-level `ActivatorTest.php` coverage proves the migration logic is correct in isolation, not that a real update behaves correctly with real WordPress internals (real cron, real Action Scheduler, real usermeta table).

## 3. WordPress.org feedback reconciliation

The official WordPress.org review email was received 2026-09-07 (reviewing `turbo-search-for-woocommerce-1.11.7.zip`, one version behind this audit's 1.11.9) and has been reconciled against this document's findings:

| Reviewer's email item | This audit's finding | Match |
|---|---|---|
| Name not distinctive; suggested "OzuLabs Turbo Search for WooCommerce" | WPR-009 | Same conclusion, same suggested name |
| Trialware: `synonym_map()`/`word_variants()` return empty arrays; `ranking_weights()` forces `recent_sales`→0; `sales_30d` stored as 0 | WPR-002, WPR-003 | Same functions named; this audit traced further into JS/CSS/docs |
| `Tested up to` in both readme and PHP header | WPR-007 | Same finding, same fix |
| Global identifiers not sufficiently prefixed | WPR-001 | This audit found the actual prefix is 3 letters (`wcs`), worse than the email's framing implied |
| Contributor `ozulabs` not a verifiable WordPress.org username | WPR-008 | Confirmed independently via direct profile lookup (404) |

Nothing in the official email was missing from this audit. This audit additionally found three Critical-severity issues (WPR-004, WPR-005, WPR-006) the reviewer's automated pass did not catch — all three are now fixed (§1). WPR-010 (no official correspondence available) is accordingly **resolved**.

Prior internal reports were treated only as evidence:

- `WORDPRESS_ORG_REVIEW_1.5.1.txt`: earlier product-cap, PHPCS and readme-size findings are fixed in source and ZIP. The current package has no product-count quota, PHPCS is clean, and the readme is 9,399 bytes.
- `WORDPRESS_ORG_DEEP_REVIEW_1.6.0.txt`: earlier remote promotion, stale POT, global cache flush, multisite pagination and MU-loader findings are fixed. Declared live-version testing remains unverified.
- `WORDPRESS_ORG_REVIEW_1.10.2.txt`: APCu isolation, remote promotion removal, multisite uninstall handling and readme size are fixed.
- `WORDPRESS_ORG_RECHECK_1.11.1.txt`: network MU-loader and shared-user-meta fixes are present in source and ZIP.
- `WORDPRESS_ORG_RELEASE_REVIEW_1.11.2.txt`: the earlier source/package CSS mismatch is fixed; all current packaged runtime files are byte-identical to source and Git `HEAD`.

## 4. Automated checks passed

Original run (2026-09-09, against 1.11.9):

| Check | Command and target | Exit | Result |
|---|---|---:|---|
| PHPUnit | `vendor/bin/phpunit --do-not-cache-result --coverage-clover=/tmp/.../coverage.xml --coverage-text --colors=never` against source | 0 | 341 tests, 936 assertions |
| Coverage gate | `php tests/phpunit/check-coverage.php /tmp/.../coverage.xml 85` | 0 | 92.12%; 1,858/2,017 lines |
| PHPCS source | `vendor/bin/phpcs --no-cache --report=json includes mu-plugin uninstall.php turbo-search-for-woocommerce.php` | 0 | 15 files; 0 errors, 0 warnings (**suppression active — see WPR-001**) |
| PHPCS ZIP | Same rules against extracted exact ZIP | 0 | 15 files; 0 errors, 0 warnings |
| PHP syntax | `php -l` over source and extracted ZIP | 0 | 15 + 15 files; 0 failures |
| JavaScript syntax | `node --check` against both JavaScript files | 0 | 0 failures |
| ZIP integrity | `unzip -t dist/turbo-search-for-woocommerce-1.11.9.zip` | 0 | 32 entries; 0 CRC errors |
| Package allowlist | Complete ZIP list against expected runtime allowlist | 0 | 23 files; 0 unexpected or missing files |
| Source/package parity | Byte comparison of packaged files to source and Git `HEAD` | 0 | 23/23 identical |
| Version consistency | PHP header, constant, readme, ZIP, changelog, packaged files and POT | 0 | All `1.11.9` |
| POT consistency | Regenerated with `wp i18n make-pot`, ignoring creation timestamp | 0 | No content difference |
| Composer audit | `composer audit --locked --no-interaction` | 0 | No security advisories |
| Composer validity | `composer --no-plugins --no-scripts validate --strict` | 1 | Valid; one non-packaged version-field warning (**now fixed — WPR-012**) |

Final re-run (2026-09-10, against 1.11.10, commit `0cbeded`):

| Check | Command and target | Exit | Result |
|---|---|---:|---|
| PHPUnit | `vendor/bin/phpunit --do-not-cache-result` | 0 | 336 tests, 922 assertions |
| Coverage gate | `composer coverage` (via `build.sh`'s Step 0, PCOV loaded) | 0 | 92.04% lines (1,851/2,011), threshold 85% |
| PHPCS, no suppression | `vendor/bin/phpcs --no-cache --report=summary includes mu-plugin uninstall.php turbo-search-for-woocommerce.php` after removing `ShortPrefixPassed` from `phpcs.xml` | 0 | 8 files; **0 errors, 0 warnings** — the suppression removal surfaced 5 real `NonPrefixedVariableFound` errors (`$delete_data`, `$mu_file`, `$mu_deleted` in `uninstall.php`; `$_phase` in `tab-settings.php`), all fixed, none re-suppressed |
| PHP syntax | `php -l` over every tracked `.php` file | 0 | 0 failures |
| JavaScript syntax | `node --check` on `search.js`/`admin.js` | 0 | 0 failures |
| Composer validity | `composer --no-plugins --no-scripts validate --strict` | 0 | Valid, no warnings |
| ZIP integrity | `unzip -t` on the rebuilt zip | 0 | 0 CRC errors |
| Package content | `unzip -l` on the rebuilt zip | — | 32 entries, no dev files, no duplicate/stale files |
| Plugin URI reachability | `curl -sL -o /dev/null -w '%{http_code} %{size_download}'` against `https://ozulabs.com/plugins/turbo-search/`, run twice independently | — | HTTP 200, 86,963 bytes, both times |

**Three packaging/correctness defects found and fixed during this remediation's own re-verification passes, after the initial 1.11.10 build — none caught by the first automated pass:**

1. The first 1.11.10 zip shipped *two* `.pot` files — the correctly-regenerated `languages/ozulabs-turbo-search-for-woocommerce.pot` and a stale, orphaned `languages/turbo-search-for-woocommerce.pot` left over from before the slug rename (never deleted from source, so `build.sh`'s rsync packaged both). Removed in commit `d2e2fa4`.
2. The persisted-state prefix rename broke the update path for any site running a pre-rename version (see WPR-001). Fixed in commit `7b11257` (Pro: `424680c`), along with the missing 1.11.10 changelog entry that same commit added.
3. That same migration still left an orphaned recurring cron event and unmigrated notice-dismissal preferences behind (see WPR-001). Fixed in commit `0cbeded` (Pro: `cd7d028`).

Worth keeping in mind for future releases: "the version/text-domain string matches" and "the zip only has files it should" are necessary checks, not sufficient ones for something as structurally invasive as a persisted-identifier rename — each of the three defects above needed someone (or something) to specifically go looking for that category of problem, not just re-run the standard battery.

Full re-run also performed and passed against the **Pro** sibling (`wp_search/`, final commit `cd7d028`): 436 tests / 1,322 assertions, PHPCS 7 files / 0 errors / 0 warnings after the same suppression removal (which surfaced and fixed 6 more `NonPrefixedVariableFound` errors in Pro's `uninstall.php` and 6 more prefix findings across `tab-analytics.php`/`tab-license.php`/`tab-settings.php` — plus, unrelated to prefixing, 2 pre-existing PHPCS findings in `class-license.php`/`class-admin-settings.php` predating this remediation, also fixed rather than left as-is), and all three of the same migration defects (found and fixed in lockstep with Free's).

## 5. Manual checks passed

- The exact ZIP has one root directory: `ozulabs-turbo-search-for-woocommerce/` (renamed from `turbo-search-for-woocommerce/` — see WPR-009).
- No credentials, secrets, hidden files, Git metadata, tests, development dependencies, internal reports, build scripts, editor files, source maps or nested archives are packaged.
- The main header, runtime constant, Stable tag, changelog, ZIP filename and POT header all consistently identify `1.11.10`.
- Main and readme short descriptions are within expected limits.
- Text domain and packaged POT content are consistent, now under the new slug.
- No product-count, request-count, site-count, time or trial restriction intended to force an upgrade was found.
- Core local search operates without a paid license.
- The earlier remote promotional-request mechanism is absent.
- The package does not download executable code.
- No obfuscated code, telemetry or crypto-mining behavior was found.
- Visible upgrade material is restrained and descriptive Pro links are permissible in principle.
- The MU loader's edition selection is required compatibility; the paid implementation branches inside Free flagged by WPR-002 have been removed.
- A site updating from 1.11.9 or earlier has its settings, search index, scheduled cleanup job, and notice-dismissal preferences all correctly migrated — not just its runtime code (WPR-001).
- The Plugin URI is product-specific relative to the sibling paid plugin's current generic URI, and **is confirmed reachable** (HTTP 200, real content) — see §6.
- License declarations are GPL-compatible; no separately bundled third-party runtime library needing additional attribution was found.

Live guidance consulted on **2026-09-07**; original review concluded on **2026-09-09**; remediation completed on **2026-09-10**:

- <https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/>
- <https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/>
- <https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/>
- <https://developer.wordpress.org/plugins/plugin-basics/best-practices/>
- <https://developer.wordpress.org/plugins/wordpress-org/common-issues/>
- <https://developer.wordpress.org/plugins/plugin-basics/header-requirements/>
- <https://make.wordpress.org/plugins/handbook/performing-reviews/review-checklist/>
- <https://developer.wordpress.org/plugins/wordpress-org/using-the-mcp-server/>
- <https://developer.woocommerce.com/docs/contribution/contributing/version-support-policy/>
- <https://developer.woocommerce.com/docs/extensions/core-concepts/example-header-plugin-comment>

## 6. Checks not performed and why

- **Official Plugin Check:** Attempted with `wp plugin check /tmp/...`; exit 1 because no WordPress installation was configured and the `check` command was unavailable. **Still not performed.**
- **Official online readme validator:** Not available as a standalone local tool. **Still not performed.**
- **WordPress.org MCP:** No WordPress.org MCP server was installed in the review environment. **Still not performed.**
- **Live WordPress/WooCommerce smoke test:** No local WordPress installation was available. **Still not performed — this is WPR-013, and it is the main reason this document's verdict is CONDITIONALLY READY rather than SUBMISSION-READY.** Must be run against a live WordPress 7.1 + WooCommerce 10.8 site before resubmission, and must specifically include an update rehearsal from a realistic pre-rename (1.11.9-era) database, not just a fresh-install check.
- **MySQL integration testing:** No review database/runtime was available. **Still not performed.**
- **Contributor ownership and official name acceptance:** `fearofbug` is now confirmed as the actual submitting account (from the reviewer's own email — WPR-008 is resolved on that front), but **final name/slug acceptance is still a Plugins Team decision**, not something this remediation can confirm. The new slug also still needs to be formally reserved by replying to the review email.
- **Plugin URI live availability:** **Resolved.** `curl -sL https://ozulabs.com/plugins/turbo-search/` was run twice, independently, at different points in this remediation: both times, HTTP 200, both times exactly 86,963 bytes of real HTML (confirmed by inspecting the response body — a normal WordPress page on the `ozupay-theme` theme, not an error page). This session's own `WebFetch` tool returned HTTP 403 for the identical URL on every attempt across the same remediation — that is a property of `WebFetch`'s request signature being blocked by this site's bot/WAF protection, not evidence about the page. Trust the `curl` result; a 403 from an automated fetch tool on this specific domain does not mean the page is down.

## 7. Exact remediation checklist

1. ~~Replace `wcs`/`WCS` with a distinctive prefix of at least four letters across every global and persisted identifier, including a working upgrade migration.~~ **Done** — `otsw`/`OTSW`, both editions; migration covers options, tables, the recurring cron job, and notice-dismissal meta (WPR-001).
2. ~~Remove `sales_30d` schema, forced-zero storage, ranking SQL and documentation from Free.~~ **Done** (WPR-002).
3. ~~Remove inert synonym/variant methods, unused option listeners and nonexistent hook documentation.~~ **Done** (WPR-002, WPR-003).
4. ~~Remove paid corrected-query, taxonomy-result and currency-conversion branches from Free JavaScript, CSS and localization unless each becomes complete Free functionality.~~ **Done** (WPR-002, WPR-004).
5. ~~Make Free always use the store currency when formatting unconverted values.~~ **Done** (WPR-004).
6. ~~Prevent throttled or incomplete fallback results from entering the shared cache.~~ **Done** (WPR-005).
7. ~~Remove the global Action Scheduler AJAX HTTP filter and scope it to this plugin's batch callback.~~ **Done** (WPR-006).
8. ~~Remove `Tested up to` from the PHP header.~~ **Done** (WPR-007).
9. ~~Confirm a real WordPress.org contributor/owner account and update `Contributors`.~~ **Done** — `fearofbug` (WPR-008).
10. Select a distinctive name and matching slug, preferably owner-branded, and align the banner's "WP Fast Search" text with that identity. **Partially done** — name/slug changed; **banner image still needs a design fix** (WPR-009).
11. ~~Confirm Plugin URI availability.~~ **Done** — `curl -L` confirms HTTP 200, twice, independently (§6).
12. Test against the exact declared WordPress and WooCommerce versions, including an update rehearsal from a realistic pre-rename database. **Still open — the only remaining code/testing item** (WPR-013, §6).
13. ~~Supply all official WordPress.org review correspondence for reconciliation.~~ **Done** — reconciled in §3 (WPR-010).
14. ~~Increment the version, regenerate the POT, build a new ZIP, and repeat every source and exact-artifact check.~~ **Done** — 1.11.10, final commit `0cbeded`, re-verified in §4. (Three defects were caught and fixed during this re-verification itself — see §4 — which is exactly why this step exists.)

**Remaining before resubmission:** item 10 (banner redesign), item 12 (live WP/WC compatibility test with an update rehearsal), and formal reservation of the new slug with the Plugins Team. That's it — every other checklist item is closed and re-verified.

## 8. Artifact identity

Original (2026-09-09):

- **Absolute ZIP:** `/home/edub/work/ozulabs/wp_search-free/dist/turbo-search-for-woocommerce-1.11.9.zip`
- **Filename:** `turbo-search-for-woocommerce-1.11.9.zip`
- **Size:** 137,159 bytes
- **SHA-256:** `71a350b9ba74259e6eaa2b4840c27d61722a6895a77792f9052643194b7804cb`
- **Packaged root:** `turbo-search-for-woocommerce/`
- **Packaged version:** `1.11.9`
- **Source commit:** `2e20e32830217570cd0a5581f8a92e9a8d514a6f`
- **Commit subject:** `result rows shrinking fix`
- **Working tree at review time:** Clean
- **Archive entries:** 32
- **Packaged files:** 23
- **Source/package identity:** All 23 packaged files were byte-identical to source and Git `HEAD`.
- **Review mutation:** None during the review. This report was created afterward at the user's explicit request.

Final (2026-09-10, after remediation — main fixes, upgrade-migration fix, and the follow-on cron/meta cleanup fix):

- **Absolute ZIP:** `/home/edub/work/ozulabs/wp_search-free/dist/ozulabs-turbo-search-for-woocommerce-1.11.10.zip`
- **Filename:** `ozulabs-turbo-search-for-woocommerce-1.11.10.zip`
- **Size:** 136,965 bytes
- **SHA-256:** `998a03b3bb00342c6ed54d960c9a0b9f01fcfecc7ac20e7e75f4f0474afb1867`
- **Packaged root:** `ozulabs-turbo-search-for-woocommerce/` (new slug)
- **Packaged version:** `1.11.10`
- **Source commit:** `0cbeded6cc1c4140fc0ccc044572721a36d66ad8`
- **Commit history for this remediation (oldest to newest):** `7baaa28` (main remediation: WPR-001 through WPR-010, WPR-012) → `d2e2fa4` (removed the stale duplicate POT file) → `7b11257` (upgrade-migration fix + 1.11.10 changelog entry) → `0cbeded` (cron/notice-meta cleanup fix)
- **Pro sibling final commit:** `cd7d028` (`f91ae7c` → `424680c` → `cd7d028`)
- **Working tree at doc-finalization time:** Clean.
- **Archive entries:** 32.
- **Packaged files:** 23 (unchanged count from 1.11.9, despite the rename — same files, new names/content where applicable).
- **Also updated:** the shared top-level `ozulabs/dist/turbo-search-free/` mirror, via `hooks/post-commit` (both the tracked source and the installed `.git/hooks/post-commit` copy, which had independently gone stale — see WPR-001's remediation).
- **Review mutation:** This document was rewritten in place across this remediation session, at the user's explicit request, across three passes as new findings (two of them from unexplained direct file insertions — see the provenance note at the top) were independently verified and, where real, fixed. The historical WPR-00x findings text is unedited from 2026-09-09; everything else reflects the final, independently-verified state.

## 9. Final verdict

**Original: BLOCKED**

**Current: CONDITIONALLY READY**

All ten originally-blocking findings (WPR-001 through WPR-010) have code-level fixes committed, tested (336/336 Free, 436/436 Pro), and re-verified with zero PHPCS suppressions. This includes two second-order bugs found only by specifically re-checking the rename's own consequences (the broken update path, then the incomplete cron/notice-meta cleanup within that same fix) — both are now fixed and tested. The official WordPress.org review has been received and fully reconciled (§3) — nothing in it was missed, and this audit found three additional Critical bugs (currency mislabeling, cache poisoning, HTTP blocking) the official review did not catch, plus the two migration bugs the rename itself introduced, all now fixed. The Plugin URI is confirmed reachable (HTTP 200, verified twice via `curl`, independent of this session's own fetch tool returning a misleading 403).

Two items remain open and cannot be closed by further code changes, which is why this is CONDITIONALLY READY rather than SUBMISSION-READY:

1. **Banner images still say "WP Fast Search"** — a third name, matching neither the old nor new plugin name. Needs a design fix (WPR-009, checklist item 10).
2. **No live WordPress 7.1 / WooCommerce 10.8 compatibility test has been run**, and that test now specifically needs to include an update rehearsal from a realistic pre-rename database against real WordPress internals (real cron, real Action Scheduler, real usermeta) — the unit-level migration tests prove the logic is correct in isolation, not that a real update behaves correctly end-to-end (WPR-013, checklist item 12).

Formal slug reservation with the Plugins Team is also still outstanding, but that's a process step contingent on replying to their email, not a technical gate.

Per the versioning discipline this repository enforces, none of this has been pushed or deployed anywhere — the 1.11.10 commits and zip (both editions) exist locally, pending those two items and your decision on when to resubmit.
