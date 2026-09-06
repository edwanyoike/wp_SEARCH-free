<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use WCS\Search\Activator;

/**
 * Guard against the C2 class of bug: every option and transient the plugin
 * creates must be covered by the explicit cleanup lists in Activator —
 * cleanup must never regress to broad LIKE 'wcs_%' patterns (which would
 * destroy WooCommerce Subscriptions data), and new options must never be
 * added without also being added to the uninstall list.
 *
 * Works by scanning the shipped source for literal option writes, so adding
 * update_option( 'wcs_new_thing', ... ) anywhere fails this suite until
 * PLUGIN_OPTIONS is updated.
 */
final class CleanupCoverageTest extends TestCase {

	/** @return string[] Plugin source files that ship to production. */
	private function sourceFiles(): array {
		$files   = glob( WCS_PLUGIN_DIR . 'includes/*.php' ) ?: array();
		$files[] = WCS_PLUGIN_DIR . 'turbo-search-for-woocommerce.php';
		$files[] = WCS_PLUGIN_DIR . 'mu-plugin/wcs-cache-bypass.php';
		return $files;
	}

	/** @return string[] Unique wcs_-prefixed option names written anywhere in the source. */
	private function writtenOptions(): array {
		$found = array();
		foreach ( $this->sourceFiles() as $file ) {
			$src = (string) file_get_contents( $file );
			// update_option / add_option / register_setting( group, 'wcs_...' )
			preg_match_all( "/(?:update_option|add_option)\(\s*'(wcs_[a-z0-9_]+)'/", $src, $m1 );
			preg_match_all( "/register_setting\(\s*'[^']+',\s*'(wcs_[a-z0-9_]+)'/", $src, $m2 );
			$found = array_merge( $found, $m1[1], $m2[1] );
		}
		sort( $found );
		return array_values( array_unique( $found ) );
	}

	public function test_every_written_option_is_in_the_cleanup_list(): void {
		$missing = array_diff( $this->writtenOptions(), Activator::PLUGIN_OPTIONS );
		$this->assertSame(
			array(),
			array_values( $missing ),
			'Options written by the plugin but missing from Activator::PLUGIN_OPTIONS — uninstall would leave them behind: ' . implode( ', ', $missing )
		);
	}

	public function test_cleanup_list_contains_no_dead_entries(): void {
		// Every listed option should be written somewhere (or read as a flag) —
		// dead entries suggest a rename that forgot to update the list.
		$all_src = '';
		foreach ( $this->sourceFiles() as $file ) {
			$all_src .= (string) file_get_contents( $file );
		}
		$dead = array();
		foreach ( Activator::PLUGIN_OPTIONS as $option ) {
			if ( false === strpos( $all_src, "'" . $option . "'" ) ) {
				$dead[] = $option;
			}
		}
		$this->assertSame( array(), $dead, 'PLUGIN_OPTIONS entries never referenced in source: ' . implode( ', ', $dead ) );
	}

	public function test_transient_prefixes_cover_every_key_family_the_plugin_creates(): void {
		// The four key families created via set_transient(). If a new family is
		// added, it must appear in TRANSIENT_PREFIXES too.
		$expected = array( 'wcs_v', 'wcs_rl_', 'wcs_nr_', 'wcs_batch_retry_' );
		foreach ( $expected as $prefix ) {
			$this->assertContains( $prefix, Activator::TRANSIENT_PREFIXES );
		}
	}

	public function test_transient_key_families_still_exist_in_source(): void {
		$all_src = '';
		foreach ( $this->sourceFiles() as $file ) {
			$all_src .= (string) file_get_contents( $file );
		}
		// Rate limiter keys are built at the call sites; the cache key inside
		// Query_Normalizer::cache_key(). A rename without updating
		// TRANSIENT_PREFIXES would orphan rows on uninstall.
		$this->assertStringContainsString( "'wcs_rl_'", $all_src );
		$this->assertStringContainsString( "'wcs_nr_'", $all_src );
		$this->assertStringContainsString( "'wcs_batch_retry_'", $all_src );
		$this->assertStringContainsString( "'wcs_v'", $all_src );
	}

	public function test_no_broad_prefix_deletes_anywhere_in_cleanup_code(): void {
		// The exact C2 bug: DELETE ... LIKE 'wcs_%' (or its transient forms)
		// matches WooCommerce Subscriptions options. Assert the dangerous
		// patterns never reappear in any shipped file, including uninstall.php.
		$files   = $this->sourceFiles();
		$files[] = WCS_PLUGIN_DIR . 'uninstall.php';

		foreach ( $files as $file ) {
			$src = (string) file_get_contents( $file );
			foreach ( array( "'wcs_' ) . '%'", "'_transient_wcs_' ) . '%'", "'_transient_timeout_wcs_' ) . '%'" ) as $dangerous ) {
				$this->assertStringNotContainsString(
					$dangerous,
					$src,
					basename( $file ) . ' contains a broad wcs_ prefix delete — this destroys WooCommerce Subscriptions data'
				);
			}
		}
	}

	public function test_uninstall_uses_the_shared_lists(): void {
		$src = (string) file_get_contents( WCS_PLUGIN_DIR . 'uninstall.php' );
		$this->assertStringContainsString( 'Activator::PLUGIN_OPTIONS', $src );
		$this->assertStringContainsString( 'Activator::TRANSIENT_PREFIXES', $src );
	}

	/** @return string[] Unique hook names passed to wp_schedule_single_event() anywhere in the source. */
	private function dynamicCronHooksInSource(): array {
		$found = array();
		foreach ( $this->sourceFiles() as $file ) {
			$src = (string) file_get_contents( $file );
			preg_match_all( "/wp_schedule_single_event\(\s*[^,]+,\s*'(wcs_[a-z0-9_]+)'/", $src, $m );
			$found = array_merge( $found, $m[1] );
		}
		sort( $found );
		return array_values( array_unique( $found ) );
	}

	/**
	 * Guards the same class of bug as test_every_written_option_is_in_the_
	 * cleanup_list(), for a different kind of state: a WP-Cron hook
	 * scheduled with dynamic, per-call arguments (a product ID, a rebuild
	 * epoch) rather than a fixed argument list. wp_next_scheduled()/
	 * wp_unschedule_event() can't clear "every pending instance" of one of
	 * these without already knowing each instance's exact args — that's
	 * what Activator::DYNAMIC_CRON_HOOKS + clear_dynamic_cron_hooks() exist
	 * for. A new dynamically-argumented retry hook added to the source
	 * without also being added to DYNAMIC_CRON_HOOKS would be left
	 * scheduled forever after deactivation/uninstall, exactly like
	 * wcs_retry_rebuild_scheduling/wcs_retry_product_enqueue/
	 * wcs_retry_product_delete all were before that mechanism existed.
	 */
	public function test_every_dynamic_cron_hook_is_registered_for_cleanup(): void {
		$missing = array_diff( $this->dynamicCronHooksInSource(), Activator::DYNAMIC_CRON_HOOKS );
		$this->assertSame(
			array(),
			array_values( $missing ),
			'wp_schedule_single_event() hooks missing from Activator::DYNAMIC_CRON_HOOKS — deactivation/uninstall would leave them scheduled forever: ' . implode( ', ', $missing )
		);
	}

	public function test_uninstall_clears_dynamic_cron_hooks(): void {
		$src = (string) file_get_contents( WCS_PLUGIN_DIR . 'uninstall.php' );
		$this->assertStringContainsString( 'Activator::clear_dynamic_cron_hooks()', $src );
	}

	public function test_wcs_rate_limits_table_is_dropped_on_uninstall(): void {
		// Tables aren't covered by the shared PLUGIN_OPTIONS/TRANSIENT_PREFIXES
		// lists above, so a table added to create_tables() needs its own drop
		// added by hand in both cleanup paths — easy to forget one of the two.
		$uninstall = (string) file_get_contents( WCS_PLUGIN_DIR . 'uninstall.php' );
		$this->assertStringContainsString( 'wcs_rate_limits', $uninstall );

		$admin = (string) file_get_contents( WCS_PLUGIN_DIR . 'includes/class-admin-settings.php' );
		$this->assertStringContainsString( 'wcs_rate_limits', $admin );
	}
}
