<?php
/**
 * App Data tab: uninstall-cleanup preference and the immediate
 * "delete all plugin data now" danger-zone action.
 *
 * Included from settings-page.php.
 *
 * @package WP_Fast_Search
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="card" style="max-width: 600px; margin-top: 20px; opacity: 0.6;">
	<h2><?php esc_html_e( 'Export / Import Settings', 'turbo-search-for-woocommerce' ); ?> <span style="font-weight:normal; font-size: 13px;">(<?php esc_html_e( 'Pro', 'turbo-search-for-woocommerce' ); ?>)</span></h2>
	<p><?php esc_html_e( 'Move settings, search-button appearance, and ranking-weight tuning between sites (e.g. staging to production) as a JSON file. Your license key and search index are never included.', 'turbo-search-for-woocommerce' ); ?></p>
	<a href="https://ozulabs.com" target="_blank" rel="noopener" class="button" disabled="disabled" style="pointer-events: none;"><?php esc_html_e( 'Export Settings', 'turbo-search-for-woocommerce' ); ?></a>
	<a href="https://ozulabs.com" target="_blank" rel="noopener" class="button button-primary" style="margin-left: 8px;"><?php esc_html_e( 'Upgrade to Pro', 'turbo-search-for-woocommerce' ); ?></a>
</div>
<form method="post" action="options.php" style="margin-top: 20px;">
	<?php settings_fields( 'wcs_data_settings_group' ); ?>
	<table class="form-table" role="presentation">
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
