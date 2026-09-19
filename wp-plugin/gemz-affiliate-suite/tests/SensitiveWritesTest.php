<?php

use PHPUnit\Framework\TestCase;

/**
 * Fail-closed behaviour of the sensitive save paths (2026-09-19): if encryption
 * is not operational, GAS_Payouts::save_tax_info() / save_details() must write
 * NOTHING (no plaintext fallback, no partial update) and flag the refusal.
 * Uses the harness's in-memory user meta.
 */
final class SensitiveWritesTest extends TestCase {

	private const USER = 42;

	protected function setUp(): void {
		$GLOBALS['gas_test_usermeta'] = array();
		GAS_Crypto::$key_override     = str_repeat( 'k', 32 );
		GAS_Crypto::$test_fail        = false;
		GAS_Crypto::$refused          = false;
	}

	protected function tearDown(): void {
		GAS_Crypto::$key_override = null;
		GAS_Crypto::$test_fail    = false;
		GAS_Crypto::$refused      = false;
		$GLOBALS['gas_test_usermeta'] = array();
	}

	private function tax_post(): array {
		return array( 'tax_form_type' => 'w9', 'tax_legal_name' => 'Test Person', 'tax_id' => '987-65-4321', 'tax_country' => 'United States' );
	}

	private function payment_post(): array {
		return array( 'payout_method' => 'wise', 'paypal_email' => 'pp@example.invalid', 'wise_account_name' => 'Test Person', 'wise_currency' => 'usd', 'wise_transfer_type' => 'aba', 'wise_account_number' => '000123456789', 'wise_routing_number' => '021000021', 'payment_notes' => 'a note' );
	}

	private function stored(): array {
		return isset( $GLOBALS['gas_test_usermeta'][ self::USER ] ) ? $GLOBALS['gas_test_usermeta'][ self::USER ] : array();
	}

	public function test_normal_save_stores_sensitive_fields_encrypted_and_reads_back(): void {
		$this->assertTrue( GAS_Payouts::save_tax_info( self::USER, $this->tax_post() ) );
		$this->assertTrue( GAS_Payouts::save_details( self::USER, $this->payment_post() ) );
		foreach ( array( 'gas_tax_id', 'gas_tax_legal_name', 'gas_tax_country', 'gas_paypal_email', 'gas_wise_account_number', 'gas_wise_routing_number', 'gas_wise_account_name', 'gas_payment_notes' ) as $key ) {
			$this->assertTrue( GAS_Crypto::is_encrypted( $this->stored()[ $key ] ), "$key must be encrypted" );
		}
		$this->assertSame( '987-65-4321', GAS_Payouts::get_tax_info( self::USER )['tax_id'] );
		$this->assertSame( '000123456789', GAS_Payouts::get_details( self::USER )['wise_account'] );
		$this->assertSame( 'w9', $this->stored()['gas_tax_form_type'], 'non-sensitive fields stay plain' );
	}

	/** @dataProvider brokenEncryption */
	public function test_tax_save_is_refused_and_writes_nothing_when_encryption_is_not_operational( string $mode ): void {
		$this->breakEncryption( $mode );
		$this->assertFalse( GAS_Payouts::save_tax_info( self::USER, $this->tax_post() ) );
		$this->assertTrue( GAS_Crypto::$refused, 'caller can tell this was a refusal, not a validation error' );
		$this->assertSame( array(), $this->stored(), 'no plaintext and no partial write of any field' );
	}

	/** @dataProvider brokenEncryption */
	public function test_payment_save_is_refused_and_writes_nothing_when_encryption_is_not_operational( string $mode ): void {
		$this->breakEncryption( $mode );
		$this->assertFalse( GAS_Payouts::save_details( self::USER, $this->payment_post() ) );
		$this->assertTrue( GAS_Crypto::$refused );
		$this->assertSame( array(), $this->stored(), 'not even the non-sensitive fields (method, currency) change' );
	}

	public static function brokenEncryption(): array {
		return array(
			'missing key'        => array( 'missing' ),
			'invalid key'        => array( 'invalid' ),
			'encryption failure' => array( 'failure' ),
		);
	}

	private function breakEncryption( string $mode ): void {
		if ( 'missing' === $mode ) {
			GAS_Crypto::$key_override = null;
		} elseif ( 'invalid' === $mode ) {
			GAS_Crypto::$key_override = 'not-32-bytes';
		} else {
			GAS_Crypto::$test_fail = true;
		}
	}

	public function test_validation_failure_is_not_reported_as_a_refusal(): void {
		$post = $this->tax_post();
		$post['tax_legal_name'] = '';
		$this->assertFalse( GAS_Payouts::save_tax_info( self::USER, $post ) );
		$this->assertFalse( GAS_Crypto::$refused );
	}

	public function test_clearing_payment_details_is_allowed_even_without_a_key(): void {
		GAS_Crypto::$key_override = null;
		$this->assertTrue( GAS_Payouts::save_details( self::USER, array( 'payout_method' => '' ) ), 'empty sensitive values need no encryption' );
		$this->assertFalse( GAS_Crypto::$refused );
	}

	public function test_a_refused_save_does_not_overwrite_previously_encrypted_data(): void {
		$this->assertTrue( GAS_Payouts::save_tax_info( self::USER, $this->tax_post() ) );
		$before = $this->stored();
		GAS_Crypto::$key_override = null;
		$again = $this->tax_post();
		$again['tax_id'] = '111-22-3333';
		$this->assertFalse( GAS_Payouts::save_tax_info( self::USER, $again ) );
		$this->assertSame( $before, $this->stored(), 'the earlier encrypted values are untouched' );
	}

	public function test_legacy_plaintext_still_reads_after_the_change(): void {
		$GLOBALS['gas_test_usermeta'][ self::USER ] = array( 'gas_tax_id' => '555-44-3333', 'gas_paypal_email' => 'legacy@example.invalid' );
		GAS_Crypto::$key_override = null; // reading needs no key for plaintext
		$this->assertSame( '555-44-3333', GAS_Payouts::get_tax_info( self::USER )['tax_id'] );
		$this->assertSame( 'legacy@example.invalid', GAS_Payouts::get_details( self::USER )['paypal_email'] );
	}
}
