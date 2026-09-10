<?php
/**
 * Settings page shell: heading, byline, tab navigation, active tab body.
 *
 * Included from Admin_Settings::render_settings_page(). Variables in scope:
 *
 * @var string $active_tab        'settings', 'data', or 'docs'.
 * @var bool   $is_indexing       Whether a rebuild is currently running.
 * @var int    $last_indexed      Timestamp of the last successful index (0 = never).
 * @var string $last_rebuild_error Non-empty error code when idle after a failed
 *                                 rebuild (e.g. 'stuck_no_batch_dispatched').
 * @var int    $total             Published product count.
 * @var int    $processed         Products processed in the current/last rebuild.
 *
 * @package OzuLabs_Turbo_Search_For_WooCommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'Turbo Search for WooCommerce Settings', 'ozulabs-turbo-search-for-woocommerce' ); ?></h1>
	<p style="color:#666;">
		<?php esc_html_e( 'By', 'ozulabs-turbo-search-for-woocommerce' ); ?>
		<a href="https://ozulabs.com" target="_blank" rel="noopener">Ozulabs</a>
		&nbsp;&middot;&nbsp;
		<a href="mailto:support@ozulabs.com">support@ozulabs.com</a>
		&nbsp;&middot;&nbsp;
		<?php
		/* translators: %s: plugin version number, e.g. "1.11.4" */
		echo esc_html( sprintf( __( 'Version %s', 'ozulabs-turbo-search-for-woocommerce' ), OTSW_VERSION ) );
		?>
	</p>

	<h2 class="nav-tab-wrapper" style="margin-top: 20px;">
		<a href="?page=otsw-fast-search&tab=settings" class="nav-tab <?php echo 'settings' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Settings', 'ozulabs-turbo-search-for-woocommerce' ); ?></a>
		<a href="?page=otsw-fast-search&tab=data" class="nav-tab <?php echo 'data' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'App Data', 'ozulabs-turbo-search-for-woocommerce' ); ?></a>
		<a href="?page=otsw-fast-search&tab=docs" class="nav-tab <?php echo 'docs' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'Documentation', 'ozulabs-turbo-search-for-woocommerce' ); ?></a>
	</h2>

	<?php
	if ( 'settings' === $active_tab ) {
		include __DIR__ . '/tab-settings.php';
	} elseif ( 'data' === $active_tab ) {
		include __DIR__ . '/tab-app-data.php';
	} else {
		include __DIR__ . '/tab-docs.php';
	}
	?>
</div>
