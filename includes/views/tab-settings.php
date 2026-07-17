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
 * @var int    $product_cap        Maximum products this edition indexes (Indexer::FREE_PRODUCT_CAP).
 *
 * @package WP_Fast_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php if ( $total > $product_cap ) : ?>
<div style="margin-top: 20px; padding: 12px 16px; background: #fef8ee; border-left: 4px solid #d97706; border-radius: 0 4px 4px 0; font-size: 13px; line-height: 1.6;">
	<strong><?php esc_html_e( 'Only part of your catalog is searchable.', 'turbo-search-for-woocommerce' ); ?></strong>
	<?php
	printf(
		/* translators: 1: number of products indexed, 2: total published products */
		esc_html__( 'The free edition indexes up to %1$d products — your store has %2$d published products, so the rest do not appear in search results.', 'turbo-search-for-woocommerce' ),
		(int) $product_cap,
		(int) $total
	);
	?>
	<?php esc_html_e( 'Upgrade to Turbo Search Pro for unlimited indexed products — see', 'turbo-search-for-woocommerce' ); ?>
	<a href="https://ozulabs.com" target="_blank" rel="noopener">ozulabs.com</a>.
</div>
<?php endif; ?>

<div class="card" style="max-width: 600px; margin-top: 20px; border-left: 4px solid #2E7D32;">
	<h2 style="margin-top:0;"><?php esc_html_e( 'Unlock More With Turbo Search Pro', 'turbo-search-for-woocommerce' ); ?></h2>
	<ul style="margin:0 0 12px 20px; list-style:disc;">
		<li><?php esc_html_e( 'Typo tolerance — misspelled searches are auto-corrected against your catalog', 'turbo-search-for-woocommerce' ); ?></li>
		<li><?php esc_html_e( 'Search synonyms — teach the search box your customers\' own words', 'turbo-search-for-woocommerce' ); ?></li>
		<li><?php esc_html_e( 'Category & brand suggestions in the dropdown', 'turbo-search-for-woocommerce' ); ?></li>
		<li><?php esc_html_e( 'Ranking-weight tuning and sales-weighted ranking', 'turbo-search-for-woocommerce' ); ?></li>
		<li><?php esc_html_e( 'Zero-result search analytics dashboard', 'turbo-search-for-woocommerce' ); ?></li>
		<li><?php esc_html_e( 'Multi-currency price support (CURCY, WOOCS, WooCommerce Multilingual)', 'turbo-search-for-woocommerce' ); ?></li>
		<li><?php esc_html_e( 'Unlimited indexed products (this edition indexes up to 100)', 'turbo-search-for-woocommerce' ); ?></li>
	</ul>
	<a href="https://ozulabs.com" target="_blank" rel="noopener" class="button button-primary"><?php esc_html_e( 'Upgrade to Pro', 'turbo-search-for-woocommerce' ); ?></a>
</div>

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
				<label for="wcs_synonyms"><?php esc_html_e( 'Search Synonyms', 'turbo-search-for-woocommerce' ); ?></label>
				<div class="wcs-tooltip">
					<span class="wcs-tooltip-icon">?</span>
					<span class="wcs-tooltip-text"><?php esc_html_e( 'One group per line, words separated by commas. Every word in a group matches products containing any other word in that group. Example: "sofa, couch, settee" makes a search for sofa also find couches.', 'turbo-search-for-woocommerce' ); ?></span>
				</div>
			</th>
			<td>
				<textarea class="large-text code" rows="5" disabled placeholder="sofa, couch, settee&#10;tee, t shirt, tshirt"></textarea>
				<p class="description"><?php echo wp_kses( sprintf( /* translators: %s: link to ozulabs.com */ __( 'Search synonyms is a Pro feature. <a href="%s" target="_blank" rel="noopener">Upgrade to Pro</a> to enable it.', 'turbo-search-for-woocommerce' ), esc_url( 'https://ozulabs.com' ) ), array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ); ?></p>
			</td>
		</tr>
		<tr>
			<th scope="row">
				<?php esc_html_e( 'Ranking Weights', 'turbo-search-for-woocommerce' ); ?>
				<div class="wcs-tooltip">
					<span class="wcs-tooltip-icon">?</span>
					<span class="wcs-tooltip-text"><?php esc_html_e( 'Tune how strongly each signal influences result order — title match, exact SKU, stock status, sales, and more.', 'turbo-search-for-woocommerce' ); ?></span>
				</div>
			</th>
			<td>
				<p class="description"><?php echo wp_kses( sprintf( /* translators: %s: link to ozulabs.com */ __( 'Ranking weight tuning is a Pro feature. <a href="%s" target="_blank" rel="noopener">Upgrade to Pro</a> to enable it.', 'turbo-search-for-woocommerce' ), esc_url( 'https://ozulabs.com' ) ), array( 'a' => array( 'href' => array(), 'target' => array(), 'rel' => array() ) ) ); ?></p>
			</td>
		</tr>
	</table>
	<?php submit_button(); ?>
</form>
