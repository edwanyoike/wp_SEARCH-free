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
 * @package WP_Fast_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="card" style="max-width: 600px; margin-top: 20px;">
	<h2><?php esc_html_e( 'Index Status', 'turbo-search-for-woocommerce' ); ?></h2>
	<p id="wcs-status-wrapper">
		<?php if ( $is_indexing ) : ?>
			<span style="color: #d63638; font-weight: bold;"><?php
				$_phase = get_option( 'wcs_rebuild_phase', 'batching' );
				if ( 'swapping' === $_phase ) {
					esc_html_e( 'Status: Finalizing — swapping live index…', 'turbo-search-for-woocommerce' );
				} elseif ( 'optimizing' === $_phase ) {
					esc_html_e( 'Status: Finalizing — optimizing index…', 'turbo-search-for-woocommerce' );
				} else {
					esc_html_e( 'Status: Indexing…', 'turbo-search-for-woocommerce' );
				}
			?></span>
		<?php else : ?>
			<span style="color: #00a32a; font-weight: bold;"><?php esc_html_e( 'Status: Idle / Complete', 'turbo-search-for-woocommerce' ); ?></span>
		<?php endif; ?>
	</p>
	<p id="wcs-progress-wrapper">
		<?php
		/* translators: 1: number of processed products, 2: total products */
		echo esc_html( sprintf( __( 'Processed %1$d of %2$d published products.', 'turbo-search-for-woocommerce' ), $processed, $total ) );
		?>
	</p>
	<p id="wcs-last-indexed">
		<?php if ( $last_indexed > 0 ) : ?>
			<?php
			/* translators: %s: human-readable time ago string */
			echo esc_html( sprintf( __( 'Last successful index: %s ago', 'turbo-search-for-woocommerce' ), human_time_diff( $last_indexed ) ) );
			?>
		<?php else : ?>
			<?php esc_html_e( 'Last successful index: never', 'turbo-search-for-woocommerce' ); ?>
		<?php endif; ?>
	</p>
	<p id="wcs-rebuild-error" style="<?php echo $last_rebuild_error ? '' : 'display:none;'; ?> color:#d63638;">
		<?php if ( $last_rebuild_error ) : ?>
			<?php
			// Unrecognized codes still render (as the raw code) rather than
			// silently hiding a real failure.
			$wcs_rebuild_error_labels = \WCS\Search\Admin_Settings::rebuild_error_labels();
			echo esc_html( $wcs_rebuild_error_labels[ $last_rebuild_error ] ?? $last_rebuild_error );
			?>
		<?php endif; ?>
	</p>
	<button id="wcs-rebuild-btn" class="button button-secondary" <?php disabled( $is_indexing ); ?>>
		<?php esc_html_e( 'Rebuild Index', 'turbo-search-for-woocommerce' ); ?>
	</button>
	<span id="wcs-rebuild-spinner" class="spinner <?php echo $is_indexing ? 'is-active' : ''; ?>"></span>
</div>

<div style="margin-top: 20px; padding: 12px 16px; background: #f0f6fc; border-left: 4px solid #2563eb; border-radius: 0 4px 4px 0; font-size: 13px; line-height: 1.6;">
	<strong><?php esc_html_e( 'Search form not showing on your site?', 'turbo-search-for-woocommerce' ); ?></strong>
	<?php esc_html_e( 'If your theme uses a custom search widget, use the shortcode below to place Turbo Search anywhere — a page, widget, or Elementor/WPBakery block:', 'turbo-search-for-woocommerce' ); ?>
	<code style="display:inline-block; margin: 6px 0 2px; padding: 4px 10px; background: #fff; border: 1px solid #c3d4e8; border-radius: 4px; font-size: 13px; user-select: all;">[turbo_search]</code>
	<span style="color:#555; margin-left: 8px;"><?php esc_html_e( 'Optional:', 'turbo-search-for-woocommerce' ); ?> <code>placeholder="…"</code> &nbsp;<code>button="Go"</code></span>
	&mdash; <a href="?page=wcs-fast-search&tab=docs#search-form-setup"><?php esc_html_e( 'full instructions', 'turbo-search-for-woocommerce' ); ?></a>
</div>

<?php if ( ! empty( $zero_hits ) ) : ?>
<div class="card" style="max-width: 600px; margin-top: 20px;">
	<h2><?php esc_html_e( 'Searches With No Results', 'turbo-search-for-woocommerce' ); ?></h2>
	<p><?php esc_html_e( 'Customers searched for these terms and found nothing. Add them as synonyms below, or add the words to the matching products.', 'turbo-search-for-woocommerce' ); ?></p>
	<table class="widefat striped">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Search term', 'turbo-search-for-woocommerce' ); ?></th>
				<th style="width: 90px;"><?php esc_html_e( 'Searches', 'turbo-search-for-woocommerce' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $zero_hits as $zero_row ) : ?>
			<tr>
				<td><code><?php echo esc_html( $zero_row['query'] ); ?></code></td>
				<td><?php echo esc_html( (string) (int) $zero_row['hits'] ); ?></td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
<?php endif; ?>

<form method="post" action="options.php" style="margin-top: 20px;">
	<?php settings_fields( 'wcs_settings_group' ); ?>
	<table class="form-table" role="presentation">
		<tr>
			<th scope="row">
				<label for="wcs_result_count"><?php esc_html_e( 'Results Count', 'turbo-search-for-woocommerce' ); ?></label>
				<div class="wcs-tooltip">
					<span class="wcs-tooltip-icon">?</span>
					<span class="wcs-tooltip-text"><?php esc_html_e( 'The maximum number of matches shown to users in the live dropdown panel.', 'turbo-search-for-woocommerce' ); ?></span>
				</div>
			</th>
			<td>
				<input name="wcs_result_count" type="number" id="wcs_result_count" value="<?php echo esc_attr( (string) get_option( 'wcs_result_count', 6 ) ); ?>" class="small-text" min="1" max="20" />
				<p class="description"><?php esc_html_e( 'Number of items to show in the search dropdown.', 'turbo-search-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<label for="wcs_min_chars"><?php esc_html_e( 'Minimum Characters', 'turbo-search-for-woocommerce' ); ?></label>
				<div class="wcs-tooltip">
					<span class="wcs-tooltip-icon">?</span>
					<span class="wcs-tooltip-text"><?php esc_html_e( 'The minimum number of characters typed in the search field before triggering auto-complete search.', 'turbo-search-for-woocommerce' ); ?></span>
				</div>
			</th>
			<td>
				<input name="wcs_min_chars" type="number" id="wcs_min_chars" value="<?php echo esc_attr( (string) get_option( 'wcs_min_chars', 2 ) ); ?>" class="small-text" min="1" max="10" />
				<p class="description"><?php esc_html_e( 'Triggers the search dropdown after this many characters.', 'turbo-search-for-woocommerce' ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<?php esc_html_e( 'Out of Stock Products', 'turbo-search-for-woocommerce' ); ?>
				<div class="wcs-tooltip">
					<span class="wcs-tooltip-icon">?</span>
					<span class="wcs-tooltip-text"><?php esc_html_e( 'Toggle to show or hide products that are currently out of stock from search results.', 'turbo-search-for-woocommerce' ); ?></span>
				</div>
			</th>
			<td>
				<fieldset>
					<label for="wcs_show_out_of_stock">
						<input name="wcs_show_out_of_stock" type="checkbox" id="wcs_show_out_of_stock" value="1" <?php checked( 1, (int) get_option( 'wcs_show_out_of_stock', 1 ), true ); ?> />
						<?php esc_html_e( 'Show out of stock products in search results.', 'turbo-search-for-woocommerce' ); ?>
					</label>
				</fieldset>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<?php esc_html_e( 'Search Fields Weighting', 'turbo-search-for-woocommerce' ); ?>
				<div class="wcs-tooltip">
					<span class="wcs-tooltip-icon">?</span>
					<span class="wcs-tooltip-text"><?php esc_html_e( 'Select which fields are compiled into the search index. Unchecking unused fields optimizes match quality and speed. Note: You must rebuild the index after modifying these.', 'turbo-search-for-woocommerce' ); ?></span>
				</div>
			</th>
			<td>
				<fieldset>
					<p>
						<label for="wcs_search_title">
							<input name="wcs_search_title" type="checkbox" id="wcs_search_title" value="1" <?php checked( 1, (int) get_option( 'wcs_search_title', 1 ), true ); ?> />
							<?php esc_html_e( 'Product Title', 'turbo-search-for-woocommerce' ); ?>
						</label>
					</p>
					<p>
						<label for="wcs_search_sku">
							<input name="wcs_search_sku" type="checkbox" id="wcs_search_sku" value="1" <?php checked( 1, (int) get_option( 'wcs_search_sku', 1 ), true ); ?> />
							<?php esc_html_e( 'Product SKU', 'turbo-search-for-woocommerce' ); ?>
						</label>
					</p>
					<p>
						<label for="wcs_search_content">
							<input name="wcs_search_content" type="checkbox" id="wcs_search_content" value="1" <?php checked( 1, (int) get_option( 'wcs_search_content', 1 ), true ); ?> />
							<?php esc_html_e( 'Short Description / Content', 'turbo-search-for-woocommerce' ); ?>
						</label>
					</p>
					<p>
						<label for="wcs_search_taxonomy">
							<input name="wcs_search_taxonomy" type="checkbox" id="wcs_search_taxonomy" value="1" <?php checked( 1, (int) get_option( 'wcs_search_taxonomy', 1 ), true ); ?> />
							<?php esc_html_e( 'Product Categories & Tags', 'turbo-search-for-woocommerce' ); ?>
						</label>
					</p>
				</fieldset>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<?php esc_html_e( 'Delete Data on Uninstall', 'turbo-search-for-woocommerce' ); ?>
				<div class="wcs-tooltip">
					<span class="wcs-tooltip-icon">?</span>
					<span class="wcs-tooltip-text"><?php esc_html_e( 'If enabled, all database tables, options, transients, and user meta created by the plugin will be deleted when you uninstall.', 'turbo-search-for-woocommerce' ); ?></span>
				</div>
			</th>
			<td>
				<fieldset>
					<label for="wcs_delete_data_on_uninstall">
						<input name="wcs_delete_data_on_uninstall" type="checkbox" id="wcs_delete_data_on_uninstall" value="1" <?php checked( 1, (int) get_option( 'wcs_delete_data_on_uninstall', 0 ), true ); ?> />
						<?php esc_html_e( 'Delete all plugin data and tables when deleting the plugin.', 'turbo-search-for-woocommerce' ); ?>
					</label>
				</fieldset>
			</td>
		</tr>
	</table>
	<?php submit_button(); ?>
</form>

<div class="card" style="max-width: 600px; margin-top: 20px; border-left: 4px solid #d63638;">
	<h2 style="color: #d63638;"><?php esc_html_e( 'Danger Zone', 'turbo-search-for-woocommerce' ); ?></h2>
	<p><?php esc_html_e( 'Immediately drop the search index tables, delete all plugin options and cached transients, and cancel pending background jobs. The plugin stays active and the index table is recreated on the next page load — you will need to trigger a Rebuild Index afterwards.', 'turbo-search-for-woocommerce' ); ?></p>
	<button id="wcs-delete-data-btn" class="button" style="background:#d63638;color:#fff;border-color:#b32d2e;">
		<?php esc_html_e( 'Delete All Plugin Data Now', 'turbo-search-for-woocommerce' ); ?>
	</button>
	<span id="wcs-delete-spinner" class="spinner"></span>
</div>
