<?php
declare(strict_types=1);

/**
 * Admin settings and dashboard.
 *
 * @package OzuLabs_Turbo_Search_For_WooCommerce
 */

namespace OTSW\Search;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Settings {

	/**
	 * Initialize hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_settings_page' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notices' ) );
		add_action( 'wp_ajax_otsw_dismiss_notice', array( __CLASS__, 'ajax_dismiss_notice' ) );
		add_action( 'wp_ajax_otsw_rebuild_index', array( __CLASS__, 'ajax_rebuild_index' ) );
		add_action( 'wp_ajax_otsw_get_index_status', array( __CLASS__, 'ajax_get_index_status' ) );
		add_action( 'wp_ajax_otsw_delete_all_data', array( __CLASS__, 'ajax_delete_all_data' ) );
		add_filter( 'plugin_action_links_' . OTSW_PLUGIN_BASENAME, array( __CLASS__, 'add_plugin_action_links' ) );
	}

	public static function ajax_dismiss_notice(): void {
		check_ajax_referer( 'otsw_dismiss_notice' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}

		$notice_id = isset( $_POST['notice_id'] ) ? sanitize_key( $_POST['notice_id'] ) : '';

		$allowed = array( 'otsw_notice_mu_bypass', 'otsw_notice_no_cache' );
		if ( ! in_array( $notice_id, $allowed, true ) ) {
			wp_send_json_error( 'invalid_notice', 400 );
		}

		update_user_meta( get_current_user_id(), $notice_id . '_dismissed', '1' );
		wp_send_json_success();
	}

	/**
	 * Render environment-specific admin notices on the plugin settings screen.
	 *
	 * Both notices are:
	 *   - Scoped to our own settings page only — never shown globally across wp-admin.
	 *   - Permanently dismissible per-user via user_meta. Clicking the X saves a
	 *     flag for the current admin; the notice will never appear again for them.
	 *
	 * Notice 1 (yellow) — MU cache-bypass file not installed.
	 * Notice 2 (blue)   — No persistent object cache detected (Redis / Memcached).
	 */
	public static function render_admin_notices(): void {
		// Only show on our own settings page.
		$screen = get_current_screen();
		if ( ! $screen || 'toplevel_page_otsw-fast-search' !== $screen->id ) {
			return;
		}

		$user_id = get_current_user_id();

		// ── Notice -1: schema creation failed (e.g. MySQL rejected the DDL) ──
		$schema_error = (string) get_option( 'otsw_schema_error', '' );
		if ( $schema_error ) {
			?>
			<div class="notice notice-error">
				<p>
					<strong><?php esc_html_e( 'Turbo Search for WooCommerce — Search Index Table Could Not Be Created', 'ozulabs-turbo-search-for-woocommerce' ); ?></strong>
				</p>
				<p>
					<?php esc_html_e( 'The database rejected the search index schema, so search results will be empty. Database error:', 'ozulabs-turbo-search-for-woocommerce' ); ?>
					<code><?php echo esc_html( $schema_error ); ?></code>
				</p>
				<p><em><?php esc_html_e( 'Please share this error with support@ozulabs.com. Deactivating and reactivating the plugin retries table creation.', 'ozulabs-turbo-search-for-woocommerce' ); ?></em></p>
			</div>
			<?php
		}

		// ── Notice 0: Action Scheduler not available ─────────────────────────
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			?>
			<div class="notice notice-error">
				<p>
					<strong><?php esc_html_e( 'Turbo Search for WooCommerce — Action Scheduler Not Found', 'ozulabs-turbo-search-for-woocommerce' ); ?></strong>
				</p>
				<p>
					<?php esc_html_e( 'Turbo Search for WooCommerce requires Action Scheduler to queue background indexing jobs. Action Scheduler is bundled with WooCommerce — please ensure WooCommerce is active. Without it, product indexing and live sync will not run.', 'ozulabs-turbo-search-for-woocommerce' ); ?>
				</p>
			</div>
			<?php
		}

		// ── Notice 1: MU plugin missing or outdated ──────────────────────────
		// Checked directly on every admin page load (matching
		// Activator::install_mu_plugin(), which self-heals this the moment
		// host permissions allow it — no manual reactivation needed) rather
		// than only when the file is entirely absent: a file that exists
		// but is stale (locked-down permissions that allow keeping an old
		// copy but not replacing it) is otherwise invisible to the admin
		// indefinitely, silently serving the slower REST route path.
		$mu_dest       = trailingslashit( WPMU_PLUGIN_DIR ) . 'otsw-cache-bypass.php';
		$mu_source     = OTSW_PLUGIN_DIR . 'mu-plugin/otsw-cache-bypass.php';
		$mu_needs_work = ! file_exists( $mu_dest ) || ( file_exists( $mu_source ) && md5_file( $mu_dest ) !== md5_file( $mu_source ) );
		if ( $mu_needs_work && ! get_user_meta( $user_id, 'otsw_notice_mu_bypass_dismissed', true ) ) {
			?>
			<div class="notice notice-warning is-dismissible" data-otsw-notice="otsw_notice_mu_bypass">
				<p>
					<strong><?php esc_html_e( 'Turbo Search for WooCommerce — Cache Bypass Not Active', 'ozulabs-turbo-search-for-woocommerce' ); ?></strong>
				</p>
				<p>
					<?php
					esc_html_e(
						'The cache-bypass file could not be installed or updated in your wp-content/mu-plugins/ directory — your host may have it set to read-only. The plugin is fully functional and serving search results, but cached responses will use the standard WordPress REST route instead of the faster early-exit path.',
						'ozulabs-turbo-search-for-woocommerce'
					);
					?>
				</p>
				<p><em><?php esc_html_e( 'To unlock maximum speed: ask your host to allow writes to wp-content/mu-plugins/. No further action needed here — the plugin checks again automatically on your next visit to any admin page.', 'ozulabs-turbo-search-for-woocommerce' ); ?></em></p>
			</div>
			<?php
		}

		// ── Notice 2: No persistent object cache ─────────────────────────────
		if ( ! wp_using_ext_object_cache() && ! get_user_meta( $user_id, 'otsw_notice_no_cache_dismissed', true ) ) {
			?>
			<div class="notice notice-info is-dismissible" data-otsw-notice="otsw_notice_no_cache">
				<p>
					<strong><?php esc_html_e( 'Turbo Search for WooCommerce — Tip: Enable a Persistent Object Cache', 'ozulabs-turbo-search-for-woocommerce' ); ?></strong>
				</p>
				<p>
					<?php
					esc_html_e(
						'Your site is currently caching search results in the database (wp_options). Everything works correctly, but adding a Redis or Memcached object cache will make cached search queries return in under 5 ms instead of a database round-trip.',
						'ozulabs-turbo-search-for-woocommerce'
					);
					?>
				</p>
				<p><em><?php esc_html_e( 'Recommended: install the free "Redis Object Cache" plugin and enable Redis on your hosting plan.', 'ozulabs-turbo-search-for-woocommerce' ); ?></em></p>
			</div>
			<?php
		}

		// Dismiss persistence is handled by assets/js/admin.js (enqueued on this
		// screen), which reads the nonce from the otswAdmin config object.
	}

	/**
	 * Add Settings link to the plugin row.
	 *
	 * @param array $links Array of plugin action links.
	 * @return array
	 */
	public static function add_plugin_action_links( array $links ): array {
		$settings_url  = admin_url( 'admin.php?page=otsw-fast-search' );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'ozulabs-turbo-search-for-woocommerce' ) . '</a>';
		array_unshift( $links, $settings_link );

		// Pro already covers everything Free does, so an upgrade prompt here
		// would be redundant (and this site's Free listing is presumably
		// inactive anyway — see the mutual-exclusion guard in the main file).
		if ( ! Activator::is_pro_edition_active() ) {
			$links[] = '<a href="https://ozulabs.com/plugins/turbo-search/" target="_blank" rel="noopener noreferrer" style="color:#008a20;font-weight:600;">'
				. esc_html__( 'Upgrade to Pro', 'ozulabs-turbo-search-for-woocommerce' )
				. '</a>';
		}

		return $links;
	}

	/**
	 * Add menu page. Standalone top-level admin menu item (not nested under
	 * Settings) so it's visible without opening that submenu first.
	 */
	public static function add_settings_page(): void {
		add_menu_page(
			esc_html__( 'Turbo Search Settings', 'ozulabs-turbo-search-for-woocommerce' ),
			esc_html__( 'Turbo Search', 'ozulabs-turbo-search-for-woocommerce' ),
			'manage_options',
			'otsw-fast-search',
			array( __CLASS__, 'render_settings_page' ),
			// A plain file URL, not a base64 data URI: WordPress recolors
			// base64-embedded SVG menu icons to a flat mask matching the
			// admin color scheme, which would wash out the green icon. The
			// ?ver= query string busts CDN/browser caches of the icon file
			// whenever it changes alongside a plugin version bump.
			OTSW_PLUGIN_URL . 'assets/images/admin-menu-icon.svg?ver=' . OTSW_VERSION,
			58
		);

		// Submenu entries for each tab, purely so hovering "Turbo Search" in
		// the admin menu shows a flyout listing them — add_menu_page() alone
		// registers no submenu array, so WP shows no flyout at all. Appending
		// '&tab=...' to the parent slug as each $menu_slug is the standard WP
		// pattern for tab-based settings pages: add_submenu_page() only uses
		// $menu_slug to build the href (admin.php?page=$menu_slug) and never
		// validates it against '&'. All entries render through the same
		// render_settings_page(); the tab whitelist there (and in
		// settings-page.php's @var doc comment) stays the single source of
		// truth for which tabs exist — keep both in sync with this list.
		$otsw_tab_slugs = array(
			'otsw-fast-search'          => __( 'Settings', 'ozulabs-turbo-search-for-woocommerce' ),
			'otsw-fast-search&tab=data' => __( 'App Data', 'ozulabs-turbo-search-for-woocommerce' ),
			'otsw-fast-search&tab=docs' => __( 'Documentation', 'ozulabs-turbo-search-for-woocommerce' ),
		);
		foreach ( $otsw_tab_slugs as $otsw_menu_slug => $otsw_tab_label ) {
			add_submenu_page(
				'otsw-fast-search',
				$otsw_tab_label,
				$otsw_tab_label,
				'manage_options',
				$otsw_menu_slug,
				array( __CLASS__, 'render_settings_page' )
			);
		}
	}

	/**
	 * Register settings.
	 */
	public static function register_settings(): void {
		register_setting( 'otsw_settings_group', 'otsw_result_count', array(
			'type'              => 'integer',
			'sanitize_callback' => static function ( $value ): int {
				// Clamp to the same 1-20 range as the settings field. Plain
				// absint() let a blank/missing submission silently save as 0,
				// which puts a literal LIMIT 0 on every search query and
				// disables search entirely with no visible error — confirmed
				// happening in production on the Pro edition, same code path.
				return min( 20, max( 1, absint( $value ) ) );
			},
			'default'           => 6,
		) );
		register_setting( 'otsw_settings_group', 'otsw_show_out_of_stock', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => true,
		) );
		register_setting( 'otsw_settings_group', 'otsw_min_chars', array(
			'type'              => 'integer',
			'sanitize_callback' => static function ( $value ): int {
				// Clamp to the same 1-10 range as the settings field. An
				// unclamped high value would silently disable search entirely.
				return min( 10, max( 1, absint( $value ) ) );
			},
			'default'           => 2,
		) );
		register_setting( 'otsw_settings_group', 'otsw_enable_recent_searches', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => true,
		) );
		register_setting( 'otsw_settings_group', 'otsw_recent_searches_count', array(
			'type'              => 'integer',
			'sanitize_callback' => static function ( $value ): int {
				// Same clamp-not-absint reasoning as otsw_min_chars above — a
				// blank/missing submission must not silently save as 0.
				return min( 10, max( 1, absint( $value ) ) );
			},
			'default'           => 5,
		) );
		register_setting( 'otsw_settings_group', 'otsw_rate_limit_requests', array(
			'type'              => 'integer',
			'sanitize_callback' => static function ( $value ): int {
				// Floor of 5: low enough to matter, but a store owner mistyping
				// "0" must not accidentally block every shopper's search.
				return min( 1000, max( 5, absint( $value ) ) );
			},
			'default'           => 60,
		) );
		register_setting( 'otsw_settings_group', 'otsw_rate_limit_window', array(
			'type'              => 'integer',
			'sanitize_callback' => static function ( $value ): int {
				return min( 3600, max( 10, absint( $value ) ) );
			},
			'default'           => MINUTE_IN_SECONDS,
		) );
		register_setting( 'otsw_settings_group', 'otsw_fallback_rate_limit_requests', array(
			'type'              => 'integer',
			'sanitize_callback' => static function ( $value ): int {
				return min( 1000, max( 1, absint( $value ) ) );
			},
			'default'           => 10,
		) );
		register_setting( 'otsw_settings_group', 'otsw_fallback_rate_limit_window', array(
			'type'              => 'integer',
			'sanitize_callback' => static function ( $value ): int {
				return min( 3600, max( 10, absint( $value ) ) );
			},
			'default'           => MINUTE_IN_SECONDS,
		) );
		register_setting( 'otsw_settings_group', 'otsw_search_title', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => true,
		) );
		register_setting( 'otsw_settings_group', 'otsw_search_sku', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => true,
		) );
		register_setting( 'otsw_settings_group', 'otsw_search_content', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => true,
		) );
		register_setting( 'otsw_settings_group', 'otsw_search_taxonomy', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => true,
		) );
		// Own settings group (not otsw_settings_group) — it lives on the App Data
		// tab's own <form>, and options.php resets every registered option in a
		// group to null if its field isn't present in the submitted form, so it
		// must not share a group with fields that only render on the Settings tab.
		register_setting( 'otsw_data_settings_group', 'otsw_delete_data_on_uninstall', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		) );
	}

	/**
	 * Enqueue the settings-page stylesheet and controller script.
	 *
	 * Loaded only on our own screen. All nonces and translatable strings the
	 * JS needs are passed via the otswAdmin config object — the page markup
	 * itself (in includes/views/) contains no inline scripts or styles.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_admin_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_otsw-fast-search' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'otsw-admin-css', OTSW_PLUGIN_URL . 'assets/css/admin.css', array(), OTSW_VERSION );
		wp_enqueue_script( 'otsw-admin-js', OTSW_PLUGIN_URL . 'assets/js/admin.js', array(), OTSW_VERSION, true );

		$config = array(
			'isIndexing'  => (bool) get_option( 'otsw_is_indexing', false ),
			'nonces'      => array(
				'status'  => wp_create_nonce( 'otsw_status' ),
				'rebuild' => wp_create_nonce( 'otsw_rebuild' ),
				'delete'  => wp_create_nonce( 'otsw_delete_all_data' ),
				'dismiss' => wp_create_nonce( 'otsw_dismiss_notice' ),
			),
			'i18n'        => array(
				/* translators: 1: number of processed products, 2: total number of published products */
				'progress'       => __( 'Processed %1$d of %2$d published products.', 'ozulabs-turbo-search-for-woocommerce' ),
				'idle'           => __( 'Status: Idle / Complete', 'ozulabs-turbo-search-for-woocommerce' ),
				'indexing'       => __( 'Status: Indexing…', 'ozulabs-turbo-search-for-woocommerce' ),
				'swapping'       => __( 'Status: Finalizing — swapping live index…', 'ozulabs-turbo-search-for-woocommerce' ),
				'optimizing'     => __( 'Status: Finalizing — optimizing index…', 'ozulabs-turbo-search-for-woocommerce' ),
				/* translators: %d: product ID the rebuild is retrying from */
				'recovering'     => __( 'Status: Recovering — retrying from product #%d…', 'ozulabs-turbo-search-for-woocommerce' ),
				/* translators: %d: seconds until automatic recovery */
				'timedOut'       => __( 'Status: Batch timed out — auto-recovering in %ds…', 'ozulabs-turbo-search-for-woocommerce' ),
				'errRebuild'     => __( 'Error triggering rebuild.', 'ozulabs-turbo-search-for-woocommerce' ),
				'errDelete'      => __( 'Error deleting plugin data.', 'ozulabs-turbo-search-for-woocommerce' ),
				'confirmRebuild' => __( 'Are you sure you want to rebuild the entire search index? This will run in the background.', 'ozulabs-turbo-search-for-woocommerce' ),
				'confirmDelete'  => __( 'This will permanently delete all plugin data including the search index, all settings, and cached results. The plugin stays active but you will need to rebuild the index afterwards. Are you absolutely sure?', 'ozulabs-turbo-search-for-woocommerce' ),
			),
			'errorLabels' => self::rebuild_error_labels(),
		);
		wp_add_inline_script( 'otsw-admin-js', 'const otswAdmin = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Human-readable text for known otsw_last_rebuild_error codes. Shared
	 * between the initial server-rendered page (tab-settings.php) and the
	 * live AJAX status poll (assets/js/admin.js) so the two never drift.
	 *
	 * @return array<string, string> Error code => translated message.
	 */
	public static function rebuild_error_labels(): array {
		return array(
			'stuck_no_batch_dispatched' => __( 'The last rebuild could not start — this usually means the server ran out of memory partway through. Click "Rebuild Index" to try again; consider lowering the batch size via the otsw_batch_size filter if this keeps happening.', 'ozulabs-turbo-search-for-woocommerce' ),
			'staging_empty'             => __( 'The last rebuild produced no data and was discarded — your existing search index was kept. Click "Rebuild Index" to try again.', 'ozulabs-turbo-search-for-woocommerce' ),
			'schedule_enqueue_failed'   => __( 'The last rebuild could not be scheduled — the background job queue was not ready yet when this ran (this can happen right after an update). It was retried automatically several times without success. Click "Rebuild Index" to try again.', 'ozulabs-turbo-search-for-woocommerce' ),
			'partial_failure'           => __( 'The last rebuild finished, but one or more products failed to write to the new index and were skipped — check WooCommerce → Status → Logs (source: turbo-search-for-woocommerce) for which ones. The rest of the catalog is searchable on the new index; re-saving an affected product will retry it.', 'ozulabs-turbo-search-for-woocommerce' ),
			'batch_write_failed'        => __( 'The last rebuild was halted because every product in a batch failed to write to the new index — your existing search index was kept. Check WooCommerce → Status → Logs (source: turbo-search-for-woocommerce) for the underlying database error, then click "Rebuild Index" to try again.', 'ozulabs-turbo-search-for-woocommerce' ),
			'batch_fetch_failed'        => __( 'The last rebuild was halted because the database repeatedly failed while reading the product list — your existing search index was kept. Check WooCommerce → Status → Logs (source: turbo-search-for-woocommerce) for the underlying database error, then click "Rebuild Index" to try again.', 'ozulabs-turbo-search-for-woocommerce' ),
			'rebuild_setup_failed'      => __( 'The last rebuild could not start because the database failed to prepare a clean staging table — your existing search index was kept. Check WooCommerce → Status → Logs (source: turbo-search-for-woocommerce) for the underlying database error, then click "Rebuild Index" to try again.', 'ozulabs-turbo-search-for-woocommerce' ),
			'swap_failed'               => __( 'The last rebuild finished building successfully but the final switch to the new index repeatedly failed — your existing search index was kept and is still active. Check WooCommerce → Status → Logs (source: turbo-search-for-woocommerce) for the underlying database error, then click "Rebuild Index" to try again.', 'ozulabs-turbo-search-for-woocommerce' ),
		);
	}

	/**
	 * Render settings page.
	 */
	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab navigation, no state change
		if ( ! in_array( $active_tab, array( 'settings', 'data', 'docs' ), true ) ) {
			$active_tab = 'settings';
		}

		$is_indexing  = (bool) get_option( 'otsw_is_indexing', false );
		$last_indexed = (int) get_option( 'otsw_last_indexed', 0 );
		// Only shown while idle — a fresh rebuild trigger clears this option,
		// so a lingering value always reflects the current idle state.
		$last_rebuild_error = $is_indexing ? '' : (string) get_option( 'otsw_last_rebuild_error', '' );
		$total              = 0;
		$counts             = wp_count_posts( 'product' );
		if ( isset( $counts->publish ) ) {
			$total = (int) $counts->publish;
		}
		$processed = min( (int) get_option( 'otsw_reindex_processed', 0 ), max( 1, $total ) );

		// Markup lives in view templates; behaviour in assets/js/admin.js
		// (enqueued by enqueue_admin_assets). $active_tab, $is_indexing,
		// $last_indexed, $last_rebuild_error, $total, $processed
		// are consumed by the views.
		include OTSW_PLUGIN_DIR . 'includes/views/settings-page.php';
	}


	/**
	 * AJAX handler — immediately drop all plugin tables, options, and transients.
	 *
	 * This is the "Delete All Data Now" button. It does the same cleanup as
	 * uninstall.php but without requiring the plugin to be deleted. After the
	 * cleanup the plugin stays active; the next page load or manual rebuild will
	 * recreate the index table via the activator.
	 */
	public static function ajax_delete_all_data(): void {
		check_ajax_referer( 'otsw_delete_all_data' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}

		global $wpdb;

		$main_table  = $wpdb->prefix . 'otsw_search_index';
		$stage_table = $wpdb->prefix . 'otsw_search_index_stage';
		$rl_table    = $wpdb->prefix . 'otsw_rate_limits';

		// Drop the index tables and the rate-limit counters. (The zero-result
		// log and vocabulary sidecar are Pro-only tables this edition never creates.)
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $main_table ) );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $stage_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $rl_table ) );    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

		// Delete plugin options via the API (invalidates object-cache entries too).
		// Explicit list — a broad LIKE 'otsw_%' would also delete WooCommerce
		// Subscriptions' options, which share the otsw_ prefix.
		foreach ( Activator::PLUGIN_OPTIONS as $option ) {
			delete_option( $option );
		}

		// Delete plugin transients by our exact key shapes — never
		// '_transient_otsw_%', which matches WC Subscriptions' otsw_report_* transients.
		foreach ( Activator::TRANSIENT_PREFIXES as $prefix ) {
			$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
			) );
		}

		// Cancel any pending Action Scheduler jobs.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( null, array(), 'ozulabs-turbo-search-for-woocommerce' );
		}

		// A global wp_cache_flush() previously ran here to guard against a stale
		// otsw_db_version option value surviving in a persistent object cache
		// (Redis/Memcached) after the direct SQL DELETE above, which bypasses
		// the options API's own cache invalidation for anything it touches.
		// That flush cleared every plugin's cached objects on this site, not
		// just this plugin's — WooCommerce and any other active plugin lost
		// their cache too, risking a stampede on a large store. The options
		// this handler itself deletes above already go through delete_option(),
		// which invalidates its own object-cache entry correctly; the specific
		// residual risk (otsw_db_version reappearing stale) is independently
		// guarded in Activator::init(), which writes it back with
		// delete_option()+add_option() rather than update_option() for exactly
		// this reason — see that method's own comment. No global flush needed.

		// Invalidate OPcache entries for this plugin's files only — not the whole
		// server — so stale bytecode doesn't outlive the data reset.
		if ( function_exists( 'opcache_invalidate' ) ) {
			$plugin_files = glob( OTSW_PLUGIN_DIR . 'includes/*.php' );
			foreach ( false !== $plugin_files ? $plugin_files : array() as $file ) {
				opcache_invalidate( $file, true );
			}
			opcache_invalidate( OTSW_PLUGIN_DIR . 'turbo-search-for-woocommerce.php', true );
		}

		// The index table will be recreated on the next page load via Activator::init().

		wp_send_json_success();
	}

	/**
	 * AJAX handler for rebuilding index.
	 */
	public static function ajax_rebuild_index(): void {
		check_ajax_referer( 'otsw_rebuild' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}

		global $wpdb;
		$main_table  = $wpdb->prefix . 'otsw_search_index';
		$stage_table = $wpdb->prefix . 'otsw_search_index_stage';

		// Create the staging table matching the live index schema. (The
		// vocabulary sidecar staging table is Pro-only — this edition never
		// creates otsw_search_terms in the first place.)
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE IF NOT EXISTS %i LIKE %i', $stage_table, $main_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $stage_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			wp_send_json_error( esc_html__( 'Action Scheduler is not available. Please ensure WooCommerce is active.', 'ozulabs-turbo-search-for-woocommerce' ), 503 );
			return;
		}

		// Cancel every pending/in-progress batch before starting fresh.
		as_unschedule_all_actions( 'otsw_rebuild_index_batch', array(), 'ozulabs-turbo-search-for-woocommerce' );

		// Millisecond precision — see Indexer::schedule_full_rebuild().
		$epoch = (int) ( microtime( true ) * 1000 );
		update_option( 'otsw_rebuild_epoch', $epoch, false );
		update_option( 'otsw_reindex_processed', 0, false );
		update_option( 'otsw_is_indexing', 1, false );
		delete_option( 'otsw_last_rebuild_error' );

		// $unique=false, $priority=10 — the trailing args were previously
		// (0, true), which cast true to priority 1 instead of the intended 10.
		as_enqueue_async_action( 'otsw_rebuild_index_batch', array(
			'last_id' => 0,
			'epoch'   => $epoch,
		), 'ozulabs-turbo-search-for-woocommerce', false, 10 );

		wp_send_json_success();
	}

	/**
	 * Process exactly one due rebuild batch, so this status poll advances the
	 * rebuild by a single, visible step instead of draining the whole queue.
	 *
	 * This exists so a rebuild keeps moving even on a host where WP-Cron is
	 * slow or unreliable — as long as an admin has the status page open, each
	 * poll nudges the rebuild forward. Deliberately bounded to exactly one
	 * action, not \ActionScheduler_QueueRunner::instance()->run() (Action
	 * Scheduler's own queue runner, which claims up to 25 actions at a time
	 * and keeps looping for up to 30 seconds by default): confirmed live in
	 * the Pro edition that for this plugin's typically-fast batches, a single
	 * such call can process an entire 2000-product rebuild's full queue
	 * before returning, so the progress bar sat at 0% for a while and then
	 * jumped straight to 100% instead of advancing with each poll the way an
	 * admin watching it would expect. Claiming exactly one action here makes
	 * visible progress track the polling cadence.
	 *
	 * Scoped to this plugin's own hook — it never touches another plugin's
	 * unrelated pending Action Scheduler jobs, which just happened to also be
	 * due at the same moment.
	 */
	private static function drive_one_rebuild_batch(): void {
		$store = null;
		$claim = null;

		try {
			$store      = \ActionScheduler::store();
			$claim      = $store->stake_claim( 1, null, array( 'otsw_rebuild_index_batch' ) );
			$action_ids = $claim->get_actions();
			if ( $action_ids ) {
				$runner = \ActionScheduler_QueueRunner::instance();
				foreach ( $action_ids as $action_id ) {
					$runner->process_action( $action_id, 'OTSW Status Poll' );
				}
			}
		} catch ( \Throwable $e ) {
			// Non-fatal fallback — the rebuild still advances via WP-Cron or
			// the next poll.
		} finally {
			if ( $store && $claim ) {
				$store->release_claim( $claim );
			}
		}
	}

	/**
	 * AJAX handler for getting index status.
	 */
	public static function ajax_get_index_status(): void {
		check_ajax_referer( 'otsw_status' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}

		$is_indexing = (bool) get_option( 'otsw_is_indexing', false );

		if ( $is_indexing && class_exists( 'ActionScheduler_QueueRunner' ) ) {
			self::drive_one_rebuild_batch();
			$is_indexing = (bool) get_option( 'otsw_is_indexing', false );
		}

		$recovering = false;
		$stall_secs = 0;
		$phase      = '';
		$cursor     = 0;

		if ( $is_indexing ) {
			global $wpdb;

			// If a batch is in-progress but older than 300s, PHP-FPM's
			// request_terminate_timeout (180s) has already killed the process. WP-Cron
			// can take 5-15 minutes to notice and retry, so we force recovery here:
			// mark it failed and re-enqueue from its cursor immediately so the admin
			// status poll becomes the recovery mechanism without needing page traffic.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$dead = $wpdb->get_row( $wpdb->prepare(
				"SELECT action_id, args,
				        TIMESTAMPDIFF(SECOND, last_attempt_gmt, UTC_TIMESTAMP()) AS age_s
				 FROM {$wpdb->prefix}actionscheduler_actions
				 WHERE hook   = %s
				   AND status = 'in-progress'
				 ORDER BY last_attempt_gmt ASC
				 LIMIT 1",
				'otsw_rebuild_index_batch'
			) );
			if ( $dead ) {
				$stall_secs = (int) $dead->age_s;
				if ( $stall_secs > 300 ) {
					$args  = json_decode( $dead->args, true );
					$epoch = (int) ( $args['epoch'] ?? 0 );
					// Mark failed via the Action Scheduler store API rather than a
					// direct status UPDATE — the API honours AS's claim handling
					// and survives its internal schema changes.
					$marked = false;
					try {
						\ActionScheduler::store()->mark_failure( (int) $dead->action_id );
						$marked = true;
					} catch ( \Throwable $e ) {
						// Store API unavailable or action already transitioned —
						// leave it; the next poll re-evaluates. Do NOT re-enqueue
						// below: the stuck action may still be live, and a second
						// batch chain would race it.
					}
					if ( $marked && $epoch && (int) get_option( 'otsw_rebuild_epoch', 0 ) === $epoch ) {
						$last_id = (int) ( $args['last_id'] ?? 0 );
						// $unique=false, $priority=10 — the trailing args
						// were previously (0, true), which cast true to
						// priority 1 instead of the intended 10.
						as_enqueue_async_action(
							'otsw_rebuild_index_batch',
							array(
								'last_id' => $last_id,
								'epoch'   => $epoch,
							),
							'ozulabs-turbo-search-for-woocommerce',
							false,
							10
						);
						$recovering = true;
						$cursor     = $last_id;
						update_option( 'otsw_rebuild_phase', 'batching', false );
					}
					$stall_secs = 0;
				}
			}

			// Detect fully stuck rebuilds: flag says indexing but no pending OR
			// in-progress batch exists at all. A genuine completion already
			// clears otsw_is_indexing itself (see do_process_batch()'s SWAP
			// branch) before this code can run, so reaching here with
			// $is_indexing still true always means something went wrong —
			// most often the very first as_enqueue_async_action() call for
			// this epoch never landed (e.g. the request ran out of memory
			// right after schedule_full_rebuild() logged the new epoch but
			// before the insert completed; see class-indexer.php's
			// dynamic_batch_size() memory notes).
			//
			// Self-heal with a bounded resume rather than silently reporting
			// "done" when no work ever actually ran: retry up to 3 times per
			// epoch, then give up and surface a real error instead of
			// leaving the site owner believing the rebuild succeeded.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$active = (bool) $wpdb->get_var( $wpdb->prepare(
				"SELECT 1 FROM {$wpdb->prefix}actionscheduler_actions
				 WHERE hook   = %s
				   AND status IN ('pending','in-progress')
				 LIMIT 1",
				'otsw_rebuild_index_batch'
			) );
			if ( ! $active ) {
				$stuck_epoch = (int) get_option( 'otsw_rebuild_epoch', 0 );
				$retry_key   = 'otsw_batch_retry_missing_' . $stuck_epoch;
				$attempts    = (int) get_transient( $retry_key );

				if ( $stuck_epoch && $attempts < 3 && function_exists( 'as_enqueue_async_action' ) ) {
					$resume_from = (int) get_option( 'otsw_rebuild_cursor', 0 );
					set_transient( $retry_key, $attempts + 1, HOUR_IN_SECONDS );
					// $unique=false, $priority=10 — the trailing args were
					// previously (0, true), which cast true to priority 1
					// instead of the intended 10.
					as_enqueue_async_action(
						'otsw_rebuild_index_batch',
						array(
							'last_id' => $resume_from,
							'epoch'   => $stuck_epoch,
						),
						'ozulabs-turbo-search-for-woocommerce',
						false,
						10
					);
					Logger::log( sprintf( 'No batch ever dispatched for epoch=%d — resuming from cursor=%d (attempt %d/3)', $stuck_epoch, $resume_from, $attempts + 1 ) );
					$recovering = true;
					$cursor     = $resume_from;
					update_option( 'otsw_rebuild_phase', 'batching', false );
				} else {
					if ( $stuck_epoch ) {
						Logger::log( sprintf( 'Resume attempts exhausted for epoch=%d — halting', $stuck_epoch ), 'warning' );
						update_option( 'otsw_last_rebuild_error', 'stuck_no_batch_dispatched', false );
					}
					update_option( 'otsw_is_indexing', 0, false );
					delete_option( 'otsw_rebuild_phase' );
					$is_indexing = false;
				}
			}

			if ( $is_indexing ) {
				$phase  = get_option( 'otsw_rebuild_phase', 'batching' );
				$cursor = $cursor ? $cursor : (int) get_option( 'otsw_rebuild_cursor', 0 );
			}
		}

		$processed = (int) get_option( 'otsw_reindex_processed', 0 );
		$total     = 0;
		$counts    = wp_count_posts( 'product' );
		if ( isset( $counts->publish ) ) {
			$total = (int) $counts->publish;
		}
		// Cap: retried batches can double-count products, making processed > total.
		$processed = min( $processed, $total );

		// Only surface an error while the index is genuinely idle — a fresh
		// rebuild trigger clears this option, so a lingering value here always
		// reflects the current (not some earlier) idle state.
		$last_error = $is_indexing ? '' : (string) get_option( 'otsw_last_rebuild_error', '' );

		// Pre-rendered exactly like the "Last successful index" line in
		// tab-settings.php's own initial page render (same human_time_diff()
		// call and translation string) — the JS side just swaps this text in
		// rather than reimplementing the relative-time formatting itself.
		// Polling only runs while indexing, so this is what actually moves
		// "23 seconds ago" forward the moment a rebuild finishes; without it
		// the line was frozen at whatever it said on the last full page load.
		$last_indexed_ts    = (int) get_option( 'otsw_last_indexed', 0 );
		$last_indexed_label = $last_indexed_ts > 0
			? sprintf(
				/* translators: %s: human-readable time ago string */
				__( 'Last successful index: %s ago', 'ozulabs-turbo-search-for-woocommerce' ),
				human_time_diff( $last_indexed_ts )
			)
			: __( 'Last successful index: never', 'ozulabs-turbo-search-for-woocommerce' );

		wp_send_json_success( array(
			'is_indexing'        => $is_indexing,
			'processed'          => $processed,
			'total'              => $total,
			'last_indexed_label' => $last_indexed_label,
			'phase'              => $phase,
			'cursor'             => $cursor,
			'recovering'         => $recovering,
			'stall_secs'         => $stall_secs,
			'last_error'         => $last_error,
		) );
	}
}
