<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GAS_DB {

	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'gas_' . $name;
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

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset_collate = $wpdb->get_charset_collate();

		$partners = self::table( 'partners' );
		$codes    = self::table( 'codes' );
		$clicks   = self::table( 'clicks' );
		$payouts  = self::table( 'payouts' );
		$leads    = self::table( 'leads' );

		$sql = "CREATE TABLE {$partners} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slug VARCHAR(64) NOT NULL,
			name VARCHAR(191) NOT NULL,
			payout_type VARCHAR(20) NOT NULL DEFAULT 'flat',
			payout_amount DECIMAL(10,2) NULL,
			payout_percent DECIMAL(5,2) NULL,
			installments_json TEXT NULL,
			default_cut_type VARCHAR(20) NOT NULL DEFAULT 'percent',
			default_cut_value DECIMAL(10,2) NOT NULL DEFAULT 0,
			cashback_type VARCHAR(20) NULL,
			cashback_value DECIMAL(10,2) NOT NULL DEFAULT 0,
			fulfillment_mode VARCHAR(20) NOT NULL DEFAULT 'redirect',
			requires_appointment TINYINT(1) NOT NULL DEFAULT 1,
			destination_url VARCHAR(500) NULL,
			email VARCHAR(191) NULL,
			user_id BIGINT UNSIGNED NULL,
			notes TEXT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY user_id (user_id)
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
			code_id BIGINT UNSIGNED NOT NULL,
			code VARCHAR(64) NOT NULL,
			partner_id BIGINT UNSIGNED NULL,
			clicked_at DATETIME NOT NULL,
			ip_address VARCHAR(45) NULL,
			user_agent VARCHAR(255) NULL,
			visitor_hash VARCHAR(64) NULL,
			PRIMARY KEY  (id),
			KEY code_id (code_id),
			KEY clicked_at (clicked_at),
			KEY dedup (code_id, visitor_hash, clicked_at)
		) {$charset_collate};

		CREATE TABLE {$payouts} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			code_id BIGINT UNSIGNED NOT NULL,
			code VARCHAR(64) NOT NULL,
			partner_id BIGINT UNSIGNED NOT NULL,
			sale_amount DECIMAL(10,2) NOT NULL,
			installment_label VARCHAR(191) NULL,
			gross_commission DECIMAL(10,2) NOT NULL,
			subaffiliate_cut DECIMAL(10,2) NOT NULL,
			net_to_cary DECIMAL(10,2) NOT NULL,
			cashback_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
			cashback_paid TINYINT(1) NOT NULL DEFAULT 0,
			cashback_paid_at DATETIME NULL,
			tier2_code_id BIGINT UNSIGNED NULL,
			tier2_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
			tier2_paid TINYINT(1) NOT NULL DEFAULT 0,
			tier3_code_id BIGINT UNSIGNED NULL,
			tier3_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
			tier3_paid TINYINT(1) NOT NULL DEFAULT 0,
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
			customer_name VARCHAR(191) NOT NULL,
			customer_email VARCHAR(191) NULL,
			customer_phone VARCHAR(64) NULL,
			appointment_at DATETIME NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'new',
			notes TEXT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY partner_id (partner_id),
			KEY code_id (code_id),
			KEY status (status)
		) {$charset_collate};";

		dbDelta( $sql );
	}
}
