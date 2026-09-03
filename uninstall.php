<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * @package WP_Fast_Search
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

// Check if data deletion on uninstall is enabled.
$delete_data = (bool) get_option( 'wcs_delete_data_on_uninstall', false );

/**
 * Clean up a single site's tables, options, transients, and background tasks.
 */
function wcs_uninstall_single_site(): void {
	global $wpdb;

	// 1. Drop the custom search index tables (main + staging) and the
	// rate-limit counters. Typo-correction vocabulary (wcs_search_terms*),
	// search-analytics (wcs_search_log), and its defunct predecessor
	// (wcs_zero_hits) are Pro-only/Pro-history tables this edition's
	// Activator never creates — see PORTING.md — so there is nothing to drop
	// for them here.
	$main_table  = $wpdb->prefix . 'wcs_search_index';
	$stage_table = $wpdb->prefix . 'wcs_search_index_stage';
	$rl_table    = $wpdb->prefix . 'wcs_rate_limits';
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $main_table ) );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $stage_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $rl_table ) );    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

	// 2. Delete the plugin's own options. Explicit list — a broad LIKE 'wcs_%'
	// would also delete WooCommerce Subscriptions' options (shared prefix).
	foreach ( \WCS\Search\Activator::PLUGIN_OPTIONS as $option ) {
		delete_option( $option );
	}

	// 3. Clear the plugin's own transients by exact key shape — never
	// '_transient_wcs_%', which matches WC Subscriptions' wcs_report_* transients.
	foreach ( \WCS\Search\Activator::TRANSIENT_PREFIXES as $prefix ) {
		$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$wpdb->esc_like( '_transient_' . $prefix ) . '%',
			$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
		) );
	}

	// 4. Clear Action Scheduler jobs.
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		as_unschedule_all_actions( null, array(), 'turbo-search-for-woocommerce' );
	}

	// 5. Clear the WP-Cron daily GC job.
	$timestamp = wp_next_scheduled( 'wcs_daily_transient_gc' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'wcs_daily_transient_gc' );
	}
}

if ( true === $delete_data ) {
	// Perform cleanup across all sites in Multisite or the current single site.
	if ( is_multisite() ) {
		// LIMIT 1000: bounds uninstall time on huge networks (mirrors the cap
		// in Activator::activate()). Beyond-cap sites retain their two index
		// tables and options; harmless orphans that can be dropped manually.
		$site_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs} LIMIT 1000" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		foreach ( $site_ids as $site_id ) {
			switch_to_blog( (int) $site_id );
			wcs_uninstall_single_site();
			restore_current_blog();
		}
	} else {
		wcs_uninstall_single_site();
	}

	// 6. Delete all wcs_notice_*_dismissed user meta.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key IN (%s, %s)",
			'wcs_notice_mu_bypass_dismissed',
			'wcs_notice_no_cache_dismissed'
		)
	);
	// Promo banners use a server-driven, per-promo dismiss_id
	// (Admin_Settings::render_admin_notices()), so the exact key can't be
	// enumerated above — 'wcs_notice_promo_%_dismissed' is specific enough
	// not to risk the broad-prefix collision this file's own tests guard
	// against (see CleanupCoverageTest::test_no_broad_prefix_deletes...).
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
			$wpdb->esc_like( 'wcs_notice_promo_' ) . '%' . $wpdb->esc_like( '_dismissed' )
		)
	);
}

// 5. Remove the MU plugin file
if ( defined( 'WPMU_PLUGIN_DIR' ) ) {
	$mu_file = trailingslashit( WPMU_PLUGIN_DIR ) . 'wcs-cache-bypass.php';
	if ( file_exists( $mu_file ) || is_link( $mu_file ) ) {
		if ( ! function_exists( 'get_filesystem_method' ) || ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$mu_deleted = false;
		if ( 'direct' === get_filesystem_method( array(), dirname( $mu_file ) ) && WP_Filesystem() ) {
			global $wp_filesystem;
			$mu_deleted = $wp_filesystem && $wp_filesystem->delete( $mu_file );
		}
		if ( ! $mu_deleted ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Turbo Search for WooCommerce uninstall: could not remove MU plugin at ' . $mu_file );
		}
	}
}
