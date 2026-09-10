<?php
/**
 * Bootstrap for the pure-logic test suite — deliberately NOT a WordPress
 * test bootstrap (no wp-load.php, no test database, no SVN-checked-out WP
 * core test library). See ROADMAP.md / SWAP-with-HOMES.md (2026-09-06) for
 * why: neither Solar's nor Home's session has a local WP/MySQL environment,
 * and standing one up is disproportionate to how this plugin actually ships
 * today (direct FTP/REST to the two live sites).
 *
 * Instead, this defines just enough of a WordPress-shaped world — a handful
 * of function stubs and a fake $wpdb — for the REAL plugin class files to
 * load and run their pure math unmodified. Every stub here exists because a
 * specific method under test (or something it calls) references it; if a
 * new test needs a WP function this file doesn't stub yet, add the stub
 * here rather than reaching for a full WP bootstrap.
 *
 * Deliberately in scope: GAS_Payouts::agent_pool_amount()/compute() (the
 * money math), GAS_Frontend::estimated_payout_range()/partner_covers_state()
 * (coverage matching + the affiliate-facing earnings estimate). NOT in
 * scope: anything touching hooks, REST controllers, real DB schema, or
 * WP user/role functions — the existing live-site smoke-checking already
 * covers that surface in practice (see ROADMAP.md fragility item #7).
 */

error_reporting( E_ALL & ~E_DEPRECATED );

define( 'ABSPATH', __DIR__ . '/' );
define( 'GAS_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'GAS_PLUGIN_FILE', GAS_PLUGIN_DIR . 'gemz-affiliate-suite.php' );

/**
 * In-memory stand-in for the wp_options table, backing the get_option()/
 * update_option() stubs below. Tests can seed it directly via
 * $GLOBALS['gas_test_options'] to exercise non-default settings (e.g. a
 * custom tier split) without going through GAS_Settings::update().
 */
$GLOBALS['gas_test_options'] = array();

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['gas_test_options'] ) ? $GLOBALS['gas_test_options'][ $name ] : $default;
}

function update_option( $name, $value ) {
	$GLOBALS['gas_test_options'][ $name ] = $value;
	return true;
}

function get_bloginfo( $show = '' ) {
	return 'Test Site';
}

/**
 * Added 2026-09-09 — GAS_Payouts::compute() now also runs a tier-stacking
 * identity check (get_details()/get_tax_info() call get_user_meta(); the
 * check itself calls get_userdata()) for every sale, not just tests that
 * exercise that check directly. No real WP user store exists in this
 * harness, so both simply return "nothing on file" — every fingerprint
 * comes back empty, tier_stacking_signals() finds nothing to match, and
 * every existing compute() assertion is unaffected.
 */
function get_userdata( $user_id ) {
	return null;
}

function get_user_meta( $user_id, $key = '', $single = false ) {
	return '';
}

/**
 * Minimal stand-in for $wpdb, covering only what the methods under test
 * actually call: get_results() (a flat list, e.g. approved partners),
 * get_row()/get_var() keyed by an id embedded in prepare()'s output, and
 * prepare() itself. Doesn't attempt real SQL parsing — tests configure
 * canned return data directly (see $codes_by_id / $results), and
 * prepare()/get_row()/get_var() just thread an id through so
 * GAS_Payouts::compute()'s sponsor-chain lookups (`WHERE id = %d`) work
 * against that canned data. Sufficient for every query pattern the four
 * methods under test use; extend it if a future test needs more.
 */
class GAS_Test_Wpdb {
	public $prefix = 'wp_';

	/** @var array<int, object> Keyed by code id, for compute()'s sponsor_code_id lookups. */
	public $codes_by_id = array();

	/** @var array<int, object> */
	public $results = array();

	public function prepare( $query, ...$args ) {
		return $query . ' /*ARGS:' . implode( ',', $args ) . '*/';
	}

	public function get_row( $query ) {
		if ( preg_match( '/ARGS:(\d+)/', $query, $m ) ) {
			$id = (int) $m[1];
			return $this->codes_by_id[ $id ] ?? null;
		}
		return null;
	}

	public function get_var( $query ) {
		$row = $this->get_row( $query );
		return $row ? current( (array) $row ) : null;
	}

	public function get_results( $query ) {
		return $this->results;
	}
}

$GLOBALS['wpdb'] = new GAS_Test_Wpdb();

require_once GAS_PLUGIN_DIR . 'includes/class-gas-settings.php';
require_once GAS_PLUGIN_DIR . 'includes/class-gas-db.php';
require_once GAS_PLUGIN_DIR . 'includes/class-gas-payouts.php';
require_once GAS_PLUGIN_DIR . 'includes/class-gas-frontend.php';
