<?php
declare(strict_types=1);

/**
 * Admin settings and dashboard.
 *
 * @package WP_Fast_Search
 */

namespace WCS\Search;

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
		add_action( 'wp_ajax_wcs_dismiss_notice', array( __CLASS__, 'ajax_dismiss_notice' ) );
		add_action( 'wp_ajax_wcs_rebuild_index', array( __CLASS__, 'ajax_rebuild_index' ) );
		add_action( 'wp_ajax_wcs_get_index_status', array( __CLASS__, 'ajax_get_index_status' ) );
		add_action( 'wp_ajax_wcs_delete_all_data', array( __CLASS__, 'ajax_delete_all_data' ) );
		add_filter( 'plugin_action_links_' . WCS_PLUGIN_BASENAME, array( __CLASS__, 'add_plugin_action_links' ) );
	}

	public static function ajax_dismiss_notice(): void {
		check_ajax_referer( 'wcs_dismiss_notice' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}

		$notice_id = isset( $_POST['notice_id'] ) ? sanitize_key( $_POST['notice_id'] ) : '';

		$allowed = array( 'wcs_notice_mu_bypass', 'wcs_notice_no_cache' );
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
		if ( ! $screen || 'settings_page_wcs-fast-search' !== $screen->id ) {
			return;
		}

		$user_id = get_current_user_id();

		// ── Notice -1: schema creation failed (e.g. MySQL rejected the DDL) ──
		$schema_error = (string) get_option( 'wcs_schema_error', '' );
		if ( $schema_error ) {
			?>
			<div class="notice notice-error">
				<p>
					<strong><?php esc_html_e( 'Turbo Search for WooCommerce — Search Index Table Could Not Be Created', 'turbo-search-for-woocommerce' ); ?></strong>
				</p>
				<p>
					<?php esc_html_e( 'The database rejected the search index schema, so search results will be empty. Database error:', 'turbo-search-for-woocommerce' ); ?>
					<code><?php echo esc_html( $schema_error ); ?></code>
				</p>
				<p><em><?php esc_html_e( 'Please share this error with support@ozulabs.com. Deactivating and reactivating the plugin retries table creation.', 'turbo-search-for-woocommerce' ); ?></em></p>
			</div>
			<?php
		}

		// ── Notice 0: Action Scheduler not available ─────────────────────────
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			?>
			<div class="notice notice-error">
				<p>
					<strong><?php esc_html_e( 'Turbo Search for WooCommerce — Action Scheduler Not Found', 'turbo-search-for-woocommerce' ); ?></strong>
				</p>
				<p>
					<?php esc_html_e( 'Turbo Search for WooCommerce requires Action Scheduler to queue background indexing jobs. Action Scheduler is bundled with WooCommerce — please ensure WooCommerce is active. Without it, product indexing and live sync will not run.', 'turbo-search-for-woocommerce' ); ?>
				</p>
			</div>
			<?php
		}

		// ── Notice 1: MU plugin not installed ────────────────────────────────
		$mu_dest          = trailingslashit( WPMU_PLUGIN_DIR ) . 'wcs-cache-bypass.php';
		if ( ! file_exists( $mu_dest ) && ! get_user_meta( $user_id, 'wcs_notice_mu_bypass_dismissed', true ) ) {
			?>
			<div class="notice notice-warning is-dismissible" data-wcs-notice="wcs_notice_mu_bypass">
				<p>
					<strong><?php esc_html_e( 'Turbo Search for WooCommerce — Cache Bypass Not Active', 'turbo-search-for-woocommerce' ); ?></strong>
				</p>
				<p>
					<?php
					esc_html_e(
						'The cache-bypass file could not be installed into your wp-content/mu-plugins/ directory — your host may have it set to read-only. The plugin is fully functional and serving search results, but cached responses will use the standard WordPress REST route instead of the faster early-exit path.',
						'turbo-search-for-woocommerce'
					);
					?>
				</p>
				<p><em><?php esc_html_e( 'To unlock maximum speed: ask your host to allow writes to wp-content/mu-plugins/, then deactivate and reactivate Turbo Search for WooCommerce.', 'turbo-search-for-woocommerce' ); ?></em></p>
			</div>
			<?php
		}

		// ── Notice 2: No persistent object cache ─────────────────────────────
		if ( ! wp_using_ext_object_cache() && ! get_user_meta( $user_id, 'wcs_notice_no_cache_dismissed', true ) ) {
			?>
			<div class="notice notice-info is-dismissible" data-wcs-notice="wcs_notice_no_cache">
				<p>
					<strong><?php esc_html_e( 'Turbo Search for WooCommerce — Tip: Enable a Persistent Object Cache', 'turbo-search-for-woocommerce' ); ?></strong>
				</p>
				<p>
					<?php
					esc_html_e(
						'Your site is currently caching search results in the database (wp_options). Everything works correctly, but adding a Redis or Memcached object cache will make cached search queries return in under 5 ms instead of a database round-trip.',
						'turbo-search-for-woocommerce'
					);
					?>
				</p>
				<p><em><?php esc_html_e( 'Recommended: install the free "Redis Object Cache" plugin and enable Redis on your hosting plan.', 'turbo-search-for-woocommerce' ); ?></em></p>
			</div>
			<?php
		}

		// Dismiss persistence is handled by assets/js/admin.js (enqueued on this
		// screen), which reads the nonce from the wcsAdmin config object.
	}

	/**
	 * Add Settings link to the plugin row.
	 *
	 * @param array $links Array of plugin action links.
	 * @return array
	 */
	public static function add_plugin_action_links( array $links ): array {
		$settings_url  = admin_url( 'options-general.php?page=wcs-fast-search' );
		$settings_link = '<a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Settings', 'turbo-search-for-woocommerce' ) . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/**
	 * Add menu page.
	 *
	 */
	public static function add_settings_page(): void {
		add_options_page(
			esc_html__( 'Turbo Search Settings', 'turbo-search-for-woocommerce' ),
			esc_html__( 'Turbo Search', 'turbo-search-for-woocommerce' ),
			'manage_options',
			'wcs-fast-search',
			array( __CLASS__, 'render_settings_page' )
		);
	}

	/**
	 * Register settings.
	 */
	public static function register_settings(): void {
		register_setting( 'wcs_settings_group', 'wcs_result_count', array(
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => 6,
		) );
		register_setting( 'wcs_settings_group', 'wcs_show_out_of_stock', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => true,
		) );
		register_setting( 'wcs_settings_group', 'wcs_min_chars', array(
			'type'              => 'integer',
			'sanitize_callback' => static function( $value ): int {
				// Clamp to the same 1-10 range as the settings field. An
				// unclamped high value would silently disable search entirely.
				return min( 10, max( 1, absint( $value ) ) );
			},
			'default'           => 2,
		) );
		register_setting( 'wcs_settings_group', 'wcs_search_title', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => true,
		) );
		register_setting( 'wcs_settings_group', 'wcs_search_sku', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => true,
		) );
		register_setting( 'wcs_settings_group', 'wcs_search_content', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => true,
		) );
		register_setting( 'wcs_settings_group', 'wcs_search_taxonomy', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => true,
		) );
		register_setting( 'wcs_settings_group', 'wcs_delete_data_on_uninstall', array(
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		) );
	}

	/**
	 * Enqueue the settings-page stylesheet and controller script.
	 *
	 * Loaded only on our own screen. All nonces and translatable strings the
	 * JS needs are passed via the wcsAdmin config object — the page markup
	 * itself (in includes/views/) contains no inline scripts or styles.
	 *
	 * @param string $hook_suffix Current admin page hook.
	 */
	public static function enqueue_admin_assets( string $hook_suffix ): void {
		if ( 'settings_page_wcs-fast-search' !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'wcs-admin-css', WCS_PLUGIN_URL . 'assets/css/admin.css', array(), WCS_VERSION );
		wp_enqueue_script( 'wcs-admin-js', WCS_PLUGIN_URL . 'assets/js/admin.js', array(), WCS_VERSION, true );

		$config = array(
			'isIndexing' => (bool) get_option( 'wcs_is_indexing', false ),
			'nonces'     => array(
				'status'  => wp_create_nonce( 'wcs_status' ),
				'rebuild' => wp_create_nonce( 'wcs_rebuild' ),
				'delete'  => wp_create_nonce( 'wcs_delete_all_data' ),
				'dismiss' => wp_create_nonce( 'wcs_dismiss_notice' ),
			),
			'i18n'       => array(
				/* translators: 1: number of processed products, 2: total number of published products */
				'progress'       => __( 'Processed %1$d of %2$d published products.', 'turbo-search-for-woocommerce' ),
				'idle'           => __( 'Status: Idle / Complete', 'turbo-search-for-woocommerce' ),
				'indexing'       => __( 'Status: Indexing…', 'turbo-search-for-woocommerce' ),
				'swapping'       => __( 'Status: Finalizing — swapping live index…', 'turbo-search-for-woocommerce' ),
				'optimizing'     => __( 'Status: Finalizing — optimizing index…', 'turbo-search-for-woocommerce' ),
				/* translators: %d: product ID the rebuild is retrying from */
				'recovering'     => __( 'Status: Recovering — retrying from product #%d…', 'turbo-search-for-woocommerce' ),
				/* translators: %d: seconds until automatic recovery */
				'timedOut'       => __( 'Status: Batch timed out — auto-recovering in %ds…', 'turbo-search-for-woocommerce' ),
				'errRebuild'     => __( 'Error triggering rebuild.', 'turbo-search-for-woocommerce' ),
				'errDelete'      => __( 'Error deleting plugin data.', 'turbo-search-for-woocommerce' ),
				'confirmRebuild' => __( 'Are you sure you want to rebuild the entire search index? This will run in the background.', 'turbo-search-for-woocommerce' ),
				'confirmDelete'  => __( 'This will permanently delete all plugin data including the search index, all settings, and cached results. The plugin stays active but you will need to rebuild the index afterwards. Are you absolutely sure?', 'turbo-search-for-woocommerce' ),
			),
			'errorLabels' => self::rebuild_error_labels(),
		);
		wp_add_inline_script( 'wcs-admin-js', 'const wcsAdmin = ' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Human-readable text for known wcs_last_rebuild_error codes. Shared
	 * between the initial server-rendered page (tab-settings.php) and the
	 * live AJAX status poll (assets/js/admin.js) so the two never drift.
	 *
	 * @return array<string, string> Error code => translated message.
	 */
	public static function rebuild_error_labels(): array {
		return array(
			'stuck_no_batch_dispatched' => __( 'The last rebuild could not start — this usually means the server ran out of memory partway through. Click "Rebuild Index" to try again; consider lowering the batch size via the wcs_batch_size filter if this keeps happening.', 'turbo-search-for-woocommerce' ),
			'staging_empty'             => __( 'The last rebuild produced no data and was discarded — your existing search index was kept. Click "Rebuild Index" to try again.', 'turbo-search-for-woocommerce' ),
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
		if ( ! in_array( $active_tab, array( 'settings', 'docs' ), true ) ) {
			$active_tab = 'settings';
		}

		$is_indexing  = (bool) get_option( 'wcs_is_indexing', false );
		$last_indexed = (int) get_option( 'wcs_last_indexed', 0 );
		// Only shown while idle — a fresh rebuild trigger clears this option,
		// so a lingering value always reflects the current idle state.
		$last_rebuild_error = $is_indexing ? '' : (string) get_option( 'wcs_last_rebuild_error', '' );
		$total        = 0;
		$counts       = wp_count_posts( 'product' );
		if ( isset( $counts->publish ) ) {
			$total = (int) $counts->publish;
		}
		$processed = min( (int) get_option( 'wcs_reindex_processed', 0 ), max( 1, $total ) );

		// Zero-result search analytics is a Pro feature — this edition never
		// logs or displays them.
		$zero_hits = array();

		// Markup lives in view templates; behaviour in assets/js/admin.js
		// (enqueued by enqueue_admin_assets). $active_tab, $is_indexing,
		// $last_indexed, $last_rebuild_error, $total, $processed, $zero_hits
		// are consumed by the views.
		include WCS_PLUGIN_DIR . 'includes/views/settings-page.php';
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
		check_ajax_referer( 'wcs_delete_all_data' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}

		global $wpdb;

		$main_table  = $wpdb->prefix . 'wcs_search_index';
		$stage_table = $wpdb->prefix . 'wcs_search_index_stage';

		// Drop the index tables. (The zero-result log and vocabulary sidecar are
		// Pro-only tables this edition never creates.)
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $main_table ) );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $stage_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

		// Delete plugin options via the API (invalidates object-cache entries too).
		// Explicit list — a broad LIKE 'wcs_%' would also delete WooCommerce
		// Subscriptions' options, which share the wcs_ prefix.
		foreach ( Activator::PLUGIN_OPTIONS as $option ) {
			delete_option( $option );
		}

		// Delete plugin transients by our exact key shapes — never
		// '_transient_wcs_%', which matches WC Subscriptions' wcs_report_* transients.
		foreach ( Activator::TRANSIENT_PREFIXES as $prefix ) {
			$wpdb->query( $wpdb->prepare( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $prefix ) . '%',
				$wpdb->esc_like( '_transient_timeout_' . $prefix ) . '%'
			) );
		}

		// Cancel any pending Action Scheduler jobs.
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( null, array(), 'turbo-search-for-woocommerce' );
		}

		// Flush the external object cache (Redis/Memcached) so that stale wcs_ option
		// values don't linger after the direct SQL DELETE above. Without this flush,
		// update_option('wcs_db_version') silently fails on every subsequent page load
		// because the cache claims the old value still exists, causing create_tables()
		// to run on every request and blocking PHP-FPM workers.
		wp_cache_flush();

		// Invalidate OPcache entries for this plugin's files only — not the whole
		// server — so stale bytecode doesn't outlive the data reset.
		if ( function_exists( 'opcache_invalidate' ) ) {
			foreach ( glob( WCS_PLUGIN_DIR . 'includes/*.php' ) ?: array() as $file ) {
				opcache_invalidate( $file, true );
			}
			opcache_invalidate( WCS_PLUGIN_DIR . 'turbo-search-for-woocommerce.php', true );
		}

		// The index table will be recreated on the next page load via Activator::init().

		wp_send_json_success();
	}

	/**
	 * AJAX handler for rebuilding index.
	 */
	public static function ajax_rebuild_index(): void {
		check_ajax_referer( 'wcs_rebuild' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}

		global $wpdb;
		$main_table  = $wpdb->prefix . 'wcs_search_index';
		$stage_table = $wpdb->prefix . 'wcs_search_index_stage';

		// Create the staging table matching the live index schema. (The
		// vocabulary sidecar staging table is Pro-only — this edition never
		// creates wcs_search_terms in the first place.)
		$wpdb->query( $wpdb->prepare( 'CREATE TABLE IF NOT EXISTS %i LIKE %i', $stage_table, $main_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( $wpdb->prepare( 'TRUNCATE TABLE %i', $stage_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange

		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			wp_send_json_error( esc_html__( 'Action Scheduler is not available. Please ensure WooCommerce is active.', 'turbo-search-for-woocommerce' ), 503 );
			return;
		}

		// Cancel every pending/in-progress batch before starting fresh.
		as_unschedule_all_actions( 'wcs_rebuild_index_batch', array(), 'turbo-search-for-woocommerce' );

		// Millisecond precision — see Indexer::schedule_full_rebuild().
		$epoch = (int) ( microtime( true ) * 1000 );
		update_option( 'wcs_rebuild_epoch', $epoch, false );
		update_option( 'wcs_reindex_processed', 0, false );
		update_option( 'wcs_is_indexing', 1, false );
		delete_option( 'wcs_last_rebuild_error' );

		as_enqueue_async_action( 'wcs_rebuild_index_batch', array( 'last_id' => 0, 'epoch' => $epoch ), 'turbo-search-for-woocommerce', 0, true );

		wp_send_json_success();
	}

	/**
	 * AJAX handler for getting index status.
	 */
	public static function ajax_get_index_status(): void {
		check_ajax_referer( 'wcs_status' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Forbidden', 403 );
		}

		$is_indexing = (bool) get_option( 'wcs_is_indexing', false );

		$recovering   = false;
		$stall_secs   = 0;
		$phase        = '';
		$cursor       = 0;

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
				'wcs_rebuild_index_batch'
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
					if ( $marked && $epoch && $epoch === (int) get_option( 'wcs_rebuild_epoch', 0 ) ) {
						$last_id = (int) ( $args['last_id'] ?? 0 );
						as_enqueue_async_action(
							'wcs_rebuild_index_batch',
							array( 'last_id' => $last_id, 'epoch' => $epoch ),
							'turbo-search-for-woocommerce',
							0,
							true
						);
						$recovering = true;
						$cursor     = $last_id;
						update_option( 'wcs_rebuild_phase', 'batching', false );
					}
					$stall_secs = 0;
				}
			}

			// Detect fully stuck rebuilds: flag says indexing but no pending OR
			// in-progress batch exists at all. A genuine completion already
			// clears wcs_is_indexing itself (see do_process_batch()'s SWAP
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
				'wcs_rebuild_index_batch'
			) );
			if ( ! $active ) {
				$stuck_epoch = (int) get_option( 'wcs_rebuild_epoch', 0 );
				$retry_key   = 'wcs_batch_retry_missing_' . $stuck_epoch;
				$attempts    = (int) get_transient( $retry_key );

				if ( $stuck_epoch && $attempts < 3 && function_exists( 'as_enqueue_async_action' ) ) {
					$resume_from = (int) get_option( 'wcs_rebuild_cursor', 0 );
					set_transient( $retry_key, $attempts + 1, HOUR_IN_SECONDS );
					as_enqueue_async_action(
						'wcs_rebuild_index_batch',
						array( 'last_id' => $resume_from, 'epoch' => $stuck_epoch ),
						'turbo-search-for-woocommerce',
						0,
						true
					);
					Logger::log( sprintf( 'No batch ever dispatched for epoch=%d — resuming from cursor=%d (attempt %d/3)', $stuck_epoch, $resume_from, $attempts + 1 ) );
					$recovering = true;
					$cursor     = $resume_from;
					update_option( 'wcs_rebuild_phase', 'batching', false );
				} else {
					if ( $stuck_epoch ) {
						Logger::log( sprintf( 'Resume attempts exhausted for epoch=%d — halting', $stuck_epoch ), 'warning' );
						update_option( 'wcs_last_rebuild_error', 'stuck_no_batch_dispatched', false );
					}
					update_option( 'wcs_is_indexing', 0, false );
					delete_option( 'wcs_rebuild_phase' );
					$is_indexing = false;
				}
			}

			if ( $is_indexing ) {
				$phase  = get_option( 'wcs_rebuild_phase', 'batching' );
				$cursor = $cursor ?: (int) get_option( 'wcs_rebuild_cursor', 0 );
			}
		}

		$processed = (int) get_option( 'wcs_reindex_processed', 0 );
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
		$last_error = $is_indexing ? '' : (string) get_option( 'wcs_last_rebuild_error', '' );

		wp_send_json_success( array(
			'is_indexing'  => $is_indexing,
			'processed'    => $processed,
			'total'        => $total,
			'phase'        => $phase,
			'cursor'       => $cursor,
			'recovering'   => $recovering,
			'stall_secs'   => $stall_secs,
			'last_error'   => $last_error,
		) );
	}
}
