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

define( 'GAS_VERSION', '1.0.0' );
define( 'GAS_DB_VERSION', '2' );
define( 'GAS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GAS_PLUGIN_FILE', __FILE__ );

require_once GAS_PLUGIN_DIR . 'includes/class-gas-settings.php';
require_once GAS_PLUGIN_DIR . 'includes/class-gas-roles.php';
require_once GAS_PLUGIN_DIR . 'includes/class-gas-db.php';
require_once GAS_PLUGIN_DIR . 'includes/class-gas-payouts.php';
require_once GAS_PLUGIN_DIR . 'includes/class-gas-paypal-payouts.php';
require_once GAS_PLUGIN_DIR . 'includes/class-gas-wise-payouts.php';
require_once GAS_PLUGIN_DIR . 'includes/class-gas-redirect.php';
require_once GAS_PLUGIN_DIR . 'includes/class-gas-admin.php';
require_once GAS_PLUGIN_DIR . 'includes/class-gas-frontend.php';
require_once GAS_PLUGIN_DIR . 'includes/class-gas-rest.php';

register_activation_hook( __FILE__, array( 'GAS_DB', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'GAS_Redirect', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'GAS_DB', 'maybe_upgrade' ) );

GAS_Redirect::init();
GAS_Admin::init();
GAS_Frontend::init();
GAS_REST::init();
