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
			// Automated monthly payout run (2026-09-10) — see
			// GAS_REST::run_automated_payout(). Fires on/after this day of
			// the month (Cary's reasoning: the previous month closes on the
			// 1st, days 1-4 are the admin's window to fix any holds before
			// the run fires, and running on the 5th means affiliates see
			// their money within the first week). The pause toggle is a
			// simple on/off, not a per-cycle workflow, per Cary's own
			// framing ("in case we run into a problem").
			'payout_run_day'           => 5,
			'payout_run_paused'        => false,
			// Color theme (2026-09-10) — which project/site a given
			// install is running determines its house color, not user
			// preference: Homes stays 'green' (its site is green
			// throughout), Solar picks 'blue' or 'blue_purple' (its site
			// is blue). Only affects public-facing plugin pages —
			// wp-admin screens are unthemed by design.
			'theme'                    => 'green',
		);
	}

	/**
	 * The 3 initial presets (2026-09-10) — each a full set of the CSS
	 * custom properties gas-frontend.css keys every themeable color off
	 * of. 'green' matches the hex fallbacks already baked into
	 * gas-frontend.css's var() calls, so picking it changes nothing
	 * visually; it exists as an explicit choice rather than "just don't
	 * set a theme" so the settings UI has one obviously-current option
	 * instead of an implicit default.
	 */
	const THEMES = array(
		'green'       => array(
			'label'  => 'Green (default)',
			'accent' => '#3F8353',
			'dark'   => '#193421',
			'tint'   => '#F7FBF7',
			'border' => '#CFE3D2',
		),
		'blue'        => array(
			'label'  => 'Blue',
			'accent' => '#2E5FA3',
			'dark'   => '#17325C',
			'tint'   => '#EEF4FB',
			'border' => '#CBDCEF',
		),
		'blue_purple' => array(
			'label'  => 'Blue & Purple',
			'accent' => '#4B4FBD',
			'dark'   => '#2C2F72',
			'tint'   => '#F0EFFB',
			'border' => '#D6D5F2',
		),
	);

	/**
	 * The CSS custom-property declarations for this site's currently
	 * selected theme, ready to hand to wp_add_inline_style() right after
	 * gas-frontend.css is enqueued — see GAS_Frontend::enqueue_assets().
	 * Falls back to 'green' for an unrecognized/removed theme key rather
	 * than emitting nothing, so a bad stored value can't leave every
	 * themeable element with no color at all.
	 */
	public static function theme_css_vars() {
		$key     = self::get( 'theme' );
		$palette = isset( self::THEMES[ $key ] ) ? self::THEMES[ $key ] : self::THEMES['green'];

		return sprintf(
			':root{--gas-accent:%1$s;--gas-accent-dark:%2$s;--gas-accent-tint:%3$s;--gas-accent-border:%4$s;}',
			$palette['accent'],
			$palette['dark'],
			$palette['tint'],
			$palette['border']
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

	/**
	 * A shared secret authenticating the automated-payout-run REST
	 * endpoint (see GAS_REST::run_automated_payout()) — a real Hostinger
	 * server cron job hits that URL with this token, not WP-Cron (which
	 * only fires on site traffic and can silently slip, unacceptable for
	 * something that moves real money on a schedule). Stored as its own
	 * raw option, same pattern as the PayPal/Wise API credentials, rather
	 * than inside the gas_settings blob, since it's a secret to copy into
	 * an external cron config, not a value an admin edits in place.
	 */
	public static function get_automated_payout_token() {
		$token = get_option( 'gas_automated_payout_token', '' );
		if ( ! $token ) {
			$token = wp_generate_password( 40, false, false );
			update_option( 'gas_automated_payout_token', $token );
		}
		return $token;
	}

	public static function regenerate_automated_payout_token() {
		$token = wp_generate_password( 40, false, false );
		update_option( 'gas_automated_payout_token', $token );
		return $token;
	}
}
