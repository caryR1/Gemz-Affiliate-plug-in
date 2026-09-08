<?php

use PHPUnit\Framework\TestCase;

/**
 * Covers GAS_Frontend::partner_covers_state() — the check behind the
 * out-of-area referral handling (matches/no-match admin notification, see
 * gemz-solar-main-partner-gosolarpower memory). It's private, so tests call
 * it via Reflection rather than duplicating its logic or loosening its
 * visibility just to make it testable.
 */
final class CoverageMatchingTest extends TestCase {

	private function coversState( $partner, string $state ): bool {
		$method = new ReflectionMethod( GAS_Frontend::class, 'partner_covers_state' );
		$method->setAccessible( true );
		return $method->invoke( null, $partner, $state );
	}

	public function test_single_state_partner_matches_that_state(): void {
		$partner = (object) array( 'state' => 'FL' );
		$this->assertTrue( $this->coversState( $partner, 'FL' ) );
	}

	public function test_single_state_partner_does_not_match_other_states(): void {
		$partner = (object) array( 'state' => 'FL' );
		$this->assertFalse( $this->coversState( $partner, 'CA' ) );
	}

	public function test_multi_state_partner_matches_any_listed_state(): void {
		// Go Solar Power's real coverage field, see
		// gemz-solar-main-partner-gosolarpower memory.
		$partner = (object) array( 'state' => 'FL,TX,GA,CA' );
		$this->assertTrue( $this->coversState( $partner, 'TX' ) );
		$this->assertTrue( $this->coversState( $partner, 'CA' ) );
		$this->assertFalse( $this->coversState( $partner, 'NY' ) );
	}

	public function test_matching_is_case_and_whitespace_tolerant(): void {
		// This data is hand-entered in wp-admin, per the field's own doc
		// comment — a stray lowercase letter or space must not silently
		// break coverage matching.
		$partner = (object) array( 'state' => ' fl , tx ,ga ' );
		$this->assertTrue( $this->coversState( $partner, 'tx' ) );
		$this->assertTrue( $this->coversState( $partner, 'GA' ) );
	}

	public function test_empty_partner_state_never_matches(): void {
		$partner = (object) array( 'state' => '' );
		$this->assertFalse( $this->coversState( $partner, 'FL' ) );

		$partner_null = (object) array( 'state' => null );
		$this->assertFalse( $this->coversState( $partner_null, 'FL' ) );
	}

	public function test_empty_queried_state_never_matches(): void {
		$partner = (object) array( 'state' => 'FL,TX' );
		$this->assertFalse( $this->coversState( $partner, '' ) );
	}
}
