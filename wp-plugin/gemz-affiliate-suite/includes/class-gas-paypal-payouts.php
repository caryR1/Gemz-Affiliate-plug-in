<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends a single PayPal Payouts batch covering every affiliate who has
 * 'paypal' set as their payout method and has an unpaid balance. Affiliates
 * on 'wise' or 'other' are never touched by this — they're paid by
 * GAS_Wise_Payouts or manually.
 *
 * Credentials are a PayPal REST app client id/secret (Sandbox or Live),
 * stored as WordPress options, set once on the Payout Ledger screen.
 */
class GAS_PayPal_Payouts {

	private static function api_base() {
		$env = get_option( 'gas_paypal_env', 'sandbox' );
		return 'live' === $env ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
	}

	private static function get_access_token() {
		$client_id     = get_option( 'gas_paypal_client_id', '' );
		$client_secret = get_option( 'gas_paypal_client_secret', '' );

		if ( ! $client_id || ! $client_secret ) {
			return new WP_Error( 'paypal_not_configured', 'PayPal API credentials are not set.' );
		}

		$response = wp_remote_post( self::api_base() . '/v1/oauth2/token', array(
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
				'Content-Type'  => 'application/x-www-form-urlencoded',
			),
			'body'    => array( 'grant_type' => 'client_credentials' ),
			'timeout' => 20,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['access_token'] ) ) {
			return new WP_Error( 'paypal_auth_failed', 'Could not authenticate with PayPal. Check the API credentials.' );
		}

		return $body['access_token'];
	}

	/**
	 * Sends one payout batch covering every eligible affiliate, then marks
	 * each successfully-included affiliate's unpaid ledger rows as paid.
	 * Nothing is marked paid unless PayPal accepted the batch.
	 *
	 * @return array|WP_Error ['paid_user_ids' => [...], 'total' => float, 'currency' => string, 'held' => [...]]
	 */
	public static function pay_all_eligible_affiliates( $currency = 'USD' ) {
		$balance = GAS_Payouts::affiliates_with_unpaid_balance( 'paypal' );
		$eligible = $balance['eligible'];
		$held     = $balance['held']; // below the min threshold, or missing tax info — carried forward, not paid

		$items          = array();
		$user_amounts   = array();

		foreach ( $eligible as $row ) {
			$email = $row['details']['paypal_email'];
			if ( ! $email ) {
				continue;
			}

			$items[] = array(
				'recipient_type' => 'EMAIL',
				'amount'         => array(
					'value'    => number_format( $row['unpaid'], 2, '.', '' ),
					'currency' => $currency,
				),
				'receiver'       => $email,
				'note'           => 'Affiliate commission payout',
				'sender_item_id' => 'gas-affiliate-' . $row['user_id'],
			);

			$user_amounts[ $row['user_id'] ] = $row['unpaid'];
		}

		if ( empty( $items ) ) {
			$msg = 'No PayPal-method affiliates are currently eligible for a payout.';
			if ( $held ) {
				$msg .= ' ' . count( $held ) . ' held back (below the minimum threshold or missing tax info).';
			}
			return new WP_Error( 'nothing_to_pay', $msg );
		}

		$token = self::get_access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$batch_id = 'gas-' . gmdate( 'YmdHis' ) . '-' . wp_generate_password( 6, false, false );

		$response = wp_remote_post( self::api_base() . '/v1/payments/payouts', array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'sender_batch_header' => array(
					'sender_batch_id' => $batch_id,
					'email_subject'   => 'You have a commission payout',
				),
				'items' => $items,
			) ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code < 200 || $status_code >= 300 ) {
			$body    = json_decode( wp_remote_retrieve_body( $response ), true );
			$message = $body['message'] ?? 'PayPal rejected the payout batch.';
			return new WP_Error( 'paypal_payout_failed', $message );
		}

		// Batch accepted — PayPal disburses asynchronously from here, but the
		// funds are committed on PayPal's side, so mark these as paid now
		// rather than polling batch status.
		$paid_user_ids = array();
		$total         = 0;
		foreach ( $user_amounts as $user_id => $amount ) {
			GAS_Payouts::mark_affiliate_paid( $user_id );
			$paid_user_ids[] = $user_id;
			$total          += $amount;
		}

		return array(
			'paid_user_ids' => $paid_user_ids,
			'total'         => $total,
			'currency'      => $currency,
			'held'          => $held,
		);
	}
}
