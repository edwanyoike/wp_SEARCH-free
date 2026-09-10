<?php
/**
 * Documentation tab: getting started, shortcode setup, re-index triggers,
 * adaptive batch sizing, developer hooks, uninstall notes.
 *
 * Included from settings-page.php.
 *
 * @package WP_Fast_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wcs-doc-section">
	<h2><?php esc_html_e( 'Getting Started', 'ozulabs-turbo-search-for-woocommerce' ); ?></h2>
	<ol style="line-height: 2.2;">
		<li><?php esc_html_e( 'Go to the Settings tab and click Rebuild Index. This runs once in the background and indexes all your products.', 'ozulabs-turbo-search-for-woocommerce' ); ?></li>
		<li><?php esc_html_e( 'Once complete, the search box on your store shows instant live results as customers type.', 'ozulabs-turbo-search-for-woocommerce' ); ?></li>
		<li><?php esc_html_e( 'After that, the index updates automatically whenever you add, edit, or delete a product — no manual action needed.', 'ozulabs-turbo-search-for-woocommerce' ); ?></li>
	</ol>
</div>

<div class="wcs-doc-section" id="search-form-setup" style="margin-top: 20px;">
	<h2><?php esc_html_e( 'Search Form Setup', 'ozulabs-turbo-search-for-woocommerce' ); ?></h2>
	<p><?php esc_html_e( 'The plugin automatically attaches to any existing WooCommerce product search form on your site. If your theme uses a custom search widget or does not have a standard product search bar, use the shortcode below to place a Turbo Search form anywhere.', 'ozulabs-turbo-search-for-woocommerce' ); ?></p>

	<h3 style="margin-bottom: 6px;"><?php esc_html_e( 'Shortcode', 'ozulabs-turbo-search-for-woocommerce' ); ?></h3>
	<p><?php esc_html_e( 'Paste this into any page, widget, or Elementor/WPBakery text block:', 'ozulabs-turbo-search-for-woocommerce' ); ?></p>
	<pre style="font-size:13px;">[turbo_search]</pre>

	<h3 style="margin-bottom:6px; margin-top:16px;"><?php esc_html_e( 'Optional attributes', 'ozulabs-turbo-search-for-woocommerce' ); ?></h3>
	<table class="widefat striped" style="max-width: 640px;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Attribute', 'ozulabs-turbo-search-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Default', 'ozulabs-turbo-search-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Example', 'ozulabs-turbo-search-for-woocommerce' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><code>placeholder</code></td>
				<td><?php esc_html_e( 'Search products…', 'ozulabs-turbo-search-for-woocommerce' ); ?></td>
				<td><code>placeholder="Find a product…"</code></td>
			</tr>
			<tr>
				<td><code>button</code></td>
				<td><?php esc_html_e( 'Search', 'ozulabs-turbo-search-for-woocommerce' ); ?></td>
				<td><code>button="Go"</code></td>
			</tr>
			<tr>
				<td><code>class</code></td>
				<td>—</td>
				<td><code>class="my-search-wrap"</code></td>
			</tr>
		</tbody>
	</table>

	<p style="margin-top: 12px; padding: 10px 14px; background: #f0f6fc; border-left: 4px solid #2563eb; border-radius: 0 4px 4px 0;">
		<?php esc_html_e( 'Tip: if your theme has its own AJAX search that appears at the same time as Turbo Search, disable the theme\'s built-in AJAX search in the theme settings, then use the shortcode to replace it.', 'ozulabs-turbo-search-for-woocommerce' ); ?>
	</p>
</div>

<div class="wcs-doc-section" style="margin-top: 20px;">
	<h2><?php esc_html_e( 'What Triggers a Re-index?', 'ozulabs-turbo-search-for-woocommerce' ); ?></h2>
	<table class="widefat striped" style="max-width: 640px;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Action', 'ozulabs-turbo-search-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'What happens', 'ozulabs-turbo-search-for-woocommerce' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr>
				<td><?php esc_html_e( 'Save or update a product', 'ozulabs-turbo-search-for-woocommerce' ); ?></td>
				<td><?php esc_html_e( 'That product is re-indexed in the background within seconds.', 'ozulabs-turbo-search-for-woocommerce' ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Change stock status', 'ozulabs-turbo-search-for-woocommerce' ); ?></td>
				<td><?php esc_html_e( 'The product\'s stock status in the index is updated immediately.', 'ozulabs-turbo-search-for-woocommerce' ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Trash or delete a product', 'ozulabs-turbo-search-for-woocommerce' ); ?></td>
				<td><?php esc_html_e( 'The product is removed from the index automatically.', 'ozulabs-turbo-search-for-woocommerce' ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Import products via CSV', 'ozulabs-turbo-search-for-woocommerce' ); ?></td>
				<td><?php esc_html_e( 'Each imported product is queued for indexing as it is created.', 'ozulabs-turbo-search-for-woocommerce' ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Rename a category, tag, brand, or attribute term', 'ozulabs-turbo-search-for-woocommerce' ); ?></td>
				<td><?php esc_html_e( 'Affected products are re-indexed; if the term has many products, a full rebuild is queued automatically.', 'ozulabs-turbo-search-for-woocommerce' ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Change search field settings', 'ozulabs-turbo-search-for-woocommerce' ); ?></td>
				<td><?php esc_html_e( 'Toggling which fields are indexed (title, SKU, etc.) triggers a full rebuild.', 'ozulabs-turbo-search-for-woocommerce' ); ?></td>
			</tr>
			<tr>
				<td><?php esc_html_e( 'Click "Rebuild Index"', 'ozulabs-turbo-search-for-woocommerce' ); ?></td>
				<td><?php esc_html_e( 'Forces a full re-index of all published products. Runs in the background — shoppers are not affected.', 'ozulabs-turbo-search-for-woocommerce' ); ?></td>
			</tr>
		</tbody>
	</table>
</div>

<div class="wcs-doc-section" style="margin-top: 20px;">
	<h2><?php esc_html_e( 'Adaptive Batch Sizing', 'ozulabs-turbo-search-for-woocommerce' ); ?></h2>
	<p><?php esc_html_e( 'During a full rebuild the indexer does not use a fixed batch size. At the start of every batch it samples the server\'s current CPU load and PHP memory usage and adjusts how many products it processes at once — automatically throttling on busy servers and speeding up on idle ones.', 'ozulabs-turbo-search-for-woocommerce' ); ?></p>
	<h3 style="margin-bottom: 6px;"><?php esc_html_e( 'CPU Load Tiers', 'ozulabs-turbo-search-for-woocommerce' ); ?></h3>
	<p style="margin-top: 0; color: #666; font-size: 13px;"><?php esc_html_e( 'Load ratio = 1-minute load average ÷ number of logical CPUs.', 'ozulabs-turbo-search-for-woocommerce' ); ?></p>
	<table class="widefat striped" style="max-width: 560px;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Load ratio', 'ozulabs-turbo-search-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Server state', 'ozulabs-turbo-search-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Products per batch', 'ozulabs-turbo-search-for-woocommerce' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<tr><td>&lt; 0.5</td><td><?php esc_html_e( 'Idle', 'ozulabs-turbo-search-for-woocommerce' ); ?></td><td>200</td></tr>
			<tr><td>0.5 – 1.0</td><td><?php esc_html_e( 'Normal', 'ozulabs-turbo-search-for-woocommerce' ); ?></td><td>100</td></tr>
			<tr><td>1.0 – 1.5</td><td><?php esc_html_e( 'Busy', 'ozulabs-turbo-search-for-woocommerce' ); ?></td><td>50</td></tr>
			<tr><td>&ge; 1.5</td><td><?php esc_html_e( 'Heavy load', 'ozulabs-turbo-search-for-woocommerce' ); ?></td><td>25</td></tr>
		</tbody>
	</table>
	<p style="margin-top: 10px;"><?php esc_html_e( 'On Windows hosts where sys_getloadavg() is unavailable, the indexer falls back to a fixed batch size of 50.', 'ozulabs-turbo-search-for-woocommerce' ); ?></p>
	<h3 style="margin-bottom: 6px;"><?php esc_html_e( 'Memory Pressure Cap', 'ozulabs-turbo-search-for-woocommerce' ); ?></h3>
	<p><?php esc_html_e( 'Two independent memory checks can cap the batch size down regardless of CPU load:', 'ozulabs-turbo-search-for-woocommerce' ); ?></p>
	<ul style="list-style: disc; padding-left: 20px; line-height: 2;">
		<li><?php esc_html_e( 'Relative — current PHP memory usage vs. this worker\'s own limit: above 70% caps at 25, above 50% caps at 50.', 'ozulabs-turbo-search-for-woocommerce' ); ?></li>
		<li><?php esc_html_e( 'Absolute — the worker\'s total memory_limit itself, regardless of current usage: 128MB or below caps at 25, 192MB or below caps at 50, 256MB or below caps at 100.', 'ozulabs-turbo-search-for-woocommerce' ); ?></li>
	</ul>
	<p><?php esc_html_e( 'The final size is kept between 10 (minimum) and 200 (maximum) before the filter below runs.', 'ozulabs-turbo-search-for-woocommerce' ); ?></p>
	<h3 style="margin-bottom: 6px;"><code>otsw_batch_size</code> <?php esc_html_e( '(filter)', 'ozulabs-turbo-search-for-woocommerce' ); ?></h3>
	<p><?php esc_html_e( 'Hard-cap the batch size directly — useful on managed hosting with a memory_limit that WordPress cannot override (WP_MEMORY_LIMIT has no effect when the host enforces memory_limit as a php_admin_value):', 'ozulabs-turbo-search-for-woocommerce' ); ?></p>
	<pre>add_filter( 'otsw_batch_size', function( int $size ): int {
	return 25; // always use small batches on this host
} );</pre>
</div>

<div class="wcs-doc-section" style="margin-top: 20px;">
	<h2><?php esc_html_e( 'Developer Hooks', 'ozulabs-turbo-search-for-woocommerce' ); ?></h2>
	<p><?php esc_html_e( 'These hooks let you customise indexing and ranking without modifying plugin files:', 'ozulabs-turbo-search-for-woocommerce' ); ?></p>

	<h3><code>otsw_indexed_product_data</code> <?php esc_html_e( '(filter)', 'ozulabs-turbo-search-for-woocommerce' ); ?></h3>
	<p><?php esc_html_e( 'Runs just before each product row is written to the index. Use it to add, remove, or transform the data that gets stored.', 'ozulabs-turbo-search-for-woocommerce' ); ?></p>
	<pre>add_filter( 'otsw_indexed_product_data', function( array $data, int $product_id ): array {
	// Example: append a custom field value to the searchable content.
	$extra = get_post_meta( $product_id, '_my_custom_field', true );
	if ( $extra ) {
		$data['content'] .= ' ' . sanitize_text_field( $extra );
	}
	return $data;
}, 10, 2 );</pre>
	<p style="color:#666;font-size:13px;"><?php esc_html_e( 'Available keys: product_id, title, sku, content, excerpt, price_min, price_max, stock_status, total_sales, image_url, permalink, updated_at. Custom keys are ignored — only the listed columns exist in the index table.', 'ozulabs-turbo-search-for-woocommerce' ); ?></p>

	<h3><code>otsw_ranking_weights</code> <?php esc_html_e( '(filter)', 'ozulabs-turbo-search-for-woocommerce' ); ?></h3>
	<p><?php esc_html_e( 'Tune how results are ranked. Title matches count 5× a description match by default; exact SKU matches always rank first; in-stock and best-selling products get a boost.', 'ozulabs-turbo-search-for-woocommerce' ); ?></p>
	<pre>add_filter( 'otsw_ranking_weights', function( array $w ): array {
	$w['sales']   = 1.0;  // Weight best-sellers more heavily.
	$w['instock'] = 2.0;  // Push out-of-stock products further down.
	return $w;
} );
// Keys: title (5.0), all_fields (1.0), exact_title (10.0), exact_sku (20.0),
//       title_prefix (3.0), phrase (4.0), instock (0.5), sales (0.3)</pre>

	<h3><code>otsw_indexed_taxonomies</code> <?php esc_html_e( '(filter)', 'ozulabs-turbo-search-for-woocommerce' ); ?></h3>
	<p><?php esc_html_e( 'Control which taxonomy term names are searchable. Defaults: categories, tags, brands, and all global attributes (color, material, …).', 'ozulabs-turbo-search-for-woocommerce' ); ?></p>
	<pre>add_filter( 'otsw_indexed_taxonomies', function( array $taxonomies ): array {
	return array_diff( $taxonomies, array( 'product_tag' ) ); // Exclude tags.
} );</pre>

	<h3><code>otsw_index_rebuild_complete</code> <?php esc_html_e( '(action)', 'ozulabs-turbo-search-for-woocommerce' ); ?></h3>
	<p><?php esc_html_e( 'Fires once after a full rebuild finishes and the new index table is live. Use it to bust a CDN cache, send a notification, or trigger a downstream job.', 'ozulabs-turbo-search-for-woocommerce' ); ?></p>
	<pre>add_action( 'otsw_index_rebuild_complete', function(): void {
	// Example: purge a CDN or send a Slack notification.
	my_cdn_purge_all();
} );</pre>
</div>

<div class="wcs-doc-section" style="margin-top: 20px;">
	<h2><?php esc_html_e( 'Uninstalling', 'ozulabs-turbo-search-for-woocommerce' ); ?></h2>
	<ul style="list-style: disc; padding-left: 20px; line-height: 2;">
		<li><?php esc_html_e( 'Deactivating the plugin keeps all your data and settings intact.', 'ozulabs-turbo-search-for-woocommerce' ); ?></li>
		<li><?php esc_html_e( 'Deleting the plugin removes data only if "Delete data on uninstall" is enabled in Settings. By default, data is kept.', 'ozulabs-turbo-search-for-woocommerce' ); ?></li>
	</ul>
</div>
