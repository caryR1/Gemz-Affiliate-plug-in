<?php

use PHPUnit\Framework\TestCase;

/**
 * GAS_Crypto — encryption at rest for tax IDs and payment details.
 * The key is injected via GAS_Crypto::$key_override (the real key is a
 * wp-config constant, which cannot be redefined between tests).
 */
final class CryptoTest extends TestCase {

	protected function setUp(): void {
		GAS_Crypto::$key_override = str_repeat( 'k', 32 );
	}

	protected function tearDown(): void {
		GAS_Crypto::$key_override = null;
	}

	public function test_round_trip_returns_the_original_and_is_marked_encrypted(): void {
		$enc = GAS_Crypto::encrypt( '123-45-6789', 'u7:gas_tax_id' );
		$this->assertTrue( GAS_Crypto::is_encrypted( $enc ) );
		$this->assertStringNotContainsString( '123-45-6789', $enc );
		$this->assertSame( '123-45-6789', GAS_Crypto::decrypt( $enc, 'u7:gas_tax_id' ) );
	}

	public function test_same_value_encrypts_differently_each_time(): void {
		$this->assertNotSame( GAS_Crypto::encrypt( 'same', 'c' ), GAS_Crypto::encrypt( 'same', 'c' ) );
	}

	public function test_empty_values_stay_empty_so_is_it_set_checks_still_work(): void {
		$this->assertSame( '', GAS_Crypto::encrypt( '', 'c' ) );
		$this->assertSame( '', GAS_Crypto::decrypt( '', 'c' ) );
	}

	public function test_legacy_plaintext_is_returned_unchanged(): void {
		$this->assertSame( 'me@example.com', GAS_Crypto::decrypt( 'me@example.com', 'c' ) );
	}

	public function test_a_value_is_not_encrypted_twice(): void {
		$enc = GAS_Crypto::encrypt( 'x', 'c' );
		$this->assertSame( $enc, GAS_Crypto::encrypt( $enc, 'c' ) );
	}

	public function test_ciphertext_moved_to_another_user_or_field_does_not_decrypt(): void {
		$enc = GAS_Crypto::encrypt( '123-45-6789', 'u7:gas_tax_id' );
		$this->assertNull( GAS_Crypto::decrypt( $enc, 'u8:gas_tax_id' ), 'different user' );
		$this->assertNull( GAS_Crypto::decrypt( $enc, 'u7:gas_paypal_email' ), 'different field' );
	}

	public function test_tampering_is_detected(): void {
		$enc  = GAS_Crypto::encrypt( 'secret value', 'c' );
		$raw  = base64_decode( substr( $enc, strlen( GAS_Crypto::PREFIX ) ) );
		$raw[ strlen( $raw ) - 1 ] = chr( ord( $raw[ strlen( $raw ) - 1 ] ) ^ 1 );
		$this->assertNull( GAS_Crypto::decrypt( GAS_Crypto::PREFIX . base64_encode( $raw ), 'c' ) );
		$this->assertNull( GAS_Crypto::decrypt( GAS_Crypto::PREFIX . 'not-base64!!', 'c' ) );
		$this->assertNull( GAS_Crypto::decrypt( GAS_Crypto::PREFIX . base64_encode( 'short' ), 'c' ) );
	}

	public function test_wrong_key_returns_null_never_garbage(): void {
		$enc = GAS_Crypto::encrypt( 'secret value', 'c' );
		GAS_Crypto::$key_override = str_repeat( 'z', 32 );
		$this->assertNull( GAS_Crypto::decrypt( $enc, 'c' ) );
	}

	public function test_no_key_means_plaintext_writes_and_unreadable_ciphertext(): void {
		$enc = GAS_Crypto::encrypt( 'secret value', 'c' );
		GAS_Crypto::$key_override = 'too-short';
		$this->assertFalse( GAS_Crypto::available() );
		$this->assertSame( 'plain', GAS_Crypto::encrypt( 'plain', 'c' ), 'fail-open: stored as-is, the Ledger page warns' );
		$this->assertNull( GAS_Crypto::decrypt( $enc, 'c' ), 'encrypted data is unreadable without the key' );
	}

	public function test_unicode_and_long_values_round_trip(): void {
		$value = "Zoë Müller — 東京 \n" . str_repeat( 'x', 5000 );
		$this->assertSame( $value, GAS_Crypto::decrypt( GAS_Crypto::encrypt( $value, 'c' ), 'c' ) );
	}
}
