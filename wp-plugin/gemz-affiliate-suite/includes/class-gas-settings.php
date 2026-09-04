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
			// Multi-tier recruiting: an affiliate who recruits another
			// affiliate (their "sponsor") earns an override on that
			// recruit's sales, and again (smaller) on sales made by
			// whoever the recruit themselves later recruits. These are a
			// percent of gross commission, additive on top of the direct
			// affiliate's own cut — recruiting never reduces what a
			// sponsored affiliate earns on their own sales.
			'tier2_override_percent'   => 10,
			'tier3_override_percent'   => 5,
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
