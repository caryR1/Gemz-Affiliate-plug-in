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

		// Ingests a batch of candidate fulfillment partners found by a
		// research pass (the actual searching happens outside PHP, e.g. a
		// Claude Code session asked to research partners for this site's
		// category/region — this just lands the results safely). Every
		// accepted candidate is 'new' outreach_status and has no
		// destination_url, so it can never start receiving real traffic
		// until an admin reviews it, sets a destination, and assigns a
		// code to it — ported in spirit from gemz-referral-crm's
		// ingest_partner_research_batch(), simplified since GAS has no
		// automatic lead-to-partner geo-matching to gate.
		register_rest_route( 'gas/v1', '/partners/research-batch', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'ingest_partner_research_batch' ),
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

	/**
	 * Normalizes a website URL for dedup matching: strips scheme, "www.",
	 * and any trailing slash/path, lowercased. Good enough to catch the
	 * common "same company, slightly different URL" case without being a
	 * full URL-equivalence engine.
	 */
	private static function normalize_website( $url ) {
		$url = strtolower( trim( (string) $url ) );
		$url = preg_replace( '#^https?://#', '', $url );
		$url = preg_replace( '#^www\.#', '', $url );
		$url = rtrim( explode( '/', $url )[0] );
		return $url;
	}

	public static function ingest_partner_research_batch( WP_REST_Request $request ) {
		global $wpdb;
		$table   = GAS_DB::table( 'partners' );
		$body    = $request->get_json_params();
		$batch_id     = isset( $body['batch_id'] ) ? sanitize_text_field( $body['batch_id'] ) : wp_generate_password( 12, false );
		$candidates   = isset( $body['candidates'] ) && is_array( $body['candidates'] ) ? $body['candidates'] : array();

		if ( ! $candidates ) {
			return new WP_Error( 'gas_no_candidates', 'No candidates provided.', array( 'status' => 400 ) );
		}

		// Existing partners, for dedup — by normalized website first, name second.
		$existing        = $wpdb->get_results( "SELECT name, source_url, destination_url FROM {$table}" );
		$known_websites  = array();
		$known_names     = array();
		foreach ( $existing as $e ) {
			$w = self::normalize_website( $e->source_url ?: $e->destination_url );
			if ( $w ) {
				$known_websites[] = $w;
			}
			$known_names[] = strtolower( trim( $e->name ) );
		}

		$inserted = array();
		$skipped  = array();
		$now      = current_time( 'mysql' );

		foreach ( $candidates as $c ) {
			$name    = isset( $c['name'] ) ? sanitize_text_field( $c['name'] ) : '';
			$website = isset( $c['website'] ) ? esc_url_raw( $c['website'] ) : '';
			if ( '' === $name ) {
				continue;
			}

			$norm_website = self::normalize_website( $website );
			$norm_name    = strtolower( trim( $name ) );

			if ( ( $norm_website && in_array( $norm_website, $known_websites, true ) ) || in_array( $norm_name, $known_names, true ) ) {
				$skipped[] = $name;
				continue;
			}

			$slug = sanitize_title( $name );
			$base = $slug;
			$i    = 0;
			while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE slug = %s", $slug ) ) ) {
				$i++;
				$slug = $base . '-' . $i;
			}

			$wpdb->insert(
				$table,
				array(
					'slug'                     => $slug,
					'name'                     => $name,
					'payout_type'              => 'flat',
					'service_area_description' => isset( $c['service_area_description'] ) ? sanitize_text_field( $c['service_area_description'] ) : '',
					'source_url'               => $website,
					'discovered_via'           => 'research',
					'outreach_status'          => 'new',
					'research_batch_id'        => $batch_id,
					'notes'                    => isset( $c['notes'] ) ? sanitize_textarea_field( $c['notes'] ) : '',
					'created_at'               => $now,
				)
			);

			GAS_Admin::audit_log( 'partner', $wpdb->insert_id, 'research_added', array( 'batch_id' => $batch_id, 'source_url' => $website ) );

			// Prevents duplicates within this same batch, not just against
			// what already existed before it started.
			if ( $norm_website ) {
				$known_websites[] = $norm_website;
			}
			$known_names[] = $norm_name;
			$inserted[]    = $name;
		}

		return new WP_REST_Response( array(
			'batch_id' => $batch_id,
			'inserted' => $inserted,
			'skipped_duplicates' => $skipped,
		), 200 );
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
				'typical_sale_amount'  => isset( $body['typical_sale_amount'] ) ? (float) $body['typical_sale_amount'] : null,
				'service_area_description' => isset( $body['service_area_description'] ) ? sanitize_text_field( $body['service_area_description'] ) : '',
				'email'                => isset( $body['email'] ) ? sanitize_email( $body['email'] ) : '',
				'notes'                => isset( $body['notes'] ) ? sanitize_text_field( $body['notes'] ) : '',
				'created_at'           => current_time( 'mysql' ),
			)
		);

		GAS_Roles::provision_partner_account( $wpdb->insert_id );
		if ( ! empty( $body['email'] ) ) {
			GAS_Contacts::upsert( $body['email'], 'partner', array( 'name' => $name, 'source' => 'partner_save', 'related_table' => 'partners', 'related_id' => $wpdb->insert_id ) );
		}

		$created = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $wpdb->insert_id ), ARRAY_A );
		return new WP_REST_Response( $created, 201 );
	}

	const PAGE_ID_OPTIONS = array( 'signup_page_id' => 'gas_signup_page_id', 'dashboard_page_id' => 'gas_dashboard_page_id', 'help_page_id' => 'gas_help_page_id', 'partner_help_page_id' => 'gas_partner_help_page_id', 'faq_page_id' => 'gas_faq_page_id' );

	public static function get_settings() {
		$settings = GAS_Settings::all();
		foreach ( self::PAGE_ID_OPTIONS as $field => $option_name ) {
			$settings[ $field ] = (int) get_option( $option_name );
		}
		return new WP_REST_Response( $settings, 200 );
	}

	public static function update_settings( WP_REST_Request $request ) {
		$body = $request->get_json_params();

		// These are separate raw options (not part of the gas_settings
		// array) — used to point a given feature's URL helper at a
		// specific existing page, e.g. when cutting a site over onto this
		// plugin, or adopting a pre-existing page (like a site's own FAQ)
		// instead of the one this plugin auto-created alongside it.
		$touched_page_id = false;
		foreach ( self::PAGE_ID_OPTIONS as $field => $option_name ) {
			if ( array_key_exists( $field, $body ) ) {
				update_option( $option_name, absint( $body[ $field ] ) );
				$touched_page_id = true;
			}
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

		if ( empty( $values ) && ! $touched_page_id ) {
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
		$allowed    = array( 'name', 'payout_type', 'payout_amount', 'payout_percent', 'typical_sale_amount', 'cashback_type', 'cashback_value', 'fulfillment_mode', 'requires_appointment', 'destination_url', 'service_area_description', 'outreach_status', 'email', 'notes' );
		$formats    = array();
		$format_map = array(
			'name'                 => '%s',
			'payout_type'          => '%s',
			'payout_amount'        => '%f',
			'payout_percent'       => '%f',
			'typical_sale_amount'  => '%f',
			'cashback_type'        => '%s',
			'cashback_value'       => '%f',
			'fulfillment_mode'     => '%s',
			'requires_appointment' => '%d',
			'destination_url'      => '%s',
			'service_area_description' => '%s',
			'outreach_status'      => '%s',
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
				} elseif ( 'notes' === $field || 'name' === $field || 'service_area_description' === $field ) {
					$value = sanitize_text_field( $value );
				} elseif ( 'payout_type' === $field ) {
					$value = in_array( $value, array( 'flat', 'percent' ), true ) ? $value : 'percent';
				} elseif ( 'cashback_type' === $field ) {
					$value = in_array( $value, array( 'flat', 'percent' ), true ) ? $value : null;
				} elseif ( 'fulfillment_mode' === $field ) {
					$value = 'lead_capture' === $value ? 'lead_capture' : 'redirect';
				} elseif ( 'requires_appointment' === $field ) {
					$value = (int) (bool) $value;
				} elseif ( 'outreach_status' === $field ) {
					$value = in_array( $value, array( 'new', 'contacted', 'approved', 'declined' ), true ) ? $value : 'approved';
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
		if ( ! empty( $data['email'] ) ) {
			$name_for_contact = $data['name'] ?? $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$table} WHERE id = %d", $id ) );
			GAS_Contacts::upsert( $data['email'], 'partner', array( 'name' => $name_for_contact, 'source' => 'partner_save', 'related_table' => 'partners', 'related_id' => $id ) );
		}

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
