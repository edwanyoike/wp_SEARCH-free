<?php
declare(strict_types=1);

/**
 * Plugin activation and schema creation logic.
 *
 * @package WP_Fast_Search
 */

namespace WCS\Search;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Activator {

	/**
	 * Schema version. Bump this whenever create_tables() changes so that
	 * existing installs receive the new schema via the plugins_loaded check
	 * in init() — WordPress does not re-run activation hooks on plugin updates.
	 */
	private const DB_VERSION = '1.8.0';

	/**
	 * Upgrading installs whose stored version is below this need one full
	 * rebuild: the index rows lack columns/data added since (total_sales,
	 * sku_normalized, sales_30d, variation SKUs, vocabulary terms, excerpt).
	 */
	private const REBUILD_REQUIRED_BELOW = '1.8.0';

	/**
	 * Every option this plugin creates. Used by the Danger Zone reset and
	 * uninstall.php so cleanup deletes exactly these — never a broad
	 * LIKE 'wcs_%' pattern, which would also match WooCommerce Subscriptions'
	 * options (that plugin shares the wcs_ prefix).
	 */
	public const PLUGIN_OPTIONS = array(
		'wcs_cache_version',
		'wcs_db_version',
		'wcs_mu_version',
		'wcs_schema_error',
		'wcs_ft_parser',
		'wcs_is_indexing',
		'wcs_reindex_processed',
		'wcs_rebuild_epoch',
		'wcs_rebuild_cursor',
		'wcs_rebuild_phase',
		'wcs_last_indexed',
		'wcs_last_rebuild_error',
		'wcs_result_count',
		'wcs_min_chars',
		'wcs_show_out_of_stock',
		'wcs_search_title',
		'wcs_search_sku',
		'wcs_search_content',
		'wcs_search_taxonomy',
		'wcs_delete_data_on_uninstall',
		'wcs_free_cap_reached',
	);

	/**
	 * Transient key prefixes this plugin creates (without the _transient_ /
	 * _transient_timeout_ WordPress prefixes). Shared by cleanup routines for
	 * the same reason as PLUGIN_OPTIONS: WooCommerce Subscriptions stores
	 * transients like wcs_report_*, so '_transient_wcs_%' must never be used.
	 */
	public const TRANSIENT_PREFIXES = array(
		'wcs_v',           // search result cache: wcs_v{version}_{currency}_{md5}
		'wcs_rl_',         // search rate limiter
		'wcs_nr_',         // nonce-refresh rate limiter
		'wcs_batch_retry_', // per-cursor rebuild retry flags
	);

	/**
	 * Run on plugin activation.
	 *
	 * @param bool $network_wide Whether the plugin is being activated network-wide.
	 * @codeCoverageIgnore Activation context: multisite loop + requirement gate; exercised only on real activation.
	 */
	public static function activate( bool $network_wide = false ): void {
		self::check_requirements();

		if ( is_multisite() && $network_wide ) {
			global $wpdb;
			// LIMIT 1000: bounds activation time on huge networks (each site
			// needs table creation + a rebuild kick-off, and this runs in one
			// request). Sites beyond the cap are picked up individually by the
			// wp_initialize_site handler / per-site schema check on first load.
			$site_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs} LIMIT 1000" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::activate_single_site();
				restore_current_blog();
			}
		} else {
			self::activate_single_site();
		}

		self::install_mu_plugin();
	}

	/**
	 * Activate a single site's schema, default options, and scheduled jobs.
	 * @codeCoverageIgnore Activation context.
	 */
	private static function activate_single_site(): void {
		global $wpdb;

		self::create_tables();

		// Truncate staging table to ensure a clean slate for the background rebuild.
		$stage_table = $wpdb->prefix . 'wcs_search_index_stage';
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $stage_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

		self::schedule_jobs();

		update_option( 'wcs_cache_version', 1, true );
		update_option( 'wcs_reindex_processed', 0, false );
		update_option( 'wcs_is_indexing', 1, false );
		update_option( 'wcs_db_version', self::DB_VERSION, false );
	}

	/**
	 * Run on plugin deactivation.
	 *
	 * @param bool $network_wide Whether the plugin is being deactivated network-wide.
	 * @codeCoverageIgnore Deactivation context: multisite loop.
	 */
	public static function deactivate( bool $network_wide = false ): void {
		if ( is_multisite() && $network_wide ) {
			global $wpdb;
			// LIMIT 1000: see activate(). Beyond-cap sites only keep scheduled
			// jobs, which no-op safely once the plugin files are gone.
			$site_ids = $wpdb->get_col( "SELECT blog_id FROM {$wpdb->blogs} LIMIT 1000" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			foreach ( $site_ids as $site_id ) {
				switch_to_blog( (int) $site_id );
				self::deactivate_single_site();
				restore_current_blog();
			}
		} else {
			self::deactivate_single_site();
		}

		self::remove_mu_plugin();
	}

	/**
	 * Deactivate a single site's scheduled actions.
	 * @codeCoverageIgnore Deactivation context.
	 */
	private static function deactivate_single_site(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( null, array(), 'turbo-search-for-woocommerce' );
		}

		$timestamp = wp_next_scheduled( 'wcs_daily_transient_gc' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wcs_daily_transient_gc' );
		}
	}

	/**
	 * Initialize runtime components.
	 *
	 * Per VERSION_MANAGEMENT.md §2: WordPress does not re-run activation hooks
	 * on plugin updates, so schema migrations must be checked here on every
	 * plugins_loaded. dbDelta() is idempotent — it only ALTERs what changed.
	 */
	public static function init(): void {
		if ( ! wp_next_scheduled( 'wcs_daily_transient_gc' ) ) {
			wp_schedule_event( time(), 'daily', 'wcs_daily_transient_gc' );
		}

		// Run schema migration when the stored DB version is behind the current one,
		// OR when the main table was manually deleted while the version option survived.
		// The version option is autoloaded (zero extra queries) and the SHOW TABLES
		// recovery probe only runs in admin requests — frontend page loads pay
		// nothing for this check in steady state.
		global $wpdb;
		$stored_version  = get_option( 'wcs_db_version', '0' );
		$needs_migration = version_compare( (string) $stored_version, self::DB_VERSION, '<' );

		$table_missing = false;
		if ( ! $needs_migration && is_admin() ) {
			$main_table    = $wpdb->prefix . 'wcs_search_index';
			$table_missing = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $main_table ) ) !== $main_table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		}

		// Keep the MU cache-bypass file current after plugin updates. WordPress
		// never re-runs activation hooks on update (dashboard, zip upload, or
		// FTP), so without this check customers keep the old MU copy forever —
		// and an outdated copy can normalize queries differently, silently
		// missing the cache on every fast-path request. Steady-state cost: one
		// autoloaded-option comparison; install_mu_plugin() itself md5-skips
		// when the file is already identical.
		if ( is_admin() && get_option( 'wcs_mu_version' ) !== WCS_VERSION ) {
			self::install_mu_plugin();
		}

		if ( $needs_migration || $table_missing ) {
			self::create_tables();
			// Use delete+add instead of update_option to survive stale external object
			// cache entries left by ajax_delete_all_data()'s direct SQL DELETE (which
			// bypasses cache invalidation, making update_option silently fail).
			delete_option( 'wcs_db_version' );
			add_option( 'wcs_db_version', self::DB_VERSION, '', true );

			// Existing rows were built under an older row shape — upgrading
			// installs need one full rebuild to populate the new columns and
			// vocabulary. Fresh activations skip this ('0' version): activate()
			// already schedules the initial build.
			if ( '0' !== (string) $stored_version && version_compare( (string) $stored_version, self::REBUILD_REQUIRED_BELOW, '<' )
				&& class_exists( '\\WCS\\Search\\Indexer' ) ) {
				\WCS\Search\Indexer::start_rebuild();
			}
		}

		// Handle newly created sites in a Multisite network
		if ( is_multisite() ) {
			add_action( 'wp_initialize_site', array( __CLASS__, 'on_new_site' ), 10, 2 );
		}
	}

	/**
	 * Initialize search tables and options on newly created sites in a multisite.
	 *
	 * @param \WP_Site $site The site object.
	 * @codeCoverageIgnore Multisite-only path.
	 */
	public static function on_new_site( $site ): void {
		if ( ! is_a( $site, 'WP_Site' ) ) {
			return;
		}

		// is_plugin_active_for_network() lives in wp-admin — ensure it is loaded.
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		if ( is_plugin_active_for_network( plugin_basename( dirname( __DIR__ ) . '/turbo-search-for-woocommerce.php' ) ) ) {
			switch_to_blog( (int) $site->blog_id );
			self::activate_single_site();
			restore_current_blog();
		}
	}

	/**
	 * Create database schema.
	 */
	private static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$tables          = array(
			$wpdb->prefix . 'wcs_search_index',
			$wpdb->prefix . 'wcs_search_index_stage',
		);

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$is_ngram_supported = self::is_ngram_supported();
		$schema_error       = '';

		// NOTE: image_url and permalink are varchar, not TEXT. MySQL (5.7 and 8.x)
		// rejects literal DEFAULT values on BLOB/TEXT columns (error 1101), so a
		// TEXT ... DEFAULT '' definition silently fails table creation on stock
		// MySQL — only MariaDB accepts it. varchar(2048) covers any realistic URL.
		foreach ( $tables as $table_name ) {
			$sql = "CREATE TABLE {$table_name} (
				product_id bigint(20) unsigned NOT NULL,
				title text NOT NULL,
				sku varchar(100) NOT NULL DEFAULT '',
				sku_normalized varchar(100) NOT NULL DEFAULT '',
				content longtext NOT NULL,
				excerpt varchar(300) NOT NULL DEFAULT '',
				price_min decimal(10,2) NOT NULL DEFAULT 0.00,
				price_max decimal(10,2) NOT NULL DEFAULT 0.00,
				stock_status varchar(30) NOT NULL DEFAULT 'instock',
				total_sales bigint(20) unsigned NOT NULL DEFAULT 0,
				sales_30d bigint(20) unsigned NOT NULL DEFAULT 0,
				image_url varchar(2048) NOT NULL DEFAULT '',
				permalink varchar(2048) NOT NULL DEFAULT '',
				updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
				PRIMARY KEY  (product_id),
				KEY stock_status (stock_status),
				KEY idx_sku (sku),
				KEY idx_sku_norm (sku_normalized),
				KEY idx_title_prefix (title(100))
			) {$charset_collate} ENGINE=InnoDB;";

			dbDelta( $sql );
			if ( $wpdb->last_error ) {
				$schema_error = $wpdb->last_error;
			}

			// Skip index creation when the table itself failed to create.
			$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			if ( ! $table_exists ) {
				continue;
			}

			// Create the FULLTEXT indexes directly if missing. Two indexes:
			//   search_data (title, sku, content) — recall: candidate matching.
			//   ft_title    (title)               — precision: lets the ranking
			//     expression weight title hits above sku/content hits.
			$ft_indexes = array(
				'search_data' => '(title, sku, content)',
				'ft_title'    => '(title)',
			);
			foreach ( $ft_indexes as $key_name => $columns ) {
				$index_exists = $wpdb->get_row( $wpdb->prepare( 'SHOW INDEX FROM %i WHERE Key_name = %s', $table_name, $key_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

				if ( ! $index_exists ) {
					$parser_sql = $is_ngram_supported ? ' WITH PARSER ngram' : '';
					// $key_name, $columns, $parser_sql are fixed literals from the array above.
					$wpdb->query( $wpdb->prepare( "ALTER TABLE %i ADD FULLTEXT KEY {$key_name} {$columns}{$parser_sql}", $table_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
				}
			}
		}

		// Search analytics (wcs_search_log) and the typo-correction
		// vocabulary sidecar (wcs_search_terms) are Pro features — this
		// edition never creates those tables.

		// Surface schema failures instead of failing silently. Without this, a
		// failed CREATE TABLE leaves every search returning empty results with
		// no admin-visible error (the query guard sees a missing table).
		$main_table        = $wpdb->prefix . 'wcs_search_index';
		$main_table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $main_table ) ) === $main_table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		if ( ! $main_table_exists ) {
			update_option( 'wcs_schema_error', $schema_error ? $schema_error : 'CREATE TABLE failed for ' . $main_table, false );
			return;
		}
		delete_option( 'wcs_schema_error' );

		// Record which FULLTEXT parser the live index actually uses so the
		// query layer can gate correctly: ngram indexes serve 2-char tokens,
		// the default InnoDB parser is only reliable from 4 chars up. Read from
		// SHOW CREATE TABLE (not is_ngram_supported()) because a pre-existing
		// index keeps whatever parser it was originally built with.
		$create_row = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $main_table ), ARRAY_N ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$parser     = ( isset( $create_row[1] ) && false !== stripos( (string) $create_row[1], 'WITH PARSER' ) ) ? 'ngram' : 'default';
		// autoload=true: read on every cache-miss search, so it must come from
		// the alloptions batch instead of its own SELECT.
		update_option( 'wcs_ft_parser', $parser, true );
	}

	private static function is_ngram_supported(): bool {
		global $wpdb;
		$raw_version = $wpdb->get_var( 'SELECT VERSION()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		// MySQL 5.7.6+ supports ngram
		// MariaDB does NOT support ngram natively in the same way, fallback to standard.
		if ( ! empty( $raw_version ) && stripos( $raw_version, 'MariaDB' ) !== false ) {
			return false; 
		}

		$version = preg_replace( '/[^0-9.-]/', '', (string) $raw_version );
		return version_compare( $version, '5.7.6', '>=' );
	}

	/**
	 * The Pro edition's plugin basename, as shipped (folder name from its
	 * own build.sh PLUGIN_SLUG). Renaming the installed folder defeats this
	 * detection — the same limitation every "detect a sibling plugin" check
	 * in WordPress has (there is no other stable cross-plugin identifier).
	 */
	private const PRO_EDITION_BASENAME = 'turbo-search-for-woocommerce-pro/turbo-search-for-woocommerce.php';

	/**
	 * Verify environment meets requirements.
	 * @codeCoverageIgnore Calls wp_die; activation context.
	 */
	private static function check_requirements(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			deactivate_plugins( plugin_basename( dirname( __DIR__ ) . '/turbo-search-for-woocommerce.php' ) );
			wp_die( esc_html__( 'Turbo Search for WooCommerce requires WooCommerce to be active.', 'turbo-search-for-woocommerce' ), 'Plugin Dependency Error', array( 'back_link' => true ) );
		}

		if ( self::is_pro_edition_active() ) {
			deactivate_plugins( plugin_basename( dirname( __DIR__ ) . '/turbo-search-for-woocommerce.php' ) );
			wp_die(
				esc_html__( 'Turbo Search for WooCommerce Pro is already active on this site. Deactivate Pro first if you want to switch to the free edition — running both at once is not supported.', 'turbo-search-for-woocommerce' ),
				'Plugin Conflict',
				array( 'back_link' => true )
			);
		}
	}

	/**
	 * Not @codeCoverageIgnore'd — unlike check_requirements() itself, this
	 * never calls wp_die() and is safe to unit test directly.
	 */
	public static function is_pro_edition_active(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( self::PRO_EDITION_BASENAME );
	}

	/**
	 * Copy the cache-bypass MU plugin into wp-content/mu-plugins/.
	 *
	 * Called on plugin activation. The source file ships inside the plugin
	 * package at mu-plugin/wcs-cache-bypass.php, so the customer never has
	 * to touch the mu-plugins directory manually.
	 *
	 * Silently skips if the mu-plugins directory is not writable (managed
	 * hosts that lock that directory will simply fall back to the normal
	 * REST route with no errors).
	 */
	private static function install_mu_plugin(): void {
		$source      = WCS_PLUGIN_DIR . 'mu-plugin/wcs-cache-bypass.php';
		$mu_dir      = trailingslashit( WPMU_PLUGIN_DIR );
		$destination = $mu_dir . 'wcs-cache-bypass.php';

		// Record the attempt for this plugin version so the init() update check
		// runs at most once per version, not on every admin page load. autoload
		// = true: init() compares it on every admin request. If the copy below
		// fails (read-only mu-plugins dir), the settings-page admin notice
		// already tells the owner how to fix permissions and retry.
		update_option( 'wcs_mu_version', WCS_VERSION, true );

		if ( ! file_exists( $source ) ) {
			return; // Source missing — nothing to install.
		}

		// Create mu-plugins dir if it does not exist yet.
		if ( ! is_dir( $mu_dir ) ) {
			wp_mkdir_p( $mu_dir );
		}

		if ( file_exists( $destination ) ) {
			// If the destination and source are the same file (e.g. symlinked in dev), skip copying.
			if ( realpath( $source ) === realpath( $destination ) ) {
				return;
			}
			// If already identical contents, skip copying.
			if ( md5_file( $source ) === md5_file( $destination ) ) {
				return;
			}
			// If the file exists but is not writable, attempt deletion. On Unix,
			// directory write permission controls deletion, not the file's own bits.
			if ( ! is_writable( $destination ) && is_writable( $mu_dir ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $destination );
			}
		}

		// Only copy if destination is writable or directory is writable.
		if ( is_writable( $mu_dir ) && ( ! file_exists( $destination ) || is_writable( $destination ) ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			// phpcs:ignore WordPress.WP.AlternativeFunctions.copy_copy
			if ( ! copy( $source, $destination ) ) {
				Logger::log( 'Could not copy MU cache-bypass plugin to ' . $destination, 'warning' );
			}
		}
	}

	/**
	 * Remove the cache-bypass MU plugin from wp-content/mu-plugins/.
	 *
	 * Called on plugin deactivation so the bypass file does not remain
	 * active after the main plugin has been turned off.
	 * @codeCoverageIgnore Deactivation context: filesystem-permission branches.
	 */
	private static function remove_mu_plugin(): void {
		$destination = trailingslashit( WPMU_PLUGIN_DIR ) . 'wcs-cache-bypass.php';

		if ( file_exists( $destination ) || is_link( $destination ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			if ( ! unlink( $destination ) ) {
				Logger::log( 'Could not remove MU cache-bypass plugin from ' . $destination, 'warning' );
			}
		}
	}

	/**
	 * Schedule initial jobs.
	 * @codeCoverageIgnore Activation context.
	 */
	private static function schedule_jobs(): void {
		if ( class_exists( '\\WCS\\Search\\Indexer' ) ) {
			\WCS\Search\Indexer::start_rebuild();
		}
	}
}
