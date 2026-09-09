<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Per-site configuration, stored as one option so every site running this
 * plugin can set its own program name and partner terminology without
 * editing code. Note: an affiliate never chooses (or sees) which
 * fulfillment partner handles their referrals on any project — that's
 * always resolved by an admin afterward, from the Codes screen. There is
 * deliberately no setting that changes this.
 */
class GAS_Settings {

	const OPTION = 'gas_settings';

	public static function defaults() {
		return array(
			'site_name'                 => get_bloginfo( 'name' ) ?: 'Affiliate Suite',
			'partner_label'             => 'partner',
			// What a paid conversion is actually called on this site's
			// signup/refer page, e.g. "installation" (solar), "home"
			// (Homes), "sale" (a generic fallback) — must flex per
			// project since this shared plugin runs different kinds of
			// businesses, same reasoning as partner_label existing at all.
			'conversion_noun'           => 'installation',
			'menu_icon'                 => 'dashicons-groups',
			// Multi-tier recruiting commission model: FIXED pooled split,
			// not additive. The gross commission on a sale is a fixed
			// total pool, divided across up to 3 tiers (the direct
			// affiliate, their sponsor, and their sponsor's sponsor) by
			// these fixed percentages — the SAME split for every
			// affiliate, not individually negotiable per code. Total
			// payout never grows with chain depth: if a tier has no one
			// in it (e.g. the affiliate has no sponsor), that tier's
			// share simply isn't paid to anyone — it stays with the
			// house (net_to_cary) rather than being redistributed to the
			// tiers that do have someone in them.
			'tier1_split_percent'      => 70,
			'tier2_split_percent'      => 20,
			'tier3_split_percent'      => 10,
			// Shown on the shared on-site "Get a Quote" page — one per
			// project/site, not per fulfillment partner or per affiliate,
			// since the customer never sees or cares which specific
			// partner ends up handling their request.
			'quote_page_intro'         => '',
			'quote_page_image_id'     => 0,
			// Below the threshold, an affiliate's unpaid balance just
			// carries forward untouched rather than triggering a PayPal/Wise
			// payout — Cary, 2026-09-08: "almost nothing we will do will
			// trigger less than that," and it avoids a transfer fee eating
			// a meaningful chunk of a tiny payout. Doesn't affect the
			// Payout Calculator/Ledger (recording that a sale happened),
			// only the automated payout runs that actually move money.
			'min_payout_threshold'     => 50,
			// Compliance-footer fields (2026-09-08) — appended to every
			// customer- and affiliate-facing email, see
			// GAS_Settings::compliance_footer(). business_name/address
			// falls back to site_name if unset since a fresh install has
			// neither filled in yet; program_terms_url stays blank (the
			// footer just omits that line) until a real terms page exists.
			'business_name'            => '',
			'business_address'         => '',
			'program_terms_url'        => '',
		);
	}

	/**
	 * A standard plain-text footer for every customer-, affiliate-, and
	 * partner-facing email — added 2026-09-08 after confirming
	 * gemz-referral-crm's own default templates have no compliance notice
	 * at all (business name/address, why-you're-receiving-this, terms
	 * link). Admin-facing notifications (new-affiliate-joined, stale-lead,
	 * coverage-match, etc.) deliberately don't get this — it's for people
	 * outside the organization, not Cary's own inbox.
	 *
	 * Pass the recipient's own email to also append a working unsubscribe
	 * link (added 2026-09-08 alongside the plugin's unsubscribe mechanism,
	 * GAS_Contacts::unsubscribe_link()) — omit it only for a transactional
	 * template where unsubscribing wouldn't make sense to offer.
	 */
	public static function compliance_footer( $email = '' ) {
		$business = self::get( 'business_name' ) ?: self::get( 'site_name' );
		$address  = self::get( 'business_address' );
		$terms    = self::get( 'program_terms_url' );

		$lines   = array();
		$lines[] = '---';
		$lines[] = $business . ( $address ? ', ' . $address : '' );
		$lines[] = 'You\'re receiving this because of your participation in the ' . $business . ' referral program.';
		if ( $terms ) {
			$lines[] = 'Program terms: ' . $terms;
		}
		if ( $email && is_email( $email ) ) {
			$lines[] = 'No longer want these emails? Unsubscribe: ' . GAS_Contacts::unsubscribe_link( $email );
		}

		return "\n\n" . implode( "\n", $lines );
	}

	public static function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		// Intersected against defaults() so a setting removed from the code
		// (like the old require_partner_at_signup toggle) can't keep leaking
		// out of a site's already-saved option blob forever.
		return array_merge( self::defaults(), array_intersect_key( $stored, self::defaults() ) );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function update( array $values ) {
		$current = self::all();
		$merged  = array_merge( $current, $values );
		update_option( self::OPTION, $merged );
	}
}
