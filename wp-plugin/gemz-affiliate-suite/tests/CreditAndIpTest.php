<?php

use PHPUnit\Framework\TestCase;

/**
 * Two small rules added 2026-09-19:
 * - the 100-day window for moving a lead's referral credit
 *   (GAS_Leads::credit_change_window_open()), and
 * - the client-IP rule (GAS_Fraud::get_client_ip()), which must ignore
 *   client-supplied forwarding headers: a forged X-Forwarded-For was stored
 *   as a real lead's TCPA consent IP before the fix.
 */
final class CreditAndIpTest extends TestCase {

	protected function tearDown(): void {
		unset( $_SERVER['REMOTE_ADDR'], $_SERVER['HTTP_X_FORWARDED_FOR'], $_SERVER['HTTP_CF_CONNECTING_IP'] );
	}

	public function test_window_is_open_on_the_day_and_up_to_100_days(): void {
		$this->assertTrue( GAS_Leads::credit_change_window_open( '2026-09-19 10:00:00', '2026-09-19 10:00:00' ) );
		$this->assertTrue( GAS_Leads::credit_change_window_open( '2026-06-01 00:00:00', '2026-09-08 00:00:00' ), 'exactly 99 days' );
		$this->assertTrue( GAS_Leads::credit_change_window_open( '2026-06-01 00:00:00', '2026-09-09 00:00:00' ), 'exactly 100 days is still allowed' );
	}

	public function test_window_is_closed_after_100_days(): void {
		$this->assertFalse( GAS_Leads::credit_change_window_open( '2026-06-01 00:00:00', '2026-09-09 00:00:01' ), '100 days and 1 second' );
		$this->assertFalse( GAS_Leads::credit_change_window_open( '2026-01-01 00:00:00', '2026-09-19 00:00:00' ) );
	}

	public function test_window_treats_unparseable_dates_as_closed(): void {
		$this->assertFalse( GAS_Leads::credit_change_window_open( 'not a date', '2026-09-19 00:00:00' ) );
		$this->assertFalse( GAS_Leads::credit_change_window_open( '2026-09-19 00:00:00', '' ) );
	}

	public function test_client_ip_uses_remote_addr(): void {
		$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
		$this->assertSame( '198.51.100.7', GAS_Fraud::get_client_ip() );
	}

	public function test_forged_forwarding_headers_are_ignored(): void {
		$_SERVER['REMOTE_ADDR']          = '198.51.100.7';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.77';
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '203.0.113.88';
		$this->assertSame( '198.51.100.7', GAS_Fraud::get_client_ip() );
	}

	public function test_missing_or_invalid_remote_addr_returns_empty_string(): void {
		$this->assertSame( '', GAS_Fraud::get_client_ip() );
		$_SERVER['REMOTE_ADDR']          = 'not-an-ip';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.77';
		$this->assertSame( '', GAS_Fraud::get_client_ip(), 'must not fall back to a forwarding header' );
	}
}
