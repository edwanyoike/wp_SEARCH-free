<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap: lightweight WordPress stubs + a scriptable fake $wpdb.
 *
 * No database, Docker, or WordPress checkout required — the plugin's classes
 * use a small, well-defined slice of the WP API, stubbed here against
 * in-memory stores that tests can inspect and reset via wcs_tests_reset().
 *
 * Deliberate limitation: these are unit tests of the plugin's own logic
 * (normalization, SQL construction, state machines, cleanup lists). Behaviour
 * that depends on real MySQL semantics (FULLTEXT matching, collations) is
 * covered by the k6 load-test harness against a live site, not here.
 */

error_reporting( E_ALL );

// ── Constants the plugin expects ───────────────────────────────────────────
define( 'ABSPATH', sys_get_temp_dir() . '/wcs-tests-abspath/' );
define( 'WP_DEBUG', false );
define( 'WPMU_PLUGIN_DIR', sys_get_temp_dir() . '/wcs-tests-mu-plugins' );

// create_tables() requires wp-admin/includes/upgrade.php for dbDelta —
// provide an empty file (dbDelta itself is stubbed below).
if ( ! is_dir( ABSPATH . 'wp-admin/includes' ) ) {
	mkdir( ABSPATH . 'wp-admin/includes', 0777, true );
}
if ( ! file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
	file_put_contents( ABSPATH . 'wp-admin/includes/upgrade.php', "<?php // stub\n" );
}
if ( ! is_dir( WPMU_PLUGIN_DIR ) ) {
	mkdir( WPMU_PLUGIN_DIR, 0777, true );
}
define( 'WCS_VERSION', '0.0.0-test' );
define( 'WCS_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );
define( 'WCS_PLUGIN_URL', 'https://example.test/wp-content/plugins/turbo-search-for-woocommerce/' );
define( 'WCS_PLUGIN_BASENAME', 'turbo-search-for-woocommerce/turbo-search-for-woocommerce.php' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
define( 'OBJECT', 'OBJECT' );
define( 'OBJECT_K', 'OBJECT_K' );
define( 'ARRAY_A', 'ARRAY_A' );
define( 'ARRAY_N', 'ARRAY_N' );

// WP_PLUGIN_DIR hosts a symlink to this repo under the deployed plugin slug,
// so the MU plugin's hardcoded require path resolves during parity tests.
$wcs_tests_plugin_dir = sys_get_temp_dir() . '/wcs-tests-plugins';
if ( ! is_dir( $wcs_tests_plugin_dir ) ) {
	mkdir( $wcs_tests_plugin_dir, 0777, true );
}
// sys_get_temp_dir() is shared by every process on the machine, including
// the sibling Pro/Free repos' own test runs (both ship a plugin literally
// named "turbo-search-for-woocommerce"). A stale symlink left pointing at
// the other repo's WCS_PLUGIN_DIR causes duplicate-class fatals when that
// repo's files get require'd into this run. Always point it at the current
// run's own directory rather than only creating it once.
$wcs_tests_symlink = $wcs_tests_plugin_dir . '/turbo-search-for-woocommerce';
if ( is_link( $wcs_tests_symlink ) && readlink( $wcs_tests_symlink ) !== rtrim( WCS_PLUGIN_DIR, '/' ) ) {
	unlink( $wcs_tests_symlink );
}
if ( ! file_exists( $wcs_tests_symlink ) ) {
	symlink( rtrim( WCS_PLUGIN_DIR, '/' ), $wcs_tests_symlink );
}
define( 'WP_PLUGIN_DIR', $wcs_tests_plugin_dir );

// ── Test-state stores ──────────────────────────────────────────────────────
function wcs_tests_reset(): void {
	$GLOBALS['wcs_test_options']       = array();
	$GLOBALS['wcs_test_transients']    = array();
	$GLOBALS['wcs_test_filters']       = array();
	$GLOBALS['wcs_test_as_calls']      = array();
	$GLOBALS['wcs_test_logs']          = array();
	$GLOBALS['wcs_test_nonce_valid']   = true;
	$GLOBALS['wcs_test_enqueued']      = array();
	$GLOBALS['wcs_test_inline_js']     = array();
	$GLOBALS['wcs_test_products']      = array();
	$GLOBALS['wcs_test_posts']         = array();
	$GLOBALS['wcs_test_thumbs']        = array();
	$GLOBALS['wcs_test_terms']         = array();
	$GLOBALS['wcs_test_taxonomies']    = array( 'product_cat', 'product_tag' );
	$GLOBALS['wcs_test_user_meta']     = array();
	$GLOBALS['wcs_test_can']           = true;
	$GLOBALS['wcs_test_referer_ok']    = true;
	$GLOBALS['wcs_test_screen_id']     = 'settings_page_wcs-fast-search';
	$GLOBALS['wcs_test_publish_count'] = 10;
	$GLOBALS['wcs_test_ext_cache']     = false;
	$GLOBALS['wcs_test_cache_add']     = true;
	$GLOBALS['wcs_test_marked_failed'] = array();
	$GLOBALS['wcs_test_script_done']   = false;
	$GLOBALS['wcs_test_cron']          = array();
	$GLOBALS['wcs_test_active_plugins']       = array();
	$GLOBALS['wcs_test_deactivated_plugins']  = array();
	$GLOBALS['wcs_test_primed']        = array();
	$GLOBALS['wcs_test_on_sale_ids']   = array();
	$GLOBALS['wcs_test_transient_read_hook'] = null;
	$GLOBALS['wcs_test_rest_routes']         = array();
	$GLOBALS['wcs_test_as_actions']          = array();
	$GLOBALS['wcs_test_mark_failure_throws'] = false;
	$GLOBALS['wcs_test_is_admin']            = false;
	$GLOBALS['wcs_test_dbdelta']             = array();
	$GLOBALS['wcs_test_registered_settings'] = array();
	$GLOBALS['wcs_test_objects_in_term']     = array();
	$GLOBALS['wcs_test_usleeps']             = array();
	// Unset (not merely reset) so dynamic_batch_size() falls through to the
	// real system functions by default; tests opt in explicitly.
	unset( $GLOBALS['wcs_test_loadavg'], $GLOBALS['wcs_test_memory_usage'], $GLOBALS['wcs_test_memory_limit'] );

	if ( class_exists( \WCS\Search\Search_Handler::class, false ) ) {
		\WCS\Search\Search_Handler::flush_runtime_cache();
	}

	if ( class_exists( \WCS\Search\Query_Normalizer::class, false ) ) {
		\WCS\Search\Query_Normalizer::flush_synonym_cache();
	}
	// Reset the Indexer's per-request static dedup flags.
	if ( class_exists( \WCS\Search\Indexer::class, false ) ) {
		$ref = new ReflectionClass( \WCS\Search\Indexer::class );
		foreach ( array( 'queued_ids' => array(), 'bust_queued' => false, 'rebuild_queued' => false ) as $prop => $value ) {
			$p = $ref->getProperty( $prop );
			$p->setValue( null, $value );
		}
	}
}
wcs_tests_reset();

// ── Options / transients ───────────────────────────────────────────────────
function get_option( string $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['wcs_test_options'] ) ? $GLOBALS['wcs_test_options'][ $name ] : $default;
}
function update_option( string $name, $value, $autoload = null ): bool {
	$GLOBALS['wcs_test_options'][ $name ] = $value;
	return true;
}
function add_option( string $name, $value = '', $deprecated = '', $autoload = null ): bool {
	if ( array_key_exists( $name, $GLOBALS['wcs_test_options'] ) ) {
		return false;
	}
	$GLOBALS['wcs_test_options'][ $name ] = $value;
	return true;
}
function delete_option( string $name ): bool {
	unset( $GLOBALS['wcs_test_options'][ $name ] );
	return true;
}
function get_transient( string $key ) {
	$GLOBALS['wcs_test_transients']['reads'][] = $key;
	// Optional per-test hook — lets a test inject a value mid-flow (e.g. a
	// "builder" filling the cache while the stampede poller waits).
	if ( ! empty( $GLOBALS['wcs_test_transient_read_hook'] ) ) {
		( $GLOBALS['wcs_test_transient_read_hook'] )( $key );
	}
	return $GLOBALS['wcs_test_transients']['data'][ $key ] ?? false;
}
function set_transient( string $key, $value, int $expiration = 0 ): bool {
	$GLOBALS['wcs_test_transients']['data'][ $key ]    = $value;
	$GLOBALS['wcs_test_transients']['expiry'][ $key ]  = $expiration;
	return true;
}
function delete_transient( string $key ): bool {
	unset( $GLOBALS['wcs_test_transients']['data'][ $key ] );
	return true;
}

// ── Hooks ──────────────────────────────────────────────────────────────────
function add_filter( string $tag, callable $cb, int $priority = 10, int $accepted_args = 1 ): bool {
	$GLOBALS['wcs_test_filters'][ $tag ][] = array( 'cb' => $cb, 'priority' => $priority, 'args' => $accepted_args );
	usort( $GLOBALS['wcs_test_filters'][ $tag ], static fn( $a, $b ) => $a['priority'] <=> $b['priority'] );
	return true;
}
function apply_filters( string $tag, $value, ...$args ) {
	foreach ( $GLOBALS['wcs_test_filters'][ $tag ] ?? array() as $entry ) {
		$call_args = array_slice( array_merge( array( $value ), $args ), 0, max( 1, $entry['args'] ) );
		$value     = call_user_func_array( $entry['cb'], $call_args );
	}
	return $value;
}
function remove_filter( string $tag, callable $cb, int $priority = 10 ): bool {
	foreach ( $GLOBALS['wcs_test_filters'][ $tag ] ?? array() as $i => $entry ) {
		if ( $entry['cb'] === $cb ) {
			unset( $GLOBALS['wcs_test_filters'][ $tag ][ $i ] );
		}
	}
	return true;
}
function add_action( string $tag, callable $cb, int $priority = 10, int $accepted_args = 1 ): bool {
	return add_filter( $tag, $cb, $priority, $accepted_args );
}
function do_action( string $tag, ...$args ): void {
	foreach ( $GLOBALS['wcs_test_filters'][ $tag ] ?? array() as $entry ) {
		call_user_func_array( $entry['cb'], array_slice( $args, 0, max( 0, $entry['args'] ) ) );
	}
}

// ── Action Scheduler recorders ─────────────────────────────────────────────
function as_enqueue_async_action( string $hook, array $args = array(), string $group = '', $priority = 0, bool $unique = false ): int {
	$GLOBALS['wcs_test_as_calls'][] = array( 'fn' => 'enqueue_async', 'hook' => $hook, 'args' => $args, 'group' => $group );
	return count( $GLOBALS['wcs_test_as_calls'] );
}
function as_schedule_single_action( int $timestamp, string $hook, array $args = array(), string $group = '' ): int {
	$GLOBALS['wcs_test_as_calls'][] = array( 'fn' => 'schedule_single', 'hook' => $hook, 'args' => $args, 'group' => $group );
	return count( $GLOBALS['wcs_test_as_calls'] );
}
function as_has_scheduled_action( string $hook, $args = null, string $group = '' ): bool {
	return false;
}
function as_unschedule_all_actions( $hook = null, array $args = array(), string $group = '' ): void {
	$GLOBALS['wcs_test_as_calls'][] = array( 'fn' => 'unschedule_all', 'hook' => $hook, 'group' => $group );
}

// ── Sanitizers / escaping / i18n (deliberately simplified approximations) ──
function wp_unslash( $value ) {
	return is_array( $value ) ? array_map( 'wp_unslash', $value ) : stripslashes( (string) $value );
}
function sanitize_text_field( $str ): string {
	$str = strip_tags( (string) $str );
	$str = preg_replace( '/[\r\n\t ]+/', ' ', $str );
	return trim( $str );
}
function sanitize_textarea_field( $str ): string {
	return trim( strip_tags( (string) $str ) );
}
function __return_true(): bool {
	return true;
}
function __return_false(): bool {
	return false;
}
function absint( $maybeint ): int {
	return abs( (int) $maybeint );
}
function sanitize_key( $key ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
}
function wp_strip_all_tags( $string, bool $remove_breaks = false ): string {
	$string = strip_tags( (string) $string );
	if ( $remove_breaks ) {
		$string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
	}
	return trim( $string );
}
function esc_url_raw( string $url ): string {
	// Approximation of esc_url_raw: allow http(s) and root-relative; reject other schemes.
	if ( '' === $url ) {
		return '';
	}
	if ( preg_match( '#^https?://#i', $url ) || str_starts_with( $url, '/' ) ) {
		return $url;
	}
	return '';
}
function __( string $text, string $domain = 'default' ): string {
	return $text;
}
function esc_html( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES );
}
function esc_html__( string $text, string $domain = 'default' ): string {
	// Matches real WordPress: esc_html__() = esc_html(translated string), not
	// a no-op. A stub that skipped the escaping step would make any test
	// relying on esc_html__()'s actual behavior pass even when the escaping
	// is wrong or missing — this exact gap let a real double-escaping bug
	// (esc_html__() used on a string later rendered via JS .textContent,
	// which doesn't decode entities) ship undetected.
	return esc_html( $text );
}
function wp_json_encode( $data, int $options = 0 ) {
	return json_encode( $data, $options );
}
function current_time( string $type ) {
	if ( 'timestamp' !== $type ) {
		return gmdate( 'Y-m-d H:i:s' );
	}
	// Matches real WordPress: current_time('timestamp') adds the site's UTC
	// offset to time() — it is NOT a true Unix timestamp. A stub that always
	// returned plain time() would make any test asserting on this distinction
	// pass even when production code wrongly compares the two directly (the
	// exact bug this simulates: wcs_last_indexed stored via
	// current_time('timestamp') but read back via human_time_diff(), whose
	// default comparison point is real time()).
	$offset_hours = (float) get_option( 'gmt_offset', 0 );
	return time() + (int) round( $offset_hours * HOUR_IN_SECONDS );
}
function wp_verify_nonce( $nonce, $action = -1 ) {
	return ! empty( $GLOBALS['wcs_test_nonce_valid'] );
}
function wp_using_ext_object_cache(): bool {
	return ! empty( $GLOBALS['wcs_test_ext_cache'] );
}
function is_plugin_active( string $plugin ): bool {
	return in_array( $plugin, $GLOBALS['wcs_test_active_plugins'] ?? array(), true );
}
function deactivate_plugins( $plugins ): void {
	$GLOBALS['wcs_test_deactivated_plugins'] = array_merge(
		$GLOBALS['wcs_test_deactivated_plugins'] ?? array(),
		(array) $plugins
	);
	$GLOBALS['wcs_test_active_plugins'] = array_values( array_diff(
		$GLOBALS['wcs_test_active_plugins'] ?? array(),
		(array) $plugins
	) );
}
function wp_cache_add( $key, $data, $group = '', $expire = 0 ): bool {
	return (bool) $GLOBALS['wcs_test_cache_add'];
}
function wp_cache_delete( $key, $group = '' ): bool {
	return true;
}
function wp_cache_flush(): bool {
	return true;
}
function rest_ensure_response( $response ) {
	return $response instanceof WP_REST_Response ? $response : new WP_REST_Response( $response );
}

// ── REST stubs ─────────────────────────────────────────────────────────────
class WP_REST_Server {
	public const READABLE = 'GET';
}
function register_rest_route( string $ns, string $route, array $args = array() ): bool {
	$GLOBALS['wcs_test_rest_routes'][ $ns . $route ] = $args;
	return true;
}
class WP_Post {
	public int $ID           = 0;
	public string $post_type = 'post';
	public function __construct( array $props = array() ) {
		foreach ( $props as $k => $v ) {
			$this->$k = $v;
		}
	}
}
class WP_REST_Response {
	public array $headers = array();
	public function __construct( public $data = null ) {}
	public function header( string $key, string $value ): void {
		$this->headers[ $key ] = $value;
	}
}
class WP_REST_Request {
	public function __construct( private array $params = array() ) {}
	public function get_param( string $key ) {
		return $this->params[ $key ] ?? null;
	}
}

// ── AJAX seams: wp_send_json_* throw instead of exit ───────────────────────
class WCS_Test_JSON_Response extends Exception {
	public function __construct(
		public bool $success,
		public $payload = null,
		public int $status = 200
	) {
		parent::__construct( 'json-response' );
	}
}
function wp_send_json_success( $data = null, ?int $status_code = null ): void {
	throw new WCS_Test_JSON_Response( true, $data, $status_code ?? 200 );
}
function wp_send_json_error( $data = null, ?int $status_code = null ): void {
	throw new WCS_Test_JSON_Response( false, $data, $status_code ?? 200 );
}
function check_ajax_referer( $action = -1, $query_arg = false, bool $stop = true ) {
	if ( empty( $GLOBALS['wcs_test_referer_ok'] ) ) {
		throw new WCS_Test_JSON_Response( false, 'bad-nonce', 403 );
	}
	return 1;
}
function current_user_can( string $capability ): bool {
	return (bool) $GLOBALS['wcs_test_can'];
}
function get_current_user_id(): int {
	return 1;
}
function update_user_meta( int $user_id, string $key, $value ): bool {
	$GLOBALS['wcs_test_user_meta'][ $user_id ][ $key ] = $value;
	return true;
}
function get_user_meta( int $user_id, string $key, bool $single = false ) {
	return $GLOBALS['wcs_test_user_meta'][ $user_id ][ $key ] ?? '';
}

// ── Assets / frontend ──────────────────────────────────────────────────────
function wp_enqueue_style( string $handle, string $src = '', array $deps = array(), $ver = false ): void {
	$GLOBALS['wcs_test_enqueued']['style'][] = $handle;
}
function wp_enqueue_script( string $handle, string $src = '', array $deps = array(), $ver = false, $in_footer = false ): void {
	$GLOBALS['wcs_test_enqueued']['script'][] = $handle;
}
function wp_add_inline_script( string $handle, string $js, string $position = 'after' ): void {
	$GLOBALS['wcs_test_inline_js'][ $handle ][] = $js;
}
function wp_script_is( string $handle, string $status = 'enqueued' ): bool {
	if ( 'enqueued' === $status ) {
		return in_array( $handle, $GLOBALS['wcs_test_enqueued']['script'] ?? array(), true );
	}
	return (bool) $GLOBALS['wcs_test_script_done'];
}
function add_shortcode( string $tag, callable $cb ): void {}
function shortcode_atts( array $defaults, $atts, string $shortcode = '' ): array {
	$atts = is_array( $atts ) ? $atts : array();
	return array_merge( $defaults, array_intersect_key( $atts, $defaults ) );
}
function rest_url( string $path = '' ): string {
	return 'https://example.test/wp-json/' . ltrim( $path, '/' );
}
function admin_url( string $path = '' ): string {
	return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
}
function home_url( string $path = '' ): string {
	return 'https://example.test' . $path;
}
function site_url( string $path = '' ): string {
	return 'https://example.test' . $path;
}
function wp_parse_url( string $url, int $component = -1 ) {
	return $component === -1 ? parse_url( $url ) : parse_url( $url, $component );
}
function get_bloginfo( string $show = '' ): string {
	return $show === 'version' ? '6.5' : 'Test Site';
}
function wp_create_nonce( $action = -1 ): string {
	return 'nonce-' . $action;
}
function get_search_query(): string {
	return '';
}
function sanitize_html_class( string $classname ): string {
	return preg_replace( '/[^A-Za-z0-9_-]/', '', $classname );
}
function esc_attr( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES );
}
function esc_attr__( string $text, string $domain = 'default' ): string {
	return $text;
}
function esc_url( string $url ): string {
	return $url;
}
function esc_js( string $text ): string {
	return addslashes( $text );
}
function wp_kses( string $string, $allowed_html, $allowed_protocols = array() ): string {
	// Test stub: real wp_kses strips disallowed tags/attrs; for these tests
	// the input is always already-safe plugin-authored markup, so pass through.
	return $string;
}
function esc_textarea( string $text ): string {
	return htmlspecialchars( $text, ENT_QUOTES );
}
function esc_html_e( string $text, string $domain = 'default' ): void {
	echo htmlspecialchars( $text, ENT_QUOTES );
}
function settings_fields( string $group ): void {
	echo '<!-- settings_fields:' . esc_html( $group ) . ' -->';
}
function submit_button(): void {
	echo '<!-- submit_button -->';
}
function checked( $checked, $current = true, bool $display = true ): string {
	$result = (string) $checked === (string) $current ? " checked='checked'" : '';
	if ( $display ) {
		echo $result;
	}
	return $result;
}
function disabled( $disabled, $current = true, bool $display = true ): string {
	$result = (string) $disabled === (string) $current ? " disabled='disabled'" : '';
	if ( $display ) {
		echo $result;
	}
	return $result;
}
function human_time_diff( int $from, int $to = 0 ): string {
	return '5 mins';
}
function get_current_screen(): ?object {
	return $GLOBALS['wcs_test_screen_id'] ? (object) array( 'id' => $GLOBALS['wcs_test_screen_id'] ) : null;
}
function wp_count_posts( string $type = 'post' ): object {
	return (object) array( 'publish' => $GLOBALS['wcs_test_publish_count'] );
}
function register_setting( string $group, string $name, array $args = array() ): void {
	$GLOBALS['wcs_test_registered_settings'][ $name ] = $args;
}
function add_options_page( ...$args ): void {}

// ── Posts / products / terms ───────────────────────────────────────────────
function get_post( int $id ): ?object {
	return $GLOBALS['wcs_test_posts'][ $id ] ?? null;
}
function get_post_type( int $id ): string|false {
	$post = get_post( $id );
	return $post->post_type ?? false;
}
function _prime_post_caches( array $ids, bool $terms = true, bool $meta = true ): void {
	$GLOBALS['wcs_test_primed'][] = $ids;
}
function get_post_thumbnail_id( int $id ): int {
	return (int) ( $GLOBALS['wcs_test_thumbs'][ $id ] ?? 0 );
}
function wp_get_attachment_image_url( int $attachment_id, $size = 'thumbnail' ) {
	return $attachment_id ? "https://example.test/img/{$attachment_id}.jpg" : false;
}
function get_the_terms( int $id, string $taxonomy ) {
	return $GLOBALS['wcs_test_terms'][ $id ][ $taxonomy ] ?? false;
}
function wp_get_post_terms( int $id, $taxonomies, array $args = array() ): array {
	$names = array();
	foreach ( (array) $taxonomies as $tax ) {
		$terms = get_the_terms( $id, $tax );
		if ( is_array( $terms ) ) {
			foreach ( $terms as $t ) {
				$names[] = $t->name;
			}
		}
	}
	return $names;
}
function get_permalink( $post ): string {
	$id = is_object( $post ) ? (int) $post->ID : (int) $post;
	return "https://example.test/?p={$id}";
}
function taxonomy_exists( string $taxonomy ): bool {
	return in_array( $taxonomy, $GLOBALS['wcs_test_taxonomies'], true );
}
function wc_get_attribute_taxonomy_names(): array {
	return array_values( array_filter( $GLOBALS['wcs_test_taxonomies'], static fn( $t ) => str_starts_with( $t, 'pa_' ) ) );
}
function wc_get_product( int $id ) {
	return $GLOBALS['wcs_test_products'][ $id ] ?? false;
}
function wc_get_product_ids_on_sale(): array {
	return $GLOBALS['wcs_test_on_sale_ids'];
}
function get_objects_in_term( int $term_id, string $taxonomy ) {
	return $GLOBALS['wcs_test_objects_in_term'] ?? array();
}
function get_term_link( $term, string $taxonomy = '' ) {
	return 'https://example.test/tax/' . $taxonomy . '/' . ( is_object( $term ) ? $term->term_id : $term );
}
function is_wp_error( $thing ): bool {
	return $thing instanceof WP_Error;
}

/** Configurable fake WC_Product. */
class Fake_Product {
	public function __construct( private array $props = array() ) {}
	public function get_id(): int {
		return (int) ( $this->props['id'] ?? 0 );
	}
	public function get_status(): string {
		return $this->props['status'] ?? 'publish';
	}
	public function is_type( string $type ): bool {
		return ( $this->props['type'] ?? 'simple' ) === $type;
	}
	public function get_parent_id(): int {
		return (int) ( $this->props['parent_id'] ?? 0 );
	}
	public function get_price(): string {
		return (string) ( $this->props['price'] ?? '0' );
	}
	public function get_variation_prices(): array {
		return $this->props['variation_prices'] ?? array( 'price' => array() );
	}
	public function get_sku(): string {
		return $this->props['sku'] ?? '';
	}
	public function get_title(): string {
		return $this->props['title'] ?? '';
	}
	public function get_stock_status(): string {
		return $this->props['stock_status'] ?? 'instock';
	}
	public function get_total_sales(): int {
		return (int) ( $this->props['total_sales'] ?? 0 );
	}
	public function get_image_id(): int {
		return (int) ( $this->props['image_id'] ?? 0 );
	}
	public function get_permalink(): string {
		return $this->props['permalink'] ?? ( 'https://example.test/?p=' . $this->get_id() );
	}
	public function get_short_description(): string {
		return $this->props['short_description'] ?? '';
	}
}

// ── Cron / scheduling / admin context ──────────────────────────────────────
function wp_next_scheduled( string $hook ) {
	return $GLOBALS['wcs_test_cron'][ $hook ] ?? false;
}
function wp_schedule_event( int $timestamp, string $recurrence, string $hook ): bool {
	$GLOBALS['wcs_test_cron'][ $hook ] = $timestamp;
	return true;
}
function wp_unschedule_event( int $timestamp, string $hook ): bool {
	unset( $GLOBALS['wcs_test_cron'][ $hook ] );
	return true;
}
function is_admin(): bool {
	return ! empty( $GLOBALS['wcs_test_is_admin'] );
}
function is_multisite(): bool {
	return false;
}
function dbDelta( $queries ): array {
	$GLOBALS['wcs_test_dbdelta'][] = $queries;
	return array();
}
function wp_mkdir_p( string $dir ): bool {
	return is_dir( $dir ) || mkdir( $dir, 0777, true );
}
function trailingslashit( string $string ): string {
	return rtrim( $string, '/\\' ) . '/';
}
function wp_doing_ajax(): bool {
	return false;
}

// WOOCS main class marker — Search_Handler::get_exchange_rate() gates the
// woocs_currencies option read behind class_exists('WOOCS').
class WOOCS {}

// ── Action Scheduler stubs (store seam + action object) ────────────────────
class ActionScheduler_Action {
	public function __construct( private string $hook, private array $args ) {}
	public function get_hook(): string {
		return $this->hook;
	}
	public function get_args(): array {
		return $this->args;
	}
}
class ActionScheduler {
	public static function store(): object {
		return new class() {
			public function mark_failure( int $action_id ): void {
				if ( ! empty( $GLOBALS['wcs_test_mark_failure_throws'] ) ) {
					throw new RuntimeException( 'claim conflict' );
				}
				$GLOBALS['wcs_test_marked_failed'][] = $action_id;
			}
			public function fetch_action( int $action_id ): ?ActionScheduler_Action {
				return $GLOBALS['wcs_test_as_actions'][ $action_id ] ?? null;
			}
		};
	}
}

// Quiet logger: Logger::log() prefers wc_get_logger(); record instead of error_log noise.
function wc_get_logger(): object {
	return new class() {
		public function log( string $level, string $message, array $context = array() ): void {
			$GLOBALS['wcs_test_logs'][] = array( 'level' => $level, 'message' => $message );
		}
	};
}

// ── Minimal WP_Error ───────────────────────────────────────────────────────
class WP_Error {
	public function __construct(
		public string $code = '',
		public string $message = '',
		public $data = null
	) {}
	public function get_error_code(): string {
		return $this->code;
	}
}

// ── Scriptable fake wpdb ───────────────────────────────────────────────────
class Fake_WPDB {
	public string $prefix     = 'wp_';
	public string $posts      = 'wp_posts';
	public string $postmeta   = 'wp_postmeta';
	public string $options    = 'wp_options';
	public string $usermeta   = 'wp_usermeta';
	public string $blogs      = 'wp_blogs';
	public string $terms      = 'wp_terms';
	public string $term_taxonomy = 'wp_term_taxonomy';
	public string $last_error = '';

	/** @var string[] Every SQL string executed, in order. */
	public array $queries = array();

	/** @var callable|null fn(string $sql, string $type): mixed — scripted results. */
	public $handler = null;

	private bool $suppress = false;

	public function esc_like( string $text ): string {
		return addcslashes( $text, '_%\\' );
	}

	/** Positional %s/%d/%f/%i substitution, mirroring wpdb::prepare closely enough for assertions. */
	public function prepare( string $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback(
			'/%(?:%|[sdfi])/',
			function ( array $m ) use ( &$i, $args ) {
				if ( '%%' === $m[0] ) {
					return '%';
				}
				$val = $args[ $i++ ] ?? null;
				switch ( $m[0] ) {
					case '%d':
						return (string) (int) $val;
					case '%f':
						return (string) (float) $val;
					case '%i':
						return '`' . str_replace( '`', '``', (string) $val ) . '`';
					default: // %s
						return "'" . addslashes( (string) $val ) . "'";
				}
			},
			$query
		);
	}

	private function run( string $sql, string $type ) {
		$this->queries[] = $sql;
		if ( $this->handler ) {
			return ( $this->handler )( $sql, $type );
		}
		return match ( $type ) {
			'results' => array(),
			'col'     => array(),
			'query'   => 0,
			default   => null,
		};
	}

	public function get_results( string $sql, string $output = OBJECT ) {
		return $this->run( $sql, 'results' );
	}
	public function get_row( string $sql, string $output = OBJECT ) {
		return $this->run( $sql, 'row' );
	}
	public function get_var( string $sql ) {
		return $this->run( $sql, 'var' );
	}
	public function get_col( string $sql ) {
		return $this->run( $sql, 'col' );
	}
	public function query( string $sql ) {
		return $this->run( $sql, 'query' );
	}
	public function replace( string $table, array $data, $formats = null ) {
		$this->queries[] = 'REPLACE INTO ' . $table . ' /* ' . wp_json_encode( $data ) . ' */';
		return 1;
	}
	public function delete( string $table, array $where, $formats = null ) {
		$this->queries[] = 'DELETE FROM ' . $table . ' /* ' . wp_json_encode( $where ) . ' */';
		return 1;
	}
	public function suppress_errors( bool $suppress = true ): bool {
		$prev           = $this->suppress;
		$this->suppress = $suppress;
		return $prev;
	}
	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci';
	}
}

$GLOBALS['wpdb'] = new Fake_WPDB();

// ── Plugin class autoloader (mirrors the one in the main plugin file) ─────
spl_autoload_register( static function ( string $class ): void {
	$prefix = 'WCS\\Search\\';
	if ( 0 !== strncmp( $prefix, $class, strlen( $prefix ) ) ) {
		return;
	}
	$relative = substr( $class, strlen( $prefix ) );
	$file     = WCS_PLUGIN_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

// Namespaced built-in overrides (usleep etc.) — must load before plugin classes.
require_once __DIR__ . '/overrides.php';

require_once __DIR__ . '/../../vendor/autoload.php';
