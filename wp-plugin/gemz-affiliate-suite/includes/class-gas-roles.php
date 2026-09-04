<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GAS_Roles {

	const ROLE         = 'gas_affiliate'; // kept as-is for backward compatibility with existing installs.
	const PARTNER_ROLE = 'gas_partner';
	const MANAGER_ROLE = 'gas_manager';

	/**
	 * Umbrella capability granted only to Administrator and GAS Manager,
	 * used solely as the top-level admin menu's own capability. WordPress
	 * hides an entire top-level menu (and every submenu under it) if the
	 * current user lacks the capability the top-level page itself was
	 * registered with, even if they hold the more specific capability a
	 * particular submenu actually checks — this exists to route around
	 * that, not to gate anything on its own.
	 */
	const ACCESS_ADMIN_CAP = 'gas_access_admin';

	/**
	 * Every custom capability this plugin defines for admin-side
	 * management, one per admin screen. Shared between the administrator
	 * role and the Manager role below so the two stay in lockstep as the
	 * plugin grows — add a new gas_ capability here once and both roles
	 * pick it up on the next request, no separate migration needed.
	 */
	public static function management_caps() {
		return array(
			'gas_manage_partners',
			'gas_manage_codes',
			'gas_manage_leads',
			'gas_manage_commissions',
			'gas_view_reports',
			'gas_view_audit_log',
			'gas_manage_settings',
		);
	}

	public static function add_role() {
		// Self-signup affiliate role: front-end only, no admin capabilities.
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, 'Affiliate', array( 'read' => true ) );
		}

		// Fulfillment partner role: logs in to see and update only their
		// own leads. The self-service portal that actually uses these
		// capabilities is a later phase — this just puts the role and its
		// capabilities in place ahead of it.
		if ( ! get_role( self::PARTNER_ROLE ) ) {
			add_role( self::PARTNER_ROLE, 'Fulfillment Partner', array(
				'read'                       => true,
				'gas_view_own_leads'         => true,
				'gas_update_own_lead_status' => true,
			) );
		}

		// Administrators get every plugin capability, same as they always
		// implicitly had via manage_options — this just makes it explicit
		// so admin-screen checks can move off manage_options without
		// narrowing what an actual site Administrator can do.
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( self::ACCESS_ADMIN_CAP );
			foreach ( self::management_caps() as $cap ) {
				$admin->add_cap( $cap );
			}
		}

		// Manager: a non-administrator staff role with full run of this
		// plugin's screens, but deliberately never manage_options or any
		// other site-wide capability — can't install/activate plugins,
		// edit themes, manage users, or touch general WP settings, even
		// though they have full run of the affiliate program itself.
		// Re-applied on every request (not just role creation) so it
		// picks up new gas_ capabilities automatically as the plugin
		// grows, same reasoning as the administrator block above.
		if ( ! get_role( self::MANAGER_ROLE ) ) {
			add_role( self::MANAGER_ROLE, 'Affiliate Program Manager', array( 'read' => true ) );
		}
		$manager = get_role( self::MANAGER_ROLE );
		if ( $manager ) {
			$manager->add_cap( self::ACCESS_ADMIN_CAP );
			foreach ( self::management_caps() as $cap ) {
				$manager->add_cap( $cap );
			}

			// Belt-and-suspenders: explicitly deny anything dangerous, even
			// if some other plugin or a future WP core change tries to
			// grant it by default.
			$explicitly_denied = array(
				'install_plugins', 'activate_plugins', 'edit_plugins', 'update_plugins', 'delete_plugins',
				'switch_themes', 'edit_themes', 'install_themes', 'update_themes', 'delete_themes', 'edit_theme_options',
				'list_users', 'create_users', 'edit_users', 'delete_users', 'promote_users', 'remove_users',
				'manage_options', 'update_core',
			);
			foreach ( $explicitly_denied as $cap ) {
				$manager->remove_cap( $cap );
			}
		}
	}

	public static function remove_role() {
		remove_role( self::ROLE );
	}

	public static function is_affiliate( $user = null ) {
		$user = $user ? $user : wp_get_current_user();
		return $user && in_array( self::ROLE, (array) $user->roles, true );
	}

	public static function is_partner( $user = null ) {
		$user = $user ? $user : wp_get_current_user();
		return $user && in_array( self::PARTNER_ROLE, (array) $user->roles, true );
	}
}
