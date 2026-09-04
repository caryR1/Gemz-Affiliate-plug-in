<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared helpers for an affiliate's payout method/banking details and their
 * unpaid/paid ledger totals. Banking details are stored as user meta and are
 * only ever written by the affiliate themselves from their own dashboard
 * (GAS_Frontend::handle_save_payment_info) — nothing in the admin screens
 * accepts or writes these fields, so there's no way to reintroduce
 * admin-editable banking info by tampering with a form.
 */
class GAS_Payouts {

	const META_METHOD         = 'gas_payout_method'; // 'paypal' | 'wise' | 'other'
	const META_PAYPAL_EMAIL   = 'gas_paypal_email';
	const META_WISE_NAME      = 'gas_wise_account_name';
	const META_WISE_CURRENCY  = 'gas_wise_currency';
	const META_WISE_TRANSFER  = 'gas_wise_transfer_type'; // 'aba' | 'iban'
	const META_WISE_ACCOUNT   = 'gas_wise_account_number'; // ABA account number, or the IBAN
	const META_WISE_ROUTING   = 'gas_wise_routing_number'; // ABA only
	const META_NOTES          = 'gas_payment_notes'; // free text, used when method = 'other'

	public static function get_details( $user_id ) {
		return array(
			'method'          => get_user_meta( $user_id, self::META_METHOD, true ) ?: '',
			'paypal_email'    => get_user_meta( $user_id, self::META_PAYPAL_EMAIL, true ),
			'wise_name'       => get_user_meta( $user_id, self::META_WISE_NAME, true ),
			'wise_currency'   => get_user_meta( $user_id, self::META_WISE_CURRENCY, true ),
			'wise_transfer'   => get_user_meta( $user_id, self::META_WISE_TRANSFER, true ),
			'wise_account'    => get_user_meta( $user_id, self::META_WISE_ACCOUNT, true ),
			'wise_routing'    => get_user_meta( $user_id, self::META_WISE_ROUTING, true ),
			'notes'           => get_user_meta( $user_id, self::META_NOTES, true ),
		);
	}

	/**
	 * Called only from the affiliate's own dashboard form handler — never
	 * from an admin screen.
	 */
	public static function save_details( $user_id, array $post ) {
		$method = isset( $post['payout_method'] ) && in_array( $post['payout_method'], array( 'paypal', 'wise', 'other' ), true )
			? $post['payout_method']
			: '';

		update_user_meta( $user_id, self::META_METHOD, $method );
		update_user_meta( $user_id, self::META_PAYPAL_EMAIL, isset( $post['paypal_email'] ) ? sanitize_email( wp_unslash( $post['paypal_email'] ) ) : '' );
		update_user_meta( $user_id, self::META_WISE_NAME, isset( $post['wise_account_name'] ) ? sanitize_text_field( wp_unslash( $post['wise_account_name'] ) ) : '' );
		update_user_meta( $user_id, self::META_WISE_CURRENCY, isset( $post['wise_currency'] ) ? strtoupper( sanitize_text_field( wp_unslash( $post['wise_currency'] ) ) ) : '' );
		$transfer = isset( $post['wise_transfer_type'] ) && in_array( $post['wise_transfer_type'], array( 'aba', 'iban' ), true ) ? $post['wise_transfer_type'] : '';
		update_user_meta( $user_id, self::META_WISE_TRANSFER, $transfer );
		update_user_meta( $user_id, self::META_WISE_ACCOUNT, isset( $post['wise_account_number'] ) ? sanitize_text_field( wp_unslash( $post['wise_account_number'] ) ) : '' );
		update_user_meta( $user_id, self::META_WISE_ROUTING, isset( $post['wise_routing_number'] ) ? sanitize_text_field( wp_unslash( $post['wise_routing_number'] ) ) : '' );
		update_user_meta( $user_id, self::META_NOTES, isset( $post['payment_notes'] ) ? sanitize_textarea_field( wp_unslash( $post['payment_notes'] ) ) : '' );
	}

	/**
	 * Admin-facing, read-only: never exposes a full account number/IBAN or
	 * email, just enough to see that something is set and spot-check it.
	 */
	public static function masked_summary( $user_id ) {
		$d = self::get_details( $user_id );

		if ( 'paypal' === $d['method'] && $d['paypal_email'] ) {
			return 'PayPal: ' . self::mask_email( $d['paypal_email'] );
		}
		if ( 'wise' === $d['method'] && $d['wise_account'] ) {
			$last4 = substr( preg_replace( '/\s+/', '', $d['wise_account'] ), -4 );
			return 'Wise: ****' . $last4 . ( $d['wise_currency'] ? ' (' . $d['wise_currency'] . ')' : '' );
		}
		if ( 'other' === $d['method'] && $d['notes'] ) {
			return $d['notes'];
		}
		return '';
	}

	private static function mask_email( $email ) {
		$parts = explode( '@', $email );
		if ( 2 !== count( $parts ) ) {
			return '***';
		}
		$local = $parts[0];
		$shown = substr( $local, 0, 1 );
		return $shown . '***@' . $parts[1];
	}

	/**
	 * Unpaid + paid totals for one affiliate. Combines three income
	 * sources that can each land on a different payout row: their own
	 * direct sub-affiliate cut (tracked by that row's shared `status`),
	 * and any tier-2/tier-3 sponsor overrides earned on OTHER people's
	 * sales (tracked independently via tier2_paid/tier3_paid, since a
	 * payout row's direct affiliate and its sponsor(s) are different
	 * people who get paid on their own schedules).
	 */
	public static function totals_for_affiliate( $user_id ) {
		global $wpdb;
		$payouts_table = GAS_DB::table( 'payouts' );
		$codes_table   = GAS_DB::table( 'codes' );

		$direct = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN pay.status = 'unpaid' THEN pay.subaffiliate_cut ELSE 0 END), 0) AS unpaid,
					COALESCE(SUM(CASE WHEN pay.status = 'paid' THEN pay.subaffiliate_cut ELSE 0 END), 0) AS paid
				 FROM {$payouts_table} pay
				 INNER JOIN {$codes_table} c ON c.id = pay.code_id
				 WHERE c.wp_user_id = %d",
				$user_id
			)
		);

		$tier2 = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN pay.tier2_paid = 0 THEN pay.tier2_amount ELSE 0 END), 0) AS unpaid,
					COALESCE(SUM(CASE WHEN pay.tier2_paid = 1 THEN pay.tier2_amount ELSE 0 END), 0) AS paid
				 FROM {$payouts_table} pay
				 INNER JOIN {$codes_table} c ON c.id = pay.tier2_code_id
				 WHERE c.wp_user_id = %d",
				$user_id
			)
		);

		$tier3 = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COALESCE(SUM(CASE WHEN pay.tier3_paid = 0 THEN pay.tier3_amount ELSE 0 END), 0) AS unpaid,
					COALESCE(SUM(CASE WHEN pay.tier3_paid = 1 THEN pay.tier3_amount ELSE 0 END), 0) AS paid
				 FROM {$payouts_table} pay
				 INNER JOIN {$codes_table} c ON c.id = pay.tier3_code_id
				 WHERE c.wp_user_id = %d",
				$user_id
			)
		);

		$unpaid = ( $direct ? (float) $direct->unpaid : 0.0 ) + ( $tier2 ? (float) $tier2->unpaid : 0.0 ) + ( $tier3 ? (float) $tier3->unpaid : 0.0 );
		$paid   = ( $direct ? (float) $direct->paid : 0.0 ) + ( $tier2 ? (float) $tier2->paid : 0.0 ) + ( $tier3 ? (float) $tier3->paid : 0.0 );

		return array(
			'unpaid'         => $unpaid,
			'paid'           => $paid,
			'override_unpaid' => ( $tier2 ? (float) $tier2->unpaid : 0.0 ) + ( $tier3 ? (float) $tier3->unpaid : 0.0 ),
			'override_paid'   => ( $tier2 ? (float) $tier2->paid : 0.0 ) + ( $tier3 ? (float) $tier3->paid : 0.0 ),
		);
	}

	/**
	 * Every affiliate (distinct wp_user_id on gas_codes) whose payout method
	 * is $method and who currently has an unpaid balance greater than zero.
	 * Returns rows shaped for the payout processors: user_id, unpaid amount,
	 * and their banking details.
	 */
	public static function affiliates_with_unpaid_balance( $method ) {
		global $wpdb;
		$codes_table = GAS_DB::table( 'codes' );

		$user_ids = $wpdb->get_col( "SELECT DISTINCT wp_user_id FROM {$codes_table} WHERE wp_user_id IS NOT NULL" );

		$eligible = array();
		foreach ( $user_ids as $user_id ) {
			$user_id = (int) $user_id;
			$details = self::get_details( $user_id );
			if ( $details['method'] !== $method ) {
				continue;
			}
			$totals = self::totals_for_affiliate( $user_id );
			if ( $totals['unpaid'] <= 0 ) {
				continue;
			}
			$eligible[] = array(
				'user_id' => $user_id,
				'unpaid'  => $totals['unpaid'],
				'details' => $details,
			);
		}
		return $eligible;
	}

	/**
	 * Marks this affiliate's currently-unpaid money as paid — their own
	 * direct sub-affiliate cut, and separately any tier-2/tier-3 sponsor
	 * overrides they're owed on other people's sales, since those live on
	 * payout rows that may belong to a different affiliate entirely and
	 * whose own direct-cut payment status must not be touched by this.
	 * Only called after a payment API has confirmed the money is on its
	 * way — never speculatively.
	 */
	public static function mark_affiliate_paid( $user_id ) {
		global $wpdb;
		$payouts_table = GAS_DB::table( 'payouts' );
		$codes_table   = GAS_DB::table( 'codes' );
		$now           = current_time( 'mysql' );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$payouts_table}
				 SET status = 'paid', paid_at = %s
				 WHERE status = 'unpaid'
				 AND code_id IN ( SELECT id FROM {$codes_table} WHERE wp_user_id = %d )",
				$now,
				$user_id
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$payouts_table}
				 SET tier2_paid = 1
				 WHERE tier2_paid = 0
				 AND tier2_code_id IN ( SELECT id FROM {$codes_table} WHERE wp_user_id = %d )",
				$user_id
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$payouts_table}
				 SET tier3_paid = 1
				 WHERE tier3_paid = 0
				 AND tier3_code_id IN ( SELECT id FROM {$codes_table} WHERE wp_user_id = %d )",
				$user_id
			)
		);
	}
}
