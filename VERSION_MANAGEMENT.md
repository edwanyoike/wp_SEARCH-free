# WP Fast Search - Version Management Guide

This document outlines the standard operating procedure (SOP) for releasing new versions of the WP Fast Search plugin. Following these steps ensures that WordPress correctly recognizes updates, browser caches are busted for static assets, and Git history remains clean.

## 1. When to Bump the Version (SemVer)

This project strictly adheres to Semantic Versioning. A version bump must occur **only when cutting a new `.zip` for deployment**. Intermediate Git commits do not require a version bump.
*   **Patch Release (1.0.x):** Bumped for backwards-compatible bug fixes.
*   **Minor Release (1.x.0):** Bumped for new, backwards-compatible functionality.
*   **Major Release (x.0.0):** Bumped for breaking architectural changes (e.g., database schema migrations).

## 2. The Two Version Strings

Whenever you release a new version (e.g., moving from `1.0.0` to `1.0.1`), you **must** update the version number in exactly two places within the main plugin file: `wp-fast-search.php`.

### A. The Plugin Header
WordPress core reads the plugin header to display the version in the **Plugins > Installed Plugins** dashboard.

```php
/*
 * Plugin Name: WP Fast Search
 * Description: Zero-dependency, sub-10ms WooCommerce product search engine.
 * Version: 1.0.1  <-- Update this
 * Author: Your Name
 */
```

### B. The `WCS_VERSION` Constant
This constant is used in `includes/class-frontend.php` to append a version query string to `search.css` and `search.js` (`?ver=1.0.1`). Updating this guarantees that returning customers don't get stuck with stale CSS or JavaScript stuck in their browser cache.

```php
// Define core constants.
define( 'WCS_VERSION', '1.0.1' ); // <-- Update this
```

## 2. Database Migrations (When Applicable)

If your new version includes structural changes to the custom MySQL table (`wcs_search_index`), you must manage the database upgrade natively.

WordPress does not automatically run activation hooks on plugin *updates*. If you change the schema in `class-activator.php`:
1. Use the `upgrader_process_complete` hook, or check against a `wcs_db_version` stored in `wp_options` during `plugins_loaded`.
2. If the stored DB version is older than the current version, trigger `\WCS\Search\Activator::activate()` programmatically to run `dbDelta()` and apply the new schema without dropping data.

## 3. The Build & Release Process

Once you have bumped the version strings and finished your code changes, follow this three-step terminal workflow:

### Step 1: Run the Build Script
Use the automated build script to commit the changes and generate the distribution zip. Use a standard conventional commit message.

```bash
./build.sh "Bump version to 1.0.1"
```
*This will create a fresh `dist/wp-fast-search.zip` containing the new version.*

### Step 2: Create a Git Tag
Tagging your releases in Git is crucial for tracking history and rolling back if necessary.

```bash
git tag v1.0.1
git push origin v1.0.1
```

### Step 3: Distribution
Upload the new `dist/wp-fast-search.zip` directly to the client's WordPress dashboard, or attach the zip to a GitHub Release corresponding to the tag you just created.
