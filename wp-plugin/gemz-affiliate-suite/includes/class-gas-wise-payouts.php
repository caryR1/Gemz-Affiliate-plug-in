<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pays affiliates whose payout method is 'wise' via the Wise API — a quote,
 * recipient account, and transfer per affiliate, funded from the site
 * owner's Wise balance. Unlike PayPal Payouts, Wise has no true batch
 * endpoint, so this loops one transfer per affiliate and reports per-affiliate
 * success/failure rather than all-or-nothing — one affiliate's bad bank
 * details never blocks the rest from getting paid.
 *
 * Supports the two most common recipient formats:
 *  - 'aba'  — US domestic: routing number + account number
 *  - 'iban' — most of the rest of the world: a single IBAN
 * An affiliate whose stored bank details don't specify one of these is
 * skipped with a clear error rather than guessed at.
 */
class GAS_Wise_Payouts {

	private static function api_base() {
		$env = get_option( 'gas_wise_env', 'sandbox' );
		return 'live' === $env ? 'https://api.transferwise.com' : 'https://api.sandbox.transferwise.tech';
	}

	private static function api_token() {
		return get_option( 'gas_wise_api_token', '' );
	}

	private static function profile_id() {
		return get_option( 'gas_wise_profile_id', '' );
	}

	private static function request( $method, $path, $body = null ) {
		$token = self::api_token();
		if ( ! $token ) {
			return new WP_Error( 'wise_not_configured', 'Wise API token is not set.' );
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'timeout' => 30,
		);

		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}

		$response = wp_remote_request( self::api_base() . $path, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status = wp_remote_retrieve_response_code( $response );
		$data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status < 200 || $status >= 300 ) {
			$message = is_array( $data ) ? ( $data['errors'][0]['message'] ?? $data['error_description'] ?? wp_remote_retrieve_body( $response ) ) : wp_remote_retrieve_body( $response );
			return new WP_Error( 'wise_api_error', $message );
		}

		return $data;
	}

	private static function recipient_details( $details ) {
		if ( 'aba' === $details['wise_transfer'] ) {
			return array(
				'legalType'     => 'PRIVATE',
				'accountNumber' => $details['wise_account'],
				'abartn'        => $details['wise_routing'],
				'accountType'   => 'CHECKING',
			);
		}
		if ( 'iban' === $details['wise_transfer'] ) {
			return array(
				'legalType' => 'PRIVATE',
				'IBAN'      => $details['wise_account'],
			);
		}
		return null;
	}

	/**
	 * Runs the full quote -> recipient -> transfer -> fund sequence for one
	 * affiliate. Returns true on success, WP_Error with a specific reason on
	 * failure — this affiliate's failure never blocks the rest of the run.
	 */
	private static function pay_one_affiliate( $details, $amount ) {
		$profile_id = self::profile_id();
		if ( ! $profile_id ) {
			return new WP_Error( 'wise_not_configured', 'Wise profile ID is not set.' );
		}

		$currency     = $details['wise_currency'];
		$account_name = $details['wise_name'];

		if ( ! $currency ) {
			return new WP_Error( 'missing_currency', 'No currency set for this affiliate.' );
		}
		if ( ! $account_name ) {
			return new WP_Error( 'missing_account_name', 'No account holder name set for this affiliate.' );
		}

		$recipient_details = self::recipient_details( $details );
		if ( ! $recipient_details ) {
			return new WP_Error( 'missing_transfer_type', "This affiliate's bank details don't specify ABA or IBAN." );
		}

		$source_currency = get_option( 'gas_wise_source_currency', 'USD' );

		// 1. Quote — how much source currency does this target amount cost right now.
		$quote = self::request( 'POST', '/v3/quotes', array(
			'sourceCurrency' => $source_currency,
			'targetCurrency' => $currency,
			'targetAmount'   => round( $amount, 2 ),
			'profile'        => (int) $profile_id,
		) );
		if ( is_wp_error( $quote ) ) {
			return $quote;
		}

		// 2. Recipient account.
		$recipient = self::request( 'POST', '/v1/accounts', array(
			'profile'           => (int) $profile_id,
			'accountHolderName' => $account_name,
			'currency'          => $currency,
			'type'              => 'aba' === $details['wise_transfer'] ? 'aba' : 'iban',
			'details'           => $recipient_details,
		) );
		if ( is_wp_error( $recipient ) ) {
			return $recipient;
		}

		// 3. Transfer.
		$transfer = self::request( 'POST', '/v1/transfers', array(
			'targetAccount'          => $recipient['id'],
			'quoteUuid'              => $quote['id'],
			'customerTransactionId'  => wp_generate_uuid4(),
			'details'                => array(
				'reference' => 'Affiliate commission',
			),
		) );
		if ( is_wp_error( $transfer ) ) {
			return $transfer;
		}

		// 4. Fund it from the site owner's Wise balance.
		$funded = self::request( 'POST', "/v3/profiles/{$profile_id}/transfers/{$transfer['id']}/payments", array(
			'type' => 'BALANCE',
		) );
		if ( is_wp_error( $funded ) ) {
			return $funded;
		}

		return true;
	}

	/**
	 * Pays every affiliate whose payout method is 'wise' and who has an
	 * unpaid balance, one transfer at a time.
	 *
	 * @return array ['paid' => [user_id => amount], 'failed' => [user_id => error message], 'held' => [...]]
	 */
	public static function pay_all_eligible_affiliates() {
		$balance  = GAS_Payouts::affiliates_with_unpaid_balance( 'wise' );
		$eligible = $balance['eligible'];

		$paid   = array();
		$failed = array();

		foreach ( $eligible as $row ) {
			$result = self::pay_one_affiliate( $row['details'], $row['unpaid'] );

			if ( is_wp_error( $result ) ) {
				$failed[ $row['user_id'] ] = $result->get_error_message();
				continue;
			}

			GAS_Payouts::mark_affiliate_paid( $row['user_id'] );
			$paid[ $row['user_id'] ] = $row['unpaid'];
		}

		return array( 'paid' => $paid, 'failed' => $failed, 'held' => $balance['held'] );
	}
}
