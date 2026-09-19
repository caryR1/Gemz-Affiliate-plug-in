<?php

use PHPUnit\Framework\TestCase;

/**
 * Covers the round-up-to-the-nearest-$10 rule (GAS_Payouts::round_up_to_ten(),
 * used by compute() for every tier and by the display estimates). The older
 * PayoutMathTest fixtures use a $700 pool whose tiers ($490/$140/$70) are
 * already multiples of $10, so they pass with or without rounding — these
 * tests use the real current Go Solar Power pool ($425) where rounding
 * actually changes the result: 297.5 -> 300, 85 -> 90, 42.5 -> 50.
 *
 * Accepted tradeoff (Cary's decision, 2026-09-13): rounding every tier UP means
 * total paid can exceed the exact pool by a few dollars; the house absorbs it.
 */
final class RoundingTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['gas_test_options'] = array();
		$GLOBALS['wpdb']->codes_by_id = array();
		$GLOBALS['wpdb']->results     = array();
	}

	private function partnerWithPool( float $pool, float $gross = 2000 ): object {
		return (object) array(
			'payout_type'      => 'flat',
			'payout_amount'    => $gross,
			'payout_percent'   => null,
			'installments_json' => null,
			'agent_pool_type'  => 'flat',
			'agent_pool_value' => $pool,
			'cashback_type'    => null,
			'cashback_value'   => 0,
		);
	}

	private function fullChain(): object {
		$GLOBALS['wpdb']->codes_by_id[5] = (object) array( 'id' => 5, 'sponsor_code_id' => 9 );
		$GLOBALS['wpdb']->codes_by_id[9] = (object) array( 'id' => 9, 'sponsor_code_id' => null );
		return (object) array( 'sponsor_code_id' => 5 );
	}

	public function test_round_up_to_ten_basics(): void {
		$this->assertEqualsWithDelta( 0.0, GAS_Payouts::round_up_to_ten( 0 ), 0.0001 );
		$this->assertEqualsWithDelta( 10.0, GAS_Payouts::round_up_to_ten( 0.01 ), 0.0001 );
		$this->assertEqualsWithDelta( 10.0, GAS_Payouts::round_up_to_ten( 10 ), 0.0001 );
		$this->assertEqualsWithDelta( 20.0, GAS_Payouts::round_up_to_ten( 10.01 ), 0.0001 );
		$this->assertEqualsWithDelta( 300.0, GAS_Payouts::round_up_to_ten( 297.5 ), 0.0001 );
		$this->assertEqualsWithDelta( 90.0, GAS_Payouts::round_up_to_ten( 85 ), 0.0001 );
		$this->assertEqualsWithDelta( 50.0, GAS_Payouts::round_up_to_ten( 42.5 ), 0.0001 );
		$this->assertEqualsWithDelta( 490.0, GAS_Payouts::round_up_to_ten( 489.99999999999994 ), 0.0001, 'float noise just below a multiple must not round up further' );
	}

	public function test_flagship_425_lone_affiliate(): void {
		$result = GAS_Payouts::compute( $this->partnerWithPool( 425 ), (object) array( 'sponsor_code_id' => null ), 0 );

		$this->assertEqualsWithDelta( 300.0, $result['tier1_amount'], 0.0001, '425 * 70% = 297.5 -> 300' );
		$this->assertEqualsWithDelta( 0.0, $result['tier2_amount'], 0.0001, 'no sponsor -> 0, never rounded up from zero' );
		$this->assertEqualsWithDelta( 0.0, $result['tier3_amount'], 0.0001 );
		$this->assertEqualsWithDelta( 1700.0, $result['net'], 0.0001, '2000 - 300' );
	}

	public function test_flagship_425_full_three_tier_chain(): void {
		$result = GAS_Payouts::compute( $this->partnerWithPool( 425 ), $this->fullChain(), 0 );

		$this->assertEqualsWithDelta( 300.0, $result['tier1_amount'], 0.0001, '425 * 70% = 297.5 -> 300' );
		$this->assertEqualsWithDelta( 90.0, $result['tier2_amount'], 0.0001, '425 * 20% = 85 -> 90' );
		$this->assertEqualsWithDelta( 50.0, $result['tier3_amount'], 0.0001, '425 * 10% = 42.5 -> 50' );
		$this->assertEqualsWithDelta( 1560.0, $result['net'], 0.0001, '2000 - 300 - 90 - 50' );
	}

	public function test_rounding_can_exceed_the_pool_but_by_less_than_ten_per_tier(): void {
		$result = GAS_Payouts::compute( $this->partnerWithPool( 425 ), $this->fullChain(), 0 );
		$total  = $result['tier1_amount'] + $result['tier2_amount'] + $result['tier3_amount'];

		$this->assertEqualsWithDelta( 440.0, $total, 0.0001 );
		$this->assertGreaterThan( 425.0, $total, 'accepted tradeoff: house absorbs the rounding' );
		$this->assertLessThan( 425.0 + 30.0, $total, 'never more than $10 over per tier' );
	}

	public function test_display_estimate_matches_real_tier1_at_425(): void {
		$GLOBALS['wpdb']->results = array( $this->partnerWithPool( 425 ) );
		$range = GAS_Frontend::estimated_payout_range();

		$this->assertNotNull( $range );
		$this->assertEqualsWithDelta( 300.0, $range['min'], 0.0001, 'display estimate must match what compute() really pays' );
		$this->assertEqualsWithDelta( 300.0, $range['max'], 0.0001 );
	}

	/**
	 * Float-noise guard: for every whole-dollar pool that is a multiple of $10,
	 * compute()'s rounded tiers must equal exact integer arithmetic. If binary
	 * floating point ever pushed an exact multiple of $10 just above itself,
	 * ceil() would overpay an extra $10 on that tier.
	 */
	public function test_no_float_noise_overpayment_on_whole_dollar_pools(): void {
		$mismatches = array();
		foreach ( range( 10, 5000, 10 ) as $pool ) {
			$result = GAS_Payouts::compute( $this->partnerWithPool( $pool, 100000 ), $this->fullChain(), 0 );
			foreach ( array( 'tier1_amount' => 70, 'tier2_amount' => 20, 'tier3_amount' => 10 ) as $key => $pct ) {
				$hundredths = $pool * $pct;                 // dollars * 100
				$expected   = (int) ceil( $hundredths / 1000 ) * 10; // exact: integer / integer
				if ( abs( $result[ $key ] - $expected ) > 0.0001 ) {
					$mismatches[] = "pool $pool $key got {$result[$key]} expected $expected";
				}
			}
		}
		$this->assertSame( array(), $mismatches, "float noise changed a rounded tier:\n" . implode( "\n", array_slice( $mismatches, 0, 10 ) ) );
	}
}
