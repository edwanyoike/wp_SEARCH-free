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
