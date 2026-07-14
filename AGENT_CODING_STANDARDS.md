# WordPress Plugin AI Steering & Coding Standards

This document serves as the primary steering file for any AI agent or developer working on this WordPress project. It merges official WordPress Coding Standards (WPCS), modern PHP 8+ best practices, Plugin Check standards, and PCI-DSS compliance requirements for e-commerce.

**AI Instruction:** When generating, modifying, or reviewing code in this repository, you MUST adhere to these rules strictly.

---

## 1. Core Architecture & Naming Conventions

### 1.1 Object-Oriented Design & Namespacing
*   **Use Namespaces:** All new classes, interfaces, and traits MUST be namespaced to avoid global namespace pollution. Use a consistent vendor prefix (e.g., `namespace WCS\Search;`).
*   **Prefixing:** Any global functions, variables, or options MUST be prefixed uniquely (e.g., `wcs_`, `wcs_search_`).
*   **One Class Per File:** Each file should contain exactly one class, interface, or trait.
*   **File Naming:**
    *   Class files: `class-class-name.php` (lowercase, hyphens).
    *   Trait files: `trait-trait-name.php`.
    *   Interface files: `interface-interface-name.php`.
    *   Template/View files: descriptive lowercase with hyphens (e.g., `search-results.php`).

### 1.2 Direct File Access Prevention
Every single PHP file executing logic or defining structures must start with an ABSPATH check to prevent direct URL access:
```php
<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}
```

---

## 2. PHP 8+ & Modern Practices

### 2.1 Strict Typing
*   Enable strict types at the top of every PHP file (immediately after the ABSPATH check):
    ```php
    declare(strict_types=1);
    ```
*   Use explicit type hints for all function arguments and return types. Use modern PHP union types (`string|null`), nullable types (`?string`), and `mixed` where appropriate.

### 2.2 Modern Syntax
*   **Constructor Property Promotion:** Use it to reduce boilerplate in classes.
*   **Nullsafe Operator (`?->`):** Use for cleaner null checking.
*   **Match Expressions:** Prefer `match` over `switch` for assignment operations.
*   **Closures/Arrow Functions:** Use arrow functions (`fn() =>`) for concise anonymous functions when appropriate.

### 2.3 Deprecated Practices to Avoid
*   **No Shorthand Tags:** Never use `<?` or `<?=`. Always use `<?php`.
*   **No `extract()`:** Never use the `extract()` function.
*   **No `eval()`:** Never execute arbitrary code.
*   **No Error Suppression:** Avoid the `@` operator. Handle errors explicitly.

---

## 3. WordPress Coding Standards (WPCS) Formatting

### 3.1 Indentation & Spacing
*   **Indentation:** Use **real tabs** for indentation, not spaces. (Exception: use spaces for mid-line alignment if it improves readability).
*   **Spaces Inside Parentheses:** Always include spaces inside parentheses for function calls, control structures, and arrays.
    *   *Correct:* `if ( $foo && ( $bar || $baz ) ) { ... }`
    *   *Incorrect:* `if($foo && ($bar || $baz)){ ... }`
*   **Spaces Around Operators:** Put spaces on both sides of logical, arithmetic, comparison, string concatenation (`.`), and assignment operators.
*   **Array Formatting:** For multiline arrays, each item must start on a new line, and a trailing comma is required after the last element.

### 3.2 Control Structures
*   **Yoda Conditions:** Always put the constant or literal on the left side of logical comparisons.
    *   *Correct:* `if ( true === $is_active )`
    *   *Incorrect:* `if ( $is_active === true )`
*   **Use `elseif`:** Use one word `elseif`, never `else if`.

### 3.3 Quotes & Type Casting
*   **Quotes:** Use single quotes `'` by default. Use double quotes `"` only when evaluating variables inside the string.
*   **Type Casting:** Use short, lowercase forms: `(int)`, `(bool)`, `(float)`. Never `(integer)` or `(boolean)`.

### 3.4 Comparisons
*   **Strict Comparisons:** Always use strict comparison operators (`===`, `!==`) instead of loose ones (`==`, `!=`) to prevent type coercion bugs.

---

## 4. Security & Data Integrity

### 4.1 Data Validation & Sanitization (Input)
*   **Never trust user input.** Sanitize everything immediately upon receipt (`$_GET`, `$_POST`, `$_REQUEST`, `$_COOKIE`).
*   Use appropriate sanitization functions: `sanitize_text_field()`, `absint()`, `sanitize_email()`, `wp_kses_post()`.

### 4.2 Escaping (Output)
*   **Late Escaping:** Escape data as close to the point of output as possible.
*   Use context-appropriate escaping:
    *   `esc_html()` for standard HTML text.
    *   `esc_attr()` for HTML attributes.
    *   `esc_url()` for URLs.
    *   `esc_js()` for inline JavaScript.
    *   `esc_textarea()` for textarea content.
    *   `wp_kses()` for outputting HTML that requires specific allowed tags.

### 4.3 Database Security
*   **Prepared Statements:** ANY direct database query MUST use `$wpdb->prepare()`.
    ```php
    // Correct
    $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}table WHERE id = %d", $id ) );
    ```
*   **Prefixing Tables:** Always use `$wpdb->prefix` when referencing custom tables, especially crucial for Multisite compatibility.
*   **Schema:** Always explicitly define `ENGINE=InnoDB` and use `$wpdb->get_charset_collate()` when creating custom tables with `dbDelta()`.

### 4.4 Authorization & Authentication
*   **Capabilities Check:** Use `current_user_can()` before executing any sensitive logic. `is_admin()` only checks if the user is in the admin panel, NOT if they are authorized.
*   **Nonces:** Protect against CSRF attacks. Require and verify a nonce via `wp_verify_nonce()` for all form submissions, AJAX requests, and REST API mutations.

---

## 5. Performance & Resource Management

### 5.1 Caching
*   **Object Cache:** Utilize WordPress transients or object cache (`wp_cache_set()`, `wp_cache_get()`) for expensive database queries.
*   **Bifurcation:** Be aware of environments using external object caches (e.g., Redis/Memcached) via `wp_using_ext_object_cache()`. Transients in these environments bypass the `wp_options` table.

### 5.2 Background Processing
*   **Action Scheduler:** Use Action Scheduler for heavy, long-running, or bulk tasks (like indexing). Do NOT use synchronous execution on hooks that could block user requests or third-party webhooks (e.g., M-Pesa/Stripe callbacks).

### 5.3 Query Optimization
*   Avoid `SELECT *` in direct DB queries. Select only required columns.
*   Limit results where possible.

---

## 6. PCI-DSS Compliance Considerations (For E-commerce)
If the plugin handles checkout pages, payment data, or interacts with WooCommerce directly:
*   **No Local Storage of PAN:** Never store Primary Account Numbers (credit card numbers) or sensitive authentication data.
*   **Script Auditing:** Ensure any injected JavaScript (analytics, UI) does not inappropriately read input fields on checkout pages to prevent Magecart/e-skimming vulnerabilities.
*   **Activity Logging:** Use `error_log()` or a dedicated logging system (like `WC_Logger`) for critical state changes, but ensure no PII (Personally Identifiable Information) or payment data is logged.

---

## 7. Plugin Check (PCP) Requirements
The WordPress.org **Plugin Check (PCP)** tool enforces automated directory standards. All code must pass PCP without any `error` level flags.
*   **No Unauthorized External Requests:** Do not ping third-party servers unless explicitly user-initiated or documented.
*   **No Deprecated Functions:** Ensure all WP functions are current (e.g., use `wp_add_inline_script` instead of directly printing to `<script>`).
*   **No File Modifications/Execution:** Avoid `eval()`, `base64_decode()` (if used for execution), or writing outside `wp-content/uploads`.
*   **Valid Headers:** The main plugin file must have proper, valid Plugin URI, Author, License (GPLv2 or later), and Text Domain headers.
*   **No "Call Home" Telemetry:** Usage tracking MUST be opt-in.
*   **Proper Asset Enqueueing:** Never hardcode `<script>` or `<link>` tags in hooks. Always use `wp_enqueue_script` and `wp_enqueue_style`.

### 7.1 Internationalization (i18n)
*   **Text Domains:** All user-facing strings must be fully translatable using functions like `__()`, `esc_html__()`, `_e()`, etc.
*   **No Variables in Translation Strings:** Translation strings must be static literals. Do not pass variables directly to translation functions (e.g., `__( $my_var, 'domain' )` is invalid). Use `sprintf()` instead.
*   **Match Plugin Slug:** The text domain must exactly match the plugin slug defined in the main file header.

### 7.2 Lifecycle Hooks (Activation/Uninstall)
*   **Clean Uninstallation:** Always provide an `uninstall.php` file or use `register_uninstall_hook()` to clean up database tables, transients, and options when the plugin is deleted. Leaving orphaned data is flagged by PCP.

---

## 8. Release & Version Management

This project strictly adheres to Semantic Versioning (SemVer) when cutting releases.

### 8.1 When to Bump Versions
A version bump must occur **only when cutting a new `.zip` for deployment**. Intermediate commits on feature branches do not require version bumps.
*   **Patch Release (x.y.Z):** Bumped for backwards-compatible bug fixes.
*   **Minor Release (x.Y.z):** Bumped for new, backwards-compatible functionality.
*   **Major Release (X.y.z):** Bumped for breaking architectural changes (e.g., database schema changes requiring a full reindex).

### 8.2 How to Bump Versions
When releasing, the version string MUST be updated in exactly two places in `wp-fast-search.php`:
1.  The Plugin Header (`Version: x.y.z`).
2.  The `WCS_VERSION` constant (`define( 'WCS_VERSION', 'x.y.z' );`) to ensure cache busting for CSS/JS.

---

## Summary Checklist for AI Agents
Before concluding a task or outputting a file, verify:
- [ ] Direct file access check (`ABSPATH`) is present.
- [ ] Strict types are declared (`declare(strict_types=1);`).
- [ ] No undeclared global variables are used.
- [ ] Yoda conditions and strict comparisons (`===`) are used.
- [ ] Prepared statements are used for ALL database queries.
- [ ] Inputs are sanitized; Outputs are escaped late.
- [ ] Version string is appropriately bumped if cutting a release.
- [ ] All strings are properly internationalized with a static text domain.
- [ ] Code is formatted with real tabs, correct spacing around operators/parens.
- [ ] Action Scheduler is used for potentially blocking tasks.
- [ ] Code strictly passes all WordPress.org Plugin Check (PCP) requirements (including `uninstall.php`).
