<?php
/**
 * Settings tab: index status card, shortcode hint, settings form, danger zone.
 *
 * Included from settings-page.php. Variables in scope:
 *
 * @var bool   $is_indexing        Whether a rebuild is currently running.
 * @var int    $last_indexed       Timestamp of the last successful index (0 = never).
 * @var string $last_rebuild_error Non-empty error code when idle after a failed rebuild.
 * @var int    $total              Published product count.
 * @var int    $processed          Products processed in the current/last rebuild.
 *
 * @package OzuLabs_Turbo_Search_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="card" style="max-width: 600px; margin-top: 20px;">
	<h2><?php esc_html_e( 'Index Status', 'ozulabs-turbo-search-for-woocommerce' ); ?></h2>
	<p id="wcs-status-wrapper">
		<?php if ( $is_indexing ) : ?>
			<span style="color: #d63638; font-weight: bold;">
			<?php
				$otsw_phase = get_option( 'otsw_rebuild_phase', 'batching' );
			if ( 'swapping' === $otsw_phase ) {
				esc_html_e( 'Status: Finalizing — swapping live index…', 'ozulabs-turbo-search-for-woocommerce' );
			} elseif ( 'optimizing' === $otsw_phase ) {
				esc_html_e( 'Status: Finalizing — optimizing index…', 'ozulabs-turbo-search-for-woocommerce' );
			} else {
				esc_html_e( 'Status: Indexing…', 'ozulabs-turbo-search-for-woocommerce' );
			}
			?>
			</span>
		<?php else : ?>
			<span style="color: #00a32a; font-weight: bold;"><?php esc_html_e( 'Status: Idle / Complete', 'ozulabs-turbo-search-for-woocommerce' ); ?></span>
		<?php endif; ?>
	</p>
	<p id="wcs-progress-wrapper">
		<?php
		/* translators: 1: number of processed products, 2: total number of published products */
		echo esc_html( sprintf( __( 'Processed %1$d of %2$d published products.', 'ozulabs-turbo-search-for-woocommerce' ), $processed, $total ) );
		?>
	</p>
	<p id="wcs-last-indexed">
		<?php if ( $last_indexed > 0 ) : ?>
			<?php
			/* translators: %s: human-readable time ago string */
			echo esc_html( sprintf( __( 'Last successful index: %s ago', 'ozulabs-turbo-search-for-woocommerce' ), human_time_diff( $last_indexed ) ) );
			?>
		<?php else : ?>
			<?php esc_html_e( 'Last successful index: never', 'ozulabs-turbo-search-for-woocommerce' ); ?>
		<?php endif; ?>
	</p>
	<p id="wcs-rebuild-error" style="<?php echo $last_rebuild_error ? '' : 'display:none;'; ?> color:#d63638;">
		<?php if ( $last_rebuild_error ) : ?>
			<?php
			// Unrecognized codes still render (as the raw code) rather than
			// silently hiding a real failure.
			$otsw_rebuild_error_labels = \OTSW\Search\Admin_Settings::rebuild_error_labels();
			echo esc_html( $otsw_rebuild_error_labels[ $last_rebuild_error ] ?? $last_rebuild_error );
			?>
		<?php endif; ?>
	</p>
	<button id="wcs-rebuild-btn" class="button button-secondary" <?php disabled( $is_indexing ); ?>>
		<?php esc_html_e( 'Rebuild Index', 'ozulabs-turbo-search-for-woocommerce' ); ?>
	</button>
	<span id="wcs-rebuild-spinner" class="spinner <?php echo $is_indexing ? 'is-active' : ''; ?>"></span>
</div>

<div style="margin-top: 20px; padding: 12px 16px; background: #f0f6fc; border-left: 4px solid #2563eb; border-radius: 0 4px 4px 0; font-size: 13px; line-height: 1.6;">
	<strong><?php esc_html_e( 'Search form not showing on your site?', 'ozulabs-turbo-search-for-woocommerce' ); ?></strong>
	<?php esc_html_e( 'If your theme uses a custom search widget, use the shortcode below to place Turbo Search anywhere — a page, widget, or Elementor/WPBakery block:', 'ozulabs-turbo-search-for-woocommerce' ); ?>
	<code style="display:inline-block; margin: 6px 0 2px; padding: 4px 10px; background: #fff; border: 1px solid #c3d4e8; border-radius: 4px; font-size: 13px; user-select: all;">[turbo_search]</code>
	<span style="color:#555; margin-left: 8px;"><?php esc_html_e( 'Optional:', 'ozulabs-turbo-search-for-woocommerce' ); ?> <code>placeholder="…"</code> &nbsp;<code>button="Go"</code></span>
	&mdash; <a href="?page=otsw-fast-search&tab=docs#search-form-setup"><?php esc_html_e( 'full instructions', 'ozulabs-turbo-search-for-woocommerce' ); ?></a>
</div>

<form method="post" action="options.php" style="margin-top: 20px;">
	<?php settings_fields( 'otsw_settings_group' ); ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">
				<label for="otsw_result_count"><?php esc_html_e( 'Results Count', 'ozulabs-turbo-search-for-woocommerce' ); ?></label>
				<div class="wcs-tooltip">
					<span class="wcs-tooltip-icon">?</span>
					<span class="wcs-tooltip-text"><?php esc_html_e( 'The maximum number of matches shown to users in the live dropdown panel.', 'ozulabs-turbo-search-for-woocommerce' ); ?></span>
				</div>
			</th>
			<td>
				<input name="otsw_result_count" type="number" id="otsw_result_count" value="<?php echo esc_attr( (string) get_option( 'otsw_result_count', 6 ) ); ?>" class="small-text" min="1" max="20" />
				<p class="description"><?php esc_html_e( 'Number of items to show in the search dropdown.', 'ozulabs-turbo-search-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="otsw_min_chars"><?php esc_html_e( 'Minimum Characters', 'ozulabs-turbo-search-for-woocommerce' ); ?></label>
				<div class="wcs-tooltip">
					<span class="wcs-tooltip-icon">?</span>
					<span class="wcs-tooltip-text"><?php esc_html_e( 'The minimum number of characters typed in the search field before triggering auto-complete search.', 'ozulabs-turbo-search-for-woocommerce' ); ?></span>
				</div>
			</th>
			<td>
				<input name="otsw_min_chars" type="number" id="otsw_min_chars" value="<?php echo esc_attr( (string) get_option( 'otsw_min_chars', 2 ) ); ?>" class="small-text" min="1" max="10" />
				<p class="description"><?php esc_html_e( 'Triggers the search dropdown after this many characters.', 'ozulabs-turbo-search-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<?php esc_html_e( 'Rate Limiting', 'ozulabs-turbo-search-for-woocommerce' ); ?>
				<div class="wcs-tooltip">
					<span class="wcs-tooltip-icon">?</span>
					<span class="wcs-tooltip-text"><?php esc_html_e( 'Caps how many searches a single visitor can make. The stricter limit applies only to searches that find nothing and fall through every fallback the plugin tries — the most expensive kind of request, and the shape a scripted flood would use to run up load. A normal shopper never notices either limit.', 'ozulabs-turbo-search-for-woocommerce' ); ?></span>
				</div>
			</th>
			<td>
				<p>
					<label for="otsw_rate_limit_requests" style="display:inline-block; min-width: 150px;"><?php esc_html_e( 'Searches per visitor', 'ozulabs-turbo-search-for-woocommerce' ); ?></label>
					<input name="otsw_rate_limit_requests" type="number" id="otsw_rate_limit_requests" value="<?php echo esc_attr( (string) get_option( 'otsw_rate_limit_requests', 60 ) ); ?>" class="small-text" min="5" max="1000" />
					<?php esc_html_e( 'per', 'ozulabs-turbo-search-for-woocommerce' ); ?>
					<input name="otsw_rate_limit_window" type="number" id="otsw_rate_limit_window" value="<?php echo esc_attr( (string) get_option( 'otsw_rate_limit_window', 60 ) ); ?>" class="small-text" min="10" max="3600" />
					<?php esc_html_e( 'seconds', 'ozulabs-turbo-search-for-woocommerce' ); ?>
				</p>
				<p style="margin-top:8px;">
					<label for="otsw_fallback_rate_limit_requests" style="display:inline-block; min-width: 150px;"><?php esc_html_e( 'Zero-result fallback searches per visitor', 'ozulabs-turbo-search-for-woocommerce' ); ?></label>
					<input name="otsw_fallback_rate_limit_requests" type="number" id="otsw_fallback_rate_limit_requests" value="<?php echo esc_attr( (string) get_option( 'otsw_fallback_rate_limit_requests', 10 ) ); ?>" class="small-text" min="1" max="1000" />
					<?php esc_html_e( 'per', 'ozulabs-turbo-search-for-woocommerce' ); ?>
					<input name="otsw_fallback_rate_limit_window" type="number" id="otsw_fallback_rate_limit_window" value="<?php echo esc_attr( (string) get_option( 'otsw_fallback_rate_limit_window', 60 ) ); ?>" class="small-text" min="10" max="3600" />
					<?php esc_html_e( 'seconds', 'ozulabs-turbo-search-for-woocommerce' ); ?>
				</p>
				<p class="description"><?php esc_html_e( 'Applies per visitor, not sitewide — a burst from one bot or IP never affects other shoppers.', 'ozulabs-turbo-search-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<?php esc_html_e( 'Recent Searches', 'ozulabs-turbo-search-for-woocommerce' ); ?>
				<div class="wcs-tooltip">
					<span class="wcs-tooltip-icon">?</span>
					<span class="wcs-tooltip-text"><?php esc_html_e( 'Remembers each shopper\'s own past searches in their browser (not shared between shoppers, and not sent to your server) and offers them again when the search box is focused empty.', 'ozulabs-turbo-search-for-woocommerce' ); ?></span>
				</div>
			</th>
			<td>
				<fieldset>
					<label for="otsw_enable_recent_searches">
						<input name="otsw_enable_recent_searches" type="checkbox" id="otsw_enable_recent_searches" value="1" <?php checked( 1, (int) get_option( 'otsw_enable_recent_searches', 1 ), true ); ?> />
						<?php esc_html_e( 'Show a shopper\'s recent searches when they focus an empty search box.', 'ozulabs-turbo-search-for-woocommerce' ); ?>
					</label>
				</fieldset>
				<p style="margin-top:8px;">
					<label for="otsw_recent_searches_count" style="display:inline-block; min-width: 150px;"><?php esc_html_e( 'Number to remember', 'ozulabs-turbo-search-for-woocommerce' ); ?></label>
					<input name="otsw_recent_searches_count" type="number" id="otsw_recent_searches_count" value="<?php echo esc_attr( (string) get_option( 'otsw_recent_searches_count', 5 ) ); ?>" class="small-text" min="1" max="10" />
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<?php esc_html_e( 'Out of Stock Products', 'ozulabs-turbo-search-for-woocommerce' ); ?>
				<div class="wcs-tooltip">
					<span class="wcs-tooltip-icon">?</span>
					<span class="wcs-tooltip-text"><?php esc_html_e( 'Toggle to show or hide products that are currently out of stock from search results.', 'ozulabs-turbo-search-for-woocommerce' ); ?></span>
				</div>
			</th>
			<td>
				<fieldset>
					<label for="otsw_show_out_of_stock">
						<input name="otsw_show_out_of_stock" type="checkbox" id="otsw_show_out_of_stock" value="1" <?php checked( 1, (int) get_option( 'otsw_show_out_of_stock', 1 ), true ); ?> />
						<?php esc_html_e( 'Show out of stock products in search results.', 'ozulabs-turbo-search-for-woocommerce' ); ?>
					</label>
				</fieldset>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<?php esc_html_e( 'Search Fields Weighting', 'ozulabs-turbo-search-for-woocommerce' ); ?>
				<div class="wcs-tooltip">
					<span class="wcs-tooltip-icon">?</span>
					<span class="wcs-tooltip-text"><?php esc_html_e( 'Select which fields are compiled into the search index. Unchecking unused fields optimizes match quality and speed. Note: You must rebuild the index after modifying these.', 'ozulabs-turbo-search-for-woocommerce' ); ?></span>
				</div>
			</th>
			<td>
				<fieldset>
					<p>
						<label for="otsw_search_title">
							<input name="otsw_search_title" type="checkbox" id="otsw_search_title" value="1" <?php checked( 1, (int) get_option( 'otsw_search_title', 1 ), true ); ?> />
							<?php esc_html_e( 'Product Title', 'ozulabs-turbo-search-for-woocommerce' ); ?>
						</label>
					</p>
					<p>
						<label for="otsw_search_sku">
							<input name="otsw_search_sku" type="checkbox" id="otsw_search_sku" value="1" <?php checked( 1, (int) get_option( 'otsw_search_sku', 1 ), true ); ?> />
							<?php esc_html_e( 'Product SKU', 'ozulabs-turbo-search-for-woocommerce' ); ?>
						</label>
					</p>
					<p>
						<label for="otsw_search_content">
							<input name="otsw_search_content" type="checkbox" id="otsw_search_content" value="1" <?php checked( 1, (int) get_option( 'otsw_search_content', 1 ), true ); ?> />
							<?php esc_html_e( 'Short Description / Content', 'ozulabs-turbo-search-for-woocommerce' ); ?>
						</label>
					</p>
					<p>
						<label for="otsw_search_taxonomy">
							<input name="otsw_search_taxonomy" type="checkbox" id="otsw_search_taxonomy" value="1" <?php checked( 1, (int) get_option( 'otsw_search_taxonomy', 1 ), true ); ?> />
							<?php esc_html_e( 'Product Categories & Tags', 'ozulabs-turbo-search-for-woocommerce' ); ?>
						</label>
					</p>
				</fieldset>
			</td>
		</tr>
	</table>
	<?php submit_button(); ?>
</form>

<div class="card" style="max-width: 600px; margin-top: 20px; border-left: 4px solid #2E7D32;">
	<h2 style="margin-top:0;"><?php esc_html_e( 'Turbo Search Pro', 'ozulabs-turbo-search-for-woocommerce' ); ?></h2>
	<p><?php esc_html_e( 'Adds typo tolerance, search synonyms, category/brand suggestions, search merchandising (pin/bury/exclude/redirect), ranking-weight tuning, zero-result analytics, Quick Add to Cart, multi-currency pricing, and settings export/import — for stores that need more than the core search this edition already provides.', 'ozulabs-turbo-search-for-woocommerce' ); ?></p>
	<a href="https://ozulabs.com/plugins/turbo-search/" target="_blank" rel="noopener" class="button button-primary"><?php esc_html_e( 'Learn more about Turbo Search Pro', 'ozulabs-turbo-search-for-woocommerce' ); ?></a>
</div>
