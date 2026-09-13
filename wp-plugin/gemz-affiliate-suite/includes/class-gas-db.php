<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GAS_DB {

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'gas_' . $name;
	}

	/**
	 * The fixed, curated list of partner capability tags an admin can tick
	 * on the Partners screen — rendered as small icons (WordPress core
	 * Dashicons, so no new asset dependency) on each of an affiliate's
	 * link cards. Deliberately spans both current verticals (solar +
	 * home-building) rather than forcing generic-sounding wording, since
	 * most partners will only ever have a handful ticked. Approved list,
	 * Cary 2026-09-07 — see SWAP-with-HOMES.md. "Appointment required" and
	 * coverage/location are intentionally NOT here: they're already their
	 * own structured fields (`requires_appointment`, `state`) and are
	 * rendered from those directly instead of being duplicated as a tag.
	 */
	public static function capability_tags() {
		return array(
			'ships_nationwide'          => array( 'label' => 'Ships nationwide', 'icon' => 'dashicons-admin-site-alt3' ),
			'full_service'              => array( 'label' => 'Full-service / turnkey (vs. plans/kit only)', 'icon' => 'dashicons-hammer' ),
			'custom_bespoke'            => array( 'label' => 'Custom / bespoke builds', 'icon' => 'dashicons-art' ),
			'adu_permanent_foundation'  => array( 'label' => 'ADU / permanent-foundation specialist', 'icon' => 'dashicons-admin-home' ),
			'solar_ready_off_grid'      => array( 'label' => 'Solar-ready / off-grid capable', 'icon' => 'dashicons-lightbulb' ),
			'financing_available'       => array( 'label' => 'Financing available', 'icon' => 'dashicons-money-alt' ),
			'battery_storage_available' => array( 'label' => 'Battery storage available', 'icon' => 'dashicons-database' ),
			'ev_charger_installation'   => array( 'label' => 'EV charger installation', 'icon' => 'dashicons-car' ),
			'roof_replacement_bundled'  => array( 'label' => 'Roof replacement bundled', 'icon' => 'dashicons-admin-multisite' ),
			'free_energy_audit'         => array( 'label' => 'Free energy audit / site assessment', 'icon' => 'dashicons-search' ),
			'warranty_guarantee'        => array( 'label' => 'Warranty / guarantee available', 'icon' => 'dashicons-shield' ),
		);
	}

	/**
	 * The 50 states + DC — added 2026-09-10 per Cary's direct request:
	 * "wherever a state is required, it should be a dropdown," not free
	 * text (was a real gap: e.g. the Get-a-Quote form's address field had
	 * no separate state at all, so partner coverage-matching couldn't use
	 * it for leads from that form — flagged earlier the same day). One
	 * shared list so every state field site-wide (referral forms, partner
	 * coverage-area fields, etc.) stays consistent rather than each
	 * screen inventing its own.
	 */
	public static function us_states() {
		return array(
			'AL' => 'Alabama', 'AK' => 'Alaska', 'AZ' => 'Arizona', 'AR' => 'Arkansas',
			'CA' => 'California', 'CO' => 'Colorado', 'CT' => 'Connecticut', 'DE' => 'Delaware',
			'DC' => 'District of Columbia', 'FL' => 'Florida', 'GA' => 'Georgia', 'HI' => 'Hawaii',
			'ID' => 'Idaho', 'IL' => 'Illinois', 'IN' => 'Indiana', 'IA' => 'Iowa',
			'KS' => 'Kansas', 'KY' => 'Kentucky', 'LA' => 'Louisiana', 'ME' => 'Maine',
			'MD' => 'Maryland', 'MA' => 'Massachusetts', 'MI' => 'Michigan', 'MN' => 'Minnesota',
			'MS' => 'Mississippi', 'MO' => 'Missouri', 'MT' => 'Montana', 'NE' => 'Nebraska',
			'NV' => 'Nevada', 'NH' => 'New Hampshire', 'NJ' => 'New Jersey', 'NM' => 'New Mexico',
			'NY' => 'New York', 'NC' => 'North Carolina', 'ND' => 'North Dakota', 'OH' => 'Ohio',
			'OK' => 'Oklahoma', 'OR' => 'Oregon', 'PA' => 'Pennsylvania', 'RI' => 'Rhode Island',
			'SC' => 'South Carolina', 'SD' => 'South Dakota', 'TN' => 'Tennessee', 'TX' => 'Texas',
			'UT' => 'Utah', 'VT' => 'Vermont', 'VA' => 'Virginia', 'WA' => 'Washington',
			'WV' => 'West Virginia', 'WI' => 'Wisconsin', 'WY' => 'Wyoming',
		);
	}

	/**
	 * The `<option>` tags for a US-state `<select>`, shared by every state
	 * field site-wide — pass the currently-selected abbreviation (blank
	 * for none). Renders "XX — Full Name" so an abbreviation-only value
	 * is never ambiguous in the list, per Cary's own spec ("state
	 * initial, state name in the dropdown").
	 */
	public static function state_dropdown_options( $selected = '' ) {
		$html = '<option value="">— Select a state —</option>';
		foreach ( self::us_states() as $abbr => $full_name ) {
			$html .= '<option value="' . esc_attr( $abbr ) . '"' . selected( $selected, $abbr, false ) . '>' . esc_html( $abbr . ' — ' . $full_name ) . '</option>';
		}
		return $html;
	}

	public static function activate() {
		self::create_tables();
		GAS_Roles::add_role();
		update_option( 'gas_db_version', GAS_DB_VERSION );

		// Rewrite rule needs to exist before we flush.
		GAS_Redirect::add_rewrite_rule();
		flush_rewrite_rules();
	}

	public static function maybe_upgrade() {
		if ( get_option( 'gas_db_version' ) !== GAS_DB_VERSION ) {
			self::create_tables();
			GAS_Roles::add_role();
			GAS_Campaigns::migrate_partner_privacy();
			self::migrate_partner_notes_to_log();
			update_option( 'gas_db_version', GAS_DB_VERSION );
		}
	}

	/**
	 * Note on partners.state: widened from VARCHAR(2) to VARCHAR(100) to
	 * hold one or more comma-separated 2-letter codes (e.g. "FL,TX,GA,CA")
	 * — the first real partner needed to cover 4 states, not just one.
	 * See GAS_Frontend::partner_covers_state() for how this is matched
	 * against a referred customer's state.
	 *
	 * Note on campaigns/campaign_variants (2026-09-08): ported from
	 * gemz-referral-crm's (GRC) architecture at Cary's explicit request —
	 * replaces the previous day's "one code row per open partner" model
	 * with GRC's proven one: a single stable per-affiliate code (see
	 * GAS_Frontend::get_or_create_code_for_user()) plus a separate,
	 * admin-managed `campaigns` table keyed by a URL tracking_slug. A
	 * link is now `{code}` + `{tracking_slug}` combined at share time
	 * (see GAS_Campaigns::build_link()), not a DB row per affiliate —
	 * any active affiliate's code works with any active campaign's slug,
	 * exactly like GRC's agents/campaigns relationship (no assignment
	 * table between the two). One deliberate addition over GRC:
	 * `is_default` marks the one auto-created campaign per partner (see
	 * GAS_Campaigns::ensure_default_for_partner()) so self-signup can
	 * still hand a new affiliate a working link immediately, without an
	 * admin having to build a campaign by hand first — GRC has no
	 * equivalent auto-provisioning, since campaign creation there is
	 * always a manual admin step.
	 *
	 * Note on leads.consent_* (2026-09-10): TCPA compliance — before a
	 * partner calls or texts a lead, we need proof THAT lead personally
	 * agreed to it. `consent_call_text`/`consent_text`/`consent_at`/
	 * `consent_ip` are only ever set by GAS_Leads::handle_submit(), i.e.
	 * a customer directly filling out the on-site Get a Quote form
	 * themselves — see GAS_Leads::consent_label() for the exact
	 * disclosure they check and consent_text stores. A lead created by
	 * GAS_Frontend::create_referral_lead() (the "refer a friend" path)
	 * deliberately leaves these at their defaults: the affiliate
	 * submitting a friend's phone number is not that friend, and cannot
	 * consent on their behalf — see the comment there.
	 *
	 * Note on partners.partner_alias / campaigns.previous_slug
	 * (2026-09-10): Homes caught (with Cary) that a real fulfillment
	 * partner's name was leaking to affiliates — worst of all, baked
	 * directly into the shareable tracking link itself
	 * (ensure_default_for_partner() used to build tracking_slug from
	 * $partner->name). partner_alias is the real name's stand-in shown
	 * anywhere an affiliate can see it; it's NEVER blank (see
	 * GAS_Campaigns::ensure_partner_alias(), which backfills a safe
	 * "{partner_label} #{id}" placeholder the moment a partner exists, no
	 * gap where the real name could show through). The harder problem —
	 * real affiliates on both sites already have real-name-slug links
	 * live — is why previous_slug exists: GAS_Campaigns::migrate_partner_privacy()
	 * (run once per campaign, from maybe_upgrade()) moves each
	 * name-derived tracking_slug into previous_slug and generates a fresh
	 * alias-based one, and GAS_Campaigns::get_by_slug() checks both
	 * columns — so a link already out in the world keeps working, while
	 * every new link affiliates copy from their dashboard uses the
	 * alias-based slug instead. Deliberately NOT a new campaign row per
	 * partner (would have orphaned that campaign's click history and any
	 * marketing-asset scoping tied to its campaign_id) — same row, same
	 * id, just a different slug.
	 */
	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();

		$partners          = self::table( 'partners' );
		$codes             = self::table( 'codes' );
		$clicks            = self::table( 'clicks' );
		$payouts           = self::table( 'payouts' );
		$leads             = self::table( 'leads' );
		$audit_log         = self::table( 'audit_log' );
		$contacts          = self::table( 'contacts' );
		$lead_magnets      = self::table( 'lead_magnets' );
		$campaigns         = self::table( 'campaigns' );
		$campaign_variants = self::table( 'campaign_variants' );
		$marketing_assets  = self::table( 'marketing_assets' );
		$partner_notes     = self::table( 'partner_notes' );

		$sql = "CREATE TABLE {$partners} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slug VARCHAR(64) NOT NULL,
			name VARCHAR(191) NOT NULL,
			payout_type VARCHAR(20) NOT NULL DEFAULT 'flat',
			payout_amount DECIMAL(10,2) NULL,
			payout_percent DECIMAL(5,2) NULL,
			agent_pool_type VARCHAR(20) NOT NULL DEFAULT 'percent',
			agent_pool_value DECIMAL(10,2) NOT NULL DEFAULT 100,
			installments_json TEXT NULL,
			default_cut_type VARCHAR(20) NOT NULL DEFAULT 'percent',
			default_cut_value DECIMAL(10,2) NOT NULL DEFAULT 0,
			cashback_type VARCHAR(20) NULL,
			cashback_value DECIMAL(10,2) NOT NULL DEFAULT 0,
			fulfillment_mode VARCHAR(20) NOT NULL DEFAULT 'lead_capture',
			requires_appointment TINYINT(1) NOT NULL DEFAULT 0,
			lead_page_intro TEXT NULL,
			lead_page_image_id BIGINT UNSIGNED NULL,
			destination_url VARCHAR(500) NULL,
			email VARCHAR(191) NULL,
			user_id BIGINT UNSIGNED NULL,
			service_area_description VARCHAR(500) NULL,
			state VARCHAR(100) NULL,
			city VARCHAR(100) NULL,
			zip VARCHAR(10) NULL,
			source_url VARCHAR(500) NULL,
			discovered_via VARCHAR(20) NOT NULL DEFAULT 'manual',
			outreach_status VARCHAR(20) NOT NULL DEFAULT 'approved',
			research_batch_id VARCHAR(40) NULL,
			typical_sale_amount DECIMAL(10,2) NULL,
			open_to_self_signup TINYINT(1) NOT NULL DEFAULT 1,
			blurb VARCHAR(500) NULL,
			spotlight_url VARCHAR(500) NULL,
			capability_tags VARCHAR(500) NULL,
			partner_alias VARCHAR(191) NULL,
			notes TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY user_id (user_id),
			KEY outreach_status (outreach_status),
			KEY state (state)
		) {$charset_collate};

		CREATE TABLE {$codes} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code VARCHAR(64) NOT NULL,
			sub_affiliate_name VARCHAR(191) NOT NULL,
			partner_id BIGINT UNSIGNED NOT NULL,
			wp_user_id BIGINT UNSIGNED NULL,
			sponsor_code_id BIGINT UNSIGNED NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			cut_type VARCHAR(20) NOT NULL DEFAULT 'percent',
			cut_value DECIMAL(10,2) NOT NULL DEFAULT 0,
			active TINYINT(1) NOT NULL DEFAULT 1,
			notes TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY code (code),
			KEY partner_id (partner_id),
			KEY wp_user_id (wp_user_id),
			KEY sponsor_code_id (sponsor_code_id)
		) {$charset_collate};

		CREATE TABLE {$clicks} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			code VARCHAR(64) NOT NULL DEFAULT '',
			partner_id BIGINT UNSIGNED NULL,
			campaign_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			clicked_at DATETIME NOT NULL,
			ip_address VARCHAR(45) NULL,
			user_agent VARCHAR(255) NULL,
			visitor_hash VARCHAR(64) NULL,
			PRIMARY KEY  (id),
			KEY code_id (code_id),
			KEY campaign_id (campaign_id),
			KEY clicked_at (clicked_at),
			KEY dedup (campaign_id, visitor_hash, clicked_at)
		) {$charset_collate};

		CREATE TABLE {$payouts} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code_id BIGINT UNSIGNED NOT NULL,
			code VARCHAR(64) NOT NULL,
			partner_id BIGINT UNSIGNED NOT NULL,
			sale_amount DECIMAL(10,2) NOT NULL,
			installment_label VARCHAR(191) NULL,
			gross_commission DECIMAL(10,2) NOT NULL,
			agent_pool_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
			subaffiliate_cut DECIMAL(10,2) NOT NULL,
			net_to_cary DECIMAL(10,2) NOT NULL,
			cashback_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
			cashback_paid TINYINT(1) NOT NULL DEFAULT 0,
			cashback_paid_at DATETIME NULL,
			customer_email VARCHAR(191) NULL,
			cashback_claimed_at DATETIME NULL,
			cashback_payment_details TEXT NULL,
			tier2_code_id BIGINT UNSIGNED NULL,
			tier2_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
			tier2_paid TINYINT(1) NOT NULL DEFAULT 0,
			tier2_paid_at DATETIME NULL,
			tier3_code_id BIGINT UNSIGNED NULL,
			tier3_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
			tier3_paid TINYINT(1) NOT NULL DEFAULT 0,
			tier3_paid_at DATETIME NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'unpaid',
			paid_at DATETIME NULL,
			entered_at DATETIME NOT NULL,
			notes TEXT NULL,
			PRIMARY KEY  (id),
			KEY code_id (code_id),
			KEY status (status),
			KEY tier2_code_id (tier2_code_id),
			KEY tier3_code_id (tier3_code_id),
			KEY customer_email (customer_email)
		) {$charset_collate};

		CREATE TABLE {$leads} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			partner_id BIGINT UNSIGNED NOT NULL,
			code_id BIGINT UNSIGNED NULL,
			campaign_id BIGINT UNSIGNED NULL,
			customer_name VARCHAR(191) NOT NULL,
			customer_email VARCHAR(191) NULL,
			customer_phone VARCHAR(64) NULL,
			customer_address VARCHAR(255) NULL,
			customer_state VARCHAR(2) NULL,
			appointment_at DATETIME NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			notes TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			consent_call_text TINYINT(1) NOT NULL DEFAULT 0,
			consent_text TEXT NULL,
			consent_at DATETIME NULL,
			consent_ip VARCHAR(45) NULL,
			PRIMARY KEY  (id),
			KEY partner_id (partner_id),
			KEY code_id (code_id),
			KEY campaign_id (campaign_id),
			KEY status (status)
		) {$charset_collate};

		CREATE TABLE {$audit_log} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NULL,
			object_type VARCHAR(20) NOT NULL,
			object_id BIGINT UNSIGNED NULL,
			action VARCHAR(60) NOT NULL,
			details TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY object_type_id (object_type, object_id),
			KEY created_at (created_at)
		) {$charset_collate};

		CREATE TABLE {$contacts} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			contact_type VARCHAR(20) NOT NULL,
			name VARCHAR(191) NULL,
			email VARCHAR(191) NOT NULL,
			phone VARCHAR(64) NULL,
			source VARCHAR(30) NOT NULL DEFAULT 'manual',
			related_table VARCHAR(20) NULL,
			related_id BIGINT UNSIGNED NULL,
			subscribed TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			KEY contact_type (contact_type)
		) {$charset_collate};

		CREATE TABLE {$lead_magnets} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(191) NOT NULL,
			description TEXT NULL,
			attachment_id BIGINT UNSIGNED NOT NULL,
			download_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
			active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id)
		) {$charset_collate};

		CREATE TABLE {$campaigns} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(200) NOT NULL,
			partner_id BIGINT UNSIGNED NOT NULL,
			tracking_slug VARCHAR(60) NOT NULL,
			previous_slug VARCHAR(60) NULL,
			landing_page_id BIGINT UNSIGNED NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			is_default TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY tracking_slug (tracking_slug),
			KEY partner_id (partner_id),
			KEY previous_slug (previous_slug)
		) {$charset_collate};

		CREATE TABLE {$campaign_variants} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_id BIGINT UNSIGNED NOT NULL,
			landing_page_id BIGINT UNSIGNED NOT NULL,
			variant_name VARCHAR(100) NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY campaign_id (campaign_id)
		) {$charset_collate};

		CREATE TABLE {$marketing_assets} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT UNSIGNED NOT NULL,
			title VARCHAR(191) NOT NULL,
			partner_id BIGINT UNSIGNED NULL,
			campaign_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY partner_id (partner_id),
			KEY campaign_id (campaign_id)
		) {$charset_collate};

		CREATE TABLE {$partner_notes} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			partner_id BIGINT UNSIGNED NOT NULL,
			note TEXT NOT NULL,
			created_by BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY partner_id (partner_id)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * One-time migration (2026-09-12): partners.notes was a single flat
	 * field that got overwritten every time the partner was re-saved, so
	 * anything written there before was silently lost the next time
	 * someone edited the partner. Replaced with the partner_notes table
	 * (a real running history, newest first, each entry timestamped and
	 * attributed to whoever added it) — the admin UI's Notes field is now
	 * that history plus a small "add a note" box, not an editable
	 * textarea. This runs once per site: for any partner whose old flat
	 * `notes` column still has text AND has no rows in partner_notes yet,
	 * carries that old text over as the first historical entry (dated to
	 * when the partner was created, since we don't know when the note
	 * itself was actually written) rather than silently dropping it.
	 * Safe to run multiple times — the "no rows yet" check makes it a
	 * no-op after the first run.
	 */
	public static function migrate_partner_notes_to_log() {
		global $wpdb;
		$partners_table = self::table( 'partners' );
		$notes_table    = self::table( 'partner_notes' );

		$partners = $wpdb->get_results( "SELECT id, notes, created_at FROM {$partners_table} WHERE notes IS NOT NULL AND notes != ''" );
		foreach ( $partners as $p ) {
			$existing = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$notes_table} WHERE partner_id = %d", $p->id ) );
			if ( $existing ) {
				continue;
			}
			$wpdb->insert(
				$notes_table,
				array(
					'partner_id' => $p->id,
					'note'       => $p->notes,
					'created_by' => null,
					'created_at' => $p->created_at ? $p->created_at : current_time( 'mysql' ),
				)
			);
		}
	}
}
