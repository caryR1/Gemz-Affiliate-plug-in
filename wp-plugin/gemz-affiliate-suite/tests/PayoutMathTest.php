<?php

use PHPUnit\Framework\TestCase;

/**
 * Covers GAS_Payouts::agent_pool_amount() and the tier-split arithmetic in
 * GAS_Payouts::compute() — the exact code where a wrong formula silently
 * over/underpays a real affiliate. Several cases below are pinned to the
 * real, already-verified Go Solar Power numbers (see
 * gemz-solar-main-partner-gosolarpower memory / SWAP-with-HOMES.md): $2,000
 * flat payout, $700 agent pool, default 70/20/10 tier split -> $490/$140/$70
 * per tier. If these ever stop matching, either this test is wrong or a
 * real affiliate's payout just changed — treat a failure here seriously.
 */
final class PayoutMathTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['gas_test_options'] = array();
		$GLOBALS['wpdb']->codes_by_id = array();
		$GLOBALS['wpdb']->results     = array();
	}

	/* ---------------------------------------------------------------- *
	 * agent_pool_amount()
	 * ---------------------------------------------------------------- */

	public function test_flat_pool_is_used_as_is_when_under_gross(): void {
		$partner = (object) array( 'agent_pool_type' => 'flat', 'agent_pool_value' => 700 );
		$this->assertSame( 700.0, GAS_Payouts::agent_pool_amount( $partner, 2000 ) );
	}

	public function test_flat_pool_is_capped_at_gross_so_it_can_never_exceed_the_sale(): void {
		$partner = (object) array( 'agent_pool_type' => 'flat', 'agent_pool_value' => 700 );
		// PHP's min() returns whichever operand is smaller WITHOUT casting
		// its type — since $gross (500) is passed in as a plain int here,
		// the real return value is the int 500, not a float. assertSame()
		// checks type as well as value, so it fails on that alone even
		// though the number is exactly right; assertEqualsWithDelta()
		// (or assertEquals()) is the correct comparison here, not evidence
		// of a math bug. Confirmed by an actual PHPUnit run over SSH,
		// 2026-09-08 (see SWAP-with-HOMES.md) — this file's original
		// assertSame() calls had never been executed before that.
		$this->assertEqualsWithDelta( 500.0, GAS_Payouts::agent_pool_amount( $partner, 500 ), 0.0001 );
	}

	public function test_percent_pool_is_a_percentage_of_gross(): void {
		$partner = (object) array( 'agent_pool_type' => 'percent', 'agent_pool_value' => 35 );
		$this->assertSame( 700.0, GAS_Payouts::agent_pool_amount( $partner, 2000 ) );
	}

	public function test_partner_with_no_pool_configured_defaults_to_100_percent_of_gross(): void {
		// No agent_pool_type/agent_pool_value at all — existing partners
		// from before this field existed must behave exactly as before.
		// Same int-vs-float subtlety as the test above: the unset-value
		// fallback in agent_pool_amount() is the int literal 100 (not
		// cast to float), and 100/100 is an exact int division in PHP —
		// so this can come back as an int rather than a float depending
		// on $gross's own type. Not a math bug, just the wrong assertion.
		$partner = (object) array();
		$this->assertEqualsWithDelta( 2000.0, GAS_Payouts::agent_pool_amount( $partner, 2000 ), 0.0001 );
	}

	/* ---------------------------------------------------------------- *
	 * compute() — flat payout, tier splits
	 * ---------------------------------------------------------------- */

	private function goSolarPowerPartner(): object {
		return (object) array(
			'payout_type'          => 'flat',
			'payout_amount'        => 2000,
			'payout_percent'       => null,
			'installments_json'    => null,
			'agent_pool_type'      => 'flat',
			'agent_pool_value'     => 700,
			'cashback_type'        => null,
			'cashback_value'       => 0,
		);
	}

	public function test_lone_affiliate_with_no_sponsor_gets_only_tier1(): void {
		$partner = $this->goSolarPowerPartner();
		$code    = (object) array( 'sponsor_code_id' => null );

		$result = GAS_Payouts::compute( $partner, $code, 0 );

		$this->assertSame( 2000.0, $result['gross'] );
		$this->assertSame( 700.0, $result['agent_pool'] );
		$this->assertSame( 490.0, $result['tier1_amount'], 'tier1 = 700 * 70% default split' );
		$this->assertSame( 0.0, $result['tier2_amount'], 'no sponsor -> tier2 share stays with the house, not redistributed' );
		$this->assertSame( 0.0, $result['tier3_amount'] );
		$this->assertNull( $result['tier2_code'] );
		$this->assertSame( 1510.0, $result['net'], '2000 gross - 490 tier1, nothing else deducted' );
	}

	public function test_two_tier_chain_pays_tier1_and_tier2_only(): void {
		$partner = $this->goSolarPowerPartner();
		$code    = (object) array( 'sponsor_code_id' => 5 );
		$GLOBALS['wpdb']->codes_by_id[5] = (object) array( 'id' => 5, 'sponsor_code_id' => null );

		$result = GAS_Payouts::compute( $partner, $code, 0 );

		$this->assertSame( 490.0, $result['tier1_amount'] );
		$this->assertSame( 140.0, $result['tier2_amount'], 'tier2 = 700 * 20% default split' );
		$this->assertSame( 0.0, $result['tier3_amount'], 'sponsor has no sponsor of their own -> tier3 stays with the house' );
		$this->assertSame( 1370.0, $result['net'] );
	}

	public function test_full_three_tier_chain_pays_all_three_tiers(): void {
		$partner = $this->goSolarPowerPartner();
		$code    = (object) array( 'sponsor_code_id' => 5 );
		$GLOBALS['wpdb']->codes_by_id[5] = (object) array( 'id' => 5, 'sponsor_code_id' => 9 );
		$GLOBALS['wpdb']->codes_by_id[9] = (object) array( 'id' => 9, 'sponsor_code_id' => null );

		$result = GAS_Payouts::compute( $partner, $code, 0 );

		$this->assertSame( 490.0, $result['tier1_amount'] );
		$this->assertSame( 140.0, $result['tier2_amount'] );
		$this->assertSame( 70.0, $result['tier3_amount'], 'tier3 = 700 * 10% default split' );
		$this->assertSame( 1300.0, $result['net'], '2000 - 490 - 140 - 70' );
	}

	public function test_total_payout_never_grows_with_chain_depth(): void {
		// The pool is fixed and divided, not additive — adding tiers moves
		// money from net_to_cary to the sponsors, it never inflates the
		// total paid out beyond the agent pool itself.
		$partner = $this->goSolarPowerPartner();

		$lone   = GAS_Payouts::compute( $partner, (object) array( 'sponsor_code_id' => null ), 0 );
		$GLOBALS['wpdb']->codes_by_id[5] = (object) array( 'id' => 5, 'sponsor_code_id' => 9 );
		$GLOBALS['wpdb']->codes_by_id[9] = (object) array( 'id' => 9, 'sponsor_code_id' => null );
		$chained = GAS_Payouts::compute( $partner, (object) array( 'sponsor_code_id' => 5 ), 0 );

		$lone_total_paid    = $lone['tier1_amount'] + $lone['tier2_amount'] + $lone['tier3_amount'];
		$chained_total_paid = $chained['tier1_amount'] + $chained['tier2_amount'] + $chained['tier3_amount'];

		$this->assertSame( 490.0, $lone_total_paid );
		$this->assertSame( 700.0, $chained_total_paid, 'full chain pays out the entire agent pool, never more' );
		$this->assertLessThanOrEqual( $partner->agent_pool_value, $chained_total_paid );
	}

	/* ---------------------------------------------------------------- *
	 * compute() — cashback, percent payouts, installments
	 * ---------------------------------------------------------------- */

	public function test_flat_cashback_is_deducted_from_gross_before_net(): void {
		$partner = $this->goSolarPowerPartner();
		$partner->cashback_type  = 'flat';
		$partner->cashback_value = 50;
		$code = (object) array( 'sponsor_code_id' => null );

		$result = GAS_Payouts::compute( $partner, $code, 0 );

		$this->assertSame( 50.0, $result['cashback'] );
		$this->assertSame( 1460.0, $result['net'], '2000 - 50 cashback - 490 tier1' );
	}

	public function test_percent_cashback_is_a_percentage_of_gross_not_agent_pool(): void {
		$partner = $this->goSolarPowerPartner();
		$partner->cashback_type  = 'percent';
		$partner->cashback_value = 5; // 5% of the $2000 gross, not of the $700 pool
		$code = (object) array( 'sponsor_code_id' => null );

		$result = GAS_Payouts::compute( $partner, $code, 0 );

		// Not rounded in production code (unlike the tier amounts), so
		// compare with a tolerance rather than exact float equality.
		$this->assertEqualsWithDelta( 100.0, $result['cashback'], 0.0001 );
	}

	public function test_percent_payout_uses_sale_amount(): void {
		$partner = (object) array(
			'payout_type'       => 'percent',
			'payout_percent'    => 8,
			'installments_json' => null,
			'agent_pool_type'   => 'percent',
			'agent_pool_value'  => 100,
			'cashback_type'     => null,
			'cashback_value'    => 0,
		);
		$code = (object) array( 'sponsor_code_id' => null );

		$result = GAS_Payouts::compute( $partner, $code, 25000 );

		$this->assertEqualsWithDelta( 2000.0, $result['gross'], 0.0001, '25000 * 8%' );
		$this->assertSame( 1400.0, $result['tier1_amount'], '2000 agent pool (100%) * 70% default split' );
	}

	public function test_installment_payout_uses_the_selected_fraction_and_label(): void {
		$partner = (object) array(
			'payout_type'       => 'percent',
			'payout_percent'    => 8,
			'installments_json' => json_encode( array(
				array( 'label' => 'Contract signed', 'fraction' => 0.5 ),
				array( 'label' => 'Job complete', 'fraction' => 0.5 ),
			) ),
			'agent_pool_type'   => 'percent',
			'agent_pool_value'  => 100,
			'cashback_type'     => null,
			'cashback_value'    => 0,
		);
		$code = (object) array( 'sponsor_code_id' => null );

		$result = GAS_Payouts::compute( $partner, $code, 25000, 0 );

		$this->assertEqualsWithDelta( 1000.0, $result['gross'], 0.0001, '25000 * 8% * 50% (first installment)' );
		$this->assertSame( 'Contract signed', $result['installment_label'] );
	}

	public function test_custom_tier_split_percentages_are_honored(): void {
		$GLOBALS['gas_test_options']['gas_settings'] = array(
			'tier1_split_percent' => 50,
			'tier2_split_percent' => 30,
			'tier3_split_percent' => 20,
		);
		$partner = $this->goSolarPowerPartner();
		$code    = (object) array( 'sponsor_code_id' => 5 );
		$GLOBALS['wpdb']->codes_by_id[5] = (object) array( 'id' => 5, 'sponsor_code_id' => null );

		$result = GAS_Payouts::compute( $partner, $code, 0 );

		$this->assertSame( 350.0, $result['tier1_amount'], '700 * 50%' );
		$this->assertSame( 210.0, $result['tier2_amount'], '700 * 30%' );
	}
}
