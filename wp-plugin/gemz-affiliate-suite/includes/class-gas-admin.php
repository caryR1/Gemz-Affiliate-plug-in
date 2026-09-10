<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GAS_Admin {

	// Per-screen capabilities (granted to Administrator and GAS_Roles::MANAGER_ROLE,
	// see class-gas-roles.php) — replaced the old blanket manage_options
	// check so a non-Administrator "Affiliate Program Manager" can run
	// this plugin day-to-day without also being able to install plugins,
	// manage users, or touch general WordPress settings.
	const CAP_CODES       = 'gas_manage_codes';
	const CAP_PARTNERS    = 'gas_manage_partners';
	const CAP_CAMPAIGNS   = 'gas_manage_campaigns';
	const CAP_LEADS       = 'gas_manage_leads';
	const CAP_COMMISSIONS = 'gas_manage_commissions';
	const CAP_REPORTS     = 'gas_view_reports';
	const CAP_SETTINGS    = 'gas_manage_settings';
	const CAP_CONTACTS    = 'gas_manage_contacts';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_media_library' ) );
		add_action( 'admin_post_gas_save_partner', array( __CLASS__, 'handle_save_partner' ) );
		add_action( 'admin_post_gas_add_partner', array( __CLASS__, 'handle_add_partner' ) );
		add_action( 'admin_post_gas_save_code', array( __CLASS__, 'handle_save_code' ) );
		add_action( 'admin_post_gas_delete_code', array( __CLASS__, 'handle_delete_code' ) );
		add_action( 'admin_post_gas_calculate_payout', array( __CLASS__, 'handle_calculate_payout' ) );
		add_action( 'admin_post_gas_delete_payout', array( __CLASS__, 'handle_delete_payout' ) );
		add_action( 'admin_post_gas_suspend_affiliate', array( __CLASS__, 'handle_suspend_affiliate' ) );
		add_action( 'admin_post_gas_reactivate_affiliate', array( __CLASS__, 'handle_reactivate_affiliate' ) );
		add_action( 'admin_post_gas_save_settings', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_gas_export_ledger_csv', array( __CLASS__, 'handle_export_ledger_csv' ) );
		add_action( 'admin_post_gas_export_tax_summary_csv', array( __CLASS__, 'handle_export_tax_summary_csv' ) );
		add_action( 'admin_post_gas_save_payout_api_settings', array( __CLASS__, 'handle_save_payout_api_settings' ) );
		add_action( 'admin_post_gas_paypal_payout_now', array( __CLASS__, 'handle_paypal_payout_now' ) );
		add_action( 'admin_post_gas_wise_payout_now', array( __CLASS__, 'handle_wise_payout_now' ) );
		add_action( 'admin_post_gas_reassign_contact', array( __CLASS__, 'handle_reassign_contact' ) );
		add_action( 'admin_post_gas_export_contacts_csv', array( __CLASS__, 'handle_export_contacts_csv' ) );
		add_action( 'admin_post_gas_mark_cashback_paid', array( __CLASS__, 'handle_mark_cashback_paid' ) );
		add_action( 'admin_post_gas_regenerate_payout_token', array( __CLASS__, 'handle_regenerate_payout_token' ) );
		add_action( 'admin_post_gas_save_lead_magnet', array( __CLASS__, 'handle_save_lead_magnet' ) );
		add_action( 'admin_post_gas_toggle_lead_magnet', array( __CLASS__, 'handle_toggle_lead_magnet' ) );
		add_action( 'admin_post_gas_start_admin_preview', array( __CLASS__, 'handle_start_admin_preview' ) );
		add_action( 'admin_post_gas_stop_admin_preview', array( __CLASS__, 'handle_stop_admin_preview' ) );
	}

	private static function fulfillment_mode_label( $mode ) {
		return 'lead_capture' === $mode ? 'On-site lead capture' : 'Redirect to partner site';
	}

	public static function add_menu() {
		$site_name = GAS_Settings::get( 'site_name' );
		$icon      = GAS_Settings::get( 'menu_icon' );

		add_menu_page(
			$site_name,
			$site_name,
			GAS_Roles::ACCESS_ADMIN_CAP,
			'gas-affiliates',
			array( __CLASS__, 'render_affiliates_page' ),
			$icon,
			58
		);
		add_submenu_page( 'gas-affiliates', 'Affiliates', 'Affiliates', self::CAP_CODES, 'gas-affiliates', array( __CLASS__, 'render_affiliates_page' ) );
		add_submenu_page( 'gas-affiliates', 'Partners', 'Partners', self::CAP_PARTNERS, 'gas-partners', array( __CLASS__, 'render_partners_page' ) );
		add_submenu_page( 'gas-affiliates', 'Campaigns', 'Campaigns', self::CAP_CAMPAIGNS, 'gas-campaigns', array( __CLASS__, 'render_campaigns_page' ) );
		add_submenu_page( 'gas-affiliates', 'Marketing Assets', 'Marketing Assets', self::CAP_CAMPAIGNS, 'gas-marketing-assets', array( __CLASS__, 'render_marketing_assets_page' ) );
		add_submenu_page( 'gas-affiliates', 'Leads', 'Leads', self::CAP_LEADS, 'gas-leads', array( __CLASS__, 'render_leads_page' ) );
		add_submenu_page( 'gas-affiliates', 'Click Log', 'Click Log', self::CAP_REPORTS, 'gas-clicks', array( __CLASS__, 'render_clicks_page' ) );
		add_submenu_page( 'gas-affiliates', 'Reports', 'Reports', self::CAP_REPORTS, 'gas-reports', array( __CLASS__, 'render_reports_page' ) );
		add_submenu_page( 'gas-affiliates', 'Payout Calculator', 'Payout Calculator', self::CAP_COMMISSIONS, 'gas-calculator', array( __CLASS__, 'render_calculator_page' ) );
		add_submenu_page( 'gas-affiliates', 'Payout Ledger', 'Payout Ledger', self::CAP_COMMISSIONS, 'gas-ledger', array( __CLASS__, 'render_ledger_page' ) );
		add_submenu_page( 'gas-affiliates', 'Audit Log', 'Audit Log', 'gas_view_audit_log', 'gas-audit-log', array( __CLASS__, 'render_audit_log_page' ) );
		add_submenu_page( 'gas-affiliates', 'Segments', 'Segments', self::CAP_CONTACTS, 'gas-segments', array( __CLASS__, 'render_segments_page' ) );
		add_submenu_page( 'gas-affiliates', 'Lead Magnets', 'Lead Magnets', self::CAP_CONTACTS, 'gas-lead-magnets', array( __CLASS__, 'render_lead_magnets_page' ) );
		add_submenu_page( 'gas-affiliates', 'Settings', 'Settings', self::CAP_SETTINGS, 'gas-settings', array( __CLASS__, 'render_settings_page' ) );
		add_submenu_page( 'gas-affiliates', 'Help', 'Help', GAS_Roles::ACCESS_ADMIN_CAP, 'gas-help', array( __CLASS__, 'render_admin_help_page' ) );
	}

	/**
	 * Records an admin-side mutation for later review — who did what to
	 * which object, and when. Ported from gemz-referral-crm's
	 * GRC_Admin::audit_log(). Called at the plugin's main mutation points
	 * (partner/code/settings saves, payout entries, lead status changes,
	 * automated payout runs) — not literally every possible action, but
	 * the ones that matter for "who changed this and why."
	 */
	public static function audit_log( $object_type, $object_id, $action, $details = array() ) {
		global $wpdb;
		$wpdb->insert(
			GAS_DB::table( 'audit_log' ),
			array(
				'user_id'     => get_current_user_id(),
				'object_type' => $object_type,
				'object_id'   => $object_id,
				'action'      => $action,
				'details'     => wp_json_encode( $details ),
				'created_at'  => current_time( 'mysql' ),
			)
		);
	}

	private static function wrap_start( $title ) {
		echo '<div class="wrap"><h1>' . esc_html( $title ) . '</h1>';
	}

	private static function wrap_end() {
		echo '</div>';
	}

	private static function get_partners() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . GAS_DB::table( 'partners' ) . ' ORDER BY name ASC' );
	}

	private static function get_partner( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . GAS_DB::table( 'partners' ) . ' WHERE id = %d', $id ) );
	}

	private static function get_codes() {
		global $wpdb;
		$codes_table    = GAS_DB::table( 'codes' );
		$partners_table = GAS_DB::table( 'partners' );
		return $wpdb->get_results(
			"SELECT c.*, p.name AS partner_name, s.sub_affiliate_name AS sponsor_name FROM {$codes_table} c
			 LEFT JOIN {$partners_table} p ON p.id = c.partner_id
			 LEFT JOIN {$codes_table} s ON s.id = c.sponsor_code_id
			 ORDER BY c.created_at DESC"
		);
	}

	private static function get_code( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . GAS_DB::table( 'codes' ) . ' WHERE id = %d', $id ) );
	}

	/* ---------------------------------------------------------------- *
	 * AFFILIATES (self-signups)
	 * ---------------------------------------------------------------- */

	public static function render_affiliates_page() {
		if ( ! current_user_can( self::CAP_CODES ) ) {
			return;
		}
		self::wrap_start( 'Affiliates' );

		if ( isset( $_GET['suspended'] ) ) {
			echo '<div class="notice notice-success"><p>Affiliate suspended &mdash; their link is now inactive.</p></div>';
		}
		if ( isset( $_GET['reactivated'] ) ) {
			echo '<div class="notice notice-success"><p>Affiliate reactivated &mdash; their link is live again.</p></div>';
		}
		if ( isset( $_GET['code_saved'] ) ) {
			echo '<div class="notice notice-success"><p>Code saved.</p></div>';
		}
		if ( isset( $_GET['code_deleted'] ) ) {
			echo '<div class="notice notice-success"><p>Code deleted.</p></div>';
		}

		global $wpdb;
		$codes_table    = GAS_DB::table( 'codes' );
		$partners_table = GAS_DB::table( 'partners' );

		$rows = $wpdb->get_results(
			"SELECT c.*, p.name AS partner_name FROM {$codes_table} c
			 LEFT JOIN {$partners_table} p ON p.id = c.partner_id
			 WHERE c.wp_user_id IS NOT NULL
			 ORDER BY c.created_at DESC"
		);

		if ( ! $rows ) {
			echo '<p>No self-signup affiliates yet. New signups from the "Become an Affiliate" page show up here.</p>';
			self::render_codes_section();
			self::wrap_end();
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Email</th><th>Partner</th><th>Code</th><th>Status</th><th>Payment info</th><th>Tax info</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$user    = get_userdata( $r->wp_user_id );
			$email   = $user ? $user->user_email : '(deleted user)';
			$payment = $r->wp_user_id ? GAS_Payouts::masked_summary( $r->wp_user_id ) : '';
			$tax     = $r->wp_user_id ? GAS_Payouts::masked_tax_summary( $r->wp_user_id ) : '';

			echo '<tr>';
			echo '<td>' . esc_html( $r->sub_affiliate_name ) . '</td>';
			echo '<td>' . esc_html( $email ) . '</td>';
			echo '<td>' . esc_html( $r->partner_name ?: '(unassigned)' ) . '</td>';
			echo '<td><code>' . esc_html( $r->code ) . '</code></td>';
			echo '<td>' . esc_html( $r->status ) . '</td>';
			echo '<td>' . ( $payment ? esc_html( $payment ) : '<em>not set</em>' ) . '</td>';
			echo '<td>' . ( $tax ? '<span style="color:#1a7a3c;">' . esc_html( $tax ) . '</span>' : '<span style="color:#b32d2e;">not on file</span>' ) . '</td>';
			echo '<td>';
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=gas-affiliates&edit_code=' . $r->id . '#gas-codes-section' ) ) . '">Edit</a> | ';

			if ( $r->wp_user_id ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
				wp_nonce_field( 'gas_start_admin_preview' );
				echo '<input type="hidden" name="action" value="gas_start_admin_preview">';
				echo '<input type="hidden" name="preview_type" value="agent">';
				echo '<input type="hidden" name="record_id" value="' . esc_attr( $r->wp_user_id ) . '">';
				echo '<button type="submit" class="button-link">View Dashboard</button> | ';
				echo '</form>';
			}

			if ( 'suspended' === $r->status ) {
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=gas_reactivate_affiliate&id=' . $r->id ), 'gas_reactivate_affiliate_' . $r->id );
				echo '<a href="' . esc_url( $url ) . '">Reactivate</a>';
			} else {
				$url = wp_nonce_url( admin_url( 'admin-post.php?action=gas_suspend_affiliate&id=' . $r->id ), 'gas_suspend_affiliate_' . $r->id );
				echo '<a href="' . esc_url( $url ) . '" onclick="return confirm(\'Suspend this affiliate? Their link will stop working immediately.\');">Suspend</a>';
			}

			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		self::render_codes_section();

		self::wrap_end();
	}

	public static function handle_suspend_affiliate() {
		if ( ! current_user_can( self::CAP_CODES ) ) {
			wp_die( 'Not allowed.' );
		}
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'gas_suspend_affiliate_' . $id );

		$code = self::get_code( $id );
		if ( ! $code ) {
			wp_die( 'Code not found.' );
		}

		global $wpdb;
		$wpdb->update(
			GAS_DB::table( 'codes' ),
			array( 'status' => 'suspended', 'active' => 0 ),
			array( 'id' => $id )
		);

		if ( $code->wp_user_id ) {
			update_user_meta( $code->wp_user_id, 'gas_status', 'suspended' );
		}

		self::audit_log( 'code', $id, 'suspended' );

		wp_safe_redirect( admin_url( 'admin.php?page=gas-affiliates&suspended=1' ) );
		exit;
	}

	public static function handle_reactivate_affiliate() {
		if ( ! current_user_can( self::CAP_CODES ) ) {
			wp_die( 'Not allowed.' );
		}
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'gas_reactivate_affiliate_' . $id );

		$code = self::get_code( $id );
		if ( ! $code ) {
			wp_die( 'Code not found.' );
		}

		global $wpdb;
		$wpdb->update(
			GAS_DB::table( 'codes' ),
			array( 'status' => 'active', 'active' => 1 ),
			array( 'id' => $id )
		);

		if ( $code->wp_user_id ) {
			update_user_meta( $code->wp_user_id, 'gas_status', 'active' );
		}

		self::audit_log( 'code', $id, 'reactivated' );

		wp_safe_redirect( admin_url( 'admin.php?page=gas-affiliates&reactivated=1' ) );
		exit;
	}

	/* ---------------------------------------------------------------- *
	 * CODES (sub-affiliates) — folded into the Affiliates screen
	 * 2026-09-10 as a section rather than its own top-level menu item.
	 * Its original main job (matching a new self-signup affiliate's code
	 * to a partner) is gone now that campaigns auto-provision that; what's
	 * left — manually adding an offline-referral code, auditing/
	 * deactivating one — fits better as a section here than its own menu
	 * entry. Not moved into Settings: Settings holds config values, not
	 * per-row data, which would be a structural mismatch.
	 * ---------------------------------------------------------------- */

	private static function render_codes_section() {
		$edit_id  = isset( $_GET['edit_code'] ) ? absint( $_GET['edit_code'] ) : 0;
		$editing  = $edit_id ? self::get_code( $edit_id ) : null;
		$partners = self::get_partners();

		echo '<h2 id="gas-codes-section">Codes</h2>';
		echo '<p class="description">Self-signup affiliates get a code automatically above &mdash; this is only for manually adding an offline-referral code, or auditing/deactivating one.</p>';

		echo '<h3>' . ( $editing ? 'Edit Code' : 'Add a Code' ) . '</h3>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'gas_save_code' );
		echo '<input type="hidden" name="action" value="gas_save_code">';
		if ( $editing ) {
			echo '<input type="hidden" name="id" value="' . esc_attr( $editing->id ) . '">';
		}
		echo '<table class="form-table"><tbody>';

		echo '<tr><th><label for="code">Code</label></th><td><input type="text" id="code" name="code" class="regular-text" required pattern="[a-zA-Z0-9_-]+" value="' . esc_attr( $editing->code ?? '' ) . '"> <p class="description">Used in the link as yoursite.com/go/{code}. Letters, numbers, hyphens, underscores only.</p></td></tr>';

		echo '<tr><th><label for="sub_affiliate_name">Sub-affiliate name</label></th><td><input type="text" id="sub_affiliate_name" name="sub_affiliate_name" class="regular-text" required value="' . esc_attr( $editing->sub_affiliate_name ?? '' ) . '"></td></tr>';

		echo '<tr><th><label for="partner_id">Partner</label></th><td><select id="partner_id" name="partner_id" required>';
		echo '<option value="">-- select --</option>';
		foreach ( $partners as $p ) {
			$sel = ( $editing && (int) $editing->partner_id === (int) $p->id ) ? ' selected' : '';
			echo '<option value="' . esc_attr( $p->id ) . '"' . $sel . '>' . esc_html( $p->name ) . '</option>';
		}
		echo '</select> <p class="description">Which ' . esc_html( GAS_Settings::get( 'partner_label' ) ) . ' this sub-affiliate\'s traffic goes to.</p></td></tr>';

		echo '<tr><th><label for="active">Active</label></th><td><label><input type="checkbox" id="active" name="active" value="1"' . checked( $editing->active ?? 1, 1, false ) . '> Redirect and log clicks for this code</label></td></tr>';

		echo '<tr><th><label for="notes">Notes</label></th><td><textarea id="notes" name="notes" class="large-text" rows="2">' . esc_textarea( $editing->notes ?? '' ) . '</textarea></td></tr>';

		echo '</tbody></table>';
		submit_button( $editing ? 'Update Code' : 'Add Code' );
		echo '</form>';

		if ( $editing ) {
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=gas-affiliates#gas-codes-section' ) ) . '">&larr; Cancel edit</a></p>';
		}

		echo '<h3>Existing Codes</h3>';
		$codes = self::get_codes();
		if ( ! $codes ) {
			echo '<p>No codes yet.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Code</th><th>Link</th><th>Sub-affiliate</th><th>Sponsored by</th><th>Partner</th><th>Active</th><th>Actions</th></tr></thead><tbody>';
			foreach ( $codes as $c ) {
				$link = home_url( '/go/' . rawurlencode( $c->code ) . '/' );
				echo '<tr>';
				echo '<td><code>' . esc_html( $c->code ) . '</code></td>';
				echo '<td><code>' . esc_html( $link ) . '</code></td>';
				echo '<td>' . esc_html( $c->sub_affiliate_name ) . '</td>';
				echo '<td>' . esc_html( $c->sponsor_name ?: '&mdash;' ) . '</td>';
				echo '<td>' . esc_html( $c->partner_name ?: '(none)' ) . '</td>';
				echo '<td>' . ( $c->active ? 'Yes' : 'No' ) . '</td>';
				echo '<td>';
				echo '<a href="' . esc_url( admin_url( 'admin.php?page=gas-affiliates&edit_code=' . $c->id . '#gas-codes-section' ) ) . '">Edit</a> | ';
				$del_url = wp_nonce_url( admin_url( 'admin-post.php?action=gas_delete_code&id=' . $c->id ), 'gas_delete_code_' . $c->id );
				echo '<a href="' . esc_url( $del_url ) . '" onclick="return confirm(\'Delete this code? Click history stays but will no longer link to a code.\');">Delete</a>';
				echo '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}
	}

	public static function handle_save_code() {
		if ( ! current_user_can( self::CAP_CODES ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'gas_save_code' );

		global $wpdb;
		$table = GAS_DB::table( 'codes' );

		$id                 = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$code               = isset( $_POST['code'] ) ? sanitize_title( wp_unslash( $_POST['code'] ) ) : '';
		$sub_affiliate_name = isset( $_POST['sub_affiliate_name'] ) ? sanitize_text_field( wp_unslash( $_POST['sub_affiliate_name'] ) ) : '';
		$partner_id         = isset( $_POST['partner_id'] ) ? absint( $_POST['partner_id'] ) : 0;
		$active             = isset( $_POST['active'] ) ? 1 : 0;
		$notes              = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		$data = array(
			'code'               => $code,
			'sub_affiliate_name' => $sub_affiliate_name,
			'partner_id'         => $partner_id,
			'active'             => $active,
			'notes'              => $notes,
		);

		if ( $id ) {
			$wpdb->update( $table, $data, array( 'id' => $id ) );
			self::audit_log( 'code', $id, 'updated', $data );
		} else {
			$data['created_at'] = current_time( 'mysql' );
			$wpdb->insert( $table, $data );
			self::audit_log( 'code', $wpdb->insert_id, 'created', $data );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=gas-affiliates&code_saved=1#gas-codes-section' ) );
		exit;
	}

	public static function handle_delete_code() {
		if ( ! current_user_can( self::CAP_CODES ) ) {
			wp_die( 'Not allowed.' );
		}
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'gas_delete_code_' . $id );

		global $wpdb;
		$wpdb->delete( GAS_DB::table( 'codes' ), array( 'id' => $id ) );
		self::audit_log( 'code', $id, 'deleted' );

		wp_safe_redirect( admin_url( 'admin.php?page=gas-affiliates&code_deleted=1#gas-codes-section' ) );
		exit;
	}

	/* ---------------------------------------------------------------- *
	 * PARTNERS
	 * ---------------------------------------------------------------- */

	public static function render_partners_page() {
		if ( ! current_user_can( self::CAP_PARTNERS ) ) {
			return;
		}
		$edit_id  = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$editing  = $edit_id ? self::get_partner( $edit_id ) : null;
		$partners = self::get_partners();

		self::wrap_start( 'Partners' );

		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success"><p>Saved.</p></div>';
		}
		if ( isset( $_GET['added'] ) ) {
			echo '<div class="notice notice-success"><p>Partner added &mdash; set its payout terms and destination URL below when ready.</p></div>';
		}

		if ( $editing ) {
			echo '<h2>Edit Partner: ' . esc_html( $editing->name ) . '</h2>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'gas_save_partner' );
			echo '<input type="hidden" name="action" value="gas_save_partner">';
			echo '<input type="hidden" name="id" value="' . esc_attr( $editing->id ) . '">';
			echo '<table class="form-table"><tbody>';

			echo '<tr><th>Name</th><td><input type="text" name="name" class="regular-text" required value="' . esc_attr( $editing->name ) . '"></td></tr>';

			echo '<tr><th>Payout type</th><td><select name="payout_type" id="payout_type">';
			echo '<option value="flat"' . selected( $editing->payout_type, 'flat', false ) . '>Flat amount per sale</option>';
			echo '<option value="percent"' . selected( $editing->payout_type, 'percent', false ) . '>Percent of sale</option>';
			echo '</select></td></tr>';

			echo '<tr><th>Flat amount ($)</th><td><input type="number" step="0.01" min="0" name="payout_amount" value="' . esc_attr( $editing->payout_amount ?? '' ) . '"> <p class="description">Used only if payout type is Flat.</p></td></tr>';

			echo '<tr><th>Percent (%)</th><td><input type="number" step="0.01" min="0" max="100" name="payout_percent" value="' . esc_attr( $editing->payout_percent ?? '' ) . '"> <p class="description">Used only if payout type is Percent, e.g. 8 for 8%.</p></td></tr>';

			echo '<tr><th>Typical sale amount ($)</th><td><input type="number" step="0.01" min="0" name="typical_sale_amount" value="' . esc_attr( $editing->typical_sale_amount ?? '' ) . '"> <p class="description">Only used (for Percent-type payout) to estimate the earnings range shown to affiliates &mdash; has no effect on real payout calculations, which always use the actual sale amount entered in the Calculator. Leave blank if unsure; this partner is simply left out of the affiliate-facing range until it\'s set.</p></td></tr>';

			echo '<tr><th>Agent commission pool</th><td>';
			echo '<select name="agent_pool_type" id="agent_pool_type">';
			echo '<option value="percent"' . selected( $editing->agent_pool_type ?? 'percent', 'percent', false ) . '>Percent of gross commission</option>';
			echo '<option value="flat"' . selected( $editing->agent_pool_type ?? 'percent', 'flat', false ) . '>Flat amount per sale</option>';
			echo '</select> ';
			echo '<input type="number" step="0.01" min="0" name="agent_pool_value" value="' . esc_attr( $editing->agent_pool_value ?? '100' ) . '"> ';
			echo '<p class="description">How much of the gross commission above is actually divided across the affiliate tiers (Settings &gt; tier split percentages apply to THIS amount, not to the full gross). The rest of gross stays with you as margin, on top of your tier-1 share. Defaults to 100% of gross &mdash; the whole commission is split, nothing held back &mdash; unless set otherwise here.</p>';
			echo '</td></tr>';

			$installments = $editing->installments_json ? json_decode( $editing->installments_json, true ) : array();
			$inst1_label  = $installments[0]['label'] ?? '';
			$inst1_frac   = isset( $installments[0]['fraction'] ) ? $installments[0]['fraction'] * 100 : '';
			$inst2_label  = $installments[1]['label'] ?? '';
			$inst2_frac   = isset( $installments[1]['fraction'] ) ? $installments[1]['fraction'] * 100 : '';

			echo '<tr><th>Installments</th><td>';
			echo '<p class="description">Optional. If this partner pays the percent commission in stages (e.g. deposit then completion), define up to two here. Leave blank for a single full payment.</p>';
			echo 'Installment 1 label: <input type="text" name="inst1_label" value="' . esc_attr( $inst1_label ) . '" placeholder="e.g. Contract signed"> ';
			echo 'is <input type="number" step="0.01" min="0" max="100" name="inst1_pct" value="' . esc_attr( $inst1_frac ) . '" style="width:80px"> % of the total commission<br><br>';
			echo 'Installment 2 label: <input type="text" name="inst2_label" value="' . esc_attr( $inst2_label ) . '" placeholder="e.g. Job complete"> ';
			echo 'is <input type="number" step="0.01" min="0" max="100" name="inst2_pct" value="' . esc_attr( $inst2_frac ) . '" style="width:80px"> % of the total commission';
			echo '</td></tr>';

			echo '<tr><th>Buyer cash back</th><td>';
			echo '<select name="cashback_type">';
			echo '<option value=""' . selected( $editing->cashback_type ?? '', '', false ) . '>None</option>';
			echo '<option value="percent"' . selected( $editing->cashback_type ?? '', 'percent', false ) . '>Percent of commission</option>';
			echo '<option value="flat"' . selected( $editing->cashback_type ?? '', 'flat', false ) . '>Flat dollar amount per sale</option>';
			echo '</select> ';
			echo '<input type="number" step="0.01" min="0" name="cashback_value" value="' . esc_attr( $editing->cashback_value ?? '0' ) . '"> ';
			echo '<p class="description">What the referred customer gets back, paid from the gross commission before the sub-affiliate cut and your net are figured. $0 (None) means no cash back is offered for this partner.</p>';
			echo '</td></tr>';

			echo '<tr><th>Fulfillment</th><td>';
			echo '<select name="fulfillment_mode" id="fulfillment_mode">';
			echo '<option value="redirect"' . selected( $editing->fulfillment_mode ?? 'redirect', 'redirect', false ) . '>Redirect to partner site</option>';
			echo '<option value="lead_capture"' . selected( $editing->fulfillment_mode ?? 'redirect', 'lead_capture', false ) . '>Capture the lead on this site</option>';
			echo '</select> ';
			echo '<label><input type="checkbox" name="requires_appointment" value="1"' . checked( $editing->requires_appointment ?? 1, 1, false ) . '> Requires picking an appointment time</label>';
			echo '<p class="description">"Redirect" sends clicks straight to the Destination URL below (today\'s behavior). "Capture the lead on this site" instead shows an on-site form and records a Lead here for you to work &mdash; use this for projects that don\'t have (or don\'t want to rely on) a partner-hosted booking flow. The appointment checkbox only matters in capture mode: leave it unchecked for projects that just want contact info, no scheduled appointment. The quote form\'s intro copy/image is configured once per project under Settings, not per partner, since the customer never sees which partner ends up handling their request.</p>';
			echo '</td></tr>';

			echo '<tr><th>Destination URL</th><td><input type="url" name="destination_url" class="regular-text" value="' . esc_attr( $editing->destination_url ?? '' ) . '" placeholder="https://... (your real referral tracking link with this partner)"> <p class="description">Only used in "Redirect to partner site" mode. Leave blank until the partnership/affiliate application is approved &mdash; codes for this partner will redirect visitors to the homepage in the meantime, but clicks still get logged.</p></td></tr>';

			echo '<tr><th>Service area</th><td><input type="text" name="service_area_description" class="regular-text" value="' . esc_attr( $editing->service_area_description ?? '' ) . '" placeholder="e.g. Tampa Bay area, FL, or Nationwide"> <p class="description">Free-text, for your own reference.</p></td></tr>';

			echo '<tr><th>State / city / zip</th><td>';
			echo '<input type="text" name="state" style="width:100px;text-transform:uppercase;" placeholder="FL or FL,TX,GA" value="' . esc_attr( $editing->state ?? '' ) . '"> ';
			echo '<input type="text" name="city" class="regular-text" style="width:200px;" placeholder="City" value="' . esc_attr( $editing->city ?? '' ) . '"> ';
			echo '<input type="text" name="zip" style="width:100px;" placeholder="Zip" value="' . esc_attr( $editing->zip ?? '' ) . '"> ';
			echo '<p class="description">Structured, so partners can be filtered by state below, and so the affiliate dashboard can show a "Serves: ..." line on this partner\'s link cards &mdash; separate from the free-text service area above. One or more comma-separated 2-letter codes.</p>';
			echo '</td></tr>';

			echo '<tr><th>Open to self-signup</th><td>';
			echo '<label><input type="checkbox" name="open_to_self_signup" value="1"' . checked( $editing->open_to_self_signup ?? 1, 1, false ) . '> New affiliates automatically get a working link to this partner the moment they join, no admin step</label>';
			echo '<p class="description">Uncheck to keep this partner admin-matched only (useful for an exclusive or capacity-limited partner) &mdash; affiliates can still request a link later from their own dashboard if you turn this back on.</p>';
			echo '</td></tr>';

			echo '<tr><th>Dashboard blurb</th><td>';
			echo '<input type="text" name="blurb" class="large-text" maxlength="300" value="' . esc_attr( $editing->blurb ?? '' ) . '" placeholder="One short sentence describing this partner">';
			echo '<p class="description">Shown to affiliates as a tap-to-reveal popover next to this partner\'s name on their dashboard link card. Leave blank to hide the icon entirely.</p>';
			echo '</td></tr>';

			echo '<tr><th>Spotlight page URL</th><td>';
			echo '<input type="url" name="spotlight_url" class="regular-text" value="' . esc_attr( $editing->spotlight_url ?? '' ) . '" placeholder="https://...">';
			echo '<p class="description">If this site has its own content page about this partner, link it here &mdash; affiliates get a "See full spotlight" link on their dashboard card. Per-site, since each site\'s own content differs; leave blank if there isn\'t one yet.</p>';
			echo '</td></tr>';

			echo '<tr><th>Capabilities</th><td>';
			$selected_tags = $editing->capability_tags ?? '' ? array_map( 'trim', explode( ',', $editing->capability_tags ) ) : array();
			echo '<fieldset>';
			foreach ( GAS_DB::capability_tags() as $slug => $tag ) {
				echo '<label style="display:block;margin-bottom:.3em;"><input type="checkbox" name="capability_tags[]" value="' . esc_attr( $slug ) . '"' . checked( in_array( $slug, $selected_tags, true ), true, false ) . '> ' . esc_html( $tag['label'] ) . '</label>';
			}
			echo '</fieldset>';
			echo '<p class="description">Shown as small icons on the affiliate dashboard; tapping/clicking one reveals its label. "Appointment required" and coverage/location aren\'t here &mdash; those already come from the Fulfillment and State fields above.</p>';
			echo '</td></tr>';

			echo '<tr><th>Source</th><td>' . ( $editing->source_url ? '<a href="' . esc_url( $editing->source_url ) . '" target="_blank" rel="noopener">' . esc_html( $editing->source_url ) . '</a>' : '<em>added manually</em>' ) . '</td></tr>';

			echo '<tr><th>Outreach status</th><td><select name="outreach_status">';
			foreach ( array( 'new' => 'New (found, not yet contacted)', 'contacted' => 'Contacted', 'approved' => 'Approved / active', 'declined' => 'Declined' ) as $val => $label ) {
				echo '<option value="' . esc_attr( $val ) . '"' . selected( $editing->outreach_status ?? 'approved', $val, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></td></tr>';

			echo '<tr><th>Partner login</th><td><input type="email" name="email" class="regular-text" value="' . esc_attr( $editing->email ?? '' ) . '" placeholder="partner@example.com">';
			if ( $editing->user_id ?? 0 ) {
				echo ' <span style="color:#1a7a3c;">Has portal access</span>';
			} elseif ( $editing->email ?? '' ) {
				echo ' <span style="color:#b32d2e;">Save to send them portal access</span>';
			}
			echo '<p class="description">Give this partner their own login to <a href="' . esc_url( GAS_Partner_Portal::page_url() ) . '">the Partner Portal</a>, where they can see and update the status of their own leads instead of you having to chase them. Saving with an email here sends them a "set your password" email the first time; leave blank if they don\'t need portal access.</p></td></tr>';

			echo '<tr><th>Notes</th><td><textarea name="notes" class="large-text" rows="3">' . esc_textarea( $editing->notes ?? '' ) . '</textarea></td></tr>';

			echo '</tbody></table>';
			submit_button( 'Update Partner' );
			echo '</form>';
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=gas-partners' ) ) . '">&larr; Back to partner list</a></p>';
		} else {
			echo '<h2>Add a partner</h2>';
			echo '<p class="description">Add the company/installer/builder you\'re partnering with. Set its payout terms and destination URL by editing it afterward, once the partnership is confirmed.</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'gas_add_partner' );
			echo '<input type="hidden" name="action" value="gas_add_partner">';
			echo '<p><input type="text" name="name" class="regular-text" placeholder="Partner name" required> ';
			submit_button( 'Add Partner', 'secondary', 'submit', false );
			echo '</p></form>';

			echo '<h2>Existing partners</h2>';
			if ( ! $partners ) {
				echo '<p>No partners yet. Add one above to get started.</p>';
			} else {
				$states = array_unique( array_filter( wp_list_pluck( $partners, 'state' ) ) );
				sort( $states );
				$state_filter = isset( $_GET['state_filter'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_GET['state_filter'] ) ) ) : '';

				if ( $states ) {
					echo '<form method="get" style="margin-bottom:1em;">';
					echo '<input type="hidden" name="page" value="gas-partners">';
					echo '<label>Filter by state: <select name="state_filter" onchange="this.form.submit()">';
					echo '<option value="">All states</option>';
					foreach ( $states as $s ) {
						echo '<option value="' . esc_attr( $s ) . '"' . selected( $state_filter, $s, false ) . '>' . esc_html( $s ) . '</option>';
					}
					echo '</select></label>';
					echo '</form>';
				}

				$visible_partners = $state_filter
					? array_values( array_filter( $partners, function( $p ) use ( $state_filter ) { return $p->state === $state_filter; } ) )
					: $partners;

				echo '<table class="widefat striped"><thead><tr><th>Name</th><th>State</th><th>Self-signup</th><th>Outreach</th><th>Payout structure</th><th>Buyer cash back</th><th>Fulfillment</th><th>Destination URL</th><th>Portal</th><th>Actions</th></tr></thead><tbody>';
				if ( ! $visible_partners ) {
					echo '<tr><td colspan="10">No partners match that filter.</td></tr>';
				}
				foreach ( $visible_partners as $p ) {
					if ( 'flat' === $p->payout_type ) {
						$structure = $p->payout_amount ? '$' . number_format( (float) $p->payout_amount, 2 ) . ' flat' : '<em>not set</em>';
					} else {
						$structure = $p->payout_percent ? esc_html( $p->payout_percent ) . '%' : '<em>not set</em>';
						$inst = $p->installments_json ? json_decode( $p->installments_json, true ) : array();
						if ( $inst ) {
							$parts = array();
							foreach ( $inst as $i ) {
								$parts[] = esc_html( $i['label'] ) . ' (' . round( $i['fraction'] * 100, 2 ) . '%)';
							}
							$structure .= ' &mdash; ' . implode( ', ', $parts );
						}
					}
					echo '<tr>';
					$cashback = $p->cashback_type ? ( 'percent' === $p->cashback_type ? esc_html( $p->cashback_value ) . '%' : '$' . esc_html( number_format( (float) $p->cashback_value, 2 ) ) . ' flat' ) : '<em>none</em>';
					$outreach_colors = array( 'new' => '#b32d2e', 'contacted' => '#8a6d00', 'approved' => '#1a7a3c', 'declined' => '#666' );
					$outreach_color  = $outreach_colors[ $p->outreach_status ] ?? '#666';
					echo '<td>' . esc_html( $p->name ) . '</td>';
					echo '<td>' . ( $p->state ? esc_html( $p->state ) : '&mdash;' ) . '</td>';
					echo '<td>' . ( ( $p->open_to_self_signup ?? 1 ) ? '<span style="color:#1a7a3c;">Yes</span>' : '<span style="color:#666;">No</span>' ) . '</td>';
					echo '<td style="color:' . esc_attr( $outreach_color ) . ';">' . esc_html( ucfirst( $p->outreach_status ) ) . '</td>';
					echo '<td>' . $structure . '</td>';
					echo '<td>' . $cashback . '</td>';
					echo '<td>' . esc_html( self::fulfillment_mode_label( $p->fulfillment_mode ) ) . '</td>';
					echo '<td>' . ( $p->destination_url ? '<code>' . esc_html( $p->destination_url ) . '</code>' : '<em>not set yet</em>' ) . '</td>';
					if ( $p->user_id ) {
						echo '<td style="color:#1a7a3c;">Has access</td>';
					} elseif ( $p->email ) {
						echo '<td style="color:#b32d2e;">Pending</td>';
					} else {
						echo '<td><em>none</em></td>';
					}
					echo '<td>';
					echo '<a href="' . esc_url( admin_url( 'admin.php?page=gas-partners&edit=' . $p->id ) ) . '">Edit</a>';
					if ( $p->user_id ) {
						echo ' | <form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
						wp_nonce_field( 'gas_start_admin_preview' );
						echo '<input type="hidden" name="action" value="gas_start_admin_preview">';
						echo '<input type="hidden" name="preview_type" value="partner">';
						echo '<input type="hidden" name="record_id" value="' . esc_attr( $p->id ) . '">';
						echo '<button type="submit" class="button-link">View Dashboard</button>';
						echo '</form>';
					}
					echo '</td>';
					echo '</tr>';
				}
				echo '</tbody></table>';
			}
		}

		self::wrap_end();
	}

	/**
	 * Campaigns screen — ported from GRC's admin/views/campaigns.php,
	 * adapted to GAS's plain-PHP (no separate view files) convention.
	 * Same shape: an add/edit form, a "Landing Page Variants" section
	 * that only appears while editing an existing campaign, and the
	 * full campaign list below.
	 */
	public static function render_campaigns_page() {
		if ( ! current_user_can( self::CAP_CAMPAIGNS ) ) {
			return;
		}
		global $wpdb;
		$edit_id  = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$editing  = $edit_id ? GAS_Campaigns::get( $edit_id ) : null;
		$partners = self::get_partners();
		$pages    = get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );

		self::wrap_start( 'Campaigns' );

		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success"><p>Saved.</p></div>';
		}
		if ( isset( $_GET['variant_saved'] ) ) {
			echo '<div class="notice notice-success"><p>Variant saved.</p></div>';
		}

		echo '<h2>' . ( $editing ? 'Edit Campaign' : 'Add a Campaign' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'gas_save_campaign' );
		echo '<input type="hidden" name="action" value="gas_save_campaign">';
		if ( $editing ) {
			echo '<input type="hidden" name="campaign_id" value="' . esc_attr( $editing->id ) . '">';
		}
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Name</th><td><input type="text" name="name" class="regular-text" required value="' . esc_attr( $editing->name ?? '' ) . '"></td></tr>';

		echo '<tr><th>Partner</th><td><select name="partner_id" required>';
		echo '<option value="">-- choose a partner --</option>';
		foreach ( $partners as $p ) {
			echo '<option value="' . esc_attr( $p->id ) . '"' . selected( $editing->partner_id ?? 0, $p->id, false ) . '>' . esc_html( $p->name ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th>Tracking slug</th><td>' . esc_html( home_url( '/go/' ) ) . '<input type="text" name="tracking_slug" style="width:220px;" required value="' . esc_attr( $editing->tracking_slug ?? '' ) . '"> <p class="description">URL-safe, must be unique across every campaign.</p></td></tr>';

		echo '<tr><th>Landing page</th><td><select name="landing_page_id"><option value="">-- default (use the partner\'s own fulfillment setup) --</option>';
		foreach ( $pages as $pg ) {
			echo '<option value="' . esc_attr( $pg->ID ) . '"' . selected( $editing->landing_page_id ?? 0, $pg->ID, false ) . '>' . esc_html( $pg->post_title ) . '</option>';
		}
		echo '</select> <p class="description">Optional. Leave as default to send clicks through this partner\'s normal fulfillment (on-site lead form or external redirect, per the Partners screen) — only set this if this specific campaign should land somewhere else instead.</p></td></tr>';

		echo '<tr><th>Status</th><td><select name="status">';
		echo '<option value="active"' . selected( $editing->status ?? 'active', 'active', false ) . '>Active</option>';
		echo '<option value="paused"' . selected( $editing->status ?? 'active', 'paused', false ) . '>Paused</option>';
		echo '</select></td></tr>';

		if ( $editing && $editing->is_default ) {
			echo '<tr><th></th><td><p class="description">This is this partner\'s auto-created default campaign (from "Open to self-signup" on the Partners screen) — safe to rename or repoint, but if you delete/pause it, that partner loses its automatic self-signup link until a new default is created.</p></td></tr>';
		}

		echo '</tbody></table>';
		submit_button( $editing ? 'Update Campaign' : 'Add Campaign' );
		echo '</form>';

		if ( $editing ) {
			echo '<h2>Landing Page Variants</h2>';
			echo '<p class="description">Additional landing pages an affiliate can choose to promote for this campaign, alongside its default above.</p>';
			$variants = GAS_Campaigns::get_variants_for( $editing->id );
			if ( $variants ) {
				echo '<table class="widefat striped"><thead><tr><th>Variant Name</th><th>Landing Page</th><th>Link</th><th>Actions</th></tr></thead><tbody>';
				foreach ( $variants as $v ) {
					$page_title = get_the_title( $v->landing_page_id ) ?: '(page deleted)';
					echo '<tr>';
					echo '<td>' . esc_html( $v->variant_name ) . '</td>';
					echo '<td>' . esc_html( $page_title ) . '</td>';
					echo '<td><input type="text" readonly style="width:100%;" value="' . esc_attr( GAS_Campaigns::build_link( $editing, 'YOUR-CODE', $v->id ) ) . '" onclick="this.select();"></td>';
					echo '<td><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Delete this variant?\');">';
					wp_nonce_field( 'gas_delete_campaign_variant' );
					echo '<input type="hidden" name="action" value="gas_delete_campaign_variant">';
					echo '<input type="hidden" name="variant_id" value="' . esc_attr( $v->id ) . '">';
					echo '<input type="hidden" name="campaign_id" value="' . esc_attr( $editing->id ) . '">';
					echo '<button type="submit" class="button-link">Delete</button>';
					echo '</form></td>';
					echo '</tr>';
				}
				echo '</tbody></table>';
			}

			echo '<h3>Add a Variant</h3>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'gas_save_campaign_variant' );
			echo '<input type="hidden" name="action" value="gas_save_campaign_variant">';
			echo '<input type="hidden" name="campaign_id" value="' . esc_attr( $editing->id ) . '">';
			echo '<p><input type="text" name="variant_name" placeholder="Variant name" required> ';
			echo '<select name="landing_page_id" required><option value="">-- landing page --</option>';
			foreach ( $pages as $pg ) {
				echo '<option value="' . esc_attr( $pg->ID ) . '">' . esc_html( $pg->post_title ) . '</option>';
			}
			echo '</select> ';
			submit_button( 'Add Variant', 'secondary', 'submit', false );
			echo '</p></form>';

			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=gas-campaigns' ) ) . '">&larr; Back to campaign list</a></p>';
		}

		echo '<h2>All Campaigns</h2>';
		$campaigns_table = GAS_DB::table( 'campaigns' );
		$partners_table  = GAS_DB::table( 'partners' );
		$campaigns = $wpdb->get_results(
			"SELECT c.*, p.name AS partner_name FROM {$campaigns_table} c
			 LEFT JOIN {$partners_table} p ON p.id = c.partner_id
			 ORDER BY c.created_at DESC"
		);
		if ( ! $campaigns ) {
			echo '<p>No campaigns yet. Add one above, or mark a partner "Open to self-signup" on the Partners screen to get one created automatically.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Partner</th><th>Link</th><th>Default?</th><th>Status</th><th>Actions</th></tr></thead><tbody>';
			foreach ( $campaigns as $c ) {
				echo '<tr>';
				echo '<td>' . esc_html( $c->name ) . '</td>';
				echo '<td>' . esc_html( $c->partner_name ?: '&mdash;' ) . '</td>';
				echo '<td><input type="text" readonly style="width:100%;" value="' . esc_attr( GAS_Campaigns::build_link( $c, 'YOUR-CODE' ) ) . '" onclick="this.select();"></td>';
				echo '<td>' . ( $c->is_default ? 'Yes' : '&mdash;' ) . '</td>';
				echo '<td>' . esc_html( ucfirst( $c->status ) ) . '</td>';
				echo '<td><a href="' . esc_url( admin_url( 'admin.php?page=gas-campaigns&edit=' . $c->id ) ) . '">Edit</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
			echo '<p class="description">Swap <code>YOUR-CODE</code> in a link for a specific affiliate\'s real referral code (see the Affiliates screen) before sending it out — any active affiliate\'s code works with any active campaign\'s link.</p>';
		}

		self::wrap_end();
	}

	/**
	 * Loads WordPress's own media library JS on the Marketing Assets
	 * screen only — reused rather than building a custom uploader, per
	 * spec. `wp.media()` opens the same modal used for featured images
	 * etc.; picking a file there populates the hidden `attachment_id`
	 * field this screen's form posts.
	 */
	public static function enqueue_media_library( $hook ) {
		if ( 'gas-affiliates_page_gas-marketing-assets' !== $hook ) {
			return;
		}
		wp_enqueue_media();
	}

	public static function render_marketing_assets_page() {
		if ( ! current_user_can( self::CAP_CAMPAIGNS ) ) {
			return;
		}
		self::wrap_start( 'Marketing Assets' );

		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success"><p>Uploaded.</p></div>';
		}
		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success"><p>Deleted.</p></div>';
		}

		echo '<h2>Add an asset</h2>';
		echo '<p class="description">Banners, images, or other downloadable creative for affiliates to use when promoting a link. Scope it to one partner or campaign, or leave both blank to show it to every affiliate regardless of what they\'re promoting.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'gas_save_marketing_asset' );
		echo '<input type="hidden" name="action" value="gas_save_marketing_asset">';
		echo '<input type="hidden" name="attachment_id" id="gas_asset_attachment_id" value="">';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Image</th><td>';
		echo '<div id="gas_asset_preview" style="margin-bottom:0.5em;"></div>';
		echo '<button type="button" class="button" id="gas_asset_pick_button">Choose from Media Library</button>';
		echo '</td></tr>';
		echo '<tr><th><label for="gas_asset_title">Title</label></th><td><input type="text" id="gas_asset_title" name="title" class="regular-text" required></td></tr>';
		echo '<tr><th>Scope (optional)</th><td>';
		echo '<select name="partner_id"><option value="">-- any partner --</option>';
		foreach ( self::get_partners() as $p ) {
			echo '<option value="' . esc_attr( $p->id ) . '">' . esc_html( $p->name ) . '</option>';
		}
		echo '</select> ';
		global $wpdb;
		$campaigns = $wpdb->get_results( 'SELECT * FROM ' . GAS_DB::table( 'campaigns' ) . ' ORDER BY name ASC' );
		echo '<select name="campaign_id"><option value="">-- any campaign --</option>';
		foreach ( $campaigns as $c ) {
			echo '<option value="' . esc_attr( $c->id ) . '">' . esc_html( $c->name ) . '</option>';
		}
		echo '</select>';
		echo ' <p class="description">Leave both as "any" for a global asset shown to every affiliate.</p>';
		echo '</td></tr>';
		echo '</tbody></table>';
		submit_button( 'Add Asset' );
		echo '</form>';

		echo '<script>
			(function() {
				var pickBtn = document.getElementById("gas_asset_pick_button");
				var input   = document.getElementById("gas_asset_attachment_id");
				var preview = document.getElementById("gas_asset_preview");
				var frame;
				pickBtn.addEventListener("click", function(e) {
					e.preventDefault();
					if (frame) { frame.open(); return; }
					frame = wp.media({ title: "Choose an image", multiple: false, library: { type: "image" } });
					frame.on("select", function() {
						var attachment = frame.state().get("selection").first().toJSON();
						input.value = attachment.id;
						preview.innerHTML = "<img src=\"" + (attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url) + "\" style=\"max-width:150px;height:auto;\">";
					});
					frame.open();
				});
			})();
		</script>';

		echo '<h2 style="margin-top:2em;">Existing assets</h2>';
		$assets = GAS_Marketing_Assets::get_all();
		if ( ! $assets ) {
			echo '<p>No marketing assets yet.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Preview</th><th>Title</th><th>Scope</th><th>Actions</th></tr></thead><tbody>';
			foreach ( $assets as $a ) {
				$thumb = wp_get_attachment_image( $a->attachment_id, array( 80, 80 ) );
				$scope = $a->partner_name ? 'Partner: ' . $a->partner_name : ( $a->campaign_name ? 'Campaign: ' . $a->campaign_name : 'Global (all affiliates)' );
				echo '<tr>';
				echo '<td>' . ( $thumb ?: '<em>(missing)</em>' ) . '</td>';
				echo '<td>' . esc_html( $a->title ) . '</td>';
				echo '<td>' . esc_html( $scope ) . '</td>';
				$del_url = wp_nonce_url( admin_url( 'admin-post.php?action=gas_delete_marketing_asset&id=' . $a->id ), 'gas_delete_marketing_asset_' . $a->id );
				echo '<td><a href="' . esc_url( $del_url ) . '" onclick="return confirm(\'Delete this asset?\');">Delete</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		self::wrap_end();
	}

	public static function handle_add_partner() {
		if ( ! current_user_can( self::CAP_PARTNERS ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'gas_add_partner' );

		$name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		if ( '' === $name ) {
			wp_die( 'Partner name is required.' );
		}

		global $wpdb;
		$table = GAS_DB::table( 'partners' );
		$slug  = sanitize_title( $name );
		$base  = $slug;
		$i     = 0;
		while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s", $slug ) ) ) {
			$i++;
			$slug = $base . '-' . $i;
		}

		$wpdb->insert(
			$table,
			array(
				'slug'              => $slug,
				'name'              => $name,
				'payout_type'       => 'flat',
				'payout_amount'     => null,
				'payout_percent'    => null,
				'agent_pool_type'   => 'percent',
				'agent_pool_value'  => 100,
				'installments_json' => null,
				'cashback_type'     => null,
				'cashback_value'    => 0,
				'fulfillment_mode'  => 'lead_capture',
				'requires_appointment' => 0,
				'destination_url'   => '',
				'email'             => '',
				'open_to_self_signup' => 1,
				'blurb'             => '',
				'spotlight_url'     => '',
				'capability_tags'   => '',
				'notes'             => '',
				'created_at'        => current_time( 'mysql' ),
			)
		);

		self::audit_log( 'partner', $wpdb->insert_id, 'created', array( 'name' => $name ) );
		GAS_Campaigns::ensure_default_for_partner( $wpdb->insert_id );

		wp_safe_redirect( admin_url( 'admin.php?page=gas-partners&added=1' ) );
		exit;
	}

	public static function handle_save_partner() {
		if ( ! current_user_can( self::CAP_PARTNERS ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'gas_save_partner' );

		global $wpdb;
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( ! $id ) {
			wp_die( 'Missing partner id.' );
		}

		$installments = array();
		if ( ! empty( $_POST['inst1_label'] ) && '' !== $_POST['inst1_pct'] ) {
			$installments[] = array(
				'label'    => sanitize_text_field( wp_unslash( $_POST['inst1_label'] ) ),
				'fraction' => (float) $_POST['inst1_pct'] / 100,
			);
		}
		if ( ! empty( $_POST['inst2_label'] ) && '' !== $_POST['inst2_pct'] ) {
			$installments[] = array(
				'label'    => sanitize_text_field( wp_unslash( $_POST['inst2_label'] ) ),
				'fraction' => (float) $_POST['inst2_pct'] / 100,
			);
		}

		$data = array(
			'name'              => sanitize_text_field( wp_unslash( $_POST['name'] ) ),
			'payout_type'       => 'percent' === $_POST['payout_type'] ? 'percent' : 'flat',
			'payout_amount'     => '' !== $_POST['payout_amount'] ? (float) $_POST['payout_amount'] : null,
			'payout_percent'    => '' !== $_POST['payout_percent'] ? (float) $_POST['payout_percent'] : null,
			'agent_pool_type'   => isset( $_POST['agent_pool_type'] ) && 'flat' === $_POST['agent_pool_type'] ? 'flat' : 'percent',
			'agent_pool_value'  => isset( $_POST['agent_pool_value'] ) && '' !== $_POST['agent_pool_value'] ? (float) $_POST['agent_pool_value'] : 100,
			'typical_sale_amount' => isset( $_POST['typical_sale_amount'] ) && '' !== $_POST['typical_sale_amount'] ? (float) $_POST['typical_sale_amount'] : null,
			'installments_json' => $installments ? wp_json_encode( $installments ) : null,
			'cashback_type'     => isset( $_POST['cashback_type'] ) && in_array( $_POST['cashback_type'], array( 'flat', 'percent' ), true ) ? $_POST['cashback_type'] : null,
			'cashback_value'    => isset( $_POST['cashback_value'] ) ? (float) $_POST['cashback_value'] : 0,
			'fulfillment_mode'  => isset( $_POST['fulfillment_mode'] ) && 'lead_capture' === $_POST['fulfillment_mode'] ? 'lead_capture' : 'redirect',
			'requires_appointment' => isset( $_POST['requires_appointment'] ) ? 1 : 0,
			'destination_url'   => isset( $_POST['destination_url'] ) ? esc_url_raw( wp_unslash( $_POST['destination_url'] ) ) : '',
			'service_area_description' => isset( $_POST['service_area_description'] ) ? sanitize_text_field( wp_unslash( $_POST['service_area_description'] ) ) : '',
			'state'             => isset( $_POST['state'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['state'] ) ) ) : '',
			'city'              => isset( $_POST['city'] ) ? sanitize_text_field( wp_unslash( $_POST['city'] ) ) : '',
			'zip'               => isset( $_POST['zip'] ) ? sanitize_text_field( wp_unslash( $_POST['zip'] ) ) : '',
			'outreach_status'   => isset( $_POST['outreach_status'] ) && in_array( $_POST['outreach_status'], array( 'new', 'contacted', 'approved', 'declined' ), true ) ? $_POST['outreach_status'] : 'approved',
			'email'             => isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '',
			'open_to_self_signup' => isset( $_POST['open_to_self_signup'] ) ? 1 : 0,
			'blurb'             => isset( $_POST['blurb'] ) ? sanitize_text_field( wp_unslash( $_POST['blurb'] ) ) : '',
			'spotlight_url'     => isset( $_POST['spotlight_url'] ) ? esc_url_raw( wp_unslash( $_POST['spotlight_url'] ) ) : '',
			'capability_tags'   => isset( $_POST['capability_tags'] ) && is_array( $_POST['capability_tags'] )
				? implode( ',', array_intersect( array_map( 'sanitize_key', wp_unslash( $_POST['capability_tags'] ) ), array_keys( GAS_DB::capability_tags() ) ) )
				: '',
			'notes'             => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '',
		);

		$wpdb->update( GAS_DB::table( 'partners' ), $data, array( 'id' => $id ) );
		GAS_Roles::provision_partner_account( $id );
		GAS_Campaigns::ensure_default_for_partner( $id );
		if ( ! empty( $data['email'] ) ) {
			GAS_Contacts::upsert( $data['email'], 'partner', array( 'name' => $data['name'], 'source' => 'partner_save', 'related_table' => 'partners', 'related_id' => $id ) );
		}
		self::audit_log( 'partner', $id, 'updated', $data );

		wp_safe_redirect( admin_url( 'admin.php?page=gas-partners&saved=1' ) );
		exit;
	}

	/* ---------------------------------------------------------------- *
	 * LEADS (on-site capture, for lead_capture partners)
	 * ---------------------------------------------------------------- */

	public static function render_leads_page() {
		if ( ! current_user_can( self::CAP_LEADS ) ) {
			return;
		}
		global $wpdb;
		self::wrap_start( 'Leads' );

		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success"><p>Status updated.</p></div>';
		}

		$leads_table    = GAS_DB::table( 'leads' );
		$partners_table = GAS_DB::table( 'partners' );

		$rows = $wpdb->get_results(
			"SELECT l.*, p.name AS partner_name FROM {$leads_table} l
			 LEFT JOIN {$partners_table} p ON p.id = l.partner_id
			 ORDER BY l.created_at DESC"
		);

		if ( ! $rows ) {
			echo '<p>No leads yet. Leads show up here when a visitor submits the on-site form for a partner set to "Capture the lead on this site" &mdash; see the Partners screen.</p>';
			self::wrap_end();
			return;
		}

		$partners = $wpdb->get_results( "SELECT id, name, requires_appointment FROM {$partners_table} WHERE outreach_status = 'approved' ORDER BY name ASC" );

		echo '<table class="widefat striped"><thead><tr><th>Received</th><th>Customer</th><th>Contact</th><th>Address</th><th>State</th><th>Partner</th><th>Appointment</th><th>Status</th></tr></thead><tbody>';
		foreach ( $rows as $l ) {
			echo '<tr>';
			echo '<td>' . esc_html( $l->created_at ) . '</td>';
			echo '<td>' . esc_html( $l->customer_name ) . '</td>';
			echo '<td>' . esc_html( $l->customer_email ) . ( $l->customer_email && $l->customer_phone ? '<br>' : '' ) . esc_html( $l->customer_phone ) . '</td>';
			echo '<td>' . ( $l->customer_address ? esc_html( $l->customer_address ) : '&mdash;' ) . '</td>';
			echo '<td>' . ( $l->customer_state ? esc_html( $l->customer_state ) : '&mdash;' ) . '</td>';
			if ( $l->partner_id ) {
				echo '<td>' . esc_html( $l->partner_name ?: '&mdash;' ) . '</td>';
			} else {
				// Unassigned — came from the merged signup/refer page's
				// referral path (GAS_Frontend::create_referral_lead()),
				// which never picks a partner itself. This is the one
				// place that gets matched, same "admin decides" rule as
				// a fresh affiliate signup's code.
				echo '<td>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="gas-assign-partner-form">';
				wp_nonce_field( 'gas_assign_lead_partner_' . $l->id );
				echo '<input type="hidden" name="action" value="gas_assign_lead_partner">';
				echo '<input type="hidden" name="lead_id" value="' . esc_attr( $l->id ) . '">';
				echo '<select name="partner_id" required style="max-width:140px;"><option value="">-- match a partner --</option>';
				foreach ( $partners as $p ) {
					echo '<option value="' . esc_attr( $p->id ) . '">' . esc_html( $p->name ) . ( $p->requires_appointment ? ' (needs appt.)' : '' ) . '</option>';
				}
				echo '</select><br>';
				echo '<input type="datetime-local" name="proposed_at" style="max-width:160px;margin-top:4px;" title="Proposed appointment time, only used if the partner requires one">';
				echo '<br><input type="datetime-local" name="backup_at" style="max-width:160px;margin-top:4px;" title="Backup appointment time">';
				echo '<br><button type="submit" class="button button-small" style="margin-top:4px;">Match &amp; notify</button>';
				echo '</form>';
				echo '</td>';
			}
			echo '<td>' . ( $l->appointment_at ? esc_html( $l->appointment_at ) : '&mdash;' ) . '</td>';
			echo '<td>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'gas_update_lead_status_' . $l->id );
			echo '<input type="hidden" name="action" value="gas_update_lead_status">';
			echo '<input type="hidden" name="lead_id" value="' . esc_attr( $l->id ) . '">';
			echo '<select name="status" onchange="this.form.submit()">';
			if ( ! in_array( $l->status, GAS_Leads::SETTABLE_STATUSES, true ) ) {
				echo '<option value="" disabled selected>' . esc_html( ucwords( str_replace( '_', ' ', $l->status ) ) ) . '</option>';
			}
			foreach ( GAS_Leads::SETTABLE_STATUSES as $s ) {
				echo '<option value="' . esc_attr( $s ) . '"' . selected( $l->status, $s, false ) . '>' . esc_html( ucwords( str_replace( '_', ' ', $s ) ) ) . '</option>';
			}
			echo '</select>';
			echo '<noscript><button type="submit" class="button">Update</button></noscript>';
			echo '</form>';
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';

		self::wrap_end();
	}

	/* ---------------------------------------------------------------- *
	 * CLICK LOG
	 * ---------------------------------------------------------------- */

	public static function render_clicks_page() {
		if ( ! current_user_can( self::CAP_REPORTS ) ) {
			return;
		}
		global $wpdb;
		self::wrap_start( 'Click Log' );

		$clicks_table   = GAS_DB::table( 'clicks' );
		$partners_table = GAS_DB::table( 'partners' );

		$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$clicks_table}" );
		$per_page = 50;
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$offset   = ( $paged - 1 ) * $per_page;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT cl.*, p.name AS partner_name FROM {$clicks_table} cl
				 LEFT JOIN {$partners_table} p ON p.id = cl.partner_id
				 ORDER BY cl.clicked_at DESC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			)
		);

		echo '<p>' . esc_html( $total ) . ' total clicks logged.</p>';

		if ( ! $rows ) {
			echo '<p>No clicks yet.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Date/Time</th><th>Code</th><th>Partner</th><th>IP</th><th>User Agent</th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				echo '<tr>';
				echo '<td>' . esc_html( $r->clicked_at ) . '</td>';
				echo '<td><code>' . esc_html( $r->code ) . '</code></td>';
				echo '<td>' . esc_html( $r->partner_name ?: '&mdash;' ) . '</td>';
				echo '<td>' . esc_html( $r->ip_address ) . '</td>';
				echo '<td style="max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' . esc_html( $r->user_agent ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';

			$total_pages = (int) ceil( $total / $per_page );
			if ( $total_pages > 1 ) {
				echo '<p>';
				for ( $i = 1; $i <= $total_pages; $i++ ) {
					if ( $i === $paged ) {
						echo '<strong>' . $i . '</strong> ';
					} else {
						echo '<a href="' . esc_url( admin_url( 'admin.php?page=gas-clicks&paged=' . $i ) ) . '">' . $i . '</a> ';
					}
				}
				echo '</p>';
			}
		}

		self::wrap_end();
	}

	/* ---------------------------------------------------------------- *
	 * PAYOUT CALCULATOR
	 * ---------------------------------------------------------------- */

	public static function render_calculator_page() {
		if ( ! current_user_can( self::CAP_COMMISSIONS ) ) {
			return;
		}
		self::wrap_start( 'Payout Calculator' );

		$codes = self::get_codes();

		if ( isset( $_GET['result'] ) ) {
			$result = get_transient( 'gas_calc_result_' . get_current_user_id() );
			if ( $result ) {
				echo '<div class="notice notice-success"><h2 style="margin-top:0">Result</h2>';
				echo '<p><strong>Gross commission (yours from the partner):</strong> $' . esc_html( number_format( $result['gross'], 2 ) ) . '</p>';
				if ( isset( $result['agent_pool'] ) && abs( $result['agent_pool'] - $result['gross'] ) > 0.001 ) {
					echo '<p><strong>Agent commission pool (this sale):</strong> $' . esc_html( number_format( $result['agent_pool'], 2 ) ) . ' <span class="description">&mdash; only this portion is split across tiers below; the rest of gross stays with you.</span></p>';
				}
				echo '<p><strong>Tier 1 share (' . esc_html( $result['tier1_pct'] ) . '% &mdash; the affiliate):</strong> $' . esc_html( number_format( $result['cut'], 2 ) ) . '</p>';
				if ( $result['cashback'] > 0 ) {
					echo '<p><strong>Buyer cash back:</strong> $' . esc_html( number_format( $result['cashback'], 2 ) ) . '</p>';
				}
				if ( $result['tier2_amount'] > 0 ) {
					echo '<p><strong>Tier 2 share (' . esc_html( $result['tier2_pct'] ) . '% &mdash; ' . esc_html( $result['tier2_name'] ) . '):</strong> $' . esc_html( number_format( $result['tier2_amount'], 2 ) ) . '</p>';
				}
				if ( $result['tier3_amount'] > 0 ) {
					echo '<p><strong>Tier 3 share (' . esc_html( $result['tier3_pct'] ) . '% &mdash; ' . esc_html( $result['tier3_name'] ) . '):</strong> $' . esc_html( number_format( $result['tier3_amount'], 2 ) ) . '</p>';
				}
				echo '<p><strong>Net to you:</strong> $' . esc_html( number_format( $result['net'], 2 ) ) . '</p>';
				if ( ! empty( $result['saved'] ) ) {
					echo '<p>Saved to the <a href="' . esc_url( admin_url( 'admin.php?page=gas-ledger' ) ) . '">Payout Ledger</a>.</p>';
				}
				echo '</div>';
			}
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'gas_calculate_payout' );
		echo '<input type="hidden" name="action" value="gas_calculate_payout">';
		echo '<table class="form-table"><tbody>';

		echo '<tr><th>Code</th><td><select name="code_id" id="code_id" required onchange="gasUpdateInstallments()">';
		echo '<option value="">-- select a code --</option>';
		foreach ( $codes as $c ) {
			echo '<option value="' . esc_attr( $c->id ) . '" data-partner="' . esc_attr( $c->partner_id ) . '">' . esc_html( $c->code . ' — ' . $c->sub_affiliate_name . ' (' . $c->partner_name . ')' ) . '</option>';
		}
		echo '</select> <p class="description">Since campaigns (2026-09-08), a self-signup affiliate\'s code isn\'t tied to one partner &mdash; choose the partner this specific sale was actually with below. A manually-assigned code\'s own partner is preselected as a convenience, but always double-check it.</p></td></tr>';

		echo '<tr><th>Partner</th><td><select name="partner_id" id="partner_id" required onchange="gasUpdateInstallments()">';
		echo '<option value="">-- select the partner this sale was with --</option>';
		foreach ( self::get_partners() as $p ) {
			echo '<option value="' . esc_attr( $p->id ) . '">' . esc_html( $p->name ) . '</option>';
		}
		echo '</select></td></tr>';

		echo '<tr><th>Sale amount ($)</th><td><input type="number" step="0.01" min="0" name="sale_amount" required></td></tr>';

		echo '<tr><th>Customer email</th><td><input type="email" name="customer_email" class="regular-text"> <p class="description">Optional &mdash; only needed if this partner pays buyer cash back. Set this and we\'ll email the customer a link to claim it (tell us how to pay them). Also lets a self-referring affiliate\'s own cashback aggregate correctly against the $600/year tax threshold.</p></td></tr>';

		echo '<tr><th>Installment</th><td><select name="installment_index" id="installment_index"><option value="">Full amount / single payment</option></select> <p class="description">Only matters for partners paid in stages. Choose which payment this is.</p></td></tr>';

		echo '<tr><th>Notes</th><td><textarea name="notes" class="large-text" rows="2" placeholder="optional"></textarea></td></tr>';

		echo '</tbody></table>';
		echo '<p><button type="submit" name="save" value="0" class="button">Calculate only</button> ';
		echo '<button type="submit" name="save" value="1" class="button button-primary">Calculate &amp; save to ledger</button></p>';
		echo '</form>';

		// Inline data + tiny script to populate installment choices per selected code's partner.
		$partner_installments = array();
		foreach ( self::get_partners() as $p ) {
			$partner_installments[ $p->id ] = $p->installments_json ? json_decode( $p->installments_json, true ) : array();
		}
		echo '<script>
			var gasPartnerInstallments = ' . wp_json_encode( $partner_installments ) . ';
			var gasPartnerAutoSelected = false;
			function gasUpdateInstallments() {
				var codeSel = document.getElementById("code_id");
				var partnerSel = document.getElementById("partner_id");
				var codePartnerId = codeSel.options[codeSel.selectedIndex] ? codeSel.options[codeSel.selectedIndex].getAttribute("data-partner") : null;

				// Convenience only: pre-select the code\'s own partner when
				// choosing a manually-assigned code (partner_id != 0) and the
				// admin hasn\'t already picked a different partner themselves —
				// self-signup codes have no such partner (0), so nothing is
				// preselected for those, which is correct now that one code
				// can be used across many partners\' campaigns.
				if (codePartnerId && codePartnerId !== "0" && !gasPartnerAutoSelected) {
					partnerSel.value = codePartnerId;
				}

				var partnerId = partnerSel.value;
				var instSel = document.getElementById("installment_index");
				instSel.innerHTML = "<option value=\"\">Full amount / single payment</option>";
				if (partnerId && gasPartnerInstallments[partnerId] && gasPartnerInstallments[partnerId].length) {
					gasPartnerInstallments[partnerId].forEach(function(inst, i) {
						var opt = document.createElement("option");
						opt.value = i;
						opt.textContent = inst.label + " (" + (inst.fraction * 100) + "%)";
						instSel.appendChild(opt);
					});
				}
			}
			document.getElementById("partner_id").addEventListener("change", function() {
				gasPartnerAutoSelected = true;
				gasUpdateInstallments();
			});
		</script>';

		self::wrap_end();
	}

	public static function handle_calculate_payout() {
		if ( ! current_user_can( self::CAP_COMMISSIONS ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'gas_calculate_payout' );

		$code_id           = isset( $_POST['code_id'] ) ? absint( $_POST['code_id'] ) : 0;
		$partner_id        = isset( $_POST['partner_id'] ) ? absint( $_POST['partner_id'] ) : 0;
		$sale_amount       = isset( $_POST['sale_amount'] ) ? (float) $_POST['sale_amount'] : 0;
		$installment_index = isset( $_POST['installment_index'] ) && '' !== $_POST['installment_index'] ? absint( $_POST['installment_index'] ) : null;
		$notes             = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
		$customer_email    = isset( $_POST['customer_email'] ) ? sanitize_email( wp_unslash( $_POST['customer_email'] ) ) : '';
		$save              = ! empty( $_POST['save'] );

		$code = self::get_code( $code_id );
		if ( ! $code ) {
			wp_die( 'Code not found.' );
		}
		// Since campaigns (2026-09-08), a code no longer implies one fixed
		// partner (a self-signup affiliate's single code can be used across
		// any partner's campaign) — the partner this specific sale was with
		// is now always an explicit choice, not inferred from the code.
		if ( ! $partner_id ) {
			wp_die( 'Please choose which partner this sale was with.' );
		}
		$partner = self::get_partner( $partner_id );
		if ( ! $partner ) {
			wp_die( 'Partner not found.' );
		}

		$calc = GAS_Payouts::compute( $partner, $code, $sale_amount, $installment_index );

		$result = array(
			'gross'         => $calc['gross'],
			'agent_pool'    => $calc['agent_pool'],
			'cut'           => $calc['tier1_amount'],
			'tier1_pct'     => $calc['tier1_pct'],
			'cashback'      => $calc['cashback'],
			'tier2_amount'  => $calc['tier2_amount'],
			'tier2_pct'     => $calc['tier2_pct'],
			'tier2_name'    => $calc['tier2_code'] ? $calc['tier2_code']->sub_affiliate_name : '',
			'tier3_amount'  => $calc['tier3_amount'],
			'tier3_pct'     => $calc['tier3_pct'],
			'tier3_name'    => $calc['tier3_code'] ? $calc['tier3_code']->sub_affiliate_name : '',
			'net'           => $calc['net'],
			'saved'         => false,
		);

		global $wpdb;
		if ( $save ) {
			$wpdb->insert(
				GAS_DB::table( 'payouts' ),
				array(
					'code_id'           => $code->id,
					'code'              => $code->code,
					'partner_id'        => $partner->id,
					'sale_amount'       => $sale_amount,
					'installment_label' => $calc['installment_label'],
					'gross_commission'  => $calc['gross'],
					'agent_pool_amount' => $calc['agent_pool'],
					'subaffiliate_cut'  => $calc['tier1_amount'],
					'cashback_amount'   => $calc['cashback'],
					'customer_email'    => $customer_email,
					'tier2_code_id'     => $calc['tier2_code'] ? $calc['tier2_code']->id : null,
					'tier2_amount'      => $calc['tier2_amount'],
					'tier3_code_id'     => $calc['tier3_code'] ? $calc['tier3_code']->id : null,
					'tier3_amount'      => $calc['tier3_amount'],
					'net_to_cary'       => $calc['net'],
					'entered_at'        => current_time( 'mysql' ),
					'notes'             => $notes,
				)
			);
			$payout_id        = $wpdb->insert_id;
			$result['saved']  = true;
			self::audit_log( 'payout', $payout_id, 'entered', array( 'code' => $code->code, 'gross' => $calc['gross'], 'net' => $calc['net'] ) );
			self::maybe_notify_partner_renegotiation_milestone( $partner );

			// Non-blocking tier-stacking flag (2026-09-09) — see
			// GAS_Payouts::tier_stacking_signals() for what this is and,
			// more importantly, isn't (never blocks the payout itself).
			if ( ! empty( $calc['tier_stacking'] ) ) {
				self::audit_log( 'payout', $payout_id, 'possible_tier_stacking', $calc['tier_stacking'] );
			}

			if ( $calc['cashback'] > 0 && $customer_email ) {
				GAS_Cashback::send_claim_email( $payout_id );
			}
		}

		set_transient( 'gas_calc_result_' . get_current_user_id(), $result, 60 );

		wp_safe_redirect( admin_url( 'admin.php?page=gas-calculator&result=1' ) );
		exit;
	}

	/**
	 * Fires once, generically, the moment ANY partner's 3rd recorded
	 * payout lands — not a one-time Go Solar Power-specific check. Three
	 * completed deals is proven enough volume to justify asking that
	 * partner for better terms, specifically: part of deal four's payout
	 * upfront instead of only on completion, to keep the referral
	 * pipeline funded. Deliberately a plain email, not a recurring
	 * reminder — Cary either acts on it or doesn't, and it should never
	 * fire again for the same partner once it has.
	 */
	private static function maybe_notify_partner_renegotiation_milestone( $partner ) {
		global $wpdb;
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT COUNT(*) FROM ' . GAS_DB::table( 'payouts' ) . ' WHERE partner_id = %d',
			$partner->id
		) );

		if ( 3 !== $count ) {
			return;
		}

		wp_mail(
			get_option( 'admin_email' ),
			"Milestone: 3 completed deals with {$partner->name} — time to renegotiate?",
			"{$partner->name} has now sent 3 completed, paid-out-worthy deals through this site. That's proven volume worth leveraging.\n\n"
			. "Worth reaching out to ask for a portion of the 4th deal's payout upfront, rather than only on completion — the pitch: \"we've sent you 3 completed deals, there's real proven value here, so let's front-load part of deal four to keep the referral pipeline funded going forward.\"\n\n"
			. "This is a one-time notice for {$partner->name} specifically — it'll fire again independently for any other partner once they hit their own 3rd deal."
		);
		self::audit_log( 'partner', $partner->id, 'renegotiation_milestone_hit', array( 'deal_count' => $count ) );
	}

	/* ---------------------------------------------------------------- *
	 * PAYOUT LEDGER
	 * ---------------------------------------------------------------- */

	public static function render_ledger_page() {
		if ( ! current_user_can( self::CAP_COMMISSIONS ) ) {
			return;
		}
		global $wpdb;
		self::wrap_start( 'Payout Ledger' );

		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success"><p>Entry deleted.</p></div>';
		}
		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success"><p>Payout API settings saved.</p></div>';
		}
		if ( isset( $_GET['payout_result'] ) ) {
			self::render_payout_result_notice();
		}
		if ( isset( $_GET['token_regenerated'] ) ) {
			echo '<div class="notice notice-success"><p>Token regenerated &mdash; update Hostinger\'s Cron Job with the new URL below before the next scheduled run.</p></div>';
		}

		$payouts_table  = GAS_DB::table( 'payouts' );
		$partners_table = GAS_DB::table( 'partners' );

		$rows = $wpdb->get_results(
			"SELECT pay.*, p.name AS partner_name FROM {$payouts_table} pay
			 LEFT JOIN {$partners_table} p ON p.id = pay.partner_id
			 ORDER BY pay.entered_at DESC"
		);

		echo '<p><a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gas_export_ledger_csv' ), 'gas_export_ledger_csv' ) ) . '" class="button">Export CSV</a></p>';

		echo '<h2>Tax summary (for your accountant)</h2>';
		echo '<p class="description">Everything actually paid to each affiliate in one calendar year, plus their tax info on file &mdash; hand this to your accountant or a 1099 e-filing service (e.g. Track1099). This plugin doesn\'t file with the IRS itself. <strong>Contains full, unmasked SSNs/EINs</strong> &mdash; handle the downloaded file as sensitive.</p>';
		$current_year = (int) current_time( 'Y' );
		echo '<form method="get" style="display:inline;">';
		echo '<input type="hidden" name="page" value="gas-ledger">';
		echo '<select name="tax_year" onchange="document.getElementById(\'gas-tax-export-link\').href = document.getElementById(\'gas-tax-export-link\').href.replace(/year=\\d+/, \'year=\' + this.value);">';
		for ( $y = $current_year; $y >= $current_year - 4; $y-- ) {
			echo '<option value="' . esc_attr( $y ) . '">' . esc_html( $y ) . '</option>';
		}
		echo '</select> ';
		echo '<a id="gas-tax-export-link" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gas_export_tax_summary_csv&year=' . $current_year ), 'gas_export_tax_summary_csv' ) ) . '" class="button">Export Tax Summary CSV</a>';
		echo '</form>';

		if ( ! $rows ) {
			echo '<p>No payouts recorded yet. Use the <a href="' . esc_url( admin_url( 'admin.php?page=gas-calculator' ) ) . '">Payout Calculator</a> and save a result to start the ledger.</p>';
		} else {
			$total_gross    = 0;
			$total_cut      = 0;
			$total_cashback = 0;
			$total_override = 0;
			$total_net      = 0;
			echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Code</th><th>Partner</th><th>Sale</th><th>Installment</th><th>Gross</th><th>Tier 1 share</th><th>Buyer cash back</th><th>Tier 2/3 shares</th><th>Net to you</th><th>Status</th><th>Notes</th><th></th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$total_gross    += (float) $r->gross_commission;
				$total_cut      += (float) $r->subaffiliate_cut;
				$total_cashback += (float) $r->cashback_amount;
				$total_override += (float) $r->tier2_amount + (float) $r->tier3_amount;
				$total_net      += (float) $r->net_to_cary;
				$overrides = array();
				if ( (float) $r->tier2_amount > 0 ) {
					$overrides[] = '$' . number_format( (float) $r->tier2_amount, 2 ) . ' (T2' . ( $r->tier2_paid ? ', paid' : '' ) . ')';
				}
				if ( (float) $r->tier3_amount > 0 ) {
					$overrides[] = '$' . number_format( (float) $r->tier3_amount, 2 ) . ' (T3' . ( $r->tier3_paid ? ', paid' : '' ) . ')';
				}
				echo '<tr>';
				echo '<td>' . esc_html( $r->entered_at ) . '</td>';
				echo '<td><code>' . esc_html( $r->code ) . '</code></td>';
				echo '<td>' . esc_html( $r->partner_name ?: '(unassigned)' ) . '</td>';
				echo '<td>$' . esc_html( number_format( (float) $r->sale_amount, 2 ) ) . '</td>';
				echo '<td>' . esc_html( $r->installment_label ?: '&mdash;' ) . '</td>';
				echo '<td>$' . esc_html( number_format( (float) $r->gross_commission, 2 ) ) . '</td>';
				echo '<td>$' . esc_html( number_format( (float) $r->subaffiliate_cut, 2 ) ) . '</td>';
				echo '<td>' . self::cashback_cell( $r ) . '</td>';
				echo '<td>' . ( $overrides ? esc_html( implode( ', ', $overrides ) ) : '&mdash;' ) . '</td>';
				echo '<td>$' . esc_html( number_format( (float) $r->net_to_cary, 2 ) ) . '</td>';
				echo '<td>' . ( 'paid' === $r->status ? '<span style="color:#1a7a3c;">Paid</span>' : 'Unpaid' ) . '</td>';
				echo '<td>' . esc_html( $r->notes ) . '</td>';
				$del_url = wp_nonce_url( admin_url( 'admin-post.php?action=gas_delete_payout&id=' . $r->id ), 'gas_delete_payout_' . $r->id );
				echo '<td><a href="' . esc_url( $del_url ) . '" onclick="return confirm(\'Delete this ledger entry?\');">Delete</a></td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
			echo '<h2>Totals</h2>';
			echo '<p><strong>Total gross commission:</strong> $' . esc_html( number_format( $total_gross, 2 ) ) . '<br>';
			echo '<strong>Total owed to sub-affiliates:</strong> $' . esc_html( number_format( $total_cut, 2 ) ) . '<br>';
			echo '<strong>Total buyer cash back:</strong> $' . esc_html( number_format( $total_cashback, 2 ) ) . '<br>';
			echo '<strong>Total sponsor overrides:</strong> $' . esc_html( number_format( $total_override, 2 ) ) . '<br>';
			echo '<strong>Total net to you:</strong> $' . esc_html( number_format( $total_net, 2 ) ) . '</p>';
		}

		self::render_automated_payouts_section();

		self::wrap_end();
	}

	/**
	 * One Ledger row's "Buyer cash back" cell: the dollar amount plus
	 * whatever's known about its claim/payment state — no customer email
	 * on file (can't be claimed at all yet), a claim link to copy/resend,
	 * claimed-with-a-masked-payment-summary, or already paid. Admin never
	 * sees the customer's raw PayPal email/account number, same masking
	 * spirit as an affiliate's own banking info.
	 */
	private static function cashback_cell( $r ) {
		$amount = '$' . number_format( (float) $r->cashback_amount, 2 );
		if ( (float) $r->cashback_amount <= 0 ) {
			return $amount;
		}
		if ( $r->cashback_paid ) {
			return $amount . '<br><span style="color:#1a7a3c;">Paid ' . esc_html( $r->cashback_paid_at ) . '</span>';
		}
		if ( ! $r->customer_email ) {
			return $amount . '<br><span class="description">No customer email on file</span>';
		}
		$out = $amount . '<br>' . esc_html( $r->customer_email );
		if ( $r->cashback_claimed_at ) {
			$summary = GAS_Cashback::masked_payment_summary( $r->id );
			$out    .= '<br><span style="color:#1a7a3c;">Claimed' . ( $summary ? ': ' . esc_html( $summary ) : '' ) . '</span>';
			$mark_url = wp_nonce_url( admin_url( 'admin-post.php?action=gas_mark_cashback_paid&id=' . $r->id ), 'gas_mark_cashback_paid_' . $r->id );
			$out     .= '<br><a href="' . esc_url( $mark_url ) . '" onclick="return confirm(\'Mark this cash back as paid? Only do this after actually sending the money.\');">Mark paid</a>';
		} else {
			$out .= '<br><span class="description">Not yet claimed</span>';
		}
		return $out;
	}

	public static function handle_mark_cashback_paid() {
		if ( ! current_user_can( self::CAP_COMMISSIONS ) ) {
			wp_die( 'Not allowed.' );
		}
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'gas_mark_cashback_paid_' . $id );

		global $wpdb;
		$wpdb->update(
			GAS_DB::table( 'payouts' ),
			array( 'cashback_paid' => 1, 'cashback_paid_at' => current_time( 'mysql' ) ),
			array( 'id' => $id )
		);
		self::audit_log( 'payout', $id, 'cashback_marked_paid' );

		wp_safe_redirect( admin_url( 'admin.php?page=gas-ledger' ) );
		exit;
	}

	private static function render_payout_result_notice() {
		$notice = get_transient( 'gas_payout_result_' . get_current_user_id() );
		if ( ! $notice ) {
			return;
		}
		echo '<div class="notice notice-' . ( $notice['error'] ? 'error' : 'success' ) . '"><p>' . wp_kses_post( $notice['message'] ) . '</p></div>';
	}

	/* ---------------------------------------------------------------- *
	 * REPORTS
	 * ---------------------------------------------------------------- */

	public static function render_reports_page() {
		if ( ! current_user_can( self::CAP_REPORTS ) ) {
			return;
		}
		global $wpdb;
		self::wrap_start( 'Reports' );

		$payouts_table  = GAS_DB::table( 'payouts' );
		$leads_table    = GAS_DB::table( 'leads' );
		$partners_table = GAS_DB::table( 'partners' );
		$codes_table    = GAS_DB::table( 'codes' );
		$clicks_table   = GAS_DB::table( 'clicks' );

		$money = $wpdb->get_row( "
			SELECT
				SUM(CASE WHEN status = 'unpaid' THEN subaffiliate_cut ELSE 0 END) AS cut_unpaid,
				SUM(CASE WHEN status = 'paid'   THEN subaffiliate_cut ELSE 0 END) AS cut_paid,
				SUM(cashback_amount) AS cashback_total,
				SUM(tier2_amount + tier3_amount) AS override_total,
				SUM(net_to_cary) AS net_total
			FROM {$payouts_table}
		" );

		echo '<h2>Commission Summary</h2>';
		echo '<table class="widefat striped" style="max-width:500px;"><tbody>';
		echo '<tr><th>Tier 1 share &mdash; unpaid</th><td>$' . esc_html( number_format( (float) ( $money->cut_unpaid ?? 0 ), 2 ) ) . '</td></tr>';
		echo '<tr><th>Tier 1 share &mdash; paid</th><td>$' . esc_html( number_format( (float) ( $money->cut_paid ?? 0 ), 2 ) ) . '</td></tr>';
		echo '<tr><th>Buyer cash back (total)</th><td>$' . esc_html( number_format( (float) ( $money->cashback_total ?? 0 ), 2 ) ) . '</td></tr>';
		echo '<tr><th>Tier 2/3 shares (total)</th><td>$' . esc_html( number_format( (float) ( $money->override_total ?? 0 ), 2 ) ) . '</td></tr>';
		echo '<tr><th>Net to you (total)</th><td>$' . esc_html( number_format( (float) ( $money->net_total ?? 0 ), 2 ) ) . '</td></tr>';
		echo '</tbody></table>';

		echo '<h2 style="margin-top:30px;">Partner Outcomes</h2>';
		$partner_outcomes = $wpdb->get_results( "
			SELECT p.name,
				COUNT(l.id) AS total_leads,
				SUM(CASE WHEN l.status = 'completed' THEN 1 ELSE 0 END) AS completed,
				SUM(CASE WHEN l.status = 'lost' THEN 1 ELSE 0 END) AS lost
			FROM {$partners_table} p
			LEFT JOIN {$leads_table} l ON l.partner_id = p.id
			GROUP BY p.id
			ORDER BY total_leads DESC
		" );
		echo '<table class="widefat striped"><thead><tr><th>Partner</th><th>Total Leads</th><th>Completed</th><th>Lost</th><th>Close Rate</th></tr></thead><tbody>';
		if ( ! $partner_outcomes ) {
			echo '<tr><td colspan="5">No leads yet.</td></tr>';
		} else {
			foreach ( $partner_outcomes as $row ) {
				$rate = $row->total_leads > 0 ? round( ( $row->completed / $row->total_leads ) * 100 ) . '%' : '&mdash;';
				echo '<tr>';
				echo '<td>' . esc_html( $row->name ) . '</td>';
				echo '<td>' . esc_html( $row->total_leads ) . '</td>';
				echo '<td>' . esc_html( $row->completed ) . '</td>';
				echo '<td>' . esc_html( $row->lost ) . '</td>';
				echo '<td>' . esc_html( $rate ) . '</td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table>';

		echo '<h2 style="margin-top:30px;">Agent / Referrer Performance</h2>';
		echo '<p class="description">Ranked by total earned, so your top senders are always at the top.</p>';
		$agent_performance = $wpdb->get_results( "
			SELECT c.id, c.code, c.sub_affiliate_name,
				COUNT(DISTINCT cl.id) AS clicks,
				COUNT(DISTINCT pay.id) AS conversions,
				COALESCE(SUM(pay.subaffiliate_cut), 0) AS earned
			FROM {$codes_table} c
			LEFT JOIN {$clicks_table} cl ON cl.code_id = c.id
			LEFT JOIN {$payouts_table} pay ON pay.code_id = c.id
			GROUP BY c.id
			ORDER BY earned DESC
		" );
		echo '<table class="widefat striped"><thead><tr><th>Code</th><th>Name</th><th>Clicks</th><th>Conversions</th><th>Conversion Rate</th><th>Total Earned</th></tr></thead><tbody>';
		if ( ! $agent_performance ) {
			echo '<tr><td colspan="6">No affiliates yet.</td></tr>';
		} else {
			foreach ( $agent_performance as $row ) {
				$rate = $row->clicks > 0 ? round( ( $row->conversions / $row->clicks ) * 100, 1 ) . '%' : '&mdash;';
				echo '<tr>';
				echo '<td><code>' . esc_html( $row->code ) . '</code></td>';
				echo '<td>' . esc_html( $row->sub_affiliate_name ) . '</td>';
				echo '<td>' . esc_html( $row->clicks ) . '</td>';
				echo '<td>' . esc_html( $row->conversions ) . '</td>';
				echo '<td>' . esc_html( $rate ) . '</td>';
				echo '<td>$' . esc_html( number_format( (float) $row->earned, 2 ) ) . '</td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table>';

		self::wrap_end();
	}

	/* ---------------------------------------------------------------- *
	 * AUDIT LOG
	 * ---------------------------------------------------------------- */

	public static function render_audit_log_page() {
		if ( ! current_user_can( 'gas_view_audit_log' ) ) {
			return;
		}
		global $wpdb;
		self::wrap_start( 'Audit Log' );

		$table    = GAS_DB::table( 'audit_log' );
		$total    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		$per_page = 50;
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$offset   = ( $paged - 1 ) * $per_page;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$table} ORDER BY created_at DESC LIMIT %d OFFSET %d",
			$per_page,
			$offset
		) );

		echo '<p>' . esc_html( $total ) . ' total entries.</p>';

		if ( ! $rows ) {
			echo '<p>Nothing logged yet.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>When</th><th>Who</th><th>Object</th><th>Action</th><th>Details</th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$user = $r->user_id ? get_userdata( $r->user_id ) : null;
				echo '<tr>';
				echo '<td>' . esc_html( $r->created_at ) . '</td>';
				echo '<td>' . ( $user ? esc_html( $user->display_name ) : '<em>system</em>' ) . '</td>';
				echo '<td>' . esc_html( $r->object_type ) . ( $r->object_id ? ' #' . esc_html( $r->object_id ) : '' ) . '</td>';
				echo '<td>' . esc_html( $r->action ) . '</td>';
				echo '<td style="max-width:400px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' . esc_html( $r->details ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';

			$total_pages = (int) ceil( $total / $per_page );
			if ( $total_pages > 1 ) {
				echo '<p>';
				for ( $i = 1; $i <= $total_pages; $i++ ) {
					if ( $i === $paged ) {
						echo '<strong>' . $i . '</strong> ';
					} else {
						echo '<a href="' . esc_url( admin_url( 'admin.php?page=gas-audit-log&paged=' . $i ) ) . '">' . $i . '</a> ';
					}
				}
				echo '</p>';
			}
		}

		self::wrap_end();
	}

	/* ---------------------------------------------------------------- *
	 * SEGMENTS (contacts / mailing list)
	 * ---------------------------------------------------------------- */

	public static function render_segments_page() {
		if ( ! current_user_can( self::CAP_CONTACTS ) ) {
			return;
		}
		global $wpdb;
		self::wrap_start( 'Segments' );

		if ( isset( $_GET['reassigned'] ) ) {
			echo '<div class="notice notice-success"><p>Contact moved.</p></div>';
		}

		$table  = GAS_DB::table( 'contacts' );
		$filter = isset( $_GET['contact_type'] ) && in_array( $_GET['contact_type'], GAS_Contacts::TYPES, true ) ? sanitize_key( $_GET['contact_type'] ) : '';

		echo '<p>';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=gas-segments' ) ) . '"' . ( '' === $filter ? ' style="font-weight:bold;"' : '' ) . '>All</a> | ';
		foreach ( GAS_Contacts::TYPES as $t ) {
			$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE contact_type = %s", $t ) );
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=gas-segments&contact_type=' . $t ) ) . '"' . ( $filter === $t ? ' style="font-weight:bold;"' : '' ) . '>' . esc_html( ucfirst( $t ) ) . ' (' . $count . ')</a> | ';
		}
		echo '</p>';

		echo '<form method="get" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin:1em 0;">';
		echo '<input type="hidden" name="action" value="gas_export_contacts_csv">';
		if ( $filter ) {
			echo '<input type="hidden" name="contact_type" value="' . esc_attr( $filter ) . '">';
		}
		wp_nonce_field( 'gas_export_contacts_csv' );
		echo '<label><input type="checkbox" name="include_unsubscribed" value="1"> Include unsubscribed</label> ';
		echo '<button type="submit" class="button">Export CSV</button>';
		echo '<span class="description" style="display:block;margin-top:4px;">Unchecked (default) excludes anyone who\'s unsubscribed — safe to import straight into an ESP like Kit.</span>';
		echo '</form>';

		$sql = "SELECT * FROM {$table}";
		if ( $filter ) {
			$sql = $wpdb->prepare( $sql . ' WHERE contact_type = %s', $filter );
		}
		$rows = $wpdb->get_results( $sql . ' ORDER BY created_at DESC' );

		if ( ! $rows ) {
			echo '<p>No contacts yet.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Email</th><th>Phone</th><th>Type</th><th>Source</th><th>Subscribed</th><th>Added</th><th>Move to</th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				echo '<tr>';
				echo '<td>' . esc_html( $r->name ) . '</td>';
				echo '<td>' . esc_html( $r->email ) . '</td>';
				echo '<td>' . esc_html( $r->phone ) . '</td>';
				echo '<td>' . esc_html( ucfirst( $r->contact_type ) ) . '</td>';
				echo '<td>' . esc_html( $r->source ) . '</td>';
				echo '<td>' . ( $r->subscribed ? 'Yes' : 'No' ) . '</td>';
				echo '<td>' . esc_html( $r->created_at ) . '</td>';
				echo '<td>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline;">';
				wp_nonce_field( 'gas_reassign_contact_' . $r->id );
				echo '<input type="hidden" name="action" value="gas_reassign_contact">';
				echo '<input type="hidden" name="id" value="' . esc_attr( $r->id ) . '">';
				echo '<select name="contact_type" onchange="this.form.submit()">';
				foreach ( GAS_Contacts::TYPES as $t ) {
					echo '<option value="' . esc_attr( $t ) . '"' . selected( $r->contact_type, $t, false ) . '>' . esc_html( ucfirst( $t ) ) . '</option>';
				}
				echo '</select>';
				echo '</form>';
				echo '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		self::wrap_end();
	}

	public static function handle_reassign_contact() {
		if ( ! current_user_can( self::CAP_CONTACTS ) ) {
			wp_die( 'Not allowed.' );
		}
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		check_admin_referer( 'gas_reassign_contact_' . $id );

		$type = isset( $_POST['contact_type'] ) && in_array( $_POST['contact_type'], GAS_Contacts::TYPES, true ) ? $_POST['contact_type'] : 'customer';

		global $wpdb;
		$wpdb->update( GAS_DB::table( 'contacts' ), array( 'contact_type' => $type, 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $id ) );
		self::audit_log( 'contact', $id, 'reassigned', array( 'contact_type' => $type ) );

		wp_safe_redirect( admin_url( 'admin.php?page=gas-segments&reassigned=1' ) );
		exit;
	}

	public static function handle_export_contacts_csv() {
		if ( ! current_user_can( self::CAP_CONTACTS ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'gas_export_contacts_csv' );

		global $wpdb;
		$table  = GAS_DB::table( 'contacts' );
		$filter = isset( $_GET['contact_type'] ) && in_array( $_GET['contact_type'], GAS_Contacts::TYPES, true ) ? sanitize_key( $_GET['contact_type'] ) : '';
		// Default excludes unsubscribed — this CSV is meant to feed
		// straight into an ESP (Kit/ConvertKit), and someone who already
		// unsubscribed here shouldn't silently get re-subscribed there on
		// import. "Include unsubscribed" is an explicit opt-in checkbox.
		$include_unsubscribed = ! empty( $_GET['include_unsubscribed'] );

		$where = array();
		if ( $filter ) {
			$where[] = $wpdb->prepare( 'contact_type = %s', $filter );
		}
		if ( ! $include_unsubscribed ) {
			$where[] = 'subscribed = 1';
		}

		$sql = "SELECT * FROM {$table}";
		if ( $where ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}
		$rows = $wpdb->get_results( $sql . ' ORDER BY created_at DESC' );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="contacts-' . ( $filter ?: 'all' ) . '-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Name', 'Email', 'Phone', 'Type', 'Source', 'Subscribed', 'Added' ) );
		foreach ( $rows as $r ) {
			fputcsv( $out, array( $r->name, $r->email, $r->phone, $r->contact_type, $r->source, $r->subscribed ? 'yes' : 'no', $r->created_at ) );
		}
		fclose( $out );
		exit;
	}

	/* ---------------------------------------------------------------- *
	 * LEAD MAGNETS
	 * ---------------------------------------------------------------- */

	public static function render_lead_magnets_page() {
		if ( ! current_user_can( self::CAP_CONTACTS ) ) {
			return;
		}
		global $wpdb;
		self::wrap_start( 'Lead Magnets' );

		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success"><p>Saved.</p></div>';
		}

		echo '<h2>Add a lead magnet</h2>';
		echo '<p class="description">Upload a PDF (a guide, checklist, etc.) and give it a title. Drop the shortcode shown after saving onto any page — visitors who enter their email get it sent to them, and land in your Customer segment.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" enctype="multipart/form-data">';
		wp_nonce_field( 'gas_save_lead_magnet' );
		echo '<input type="hidden" name="action" value="gas_save_lead_magnet">';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Title</th><td><input type="text" name="title" class="regular-text" required></td></tr>';
		echo '<tr><th>Description</th><td><textarea name="description" class="large-text" rows="2"></textarea></td></tr>';
		echo '<tr><th>PDF file</th><td><input type="file" name="pdf_file" accept="application/pdf" required></td></tr>';
		echo '</tbody></table>';
		submit_button( 'Add Lead Magnet' );
		echo '</form>';

		echo '<h2>Existing lead magnets</h2>';
		$magnets = $wpdb->get_results( 'SELECT * FROM ' . GAS_DB::table( 'lead_magnets' ) . ' ORDER BY created_at DESC' );
		if ( ! $magnets ) {
			echo '<p>None yet.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Title</th><th>Shortcode</th><th>Downloads</th><th>Active</th><th>Actions</th></tr></thead><tbody>';
			foreach ( $magnets as $m ) {
				echo '<tr>';
				echo '<td>' . esc_html( $m->title ) . '</td>';
				echo '<td><code>[gas_lead_magnet id="' . esc_html( $m->id ) . '"]</code></td>';
				echo '<td>' . esc_html( $m->download_count ) . '</td>';
				echo '<td>' . ( $m->active ? 'Yes' : 'No' ) . '</td>';
				echo '<td>';
				$toggle_url = wp_nonce_url( admin_url( 'admin-post.php?action=gas_toggle_lead_magnet&id=' . $m->id ), 'gas_toggle_lead_magnet_' . $m->id );
				echo '<a href="' . esc_url( $toggle_url ) . '">' . ( $m->active ? 'Deactivate' : 'Activate' ) . '</a>';
				echo '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		self::wrap_end();
	}

	public static function handle_save_lead_magnet() {
		if ( ! current_user_can( self::CAP_CONTACTS ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'gas_save_lead_magnet' );

		$title       = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$description = isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '';

		if ( '' === $title || empty( $_FILES['pdf_file']['name'] ) ) {
			wp_die( 'Title and a PDF file are both required.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_handle_upload( 'pdf_file', 0 );
		if ( is_wp_error( $attachment_id ) ) {
			wp_die( 'Upload failed: ' . esc_html( $attachment_id->get_error_message() ) );
		}

		global $wpdb;
		$wpdb->insert(
			GAS_DB::table( 'lead_magnets' ),
			array(
				'title'         => $title,
				'description'   => $description,
				'attachment_id' => $attachment_id,
				'active'        => 1,
				'created_at'    => current_time( 'mysql' ),
			)
		);
		self::audit_log( 'lead_magnet', $wpdb->insert_id, 'created', array( 'title' => $title ) );

		wp_safe_redirect( admin_url( 'admin.php?page=gas-lead-magnets&saved=1' ) );
		exit;
	}

	public static function handle_toggle_lead_magnet() {
		if ( ! current_user_can( self::CAP_CONTACTS ) ) {
			wp_die( 'Not allowed.' );
		}
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'gas_toggle_lead_magnet_' . $id );

		global $wpdb;
		$table   = GAS_DB::table( 'lead_magnets' );
		$current = (int) $wpdb->get_var( $wpdb->prepare( "SELECT active FROM {$table} WHERE id = %d", $id ) );
		$wpdb->update( $table, array( 'active' => $current ? 0 : 1 ), array( 'id' => $id ) );

		wp_safe_redirect( admin_url( 'admin.php?page=gas-lead-magnets&saved=1' ) );
		exit;
	}

	/* ---------------------------------------------------------------- *
	 * ADMIN "VIEW AS" — PREVIEW AN AFFILIATE'S OR PARTNER'S DASHBOARD
	 * ---------------------------------------------------------------- */

	/**
	 * Lets an admin view a SPECIFIC real affiliate's or partner's dashboard,
	 * read-only, without ever creating a fake codes/partners row for the
	 * admin's own account — that would mean a fake referral code or partner
	 * sitting in real reports and commission calculations just so someone
	 * could preview a page. Instead this just remembers which record to
	 * show, in a short-lived transient scoped to this specific admin,
	 * checked by GAS_Roles::get_admin_preview() wherever the front-end
	 * dashboards look up "whose data am I showing". Ported from
	 * gemz-referral-crm's GRC_Admin::handle_start_admin_preview().
	 */
	public static function handle_start_admin_preview() {
		check_admin_referer( 'gas_start_admin_preview' );

		$type = isset( $_POST['preview_type'] ) ? sanitize_key( $_POST['preview_type'] ) : '';
		$id   = isset( $_POST['record_id'] ) ? absint( $_POST['record_id'] ) : 0;

		if ( 'agent' === $type ) {
			if ( ! current_user_can( self::CAP_CODES ) ) {
				wp_die( 'Not allowed.' );
			}
			$redirect = GAS_Frontend::dashboard_url();
		} elseif ( 'partner' === $type ) {
			if ( ! current_user_can( self::CAP_PARTNERS ) ) {
				wp_die( 'Not allowed.' );
			}
			$redirect = GAS_Partner_Portal::page_url();
		} else {
			wp_die( 'Unknown preview type.' );
		}

		if ( ! $id ) {
			wp_die( 'Missing record to preview.' );
		}

		// 'agent' id is a wp_user_id (an affiliate can hold more than one
		// code, so the dashboard is scoped by user, not by a single code);
		// 'partner' id is a partners.id, matching how the partner portal
		// already looks itself up.
		set_transient( 'gas_admin_preview_' . get_current_user_id(), array( 'type' => $type, 'id' => $id ), 15 * MINUTE_IN_SECONDS );

		wp_safe_redirect( $redirect );
		exit;
	}

	public static function handle_stop_admin_preview() {
		check_admin_referer( 'gas_stop_admin_preview' );

		if ( is_user_logged_in() ) {
			delete_transient( 'gas_admin_preview_' . get_current_user_id() );
		}

		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}

	/* ---------------------------------------------------------------- *
	 * AUTOMATED PAYOUTS (PayPal / Wise)
	 * ---------------------------------------------------------------- */

	private static function render_automated_payouts_section() {
		echo '<h2>Automated Payouts</h2>';
		echo '<p class="description">Affiliates set their own payout method and banking details from their dashboard. Configure your API credentials below, then pay everyone with an unpaid balance on that method in one click. Nothing here can see or edit an affiliate\'s account/IBAN &mdash; that\'s only ever visible to them.</p>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'gas_save_payout_api_settings' );
		echo '<input type="hidden" name="action" value="gas_save_payout_api_settings">';
		echo '<h3>PayPal</h3>';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Environment</th><td><select name="gas_paypal_env">';
		echo '<option value="sandbox"' . selected( get_option( 'gas_paypal_env', 'sandbox' ), 'sandbox', false ) . '>Sandbox</option>';
		echo '<option value="live"' . selected( get_option( 'gas_paypal_env', 'sandbox' ), 'live', false ) . '>Live</option>';
		echo '</select></td></tr>';
		echo '<tr><th>Client ID</th><td><input type="text" name="gas_paypal_client_id" class="regular-text" value="' . esc_attr( get_option( 'gas_paypal_client_id', '' ) ) . '"></td></tr>';
		echo '<tr><th>Client secret</th><td><input type="password" name="gas_paypal_client_secret" class="regular-text" value="' . esc_attr( get_option( 'gas_paypal_client_secret', '' ) ) . '"></td></tr>';
		echo '<tr><th>Payout currency</th><td><input type="text" name="gas_paypal_currency" maxlength="3" style="width:80px" value="' . esc_attr( get_option( 'gas_paypal_currency', 'USD' ) ) . '"></td></tr>';
		echo '</tbody></table>';

		echo '<h3>Wise</h3>';
		echo '<table class="form-table"><tbody>';
		echo '<tr><th>Environment</th><td><select name="gas_wise_env">';
		echo '<option value="sandbox"' . selected( get_option( 'gas_wise_env', 'sandbox' ), 'sandbox', false ) . '>Sandbox</option>';
		echo '<option value="live"' . selected( get_option( 'gas_wise_env', 'sandbox' ), 'live', false ) . '>Live</option>';
		echo '</select></td></tr>';
		echo '<tr><th>API token</th><td><input type="password" name="gas_wise_api_token" class="regular-text" value="' . esc_attr( get_option( 'gas_wise_api_token', '' ) ) . '"></td></tr>';
		echo '<tr><th>Profile ID</th><td><input type="text" name="gas_wise_profile_id" class="regular-text" value="' . esc_attr( get_option( 'gas_wise_profile_id', '' ) ) . '"></td></tr>';
		echo '<tr><th>Source currency (what you\'re paying from)</th><td><input type="text" name="gas_wise_source_currency" maxlength="3" style="width:80px" value="' . esc_attr( get_option( 'gas_wise_source_currency', 'USD' ) ) . '"></td></tr>';
		echo '</tbody></table>';

		submit_button( 'Save API Settings' );
		echo '</form>';

		echo '<p>';
		echo '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gas_paypal_payout_now' ), 'gas_paypal_payout_now' ) ) . '" class="button button-primary" onclick="return confirm(\'Send a real PayPal payout to every affiliate with an unpaid balance on PayPal?\');">Pay All PayPal Affiliates Now</a> ';
		echo '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gas_wise_payout_now' ), 'gas_wise_payout_now' ) ) . '" class="button button-primary" onclick="return confirm(\'Send real Wise transfers to every affiliate with an unpaid balance on Wise?\');">Pay All Wise Affiliates Now</a>';
		echo '</p>';

		self::render_automated_run_cron_section();
	}

	/**
	 * The URL Cary needs to paste into Hostinger's own Cron Jobs panel
	 * (hPanel) — this plugin can't create that cron entry itself, SSH on
	 * this host has no crontab access, so a real server-level schedule has
	 * to be configured there directly. Everything else (pause toggle, run
	 * day) lives on the Settings screen since those are ordinary config;
	 * this lives here since it's the one thing that needs copying
	 * somewhere else, not edited in place.
	 */
	private static function render_automated_run_cron_section() {
		if ( ! current_user_can( self::CAP_SETTINGS ) ) {
			return;
		}
		$token     = GAS_Settings::get_automated_payout_token();
		$cron_url  = rest_url( 'gas/v1/automated-payout-run' ) . '?token=' . rawurlencode( $token );
		$last_run  = get_option( 'gas_last_automated_payout_run', '' );
		$run_day   = (int) GAS_Settings::get( 'payout_run_day' );
		$paused    = GAS_Settings::get( 'payout_run_paused' );

		echo '<h3>Automated monthly payout run &mdash; server cron setup</h3>';
		echo '<p class="description">A real server cron job (not WP-Cron) needs to hit this URL once a day — the run itself only actually fires on/after day ' . esc_html( $run_day ) . ' of the month, and at most once per month, so a daily schedule is safe and simplest. In Hostinger\'s hPanel, add a Cron Job set to run daily hitting this exact URL:</p>';
		echo '<p><code style="word-break:break-all;">' . esc_html( $cron_url ) . '</code></p>';
		echo '<p class="description">Status: ' . ( $paused ? '<strong style="color:#b32d2e;">Paused</strong> (see Settings to unpause)' : '<strong style="color:#1a7a3c;">Active</strong>' ) . ( $last_run ? ', last ran ' . esc_html( $last_run ) : ', has not run yet' ) . '.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return confirm(\'Regenerate the token? The old cron URL will stop working until you update Hostinger with the new one.\');">';
		wp_nonce_field( 'gas_regenerate_payout_token' );
		echo '<input type="hidden" name="action" value="gas_regenerate_payout_token">';
		echo '<button type="submit" class="button">Regenerate token</button>';
		echo '</form>';
	}

	public static function handle_regenerate_payout_token() {
		if ( ! current_user_can( self::CAP_SETTINGS ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'gas_regenerate_payout_token' );

		GAS_Settings::regenerate_automated_payout_token();
		self::audit_log( 'settings', 0, 'payout_token_regenerated' );

		wp_safe_redirect( admin_url( 'admin.php?page=gas-ledger&token_regenerated=1' ) );
		exit;
	}

	public static function handle_save_payout_api_settings() {
		if ( ! current_user_can( self::CAP_COMMISSIONS ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'gas_save_payout_api_settings' );

		update_option( 'gas_paypal_env', 'live' === ( $_POST['gas_paypal_env'] ?? '' ) ? 'live' : 'sandbox' );
		update_option( 'gas_paypal_client_id', sanitize_text_field( wp_unslash( $_POST['gas_paypal_client_id'] ?? '' ) ) );
		update_option( 'gas_paypal_client_secret', sanitize_text_field( wp_unslash( $_POST['gas_paypal_client_secret'] ?? '' ) ) );
		update_option( 'gas_paypal_currency', strtoupper( sanitize_text_field( wp_unslash( $_POST['gas_paypal_currency'] ?? 'USD' ) ) ) );

		update_option( 'gas_wise_env', 'live' === ( $_POST['gas_wise_env'] ?? '' ) ? 'live' : 'sandbox' );
		update_option( 'gas_wise_api_token', sanitize_text_field( wp_unslash( $_POST['gas_wise_api_token'] ?? '' ) ) );
		update_option( 'gas_wise_profile_id', sanitize_text_field( wp_unslash( $_POST['gas_wise_profile_id'] ?? '' ) ) );
		update_option( 'gas_wise_source_currency', strtoupper( sanitize_text_field( wp_unslash( $_POST['gas_wise_source_currency'] ?? 'USD' ) ) ) );

		wp_safe_redirect( admin_url( 'admin.php?page=gas-ledger&saved=1' ) );
		exit;
	}

	/**
	 * Turns a payout run's `held` list (below the minimum threshold, or
	 * missing tax info — see GAS_Payouts::affiliates_with_unpaid_balance())
	 * into a short admin-facing summary, so a payout run's result notice
	 * says WHY someone wasn't paid rather than just going quiet about them.
	 */
	private static function held_summary_text( array $held ) {
		if ( ! $held ) {
			return '';
		}
		$no_tax   = count( array_filter( $held, function( $r ) { return 'no_tax_info' === $r['reason']; } ) );
		$below    = count( array_filter( $held, function( $r ) { return 'below_threshold' === $r['reason']; } ) );
		$parts    = array();
		if ( $no_tax ) {
			$parts[] = $no_tax . ' held for missing tax info';
		}
		if ( $below ) {
			$parts[] = $below . ' held below the minimum payout threshold';
		}
		return ' ' . implode( ', ', $parts ) . '.';
	}

	public static function handle_paypal_payout_now() {
		if ( ! current_user_can( self::CAP_COMMISSIONS ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'gas_paypal_payout_now' );

		$currency = get_option( 'gas_paypal_currency', 'USD' );
		$result   = GAS_PayPal_Payouts::pay_all_eligible_affiliates( $currency );

		if ( is_wp_error( $result ) ) {
			$notice = array( 'error' => true, 'message' => 'PayPal payout failed: ' . esc_html( $result->get_error_message() ) );
		} else {
			$notice = array(
				'error'   => false,
				'message' => sprintf(
					'PayPal payout sent: %d affiliate(s) paid, totaling %s %s.',
					count( $result['paid_user_ids'] ),
					esc_html( number_format( $result['total'], 2 ) ),
					esc_html( $result['currency'] )
				) . self::held_summary_text( $result['held'] ),
			);
			self::audit_log( 'payout_run', 0, 'paypal_pay_all', array( 'paid_user_ids' => $result['paid_user_ids'], 'total' => $result['total'], 'held' => $result['held'], 'trigger' => 'manual' ) );
		}

		set_transient( 'gas_payout_result_' . get_current_user_id(), $notice, 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=gas-ledger&payout_result=1' ) );
		exit;
	}

	public static function handle_wise_payout_now() {
		if ( ! current_user_can( self::CAP_COMMISSIONS ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'gas_wise_payout_now' );

		$result = GAS_Wise_Payouts::pay_all_eligible_affiliates();

		$paid_count   = count( $result['paid'] );
		$failed_count = count( $result['failed'] );
		$message      = sprintf( 'Wise payouts: %d affiliate(s) paid.', $paid_count );
		if ( $failed_count ) {
			$reasons  = array();
			foreach ( $result['failed'] as $uid => $reason ) {
				$user       = get_userdata( $uid );
				$reasons[]  = ( $user ? esc_html( $user->display_name ) : 'user #' . $uid ) . ': ' . esc_html( $reason );
			}
			$message .= ' ' . $failed_count . ' failed &mdash; ' . implode( '; ', $reasons );
		}
		$message .= self::held_summary_text( $result['held'] );

		if ( $paid_count ) {
			self::audit_log( 'payout_run', 0, 'wise_pay_all', array( 'paid' => $result['paid'], 'failed_count' => $failed_count, 'held' => $result['held'], 'trigger' => 'manual' ) );
		}

		set_transient( 'gas_payout_result_' . get_current_user_id(), array( 'error' => (bool) $failed_count && ! $paid_count, 'message' => $message ), 60 );
		wp_safe_redirect( admin_url( 'admin.php?page=gas-ledger&payout_result=1' ) );
		exit;
	}

	public static function handle_export_ledger_csv() {
		if ( ! current_user_can( self::CAP_COMMISSIONS ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'gas_export_ledger_csv' );

		global $wpdb;
		$payouts_table  = GAS_DB::table( 'payouts' );
		$partners_table = GAS_DB::table( 'partners' );

		$rows = $wpdb->get_results(
			"SELECT pay.*, p.name AS partner_name FROM {$payouts_table} pay
			 LEFT JOIN {$partners_table} p ON p.id = pay.partner_id
			 ORDER BY pay.entered_at DESC"
		);

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="payout-ledger-' . gmdate( 'Y-m-d' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Date', 'Code', 'Partner', 'Sale Amount', 'Installment', 'Gross Commission', 'Sub-affiliate Cut', 'Buyer Cash Back', 'Tier 2 Override', 'Tier 2 Paid', 'Tier 3 Override', 'Tier 3 Paid', 'Net', 'Status', 'Paid At', 'Notes' ) );
		foreach ( $rows as $r ) {
			fputcsv( $out, array(
				$r->entered_at,
				$r->code,
				$r->partner_name ?: '',
				$r->sale_amount,
				$r->installment_label ?: '',
				$r->gross_commission,
				$r->subaffiliate_cut,
				$r->cashback_amount,
				$r->tier2_amount,
				$r->tier2_paid ? 'yes' : 'no',
				$r->tier3_amount,
				$r->tier3_paid ? 'yes' : 'no',
				$r->net_to_cary,
				$r->status,
				$r->paid_at ?: '',
				$r->notes,
			) );
		}
		fclose( $out );
		exit;
	}

	/**
	 * Accountant-ready CSV of everything actually PAID to each affiliate in
	 * one calendar year, plus whatever tax info they've submitted —
	 * exactly what's needed to hand to an accountant or a real 1099-NEC
	 * e-filing service (Track1099/Tax1099/etc.); this plugin doesn't file
	 * anything with the IRS itself. Contains full, unmasked SSN/EIN/TIN
	 * values (unlike the admin-facing masked_tax_summary() shown on
	 * screen) since that's what a real 1099 filing actually requires —
	 * treat the downloaded file as sensitive, same as you would a payroll
	 * export. Only affiliates paid something > $0 in the selected year are
	 * included; a $0 year isn't 1099-relevant.
	 */
	public static function handle_export_tax_summary_csv() {
		if ( ! current_user_can( self::CAP_COMMISSIONS ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'gas_export_tax_summary_csv' );

		$year = isset( $_GET['year'] ) ? absint( $_GET['year'] ) : (int) current_time( 'Y' );

		global $wpdb;
		$codes_table = GAS_DB::table( 'codes' );
		$user_ids    = $wpdb->get_col( "SELECT DISTINCT wp_user_id FROM {$codes_table} WHERE wp_user_id IS NOT NULL" );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="tax-summary-' . $year . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Affiliate Name', 'Email', 'Total Paid ' . $year, 'Tax Form Type', 'Legal Name', 'Tax ID', 'Country', 'Tax Info Submitted' ) );

		foreach ( $user_ids as $user_id ) {
			$user_id = (int) $user_id;
			$total   = GAS_Payouts::paid_this_calendar_year( $user_id, $year );
			if ( $total <= 0 ) {
				continue;
			}
			$user = get_userdata( $user_id );
			$tax  = GAS_Payouts::get_tax_info( $user_id );
			fputcsv( $out, array(
				$user ? $user->display_name : 'user #' . $user_id,
				$user ? $user->user_email : '',
				number_format( $total, 2, '.', '' ),
				$tax['form_type'] ? strtoupper( $tax['form_type'] ) : 'NOT ON FILE',
				$tax['legal_name'],
				$tax['tax_id'],
				$tax['country'],
				$tax['submitted_at'] ?: '',
			) );
		}
		fclose( $out );
		exit;
	}

	public static function handle_delete_payout() {
		if ( ! current_user_can( self::CAP_COMMISSIONS ) ) {
			wp_die( 'Not allowed.' );
		}
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'gas_delete_payout_' . $id );

		global $wpdb;
		$wpdb->delete( GAS_DB::table( 'payouts' ), array( 'id' => $id ) );
		self::audit_log( 'payout', $id, 'deleted' );

		wp_safe_redirect( admin_url( 'admin.php?page=gas-ledger&deleted=1' ) );
		exit;
	}

	/* ---------------------------------------------------------------- *
	 * SETTINGS
	 * ---------------------------------------------------------------- */

	public static function render_settings_page() {
		if ( ! current_user_can( self::CAP_SETTINGS ) ) {
			return;
		}
		$settings = GAS_Settings::all();

		self::wrap_start( 'Settings' );

		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
		}

		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'gas_save_settings' );
		echo '<input type="hidden" name="action" value="gas_save_settings">';
		echo '<table class="form-table"><tbody>';

		echo '<tr><th><label for="site_name">Program name</label></th><td><input type="text" id="site_name" name="site_name" class="regular-text" required value="' . esc_attr( $settings['site_name'] ) . '"> <p class="description">Shown as the admin menu label and page heading.</p></td></tr>';

		echo '<tr><th><label for="partner_label">Partner label</label></th><td><input type="text" id="partner_label" name="partner_label" class="regular-text" required value="' . esc_attr( $settings['partner_label'] ) . '"> <p class="description">The word used for "partner" in admin screens, e.g. "solar partner" or "builder". New affiliates never choose or see a partner at signup on any project &mdash; they always go live unassigned, and an admin matches them to a partner afterward from the Codes screen.</p></td></tr>';

		echo '<tr><th><label for="conversion_noun">Conversion noun</label></th><td><input type="text" id="conversion_noun" name="conversion_noun" class="regular-text" required value="' . esc_attr( $settings['conversion_noun'] ) . '"> <p class="description">What a paid referral is called on the public signup/refer page, e.g. "installation", "home", "sale". Used in copy like "$X per completed &lt;this&gt; you refer" &mdash; pick whatever reads naturally for this site.</p></td></tr>';

		echo '<tr><th><label for="menu_icon">Admin menu icon</label></th><td><input type="text" id="menu_icon" name="menu_icon" class="regular-text" value="' . esc_attr( $settings['menu_icon'] ) . '"> <p class="description">A <a href="https://developer.wordpress.org/resource/dashicons/" target="_blank" rel="noopener">dashicon</a> slug, e.g. dashicons-groups.</p></td></tr>';

		echo '<tr><th>Color theme</th><td>';
		echo '<p class="description">Sets this site\'s color on every public-facing page (signup, dashboard, partner portal, help). Doesn\'t affect wp-admin screens.</p>';
		echo '<div style="display:flex;gap:1em;flex-wrap:wrap;">';
		foreach ( GAS_Settings::THEMES as $key => $palette ) {
			$checked = checked( $settings['theme'], $key, false );
			echo '<label style="display:flex;align-items:center;gap:.5em;border:1.5px solid ' . ( $settings['theme'] === $key ? esc_attr( $palette['accent'] ) : '#dcdcde' ) . ';border-radius:8px;padding:.6em 1em;cursor:pointer;">';
			echo '<input type="radio" name="theme" value="' . esc_attr( $key ) . '"' . $checked . '> ';
			echo '<span style="display:inline-block;width:18px;height:18px;border-radius:50%;background:' . esc_attr( $palette['accent'] ) . ';border:1px solid rgba(0,0,0,.15);"></span> ';
			echo esc_html( $palette['label'] );
			echo '</label>';
		}
		echo '</div>';
		echo '</td></tr>';

		echo '<tr><th>Commission tier split</th><td>';
		echo '<p class="description">The gross commission on a sale is a fixed pool, split across up to 3 tiers by these percentages &mdash; the same split for every affiliate, not individually negotiable. Total payout never grows with recruiting depth: if a tier has no one in it (e.g. the affiliate has no sponsor), that tier\'s share simply stays with you rather than going to anyone else.</p>';
		echo 'Tier 1 (the affiliate): <input type="number" step="0.01" min="0" max="100" name="tier1_split_percent" value="' . esc_attr( $settings['tier1_split_percent'] ) . '" style="width:80px"> % &nbsp; ';
		echo 'Tier 2 (their sponsor): <input type="number" step="0.01" min="0" max="100" name="tier2_split_percent" value="' . esc_attr( $settings['tier2_split_percent'] ) . '" style="width:80px"> % &nbsp; ';
		echo 'Tier 3 (sponsor\'s sponsor): <input type="number" step="0.01" min="0" max="100" name="tier3_split_percent" value="' . esc_attr( $settings['tier3_split_percent'] ) . '" style="width:80px"> %';
		echo '</td></tr>';

		echo '<tr><th>Get-a-Quote page content</th><td>';
		echo '<label>Intro text<br><textarea name="quote_page_intro" class="large-text" rows="4" placeholder="A sentence or two explaining the offer and what happens after they submit.">' . esc_textarea( $settings['quote_page_intro'] ) . '</textarea></label><br><br>';
		if ( ! empty( $settings['quote_page_image_id'] ) ) {
			$thumb = wp_get_attachment_image( $settings['quote_page_image_id'], array( 120, 120 ) );
			if ( $thumb ) {
				echo '<div style="margin-bottom:0.5em;">' . $thumb . '</div>';
			}
		}
		echo '<label>Image (optional)<br><input type="file" name="quote_page_image" accept="image/*"></label>';
		echo '<p class="description">Shown above the on-site quote form for this whole project/site &mdash; one shared design for every fulfillment partner and affiliate, since the customer never sees or needs to know which specific partner ends up handling their request. Leave the image blank to keep the current one (if any).</p>';
		echo '</td></tr>';

		echo '<tr><th><label for="min_payout_threshold">Minimum payout threshold ($)</label></th><td><input type="number" step="0.01" min="0" id="min_payout_threshold" name="min_payout_threshold" style="width:120px" value="' . esc_attr( $settings['min_payout_threshold'] ) . '"> <p class="description">The PayPal/Wise automated payout runs skip anyone with an unpaid balance under this amount &mdash; their balance carries forward untouched rather than triggering a payout (and a transfer fee eating a chunk of a tiny amount). Doesn\'t affect the Payout Calculator/Ledger, only the automated runs.</p></td></tr>';

		echo '<tr><th>Automated monthly payout run</th><td>';
		echo '<p class="description">Fires automatically each month via a real server cron job (not WP-Cron) &mdash; see the Payout Ledger screen for the exact URL to schedule. Only ever pays out CLOSED prior months, never the current still-open month.</p>';
		echo '<label>Run on/after day of month: <input type="number" min="1" max="28" name="payout_run_day" style="width:70px" value="' . esc_attr( $settings['payout_run_day'] ) . '"></label><br><br>';
		echo '<label><input type="checkbox" name="payout_run_paused" value="1"' . checked( $settings['payout_run_paused'], true, false ) . '> Pause the automated run (skips it entirely until unchecked &mdash; for when something needs manual attention first)</label>';
		echo '</td></tr>';

		echo '<tr><th>Compliance footer (email)</th><td>';
		echo '<p class="description">Appended to every customer- and affiliate-facing email &mdash; business name/address and a "why you\'re receiving this" line, standard commercial-email practice.</p>';
		echo '<label>Business/legal name<br><input type="text" name="business_name" class="regular-text" placeholder="' . esc_attr( $settings['site_name'] ) . ' (defaults to Program name above if left blank)" value="' . esc_attr( $settings['business_name'] ) . '"></label><br><br>';
		echo '<label>Business address<br><input type="text" name="business_address" class="large-text" placeholder="Street, City, State ZIP" value="' . esc_attr( $settings['business_address'] ) . '"></label><br><br>';
		echo '<label>Program terms URL (optional)<br><input type="url" name="program_terms_url" class="regular-text" placeholder="https://..." value="' . esc_attr( $settings['program_terms_url'] ) . '"> <span class="description">Once the affiliate agreement is live as a page, link it here &mdash; the footer line is omitted until then.</span></label>';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( 'Save Settings' );
		echo '</form>';

		self::wrap_end();
	}

	public static function handle_save_settings() {
		if ( ! current_user_can( self::CAP_SETTINGS ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'gas_save_settings' );

		$values = array(
			'site_name'                 => isset( $_POST['site_name'] ) ? sanitize_text_field( wp_unslash( $_POST['site_name'] ) ) : '',
			'partner_label'             => isset( $_POST['partner_label'] ) ? sanitize_text_field( wp_unslash( $_POST['partner_label'] ) ) : 'partner',
			'conversion_noun'           => isset( $_POST['conversion_noun'] ) ? sanitize_text_field( wp_unslash( $_POST['conversion_noun'] ) ) : 'installation',
			'menu_icon'                 => isset( $_POST['menu_icon'] ) ? sanitize_text_field( wp_unslash( $_POST['menu_icon'] ) ) : 'dashicons-groups',
			'theme'                     => ( isset( $_POST['theme'] ) && array_key_exists( $_POST['theme'], GAS_Settings::THEMES ) ) ? sanitize_text_field( wp_unslash( $_POST['theme'] ) ) : 'green',
			'tier1_split_percent'       => isset( $_POST['tier1_split_percent'] ) ? (float) $_POST['tier1_split_percent'] : 70,
			'tier2_split_percent'       => isset( $_POST['tier2_split_percent'] ) ? (float) $_POST['tier2_split_percent'] : 20,
			'tier3_split_percent'       => isset( $_POST['tier3_split_percent'] ) ? (float) $_POST['tier3_split_percent'] : 10,
			'quote_page_intro'          => isset( $_POST['quote_page_intro'] ) ? sanitize_textarea_field( wp_unslash( $_POST['quote_page_intro'] ) ) : '',
			'min_payout_threshold'      => isset( $_POST['min_payout_threshold'] ) ? (float) $_POST['min_payout_threshold'] : 50,
			'payout_run_day'            => isset( $_POST['payout_run_day'] ) ? max( 1, min( 28, absint( $_POST['payout_run_day'] ) ) ) : 5,
			'payout_run_paused'         => ! empty( $_POST['payout_run_paused'] ),
			'business_name'             => isset( $_POST['business_name'] ) ? sanitize_text_field( wp_unslash( $_POST['business_name'] ) ) : '',
			'business_address'          => isset( $_POST['business_address'] ) ? sanitize_text_field( wp_unslash( $_POST['business_address'] ) ) : '',
			'program_terms_url'         => isset( $_POST['program_terms_url'] ) ? esc_url_raw( wp_unslash( $_POST['program_terms_url'] ) ) : '',
		);

		// Image is optional per save — only touch quote_page_image_id when
		// a new file was actually uploaded, so leaving the field blank
		// keeps whatever image (if any) is already set.
		if ( ! empty( $_FILES['quote_page_image']['name'] ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
			$attachment_id = media_handle_upload( 'quote_page_image', 0 );
			if ( ! is_wp_error( $attachment_id ) ) {
				$values['quote_page_image_id'] = $attachment_id;
			}
		}

		GAS_Settings::update( $values );

		self::audit_log( 'settings', 0, 'updated' );

		wp_safe_redirect( admin_url( 'admin.php?page=gas-settings&saved=1' ) );
		exit;
	}

	/* ---------------------------------------------------------------- *
	 * ADMIN HELP
	 * ---------------------------------------------------------------- */

	public static function render_admin_help_page() {
		self::wrap_start( 'Help' );
		$site_name = GAS_Settings::get( 'site_name' );
		?>
		<h2>Screens at a glance</h2>
		<ul style="list-style:disc;margin-left:1.5em;">
			<li><strong>Affiliates</strong> — every self-signed-up affiliate; suspend/reactivate their link here. A "Codes" section further down the same screen handles manually adding an offline-referral code, or auditing/deactivating one.</li>
			<li><strong>Campaigns</strong> — the actual promotable links: a name, a partner, a URL tracking slug, and optional landing-page variants. Any affiliate's code works with any active campaign automatically — that's what actually makes a link "theirs." Marking a partner "Open to self-signup" auto-creates that partner's first default campaign; add more from this screen any time.</li>
			<li><strong>Partners</strong> — your fulfillment partners: payout terms, buyer cash back, fulfillment mode (redirect vs. on-site lead capture), whether they're open to self-signup (auto-creates a default campaign), the dashboard blurb/spotlight link/capability icons affiliates see on that partner's campaign cards, and partner portal login.</li>
			<li><strong>Leads</strong> — on-site lead-capture submissions, for partners set to that mode.</li>
			<li><strong>Click Log</strong> — raw click history per code.</li>
			<li><strong>Reports</strong> — commission summary, partner outcomes, and agent/referrer performance ranked by earnings.</li>
			<li><strong>Payout Calculator / Ledger</strong> — enter a completed sale to compute and record the tier split. The Ledger tracks everything entered, paid or not; shows each row's buyer cash back claim/payment status with a manual "Mark cashback paid" action; has PayPal/Wise "Pay All Now" buttons plus the automated-monthly-run cron URL and status; and has a Tax Summary CSV export for your accountant.</li>
			<li><strong>Audit Log</strong> — who changed what, when.</li>
			<li><strong>Segments</strong> — every contact this plugin has ever talked to (affiliate/customer/partner), filterable and exportable for outreach — excludes anyone unsubscribed by default.</li>
			<li><strong>Lead Magnets</strong> — PDF opt-in forms that grow your customer segment.</li>
			<li><strong>Settings</strong> — program name, terminology, commission tier split percentages, minimum payout threshold, compliance-footer business info, and the automated payout run's pause toggle and day-of-month.</li>
		</ul>

		<h2>How commissions work</h2>
		<p>Gross commission on a sale is a fixed pool, split across up to 3 tiers (Settings controls the percentages): the affiliate who made the sale, their sponsor (whoever recruited them), and the sponsor's own sponsor. A tier with no one in it keeps its share as net to <?php echo esc_html( $site_name ); ?> — it's never redistributed to the tiers that do have someone in them. A partner can also be configured to pay the buyer cash back, separate from the tier split — see "Buyer cash back" below.</p>

		<h2>Tax compliance and minimum payout</h2>
		<p>An affiliate must have a W-9 (US) or W-8BEN (non-US) on file before ANY payout goes out — not just once they'd cross the IRS's $600/year threshold, which avoids a partial-year tracking edge case. The automated and manual PayPal/Wise payout runs both hold anyone missing this (or below the $50 minimum payout threshold in Settings) rather than paying them, and email the affiliate why — see the Payout Ledger for a per-run breakdown of who was held and why, and the Tax Summary CSV export for a per-affiliate, per-year total to hand your accountant (not a 1099 e-filer itself).</p>

		<h2>Buyer cash back</h2>
		<p>If a partner is configured with buyer cash back, entering that sale in the Payout Calculator with a customer email automatically emails the customer a link to claim it — they choose PayPal/Wise/other themselves, the same way an affiliate sets their own payout method. You see a masked summary and a manual "Mark cashback paid" button on the Ledger once they've claimed; it's not wired into the automated PayPal/Wise batch runs.</p>

		<h2>Self-referral</h2>
		<p>An affiliate is allowed to use their own referral link and become their own customer on a real sale — the commission pool is fixed either way, so this doesn't cost anything extra. What's actually flagged (a non-blocking note in the Audit Log, never a block) is a sponsor chain where two different accounts share the same payout email, PayPal/Wise details, tax ID, or signup IP — a signal worth a manual look, not proof of anything by itself.</p>

		<h2>Roles</h2>
		<p>Administrators have full access. The "Affiliate Program Manager" role can run every screen above but can never install plugins, manage other WordPress users, or touch general site settings — safe to hand to a trusted staff member.</p>

		<h2>Front-end pages this plugin manages</h2>
		<p>Become an Affiliate, Affiliate Dashboard, Affiliate Help, Partner Portal, Partner Help, Get a Quote (lead capture), and FAQ are all auto-created on first activation — safe to move in your nav menu, but avoid changing their slugs since the plugin links to them by page ID.</p>
		<?php
		self::wrap_end();
	}
}
