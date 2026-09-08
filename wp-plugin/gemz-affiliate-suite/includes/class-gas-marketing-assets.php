<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Downloadable marketing collateral for affiliates (2026-09-08) — banners,
 * images, etc. an admin uploads once via WordPress's own native media
 * library (no custom uploader built) and attaches to either a specific
 * partner, a specific campaign, or neither (global — shown to every
 * affiliate regardless of which campaigns they're promoting). The
 * "alternate landing page" half of the original ask already has a home in
 * `gas_campaign_variants` (Part 1, 2026-09-07) — this class is only the
 * creative-asset half.
 */
class GAS_Marketing_Assets {

	public static function init() {
		add_action( 'admin_post_gas_save_marketing_asset', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_gas_delete_marketing_asset', array( __CLASS__, 'handle_delete' ) );
	}

	/**
	 * Every asset relevant to one affiliate: global assets (no partner_id
	 * and no campaign_id set) plus anything scoped to one of the specific
	 * partners/campaigns passed in — dedupes on attachment_id in case an
	 * asset is somehow reachable both ways in the same call.
	 */
	public static function get_for_affiliate( array $partner_ids, array $campaign_ids ) {
		global $wpdb;
		$table = GAS_DB::table( 'marketing_assets' );

		$where = array( "(partner_id IS NULL AND campaign_id IS NULL)" );
		$args  = array();

		if ( $partner_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $partner_ids ), '%d' ) );
			$where[] = "partner_id IN ({$placeholders})";
			$args    = array_merge( $args, $partner_ids );
		}
		if ( $campaign_ids ) {
			$placeholders = implode( ',', array_fill( 0, count( $campaign_ids ), '%d' ) );
			$where[] = "campaign_id IN ({$placeholders})";
			$args    = array_merge( $args, $campaign_ids );
		}

		$sql = "SELECT * FROM {$table} WHERE " . implode( ' OR ', $where ) . " ORDER BY created_at DESC";
		if ( $args ) {
			$sql = $wpdb->prepare( $sql, $args );
		}
		return $wpdb->get_results( $sql );
	}

	public static function get_all() {
		global $wpdb;
		$table           = GAS_DB::table( 'marketing_assets' );
		$partners_table  = GAS_DB::table( 'partners' );
		$campaigns_table = GAS_DB::table( 'campaigns' );
		return $wpdb->get_results(
			"SELECT a.*, p.name AS partner_name, c.name AS campaign_name
			 FROM {$table} a
			 LEFT JOIN {$partners_table} p ON p.id = a.partner_id
			 LEFT JOIN {$campaigns_table} c ON c.id = a.campaign_id
			 ORDER BY a.created_at DESC"
		);
	}

	public static function handle_save() {
		if ( ! current_user_can( GAS_Admin::CAP_CAMPAIGNS ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'gas_save_marketing_asset' );

		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;
		$title         = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
		$partner_id    = isset( $_POST['partner_id'] ) ? absint( $_POST['partner_id'] ) : 0;
		$campaign_id   = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;

		if ( ! $attachment_id || '' === $title ) {
			wp_die( 'An image and a title are both required.' );
		}

		global $wpdb;
		$wpdb->insert( GAS_DB::table( 'marketing_assets' ), array(
			'attachment_id' => $attachment_id,
			'title'         => $title,
			'partner_id'    => $partner_id ?: null,
			'campaign_id'   => $campaign_id ?: null,
			'created_at'    => current_time( 'mysql' ),
		) );

		GAS_Admin::audit_log( 'marketing_asset', $wpdb->insert_id, 'created', array( 'title' => $title ) );
		wp_safe_redirect( admin_url( 'admin.php?page=gas-marketing-assets&saved=1' ) );
		exit;
	}

	public static function handle_delete() {
		if ( ! current_user_can( GAS_Admin::CAP_CAMPAIGNS ) ) {
			wp_die( 'Not allowed.' );
		}
		$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		check_admin_referer( 'gas_delete_marketing_asset_' . $id );

		global $wpdb;
		$wpdb->delete( GAS_DB::table( 'marketing_assets' ), array( 'id' => $id ) );
		GAS_Admin::audit_log( 'marketing_asset', $id, 'deleted' );

		wp_safe_redirect( admin_url( 'admin.php?page=gas-marketing-assets&deleted=1' ) );
		exit;
	}
}
