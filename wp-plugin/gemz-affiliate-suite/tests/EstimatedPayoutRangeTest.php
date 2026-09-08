<?php

use PHPUnit\Framework\TestCase;

/**
 * Covers GAS_Frontend::estimated_payout_range() — the "earn between $X and
 * $Y" copy shown on the public signup/refer page. This is the exact bug
 * class that already happened once for real (it originally read the
 * vestigial default_cut_type/default_cut_value fields instead of the real
 * agent_pool/tier-split math — see gemz-solar-merged-signup-page and
 * gemz-solar-main-partner-gosolarpower memory) — these tests exist so that
 * regression can't silently happen again.
 */
final class EstimatedPayoutRangeTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['gas_test_options'] = array();
		$GLOBALS['wpdb']->results    = array();
	}

	private function flatPartner( float $payoutAmount, float $poolValue = 100 ): object {
		return (object) array(
			'payout_type'         => 'flat',
			'payout_amount'       => $payoutAmount,
			'payout_percent'      => null,
			'typical_sale_amount' => null,
			'agent_pool_type'     => 'flat',
			'agent_pool_value'    => $poolValue,
		);
	}

	public function test_no_approved_partners_returns_null(): void {
		$GLOBALS['wpdb']->results = array();
		$this->assertNull( GAS_Frontend::estimated_payout_range() );
	}

	public function test_single_flat_partner_gives_a_single_point_range(): void {
		// Go Solar Power's real numbers: $2,000 flat, $700 agent pool,
		// default 70% tier-1 split -> $490. estimated_payout_range()
		// doesn't round() its tier1 estimate (unlike compute()'s tier
		// amounts), so 700*0.7 lands on 489.99999999999994 in real IEEE-754
		// arithmetic — confirmed by an actual PHPUnit run over SSH,
		// 2026-09-08 (see SWAP-with-HOMES.md). Not a math bug: the real
		// value is correct to well under a thousandth of a cent,
		// assertSame() was simply the wrong comparison for a computed
		// (non-rounded) float.
		$GLOBALS['wpdb']->results = array( $this->flatPartner( 2000, 700 ) );

		$range = GAS_Frontend::estimated_payout_range();

		$this->assertNotNull( $range );
		$this->assertEqualsWithDelta( 490.0, $range['min'], 0.0001 );
		$this->assertEqualsWithDelta( 490.0, $range['max'], 0.0001 );
	}

	public function test_percent_partner_with_typical_sale_amount_is_included(): void {
		$GLOBALS['wpdb']->results = array(
			$this->flatPartner( 2000, 700 ), // -> 490
			(object) array(
				'payout_type'         => 'percent',
				'payout_amount'       => null,
				'payout_percent'      => 10,
				'typical_sale_amount' => 25000,
				'agent_pool_type'     => 'percent',
				'agent_pool_value'    => 100,
			), // 25000 * 10% = 2500 gross, agent pool 100% = 2500, tier1 = 2500*70% = 1750
		);

		$range = GAS_Frontend::estimated_payout_range();

		$this->assertNotNull( $range );
		$this->assertEqualsWithDelta( 490.0, $range['min'], 0.0001 );
		$this->assertEqualsWithDelta( 1750.0, $range['max'], 0.0001 );
	}

	public function test_percent_partner_without_typical_sale_amount_is_excluded_not_guessed_at(): void {
		$GLOBALS['wpdb']->results = array(
			$this->flatPartner( 2000, 700 ), // -> 490, the only real data point
			(object) array(
				'payout_type'         => 'percent',
				'payout_amount'       => null,
				'payout_percent'      => 10,
				'typical_sale_amount' => null, // no basis to estimate this one
				'agent_pool_type'     => 'percent',
				'agent_pool_value'    => 100,
			),
		);

		$range = GAS_Frontend::estimated_payout_range();

		// If the excluded partner leaked in, min/max would no longer both
		// equal 490 (there'd be nothing to form a real second data point
		// from, so a bug here would most likely show up as a 0 in the range).
		$this->assertEqualsWithDelta( 490.0, $range['min'], 0.0001 );
		$this->assertEqualsWithDelta( 490.0, $range['max'], 0.0001 );
	}

	public function test_partner_with_zero_or_unset_payout_amount_is_excluded(): void {
		$GLOBALS['wpdb']->results = array(
			$this->flatPartner( 2000, 700 ),
			$this->flatPartner( 0, 700 ), // not configured yet — must not drag the range down to 0
		);

		$range = GAS_Frontend::estimated_payout_range();

		$this->assertEqualsWithDelta( 490.0, $range['min'], 0.0001, 'the $0 partner must be excluded, not treated as a real $0 data point' );
		$this->assertEqualsWithDelta( 490.0, $range['max'], 0.0001 );
	}

	public function test_partner_with_no_pool_configured_defaults_to_100_percent_of_gross(): void {
		$partner = (object) array(
			'payout_type'         => 'flat',
			'payout_amount'       => 1000,
			'payout_percent'      => null,
			'typical_sale_amount' => null,
			'agent_pool_type'     => null,
			'agent_pool_value'    => null,
		);
		$GLOBALS['wpdb']->results = array( $partner );

		$range = GAS_Frontend::estimated_payout_range();

		// 1000 gross * 100% pool * 70% tier-1 split = 700
		$this->assertSame( 700.0, $range['min'] );
		$this->assertSame( 700.0, $range['max'] );
	}
}
