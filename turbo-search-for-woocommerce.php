<?php
declare(strict_types=1);

/**
 * Plugin Name:          Turbo Search for WooCommerce
 * Plugin URI:           https://ozulabs.com
 * Description:          A high-performance, zero-dependency WooCommerce search engine using native FULLTEXT indexing.
 * Version:              1.0.0
 * Author:               Ozulabs
 * Author URI:           https://ozulabs.com
 * License:              GPLv2 or later
 * Text Domain:          turbo-search-for-woocommerce
 * Domain Path:          /languages
 * Requires at least:    6.5
 * Tested up to:         7.0
 * Requires PHP:         8.0
 * Requires Plugins:     woocommerce
 * WC requires at least: 8.0
 * WC tested up to:      9.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define core constants.
define( 'WCS_VERSION', '1.0.0' );
define( 'WCS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WCS_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Autoloader for WCS classes.
 * Maps WCS\Search\ClassName to includes/class-class-name.php
 */
spl_autoload_register( function ( string $class ): void {
	$prefix   = 'WCS\\Search\\';
	$base_dir = WCS_PLUGIN_DIR . 'includes/';

	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, $len );
	$file_name      = 'class-' . strtolower( str_replace( '_', '-', $relative_class ) ) . '.php';
	$file           = $base_dir . $file_name;

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

/**
 * Main plugin bootstrap function.
 */
function wcs_search_init(): void {
	// Check WooCommerce dependency.
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'wcs_woocommerce_missing_notice' );
		return;
	}

	// Wait for the class to be available (it will be built in Phase 2)
	if ( class_exists( '\\WCS\\Search\\Activator' ) ) {
		\WCS\Search\Activator::init();
	}
	if ( class_exists( '\\WCS\\Search\\Indexer' ) ) {
		\WCS\Search\Indexer::init();
	}
	if ( class_exists( '\\WCS\\Search\\Search_Handler' ) ) {
		\WCS\Search\Search_Handler::init();
	}
	if ( class_exists( '\\WCS\\Search\\Frontend' ) ) {
		\WCS\Search\Frontend::init();
	}
	if ( class_exists( '\\WCS\\Search\\Admin_Settings' ) ) {
		\WCS\Search\Admin_Settings::init();
	}
}
add_action( 'plugins_loaded', 'wcs_search_init' );

// This edition is distributed from wordpress.org, which auto-loads
// translations from the Domain Path header — no load_plugin_textdomain()
// call needed (and Plugin Check flags one as redundant/discouraged here).

// Declare WooCommerce HPOS compatibility (this plugin does not touch order tables).
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

/**
 * Admin notice if WooCommerce is not active.
 */
function wcs_woocommerce_missing_notice(): void {
	?>
	<div class="notice notice-error is-dismissible">
		<p><?php esc_html_e( 'Turbo Search for WooCommerce requires WooCommerce to be installed and active.', 'turbo-search-for-woocommerce' ); ?></p>
	</div>
	<?php
}

// Register activation hooks. We map these to static methods in the Activator class.
register_activation_hook( __FILE__, array( '\\WCS\\Search\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( '\\WCS\\Search\\Activator', 'deactivate' ) );
