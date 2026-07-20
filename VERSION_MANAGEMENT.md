# Turbo Search for WooCommerce (Free) — Version Management Guide

This document is the standard operating procedure (SOP) for releasing new
versions of this plugin. Following it ensures WordPress correctly recognizes
updates, browser caches are busted for static assets, and Git history stays
clean and traceable to a specific shipped zip.

## 0. The standing rule: `./build.sh` is the only way to cut a release

**Never `git commit`/`git push` by hand for a change that will be deployed to
a live site or otherwise handed to a user as "ready" — always run
`./build.sh` instead.** `build.sh` is what bumps the version; a plain
`git commit` does not, and the `post-commit` git hook that mirrors zips into
the shared top-level `dist/` (see the combined `../VERSION_MANAGEMENT.md`)
explicitly does **not** bump anything either — it only packages whatever
version is *currently* in the plugin header.

This matters even for changes you consider "just a fix" or "still testing":
if a zip from this repo is going to be installed anywhere outside your own
local checkout, it needs a version bump, or:
- WordPress can't tell two different zips apart in the Plugins list.
- The `?ver=` query string on `search.css`/`search.js` (see
  `includes/class-frontend.php`) doesn't change, so a browser or CDN that
  already cached the old file under that same `?ver=` keeps serving stale
  JS/CSS after you deploy new code that fixes a bug in it — silently
  reintroducing the very bug the deploy was meant to fix.

If you're mid-investigation and only testing against a disposable copy of a
file (not the live plugin directory), a bump isn't needed for that — but the
moment a change is deployed to a real, running install, treat it as a
release and run `./build.sh`.

## 1. When to Bump the Version (SemVer)

This project strictly adheres to Semantic Versioning.
*   **Patch (`1.0.x`):** backwards-compatible bug fixes, CSS/behavior
    corrections, no new capability. `./build.sh "message"` (patch is the
    default when no bump type is given).
*   **Minor (`1.x.0`):** new, backwards-compatible functionality.
    `./build.sh "message" minor`.
*   **Major (`x.0.0`):** breaking changes — a database schema migration that
    isn't auto-handled, a removed shortcode/option, anything that could
    break an existing site on upgrade. `./build.sh "message" major`.

When a single release bundles several fixes plus one new feature, the
feature decides the bump — pick minor, don't undercount it as a patch.

Note `../PORTING.md` for how feature parity with the Pro edition
(`wp_search/`) is tracked — Free and Pro version numbers are independent of
each other; only bump this repo's version when this repo's code changed.

## 2. The Two Version Strings

`./build.sh` updates both of these for you — you should never need to hand-edit
either, but it's useful to know what it's touching:

### A. The Plugin Header
WordPress core reads this to show the version in **Plugins > Installed Plugins**,
and it's also what a wordpress.org listing (if this edition is published
there) uses to detect available updates.

```php
/*
 * Plugin Name:          Turbo Search for WooCommerce
 * Version:              1.0.6  <-- kept in sync by build.sh
 */
```

### B. The `WCS_VERSION` Constant
Defined in `turbo-search-for-woocommerce.php`, consumed by
`includes/class-frontend.php` to append `?ver=1.0.6` to every enqueued
plugin asset — this is the cache-busting mechanism referenced in section 0.

```php
define( 'WCS_VERSION', '1.0.6' ); // <-- kept in sync by build.sh
```

`readme.txt`'s `Stable tag:` is also kept in sync by the script.

## 3. Database Migrations (When Applicable)

If a change alters the schema of the custom MySQL tables (`wcs_search_index`,
`wcs_search_terms`, etc.), you must manage the upgrade natively — WordPress
does **not** automatically re-run activation hooks on plugin *updates*:
1. Bump the DB version tracked in `includes/class-activator.php` and add the
   corresponding upgrade path.
2. This is exactly the kind of change that calls for at least a minor bump,
   often major if it isn't backwards-compatible with older stored data.

## 4. The Build & Release Process

```bash
./build.sh "Describe the change"            # patch bump (default)
./build.sh "Describe the change" minor      # new feature
./build.sh "Describe the change" major      # breaking change
```

This single command:
1. Bumps the version (per the bump type) in the plugin header, the
   `WCS_VERSION` constant, and `readme.txt`.
2. Runs the full PHPUnit suite (with the coverage ratchet if a coverage
   driver is installed) — aborts before committing anything if it fails.
3. Commits everything with your message.
4. Zips the plugin into `dist/turbo-search-for-woocommerce-X.Y.Z.zip`,
   archiving the previous zip under `dist/archive/`.

Per the project's standing "always push" rule, push the commit right after:

```bash
git push origin main
```

Then tag the release (optional but recommended for anything actually
shipped):

```bash
git tag vX.Y.Z
git push origin vX.Y.Z
```

### Distribution
Upload `dist/turbo-search-for-woocommerce-X.Y.Z.zip` directly to the target
site's WordPress dashboard (or use the `wp plugin install --force` workflow
in `../DEPLOYMENT.md`), attach it to a GitHub Release matching the tag, or
submit it through the wordpress.org SVN process if this edition is listed
there.
