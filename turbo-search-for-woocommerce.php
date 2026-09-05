<?php
declare(strict_types=1);

/**
 * Plugin Name:          Turbo Search for WooCommerce
 * Plugin URI:           https://ozulabs.com/plugins/turbo-search/
 * Description:          A high-performance, zero-dependency WooCommerce search engine using native FULLTEXT indexing.
 * Version:              1.9.0
 * Author:               Ozulabs
 * Author URI:           https://ozulabs.com
 * License:              GPLv2 or later
 * Text Domain:          turbo-search-for-woocommerce
 * Domain Path:          /languages
 * Requires at least:    6.5
 * Tested up to:         7.1
 * Requires PHP:         8.0
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.0
 * WC tested up to:      10.8
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// ── Free/Pro mutual-exclusion guard ────────────────────────────────────────
// Both editions declare identical global functions, constants, and the
// WCS\Search class namespace (Pro is a superset of this edition's core). If
// both plugin files load in the same request, PHP fatals with "Cannot
// redeclare wcs_search_init()" — and NOT because of some runtime race: an
// ordinary top-level `function wcs_search_init(){}` (not nested inside an
// `if`) is bound by PHP at *compile* time, before any of this file's own
// runtime code executes. An early `if (...) { return; }` guard placed above
// such a declaration — what this file used to do — can never prevent that
// later declaration from still being bound and fataling; only wrapping the
// declaration itself in `function_exists()` makes it a *conditional*
// declaration, which PHP does not compile-time-bind. See both function
// declarations below.
//
// Deactivating this edition (Pro is the superset, so Free always yields) is
// handled separately, deferred to 'shutdown': if this file is loaded via WP
// core's activate_plugin(), that function already read 'active_plugins'
// into a local variable *before* this include ran, and overwrites the
// option with that stale copy right after the include finishes — silently
// undoing a deactivate_plugins() call made synchronously here. 'shutdown'
// fires after every option write for the request, including core's, so the
// deferred callback re-reads a fully current value before acting. This uses
// only WP core functions and a literal basename — never the WCS_*
// constants defined below, since those are exactly the ones Pro may have
// already declared this request (redefining an existing constant is a
// silent no-op, not a fatal).
//
// This top-level guard alone left admins with a silent failure, confirmed
// live: activating Free while Pro is active reports "Plugin ... activated."
// (even from `wp plugin activate`) and the plugin then simply shows
// "Inactive" again on the very next page load, with no explanation ever
// shown. The admin_notices callback registered below IS real, but it can
// never render on the activation request itself — WordPress redirects
// immediately after processing an activation, before any admin_notices
// action fires on that request — and by the following page load Free has
// already been deactivated (by the 'shutdown' callback below), so its file
// is not included again to register the notice a second time either.
// wcs_deny_activation_if_pro_active() below closes that gap for the
// activation moment specifically, using a real register_activation_hook()
// callback instead: those run via do_action( "activate_{$plugin}" ), which
// core's activate_plugin() fires strictly *after* it has already written
// 'active_plugins' — so deactivate_plugins() there is immediately correct
// with no stale-array race to defer around, and wp_die() reliably
// interrupts the redirect with a real, visible message. This top-level
// guard (and its 'shutdown'-deferred deactivation) still matters for the
// separate case that isn't an activation request at all — e.g. Pro gets
// activated later while Free is already running — where admin_notices
// fires normally on that ordinary page load.
if ( ! function_exists( 'is_plugin_active' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
$wcs_pro_edition_basename = 'turbo-search-for-woocommerce-pro/turbo-search-for-woocommerce.php';
if ( is_plugin_active( $wcs_pro_edition_basename ) ) {
	add_action( 'shutdown', static function () use ( $wcs_pro_edition_basename ): void {
		if ( is_plugin_active( $wcs_pro_edition_basename ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
		}
	}, 0 );
	add_action( 'admin_notices', static function (): void {
		?>
		<div class="notice notice-warning is-dismissible">
			<p>
				<?php esc_html_e( 'Turbo Search for WooCommerce: the free edition cannot run alongside Pro and has been deactivated automatically.', 'turbo-search-for-woocommerce' ); ?>
			</p>
		</div>
		<?php
	} );
}

/**
 * Activation-time half of the mutual-exclusion guard — see the comment
 * above for why the top-level guard's own admin_notices never actually
 * renders during Free's own activation request. function_exists()-wrapped
 * for the same compile-time-binding reason as wcs_search_init() below.
 */
if ( ! function_exists( 'wcs_deny_activation_if_pro_active' ) ) {
	function wcs_deny_activation_if_pro_active(): void {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( ! is_plugin_active( 'turbo-search-for-woocommerce-pro/turbo-search-for-woocommerce.php' ) ) {
			return;
		}
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die(
			esc_html__( 'Turbo Search for WooCommerce (Free) was not activated because Turbo Search for WooCommerce Pro is already active on this site — Pro already includes everything Free does. Deactivate Pro first if you need to switch to Free.', 'turbo-search-for-woocommerce' ),
			esc_html__( 'Plugin activation blocked', 'turbo-search-for-woocommerce' ),
			array( 'back_link' => true )
		);
	}
}

// Define core constants.
define( 'WCS_VERSION', '1.9.0' );
define( 'WCS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WCS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader for WCS classes.
 * Maps WCS\Search\ClassName to includes/class-class-name.php
 */
spl_autoload_register( function ( string $class_name ): void {
	$prefix   = 'WCS\\Search\\';
	$base_dir = WCS_PLUGIN_DIR . 'includes/';

	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class_name, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class_name, $len );
	$file_name      = 'class-' . strtolower( str_replace( '_', '-', $relative_class ) ) . '.php';
	$file           = $base_dir . $file_name;

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

/**
 * Main plugin bootstrap function.
 *
 * Wrapped in function_exists() — not just this file's own early-return
 * guard above — because an unconditional top-level function declaration is
 * bound by PHP at compile time, before any runtime code (including that
 * guard) executes. See the mutual-exclusion comment above.
 */
if ( ! function_exists( 'wcs_search_init' ) ) {
	function wcs_search_init(): void {
		// Check WooCommerce dependency.
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', 'wcs_woocommerce_missing_notice' );
			return;
		}

		// Wait for the class to be available (it will be built in Phase 2)
		if ( class_exists( '\\WCS\\Search\\Activator' ) ) {
			\WCS\Search\Activator::init();
		}
		if ( class_exists( '\\WCS\\Search\\Indexer' ) ) {
			\WCS\Search\Indexer::init();
		}
		if ( class_exists( '\\WCS\\Search\\Search_Handler' ) ) {
			\WCS\Search\Search_Handler::init();
		}
		if ( class_exists( '\\WCS\\Search\\Frontend' ) ) {
			\WCS\Search\Frontend::init();
		}
		if ( class_exists( '\\WCS\\Search\\Admin_Settings' ) ) {
			\WCS\Search\Admin_Settings::init();
		}
	}
}
add_action( 'plugins_loaded', 'wcs_search_init' );

// This edition is distributed from wordpress.org, which auto-loads
// translations from the Domain Path header — no load_plugin_textdomain()
// call needed (and Plugin Check flags one as redundant/discouraged here).

// Declare WooCommerce HPOS compatibility (this plugin does not touch order tables).
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/**
 * Admin notice if WooCommerce is not active.
 *
 * function_exists()-wrapped for the same compile-time-binding reason as
 * wcs_search_init() above.
 */
if ( ! function_exists( 'wcs_woocommerce_missing_notice' ) ) {
	function wcs_woocommerce_missing_notice(): void {
		?>
	<div class="notice notice-error is-dismissible">
		<p><?php esc_html_e( 'Turbo Search for WooCommerce requires WooCommerce to be installed and active.', 'turbo-search-for-woocommerce' ); ?></p>
	</div>
		<?php
	}
}

// Register activation hooks. wcs_deny_activation_if_pro_active() is
// registered first so it runs first (do_action() calls same-priority
// callbacks in registration order) — if it wp_die()s, Activator::activate()
// never runs, so no tables/schedules get created for a blocked activation.
register_activation_hook( __FILE__, 'wcs_deny_activation_if_pro_active' );
register_activation_hook( __FILE__, array( '\\WCS\\Search\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\WCS\\Search\\Activator', 'deactivate' ) );
