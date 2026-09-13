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

	// Tax compliance fields (2026-09-08) — same storage pattern as banking
	// info above: only ever written by the affiliate themselves from their
	// own dashboard, never admin-editable. Cary's call: require this on
	// file before ANY payout goes out, not gated at the $600/year IRS
	// reporting threshold — simpler than tracking a running per-affiliate
	// total against a threshold, and avoids a partial-year edge case
	// (e.g. an affiliate who crosses $600 mid-batch-run). Stored as plain
	// user meta, same as the bank account numbers already stored this way
	// above — worth flagging as a fragility item if this program ever
	// handles enough volume to justify encrypting these fields at rest;
	// not done in this pass to stay consistent with the existing pattern.
	const META_TAX_FORM_TYPE     = 'gas_tax_form_type'; // 'w9' | 'w8ben'
	const META_TAX_LEGAL_NAME    = 'gas_tax_legal_name';
	const META_TAX_ID            = 'gas_tax_id'; // SSN/EIN (W-9) or foreign TIN (W-8BEN, optional there)
	const META_TAX_COUNTRY       = 'gas_tax_country';
	const META_TAX_SUBMITTED_AT  = 'gas_tax_submitted_at';

	// Recorded at signup (2026-09-09) purely as an identity signal for the
	// tier-stacking check below — never shown to anyone, never used for
	// rate-limiting itself (GAS_Fraud's transient-based signup limiter
	// already covers that).
	const META_SIGNUP_IP = 'gas_signup_ip';

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

	public static function get_tax_info( $user_id ) {
		return array(
			'form_type'    => get_user_meta( $user_id, self::META_TAX_FORM_TYPE, true ) ?: '',
			'legal_name'   => get_user_meta( $user_id, self::META_TAX_LEGAL_NAME, true ),
			'tax_id'       => get_user_meta( $user_id, self::META_TAX_ID, true ),
			'country'      => get_user_meta( $user_id, self::META_TAX_COUNTRY, true ),
			'submitted_at' => get_user_meta( $user_id, self::META_TAX_SUBMITTED_AT, true ),
		);
	}

	/**
	 * Called only from the affiliate's own dashboard form handler — never
	 * from an admin screen, same rule as save_details() above. A W-9 needs
	 * a real US tax ID (SSN/EIN); a W-8BEN is for a non-US person and its
	 * foreign tax ID is commonly not applicable, so it's the one optional
	 * field — everything else is required for either form type.
	 */
	public static function save_tax_info( $user_id, array $post ) {
		$form_type = isset( $post['tax_form_type'] ) && in_array( $post['tax_form_type'], array( 'w9', 'w8ben' ), true )
			? $post['tax_form_type']
			: '';
		$legal_name = isset( $post['tax_legal_name'] ) ? sanitize_text_field( wp_unslash( $post['tax_legal_name'] ) ) : '';
		$tax_id     = isset( $post['tax_id'] ) ? sanitize_text_field( wp_unslash( $post['tax_id'] ) ) : '';
		$country    = isset( $post['tax_country'] ) ? sanitize_text_field( wp_unslash( $post['tax_country'] ) ) : '';

		if ( '' === $form_type || '' === $legal_name || '' === $country ) {
			return false;
		}
		if ( 'w9' === $form_type && '' === $tax_id ) {
			return false; // required for a W-9; optional for a W-8BEN
		}

		update_user_meta( $user_id, self::META_TAX_FORM_TYPE, $form_type );
		update_user_meta( $user_id, self::META_TAX_LEGAL_NAME, $legal_name );
		update_user_meta( $user_id, self::META_TAX_ID, $tax_id );
		update_user_meta( $user_id, self::META_TAX_COUNTRY, $country );
		update_user_meta( $user_id, self::META_TAX_SUBMITTED_AT, current_time( 'mysql' ) );
		return true;
	}

	public static function has_tax_info_on_file( $user_id ) {
		return (bool) get_user_meta( $user_id, self::META_TAX_SUBMITTED_AT, true );
	}

	/**
	 * Admin-facing, read-only summary — never the raw tax ID, same masking
	 * spirit as masked_summary() above for banking info.
	 */
	public static function masked_tax_summary( $user_id ) {
		$t = self::get_tax_info( $user_id );
		if ( ! $t['submitted_at'] ) {
			return '';
		}
		$label = 'w9' === $t['form_type'] ? 'W-9' : 'W-8BEN';
		return $label . ' on file (' . $t['submitted_at'] . ')';
	}

	/**
	 * Sum of everything actually PAID to this PERSON (their own direct
	 * affiliate cut, any tier-2/3 overrides they're owed, AND any buyer
	 * cash back paid to their email) within the current calendar year —
	 * the figure that matters for 1099 purposes, which is based on amounts
	 * paid during the tax year, not amounts earned/entered. Uses paid_at
	 * (when the money actually moved), not entered_at.
	 *
	 * Cashback aggregation added 2026-09-09 alongside allowing
	 * self-referral (see SWAP-with-HOMES.md): the same person can now
	 * legitimately receive both customer cashback and affiliate
	 * commission, so the $600/year threshold has to be tracked as ONE
	 * combined total per person — two separate buckets that could each
	 * individually stay under $600 while the real total crosses it would
	 * be a real (not theoretical) tax-reporting gap. Matched by email,
	 * case-insensitively, since a customer receiving cashback has no WP
	 * user id to join on — email is the only identifier the affiliate
	 * account and the cashback payout row are guaranteed to share.
	 */
	public static function paid_this_calendar_year( $user_id, $year = null ) {
		global $wpdb;
		$payouts_table = GAS_DB::table( 'payouts' );
		$codes_table   = GAS_DB::table( 'codes' );
		$year          = $year ?: (int) current_time( 'Y' );
		$year_start    = "{$year}-01-01 00:00:00";
		$year_end      = ( $year + 1 ) . '-01-01 00:00:00';

		$direct = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(pay.subaffiliate_cut),0) FROM {$payouts_table} pay
			 INNER JOIN {$codes_table} c ON c.id = pay.code_id
			 WHERE c.wp_user_id = %d AND pay.status = 'paid' AND pay.paid_at >= %s AND pay.paid_at < %s",
			$user_id, $year_start, $year_end
		) );
		$tier2 = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(pay.tier2_amount),0) FROM {$payouts_table} pay
			 INNER JOIN {$codes_table} c ON c.id = pay.tier2_code_id
			 WHERE c.wp_user_id = %d AND pay.tier2_paid = 1 AND pay.tier2_paid_at >= %s AND pay.tier2_paid_at < %s",
			$user_id, $year_start, $year_end
		) );
		$tier3 = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(pay.tier3_amount),0) FROM {$payouts_table} pay
			 INNER JOIN {$codes_table} c ON c.id = pay.tier3_code_id
			 WHERE c.wp_user_id = %d AND pay.tier3_paid = 1 AND pay.tier3_paid_at >= %s AND pay.tier3_paid_at < %s",
			$user_id, $year_start, $year_end
		) );

		$cashback = 0.0;
		$user     = get_userdata( $user_id );
		if ( $user && $user->user_email ) {
			$cashback = (float) $wpdb->get_var( $wpdb->prepare(
				"SELECT COALESCE(SUM(cashback_amount),0) FROM {$payouts_table}
				 WHERE LOWER(customer_email) = %s AND cashback_paid = 1 AND cashback_paid_at >= %s AND cashback_paid_at < %s",
				strtolower( $user->user_email ), $year_start, $year_end
			) );
		}

		return $direct + $tier2 + $tier3 + $cashback;
	}

	/**
	 * Public (not private) since GAS_Cashback also masks a customer's
	 * cashback payment email using the exact same convention.
	 */
	public static function mask_email( $email ) {
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
	 * The slice of a sale's gross commission that's actually divided among
	 * affiliate tiers — can be less than the full gross, with the remainder
	 * kept as house margin (net_to_cary absorbs the gap automatically,
	 * since it's already computed as gross minus every other deduction).
	 * Flat: a fixed dollar amount, capped at gross so a misconfigured pool
	 * can never exceed what was actually earned on the sale. Percent: that
	 * percentage of the gross commission. Defaults to 100% of gross when a
	 * partner has no pool configured, so existing partners behave exactly
	 * as before this field existed.
	 */
	/**
	 * Rounds a dollar figure UP to the nearest $10 (2026-09-13, Cary's
	 * ask — "we need everything to be uniform") — every tier payout, real
	 * or displayed, should land on a clean multiple of ten, never cents
	 * or an odd amount. This is the single canonical implementation;
	 * GAS_Frontend's display-side estimate functions call this one
	 * instead of duplicating it, so a future change to the rounding rule
	 * only has to happen here.
	 *
	 * Known tradeoff, accepted by Cary: rounding every tier UP means the
	 * total actually disbursed on a sale can exceed the exact 70/20/10
	 * math would give — the house absorbs that extra few dollars as a
	 * cost of clean numbers (see net's definition below, which already
	 * absorbs it automatically). On a partner with a thin agent_pool
	 * relative to gross, this could in theory push net_to_cary quite low
	 * or negative — worth a sanity check on any new partner's numbers,
	 * not just trusting the split percentages alone.
	 */
	public static function round_up_to_ten( $amount ) {
		return ceil( $amount / 10 ) * 10;
	}

	public static function agent_pool_amount( $partner, $gross ) {
		$type  = isset( $partner->agent_pool_type ) ? $partner->agent_pool_type : 'percent';
		$value = isset( $partner->agent_pool_value ) && '' !== $partner->agent_pool_value ? (float) $partner->agent_pool_value : 100;

		if ( 'flat' === $type ) {
			return min( $value, $gross );
		}
		return $gross * ( $value / 100 );
	}

	/**
	 * Core commission math for one sale against one code+partner — the
	 * single source of truth, used by both the admin Payout Calculator and
	 * the REST payout-creation endpoint, so this is never computed two
	 * different ways in two different places.
	 */
	public static function compute( $partner, $code, $sale_amount, $installment_index = null ) {
		global $wpdb;

		$installment_label = null;
		if ( 'flat' === $partner->payout_type ) {
			$gross = (float) $partner->payout_amount;
		} else {
			$installments = $partner->installments_json ? json_decode( $partner->installments_json, true ) : array();
			if ( $installments && null !== $installment_index && isset( $installments[ $installment_index ] ) ) {
				$fraction           = (float) $installments[ $installment_index ]['fraction'];
				$installment_label = $installments[ $installment_index ]['label'];
				$gross              = $sale_amount * ( (float) $partner->payout_percent / 100 ) * $fraction;
			} else {
				$gross = $sale_amount * ( (float) $partner->payout_percent / 100 );
			}
		}

		// Buyer cash back comes out of the gross commission independently
		// of the tier split below — a separate deduction, not part of the
		// pool that gets divided among tiers.
		$cashback = 0.0;
		if ( $partner->cashback_type ) {
			$cashback = 'flat' === $partner->cashback_type
				? (float) $partner->cashback_value
				: $gross * ( (float) $partner->cashback_value / 100 );
		}

		$agent_pool = self::agent_pool_amount( $partner, $gross );

		// Multi-tier recruiting commissions: FIXED pooled split, not
		// additive, and applied against the agent pool (which may be less
		// than full gross) rather than gross itself — the same split for
		// every affiliate, never individually negotiated per code. Total
		// payout never grows with chain depth: a tier with no one in it
		// simply isn't paid to anyone — that share stays with the house.
		$codes_table = GAS_DB::table( 'codes' );
		$tier2_code  = $code->sponsor_code_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$codes_table} WHERE id = %d", $code->sponsor_code_id ) ) : null;
		$tier3_code  = $tier2_code && $tier2_code->sponsor_code_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$codes_table} WHERE id = %d", $tier2_code->sponsor_code_id ) ) : null;

		$tier1_pct = (float) GAS_Settings::get( 'tier1_split_percent' );
		$tier2_pct = (float) GAS_Settings::get( 'tier2_split_percent' );
		$tier3_pct = (float) GAS_Settings::get( 'tier3_split_percent' );

		$tier1_amount = self::round_up_to_ten( $agent_pool * ( $tier1_pct / 100 ) );
		$tier2_amount = $tier2_code ? self::round_up_to_ten( $agent_pool * ( $tier2_pct / 100 ) ) : 0.0;
		$tier3_amount = $tier3_code ? self::round_up_to_ten( $agent_pool * ( $tier3_pct / 100 ) ) : 0.0;

		$net = $gross - $cashback - $tier1_amount - $tier2_amount - $tier3_amount;

		return array(
			'gross'             => $gross,
			'agent_pool'        => $agent_pool,
			'cashback'          => $cashback,
			'installment_label' => $installment_label,
			'tier1_amount'      => $tier1_amount,
			'tier1_pct'         => $tier1_pct,
			'tier2_amount'      => $tier2_amount,
			'tier2_pct'         => $tier2_pct,
			'tier2_code'        => $tier2_code,
			'tier3_amount'      => $tier3_amount,
			'tier3_pct'         => $tier3_pct,
			'tier3_code'        => $tier3_code,
			'net'               => $net,
			'tier_stacking'     => self::tier_stacking_signals( $code, $tier2_code, $tier3_code ),
		);
	}

	/**
	 * Lightweight, non-blocking fraud signal added 2026-09-09 alongside
	 * allowing self-referral (Cary's call — see SWAP-with-HOMES.md): since
	 * one real person being both affiliate and customer on their own sale
	 * is now fine (fixed pool, no cost to the partner), the actual risk
	 * worth watching for is narrower — a SOCKPUPPET second account
	 * stacking an extra tier-2/3 on top of what's really one person's one
	 * transaction. Cary explicitly judged this low-probability and asked
	 * for a flag an admin can review, not a block that could stall a real
	 * payout over a coincidence (e.g. two genuinely different people who
	 * happen to share a household PayPal account).
	 *
	 * Compares each PRESENT pair among tier1/tier2/tier3 codes that belong
	 * to two DIFFERENT wp_user_id's, looking for any shared identity
	 * signal: payout email (WP account email), PayPal email, Wise account
	 * number, tax ID, or signup IP. Returns an array of matches (empty if
	 * none) — never blocks anything itself, the caller decides whether to
	 * audit-log it.
	 */
	private static function tier_stacking_signals( $code, $tier2_code, $tier3_code ) {
		$candidates = array(
			'tier1' => $code,
			'tier2' => $tier2_code,
			'tier3' => $tier3_code,
		);

		$fingerprints = array();
		foreach ( $candidates as $label => $c ) {
			if ( ! $c || empty( $c->wp_user_id ) ) {
				continue;
			}
			$user_id = (int) $c->wp_user_id;
			$user    = get_userdata( $user_id );
			$details = self::get_details( $user_id );
			$tax     = self::get_tax_info( $user_id );

			$fingerprints[ $label ] = array(
				'user_id'      => $user_id,
				'email'        => $user ? strtolower( $user->user_email ) : '',
				'paypal_email' => $details['paypal_email'] ? strtolower( $details['paypal_email'] ) : '',
				'wise_account' => $details['wise_account'] ? preg_replace( '/\s+/', '', $details['wise_account'] ) : '',
				'tax_id'       => $tax['tax_id'] ?: '',
				'signup_ip'    => get_user_meta( $user_id, self::META_SIGNUP_IP, true ),
			);
		}

		$signals = array( 'email', 'paypal_email', 'wise_account', 'tax_id', 'signup_ip' );
		$labels  = array_keys( $fingerprints );
		$matches = array();

		for ( $i = 0; $i < count( $labels ); $i++ ) {
			for ( $j = $i + 1; $j < count( $labels ); $j++ ) {
				$a = $fingerprints[ $labels[ $i ] ];
				$b = $fingerprints[ $labels[ $j ] ];
				if ( $a['user_id'] === $b['user_id'] ) {
					continue; // same account isn't "stacking" — nothing to flag
				}
				foreach ( $signals as $signal ) {
					if ( '' !== $a[ $signal ] && $a[ $signal ] === $b[ $signal ] ) {
						$matches[] = array(
							'between' => array( $labels[ $i ], $labels[ $j ] ),
							'signal'  => $signal,
						);
					}
				}
			}
		}

		return $matches;
	}

	/**
	 * Affiliate-facing dollar range for one tier, computed across every
	 * active partner's configured payout structure — deliberately NOT
	 * personalized to one affiliate's actual sponsor chain, and never the
	 * exact per-partner numbers an admin sees, so an affiliate can't
	 * back-calculate real margins from it. Flat-payout partners contribute
	 * an exact figure; percent-of-sale partners only contribute one if
	 * `typical_sale_amount` is set (there's no single sale amount to base
	 * a percent on otherwise) — a partner with neither is simply left out
	 * of the range rather than guessed at.
	 */
	public static function tier_dollar_range( $tier_num ) {
		global $wpdb;
		$partners = $wpdb->get_results( 'SELECT * FROM ' . GAS_DB::table( 'partners' ) . " WHERE outreach_status = 'approved'" );

		$pct_key = 'tier' . absint( $tier_num ) . '_split_percent';
		$pct     = (float) GAS_Settings::get( $pct_key );

		$amounts = array();
		foreach ( $partners as $p ) {
			if ( 'flat' === $p->payout_type ) {
				$gross = (float) $p->payout_amount;
			} elseif ( $p->typical_sale_amount ) {
				$gross = (float) $p->typical_sale_amount * ( (float) $p->payout_percent / 100 );
			} else {
				continue; // no basis to estimate this partner's gross
			}
			if ( $gross > 0 ) {
				$pool      = self::agent_pool_amount( $p, $gross );
				$amounts[] = round( $pool * ( $pct / 100 ), 2 );
			}
		}

		if ( ! $amounts ) {
			return null;
		}
		return array( 'min' => min( $amounts ), 'max' => max( $amounts ) );
	}

	/**
	 * Current-month (still-open, not yet finalized) sale counts per tier
	 * for one affiliate — used with tier_dollar_range() to show a pending
	 * earnings ESTIMATE rather than the exact number, since exact tier
	 * amounts aren't shown to affiliates until the month closes.
	 */
	public static function pending_tier_counts( $user_id ) {
		global $wpdb;
		$payouts_table = GAS_DB::table( 'payouts' );
		$codes_table   = GAS_DB::table( 'codes' );
		$month_start   = gmdate( 'Y-m-01 00:00:00', current_time( 'timestamp' ) );

		$tier1 = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$payouts_table} pay INNER JOIN {$codes_table} c ON c.id = pay.code_id
			 WHERE c.wp_user_id = %d AND pay.entered_at >= %s",
			$user_id, $month_start
		) );
		$tier2 = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$payouts_table} pay INNER JOIN {$codes_table} c ON c.id = pay.tier2_code_id
			 WHERE c.wp_user_id = %d AND pay.entered_at >= %s",
			$user_id, $month_start
		) );
		$tier3 = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$payouts_table} pay INNER JOIN {$codes_table} c ON c.id = pay.tier3_code_id
			 WHERE c.wp_user_id = %d AND pay.entered_at >= %s",
			$user_id, $month_start
		) );

		return array( 1 => $tier1, 2 => $tier2, 3 => $tier3 );
	}

	/**
	 * Exact finalized totals per tier for one affiliate, for everything
	 * BEFORE the current (still-open) month — real numbers, not an
	 * estimate, since these sales are done and the amounts are exactly
	 * what's already stored on those payout rows.
	 */
	public static function finalized_tier_totals( $user_id ) {
		global $wpdb;
		$payouts_table = GAS_DB::table( 'payouts' );
		$codes_table   = GAS_DB::table( 'codes' );
		$month_start   = gmdate( 'Y-m-01 00:00:00', current_time( 'timestamp' ) );

		$tier1 = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(pay.subaffiliate_cut),0) FROM {$payouts_table} pay INNER JOIN {$codes_table} c ON c.id = pay.code_id
			 WHERE c.wp_user_id = %d AND pay.entered_at < %s",
			$user_id, $month_start
		) );
		$tier2 = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(pay.tier2_amount),0) FROM {$payouts_table} pay INNER JOIN {$codes_table} c ON c.id = pay.tier2_code_id
			 WHERE c.wp_user_id = %d AND pay.entered_at < %s",
			$user_id, $month_start
		) );
		$tier3 = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(pay.tier3_amount),0) FROM {$payouts_table} pay INNER JOIN {$codes_table} c ON c.id = pay.tier3_code_id
			 WHERE c.wp_user_id = %d AND pay.entered_at < %s",
			$user_id, $month_start
		) );

		return array( 1 => $tier1, 2 => $tier2, 3 => $tier3 );
	}

	/**
	 * Same three income sources as totals_for_affiliate(), but restricted to
	 * payout rows entered BEFORE the current (still-open) calendar month —
	 * i.e. the same "closed months only" boundary already used by
	 * pending_tier_counts()/finalized_tier_totals() for the affiliate's own
	 * dashboard display. Added 2026-09-10 specifically for
	 * affiliates_with_unpaid_balance(): a real bug existed where a payout
	 * entered on, say, the 3rd of a new month could get swept into a batch
	 * run firing on the 5th, even though that's THIS month's still-open
	 * earnings, not a closed prior month owed to the affiliate yet.
	 * Deliberately a separate method rather than changing
	 * totals_for_affiliate() itself, which an affiliate's own dashboard
	 * ("Unpaid balance") still uses to show their true full accrued total,
	 * current month included — only the money-moving payout-run path needs
	 * this stricter, closed-months-only figure.
	 */
	public static function closed_month_unpaid_balance( $user_id ) {
		global $wpdb;
		$payouts_table = GAS_DB::table( 'payouts' );
		$codes_table   = GAS_DB::table( 'codes' );
		$month_start   = gmdate( 'Y-m-01 00:00:00', current_time( 'timestamp' ) );

		$direct = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(pay.subaffiliate_cut),0) FROM {$payouts_table} pay
			 INNER JOIN {$codes_table} c ON c.id = pay.code_id
			 WHERE c.wp_user_id = %d AND pay.status = 'unpaid' AND pay.entered_at < %s",
			$user_id, $month_start
		) );
		$tier2 = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(pay.tier2_amount),0) FROM {$payouts_table} pay
			 INNER JOIN {$codes_table} c ON c.id = pay.tier2_code_id
			 WHERE c.wp_user_id = %d AND pay.tier2_paid = 0 AND pay.entered_at < %s",
			$user_id, $month_start
		) );
		$tier3 = (float) $wpdb->get_var( $wpdb->prepare(
			"SELECT COALESCE(SUM(pay.tier3_amount),0) FROM {$payouts_table} pay
			 INNER JOIN {$codes_table} c ON c.id = pay.tier3_code_id
			 WHERE c.wp_user_id = %d AND pay.tier3_paid = 0 AND pay.entered_at < %s",
			$user_id, $month_start
		) );

		return $direct + $tier2 + $tier3;
	}

	/**
	 * Every affiliate (distinct wp_user_id on gas_codes) whose payout method
	 * is $method and who has an unpaid balance, from CLOSED prior months
	 * only (see closed_month_unpaid_balance()), greater than zero. Returns
	 * rows shaped for the payout processors: user_id, unpaid amount, and
	 * their banking details. Split into two buckets, both returned,
	 * caller's choice what to do with each:
	 * - 'eligible': balance >= the minimum payout threshold AND tax info is
	 *   on file — safe to actually pay.
	 * - 'held': everyone else with a real unpaid balance who was excluded,
	 *   with a 'reason' ('below_threshold' or 'no_tax_info') so a payout
	 *   run can tell an admin exactly why someone wasn't paid, rather than
	 *   silently skipping them.
	 */
	public static function affiliates_with_unpaid_balance( $method ) {
		global $wpdb;
		$codes_table = GAS_DB::table( 'codes' );
		$min_payout  = (float) GAS_Settings::get( 'min_payout_threshold' );

		$user_ids = $wpdb->get_col( "SELECT DISTINCT wp_user_id FROM {$codes_table} WHERE wp_user_id IS NOT NULL" );

		$eligible = array();
		$held     = array();
		foreach ( $user_ids as $user_id ) {
			$user_id = (int) $user_id;
			$details = self::get_details( $user_id );
			if ( $details['method'] !== $method ) {
				continue;
			}
			$unpaid = self::closed_month_unpaid_balance( $user_id );
			if ( $unpaid <= 0 ) {
				continue;
			}

			$row = array( 'user_id' => $user_id, 'unpaid' => $unpaid, 'details' => $details );

			// Tax-info gate takes priority in the reason shown — an
			// affiliate missing both is more clearly "not ready to pay"
			// than "below threshold," and fixing the tax-info gap is the
			// more urgent of the two for the admin to notice.
			if ( ! self::has_tax_info_on_file( $user_id ) ) {
				$held[] = $row + array( 'reason' => 'no_tax_info' );
				continue;
			}
			if ( $unpaid < $min_payout ) {
				$held[] = $row + array( 'reason' => 'below_threshold' );
				continue;
			}

			$eligible[] = $row;
		}
		return array( 'eligible' => $eligible, 'held' => $held );
	}

	/**
	 * Plain-text bodies for a held-affiliate notification — added
	 * 2026-09-10 per Cary's explicit call: encouraging language only, NO
	 * specific dollar figures anywhere (not their balance, not the
	 * threshold amount) after an FTC/income-claim caution was raised.
	 * There's no notification-templating system in GAS yet (that's still
	 * unstarted "Part 3" of the original GRC port) — these are plain
	 * hardcoded wp_mail() bodies, same pattern as every other notification
	 * already in this plugin, not a new templating layer.
	 */
	private static function held_notification_copy( $reason ) {
		if ( 'no_tax_info' === $reason ) {
			return array(
				'subject' => 'Action needed before your next payout',
				'body'    => "Hi,\n\nWe went to send your commission payout this cycle, but couldn't yet — we still need your tax information on file first (a quick one-time form).\n\nAdd it from your dashboard and you'll be included automatically the next time payouts run:\n{dashboard_url}\n\nThanks for being part of the program!",
			);
		}
		if ( 'below_threshold' === $reason ) {
			return array(
				'subject' => 'Your balance is building up',
				'body'    => "Hi,\n\nYou didn't hit this cycle's payout, but nothing is lost — your balance carries forward automatically, and you'll be paid as soon as it's ready.\n\nThe fastest way to get there: keep sharing your link, and don't forget the bonus you earn from recruiting your own team. Check your dashboard for your current stats:\n{dashboard_url}\n\nThanks for being part of the program!",
			);
		}
		return null;
	}

	/**
	 * Emails each held affiliate why they weren't paid this run — a real
	 * gap before this: affiliates_with_unpaid_balance() already reported
	 * held reasons to the ADMIN in the batch-run summary, but nothing ever
	 * told the affected affiliate. Called once per actual payout run (both
	 * the manual "Pay Now" buttons and the future automated monthly run),
	 * not on every page load that merely checks eligibility — a per-user
	 * per-reason per-day transient guard prevents a double-send if an
	 * admin clicks "Pay Now" more than once in the same day.
	 */
	public static function notify_held_affiliates( array $held ) {
		foreach ( $held as $row ) {
			$copy = self::held_notification_copy( $row['reason'] );
			if ( ! $copy ) {
				continue;
			}

			$dedup_key = 'gas_held_notify_' . $row['user_id'] . '_' . $row['reason'];
			if ( get_transient( $dedup_key ) ) {
				continue;
			}

			$user = get_userdata( $row['user_id'] );
			if ( ! $user || ! $user->user_email ) {
				continue;
			}

			$body = str_replace( '{dashboard_url}', GAS_Frontend::dashboard_url(), $copy['body'] );
			wp_mail( $user->user_email, $copy['subject'], $body . GAS_Settings::compliance_footer( $user->user_email ) );

			set_transient( $dedup_key, 1, DAY_IN_SECONDS );
		}
	}

	/**
	 * Marks this affiliate's currently-unpaid money as paid — their own
	 * direct sub-affiliate cut, and separately any tier-2/tier-3 sponsor
	 * overrides they're owed on other people's sales, since those live on
	 * payout rows that may belong to a different affiliate entirely and
	 * whose own direct-cut payment status must not be touched by this.
	 * Only called after a payment API has confirmed the money is on its
	 * way — never speculatively.
	 *
	 * Restricted to CLOSED prior months (added 2026-09-10, same boundary
	 * as closed_month_unpaid_balance()) — without this, a payout entered
	 * earlier the same day a batch run fires would get marked paid even
	 * though affiliates_with_unpaid_balance() correctly excluded it from
	 * that affiliate's eligible amount, silently paying out (and marking
	 * permanently settled) still-open current-month earnings. The two
	 * callers of this method (GAS_PayPal_Payouts/GAS_Wise_Payouts) are the
	 * only places anything gets marked paid outside a manual Calculator
	 * edit, so this boundary applies unconditionally rather than as an
	 * opt-in parameter.
	 */
	public static function mark_affiliate_paid( $user_id ) {
		global $wpdb;
		$payouts_table = GAS_DB::table( 'payouts' );
		$codes_table   = GAS_DB::table( 'codes' );
		$now           = current_time( 'mysql' );
		$month_start   = gmdate( 'Y-m-01 00:00:00', current_time( 'timestamp' ) );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$payouts_table}
				 SET status = 'paid', paid_at = %s
				 WHERE status = 'unpaid'
				 AND entered_at < %s
				 AND code_id IN ( SELECT id FROM {$codes_table} WHERE wp_user_id = %d )",
				$now,
				$month_start,
				$user_id
			)
		);

		// tier2_paid_at/tier3_paid_at added 2026-09-08 — previously only the
		// direct payout's shared `paid_at` was ever set, which reflects
		// when the DIRECT (tier-1) affiliate was paid, not necessarily
		// when a sponsor's own tier-2/3 override was actually paid to
		// THEM (a different person, on their own schedule). Left the
		// original bug in place, the calendar-year tax total for a
		// sponsor would silently undercount whenever they were paid
		// before the direct affiliate on the same row was.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$payouts_table}
				 SET tier2_paid = 1, tier2_paid_at = %s
				 WHERE tier2_paid = 0
				 AND entered_at < %s
				 AND tier2_code_id IN ( SELECT id FROM {$codes_table} WHERE wp_user_id = %d )",
				$now,
				$month_start,
				$user_id
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$payouts_table}
				 SET tier3_paid = 1, tier3_paid_at = %s
				 WHERE tier3_paid = 0
				 AND entered_at < %s
				 AND tier3_code_id IN ( SELECT id FROM {$codes_table} WHERE wp_user_id = %d )",
				$now,
				$month_start,
				$user_id
			)
		);
	}
}
