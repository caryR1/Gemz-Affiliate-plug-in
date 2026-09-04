<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal authenticated REST surface for the plugin's own data, gated
 * behind manage_options. Exists because Application Password auth on
 * these sites only works against /wp-json/, not normal wp-admin page
 * loads, so admin tooling needs a REST path to manage plugin data
 * programmatically (e.g. from Claude Code) rather than only via the
 * wp-admin screens.
 */
class GAS_REST {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function permission_check() {
		return current_user_can( 'manage_options' );
	}

	public static function register_routes() {
		register_rest_route( 'gas/v1', '/partners', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'list_partners' ),
			'permission_callback' => array( __CLASS__, 'permission_check' ),
		) );

		register_rest_route( 'gas/v1', '/partners/(?P<id>\d+)', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'update_partner' ),
			'permission_callback' => array( __CLASS__, 'permission_check' ),
			'args'                => array(
				'id' => array( 'validate_callback' => function( $param ) { return is_numeric( $param ); } ),
			),
		) );

		register_rest_route( 'gas/v1', '/codes', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'list_codes' ),
			'permission_callback' => array( __CLASS__, 'permission_check' ),
		) );

		register_rest_route( 'gas/v1', '/affiliates', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'list_affiliates' ),
			'permission_callback' => array( __CLASS__, 'permission_check' ),
		) );
	}

	public static function list_partners() {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT * FROM ' . GAS_DB::table( 'partners' ) . ' ORDER BY name ASC', ARRAY_A );
		foreach ( $rows as &$r ) {
			$r['installments'] = $r['installments_json'] ? json_decode( $r['installments_json'], true ) : array();
			unset( $r['installments_json'] );
		}
		return new WP_REST_Response( $rows, 200 );
	}

	public static function update_partner( WP_REST_Request $request ) {
		global $wpdb;
		$id    = absint( $request['id'] );
		$table = GAS_DB::table( 'partners' );

		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $id ) );
		if ( ! $existing ) {
			return new WP_Error( 'gas_not_found', 'Partner not found.', array( 'status' => 404 ) );
		}

		$data       = array();
		$body       = $request->get_json_params();
		$allowed    = array( 'name', 'payout_type', 'payout_amount', 'payout_percent', 'default_cut_type', 'default_cut_value', 'destination_url', 'notes' );
		$formats    = array();
		$format_map = array(
			'name'              => '%s',
			'payout_type'       => '%s',
			'payout_amount'     => '%f',
			'payout_percent'    => '%f',
			'default_cut_type'  => '%s',
			'default_cut_value' => '%f',
			'destination_url'   => '%s',
			'notes'             => '%s',
		);

		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $body ) ) {
				$value = $body[ $field ];
				if ( 'destination_url' === $field ) {
					$value = esc_url_raw( $value );
				} elseif ( 'notes' === $field || 'name' === $field ) {
					$value = sanitize_text_field( $value );
				} elseif ( in_array( $field, array( 'payout_type', 'default_cut_type' ), true ) ) {
					$value = in_array( $value, array( 'flat', 'percent' ), true ) ? $value : 'percent';
				} else {
					$value = (float) $value;
				}
				$data[ $field ] = $value;
				$formats[]      = $format_map[ $field ];
			}
		}

		if ( empty( $data ) ) {
			return new WP_Error( 'gas_no_fields', 'No recognized fields to update.', array( 'status' => 400 ) );
		}

		$wpdb->update( $table, $data, array( 'id' => $id ), $formats, array( '%d' ) );

		$updated = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return new WP_REST_Response( $updated, 200 );
	}

	public static function list_codes() {
		global $wpdb;
		$codes_table    = GAS_DB::table( 'codes' );
		$partners_table = GAS_DB::table( 'partners' );
		$rows = $wpdb->get_results(
			"SELECT c.*, p.name AS partner_name FROM {$codes_table} c
			 LEFT JOIN {$partners_table} p ON p.id = c.partner_id
			 ORDER BY c.created_at DESC",
			ARRAY_A
		);
		return new WP_REST_Response( $rows, 200 );
	}

	public static function list_affiliates() {
		global $wpdb;
		$codes_table    = GAS_DB::table( 'codes' );
		$partners_table = GAS_DB::table( 'partners' );
		$rows = $wpdb->get_results(
			"SELECT c.*, p.name AS partner_name FROM {$codes_table} c
			 LEFT JOIN {$partners_table} p ON p.id = c.partner_id
			 WHERE c.wp_user_id IS NOT NULL
			 ORDER BY c.created_at DESC",
			ARRAY_A
		);
		foreach ( $rows as &$r ) {
			$user                = get_userdata( $r['wp_user_id'] );
			$r['email']          = $user ? $user->user_email : null;
			// Masked summary only — never expose a full account number/IBAN/email
			// over the REST API, same as the admin screens.
			$r['payout_summary'] = $r['wp_user_id'] ? GAS_Payouts::masked_summary( $r['wp_user_id'] ) : '';
		}
		return new WP_REST_Response( $rows, 200 );
	}
}
