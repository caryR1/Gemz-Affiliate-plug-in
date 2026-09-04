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
		);
	}

	public static function all() {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
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
