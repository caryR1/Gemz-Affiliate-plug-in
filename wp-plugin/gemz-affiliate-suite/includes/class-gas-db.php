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
			KEY tier3_code_id (tier3_code_id)
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
			landing_page_id BIGINT UNSIGNED NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			is_default TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY tracking_slug (tracking_slug),
			KEY partner_id (partner_id)
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
		) {$charset_collate};";

		dbDelta( $sql );
	}
}
