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

		// Campaigns — exposed from the start (unlike partners/settings,
		// where a field missing from REST silently locked Home out of it
		// once, see class-gas-rest.php's own history) since campaigns are
		// exactly the kind of admin-managed data a site with only
		// FTP+REST access (no wp-admin) needs to create/edit directly.
		register_rest_route( 'gas/v1', '/campaigns', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'list_campaigns' ),
			'permission_callback' => array( __CLASS__, 'permission_check' ),
		) );

		register_rest_route( 'gas/v1', '/campaigns', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'create_campaign' ),
			'permission_callback' => array( __CLASS__, 'permission_check' ),
		) );

		register_rest_route( 'gas/v1', '/campaigns/(?P<id>\d+)', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'update_campaign' ),
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

		// Lets a payout row be created with an explicit entered_at, backdated
		// into a prior calendar month — something the wp-admin Payout
		// Calculator form can't do (it always uses current_time('mysql')).
		// Needed for seeding realistic test/demo ledger data that spans the
		// pending-vs-finalized month boundary; uses the exact same tier-split
		// math as the calculator (GAS_Payouts::compute()), so it can never
		// drift from what a real admin-entered payout would compute.
		register_rest_route( 'gas/v1', '/payouts', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'create_payout' ),
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

	public static function list_campaigns() {
		global $wpdb;
		$campaigns_table = GAS_DB::table( 'campaigns' );
		$partners_table  = GAS_DB::table( 'partners' );
		$rows = $wpdb->get_results(
			"SELECT c.*, p.name AS partner_name FROM {$campaigns_table} c
			 LEFT JOIN {$partners_table} p ON p.id = c.partner_id
			 ORDER BY c.created_at DESC",
			ARRAY_A
		);
		return new WP_REST_Response( $rows, 200 );
	}

	public static function create_campaign( WP_REST_Request $request ) {
		global $wpdb;
		$table = GAS_DB::table( 'campaigns' );
		$body  = $request->get_json_params();

		$name       = isset( $body['name'] ) ? sanitize_text_field( $body['name'] ) : '';
		$partner_id = isset( $body['partner_id'] ) ? absint( $body['partner_id'] ) : 0;
		$slug       = isset( $body['tracking_slug'] ) ? sanitize_title( $body['tracking_slug'] ) : '';

		if ( '' === $name || ! $partner_id || '' === $slug ) {
			return new WP_Error( 'gas_missing_fields', 'name, partner_id, and tracking_slug are all required.', array( 'status' => 400 ) );
		}
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE tracking_slug = %s", $slug ) ) ) {
			return new WP_Error( 'gas_slug_taken', 'That tracking slug is already used by another campaign.', array( 'status' => 409 ) );
		}

		$now = current_time( 'mysql' );
		$wpdb->insert( $table, array(
			'name'            => $name,
			'partner_id'      => $partner_id,
			'tracking_slug'   => $slug,
			'landing_page_id' => isset( $body['landing_page_id'] ) ? absint( $body['landing_page_id'] ) ?: null : null,
			'status'          => isset( $body['status'] ) && 'paused' === $body['status'] ? 'paused' : 'active',
			'is_default'      => 0,
			'created_at'      => $now,
			'updated_at'      => $now,
		) );

		GAS_Admin::audit_log( 'campaign', $wpdb->insert_id, 'created_via_rest', array( 'name' => $name ) );

		$created = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $wpdb->insert_id ), ARRAY_A );
		return new WP_REST_Response( $created, 201 );
	}

	public static function update_campaign( WP_REST_Request $request ) {
		global $wpdb;
		$id    = absint( $request['id'] );
		$table = GAS_DB::table( 'campaigns' );

		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d", $id ) );
		if ( ! $existing ) {
			return new WP_Error( 'gas_not_found', 'Campaign not found.', array( 'status' => 404 ) );
		}

		$body    = $request->get_json_params();
		$allowed = array( 'name', 'partner_id', 'tracking_slug', 'landing_page_id', 'status' );
		$data    = array();

		foreach ( $allowed as $field ) {
			if ( ! array_key_exists( $field, $body ) ) {
				continue;
			}
			if ( 'name' === $field ) {
				$data['name'] = sanitize_text_field( $body['name'] );
			} elseif ( 'partner_id' === $field ) {
				$data['partner_id'] = absint( $body['partner_id'] );
			} elseif ( 'tracking_slug' === $field ) {
				$slug = sanitize_title( $body['tracking_slug'] );
				$taken = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE tracking_slug = %s AND id != %d", $slug, $id ) );
				if ( $taken ) {
					return new WP_Error( 'gas_slug_taken', 'That tracking slug is already used by another campaign.', array( 'status' => 409 ) );
				}
				$data['tracking_slug'] = $slug;
			} elseif ( 'landing_page_id' === $field ) {
				$data['landing_page_id'] = absint( $body['landing_page_id'] ) ?: null;
			} elseif ( 'status' === $field ) {
				$data['status'] = 'paused' === $body['status'] ? 'paused' : 'active';
			}
		}

		if ( empty( $data ) ) {
			return new WP_Error( 'gas_no_fields', 'No recognized fields to update.', array( 'status' => 400 ) );
		}

		$data['updated_at'] = current_time( 'mysql' );
		$wpdb->update( $table, $data, array( 'id' => $id ) );
		GAS_Admin::audit_log( 'campaign', $id, 'updated_via_rest', $data );

		$updated = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ), ARRAY_A );
		return new WP_REST_Response( $updated, 200 );
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
					'fulfillment_mode'         => 'lead_capture',
					'requires_appointment'     => 0,
					'service_area_description' => isset( $c['service_area_description'] ) ? sanitize_text_field( $c['service_area_description'] ) : '',
					'state'                    => isset( $c['state'] ) ? strtoupper( sanitize_text_field( $c['state'] ) ) : '',
					'city'                     => isset( $c['city'] ) ? sanitize_text_field( $c['city'] ) : '',
					'zip'                      => isset( $c['zip'] ) ? sanitize_text_field( $c['zip'] ) : '',
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
		$fulfillment_mode = isset( $body['fulfillment_mode'] ) && 'redirect' === $body['fulfillment_mode'] ? 'redirect' : 'lead_capture';

		$wpdb->insert(
			$table,
			array(
				'slug'                 => $slug,
				'name'                 => $name,
				'payout_type'          => $payout_type,
				'payout_amount'        => isset( $body['payout_amount'] ) ? (float) $body['payout_amount'] : null,
				'payout_percent'       => isset( $body['payout_percent'] ) ? (float) $body['payout_percent'] : null,
				'agent_pool_type'      => isset( $body['agent_pool_type'] ) && 'flat' === $body['agent_pool_type'] ? 'flat' : 'percent',
				'agent_pool_value'     => isset( $body['agent_pool_value'] ) ? (float) $body['agent_pool_value'] : 100,
				'installments_json'    => ! empty( $body['installments'] ) ? wp_json_encode( $body['installments'] ) : null,
				'cashback_type'        => $cashback_type,
				'cashback_value'       => isset( $body['cashback_value'] ) ? (float) $body['cashback_value'] : 0,
				'fulfillment_mode'     => $fulfillment_mode,
				'requires_appointment' => array_key_exists( 'requires_appointment', $body ) ? (int) (bool) $body['requires_appointment'] : 0,
				'destination_url'      => isset( $body['destination_url'] ) ? esc_url_raw( $body['destination_url'] ) : '',
				'typical_sale_amount'  => isset( $body['typical_sale_amount'] ) ? (float) $body['typical_sale_amount'] : null,
				'service_area_description' => isset( $body['service_area_description'] ) ? sanitize_text_field( $body['service_area_description'] ) : '',
				'state'                => isset( $body['state'] ) ? strtoupper( sanitize_text_field( $body['state'] ) ) : '',
				'city'                 => isset( $body['city'] ) ? sanitize_text_field( $body['city'] ) : '',
				'zip'                  => isset( $body['zip'] ) ? sanitize_text_field( $body['zip'] ) : '',
				'email'                => isset( $body['email'] ) ? sanitize_email( $body['email'] ) : '',
				'open_to_self_signup'  => array_key_exists( 'open_to_self_signup', $body ) ? (int) (bool) $body['open_to_self_signup'] : 1,
				'blurb'                => isset( $body['blurb'] ) ? sanitize_text_field( $body['blurb'] ) : '',
				'spotlight_url'        => isset( $body['spotlight_url'] ) ? esc_url_raw( $body['spotlight_url'] ) : '',
				'capability_tags'      => ! empty( $body['capability_tags'] ) && is_array( $body['capability_tags'] )
					? implode( ',', array_intersect( array_map( 'sanitize_key', $body['capability_tags'] ), array_keys( GAS_DB::capability_tags() ) ) )
					: '',
				'notes'                => isset( $body['notes'] ) ? sanitize_text_field( $body['notes'] ) : '',
				'created_at'           => current_time( 'mysql' ),
			)
		);

		GAS_Roles::provision_partner_account( $wpdb->insert_id );
		GAS_Campaigns::ensure_default_for_partner( $wpdb->insert_id );
		if ( ! empty( $body['email'] ) ) {
			GAS_Contacts::upsert( $body['email'], 'partner', array( 'name' => $name, 'source' => 'partner_save', 'related_table' => 'partners', 'related_id' => $wpdb->insert_id ) );
		}

		$created = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $wpdb->insert_id ), ARRAY_A );
		return new WP_REST_Response( $created, 201 );
	}

	public static function create_payout( WP_REST_Request $request ) {
		global $wpdb;
		$body = $request->get_json_params();

		$code_id     = isset( $body['code_id'] ) ? absint( $body['code_id'] ) : 0;
		$codes_table = GAS_DB::table( 'codes' );
		$code        = $code_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$codes_table} WHERE id = %d", $code_id ) ) : null;
		if ( ! $code ) {
			return new WP_Error( 'gas_not_found', 'Code not found.', array( 'status' => 404 ) );
		}

		// Since campaigns (2026-09-08), a code no longer implies one fixed
		// partner — an explicit partner_id is required in the request body.
		// Falls back to the code's own partner_id only for a
		// manually-assigned code that still has one (partner_id != 0),
		// purely for backward compatibility with existing callers.
		$partner_id = isset( $body['partner_id'] ) ? absint( $body['partner_id'] ) : ( (int) $code->partner_id ?: 0 );
		if ( ! $partner_id ) {
			return new WP_Error( 'gas_missing_partner', 'partner_id is required — this code isn\'t tied to a single partner.', array( 'status' => 400 ) );
		}

		$partners_table = GAS_DB::table( 'partners' );
		$partner        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$partners_table} WHERE id = %d", $partner_id ) );
		if ( ! $partner ) {
			return new WP_Error( 'gas_not_found', 'Partner not found.', array( 'status' => 404 ) );
		}

		$sale_amount       = isset( $body['sale_amount'] ) ? (float) $body['sale_amount'] : 0;
		$installment_index = isset( $body['installment_index'] ) ? absint( $body['installment_index'] ) : null;
		$notes             = isset( $body['notes'] ) ? sanitize_textarea_field( $body['notes'] ) : '';
		$entered_at        = ! empty( $body['entered_at'] ) ? sanitize_text_field( $body['entered_at'] ) : current_time( 'mysql' );

		// Optional, and only meaningful for backdated/seeded rows — a real
		// admin-entered payout is always freshly 'unpaid' via the wp-admin
		// Calculator. Lets test/demo data reflect a realistic mix of
		// already-settled vs still-owed money without a separate API call
		// per tier to mark it paid after the fact.
		$status      = isset( $body['status'] ) && 'paid' === $body['status'] ? 'paid' : 'unpaid';
		$paid_at     = 'paid' === $status ? ( ! empty( $body['paid_at'] ) ? sanitize_text_field( $body['paid_at'] ) : $entered_at ) : null;
		$tier2_paid  = 'paid' === $status && ! empty( $body['tier2_paid'] ) ? 1 : 0;
		$tier3_paid  = 'paid' === $status && ! empty( $body['tier3_paid'] ) ? 1 : 0;

		$calc = GAS_Payouts::compute( $partner, $code, $sale_amount, $installment_index );

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
				'tier2_code_id'     => $calc['tier2_code'] ? $calc['tier2_code']->id : null,
				'tier2_amount'      => $calc['tier2_amount'],
				'tier3_code_id'     => $calc['tier3_code'] ? $calc['tier3_code']->id : null,
				'tier3_amount'      => $calc['tier3_amount'],
				'net_to_cary'       => $calc['net'],
				'status'            => $status,
				'paid_at'           => $paid_at,
				'tier2_paid'        => $tier2_paid,
				'tier3_paid'        => $tier3_paid,
				'entered_at'        => $entered_at,
				'notes'             => $notes,
			)
		);

		GAS_Admin::audit_log( 'payout', $wpdb->insert_id, 'entered_via_rest', array( 'code' => $code->code, 'gross' => $calc['gross'], 'net' => $calc['net'] ) );

		$created = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . GAS_DB::table( 'payouts' ) . " WHERE id = %d", $wpdb->insert_id ), ARRAY_A );
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

		$allowed  = array( 'site_name', 'partner_label', 'conversion_noun', 'menu_icon', 'tier1_split_percent', 'tier2_split_percent', 'tier3_split_percent', 'quote_page_intro', 'quote_page_image_id', 'min_payout_threshold', 'business_name', 'business_address', 'program_terms_url' );
		$numeric  = array( 'tier1_split_percent', 'tier2_split_percent', 'tier3_split_percent', 'quote_page_image_id', 'min_payout_threshold' );

		$values = array();
		foreach ( $allowed as $field ) {
			if ( ! array_key_exists( $field, $body ) ) {
				continue;
			}
			if ( in_array( $field, $numeric, true ) ) {
				$values[ $field ] = (float) $body[ $field ];
			} elseif ( 'quote_page_intro' === $field ) {
				$values[ $field ] = sanitize_textarea_field( $body[ $field ] );
			} elseif ( 'program_terms_url' === $field ) {
				$values[ $field ] = esc_url_raw( $body[ $field ] );
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
		$allowed    = array( 'name', 'payout_type', 'payout_amount', 'payout_percent', 'agent_pool_type', 'agent_pool_value', 'typical_sale_amount', 'cashback_type', 'cashback_value', 'fulfillment_mode', 'requires_appointment', 'destination_url', 'service_area_description', 'state', 'city', 'zip', 'outreach_status', 'email', 'open_to_self_signup', 'blurb', 'spotlight_url', 'capability_tags', 'notes' );
		$formats    = array();
		$format_map = array(
			'name'                 => '%s',
			'payout_type'          => '%s',
			'payout_amount'        => '%f',
			'payout_percent'       => '%f',
			'agent_pool_type'      => '%s',
			'agent_pool_value'     => '%f',
			'typical_sale_amount'  => '%f',
			'cashback_type'        => '%s',
			'cashback_value'       => '%f',
			'fulfillment_mode'     => '%s',
			'requires_appointment' => '%d',
			'destination_url'      => '%s',
			'service_area_description' => '%s',
			'state'                => '%s',
			'city'                 => '%s',
			'zip'                  => '%s',
			'outreach_status'      => '%s',
			'email'                => '%s',
			'open_to_self_signup'  => '%d',
			'blurb'                => '%s',
			'spotlight_url'        => '%s',
			'capability_tags'      => '%s',
			'notes'                => '%s',
		);

		foreach ( $allowed as $field ) {
			if ( array_key_exists( $field, $body ) ) {
				$value = $body[ $field ];
				if ( 'destination_url' === $field ) {
					$value = esc_url_raw( $value );
				} elseif ( 'email' === $field ) {
					$value = sanitize_email( $value );
				} elseif ( 'notes' === $field || 'name' === $field || 'service_area_description' === $field || 'city' === $field || 'zip' === $field ) {
					$value = sanitize_text_field( $value );
				} elseif ( 'state' === $field ) {
					$value = strtoupper( sanitize_text_field( $value ) );
				} elseif ( 'payout_type' === $field ) {
					$value = in_array( $value, array( 'flat', 'percent' ), true ) ? $value : 'percent';
				} elseif ( 'agent_pool_type' === $field ) {
					$value = in_array( $value, array( 'flat', 'percent' ), true ) ? $value : 'percent';
				} elseif ( 'cashback_type' === $field ) {
					$value = in_array( $value, array( 'flat', 'percent' ), true ) ? $value : null;
				} elseif ( 'fulfillment_mode' === $field ) {
					$value = 'lead_capture' === $value ? 'lead_capture' : 'redirect';
				} elseif ( 'requires_appointment' === $field ) {
					$value = (int) (bool) $value;
				} elseif ( 'outreach_status' === $field ) {
					$value = in_array( $value, array( 'new', 'contacted', 'approved', 'declined' ), true ) ? $value : 'approved';
				} elseif ( 'open_to_self_signup' === $field ) {
					$value = (int) (bool) $value;
				} elseif ( 'blurb' === $field ) {
					$value = sanitize_text_field( $value );
				} elseif ( 'spotlight_url' === $field ) {
					$value = esc_url_raw( $value );
				} elseif ( 'capability_tags' === $field ) {
					$value = is_array( $value )
						? implode( ',', array_intersect( array_map( 'sanitize_key', $value ), array_keys( GAS_DB::capability_tags() ) ) )
						: '';
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
		GAS_Campaigns::ensure_default_for_partner( $id );
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
			// Masked summaries only — never expose a full account number/
			// IBAN/tax ID over the REST API, same as the admin screens.
			$r['payout_summary'] = $r['wp_user_id'] ? GAS_Payouts::masked_summary( $r['wp_user_id'] ) : '';
			$r['tax_summary']    = $r['wp_user_id'] ? GAS_Payouts::masked_tax_summary( $r['wp_user_id'] ) : '';
		}
		return new WP_REST_Response( $rows, 200 );
	}
}
