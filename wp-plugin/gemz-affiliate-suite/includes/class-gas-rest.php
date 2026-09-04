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

		register_rest_route( 'gas/v1', '/partners', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'create_partner' ),
			'permission_callback' => array( __CLASS__, 'permission_check' ),
		) );

		register_rest_route( 'gas/v1', '/settings', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'get_settings' ),
			'permission_callback' => array( __CLASS__, 'permission_check' ),
		) );

		register_rest_route( 'gas/v1', '/settings', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'update_settings' ),
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

		register_rest_route( 'gas/v1', '/leads', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'list_leads' ),
			'permission_callback' => array( __CLASS__, 'permission_check' ),
		) );

		// WordPress caches rewrite rules; a new URL pattern (e.g. adding
		// /join/{code}) needs a flush before it resolves, same as it would
		// after saving Permalinks in wp-admin. Exists so that can happen
		// over the app-password REST auth too, without a wp-admin session.
		register_rest_route( 'gas/v1', '/flush-rewrite-rules', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'flush_rewrite_rules' ),
			'permission_callback' => array( __CLASS__, 'permission_check' ),
		) );
	}

	public static function flush_rewrite_rules() {
		flush_rewrite_rules();
		return new WP_REST_Response( array( 'flushed' => true ), 200 );
	}

	public static function list_leads() {
		global $wpdb;
		$leads_table    = GAS_DB::table( 'leads' );
		$partners_table = GAS_DB::table( 'partners' );
		$rows = $wpdb->get_results(
			"SELECT l.*, p.name AS partner_name FROM {$leads_table} l
			 LEFT JOIN {$partners_table} p ON p.id = l.partner_id
			 ORDER BY l.created_at DESC",
			ARRAY_A
		);
		return new WP_REST_Response( $rows, 200 );
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

	public static function create_partner( WP_REST_Request $request ) {
		global $wpdb;
		$table = GAS_DB::table( 'partners' );
		$body  = $request->get_json_params();

		$name = isset( $body['name'] ) ? sanitize_text_field( $body['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error( 'gas_missing_name', 'Partner name is required.', array( 'status' => 400 ) );
		}

		$slug = sanitize_title( $name );
		$base = $slug;
		$i    = 0;
		while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s", $slug ) ) ) {
			$i++;
			$slug = $base . '-' . $i;
		}

		$payout_type      = isset( $body['payout_type'] ) && in_array( $body['payout_type'], array( 'flat', 'percent' ), true ) ? $body['payout_type'] : 'flat';
		$cashback_type    = isset( $body['cashback_type'] ) && in_array( $body['cashback_type'], array( 'flat', 'percent' ), true ) ? $body['cashback_type'] : null;
		$fulfillment_mode = isset( $body['fulfillment_mode'] ) && 'lead_capture' === $body['fulfillment_mode'] ? 'lead_capture' : 'redirect';

		$wpdb->insert(
			$table,
			array(
				'slug'                 => $slug,
				'name'                 => $name,
				'payout_type'          => $payout_type,
				'payout_amount'        => isset( $body['payout_amount'] ) ? (float) $body['payout_amount'] : null,
				'payout_percent'       => isset( $body['payout_percent'] ) ? (float) $body['payout_percent'] : null,
				'installments_json'    => ! empty( $body['installments'] ) ? wp_json_encode( $body['installments'] ) : null,
				'cashback_type'        => $cashback_type,
				'cashback_value'       => isset( $body['cashback_value'] ) ? (float) $body['cashback_value'] : 0,
				'fulfillment_mode'     => $fulfillment_mode,
				'requires_appointment' => array_key_exists( 'requires_appointment', $body ) ? (int) (bool) $body['requires_appointment'] : 1,
				'destination_url'      => isset( $body['destination_url'] ) ? esc_url_raw( $body['destination_url'] ) : '',
				'email'                => isset( $body['email'] ) ? sanitize_email( $body['email'] ) : '',
				'notes'                => isset( $body['notes'] ) ? sanitize_text_field( $body['notes'] ) : '',
				'created_at'           => current_time( 'mysql' ),
			)
		);

		GAS_Roles::provision_partner_account( $wpdb->insert_id );

		$created = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $wpdb->insert_id ), ARRAY_A );
		return new WP_REST_Response( $created, 201 );
	}

	public static function get_settings() {
		$settings                      = GAS_Settings::all();
		$settings['signup_page_id']    = (int) get_option( 'gas_signup_page_id' );
		$settings['dashboard_page_id'] = (int) get_option( 'gas_dashboard_page_id' );
		return new WP_REST_Response( $settings, 200 );
	}

	public static function update_settings( WP_REST_Request $request ) {
		$body = $request->get_json_params();

		// signup_page_id / dashboard_page_id are separate raw options (not
		// part of the gas_settings array) — used to point GAS_Frontend's
		// signup_url()/dashboard_url() at a specific existing page, e.g.
		// when cutting a site over onto this plugin without losing pages
		// that already have nav menu items pointing at their post ID.
		if ( array_key_exists( 'signup_page_id', $body ) ) {
			update_option( 'gas_signup_page_id', absint( $body['signup_page_id'] ) );
		}
		if ( array_key_exists( 'dashboard_page_id', $body ) ) {
			update_option( 'gas_dashboard_page_id', absint( $body['dashboard_page_id'] ) );
		}

		$allowed  = array( 'site_name', 'partner_label', 'menu_icon', 'tier1_split_percent', 'tier2_split_percent', 'tier3_split_percent' );
		$numeric  = array( 'tier1_split_percent', 'tier2_split_percent', 'tier3_split_percent' );

		$values = array();
		foreach ( $allowed as $field ) {
			if ( ! array_key_exists( $field, $body ) ) {
				continue;
			}
			if ( in_array( $field, $numeric, true ) ) {
				$values[ $field ] = (float) $body[ $field ];
			} else {
				$values[ $field ] = sanitize_text_field( $body[ $field ] );
			}
		}

		if ( empty( $values ) && ! array_key_exists( 'signup_page_id', $body ) && ! array_key_exists( 'dashboard_page_id', $body ) ) {
			return new WP_Error( 'gas_no_fields', 'No recognized settings fields to update.', array( 'status' => 400 ) );
		}

		if ( empty( $values ) ) {
			return new WP_REST_Response( self::get_settings()->get_data(), 200 );
		}

		GAS_Settings::update( $values );
		return self::get_settings();
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
		$allowed    = array( 'name', 'payout_type', 'payout_amount', 'payout_percent', 'cashback_type', 'cashback_value', 'fulfillment_mode', 'requires_appointment', 'destination_url', 'email', 'notes' );
		$formats    = array();
		$format_map = array(
			'name'                 => '%s',
			'payout_type'          => '%s',
			'payout_amount'        => '%f',
			'payout_percent'       => '%f',
			'cashback_type'        => '%s',
			'cashback_value'       => '%f',
			'fulfillment_mode'     => '%s',
			'requires_appointment' => '%d',
			'destination_url'      => '%s',
			'email'                => '%s',
			'notes'                => '%s',
		);

		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $body ) ) {
				$value = $body[ $field ];
				if ( 'destination_url' === $field ) {
					$value = esc_url_raw( $value );
				} elseif ( 'email' === $field ) {
					$value = sanitize_email( $value );
				} elseif ( 'notes' === $field || 'name' === $field ) {
					$value = sanitize_text_field( $value );
				} elseif ( 'payout_type' === $field ) {
					$value = in_array( $value, array( 'flat', 'percent' ), true ) ? $value : 'percent';
				} elseif ( 'cashback_type' === $field ) {
					$value = in_array( $value, array( 'flat', 'percent' ), true ) ? $value : null;
				} elseif ( 'fulfillment_mode' === $field ) {
					$value = 'lead_capture' === $value ? 'lead_capture' : 'redirect';
				} elseif ( 'requires_appointment' === $field ) {
					$value = (int) (bool) $value;
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
		GAS_Roles::provision_partner_account( $id );

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
