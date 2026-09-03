# Turbo Search for WooCommerce (Free) — WordPress.org Compliance Status

This file records this plugin's compliance state against the shared
[`ozulabs/WORDPRESS_ORG_HANDBOOK.md`](../WORDPRESS_ORG_HANDBOOK.md) — the generic WordPress.org
Plugin Handbook extraction used across every OzuLabs product listed there. Read the shared file
for what each rule *is*; this file records what was actually verified in **this** codebase, when,
and this product's own findings against those rules. Section numbers below match the shared
handbook.

**This is a first-pass self-audit, not a confirmation of an actual WordPress.org review outcome**
— see the dated snapshot note at the end. Unlike `ozupay/WORDPRESS_ORG_COMPLIANCE.md`, this plugin
has not yet been through a live WordPress.org submission, so nothing here should be read as "this
got approved" — only "this is what a careful reading of the source shows as of the stated date."

---

## §1 Plugin Basics

- **Headers** (`turbo-search-for-woocommerce.php:4-20`): full field set present — `Plugin Name`,
  `Plugin URI`, `Description` (137 chars, under the 140-char guidance), `Version`, `Author`,
  `Author URI`, `License`, `Text Domain`, `Domain Path`, `Requires at least`, `Requires PHP`,
  `Requires Plugins: woocommerce` (a real hard dependency via the WordPress.org slug — stronger
  than OzuPay's soft `class_exists()` check, and correct: WooCommerce's own slug on .org is
  `woocommerce`). No `Update URI` or `Network` header — both optional; no multisite-only-activation
  behavior is needed here (the plugin already handles multisite via its own `is_multisite()` logic
  in `Activator`, not the `Network: true` header, which is a different mechanism for forcing
  network-only activation).
- **Activation/deactivation** (`class-activator.php`): `activate()` does setup only (schema
  creation, default options, scheduling the initial rebuild, installing the MU cache-bypass file).
  `deactivate()` only unschedules Action Scheduler jobs and WP-Cron, and removes the MU file — no
  data deletion. Compliant with "deactivation is reversible, uninstall is not."
- **Uninstall** (`uninstall.php:11-13`): guards correctly on
  `defined( 'WP_UNINSTALL_PLUGIN' ) || exit;` — not `ABSPATH`. Verified by reading the file
  directly. Compliant.
- **Existence-checking / naming-collision guard**
  (`turbo-search-for-woocommerce.php:26-71,104-134,154-162`): this is the one area with real,
  documented complexity, and it's handled correctly. Both `wcs_search_init()` and
  `wcs_woocommerce_missing_notice()` are wrapped in `function_exists()` — but the code's own
  comment explains *why* this is the sanctioned use and not the anti-pattern the shared handbook
  warns about: an unconditional top-level `function wcs_search_init(){}` is bound by PHP at
  **compile time**, before any runtime guard (including an early `if / return`) can run — so
  `function_exists()` here isn't a weak same-plugin-name collision guard, it's the *only* thing
  that makes the declaration conditional at all. The actual Free/Pro mutual-exclusion logic is a
  separate, correctly-designed mechanism: it detects Pro via `is_plugin_active()` (a real WP core
  check, not a guess) and defers `deactivate_plugins()` to `'shutdown'` specifically because
  `activate_plugin()` reads `active_plugins` into a local variable *before* this file's `include`
  runs and would otherwise silently clobber a synchronous deactivation. This is a materially
  correct engineering solution to a real WordPress quirk, not a workaround for a naming problem —
  worth keeping intact exactly as commented if it's ever touched.

---

## §2 Security

**Every AJAX/REST handler traced individually** (not just grepped for the presence of
`check_ajax_referer`), 2026-09-01:

| Handler | File:Line | Nonce | Capability | Notes |
|---|---|---|---|---|
| `ajax_dismiss_notice` | `class-admin-settings.php:33` | `check_ajax_referer( 'wcs_dismiss_notice' )` | `current_user_can( 'manage_options' )` | notice_id validated against an explicit allow-list + a bounded regex for server-driven promo ids |
| `ajax_delete_all_data` | `class-admin-settings.php:401` | `check_ajax_referer( 'wcs_delete_all_data' )` | `current_user_can( 'manage_options' )` | destructive (drops tables) — both checks run before any action |
| `ajax_rebuild_index` | `class-admin-settings.php:463` | `check_ajax_referer( 'wcs_rebuild' )` | `current_user_can( 'manage_options' )` | |
| `ajax_get_index_status` | `class-admin-settings.php:502` | `check_ajax_referer( 'wcs_status' )` | `current_user_can( 'manage_options' )` | read-only status poll, still gated — correct, since it also self-heals stuck rebuilds server-side |
| `ajax_refresh_nonce` | `class-frontend.php:91` | none (deliberate) | none (deliberate, `wp_ajax_nopriv_*`) | Documented in-code: this endpoint only *issues* a fresh `wp_rest` nonce, it never consumes one or mutates state, so there is nothing a CSRF could forge. Rate-limited instead (10/min/IP via `Rate_Limiter`) specifically to stop it being used as a free nonce dispenser. This is a sound, narrow exception — not a gap. |

All four `manage_options`-gated handlers put the nonce check and the capability check as
literally the first two statements, before touching any data — matches the handbook's "nonce
alone never proves authorization" principle exactly (nonce first, capability second, in that
order, every time).

**REST endpoint** (`class-search-handler.php:28-71`): `register_rest_route( 'wcs/v1', '/search', … )`
with a `permission_callback` that calls `wp_verify_nonce( $nonce, 'wp_rest' )` — the core WP-standard
nonce action for REST traffic (not a bespoke per-record action, which is correct here: this is a
public, read-only product-search endpoint with no per-record data to scope a nonce action to,
unlike e.g. OzuPay's per-order payment-status checks). No capability check on this endpoint —
correct, since product search is public data by definition (same trust level as WooCommerce's own
built-in search); the nonce exists only to block trivial scripted scraping, backed up by a
per-IP rate limiter (60 req/min via `Rate_Limiter::allow()`, `class-search-handler.php:66`).

**Note on the REST API vs. the handbook's "all AJAX through admin-ajax.php" rule (§5):** the
search endpoint is a `register_rest_route()` REST route, not admin-ajax traffic. The shared
handbook's AJAX section is written around the classic `wp_ajax_*`/`admin-ajax.php` mechanism and
doesn't explicitly bless or forbid the REST API as an alternative — but the REST API is WordPress
core's own sanctioned, modern mechanism for exactly this kind of endpoint (it is not "inventing a
direct endpoint" in the sense the handbook warns against — it's the standard `register_rest_route()`
API), and every actual `wp_ajax_*` action in this plugin (four in `Admin_Settings`, one in
`Frontend`) correctly goes through `admin-ajax.php`. Not treating this as a violation, but flagging
it explicitly since the handbook doesn't spell out the REST case.

**MU cache-bypass plugin** (`mu-plugin/wcs-cache-bypass.php`): re-implements the same nonce check
(`wp_verify_nonce( $nonce, 'wp_rest' )`, line 75) before serving a cached response — it does not
bypass the security check, only the WordPress bootstrap/routing overhead for a cache hit. Verified
line-by-line: every code path that returns data either fails the nonce check and falls through to
the real REST route, or passes the identical check the REST route itself would have performed.

**Sanitizing:** input sanitization is consistently function-per-purpose —
`sanitize_text_field()` for the query string, `sanitize_key()` for tab slugs/notice ids/stock
status, `absint()`/explicit clamping for numeric settings (with an explicit note in
`register_settings()` about why bare `absint()` was rejected — a blank submission silently saving
as `0` previously put a literal `LIMIT 0` on every query and disabled search with no visible
error; the current clamp closure prevents that class of bug specifically).

**Escaping:** consistently late — verified across all four view templates
(`includes/views/*.php`) and both `Frontend::render_shortcode()` and `Admin_Settings`. One
explicit, correctly-commented exception matches the handbook's carve-out precisely:
`class-frontend.php:51-56` passes plain `__()` (not `esc_html__()`) into the `wcs_config` JS
object because the value is rendered via `.textContent` in `search.js`, not HTML-parsed — using
`esc_html__()` there previously left literal `&quot;` visible instead of a real quote character.
This is the "escape at creation only when it can't pass through `wp_kses()` at all" exception the
handbook itself allows, and it's documented in-code as such.

**Data validation:** the tab slug in `render_settings_page()` (`class-admin-settings.php:360`) uses
`sanitize_key()` + `in_array( ..., true )` against an explicit `array( 'settings', 'data', 'docs' )`
allow-list — the exact safelist-with-strict-comparison pattern the handbook recommends. Currency
codes (both in `Search_Handler` and the MU plugin) are normalized to 3 uppercase letters and
checked against known-currency-plugin option values before being trusted for a cache key, with an
explicit fallback to the store default for anything unrecognized.

**Capabilities:** every settings-mutating/data-destroying code path checks `current_user_can( 'manage_options' )`
— no custom capability is defined (this plugin doesn't need one; `manage_options` is the correct,
narrowest built-in capability for "can configure this store's plugins"). No role-name string checks
anywhere (verified via grep for `current_user_can( 'administrator'` — zero hits).

---

## §3 Hooks

Verified 2026-09-01 — every custom `do_action()`/`apply_filters()` call site uses the `wcs_` prefix:
`wcs_search_results`, `wcs_ranking_weights`, `wcs_get_client_ip`, `wcs_index_rebuild_complete`,
`wcs_batch_time_budget`, `wcs_indexed_taxonomies`, `wcs_indexed_product_data`, `wcs_batch_size`,
`wcs_known_currencies` — all found via `grep -rn "do_action\|apply_filters"` across `includes/`,
the main plugin file, and the MU plugin, cross-checked against the grep output with none excluded.
No un-prefixed custom hook found.

---

## §4 Internationalization

- **Text domain vs. slug:** `Text Domain: turbo-search-for-woocommerce` (plugin header) matches
  the string used in every translation function call (spot-checked across all `includes/*.php`,
  `includes/views/*.php`, and the main plugin file) and matches the slug `build.sh` uses
  (`PLUGIN_SLUG="turbo-search-for-woocommerce"`, `build.sh:36`) and the folder name inside the
  shipped zip (`unzip -l dist/turbo-search-for-woocommerce-1.1.2.zip` shows the top-level folder
  as `turbo-search-for-woocommerce/`). **Caveat, stated explicitly per the audit instructions:** I
  have no way to independently confirm from this repo alone that `turbo-search-for-woocommerce` is
  the *actual, already-registered* WordPress.org slug (as opposed to merely the intended one) —
  there is no live-listing check available in this session. Everything internal to the repo is
  internally consistent; if this edition has not yet been submitted, the first submission is what
  actually reserves the slug (and per §20, it's permanent once approved — confirm intent before
  first submission).
- **No variable text domains:** grepped every translation function call
  (`__()`, `_e()`, `esc_html__()`, `esc_html_e()`, `esc_attr__()`, `esc_attr_e()`) — every call
  passes the literal string `'turbo-search-for-woocommerce'`, never a variable. Zero hits for a
  variable in the text-domain argument position.
- **No embedded-variable translatable strings:** spot-checked the higher-risk files
  (`class-admin-settings.php`'s `i18n` config block, `tab-settings.php`, `tab-app-data.php`,
  `tab-docs.php`) — every string with a dynamic value uses `sprintf()`/`printf()` with `%d`/`%s`/
  `%1$d`/`%2$d` placeholders and a `/* translators: */` comment directly above (e.g.
  `class-admin-settings.php:318`, `tab-settings.php:26-27,72,79`). No `_e( "text $var" )` pattern
  found.
- One thing worth a second look before submission: `assets/js/admin.js` does its own placeholder
  substitution client-side (`i18n.progress.replace('%1$d', processed).replace('%2$d', total)`,
  `admin.js:88`) rather than using `wp.i18n`'s `sprintf`. This isn't a handbook violation — the
  strings themselves are still proper PHP-side translatable strings with correct placeholders and
  translator comments, JS is just doing simple string substitution on the already-localized text —
  but it means a translator reordering `%1$d`/`%2$d` in their locale works correctly (JS replaces
  by literal placeholder text, not positionally), which was likely the point.

---

## §5 JavaScript, jQuery, and Ajax

- **Enqueuing:** every `wp_enqueue_script()`/`wp_enqueue_style()` call is inside the correct hook
  (`admin_enqueue_scripts` in `Admin_Settings::enqueue_admin_assets()`, gated to the plugin's own
  screen via `$hook_suffix` check at `class-admin-settings.php:302`; `wp_enqueue_scripts` in
  `Frontend::enqueue_assets()`). Real `$ver` passed everywhere (`WCS_VERSION`, or `time()` under
  `WP_DEBUG` for dev cache-busting — `class-frontend.php:37-40`). Scripts load in the footer
  (`true` as the last arg) in both enqueue calls. No jQuery dependency — both JS files are vanilla,
  which also means no risk of relying on WordPress's bundled jQuery version drifting.
- **`wp_localize_script()`-equivalent:** this plugin uses `wp_add_inline_script()` with a `const`
  declaration instead of the literal `wp_localize_script()` function
  (`class-admin-settings.php:335`: `const wcsAdmin = …`; `class-frontend.php:80`:
  `const wcs_config = …`) — functionally the same sanctioned pattern (PHP data → JS global), and
  both object names are effectively prefixed (`wcsAdmin`, `wcs_config`) even though `wcs_config`
  uses a snake_case break from the `wcs`-prefix camelCase convention used elsewhere; not a
  violation, just an inconsistency worth normalizing if this file is touched again.
- **Ajax:** all five real `wp_ajax_*`/`wp_ajax_nopriv_*` actions go through `admin-ajax.php`
  (table in §2 above). `wp_send_json_success()`/`wp_send_json_error()` are used throughout, which
  already call `wp_die()` internally — so the handbook's "every AJAX callback must `wp_die()` on
  both paths" requirement is satisfied without a separate explicit call.
- **No inline `<script>`/`<style>` hand-echoed into a page:** verified across all four view
  templates and both class files that render admin markup — `admin.js`/`search.js` are always
  proper enqueued files, with configuration injected via `wp_add_inline_script()` (the sanctioned
  mechanism), never a raw `<script>` tag with interpolated PHP.

---

## §6 Cron

- `wcs_daily_transient_gc` is the one real WP-Cron hook. Guarded correctly:
  `if ( ! wp_next_scheduled( 'wcs_daily_transient_gc' ) ) { wp_schedule_event(...) }`
  (`class-activator.php:171-173`, inside `init()`, which runs on every `plugins_loaded` — so this
  guard matters and is present).
- Unscheduled on deactivation: `deactivate_single_site()` (`class-activator.php:157-160`) calls
  `wp_next_scheduled()` + `wp_unschedule_event()` for the same hook. Also cleaned up in
  `uninstall.php:64-67` for the delete-data path. Compliant on all three lifecycle points
  (schedule guard, deactivation unschedule, uninstall unschedule).
- The heavier background work (index rebuilds, per-product reindexing, cache-bust debouncing) runs
  through **Action Scheduler**, not WP-Cron — `as_unschedule_all_actions( null, array(), 'turbo-search-for-woocommerce' )`
  is called on deactivation (`class-activator.php:154`) and in the uninstall path
  (`uninstall.php:59-61`), scoped to the plugin's own Action Scheduler group so it can't cancel
  unrelated jobs. This is a correct, more-robust-than-required pattern (Action Scheduler is what
  the handbook's own "use for heavy/long-running tasks" performance guidance points toward), and
  it's cleaned up on both deactivation and uninstall exactly like a WP-Cron hook would need to be.

---

## §7 HTTP API

- Exactly one outbound HTTP call in the entire codebase: `Promo::get()` (`class-promo.php:50`),
  using `wp_remote_get()` with an explicit 5-second timeout, `is_wp_error()` checked, response code
  checked, and the result cached in a transient (12h success / 1h failure) so a server outage or a
  slow endpoint can't repeat-hit on every admin page load. This is the Transients-API caching
  pattern the handbook explicitly recommends for "expensive/repeated remote calls."
- Verified via `grep -rn "curl_init\|curl_exec\|file_get_contents"` across `includes/`, the main
  plugin file, and the MU plugin: the only `file_get_contents` hit is
  `class-indexer.php:1346` reading `/proc/cpuinfo` (a local filesystem read for CPU-count detection,
  not a network call) — correctly not a violation of "use `wp_remote_*` instead of raw HTTP calls,"
  since it isn't an HTTP call at all.
- **What `Promo::get()` actually does, for the record:** it fetches an optional promo/announcement
  banner from `https://ozupay.com/wp-json/ozls/v1/promo`, sending only a `plugin_slug` query
  parameter (`turbo_search_free`) — no site URL, no usage data, no telemetry payload. This is a
  content *fetch*, not a data *send*, so it does not trigger the handbook's §14.7 "tracking
  requires opt-in" rule (that rule is about the plugin phoning home with data about the site, not
  about pulling a banner shown in the plugin's own dismissible admin notice). Worth noting
  content fetch, not a data send. The readme now has a dedicated `External services` section that
  names the endpoint, trigger, static plugin-edition parameter, cache periods, data not sent, and
  the provider's website/privacy policy. This replaces the original audit's changelog-only
  disclosure gap.
- **`link_url` hardened 2026-09-03** (`Promo::sanitize_link_url()`, `class-promo.php`): the promo
  payload's `message`/`link_text` are still remote-controlled content rendered via
  `wp_kses_post()`/`esc_html()`, but `link_url` is now validated against an explicit hostname
  allow-list (`ozulabs.com`, `ozupay.com`), rejected outright if not `https`, and rejected if its
  query string carries any tracking-parameter prefix (`utm_`, `ref`, `affiliate`, `click_id`,
  `fbclid`, `gclid`). A rejected `link_url` degrades to no link rather than sinking the whole promo
  (the message still renders) — see `PromoTest.php`'s allow-list/tracking-param/non-https tests.

---

## §8 Metadata, Custom Post Types, Taxonomies

- N/A for post types — this plugin registers none. It reads existing taxonomies
  (`product_cat`, `product_tag`, `product_brand`, WooCommerce attribute taxonomies) but registers
  none of its own.
- User meta keys used for notice-dismissal state (`wcs_notice_mu_bypass_dismissed`,
  `wcs_notice_no_cache_dismissed`, and the dynamic `wcs_notice_promo_{id}_dismissed`) are all
  `wcs_`-prefixed. Deleted in bulk on uninstall via an explicit `IN (...)` list
  (`uninstall.php:87-94`) — though note that list only covers the two *static* keys
  (`wcs_notice_mu_bypass_dismissed`, `wcs_notice_no_cache_dismissed`); the dynamic per-promo
  dismissal keys (`wcs_notice_promo_{dismiss_id}_dismissed`, `class-admin-settings.php:78`) are
  **not** swept on uninstall since their exact key can't be known ahead of time without a
  `LIKE 'wcs_notice_promo_%'` usermeta query, which `uninstall.php` doesn't currently run. This is
  a minor, low-severity orphaned-data gap (a few bytes of usermeta per admin who ever saw and
  dismissed a promo banner) — not a security or major-guideline issue, but technically incomplete
  relative to the "clean up properly on uninstall" principle (§13) if `wcs_delete_data_on_uninstall`
  is enabled. Worth a follow-up fix (`DELETE FROM usermeta WHERE meta_key LIKE 'wcs\_notice\_promo\_%'`),
  not urgent enough to block a submission on its own.

---

## §9 Settings and Options

- This plugin uses the **real Settings API**, not a hand-rolled equivalent: `register_setting()`
  for every option (`class-admin-settings.php:235-289`), `settings_fields()` in every form
  (`tab-settings.php:134`, `tab-app-data.php:22`), and `options.php` as the form action — meaning
  the nonce, the `manage_options` capability check, and sanitize-callback wiring are all handled by
  WordPress core itself, not reimplemented. This is the *more* standard-compliant of the two
  legitimate patterns the handbook describes (vs. OzuPay's hand-rolled equivalent), and it means
  Plugin Check's `setting_sanitization` check has real `register_setting()` calls with real
  `sanitize_callback` entries to inspect, unlike a hand-rolled page.
- Deliberately **two separate settings groups** (`wcs_settings_group`, `wcs_data_settings_group`)
  rather than one — documented in-code (`class-admin-settings.php:281-284`) as intentional: they
  render in separate `<form>` tags on separate tabs, and `options.php` nulls out every option in a
  submitted group that isn't present in that particular form's fields, so sharing one group across
  two tabs would silently wipe the other tab's settings on save. This is a correct, non-obvious
  Settings-API gotcha handled properly, not an accidental fragmentation.
- Options are **not** consolidated into a single array option — each setting
  (`wcs_result_count`, `wcs_min_chars`, `wcs_search_title`, etc.) is its own row. The handbook
  recommends grouping related values into one array option "for performance — fewer individual DB
  round-trips," which this plugin does not do. In practice the impact is small (most of these
  options are individually read at most once per admin page load, and several are `autoload=true`
  so they ride along in WordPress's single `alloptions` query anyway — verified `autoload` args at
  their `register_setting()`/`update_option()` call sites), but it's a real, if minor, divergence
  from the handbook's stated best practice. Not something Plugin Check enforces as an error; noted
  for completeness rather than flagged as a compliance failure.

---

## §10 Users, Roles, and Capabilities

- N/A for `add_role()`'s one-time-only gotcha — this plugin creates no custom role and adds no
  custom capability at all. It uses only the built-in `manage_options` capability throughout.
  Nothing to verify here beyond "no role/capability code exists," confirmed by grep
  (`grep -rn "add_role\|add_cap\|register_activation_hook.*cap"` — zero relevant hits beyond the
  `manage_options` checks already covered in §2).

---

## §11 Administration Menus

- Registered on `admin_menu` (`class-admin-settings.php:22`), via `add_menu_page()` with
  `'manage_options'` as the capability argument (`class-admin-settings.php:192`), plus three
  `add_submenu_page()` calls for the tab flyout (Settings / App Data / Documentation), each also
  passed `'manage_options'` (`class-admin-settings.php:224`).
- **Independent re-check inside the render callback:** `render_settings_page()` itself opens with
  `if ( ! current_user_can( 'manage_options' ) ) { return; }` (`class-admin-settings.php:356-358`)
  — satisfying the handbook's explicit "menu visibility is not a security boundary, re-check inside
  the callback" rule, not just relying on the menu registration's own capability argument.
- Menu icon is a plain SVG file URL with a version query string, not a base64 data URI — the
  in-code comment (`class-admin-settings.php:195-199`) correctly explains this is deliberate:
  WordPress recolors base64-embedded SVG menu icons to a flat admin-color-scheme mask, which would
  wash out this plugin's actual icon color. Not a compliance issue either way, just worth noting
  the reasoning is documented rather than accidental.

---

## §12 Shortcodes

- One shortcode: `[turbo_search]`, registered via `add_shortcode( 'turbo_search', … )`
  (`class-frontend.php:26`). Correctly prefixed relative to the plugin's own namespace (`turbo_search`
  reads as this plugin's own term, not a generic English word, even though it doesn't carry the
  `wcs_` prefix used elsewhere — the handbook's prefix-uniqueness bar is about avoiding collision
  with another plugin's identically-named shortcode, and `turbo_search` is distinctive enough for
  that purpose; a hypothetical collision would need another plugin to coincidentally choose the
  exact same tag).
- `render_shortcode()` **returns** its output (`class-frontend.php:144-160`, a single `return
  sprintf(...)`) — never echoes — matching the handbook's explicit "never echo inside a shortcode
  callback" rule.
- `shortcode_atts( $defaults, $atts, $tag )` is used correctly with the tag name as the third
  argument (`class-frontend.php:120-128`), and every attribute value is escaped at the point of
  interpolation into the returned HTML (`esc_attr()` throughout the `sprintf()` call).

---

## §13 Privacy

- **The 13-question self-audit, answered:**
  - *Share data with third parties?* No.
  - *Bundle a third-party SDK that collects data?* No.
  - *Send telemetry?* No — confirmed by reading every file for any outbound `wp_remote_*` call
    (§7): the only one is the promo-banner *fetch*, which sends nothing but a static plugin-slug
    string, never anything about the site, its products, or its traffic.
  - *Set cookies?* No — this plugin only *reads* cookies set by other multi-currency plugins
    (`wmc_current_currency`, `woocs_current_currency`, etc.) for currency-detection purposes; it
    sets none of its own.
  - *Need a data exporter/eraser?* No personal data is collected or stored by this plugin at all —
    the search index holds only product data (title, SKU, price, stock, images — all already
    public), and the only user-linked data is admin notice-dismissal usermeta (not personal data in
    the GDPR sense; it's an admin's own UI preference, not data *about* them).
  - *Log anything containing personal data?* `Logger::log()` writes operational messages (rebuild
    progress, batch failures) via `wc_get_logger()` — spot-checked every `Logger::log()` /
    `self::log()` call site across `class-indexer.php` and found only product IDs, epoch numbers,
    and error strings; no customer/order/PII data logged anywhere.
  - *Clean up properly on uninstall?* Yes, gated behind an explicit opt-in setting
    (`wcs_delete_data_on_uninstall`, default `false`) — see §1/§8. One minor gap noted in §8 (promo
    notice-dismissal usermeta not swept).
- **Privacy by Design / opt-in-by-default (§14.7):** the one setting that controls data retention
  (`wcs_delete_data_on_uninstall`) defaults to `false` — i.e. the *safer* default (don't delete
  data silently) rather than the more aggressive one, which is the correct default direction for
  this particular setting (accidental data loss is worse than a lingering orphaned table after
  uninstall). This isn't a "tracking opt-in" in the sense §14.7 is usually applied to, since there
  is no tracking in this plugin at all — noted for completeness.

---

## §14 Detailed Guidelines — relevant points expanded

1. **GPL compatibility** — `License: GPLv2 or later` declared in both the plugin header and
   `readme.txt`; `composer.json` also declares `"license": "GPL-2.0-or-later"`. No bundled
   third-party PHP/JS library found (both `admin.js` and `search.js` are hand-authored vanilla JS,
   verified by reading them in full — no minified/vendored library signature in either).
2. **Developer responsibility** — noted as a live risk area specifically because of the MU-plugin
   install step (see the `write_file` finding under §16 below): a file this plugin writes outside
   its own directory is squarely "responsible for every file," even though it isn't literally
   inside the submitted zip's own folder once copied.
3. **Stable version maintained in the directory** — not applicable/not yet verifiable; this
   edition's submission history to WordPress.org could not be checked in this session (no live
   listing access). If already listed, re-verify trunk/tag currency before the next release.
4. **No obfuscation** — confirmed: all PHP and JS is human-readable, real variable/function names,
   no packers or minified-without-source files shipped (checked `assets/js/` and `assets/css/` —
   both are the actual hand-authored source, not a minified build).
5. **No trialware** — **corrected 2026-09-03.** This section previously argued the 100-product
   `Indexer::FREE_PRODUCT_CAP` was an acceptable permanent architectural limit because it never
   expired by time. A dedicated review (`WORDPRESS_ORG_REVIEW_1.5.1.txt`) found that reasoning
   unreliable: the current official Guideline 5 text prohibits functionality being disabled after
   a quota is met regardless of whether the quota is time-limited, and a working local indexer
   that deliberately stops at a payment-linked number is exactly the pattern it targets — unlike
   excluding a genuinely separate Pro-only feature. `FREE_PRODUCT_CAP` and every quota-reached flag,
   admin-notice, and readme claim built on it have been removed entirely; this edition now indexes
   its full published catalog with no product-count limit of any kind (see
   `tests/phpunit/tests/IndexerBulkTest.php::test_bulk_indexes_more_than_a_hundred_products_in_one_call`
   and the "Free edition indexes the full catalog, no product cap" tests in
   `InitAndBatchLifecycleTest.php`). Every remaining Pro-only feature in this plugin's UI is still a
   genuinely **absent** capability, not a working feature gated by a license check:
   `Query_Normalizer::word_variants()` and `synonym_map()` are hardcoded to return empty arrays with
   an in-code comment stating this is intentional, not a feature flag
   (`class-query-normalizer.php:127-135,176-186`). This is the clean "freemium: a genuinely separate,
   functional free plugin plus a separate paid upgrade" pattern the handbook explicitly allows, not
   the "ships the paid feature's code but license-locks it" pattern it forbids.
6. **SaaS** — N/A; no SaaS functionality of any kind, paid or free.
7. **No unauthorized tracking** — confirmed no telemetry exists at all (§13).
8. **No third-party code execution** — the promo banner (`Promo::get()`) renders only
   `wp_kses_post()`-sanitized *text* (`class-admin-settings.php:83`, allowing only post-content-safe
   tags — no `<script>`), never executes anything fetched from the network. No iframes, no CDN
   script loading anywhere in the codebase.
9. **No illegal/dishonest/manipulative conduct** — no "implying payment unlocks included features"
   language found; every Pro-upsell string is phrased as "this is a Pro feature" / "Upgrade to Pro
   for X," never implying a *purchased* feature is being artificially withheld from a working
   install.
10. **"Powered by" links must default off** — N/A, no such links exist in this plugin at all
    (verified: the only external links in the entire UI are the `ozulabs.com` upsell links inside
    the plugin's own settings page, and the `support@ozulabs.com` mailto — neither is a "powered
    by"-style credit link injected into site-facing output).
11. **Admin notices dismissible/contextual, no ad-space abuse** — every notice in
    `render_admin_notices()` is scoped to the plugin's own settings screen only (`class-admin-settings.php:67-71`,
    an explicit `get_current_screen()` check before rendering anything) and is either
    `is-dismissible` with real per-user persistence via usermeta, or — for the schema-failure and
    Action-Scheduler-missing notices — a genuine blocking-config-error state that the handbook's
    own carve-out explicitly allows to stay non-dismissible (it auto-clears once the underlying
    problem is fixed: `wcs_schema_error` is deleted the moment `create_tables()` next succeeds).
    No notice appears outside the plugin's own screen; no referral-tracking query params on any
    notice link.
12. Tags/readme spam — see §17 below.
13. **Uses WordPress's own bundled libraries** — N/A, no JS framework/library dependency at all
    (vanilla JS throughout), so there's no bundled-copy-of-jQuery-etc. question to answer.
17. **Trademarks** — "Turbo Search for WooCommerce" follows the accepted "Feature for Product"
    naming pattern (Turbo Search leads, WooCommerce is the descriptive integration target), the
    same pattern the handbook cites as acceptable and the same one OzuPay Free's approved name
    uses.

---

## §15 Official Review Checklist

- **No PHP short tags** — verified via `grep -rn "<?=" --include="*.php"` and a scan for bare
  `<?` not followed by `php` across `includes/`, views, the main file, and `uninstall.php`: zero
  hits. Every file opens with `<?php`.
- **SQL uses prepared statements** — every direct `$wpdb` query site in `class-activator.php`,
  `class-indexer.php`, `class-search-handler.php`, `class-admin-settings.php`, `uninstall.php`, and
  the MU plugin goes through `$wpdb->prepare()`, including table-name interpolation via the `%i`
  placeholder (verified this is used consistently for `DROP TABLE`/`TRUNCATE TABLE`/`SHOW TABLES
  LIKE`/`ALTER TABLE` statements rather than raw string concatenation of table names). Several
  sites carry a `phpcs:ignore` comment acknowledging `WordPress.DB.DirectDatabaseQuery` — that
  sniff family is about auditing *direct* queries generally, not about whether they're prepared;
  every one of those ignored lines is still wrapped in `$wpdb->prepare()`.
- **PHPUnit coverage exists and is CI-enforced** — `phpunit.xml.dist` covers the full `includes/`
  directory; `composer.json`'s `coverage` script runs a coverage-ratchet check
  (`tests/phpunit/check-coverage.php`) that **fails the build below an 85% line-coverage
  threshold**, and `VERSION_MANAGEMENT.md` states `build.sh` runs the full suite and "aborts before
  committing anything if it fails" — meaning a release literally cannot ship without tests passing.
  16 test files exist under `tests/phpunit/tests/`, including dedicated files for admin-settings
  AJAX behavior, frontend enqueue/nonce behavior, cache-key parity between the REST handler and the
  MU plugin, and init/batch lifecycle — a meaningfully thorough suite for a plugin this size.
- **No `WP_DEBUG` notices/warnings** — `phpunit.xml.dist` sets `failOnWarning="true"` and
  `failOnNotice="true"`, which catches PHP-level warnings/notices during the test run itself; this
  is not the same as a live `WP_DEBUG`-on manual smoke test against a real WordPress install (which
  this audit session had no environment to perform), so treat this as "the test suite is
  configured to be strict," not as "a live `WP_DEBUG` pass was actually run this session."
- **Minified assets ship with unminified source alongside** — N/A, no minified assets are shipped
  at all (see §14.4).

---

## §16 Plugin Check tool — audit against all 33 checks

The table below began as a manual source review. A real WordPress.org Plugin Check run was later
performed against a live WordPress 7.1 install on 2026-09-01. It found two readme/header issues
(stale `Tested up to` and an overlong short-description block); both were fixed in commit
`5c9d9bb`. Re-run Plugin Check against the final uploaded ZIP before submission because this
recorded run does not automatically validate later package changes.

| Check | Verdict | Evidence |
|---|---|---|
| `late_escaping` | Clean | Escaping verified late/at-output throughout (§2); one documented early-escape exception matches the handbook's own carve-out. |
| `safe_redirect` | Clean | No `header( 'Location: ...' )` anywhere in the codebase (grepped); no redirect logic exists in this plugin at all. |
| `direct_db_queries` / `direct_db` | Clean | Every query prepared (§15). |
| `enqueued_scripts_size` / `enqueued_styles_size` | Likely clean, not measured | `search.js` and `admin.js` are hand-authored, no vendored library; file sizes were not measured against the tool's specific threshold this session — worth a quick `wc -c assets/js/*.js assets/css/*.css` before submission just to have the number in hand. |
| `performant_wp_query_params` | Needs a closer look | The plugin's own custom `wcs_search_index` table queries are fine (not what this check targets — it targets `WP_Query`/`get_posts()` `meta_query`/`tax_query` shapes). The one real `WP_Query`-adjacent risk is `Activator`/`uninstall.php`'s `SELECT blog_id FROM {$wpdb->blogs} LIMIT 1000` for multisite loops — a direct query, not `WP_Query`, and already bounded with `LIMIT 1000` with an in-code rationale. Likely fine but this check's exact scope wasn't independently confirmed. |
| `enqueued_scripts_in_footer` | Clean | Both scripts enqueued with `$in_footer = true`. |
| `enqueued_resources` / `enqueued_styles_scope` / `enqueued_scripts_scope` | Mostly clean, one documented exception | Admin assets scoped to the plugin's own screen only. Frontend assets load on every front-end page — but this is the handbook's own explicitly-allowed exception (a site-wide search-box enhancement genuinely needs to run everywhere a theme might place a search field), and it's documented in-code as a deliberate choice (`class-frontend.php:33-35`), matching the same pattern OzuPay's compliance doc accepted for its own persistent-sidebar-icon asset. |
| `non_blocking_scripts` | Not used, not flagged as required | No `strategy: 'defer'/'async'` on either enqueue call. Not necessarily an error-level flag (the check likely just suggests it where safe) but worth considering for `search.js` specifically, which runs on every front-end page load. |
| `code_obfuscation` | Clean | See §14.4. |
| `plugin_content` (readme spam) | Clean | See §17. |
| `file_type` | Likely clean, not exhaustively verified | No obviously disallowed file types found while reading the tree (`ls -la`, `find`), but a full file-type sweep of the packaged zip wasn't run this session. |
| `plugin_header_fields` | Clean | See §1. |
| `plugin_updater` | Clean | No self-update mechanism found anywhere — no `wp_update_plugins` filter override, no custom update-check HTTP call. |
| `plugin_uninstall` | Clean | See §1. |
| `plugin_readme` | Clean | See §17. |
| `localhost` | Clean | Grepped for `localhost`/`127.0.0.1`/`.local` across the codebase — no hits. |
| `no_unfiltered_uploads` | N/A | Plugin does not handle file uploads at all. |
| `trademarks` | Clean | See §14.17. |
| `offloading_files` | Clean | No CDN-hosted asset loading found — everything is served from the plugin's own `assets/` directory via `WCS_PLUGIN_URL`. |
| **`write_file`** | **Fixed** | The original audit found raw `copy()`/`unlink()` calls in the MU-plugin lifecycle. Commit `3f2de99` migrated these paths to WordPress's filesystem API using the direct adapter only when it can run without requesting credentials. |
| `setting_sanitization` | Clean | Real `register_setting()` calls with real `sanitize_callback` entries throughout (§9) — unlike a hand-rolled settings page, this check has actual data to inspect and should pass cleanly. |
| **`prefixing`** | Clean | The manual §19 sweep found no unprefixed identifiers. Commit `ba3fc67` subsequently added `phpcs.xml` and Composer lint scripts; the current PHPCS gate passes. |
| `minified_files` | Clean | See §14.4. |
| `direct_file_access` | Clean | Every PHP file opens with an `ABSPATH` guard (`turbo-search-for-woocommerce.php`, every `includes/*.php`, every `includes/views/*.php`, `uninstall.php` via its own `WP_UNINSTALL_PLUGIN` guard, and the MU plugin) — verified by reading each file's opening lines. |
| `external_admin_menu_links` | Clean | The admin menu itself points only at the plugin's own settings page; external `ozulabs.com` links exist only as upsell `<a>` tags *within* rendered page content, not as menu items. |
| `wp_functions_compatibility` | Not independently verified | Would require checking every WP function call against the `Requires at least: 6.5` floor — not exhaustively cross-referenced this session; nothing encountered while reading the code looked unusually new/deprecated, but this wasn't a targeted pass. |
| `i18n_usage` | Clean | See §4. |
| `php_error_reporting` | Clean | Grepped for `error_reporting(`/`ini_set( 'display_errors'` — zero hits. The plugin does call raw `error_log()` in two places (`Logger::log()`'s fallback path when `wc_get_logger()` is unavailable, and `uninstall.php`'s MU-file-removal-failure path) — that's a different, allowed thing (writing a log line) from *changing the site's error-reporting configuration*, which is what this check actually targets. |
| `ai_provider` | N/A | No AI-service integration of any kind. |

**Summary:** the original actionable `write_file` finding is fixed, and the later real Plugin
Check run's two metadata findings are fixed. A final run against the exact upload ZIP remains a
release gate.

---

## §17 readme.txt structure

- **File size:** `wc -c readme.txt` → **6,082 bytes** (2026-09-03; was 13,868 bytes at the top of
  that day, over the ~10KB threshold, before the changelog split below) — comfortably under the
  ~10KB threshold, with headroom for several more releases' changelog entries before this needs
  trimming again (unlike OzuPay's readme, which has repeatedly grown back past the threshold —
  worth watching for the same drift here as more `== Changelog ==` entries accumulate, per the
  handbook's explicit "recurring gap, not one-time" warning).
- **Header fields, checked against the mechanics in the shared handbook, not just presence:**
  - `Stable tag: 1.1.2` (readme) matches `Version: 1.1.2` (plugin header, `turbo-search-for-woocommerce.php:8`)
    and `WCS_VERSION` (`define( 'WCS_VERSION', '1.1.2' )`, line 74) — all three in sync. This
    matters for the exact mechanistic reason the handbook describes: `Stable tag` controls which
    SVN tag folder is read, while the plugin header's `Version` is what's actually displayed on the
    Download button — a mismatch between these two would show a stale version number even with a
    correct `Stable tag`.
  - `Requires at least: 6.5`, `Tested up to: 7.0`, `Requires PHP: 8.0` — all present in both the
    readme and the plugin header, and in sync between the two (checked both files side by side).
    `Tested up to` is major.minor format (`7.0`, not a patch-level number), matching the handbook's
    format rule.
  - `Requires Plugins: woocommerce` present in the plugin header (line 17) — not duplicated in the
    readme's own header block, which is fine; since WP 5.8 this is read from the plugin file, not
    parsed from the readme.
  - `License: GPLv2 or later` / `License URI` both present and correct.
  - `Contributors: ozulabs` — a single WordPress.org username; not independently verified that this
    username actually exists/is spelled correctly on .org (no live lookup available this session).
- **Tags:** `woocommerce, search, product search, live search, ajax search` — **exactly 5**, at the
  handbook's stated maximum (don't add a 6th without removing one). None are an obvious competitor
  plugin's name. **Not independently verifiable this session:** whether any of these five is a tag
  "unique to this plugin" (which the handbook says gets silently dropped since tags exist to group
  *shared* categories) requires querying the live WordPress.org tag directory, which wasn't
  available here — `search`, `product search`, `live search`, and `ajax search` all read as
  plausible shared/generic terms likely used by other search plugins, but this is an inference, not
  a verification.
- **One-line description** (under the `=== Plugin Name ===` header block): "Instant live product
  search for WooCommerce using native MySQL FULLTEXT indexing." — well under the ~150-char cap, no
  markup.
- **Changelog:** **fixed 2026-09-03.** Historical entries (1.4.1 down to 1.0.0) now live in a
  separate `changelog.txt` at the repo root (not excluded by `build.sh`'s rsync rules, so it ships
  in the release zip); `readme.txt`'s own `== Changelog ==` section keeps only the current release
  (1.5.1) and the immediately previous one (1.5.0), with a pointer to `changelog.txt` for older
  entries, matching the handbook's explicit guidance.
- **`Plugin URI` — confirmed collision, fixed.** Both editions previously declared the identical
  `Plugin URI: https://ozulabs.com` — confirmed by direct comparison against
  `wp_search/turbo-search-for-woocommerce.php`'s header, the exact duplicate-URI problem the
  handbook calls out ("do not use the same URI for your free and pro plugin. It ends badly.").
  Fixed: Free now points at `https://ozulabs.com/plugins/turbo-search/` (verified live and
  resolving 200 before use — `curl -sL -o /dev/null -w '%{http_code} %{url_effective}'
  https://ozulabs.com/turbo-search/` redirects to it), distinct from Pro's bare
  `https://ozulabs.com`.
- **Installation section:** present, four short steps — reasonable given there's real setup
  guidance beyond "just activate it" (specifically: go rebuild the index).

---

## §18 Plugin assets

**Not verified against the live WordPress.org listing** — this repo does have repo-local
banner/icon/screenshot files at the top level (`banner-772x250.png`, `banner-1544x500.png`,
`icon-128x128.png`, `icon-256x256.png`, plus several product screenshots:
`home.png`, `search_kes.png`, `search_usd.png`, `search_usd_fixed.png`, `shop.png`,
`shop_full.png`, `naruki-dual-dropdown.png`). These match the handbook's required banner/icon
dimensions by filename convention (`banner-772x250`, `banner-1544x500`, `icon-128x128`,
`icon-256x256`), but:
- They live at the git repo's top level, not in a `screenshot-N.png`-named, SVN-`assets/`-ready
  layout — the screenshot files in particular don't follow the required `screenshot-1.png`,
  `screenshot-2.png`, ... naming convention the live SVN `assets/` directory needs.
- Per the audit scope: these are not blocking findings (asset sync happens at actual SVN-submission
  time, in a directory this git repo doesn't control), but flagging explicitly that **none of this
  was checked against what, if anything, is already live in a WordPress.org SVN `assets/`
  directory** — no live-listing access was available this session. If this edition is already
  listed, confirm the live assets match current branding before the next release; if not yet
  submitted, the screenshots will need renaming to the `screenshot-N` convention and the readme's
  `== Screenshots ==` section (currently 3 numbered entries, `readme.txt:74-78`) will need to map
  correctly to whichever three of these repo-local images are chosen.

---

## §19 Prefixing

The original audit found no PHPCS configuration, so its first prefixing verdict rested entirely
on the manual sweep below. That process gap was fixed later on 2026-09-01: `phpcs.xml` now enables
WordPress-Core, PHPCompatibilityWP, the plugin text domain, and the established `wcs`/`WCS`
prefixes; `composer lint` is the automated gate and currently passes.

**Manual grep sweep performed 2026-09-01** (commands run against `includes/`, the main plugin
file, `uninstall.php`, and the MU plugin):

- `get_option`/`update_option`/`add_option`/`delete_option` literal string arguments: every hit is
  `wcs_`-prefixed, **except** three reads of *other plugins'* options (`woo_multi_currency_params`,
  `woocs_currencies`, `wcml_exchange_rates` in the MU plugin, lines 119/124/129) — these are
  correctly un-prefixed because they belong to CURCY/WOOCS/WPML respectively, not to this plugin;
  reading a third-party option is not subject to the plugin's own prefix rule.
- `set_transient`/`get_transient`/`delete_transient`: every literal/variable key traced back to a
  `wcs_`-prefixed constant or a `'wcs_' . ...` string build (`wcs_promo_cache`, `wcs_v{version}_...`
  cache keys, `wcs_rl_*`/`wcs_nr_*` rate-limiter keys, `wcs_batch_retry_*`).
- `wp_ajax_*` action names: `wcs_dismiss_notice`, `wcs_rebuild_index`, `wcs_get_index_status`,
  `wcs_delete_all_data`, `wcs_refresh_nonce` — all prefixed.
- `register_setting()` option names: all eight are `wcs_`-prefixed; both setting *groups*
  (`wcs_settings_group`, `wcs_data_settings_group`) are prefixed too.
- `register_rest_route()` namespace: `wcs/v1` — prefixed.
- `add_shortcode()` tag: `turbo_search` — see the note in §12 on why this is acceptable despite not
  literally starting with `wcs_`.
- Script/style handles: `wcs-admin-css`, `wcs-admin-js`, `wcs-search-css`, `wcs-search-js` — all
  prefixed.
- User meta keys: all `wcs_notice_*_dismissed` — prefixed.
- Class namespace: `WCS\Search\*` — a real PHP namespace, not the global namespace, which the
  handbook treats as an even stronger mitigation than a flat function-name prefix.
- Global constants: `WCS_VERSION`, `WCS_PLUGIN_DIR`, `WCS_PLUGIN_URL`, `WCS_PLUGIN_BASENAME` — all
  prefixed, none using the forbidden `WP_`/`__`/bare-`_` patterns.

**Zero un-prefixed findings.** PHPCS now backs the declaration-level portion of this result;
the manual string-literal sweep must still be repeated because WPCS does not validate every
option, transient, meta-key, nonce-action, and handle string.

---

## §20 Submission and maintenance process notes

No submission has been made from this repo's audit history that I could find evidence of in this
session (no review-thread reference, no `Get Plugin Status` check performed — the WordPress.org MCP
server, §22, was not available/authorized here). If this edition has never been submitted, the
slug-permanence warning in the shared handbook (§20) applies at first submission, not before —
confirm `turbo-search-for-woocommerce` is the intended permanent slug before that first submission.
The `Plugin URI` Free/Pro collision raised in §17 has since been confirmed and fixed (see §17).

---

## §21–23

No repo-specific findings beyond the shared handbook. Worth setting up the WordPress.org MCP server
(§22) before the next submission attempt specifically because two findings in this document
(readme Tags uniqueness in §17, and the `write_file`/`prefixing` checks in §16) would be resolved
with certainty by an actual tool run rather than left as "likely fine, not independently verified."

---

## Verified compliance snapshot (2026-09-01)

**This is a first-pass self-audit against the shared handbook, performed by reading the actual
source in full** — every file under `includes/`, `includes/views/`, `assets/js/`, `mu-plugin/`,
the main plugin file, `uninstall.php`, `readme.txt`, `composer.json`, `build.sh`,
`VERSION_MANAGEMENT.md`, and `AGENT_CODING_STANDARDS.md` was read directly, plus targeted greps for
hook names, option/transient literals, filesystem calls, and HTTP calls, as recorded inline above
with file:line citations. **It has not been confirmed by an actual WordPress.org review**, and no
live WordPress.org data (the actual .org listing, live tag-directory contents, or a real Plugin
Check tool run) was available in this session — every place above where that matters is called out
explicitly rather than assumed clean.

**Open items before a submission or resubmission, in priority order:**
1. ~~`write_file` (§16)~~ — **Fixed 2026-09-01.** `class-activator.php`/`uninstall.php` now use
   `WP_Filesystem`'s "direct" adapter instead of raw `copy()`/`unlink()`, only ever initialized when
   it can operate without prompting for FTP/SSH credentials.
2. ~~Prefixing/PHPCS gap (§19)~~ — **Fixed 2026-09-01.** `phpcs.xml` added (WordPress-Core +
   PHPCompatibilityWP, with a documented exception for the established `wcs`/`WCS` prefix being
   under WPCS's 4-char minimum). `composer lint` runs clean. `composer.json` gained `lint`/
   `lint-fix` scripts.
3. **Run the real Plugin Check tool and the WordPress.org MCP server's Validate Readme tool
   (§16, §17, §22)** before submission — still not done; neither is available without a live
   WordPress install or live WordPress.org account access, neither of which this environment has.
   Several items in this document are marked "likely clean, not independently verified"
   specifically because only a real tool run (not a manual source read) can close them out
   completely (asset/tag-directory checks, exhaustive file-type/compat scans).
4. ~~Screenshots aren't in submission-ready form (§18)~~ — **Fixed 2026-09-01.** The original
   `readme.txt` described 2 screenshots (settings page, docs tab) that had no matching source image
   anywhere in the repo — all 7 candidate PNGs at the repo's top level are storefront live-search
   demos, none of them admin screens. Rather than leave a readme claiming screenshots that don't
   exist, `readme.txt`'s `== Screenshots ==` section now describes only what's real: two live-search
   dropdown demos on real stores (KES and USD), copied to `screenshot-1.png`/`screenshot-2.png` at
   the repo's top level (the naming convention the live SVN `assets/` directory needs — still needs
   moving into that directory at actual SVN-submission time, this git repo doesn't control it).
   Admin-screen screenshots (settings page, docs tab) don't exist and weren't fabricated; add real
   ones from an actual install if desired before submission — not blocking.
5. Minor/non-blocking: ~~the orphaned promo-dismissal usermeta on uninstall (§8)~~ **fixed
   2026-09-01**; the options-not-grouped-into-one-array divergence from best practice (§9) and
   splitting the changelog into a separate `changelog.txt` proactively (§17) before it becomes a
   repeat-fix like OzuPay's has are both still open, deliberately deferred (architecture/cosmetic
   calls, not bugs — readme.txt is currently a healthy 6,025 bytes, well under the 10KB threshold).
6. ~~Release ZIP contained development-only files~~ — **Fixed 2026-09-01.** A direct package
   inspection found `phpcs.xml`, `hooks/post-commit`, `.kiro/`, and `releasenotes/` in
   `dist/turbo-search-for-woocommerce-1.1.2.zip`. `build.sh` now excludes those paths and asserts
   that no development/release-process path reached the staging tree before zipping. The 1.1.2
   artifact was rebuilt in place and its runtime files were verified against the source tree.
7. ~~Remote promo service was disclosed only in an old changelog entry~~ — **Fixed 2026-09-01.**
   `readme.txt` now includes a dedicated `External services` section describing the request trigger,
   endpoint, static parameter, cache lifetime, data not sent, provider, and privacy-policy URL.

**Also fixed since this snapshot (2026-09-01), beyond what this document originally flagged:**
real bugs found by a full code-quality/security review — a leftover Pro-only vocabulary-sidecar
code path that threw real SQL errors on every full rebuild (`class-indexer.php`), the public search
endpoint indexing/returning products a merchant had explicitly hidden or password-protected
(no policy citation in the original handbook sweep, but a real data-exposure bug), the MU
cache-bypass fast path never being rate-limited, and that same fast path computing a different
cache key than this edition's own `Search_Handler` for multi-currency-switcher shoppers. See this
repo's git history (commits `386c6c3`..`ba3fc67`) for the full list.

**This snapshot goes stale the moment new code ships.** Per the same standing rule OzuPay's own
compliance doc states: code changes, but this audit does not automatically follow them. Re-run this
audit — re-read the changed files, re-run the grep sweeps, and re-check anything the code change
touches — before the next WordPress.org submission of this edition, rather than trusting this
2026-09-01 snapshot to still hold.

**Remediation pass, 2026-09-03** (per `WORDPRESS_ORG_REVIEW_1.5.1.txt`, which superseded this
document's earlier acceptance of the product cap — see §14.5 above): removed
`Indexer::FREE_PRODUCT_CAP` and every quota-reached flag/admin-notice/readme claim built on it —
this edition indexes its whole published catalog with no cap now, with regression tests added
(`IndexerBulkTest.php`, `InitAndBatchLifecycleTest.php`); fixed the 36-error PHPCS gate to zero
(35 auto-fixed array-formatting errors in `class-query-normalizer.php`, one hand-fixed unprepared
SQL identifier in `class-indexer.php`'s rate-limit GC using `%i`); split `readme.txt`'s changelog
into `changelog.txt`, bringing `readme.txt` from 13,868 back down to 6,082 bytes; consolidated the
Settings tab's several separate Pro upsells (the partial-catalog warning, the top-of-page card, and
four disabled placeholder controls — Search Merchandising, Quick Add to Cart, Search Synonyms,
Ranking Weights) plus the App Data tab's disabled Export card into one compact comparison card
rendered after the real settings form, linking to `https://ozulabs.com/plugins/turbo-search/`
instead of the bare homepage; and hardened the remote promo's `link_url` per §7 above. `composer
lint` and the full PHPUnit suite (233 tests) both pass clean as of this remediation. Not yet done:
an actual WordPress.org Plugin Check tool run and Readme Validator pass against the rebuilt zip
(§16, §17, §22) — still open from the 2026-09-01 snapshot, and this remediation makes that run more
urgent to actually perform rather than defer again.

**Second remediation pass, 2026-09-03** (per `WORDPRESS_ORG_DEEP_REVIEW_1.6.0.txt`, a deeper review
run against the 1.6.0 zip produced by the first pass above): that review found the 1.6.0 ZIP itself
had regressed on package hygiene (it contained the internal `WORDPRESS_ORG_REVIEW_1.5.1.txt` report,
which explicitly states "NOT READY FOR SUBMISSION" — a real risk if a human reviewer read it as the
developer's own current admission) and found two real MU cache-bypass bugs:

- **Package hygiene**: `build.sh` and `hooks/post-commit` now exclude `/WORDPRESS_ORG_*.txt` and,
  as defense in depth, assert a positive allowlist — the only root-level `.txt` files a build may
  stage are `readme.txt` and `changelog.txt`, anything else aborts the build. Verified: rebuilding
  the zip after this fix no longer contains either review report; `unzip -Z1` confirmed clean.
- **MU fast path rate-limit drift** (`mu-plugin/wcs-cache-bypass.php`): previously hardcoded
  60 requests/minute regardless of the `wcs_rate_limit_requests`/`wcs_rate_limit_window` options an
  administrator configures — cached and uncached search requests were governed by two different
  effective limits. Both paths now read `Rate_Limiter::resolved_search_limit()`, a single shared
  method, so they can't drift apart again. See `RateLimiterTest.php`'s `resolved_search_limit()`
  tests and `CacheKeyParityTest.php`'s non-default-setting tests (deliberately never exercising the
  actual rejection branch through the MU intercept, since that calls `exit` directly and would kill
  the PHPUnit process rather than fail a clean assertion — `Rate_Limiter::allow()`'s own
  reject-at-the-boundary behavior is covered by `RateLimiterTest.php` in isolation instead).
- **MU fast path could execute an inactive edition**: previously selected whichever of
  `turbo-search-for-woocommerce(-pro)` had a directory on disk, preferring Pro whenever both existed
  — regardless of which one WordPress actually had active. Replaced with
  `wcs_mu_resolve_active_edition()`, which resolves strictly from WordPress's own active-plugin state
  (site-level `active_plugins` and, on multisite, network-level `active_sitewide_plugins`) and
  returns null (skip the fast path entirely) whenever exactly one edition can't be resolved as
  active. See `MuEditionResolutionTest.php`.
- **`ajax_delete_all_data()` global cache flush**: replaced `wp_cache_flush()` (which cleared every
  active plugin's cached objects, not just this plugin's) with no call at all — the options this
  handler already deletes go through `delete_option()`, which invalidates its own cache entry
  correctly, and the one residual risk case (a stale `wcs_db_version` value) is independently guarded
  in `Activator::init()`'s own `delete_option()`+`add_option()` pattern. See
  `test_delete_all_data_never_flushes_the_global_object_cache` in `AdminSettingsTest.php`.
- **Multisite lifecycle capped at 1,000 sites**: `Activator::each_network_site()` now paginates via
  `get_sites()` in bounded pages instead of a single `LIMIT 1000` query, restoring blog context in a
  `finally` block so one site's failure never leaves the loop stuck in that site's context. Used by
  `activate()`, `deactivate()`, and `uninstall.php`. See the `each_network_site` tests in
  `ActivatorTest.php`.
- **Stale translation template**: regenerated `languages/turbo-search-for-woocommerce.pot` via
  `wp i18n make-pot` — now correctly versioned at 1.6.0, includes every current string (Rate
  Limiting, Recent Searches, the consolidated Pro card's copy), and points `Report-Msgid-Bugs-To` at
  the real slug instead of the old `wp_search` one. `build.sh` now regenerates it on every release
  automatically (skips gracefully if `wp-cli` isn't installed on the machine running the build).

**Left open, deliberately**: `WC tested up to: 9.4` is stale (WooCommerce is at 11.1.0 as of this
review), but the review's own instruction is not to bump this blindly — it requires actually testing
against the current WooCommerce release first, which needs a live WordPress/WooCommerce install this
environment doesn't have. Bump it only after that testing actually happens.

All fixes verified: `composer lint` (0 findings), `./vendor/bin/phpunit` (253 tests), `composer
coverage` (90.40%, threshold 85%), `composer audit` (no advisories), a full `php -l` sweep (clean),
and a rebuilt zip whose `readme.txt`/`changelog.txt`/main plugin file hashes match source exactly
and whose contents no longer include either internal review report.
