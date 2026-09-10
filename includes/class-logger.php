<?php
declare(strict_types=1);

/**
 * Custom Logger for the plugin.
 *
 * @package WP_Fast_Search
 */

namespace OTSW\Search;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Logger {

	/**
	 * Log a message with version prefix.
	 *
	 * @param string $message The message to log.
	 * @param string $level   Log level (e.g., 'info', 'error', 'warning').
	 */
	public static function log( string $message, string $level = 'info' ): void {
		$prefix = '[v' . OTSW_VERSION . '] ';

		if ( function_exists( 'wc_get_logger' ) ) {
			$logger  = wc_get_logger();
			$context = array( 'source' => 'ozulabs-turbo-search-for-woocommerce' );
			$logger->log( $level, $prefix . $message, $context );
		} else {
			error_log( 'Turbo Search for WooCommerce ' . $prefix . strtoupper( $level ) . ': ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}
}
