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
	const CAP_LEADS       = 'gas_manage_leads';
	const CAP_COMMISSIONS = 'gas_manage_commissions';
	const CAP_REPORTS     = 'gas_view_reports';
	const CAP_SETTINGS    = 'gas_manage_settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
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
		add_action( 'admin_post_gas_save_payout_api_settings', array( __CLASS__, 'handle_save_payout_api_settings' ) );
		add_action( 'admin_post_gas_paypal_payout_now', array( __CLASS__, 'handle_paypal_payout_now' ) );
		add_action( 'admin_post_gas_wise_payout_now', array( __CLASS__, 'handle_wise_payout_now' ) );
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
		add_submenu_page( 'gas-affiliates', 'Sub-Affiliate Codes', 'Codes', self::CAP_CODES, 'gas-codes', array( __CLASS__, 'render_codes_page' ) );
		add_submenu_page( 'gas-affiliates', 'Partners', 'Partners', self::CAP_PARTNERS, 'gas-partners', array( __CLASS__, 'render_partners_page' ) );
		add_submenu_page( 'gas-affiliates', 'Leads', 'Leads', self::CAP_LEADS, 'gas-leads', array( __CLASS__, 'render_leads_page' ) );
		add_submenu_page( 'gas-affiliates', 'Click Log', 'Click Log', self::CAP_REPORTS, 'gas-clicks', array( __CLASS__, 'render_clicks_page' ) );
		add_submenu_page( 'gas-affiliates', 'Payout Calculator', 'Payout Calculator', self::CAP_COMMISSIONS, 'gas-calculator', array( __CLASS__, 'render_calculator_page' ) );
		add_submenu_page( 'gas-affiliates', 'Payout Ledger', 'Payout Ledger', self::CAP_COMMISSIONS, 'gas-ledger', array( __CLASS__, 'render_ledger_page' ) );
		add_submenu_page( 'gas-affiliates', 'Settings', 'Settings', self::CAP_SETTINGS, 'gas-settings', array( __CLASS__, 'render_settings_page' ) );
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
			"SELECT c.*, p.name AS partner_name FROM {$codes_table} c
			 LEFT JOIN {$partners_table} p ON p.id = c.partner_id
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
			self::wrap_end();
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Email</th><th>Partner</th><th>Code</th><th>Cut rate</th><th>Status</th><th>Payment info</th><th>Actions</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$user    = get_userdata( $r->wp_user_id );
			$email   = $user ? $user->user_email : '(deleted user)';
			$payment = $r->wp_user_id ? GAS_Payouts::masked_summary( $r->wp_user_id ) : '';
			$cut     = 'percent' === $r->cut_type ? esc_html( $r->cut_value ) . '%' : '$' . esc_html( number_format( (float) $r->cut_value, 2 ) ) . ' flat';
			if ( 0.0 === (float) $r->cut_value ) {
				$cut .= ' <span style="color:#b32d2e;">(0 &mdash; check this)</span>';
			}

			echo '<tr>';
			echo '<td>' . esc_html( $r->sub_affiliate_name ) . '</td>';
			echo '<td>' . esc_html( $email ) . '</td>';
			echo '<td>' . esc_html( $r->partner_name ?: '(unassigned)' ) . '</td>';
			echo '<td><code>' . esc_html( $r->code ) . '</code></td>';
			echo '<td>' . $cut . '</td>';
			echo '<td>' . esc_html( $r->status ) . '</td>';
			echo '<td>' . ( $payment ? esc_html( $payment ) : '<em>not set</em>' ) . '</td>';
			echo '<td>';
			echo '<a href="' . esc_url( admin_url( 'admin.php?page=gas-codes&edit=' . $r->id ) ) . '">Edit rate</a> | ';

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

		wp_safe_redirect( admin_url( 'admin.php?page=gas-affiliates&reactivated=1' ) );
		exit;
	}

	/* ---------------------------------------------------------------- *
	 * CODES (sub-affiliates)
	 * ---------------------------------------------------------------- */

	public static function render_codes_page() {
		if ( ! current_user_can( self::CAP_CODES ) ) {
			return;
		}
		$edit_id  = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
		$editing  = $edit_id ? self::get_code( $edit_id ) : null;
		$partners = self::get_partners();

		self::wrap_start( 'Sub-Affiliate Codes' );

		if ( isset( $_GET['saved'] ) ) {
			echo '<div class="notice notice-success"><p>Saved.</p></div>';
		}
		if ( isset( $_GET['deleted'] ) ) {
			echo '<div class="notice notice-success"><p>Deleted.</p></div>';
		}

		echo '<h2>' . ( $editing ? 'Edit Code' : 'Add a Code' ) . '</h2>';
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

		$cut_type = $editing->cut_type ?? 'percent';
		echo '<tr><th>Sub-affiliate cut</th><td>';
		echo '<select name="cut_type">';
		echo '<option value="percent"' . selected( $cut_type, 'percent', false ) . '>Percent of commission</option>';
		echo '<option value="flat"' . selected( $cut_type, 'flat', false ) . '>Flat dollar amount per sale</option>';
		echo '</select> ';
		echo '<input type="number" step="0.01" min="0" name="cut_value" value="' . esc_attr( $editing->cut_value ?? '' ) . '" placeholder="e.g. 50 for 50%, or 25.00 for $25 flat"> ';
		echo '<p class="description">This is what YOU owe the sub-affiliate, out of your own commission, per sale attributed to this code. Rates can differ per partner &mdash; add a separate code per partner if the same person promotes more than one.</p>';
		echo '</td></tr>';

		echo '<tr><th><label for="active">Active</label></th><td><label><input type="checkbox" id="active" name="active" value="1"' . checked( $editing->active ?? 1, 1, false ) . '> Redirect and log clicks for this code</label></td></tr>';

		echo '<tr><th><label for="notes">Notes</label></th><td><textarea id="notes" name="notes" class="large-text" rows="2">' . esc_textarea( $editing->notes ?? '' ) . '</textarea></td></tr>';

		echo '</tbody></table>';
		submit_button( $editing ? 'Update Code' : 'Add Code' );
		echo '</form>';

		if ( $editing ) {
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=gas-codes' ) ) . '">&larr; Cancel edit</a></p>';
		}

		echo '<h2>Existing Codes</h2>';
		$codes = self::get_codes();
		if ( ! $codes ) {
			echo '<p>No codes yet.</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>Code</th><th>Link</th><th>Sub-affiliate</th><th>Partner</th><th>Cut</th><th>Active</th><th>Actions</th></tr></thead><tbody>';
			foreach ( $codes as $c ) {
				$link = home_url( '/go/' . rawurlencode( $c->code ) . '/' );
				$cut  = 'percent' === $c->cut_type ? esc_html( $c->cut_value ) . '%' : '$' . esc_html( number_format( (float) $c->cut_value, 2 ) ) . ' flat';
				echo '<tr>';
				echo '<td><code>' . esc_html( $c->code ) . '</code></td>';
				echo '<td><code>' . esc_html( $link ) . '</code></td>';
				echo '<td>' . esc_html( $c->sub_affiliate_name ) . '</td>';
				echo '<td>' . esc_html( $c->partner_name ?: '(none)' ) . '</td>';
				echo '<td>' . $cut . '</td>';
				echo '<td>' . ( $c->active ? 'Yes' : 'No' ) . '</td>';
				echo '<td>';
				echo '<a href="' . esc_url( admin_url( 'admin.php?page=gas-codes&edit=' . $c->id ) ) . '">Edit</a> | ';
				$del_url = wp_nonce_url( admin_url( 'admin-post.php?action=gas_delete_code&id=' . $c->id ), 'gas_delete_code_' . $c->id );
				echo '<a href="' . esc_url( $del_url ) . '" onclick="return confirm(\'Delete this code? Click history stays but will no longer link to a code.\');">Delete</a>';
				echo '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		self::wrap_end();
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
		$cut_type           = isset( $_POST['cut_type'] ) && 'flat' === $_POST['cut_type'] ? 'flat' : 'percent';
		$cut_value          = isset( $_POST['cut_value'] ) ? (float) $_POST['cut_value'] : 0;
		$active             = isset( $_POST['active'] ) ? 1 : 0;
		$notes              = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';

		$data = array(
			'code'               => $code,
			'sub_affiliate_name' => $sub_affiliate_name,
			'partner_id'         => $partner_id,
			'cut_type'           => $cut_type,
			'cut_value'          => $cut_value,
			'active'             => $active,
			'notes'              => $notes,
		);

		if ( $id ) {
			$wpdb->update( $table, $data, array( 'id' => $id ) );
		} else {
			$data['created_at'] = current_time( 'mysql' );
			$wpdb->insert( $table, $data );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=gas-codes&saved=1' ) );
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

		wp_safe_redirect( admin_url( 'admin.php?page=gas-codes&deleted=1' ) );
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

			echo '<tr><th>Default sub-affiliate cut</th><td>';
			echo '<select name="default_cut_type">';
			echo '<option value="percent"' . selected( $editing->default_cut_type ?? 'percent', 'percent', false ) . '>Percent of commission</option>';
			echo '<option value="flat"' . selected( $editing->default_cut_type ?? 'percent', 'flat', false ) . '>Flat dollar amount per sale</option>';
			echo '</select> ';
			echo '<input type="number" step="0.01" min="0" name="default_cut_value" value="' . esc_attr( $editing->default_cut_value ?? '0' ) . '"> ';
			echo '<p class="description">Applied automatically to new self-signup affiliates for this partner, when signup requires choosing a partner up front. You can still override any individual affiliate\'s rate later from the Codes screen.</p>';
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
			echo '<p class="description">"Redirect" sends clicks straight to the Destination URL below (today\'s behavior). "Capture the lead on this site" instead shows an on-site form and records a Lead here for you to work &mdash; use this for projects that don\'t have (or don\'t want to rely on) a partner-hosted booking flow. The appointment checkbox only matters in capture mode: leave it unchecked for projects that just want contact info, no scheduled appointment.</p>';
			echo '</td></tr>';

			echo '<tr><th>Destination URL</th><td><input type="url" name="destination_url" class="regular-text" value="' . esc_attr( $editing->destination_url ?? '' ) . '" placeholder="https://... (your real referral tracking link with this partner)"> <p class="description">Only used in "Redirect to partner site" mode. Leave blank until the partnership/affiliate application is approved &mdash; codes for this partner will redirect visitors to the homepage in the meantime, but clicks still get logged.</p></td></tr>';

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
				echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Payout structure</th><th>Default sub-affiliate cut</th><th>Buyer cash back</th><th>Fulfillment</th><th>Destination URL</th><th>Actions</th></tr></thead><tbody>';
				foreach ( $partners as $p ) {
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
					$default_cut = 'percent' === $p->default_cut_type ? esc_html( $p->default_cut_value ) . '%' : '$' . esc_html( number_format( (float) $p->default_cut_value, 2 ) ) . ' flat';
					$cashback    = $p->cashback_type ? ( 'percent' === $p->cashback_type ? esc_html( $p->cashback_value ) . '%' : '$' . esc_html( number_format( (float) $p->cashback_value, 2 ) ) . ' flat' ) : '<em>none</em>';
					echo '<td>' . esc_html( $p->name ) . '</td>';
					echo '<td>' . $structure . '</td>';
					echo '<td>' . $default_cut . '</td>';
					echo '<td>' . $cashback . '</td>';
					echo '<td>' . esc_html( self::fulfillment_mode_label( $p->fulfillment_mode ) ) . '</td>';
					echo '<td>' . ( $p->destination_url ? '<code>' . esc_html( $p->destination_url ) . '</code>' : '<em>not set yet</em>' ) . '</td>';
					echo '<td><a href="' . esc_url( admin_url( 'admin.php?page=gas-partners&edit=' . $p->id ) ) . '">Edit</a></td>';
					echo '</tr>';
				}
				echo '</tbody></table>';
			}
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
				'installments_json' => null,
				'default_cut_type'  => 'percent',
				'default_cut_value' => 0,
				'cashback_type'     => null,
				'cashback_value'    => 0,
				'fulfillment_mode'  => 'redirect',
				'requires_appointment' => 1,
				'destination_url'   => '',
				'notes'             => '',
				'created_at'        => current_time( 'mysql' ),
			)
		);

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
			'installments_json' => $installments ? wp_json_encode( $installments ) : null,
			'default_cut_type'  => isset( $_POST['default_cut_type'] ) && 'flat' === $_POST['default_cut_type'] ? 'flat' : 'percent',
			'default_cut_value' => isset( $_POST['default_cut_value'] ) ? (float) $_POST['default_cut_value'] : 0,
			'cashback_type'     => isset( $_POST['cashback_type'] ) && in_array( $_POST['cashback_type'], array( 'flat', 'percent' ), true ) ? $_POST['cashback_type'] : null,
			'cashback_value'    => isset( $_POST['cashback_value'] ) ? (float) $_POST['cashback_value'] : 0,
			'fulfillment_mode'  => isset( $_POST['fulfillment_mode'] ) && 'lead_capture' === $_POST['fulfillment_mode'] ? 'lead_capture' : 'redirect',
			'requires_appointment' => isset( $_POST['requires_appointment'] ) ? 1 : 0,
			'destination_url'   => isset( $_POST['destination_url'] ) ? esc_url_raw( wp_unslash( $_POST['destination_url'] ) ) : '',
			'notes'             => isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '',
		);

		$wpdb->update( GAS_DB::table( 'partners' ), $data, array( 'id' => $id ) );

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

		echo '<table class="widefat striped"><thead><tr><th>Received</th><th>Customer</th><th>Contact</th><th>Partner</th><th>Appointment</th><th>Status</th></tr></thead><tbody>';
		foreach ( $rows as $l ) {
			echo '<tr>';
			echo '<td>' . esc_html( $l->created_at ) . '</td>';
			echo '<td>' . esc_html( $l->customer_name ) . '</td>';
			echo '<td>' . esc_html( $l->customer_email ) . ( $l->customer_email && $l->customer_phone ? '<br>' : '' ) . esc_html( $l->customer_phone ) . '</td>';
			echo '<td>' . esc_html( $l->partner_name ?: '&mdash;' ) . '</td>';
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
				echo '<p><strong>Sub-affiliate cut:</strong> $' . esc_html( number_format( $result['cut'], 2 ) ) . '</p>';
				if ( $result['cashback'] > 0 ) {
					echo '<p><strong>Buyer cash back:</strong> $' . esc_html( number_format( $result['cashback'], 2 ) ) . '</p>';
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
		echo '</select></td></tr>';

		echo '<tr><th>Sale amount ($)</th><td><input type="number" step="0.01" min="0" name="sale_amount" required></td></tr>';

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
			function gasUpdateInstallments() {
				var codeSel = document.getElementById("code_id");
				var partnerId = codeSel.options[codeSel.selectedIndex] ? codeSel.options[codeSel.selectedIndex].getAttribute("data-partner") : null;
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
		</script>';

		self::wrap_end();
	}

	public static function handle_calculate_payout() {
		if ( ! current_user_can( self::CAP_COMMISSIONS ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'gas_calculate_payout' );

		$code_id           = isset( $_POST['code_id'] ) ? absint( $_POST['code_id'] ) : 0;
		$sale_amount       = isset( $_POST['sale_amount'] ) ? (float) $_POST['sale_amount'] : 0;
		$installment_index = isset( $_POST['installment_index'] ) && '' !== $_POST['installment_index'] ? absint( $_POST['installment_index'] ) : null;
		$notes             = isset( $_POST['notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['notes'] ) ) : '';
		$save              = ! empty( $_POST['save'] );

		$code = self::get_code( $code_id );
		if ( ! $code ) {
			wp_die( 'Code not found.' );
		}
		$partner = self::get_partner( $code->partner_id );
		if ( ! $partner ) {
			wp_die( 'Partner not found.' );
		}

		$installment_label = null;

		if ( 'flat' === $partner->payout_type ) {
			$gross = (float) $partner->payout_amount;
		} else {
			$installments = $partner->installments_json ? json_decode( $partner->installments_json, true ) : array();
			if ( $installments && null !== $installment_index && isset( $installments[ $installment_index ] ) ) {
				$fraction          = (float) $installments[ $installment_index ]['fraction'];
				$installment_label = $installments[ $installment_index ]['label'];
				$gross             = $sale_amount * ( (float) $partner->payout_percent / 100 ) * $fraction;
			} else {
				$gross = $sale_amount * ( (float) $partner->payout_percent / 100 );
			}
		}

		if ( 'flat' === $code->cut_type ) {
			$cut = (float) $code->cut_value;
		} else {
			$cut = $gross * ( (float) $code->cut_value / 100 );
		}

		// Buyer cash back comes out of the gross commission, same base as
		// the sub-affiliate cut — the two are independent shares of the
		// same gross, not stacked on top of each other.
		if ( $partner->cashback_type ) {
			$cashback = 'flat' === $partner->cashback_type
				? (float) $partner->cashback_value
				: $gross * ( (float) $partner->cashback_value / 100 );
		} else {
			$cashback = 0.0;
		}

		$net = $gross - $cut - $cashback;

		$result = array(
			'gross'    => $gross,
			'cut'      => $cut,
			'cashback' => $cashback,
			'net'      => $net,
			'saved'    => false,
		);

		if ( $save ) {
			global $wpdb;
			$wpdb->insert(
				GAS_DB::table( 'payouts' ),
				array(
					'code_id'           => $code->id,
					'code'              => $code->code,
					'partner_id'        => $partner->id,
					'sale_amount'       => $sale_amount,
					'installment_label' => $installment_label,
					'gross_commission'  => $gross,
					'subaffiliate_cut'  => $cut,
					'cashback_amount'   => $cashback,
					'net_to_cary'       => $net,
					'entered_at'        => current_time( 'mysql' ),
					'notes'             => $notes,
				)
			);
			$result['saved'] = true;
		}

		set_transient( 'gas_calc_result_' . get_current_user_id(), $result, 60 );

		wp_safe_redirect( admin_url( 'admin.php?page=gas-calculator&result=1' ) );
		exit;
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

		$payouts_table  = GAS_DB::table( 'payouts' );
		$partners_table = GAS_DB::table( 'partners' );

		$rows = $wpdb->get_results(
			"SELECT pay.*, p.name AS partner_name FROM {$payouts_table} pay
			 LEFT JOIN {$partners_table} p ON p.id = pay.partner_id
			 ORDER BY pay.entered_at DESC"
		);

		echo '<p><a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gas_export_ledger_csv' ), 'gas_export_ledger_csv' ) ) . '" class="button">Export CSV</a></p>';

		if ( ! $rows ) {
			echo '<p>No payouts recorded yet. Use the <a href="' . esc_url( admin_url( 'admin.php?page=gas-calculator' ) ) . '">Payout Calculator</a> and save a result to start the ledger.</p>';
		} else {
			$total_gross    = 0;
			$total_cut      = 0;
			$total_cashback = 0;
			$total_net      = 0;
			echo '<table class="widefat striped"><thead><tr><th>Date</th><th>Code</th><th>Partner</th><th>Sale</th><th>Installment</th><th>Gross</th><th>Sub-affiliate cut</th><th>Buyer cash back</th><th>Net to you</th><th>Status</th><th>Notes</th><th></th></tr></thead><tbody>';
			foreach ( $rows as $r ) {
				$total_gross    += (float) $r->gross_commission;
				$total_cut      += (float) $r->subaffiliate_cut;
				$total_cashback += (float) $r->cashback_amount;
				$total_net      += (float) $r->net_to_cary;
				echo '<tr>';
				echo '<td>' . esc_html( $r->entered_at ) . '</td>';
				echo '<td><code>' . esc_html( $r->code ) . '</code></td>';
				echo '<td>' . esc_html( $r->partner_name ?: '(unassigned)' ) . '</td>';
				echo '<td>$' . esc_html( number_format( (float) $r->sale_amount, 2 ) ) . '</td>';
				echo '<td>' . esc_html( $r->installment_label ?: '&mdash;' ) . '</td>';
				echo '<td>$' . esc_html( number_format( (float) $r->gross_commission, 2 ) ) . '</td>';
				echo '<td>$' . esc_html( number_format( (float) $r->subaffiliate_cut, 2 ) ) . '</td>';
				echo '<td>$' . esc_html( number_format( (float) $r->cashback_amount, 2 ) ) . '</td>';
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
			echo '<strong>Total net to you:</strong> $' . esc_html( number_format( $total_net, 2 ) ) . '</p>';
		}

		self::render_automated_payouts_section();

		self::wrap_end();
	}

	private static function render_payout_result_notice() {
		$notice = get_transient( 'gas_payout_result_' . get_current_user_id() );
		if ( ! $notice ) {
			return;
		}
		echo '<div class="notice notice-' . ( $notice['error'] ? 'error' : 'success' ) . '"><p>' . wp_kses_post( $notice['message'] ) . '</p></div>';
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
				),
			);
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
		fputcsv( $out, array( 'Date', 'Code', 'Partner', 'Sale Amount', 'Installment', 'Gross Commission', 'Sub-affiliate Cut', 'Buyer Cash Back', 'Net', 'Status', 'Paid At', 'Notes' ) );
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
				$r->net_to_cary,
				$r->status,
				$r->paid_at ?: '',
				$r->notes,
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

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'gas_save_settings' );
		echo '<input type="hidden" name="action" value="gas_save_settings">';
		echo '<table class="form-table"><tbody>';

		echo '<tr><th><label for="site_name">Program name</label></th><td><input type="text" id="site_name" name="site_name" class="regular-text" required value="' . esc_attr( $settings['site_name'] ) . '"> <p class="description">Shown as the admin menu label and page heading.</p></td></tr>';

		echo '<tr><th><label for="partner_label">Partner label</label></th><td><input type="text" id="partner_label" name="partner_label" class="regular-text" required value="' . esc_attr( $settings['partner_label'] ) . '"> <p class="description">The word used for "partner" in the self-signup form, e.g. "solar partner" or "builder".</p></td></tr>';

		echo '<tr><th><label for="require_partner_at_signup">Require partner at signup</label></th><td><label><input type="checkbox" id="require_partner_at_signup" name="require_partner_at_signup" value="1"' . checked( $settings['require_partner_at_signup'], true, false ) . '> New affiliates must choose a partner when they self-sign-up</label> <p class="description">If unchecked, new affiliates go live immediately unassigned, and an admin matches them to a partner and sets their cut rate afterward from the Codes screen. If checked, the partner\'s default cut rate is applied automatically at signup.</p></td></tr>';

		echo '<tr><th><label for="menu_icon">Admin menu icon</label></th><td><input type="text" id="menu_icon" name="menu_icon" class="regular-text" value="' . esc_attr( $settings['menu_icon'] ) . '"> <p class="description">A <a href="https://developer.wordpress.org/resource/dashicons/" target="_blank" rel="noopener">dashicon</a> slug, e.g. dashicons-groups.</p></td></tr>';

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

		GAS_Settings::update( array(
			'site_name'                 => isset( $_POST['site_name'] ) ? sanitize_text_field( wp_unslash( $_POST['site_name'] ) ) : '',
			'partner_label'             => isset( $_POST['partner_label'] ) ? sanitize_text_field( wp_unslash( $_POST['partner_label'] ) ) : 'partner',
			'require_partner_at_signup' => ! empty( $_POST['require_partner_at_signup'] ),
			'menu_icon'                 => isset( $_POST['menu_icon'] ) ? sanitize_text_field( wp_unslash( $_POST['menu_icon'] ) ) : 'dashicons-groups',
		) );

		wp_safe_redirect( admin_url( 'admin.php?page=gas-settings&saved=1' ) );
		exit;
	}
}
