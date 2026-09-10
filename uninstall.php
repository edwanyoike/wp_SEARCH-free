<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package OzuLabs_Turbo_Search_For_WooCommerce
 */

declare(strict_types=1);

// If uninstall not called from WordPress, then exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}


global $wpdb;

// The plugin's autoloader is not registered during uninstall — load the
// Activator directly for its canonical option / transient-prefix lists.
require_once __DIR__ . '/includes/class-activator.php';

// Check if data deletion on uninstall is enabled — for the top-level
// single-site/network-wide dispatch below; each_network_site() re-checks
// each site's OWN stored value after switching into it (see
// otsw_uninstall_single_site()'s docblock), since this top-level read
// reflects only whichever site's context WordPress happened to run
// uninstall.php in, not a network-wide setting.
$otsw_delete_data = (bool) get_option( 'otsw_delete_data_on_uninstall', false );

/**
 * Clean up a single site's tables, options, transients, and background tasks.
 *
 * Re-checks otsw_delete_data_on_uninstall itself, scoped to whichever site
 * is currently active — each_network_site() calls this once per site,
 * after switch_to_blog(), so get_option() here reads THAT site's own
 * stored preference. Without this, the single top-level read at the top of
 * this file (necessarily just one site's value — the one WordPress
 * happened to run uninstall.php in) would apply uniformly to every site in
 * the network: a main site left opted out could skip a subsite that
 * explicitly opted in, or a main site opted in could delete a subsite's
 * data against that subsite's own explicit choice to keep it.
 *
 * Also refuses to run at all while Pro is active on this site: the search
 * index tables and several settings options below are shared with Pro (see
 * PORTING.md), not Free-exclusive state, so an admin's explicit opt-in to
 * "delete data on uninstall" is about removing FREE's own footprint when
 * Free is genuinely being removed — it was never consent to drop the
 * search index (and the rate-limit table, and every shared setting) out
 * from under a Pro install that migrated onto that exact same data. This
 * is the same principle the MU-file check below already applies to their
 * other shared resource, just for tables/options instead of a file.
 */
function otsw_uninstall_single_site(): void {
	global $wpdb;

	if ( ! (bool) get_option( 'otsw_delete_data_on_uninstall', false ) ) {
		return;
	}

	if ( \OTSW\Search\Activator::is_pro_edition_active() ) {
		return;
	}

	// 1. Drop the custom search index tables (main + staging) and the
	// rate-limit counters. Typo-correction vocabulary (otsw_search_terms*),
	// search-analytics (otsw_search_log), and its defunct predecessor
	// (otsw_zero_hits) are Pro-only/Pro-history tables this edition's
	// Activator never creates — see PORTING.md — so there is nothing to drop
	// for them here.
	$main_table  = $wpdb->prefix . 'otsw_search_index';
	$stage_table = $wpdb->prefix . 'otsw_search_index_stage';
	$rl_table    = $wpdb->prefix . 'otsw_rate_limits';
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $main_table ) );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $stage_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $rl_table ) );    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

	// 2. Delete the plugin's own options. Explicit list — a broad LIKE 'otsw_%'
	// would also delete WooCommerce Subscriptions' options (shared prefix).
	foreach ( \OTSW\Search\Activator::PLUGIN_OPTIONS as $option ) {
		delete_option( $option );
	}

	// 3. Clear the plugin's own transients by exact key shape — never
	// '_transient_otsw_%', which matches WC Subscriptions' otsw_report_* transients.
	foreach ( \OTSW\Search\Activator::TRANSIENT_PREFIXES as $prefix ) {
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_' . $prefix ) . '%',
			$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
		) );
	}

	// 4. Clear Action Scheduler jobs.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( null, array(), 'ozulabs-turbo-search-for-woocommerce' );
	}

	// 5. Clear the WP-Cron daily GC job.
	$timestamp = wp_next_scheduled( 'otsw_daily_transient_gc' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'otsw_daily_transient_gc' );
	}

	// 5b. Clear any pending dynamically-argumented WP-Cron retries (a
	// product-update/removal retry, or a rebuild-scheduling retry) — these
	// are keyed by product ID or rebuild epoch, not a fixed argument list,
	// so wp_next_scheduled()/wp_unschedule_event() alone can't target them;
	// see Activator::clear_dynamic_cron_hooks()'s docblock.
	\OTSW\Search\Activator::clear_dynamic_cron_hooks();
}

/**
 * Delete this plugin's own per-user notice-dismissal preferences.
 *
 * A separate function (rather than folded into otsw_uninstall_single_site())
 * because these meta keys are user-level state stored once per
 * install, not per-site table/option state — it must run at most once
 * regardless of how many sites each_network_site() walks on a Multisite
 * network, unlike the per-site cleanup above.
 *
 * otsw_notice_mu_bypass_dismissed and otsw_notice_no_cache_dismissed record a
 * user's own dismissal of notices about the shared MU cache-bypass file and
 * the shared no-persistent-object-cache warning — both concerns apply
 * identically to a still-active Pro install, not just to Free. Wiping them
 * out would silently reset Pro's own already-dismissed notices back to "not
 * dismissed" for every admin, purely because Free was the edition being
 * removed. Same principle already applied to otsw_uninstall_single_site()'s
 * table/option cleanup and to the MU file below: an opt-in to delete Free's
 * own data is not consent to touch state a retained Pro install still
 * relies on.
 *
 * Unlike otsw_uninstall_single_site()'s table/option cleanup (already
 * correctly per-site via switch_to_blog()'s effect on wpdb/get_option()),
 * WordPress's users/usermeta tables are network-wide on Multisite — a
 * single shared table, not one per site — so a same-site-only check here
 * would miss Pro running on a DIFFERENT site in the network. Uses
 * Activator::is_shared_network_resource_still_needed(), which scans the
 * whole network on Multisite and falls back to the same-site check
 * everywhere else — see its own docblock for why this differs from the
 * intentionally same-site-only check at deactivation time.
 */
function otsw_delete_notice_dismissals(): void {
	global $wpdb;

	if ( \OTSW\Search\Activator::is_shared_network_resource_still_needed() ) {
		return;
	}

	// 6. Delete all otsw_notice_*_dismissed user meta.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s)",
			'otsw_notice_mu_bypass_dismissed',
			'otsw_notice_no_cache_dismissed'
		)
	);
}

// Perform cleanup across all sites in Multisite or the current single site.
// Paginates via Activator::each_network_site() (get_sites() in bounded
// pages, restoring blog context even if a site's cleanup throws) so a
// network of any size is fully cleaned up, not just its first 1,000 sites.
//
// Multisite always dispatches to every site regardless of $otsw_delete_data —
// otsw_uninstall_single_site() checks each site's OWN preference itself
// after switch_to_blog() (see its docblock); gating the dispatch on this
// file's single top-level read would apply just one site's value network-
// wide. Single-site has only the one preference to check, so the top-level
// $otsw_delete_data (already that site's own value) gates it directly, same as
// before.
if ( is_multisite() ) {
	\OTSW\Search\Activator::each_network_site( 'otsw_uninstall_single_site' );
} elseif ( $otsw_delete_data ) {
	otsw_uninstall_single_site();
}

if ( true === $otsw_delete_data ) {
	otsw_delete_notice_dismissals();
}

// 5. Remove the MU plugin file — but not out from under a still-active Pro
// (or, on Multisite, Free) install anywhere in the network: mu-plugins/ is
// a single, network-wide directory (not per-site), and Free/Pro both
// install and use the exact same otsw-cache-bypass.php. Uninstalling Free
// after migrating to Pro is a supported, expected path. Unlike
// remove_mu_plugin()'s deactivation-time check (deliberately same-site-only
// — see its docblock), uninstall is a one-time, hard-to-reverse action, so
// the whole-network scan in is_shared_network_resource_still_needed() is
// justified here.
//
// Both filenames are checked: a site that never went through an active
// admin request under 1.11.10+ (migrate_legacy_wcs_prefix() runs on
// plugins_loaded, so this is only theoretical, but uninstall is one-time
// and hard to reverse) could still be carrying the pre-rename
// wcs-cache-bypass.php name.
if ( defined( 'WPMU_PLUGIN_DIR' ) && ! \OTSW\Search\Activator::is_shared_network_resource_still_needed() ) {
	foreach ( array( 'otsw-cache-bypass.php', 'wcs-cache-bypass.php' ) as $otsw_mu_filename ) {
		$otsw_mu_file = trailingslashit( WPMU_PLUGIN_DIR ) . $otsw_mu_filename;
		if ( ! file_exists( $otsw_mu_file ) && ! is_link( $otsw_mu_file ) ) {
			continue;
		}
		if ( ! function_exists( 'get_filesystem_method' ) || ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$otsw_mu_deleted = false;
		if ( 'direct' === get_filesystem_method( array(), dirname( $otsw_mu_file ) ) && WP_Filesystem() ) {
			global $wp_filesystem;
			$otsw_mu_deleted = $wp_filesystem && $wp_filesystem->delete( $otsw_mu_file );
		}
		if ( ! $otsw_mu_deleted ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Turbo Search for WooCommerce uninstall: could not remove MU plugin at ' . $otsw_mu_file );
		}
	}
}
