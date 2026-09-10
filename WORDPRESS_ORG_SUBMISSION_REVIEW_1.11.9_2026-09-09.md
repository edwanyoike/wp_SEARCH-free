# WordPress.org Submission Review — Turbo Search for WooCommerce 1.11.9

Review completed: 2026-09-09  
Review type: strict, evidence-based, exact-ZIP submission audit  
Verdict: **BLOCKED**

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

### WPR-009 — Name and visual identity are not sufficiently distinctive

- **Severity:** Medium
- **Submission-blocking:** Yes
- **Official requirement:** Names and slugs must be distinctive and must not imply affiliation. Third-party marks should use a clear "for ..." construction. See <https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/> and Guideline 17 at <https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/>.
- **Evidence:** Wider-web searches found unrelated uses of “Turbo Search,” including TurboSearch.com, software extensions, packages and ecommerce search products. The proposed WordPress.org slug did not resolve to an existing plugin page, but availability does not establish distinctiveness. The repository banner says “WP Fast Search,” which does not match “Turbo Search for WooCommerce.”
- **Impact:** The descriptive leading phrase and mismatched banner create avoidable collision and identity risk during manual review.
- **Required remediation:** Lead with a strong owner identifier, such as “OzuLabs Turbo Search for WooCommerce,” subject to Plugins Team acceptance. Align the slug, icon, banner and Plugin URI with the final identity.
- **Same-pattern occurrences:** Display name, proposed slug and banner copy.
- **Verification status:** Search results and banner mismatch are **verified facts**; likely rejection is a **reasoned inference**. Final acceptance requires the Plugins Team.

### WPR-010 — Official WordPress.org review feedback is unavailable

- **Severity:** Medium
- **Submission-blocking:** Yes under the supplied review gate
- **Official requirement:** The review specification prohibits a `SUBMISSION-READY` verdict when feedback verification is unavailable.
- **Evidence:** No file containing `Review in Progress: Turbo Search for WooCommerce` exists in the repository; no `wordpress-org-reviews/` directory exists; the supplied attachment contains instructions rather than WordPress.org correspondence.
- **Impact:** Previous official findings, if any, cannot be reconciled or proven resolved.
- **Required remediation:** Supply the complete WordPress.org review correspondence and repeat the exact-ZIP reconciliation.
- **Same-pattern occurrences:** Internal reports exist, but none is official correspondence.
- **Verification status:** **Verified fact.**

## 2. Non-blocking findings

### WPR-011 — Changelog is longer than current guidance encourages

- **Severity:** Low
- **Submission-blocking:** No
- **Evidence:** The 9,399-byte readme includes releases 1.11.9 through 1.11.0.
- **Required remediation:** Retain the current and previous release and move older history externally if future growth approaches the parser limit.
- **Verification status:** **Optional recommendation.**

### WPR-012 — Composer metadata contains a strict-validation warning

- **Severity:** Low
- **Submission-blocking:** No
- **Evidence:** `composer validate --strict` reported only that the explicit `version` field is usually omitted. `composer.json` is development-only and is absent from the ZIP.
- **Required remediation:** Remove the Composer `version` field during routine development cleanup.
- **Verification status:** **Verified fact.**

### WPR-013 — Declared WordPress and WooCommerce compatibility lacks a live integration result

- **Severity:** Medium
- **Submission-blocking:** No independently; final verification remains incomplete
- **Evidence:** The ZIP declares WordPress `Tested up to: 7.1` and WooCommerce `WC tested up to: 10.8`. Unit tests use stubs and do not reproduce a live WordPress/WooCommerce runtime.
- **Required remediation:** Test the rebuilt artifact against the exact declared versions and declare only versions actually tested. See <https://developer.woocommerce.com/docs/contribution/contributing/version-support-policy/> and <https://developer.woocommerce.com/docs/extensions/core-concepts/example-header-plugin-comment>.
- **Verification status:** **External claim not independently reproduced.**

## 3. WordPress.org feedback reconciliation

No official WordPress.org review message was available, so official reconciliation remains unresolved.

Internal reports were treated only as evidence:

- `WORDPRESS_ORG_REVIEW_1.5.1.txt`: earlier product-cap, PHPCS and readme-size findings are fixed in source and ZIP. The current package has no product-count quota, PHPCS is clean, and the readme is 9,399 bytes.
- `WORDPRESS_ORG_DEEP_REVIEW_1.6.0.txt`: earlier remote promotion, stale POT, global cache flush, multisite pagination and MU-loader findings are fixed. Declared live-version testing remains unverified.
- `WORDPRESS_ORG_REVIEW_1.10.2.txt`: APCu isolation, remote promotion removal, multisite uninstall handling and readme size are fixed.
- `WORDPRESS_ORG_RECHECK_1.11.1.txt`: network MU-loader and shared-user-meta fixes are present in source and ZIP.
- `WORDPRESS_ORG_RELEASE_REVIEW_1.11.2.txt`: the earlier source/package CSS mismatch is fixed; all current packaged runtime files are byte-identical to source and Git `HEAD`.

Those reports did not identify the current currency-labeling, shared-cache poisoning, broad HTTP blocking, three-letter prefixing or dormant paid-code findings.

## 4. Automated checks passed

| Check | Command and target | Exit | Result |
|---|---|---:|---|
| PHPUnit | `vendor/bin/phpunit --do-not-cache-result --coverage-clover=/tmp/.../coverage.xml --coverage-text --colors=never` against source | 0 | 341 tests, 936 assertions |
| Coverage gate | `php tests/phpunit/check-coverage.php /tmp/.../coverage.xml 85` | 0 | 92.12%; 1,858/2,017 lines |
| PHPCS source | `vendor/bin/phpcs --no-cache --report=json includes mu-plugin uninstall.php turbo-search-for-woocommerce.php` | 0 | 15 files; 0 errors, 0 warnings |
| PHPCS ZIP | Same rules against extracted exact ZIP | 0 | 15 files; 0 errors, 0 warnings |
| PHP syntax | `php -l` over source and extracted ZIP | 0 | 15 + 15 files; 0 failures |
| JavaScript syntax | `node --check` against both JavaScript files | 0 | 0 failures |
| ZIP integrity | `unzip -t dist/turbo-search-for-woocommerce-1.11.9.zip` | 0 | 32 entries; 0 CRC errors |
| Package allowlist | Complete ZIP list against expected runtime allowlist | 0 | 23 files; 0 unexpected or missing files |
| Source/package parity | Byte comparison of packaged files to source and Git `HEAD` | 0 | 23/23 identical |
| Version consistency | PHP header, constant, readme, ZIP, changelog, packaged files and POT | 0 | All `1.11.9` |
| POT consistency | Regenerated with `wp i18n make-pot`, ignoring creation timestamp | 0 | No content difference |
| Composer audit | `composer audit --locked --no-interaction` | 0 | No security advisories |
| Composer validity | `composer --no-plugins --no-scripts validate --strict` | 1 | Valid; one non-packaged version-field warning |

## 5. Manual checks passed

- The exact ZIP has one root directory: `turbo-search-for-woocommerce/`.
- No credentials, secrets, hidden files, Git metadata, tests, development dependencies, internal reports, build scripts, editor files, source maps or nested archives are packaged.
- The version is consistently `1.11.9`.
- Main and readme short descriptions are within expected limits.
- Text domain and packaged POT content are consistent.
- No product-count, request-count, site-count, time or trial restriction intended to force an upgrade was found.
- Core local search operates without a paid license.
- The earlier remote promotional-request mechanism is absent.
- The package does not download executable code.
- No obfuscated code, telemetry or crypto-mining behavior was found.
- Visible upgrade material is restrained and descriptive Pro links are permissible in principle.
- The MU loader's edition selection is required compatibility, although paid implementation branches inside Free still require removal.
- The Plugin URI is product-specific relative to the sibling paid plugin's current generic URI.
- License declarations are GPL-compatible; no separately bundled third-party runtime library needing additional attribution was found.

Live guidance consulted on **2026-09-07**; review concluded on **2026-09-09**:

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

- **Official Plugin Check:** Attempted with `wp plugin check /tmp/...`; exit 1 because no WordPress installation was configured and the `check` command was unavailable.
- **Official online readme validator:** Not available as a standalone local tool. An attempted current parser could not execute outside WordPress because it depends on WordPress functions such as `esc_html()`.
- **WordPress.org MCP:** No WordPress.org MCP server was installed in the review environment.
- **Live WordPress/WooCommerce smoke test:** No local WordPress installation was available, and the original review authorization prohibited creating or changing one.
- **MySQL integration testing:** No review database/runtime was available.
- **Contributor ownership and official name acceptance:** These require WordPress.org account state and a Plugins Team decision.
- **Official feedback reconciliation:** No official correspondence was supplied or stored.

## 7. Exact remediation checklist

1. Replace `wcs`/`WCS` with a distinctive prefix of at least four letters across every global and persisted identifier; add only necessary migration aliases.
2. Remove `sales_30d` schema, forced-zero storage, ranking SQL and documentation from Free.
3. Remove inert synonym/variant methods, unused option listeners and nonexistent hook documentation.
4. Remove paid corrected-query, taxonomy-result and currency-conversion branches from Free JavaScript, CSS and localization unless each becomes complete Free functionality.
5. Make Free always use the store currency when formatting unconverted values.
6. Prevent throttled or incomplete fallback results from entering the shared cache.
7. Remove the global Action Scheduler AJAX HTTP filter and scope it to this plugin's batch callback.
8. Remove `Tested up to` from the PHP header.
9. Confirm a real WordPress.org contributor/owner account and update `Contributors`.
10. Select a distinctive name and matching slug, preferably owner-branded, and align the banner's “WP Fast Search” text with that identity.
11. Confirm Plugin URI availability without relying on the automated request that received HTTP 403.
12. Test against the exact declared WordPress and WooCommerce versions.
13. Supply all official WordPress.org review correspondence for reconciliation.
14. Increment the version, regenerate the POT, build a new ZIP, and repeat every source and exact-artifact check. Any code or metadata change invalidates this artifact verdict.

## 8. Artifact identity

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

## 9. Final verdict

**BLOCKED**

Known technical and policy failures remain in the exact 1.11.9 ZIP, including wrong-currency price presentation, cross-user cache poisoning, globally scoped HTTP blocking, three-letter global prefixing, dormant paid-feature implementation, inaccurate feature documentation, unsupported header placement and unverified contributor/name identity. Official WordPress.org feedback and live compatibility validation are also unavailable.

