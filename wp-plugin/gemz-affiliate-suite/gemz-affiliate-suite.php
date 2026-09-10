<?php
/**
 * Plugin Name: Gemz Affiliate Suite
 * Description: Reusable, admin-only sub-affiliate click tracking and payout calculator for Gemz referral/affiliate sites. Configure the program name, partner terminology, and signup requirements from Settings. Not visible to site visitors except the /go/{code} redirect itself.
 * Version: 1.0.0
 * Author: Cary Robinson
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAS_VERSION', '2.9.0' );
define( 'GAS_DB_VERSION', '16' );
define( 'GAS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GAS_PLUGIN_FILE', __FILE__ );

require_once GAS_PLUGIN_DIR . 'includes/class-gas-settings.php';
require_once GAS_PLUGIN_DIR . 'includes/class-gas-roles.php';
require_once GAS_PLUGIN_DIR . 'includes/class-gas-db.php';
require_once GAS_PLUGIN_DIR . 'includes/class-gas-fraud.php';
require_once GAS_PLUGIN_DIR . 'includes/class-gas-payouts.php';
require_once GAS_PLUGIN_DIR . 'includes/class-gas-cashback.php';
require_once GAS_PLUGIN_DIR . 'includes/class-gas-paypal-payouts.php';
require_once GAS_PLUGIN_DIR . 'includes/class-gas-wise-payouts.php';
require_once GAS_PLUGIN_DIR . 'includes/class-gas-campaigns.php';
require_once GAS_PLUGIN_DIR . 'includes/class-gas-marketing-assets.php';
require_once GAS_PLUGIN_DIR . 'includes/class-gas-redirect.php';
require_once GAS_PLUGIN_DIR . 'includes/class-gas-leads.php';
require_once GAS_PLUGIN_DIR . 'includes/class-gas-partner-portal.php';
require_once GAS_PLUGIN_DIR . 'includes/class-gas-contacts.php';
require_once GAS_PLUGIN_DIR . 'includes/class-gas-help.php';
require_once GAS_PLUGIN_DIR . 'includes/class-gas-admin.php';
require_once GAS_PLUGIN_DIR . 'includes/class-gas-frontend.php';
require_once GAS_PLUGIN_DIR . 'includes/class-gas-rest.php';

register_activation_hook( __FILE__, array( 'GAS_DB', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'GAS_Redirect', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'GAS_DB', 'maybe_upgrade' ) );
// Re-applied on every request (cheap — WP caches roles), not just at
// activation/upgrade, so a new gas_ capability reaches the Administrator
// and Manager roles on an already-active install without needing a DB
// version bump or reactivation.
add_action( 'plugins_loaded', array( 'GAS_Roles', 'add_role' ) );

// Safety net for the daily stale-lead check: WP-Cron's scheduled-events
// option isn't restored by re-uploading plugin files (only by the
// activation hook, or an explicit check like this one), so this makes
// sure the schedule exists on every request rather than only once at
// activation — same reasoning gemz-referral-crm uses for its equivalent.
add_action( 'plugins_loaded', function() {
	if ( ! wp_next_scheduled( 'gas_daily_stale_lead_check' ) ) {
		wp_schedule_event( time(), 'daily', 'gas_daily_stale_lead_check' );
	}
} );
register_deactivation_hook( __FILE__, function() {
	wp_clear_scheduled_hook( 'gas_daily_stale_lead_check' );
} );

GAS_Campaigns::init();
GAS_Cashback::init();
GAS_Marketing_Assets::init();
GAS_Redirect::init();
GAS_Leads::init();
GAS_Partner_Portal::init();
GAS_Contacts::init();
GAS_Help::init();
GAS_Admin::init();
GAS_Frontend::init();
GAS_REST::init();
