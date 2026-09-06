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
			'gas_manage_contacts',
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

	/**
	 * Auto-provisions (or links) a WP user account for a partner so they
	 * can log in to their own portal — called whenever a partner is saved
	 * with an email and doesn't have one yet. If that email already
	 * belongs to a WP user, the partner role is ADDED to that account
	 * rather than creating a duplicate, so one person can hold both an
	 * affiliate account and a partner account without two disconnected
	 * logins. Ported from gemz-referral-crm's GRC_Roles::provision_partner_account().
	 */
	public static function provision_partner_account( $partner_id ) {
		global $wpdb;
		$table   = GAS_DB::table( 'partners' );
		$partner = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $partner_id ) );

		if ( ! $partner || empty( $partner->email ) || ! empty( $partner->user_id ) ) {
			return; // no email to invite, or already has an account
		}

		$existing_user = get_user_by( 'email', $partner->email );

		if ( $existing_user ) {
			$existing_user->add_role( self::PARTNER_ROLE );
			$wpdb->update( $table, array( 'user_id' => $existing_user->ID ), array( 'id' => $partner_id ) );
			return;
		}

		$base_username = sanitize_user( current( explode( '@', $partner->email ) ), true );
		$username      = $base_username ?: 'partner';
		$suffix        = 1;
		while ( username_exists( $username ) ) {
			$username = $base_username . $suffix;
			$suffix++;
		}

		$user_id = wp_insert_user( array(
			'user_login'   => $username,
			'user_email'   => $partner->email,
			'user_pass'    => wp_generate_password( 20 ),
			'display_name' => $partner->name,
			'role'         => self::PARTNER_ROLE,
		) );

		if ( is_wp_error( $user_id ) ) {
			return;
		}

		$wpdb->update( $table, array( 'user_id' => $user_id ), array( 'id' => $partner_id ) );

		// WP core's standard "set your new password" email, same mechanism
		// as the "forgot password" flow, just triggered proactively.
		retrieve_password( $partner->email );
	}

	/**
	 * Is the current user an admin actively previewing a specific
	 * affiliate's or partner's dashboard (read-only)? Set by
	 * GAS_Admin::handle_start_admin_preview() via a short-lived per-admin
	 * transient — never a real row anywhere, so previewing can't pollute
	 * codes/leads/payouts with fake data. Re-checks the relevant
	 * capability here too (not just when the transient was set), so a
	 * stale transient can't outlive a role change. Ported from
	 * gemz-referral-crm's GRC_Roles::get_admin_preview().
	 *
	 * @return array{type:string,id:int}|null 'id' is a wp_user_id for
	 *   type 'agent' (an affiliate can hold more than one code, so the
	 *   dashboard is scoped by user, not by a single code), or a
	 *   partners.id for type 'partner'.
	 */
	public static function get_admin_preview() {
		if ( ! is_user_logged_in() ) {
			return null;
		}
		$preview = get_transient( 'gas_admin_preview_' . get_current_user_id() );
		if ( ! is_array( $preview ) || empty( $preview['type'] ) || empty( $preview['id'] ) ) {
			return null;
		}
		if ( 'agent' === $preview['type'] && ! current_user_can( 'gas_manage_codes' ) ) {
			return null;
		}
		if ( 'partner' === $preview['type'] && ! current_user_can( 'gas_manage_partners' ) ) {
			return null;
		}
		return $preview;
	}
}
