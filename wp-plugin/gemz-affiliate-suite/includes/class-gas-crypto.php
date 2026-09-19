<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encryption at rest for tax IDs, legal names and payment details.
 *
 * Values are encrypted with AES-256-GCM (authenticated: a tampered value fails
 * to decrypt instead of returning garbage) under a key that lives OUTSIDE the
 * database, as the constant GAS_DATA_KEY in wp-config.php (base64 of 32 random
 * bytes). A database dump, backup or SQL-injection read therefore exposes only
 * ciphertext. It does NOT protect against someone who can read wp-config.php or
 * run PHP on the server, and losing the key makes the stored values
 * unrecoverable, so the key must be backed up separately.
 *
 * Stored format: "gasenc1:" . base64( iv(12) . tag(16) . ciphertext ). The
 * optional $context string (e.g. "u42:gas_tax_id") is bound in as
 * authenticated data, so a ciphertext copied to another user or field will not
 * decrypt.
 *
 * Compatibility: a stored value WITHOUT the prefix is treated as legacy
 * plaintext and returned as-is (so reads keep working before the migration).
 * If no valid key is configured, writes fall back to plaintext and the admin
 * Ledger page says so; it never silently invents a key or stores one in the
 * database.
 */
class GAS_Crypto {

	const PREFIX = 'gasenc1:';
	const CIPHER = 'aes-256-gcm';

	/** For tests only: a raw 32-byte key that replaces the wp-config constant. */
	public static $key_override = null;

	private static function key() {
		if ( null !== self::$key_override ) {
			return 32 === strlen( self::$key_override ) ? self::$key_override : null;
		}
		if ( ! defined( 'GAS_DATA_KEY' ) ) {
			return null;
		}
		$raw = base64_decode( (string) GAS_DATA_KEY, true );
		return ( false !== $raw && 32 === strlen( $raw ) ) ? $raw : null;
	}

	public static function available() {
		return function_exists( 'openssl_encrypt' )
			&& in_array( self::CIPHER, openssl_get_cipher_methods(), true )
			&& null !== self::key();
	}

	public static function is_encrypted( $value ) {
		return is_string( $value ) && 0 === strpos( $value, self::PREFIX );
	}

	/**
	 * Empty values are left empty (so "is anything set?" checks keep working).
	 * Without a valid key the plaintext is returned unchanged.
	 */
	public static function encrypt( $plain, $context = '' ) {
		$plain = (string) $plain;
		if ( '' === $plain || self::is_encrypted( $plain ) || ! self::available() ) {
			return $plain;
		}
		$iv  = random_bytes( 12 );
		$tag = '';
		$ct  = openssl_encrypt( $plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag, (string) $context, 16 );
		if ( false === $ct || 16 !== strlen( $tag ) ) {
			return $plain;
		}
		return self::PREFIX . base64_encode( $iv . $tag . $ct );
	}

	/**
	 * Returns the plaintext; the input unchanged if it was never encrypted;
	 * or NULL if it is encrypted but cannot be read (wrong or missing key,
	 * tampering, or a different context).
	 */
	public static function decrypt( $stored, $context = '' ) {
		if ( ! self::is_encrypted( $stored ) ) {
			return $stored;
		}
		$key = self::key();
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true );
		if ( null === $key || false === $raw || strlen( $raw ) < 29 ) {
			return null;
		}
		$plain = openssl_decrypt( substr( $raw, 28 ), self::CIPHER, $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ), (string) $context );
		return false === $plain ? null : $plain;
	}

	/**
	 * API credentials stored in wp_options (PayPal client secret, Wise API
	 * token). Client IDs and profile IDs are identifiers, not secrets, and
	 * stay plain.
	 */
	const SECRET_OPTIONS = array( 'gas_paypal_client_secret', 'gas_wise_api_token' );

	public static function get_secret_option( $name ) {
		$plain = self::decrypt( get_option( $name, '' ), 'opt:' . $name );
		return null === $plain ? '' : (string) $plain;
	}

	/** True when something is stored, even if it can't currently be decrypted. */
	public static function has_secret_option( $name ) {
		return '' !== (string) get_option( $name, '' );
	}

	public static function update_secret_option( $name, $value ) {
		update_option( $name, self::encrypt( $value, 'opt:' . $name ) );
	}

	/**
	 * Encrypts every still-plaintext sensitive value in place. Idempotent and
	 * safe to re-run. Each value is decrypted again and compared before it is
	 * written; a mismatch is skipped and counted, never overwritten.
	 * Returns array( 'user_meta' => n, 'payment_details' => n, 'skipped' => n ).
	 */
	public static function migrate_plaintext() {
		global $wpdb;
		$result = array( 'user_meta' => 0, 'payment_details' => 0, 'skipped' => 0 );
		if ( ! self::available() ) {
			$result['skipped'] = -1; // no valid key: nothing attempted
			return $result;
		}

		$keys = GAS_Payouts::encrypted_meta_keys();
		$in   = "'" . implode( "','", array_map( 'esc_sql', $keys ) ) . "'";
		$rows = $wpdb->get_results( "SELECT umeta_id, user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ({$in}) AND meta_value <> '' AND meta_value NOT LIKE '" . self::PREFIX . "%'" );
		foreach ( (array) $rows as $r ) {
			$ctx = 'u' . $r->user_id . ':' . $r->meta_key;
			$enc = self::encrypt( $r->meta_value, $ctx );
			if ( self::is_encrypted( $enc ) && self::decrypt( $enc, $ctx ) === $r->meta_value ) {
				// Through WordPress (not a raw UPDATE) so the object cache is refreshed too.
				update_user_meta( (int) $r->user_id, $r->meta_key, $enc );
				$result['user_meta']++;
			} else {
				$result['skipped']++;
			}
		}

		$result['options'] = 0;
		foreach ( self::SECRET_OPTIONS as $name ) {
			$value = get_option( $name, '' );
			if ( '' === (string) $value || self::is_encrypted( $value ) ) {
				continue;
			}
			$enc = self::encrypt( $value, 'opt:' . $name );
			if ( self::is_encrypted( $enc ) && self::decrypt( $enc, 'opt:' . $name ) === $value ) {
				update_option( $name, $enc );
				$result['options']++;
			} else {
				$result['skipped']++;
			}
		}

		$payouts = GAS_DB::table( 'payouts' );
		$rows    = $wpdb->get_results( "SELECT id, cashback_payment_details FROM {$payouts} WHERE cashback_payment_details IS NOT NULL AND cashback_payment_details <> '' AND cashback_payment_details NOT LIKE '" . self::PREFIX . "%'" );
		foreach ( (array) $rows as $r ) {
			$ctx = 'p' . $r->id . ':cashback';
			$enc = self::encrypt( $r->cashback_payment_details, $ctx );
			if ( self::is_encrypted( $enc ) && self::decrypt( $enc, $ctx ) === $r->cashback_payment_details ) {
				$wpdb->update( $payouts, array( 'cashback_payment_details' => $enc ), array( 'id' => $r->id ) );
				$result['payment_details']++;
			} else {
				$result['skipped']++;
			}
		}
		return $result;
	}

	/** How many sensitive values are still stored as plaintext (for the admin status line). */
	public static function count_plaintext() {
		global $wpdb;
		$keys = GAS_Payouts::encrypted_meta_keys();
		$in   = "'" . implode( "','", array_map( 'esc_sql', $keys ) ) . "'";
		$n    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key IN ({$in}) AND meta_value <> '' AND meta_value NOT LIKE '" . self::PREFIX . "%'" );
		$n   += (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . GAS_DB::table( 'payouts' ) . " WHERE cashback_payment_details IS NOT NULL AND cashback_payment_details <> '' AND cashback_payment_details NOT LIKE '" . self::PREFIX . "%'" );
		foreach ( self::SECRET_OPTIONS as $name ) {
			$value = get_option( $name, '' );
			if ( '' !== (string) $value && ! self::is_encrypted( $value ) ) {
				$n++;
			}
		}
		return $n;
	}
}
