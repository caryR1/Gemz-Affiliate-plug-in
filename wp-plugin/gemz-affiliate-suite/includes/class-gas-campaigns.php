<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Campaigns — ported from gemz-referral-crm (GRC) at Cary's explicit
 * request, 2026-09-08 (see SWAP-with-HOMES.md and ROADMAP.md). A campaign
 * is a real, admin-managed entity: a name, a partner, a URL tracking slug,
 * an optional custom landing page, and optional landing-page variants —
 * not an invisible side effect of a partner existing, the way yesterday's
 * "one code row per open partner" model treated it.
 *
 * The link model this replaces: a self-signup affiliate used to get one
 * `codes` row per partner (partner_id baked into the code itself). Now an
 * affiliate has exactly ONE stable code (unchanged mechanism —
 * GAS_Frontend::get_or_create_code_for_user()) and a link is that code
 * combined with a campaign's tracking_slug at share time
 * (build_link()) — any active affiliate's code works with any active
 * campaign's slug, matching GRC's agents/campaigns relationship exactly
 * (there is no assignment table between the two, in either system).
 */
class GAS_Campaigns {

	public static function init() {
		add_action( 'admin_post_gas_save_campaign', array( __CLASS__, 'handle_save_campaign' ) );
		add_action( 'admin_post_gas_save_campaign_variant', array( __CLASS__, 'handle_save_campaign_variant' ) );
		add_action( 'admin_post_gas_delete_campaign_variant', array( __CLASS__, 'handle_delete_campaign_variant' ) );
	}

	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . GAS_DB::table( 'campaigns' ) . ' WHERE id = %d', $id ) );
	}

	/**
	 * Matches on tracking_slug OR previous_slug so a link already shared
	 * under an old (real-name) slug keeps working forever after
	 * migrate_partner_privacy() moves a campaign to its alias-based slug
	 * — see the note on partners.partner_alias in class-gas-db.php.
	 */
	public static function get_by_slug( $slug ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . GAS_DB::table( 'campaigns' ) . " WHERE (tracking_slug = %s OR previous_slug = %s) AND status = 'active'",
			$slug, $slug
		) );
	}

	/**
	 * Every active campaign for an approved partner, for the affiliate
	 * dashboard — deliberately not filtered by `open_to_self_signup`,
	 * since that flag's only remaining job is gating whether a default
	 * campaign gets auto-created (see ensure_default_for_partner()); once
	 * a campaign exists at all, any affiliate's code can promote it, same
	 * as GRC.
	 */
	public static function get_active_for_approved_partners() {
		global $wpdb;
		$campaigns_table = GAS_DB::table( 'campaigns' );
		$partners_table  = GAS_DB::table( 'partners' );
		return $wpdb->get_results(
			"SELECT c.*, p.name AS partner_name, p.partner_alias AS partner_alias, p.state AS partner_state, p.blurb AS partner_blurb,
				p.spotlight_url AS partner_spotlight_url, p.capability_tags AS partner_capability_tags,
				p.requires_appointment AS partner_requires_appointment
			 FROM {$campaigns_table} c
			 INNER JOIN {$partners_table} p ON p.id = c.partner_id
			 WHERE c.status = 'active' AND p.outreach_status = 'approved'
			 ORDER BY p.name ASC, c.is_default DESC, c.name ASC"
		);
	}

	public static function get_variants_for( $campaign_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			'SELECT * FROM ' . GAS_DB::table( 'campaign_variants' ) . ' WHERE campaign_id = %d ORDER BY created_at ASC',
			$campaign_id
		) );
	}

	/**
	 * Builds the ready-to-share link for one campaign + one affiliate's
	 * stable code, optionally pointed at a specific variant — GAS's
	 * equivalent of GRC_Referral_Codes::build_campaign_link(), extended
	 * with the variant param GRC's own version never got around to
	 * adding (GRC's UIs built that URL by hand instead).
	 */
	public static function build_link( $campaign, $ref_code, $variant_id = null ) {
		$url = home_url( '/go/' . $campaign->tracking_slug );
		$url = add_query_arg( 'ref', $ref_code, $url );
		if ( $variant_id ) {
			$url = add_query_arg( 'variant', absint( $variant_id ), $url );
		}
		return $url;
	}

	private static function generate_unique_slug( $name ) {
		global $wpdb;
		$table = GAS_DB::table( 'campaigns' );
		$base  = sanitize_title( $name );
		if ( '' === $base ) {
			$base = 'campaign';
		}
		$slug = $base;
		$i    = 0;
		while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE tracking_slug = %s", $slug ) ) ) {
			$i++;
			$slug = $base . '-' . $i;
		}
		return $slug;
	}

	/**
	 * Auto-provisions one default campaign for a partner the moment it's
	 * both approved and marked "Open to self-signup" — GAS-specific
	 * addition GRC has no equivalent of (GRC's campaign creation is
	 * always a manual admin step). Idempotent: does nothing if this
	 * partner already has an `is_default` campaign, so it's safe to call
	 * on every partner save rather than needing its own one-time trigger.
	 * Called from GAS_Admin::handle_save_partner()/handle_add_partner()
	 * and GAS_REST::create_partner()/update_partner().
	 */
	public static function ensure_default_for_partner( $partner_id ) {
		global $wpdb;
		$partners_table  = GAS_DB::table( 'partners' );
		$campaigns_table = GAS_DB::table( 'campaigns' );

		// Guarantee an alias exists BEFORE reading the partner row, so
		// $partner->partner_alias below is never blank/never the real
		// name — see the note on partners.partner_alias in class-gas-db.php.
		self::ensure_partner_alias( $partner_id );

		$partner = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$partners_table} WHERE id = %d", $partner_id ) );
		if ( ! $partner || 'approved' !== $partner->outreach_status || ! $partner->open_to_self_signup ) {
			return;
		}

		$existing = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$campaigns_table} WHERE partner_id = %d AND is_default = 1", $partner_id
		) );
		if ( $existing ) {
			return;
		}

		$now = current_time( 'mysql' );
		$wpdb->insert( $campaigns_table, array(
			'name'          => $partner->name . ' — Direct Link',
			'partner_id'    => $partner_id,
			// Deliberately built from the alias, NOT $partner->name — an
			// affiliate's shareable link (/go/{tracking_slug}) must never
			// contain the real partner name. See the note on
			// partners.partner_alias in class-gas-db.php.
			'tracking_slug' => self::generate_unique_slug( $partner->partner_alias ),
			'status'        => 'active',
			'is_default'    => 1,
			'created_at'    => $now,
			'updated_at'    => $now,
		) );

		GAS_Admin::audit_log( 'campaign', $wpdb->insert_id, 'auto_created_default', array( 'partner_id' => $partner_id ) );
	}

	/**
	 * Guarantees a partner has a non-blank, non-real-name alias — called
	 * before any tracking_slug is ever generated for that partner, and
	 * from the one-time migrate_partner_privacy() pass for partners that
	 * pre-date this feature. Never overwrites an alias an admin already
	 * set. Placeholder format deliberately generic (uses the site's own
	 * partner_label setting, e.g. "Partner #4") — an admin can replace it
	 * with something more specific any time from the Partners screen.
	 */
	public static function ensure_partner_alias( $partner_id ) {
		global $wpdb;
		$table   = GAS_DB::table( 'partners' );
		$current = $wpdb->get_var( $wpdb->prepare( "SELECT partner_alias FROM {$table} WHERE id = %d", $partner_id ) );
		if ( $current ) {
			return $current;
		}
		$alias = ucfirst( GAS_Settings::get( 'partner_label' ) ) . ' #' . $partner_id;
		$wpdb->update( $table, array( 'partner_alias' => $alias ), array( 'id' => $partner_id ) );
		return $alias;
	}

	/**
	 * One-time (per campaign) privacy migration — see the long note on
	 * partners.partner_alias / campaigns.previous_slug in class-gas-db.php
	 * for the full story. Safe to call on every maybe_upgrade(): each
	 * partner/campaign is only ever touched once (partner_alias set only
	 * if blank; a campaign only re-slugged if its CURRENT tracking_slug
	 * still looks like it was generated from the partner's real name).
	 */
	public static function migrate_partner_privacy() {
		global $wpdb;
		$partners_table  = GAS_DB::table( 'partners' );
		$campaigns_table = GAS_DB::table( 'campaigns' );

		$partner_ids = $wpdb->get_col( "SELECT id FROM {$partners_table}" );
		foreach ( $partner_ids as $partner_id ) {
			self::ensure_partner_alias( $partner_id );
		}

		$campaigns = $wpdb->get_results(
			"SELECT c.id, c.tracking_slug, p.name AS partner_name, p.partner_alias AS partner_alias
			 FROM {$campaigns_table} c
			 INNER JOIN {$partners_table} p ON p.id = c.partner_id
			 WHERE c.previous_slug IS NULL"
		);
		foreach ( $campaigns as $c ) {
			$name_slug = sanitize_title( $c->partner_name );
			// Only re-slug a campaign whose CURRENT slug is (still)
			// derived from the real name — generate_unique_slug()'s own
			// disambiguation suffix is "-{n}", so match that too. A slug
			// an admin deliberately customized to something unrelated to
			// the partner's name is left alone.
			if ( ! $name_slug || ! preg_match( '/^' . preg_quote( $name_slug, '/' ) . '(-\d+)?$/', $c->tracking_slug ) ) {
				continue;
			}
			$new_slug = self::generate_unique_slug( $c->partner_alias );
			$wpdb->update(
				$campaigns_table,
				array(
					'previous_slug' => $c->tracking_slug,
					'tracking_slug' => $new_slug,
					'updated_at'    => current_time( 'mysql' ),
				),
				array( 'id' => $c->id )
			);
			GAS_Admin::audit_log( 'campaign', $c->id, 'slug_privacy_migrated', array( 'old_slug' => $c->tracking_slug, 'new_slug' => $new_slug ) );
		}
	}

	/* ---------------------------------------------------------------- *
	 * ADMIN HANDLERS
	 * ---------------------------------------------------------------- */

	public static function handle_save_campaign() {
		if ( ! current_user_can( GAS_Admin::CAP_CAMPAIGNS ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'gas_save_campaign' );

		global $wpdb;
		$table       = GAS_DB::table( 'campaigns' );
		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		$now         = current_time( 'mysql' );

		$raw_slug = isset( $_POST['tracking_slug'] ) ? sanitize_title( wp_unslash( $_POST['tracking_slug'] ) ) : '';
		if ( '' === $raw_slug ) {
			wp_die( 'Tracking slug is required and must be URL-safe.' );
		}
		$slug_taken = $wpdb->get_var( $wpdb->prepare(
			"SELECT id FROM {$table} WHERE tracking_slug = %s AND id != %d", $raw_slug, $campaign_id
		) );
		if ( $slug_taken ) {
			wp_die( 'That tracking slug is already used by another campaign. Choose a different one.' );
		}

		$data = array(
			'name'            => isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '',
			'partner_id'      => isset( $_POST['partner_id'] ) ? absint( $_POST['partner_id'] ) : 0,
			'tracking_slug'   => $raw_slug,
			'landing_page_id' => isset( $_POST['landing_page_id'] ) ? absint( $_POST['landing_page_id'] ) : 0,
			'status'          => isset( $_POST['status'] ) && 'paused' === $_POST['status'] ? 'paused' : 'active',
			'updated_at'      => $now,
		);
		if ( ! $data['landing_page_id'] ) {
			$data['landing_page_id'] = null;
		}
		if ( '' === $data['name'] || ! $data['partner_id'] ) {
			wp_die( 'Name and partner are both required.' );
		}

		if ( $campaign_id ) {
			// If an admin is manually changing the slug here, preserve the
			// old one in previous_slug so any link already shared under it
			// keeps working — same protection migrate_partner_privacy()
			// gives the auto-generated ones, applied here too since a
			// manual edit can just as easily break a live link.
			$old_slug = $wpdb->get_var( $wpdb->prepare( "SELECT tracking_slug FROM {$table} WHERE id = %d", $campaign_id ) );
			if ( $old_slug && $old_slug !== $data['tracking_slug'] ) {
				$data['previous_slug'] = $old_slug;
			}
			$wpdb->update( $table, $data, array( 'id' => $campaign_id ) );
			GAS_Admin::audit_log( 'campaign', $campaign_id, 'updated', $data );
		} else {
			$data['created_at'] = $now;
			$data['is_default'] = 0;
			$wpdb->insert( $table, $data );
			$campaign_id = $wpdb->insert_id;
			GAS_Admin::audit_log( 'campaign', $campaign_id, 'created', $data );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=gas-campaigns&edit=' . $campaign_id . '&saved=1' ) );
		exit;
	}

	public static function handle_save_campaign_variant() {
		if ( ! current_user_can( GAS_Admin::CAP_CAMPAIGNS ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'gas_save_campaign_variant' );

		$campaign_id     = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		$landing_page_id = isset( $_POST['landing_page_id'] ) ? absint( $_POST['landing_page_id'] ) : 0;
		$variant_name    = isset( $_POST['variant_name'] ) ? sanitize_text_field( wp_unslash( $_POST['variant_name'] ) ) : '';

		if ( ! $campaign_id || ! $landing_page_id || '' === $variant_name ) {
			wp_die( 'A campaign, landing page, and variant name are all required.' );
		}

		global $wpdb;
		$wpdb->insert( GAS_DB::table( 'campaign_variants' ), array(
			'campaign_id'     => $campaign_id,
			'landing_page_id' => $landing_page_id,
			'variant_name'    => $variant_name,
			'created_at'      => current_time( 'mysql' ),
		) );

		GAS_Admin::audit_log( 'campaign', $campaign_id, 'variant_added', array( 'landing_page_id' => $landing_page_id, 'variant_name' => $variant_name ) );
		wp_safe_redirect( admin_url( 'admin.php?page=gas-campaigns&edit=' . $campaign_id . '&variant_saved=1' ) );
		exit;
	}

	public static function handle_delete_campaign_variant() {
		if ( ! current_user_can( GAS_Admin::CAP_CAMPAIGNS ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'gas_delete_campaign_variant' );

		global $wpdb;
		$variant_id  = isset( $_POST['variant_id'] ) ? absint( $_POST['variant_id'] ) : 0;
		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		$wpdb->delete( GAS_DB::table( 'campaign_variants' ), array( 'id' => $variant_id ) );

		GAS_Admin::audit_log( 'campaign', $campaign_id, 'variant_deleted', array( 'variant_id' => $variant_id ) );
		wp_safe_redirect( admin_url( 'admin.php?page=gas-campaigns&edit=' . $campaign_id . '&variant_saved=1' ) );
		exit;
	}
}
