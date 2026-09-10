<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Customer-facing cashback claim flow — added 2026-09-09 per Cary's
 * self-referral policy call (see SWAP-with-HOMES.md): payouts only fire on
 * partner-confirmed completed work from a fixed commission pool, so a
 * customer (who may or may not also be an affiliate) needs a real way to
 * be identified and paid their buyer cash back, not just a math line on
 * the Ledger that never actually gets paid.
 *
 * A customer is never a WP user/account here — no login, no dashboard.
 * Identity is a single payout row + the email an admin entered for it, and
 * the claim link is a deterministic HMAC token (same pattern as
 * GAS_Contacts::unsubscribe_link()) rather than a stored/DB-issued one, so
 * no separate token column or expiry bookkeeping is needed.
 */
class GAS_Cashback {

	public static function init() {
		add_action( 'admin_post_gas_cashback_claim', array( __CLASS__, 'render_claim_page' ) );
		add_action( 'admin_post_nopriv_gas_cashback_claim', array( __CLASS__, 'render_claim_page' ) );
		add_action( 'admin_post_gas_submit_cashback_claim', array( __CLASS__, 'handle_submit_claim' ) );
		add_action( 'admin_post_nopriv_gas_submit_cashback_claim', array( __CLASS__, 'handle_submit_claim' ) );
	}

	/**
	 * Bound to one specific payout row (not just an email) so a claim link
	 * can never be reused across a customer's other, unrelated payouts —
	 * each cash-back payout gets its own claim email and its own token.
	 */
	public static function claim_token( $payout_id, $email ) {
		return substr( hash_hmac( 'sha256', $payout_id . '|' . strtolower( trim( $email ) ), wp_salt( 'auth' ) ), 0, 32 );
	}

	public static function claim_link( $payout_id, $email ) {
		return add_query_arg(
			array(
				'action'    => 'gas_cashback_claim',
				'payout_id' => $payout_id,
				'email'     => rawurlencode( $email ),
				'token'     => self::claim_token( $payout_id, $email ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	private static function get_valid_payout( $payout_id, $email, $token ) {
		if ( ! $payout_id || ! is_email( $email ) || ! $token || ! hash_equals( self::claim_token( $payout_id, $email ), $token ) ) {
			return null;
		}
		global $wpdb;
		$payout = $wpdb->get_row( $wpdb->prepare(
			'SELECT * FROM ' . GAS_DB::table( 'payouts' ) . ' WHERE id = %d AND LOWER(customer_email) = %s',
			$payout_id,
			strtolower( $email )
		) );
		return $payout ?: null;
	}

	public static function get_payment_details( $payout_id ) {
		global $wpdb;
		$raw = $wpdb->get_var( $wpdb->prepare(
			'SELECT cashback_payment_details FROM ' . GAS_DB::table( 'payouts' ) . ' WHERE id = %d',
			$payout_id
		) );
		$decoded = $raw ? json_decode( $raw, true ) : array();
		return wp_parse_args( is_array( $decoded ) ? $decoded : array(), array(
			'method'        => '',
			'paypal_email'  => '',
			'wise_name'     => '',
			'wise_currency' => '',
			'wise_transfer' => '',
			'wise_account'  => '',
			'wise_routing'  => '',
			'notes'         => '',
		) );
	}

	/**
	 * Same shape/field-name convention as GAS_Payouts::save_details() for
	 * an affiliate's own banking info, so the two forms (and any future
	 * shared JS) stay interchangeable — but stored as JSON on the payout
	 * row itself rather than user meta, since a customer has no WP user
	 * row to attach meta to.
	 */
	public static function save_payment_details( $payout_id, array $post ) {
		$method = isset( $post['payout_method'] ) && in_array( $post['payout_method'], array( 'paypal', 'wise', 'other' ), true )
			? $post['payout_method']
			: '';
		if ( '' === $method ) {
			return false;
		}

		$details = array(
			'method'        => $method,
			'paypal_email'  => isset( $post['paypal_email'] ) ? sanitize_email( wp_unslash( $post['paypal_email'] ) ) : '',
			'wise_name'     => isset( $post['wise_account_name'] ) ? sanitize_text_field( wp_unslash( $post['wise_account_name'] ) ) : '',
			'wise_currency' => isset( $post['wise_currency'] ) ? strtoupper( sanitize_text_field( wp_unslash( $post['wise_currency'] ) ) ) : '',
			'wise_transfer' => isset( $post['wise_transfer_type'] ) && in_array( $post['wise_transfer_type'], array( 'aba', 'iban' ), true ) ? $post['wise_transfer_type'] : '',
			'wise_account'  => isset( $post['wise_account_number'] ) ? sanitize_text_field( wp_unslash( $post['wise_account_number'] ) ) : '',
			'wise_routing'  => isset( $post['wise_routing_number'] ) ? sanitize_text_field( wp_unslash( $post['wise_routing_number'] ) ) : '',
			'notes'         => isset( $post['payment_notes'] ) ? sanitize_textarea_field( wp_unslash( $post['payment_notes'] ) ) : '',
		);

		global $wpdb;
		$wpdb->update(
			GAS_DB::table( 'payouts' ),
			array(
				'cashback_payment_details' => wp_json_encode( $details ),
				'cashback_claimed_at'      => current_time( 'mysql' ),
			),
			array( 'id' => $payout_id )
		);
		return true;
	}

	/**
	 * Admin-facing, read-only — same masking spirit as
	 * GAS_Payouts::masked_summary() for an affiliate's own banking info.
	 */
	public static function masked_payment_summary( $payout_id ) {
		$d = self::get_payment_details( $payout_id );
		if ( 'paypal' === $d['method'] && $d['paypal_email'] ) {
			return 'PayPal: ' . GAS_Payouts::mask_email( $d['paypal_email'] );
		}
		if ( 'wise' === $d['method'] && $d['wise_account'] ) {
			$last4 = substr( preg_replace( '/\s+/', '', $d['wise_account'] ), -4 );
			return 'Wise: ****' . $last4 . ( $d['wise_currency'] ? ' (' . $d['wise_currency'] . ')' : '' );
		}
		if ( 'other' === $d['method'] && $d['notes'] ) {
			return $d['notes'];
		}
		return '';
	}

	/**
	 * Fired right after a payout with cashback is saved (Calculator or
	 * REST) whenever an admin entered a customer email for it. Silently
	 * does nothing without one — cashback still gets tracked on the
	 * Ledger either way, it just can't be claimed until an email exists.
	 */
	public static function send_claim_email( $payout_id ) {
		global $wpdb;
		$payout = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . GAS_DB::table( 'payouts' ) . ' WHERE id = %d', $payout_id ) );
		if ( ! $payout || ! $payout->customer_email || (float) $payout->cashback_amount <= 0 ) {
			return false;
		}

		$partner   = $wpdb->get_row( $wpdb->prepare( 'SELECT name FROM ' . GAS_DB::table( 'partners' ) . ' WHERE id = %d', $payout->partner_id ) );
		$site_name = GAS_Settings::get( 'site_name' );
		$link      = self::claim_link( $payout_id, $payout->customer_email );
		$amount    = number_format( (float) $payout->cashback_amount, 2 );

		wp_mail(
			$payout->customer_email,
			"You've got \${$amount} cash back from {$site_name}",
			"Hi,\n\nThanks for your recent " . ( $partner ? "purchase with {$partner->name}" : 'purchase' ) . " through {$site_name} — you're eligible for \${$amount} cash back.\n\nClaim it here (just tell us how you'd like to be paid):\n{$link}\n\nIf you weren't expecting this email, you can safely ignore it." . GAS_Settings::compliance_footer( $payout->customer_email )
		);
		return true;
	}

	/**
	 * Minimal, self-contained HTML — this is an admin-post.php endpoint,
	 * not a real page/post, so there's no theme header/footer to hook
	 * into. Links the plugin's own existing frontend stylesheet directly
	 * (same classes the affiliate dashboard forms use) rather than
	 * inlining a second copy of the same rules.
	 */
	public static function render_claim_page() {
		$payout_id = isset( $_GET['payout_id'] ) ? absint( $_GET['payout_id'] ) : 0;
		$email     = isset( $_GET['email'] ) ? sanitize_email( wp_unslash( $_GET['email'] ) ) : '';
		$token     = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		$payout = self::get_valid_payout( $payout_id, $email, $token );
		if ( ! $payout ) {
			wp_die( 'This claim link is invalid, or the email doesn\'t match our records. If you think this is a mistake, please contact us directly.', 'Cash Back Claim', array( 'response' => 400 ) );
		}

		$site_name = GAS_Settings::get( 'site_name' );
		$css_url   = plugins_url( 'assets/gas-frontend.css', GAS_PLUGIN_FILE );
		$amount    = number_format( (float) $payout->cashback_amount, 2 );
		$d         = self::get_payment_details( $payout_id );
		$claimed   = ! empty( $payout->cashback_claimed_at );
		$paid      = ! empty( $payout->cashback_paid );

		nocache_headers();
		?>
		<!doctype html>
		<html>
		<head>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title>Cash Back Claim &mdash; <?php echo esc_html( $site_name ); ?></title>
			<link rel="stylesheet" href="<?php echo esc_url( $css_url ); ?>">
			<style>body{max-width:560px;margin:2.5em auto;padding:0 1.2em;font-family:-apple-system,Segoe UI,Roboto,sans-serif;}</style>
		</head>
		<body>
			<h1>Cash back from <?php echo esc_html( $site_name ); ?></h1>
			<?php if ( $paid ) : ?>
				<p class="gas-notice gas-notice-success">Your <strong>$<?php echo esc_html( $amount ); ?></strong> cash back has already been paid out. No action needed.</p>
			<?php else : ?>
				<p class="gas-notice gas-notice-success">You're eligible for <strong>$<?php echo esc_html( $amount ); ?></strong> cash back<?php echo $claimed ? ' — you\'ve already told us how to pay you; submitting again below will replace it' : ''; ?>.</p>
				<p class="gas-fineprint">Tell us how you'd like to be paid. This is only ever used to send you this cash back.</p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gas-form" id="gas-cashback-form">
					<?php wp_nonce_field( 'gas_submit_cashback_claim_' . $payout->id ); ?>
					<input type="hidden" name="action" value="gas_submit_cashback_claim">
					<input type="hidden" name="payout_id" value="<?php echo esc_attr( $payout->id ); ?>">
					<input type="hidden" name="email" value="<?php echo esc_attr( $payout->customer_email ); ?>">
					<input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">
					<p style="position:absolute;left:-9999px;" aria-hidden="true">
						<label>Leave this field empty<input type="text" name="gas_hp" tabindex="-1" autocomplete="off"></label>
					</p>

					<p>
						<label for="gas_payout_method">How would you like to be paid?</label><br>
						<select id="gas_payout_method" name="payout_method" class="gas-input">
							<option value="">-- choose one --</option>
							<option value="paypal" <?php selected( $d['method'], 'paypal' ); ?>>PayPal</option>
							<option value="wise" <?php selected( $d['method'], 'wise' ); ?>>Wise (bank transfer)</option>
							<option value="other" <?php selected( $d['method'], 'other' ); ?>>Other / tell us manually</option>
						</select>
					</p>

					<div class="gas-payout-fields" data-method="paypal">
						<p>
							<label for="gas_paypal_email">PayPal email</label><br>
							<input type="email" id="gas_paypal_email" name="paypal_email" class="gas-input" value="<?php echo esc_attr( $d['paypal_email'] ); ?>">
						</p>
					</div>

					<div class="gas-payout-fields" data-method="wise">
						<p>
							<label for="gas_wise_account_name">Account holder name</label><br>
							<input type="text" id="gas_wise_account_name" name="wise_account_name" class="gas-input" value="<?php echo esc_attr( $d['wise_name'] ); ?>">
						</p>
						<p>
							<label for="gas_wise_currency">Currency you're paid in (e.g. USD, EUR, GBP)</label><br>
							<input type="text" id="gas_wise_currency" name="wise_currency" class="gas-input" maxlength="3" value="<?php echo esc_attr( $d['wise_currency'] ); ?>">
						</p>
						<p>
							<label for="gas_wise_transfer_type">Account format</label><br>
							<select id="gas_wise_transfer_type" name="wise_transfer_type" class="gas-input">
								<option value="">-- choose one --</option>
								<option value="aba" <?php selected( $d['wise_transfer'], 'aba' ); ?>>US (routing + account number)</option>
								<option value="iban" <?php selected( $d['wise_transfer'], 'iban' ); ?>>International (IBAN)</option>
							</select>
						</p>
						<p>
							<label for="gas_wise_routing_number">Routing number (US only)</label><br>
							<input type="text" id="gas_wise_routing_number" name="wise_routing_number" class="gas-input" value="<?php echo esc_attr( $d['wise_routing'] ); ?>">
						</p>
						<p>
							<label for="gas_wise_account_number">Account number (US) or IBAN (international)</label><br>
							<input type="text" id="gas_wise_account_number" name="wise_account_number" class="gas-input" value="<?php echo esc_attr( $d['wise_account'] ); ?>">
						</p>
					</div>

					<div class="gas-payout-fields" data-method="other">
						<p>
							<label for="gas_payment_notes">How would you like to be paid?</label><br>
							<textarea id="gas_payment_notes" name="payment_notes" rows="4" class="gas-input" placeholder="e.g. Venmo, Zelle, or other details"><?php echo esc_textarea( $d['notes'] ); ?></textarea>
						</p>
					</div>

					<p><button type="submit" class="gas-button">Submit</button></p>
				</form>
				<script>
					(function() {
						var select = document.getElementById('gas_payout_method');
						var groups = document.querySelectorAll('.gas-payout-fields');
						function sync() {
							groups.forEach(function(g) {
								g.style.display = ( g.getAttribute('data-method') === select.value ) ? '' : 'none';
							});
						}
						select.addEventListener('change', sync);
						sync();
					})();
				</script>
			<?php endif; ?>
		</body>
		</html>
		<?php
		exit;
	}

	public static function handle_submit_claim() {
		$payout_id = isset( $_POST['payout_id'] ) ? absint( $_POST['payout_id'] ) : 0;
		$email     = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$token     = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

		$payout = self::get_valid_payout( $payout_id, $email, $token );
		if ( ! $payout ) {
			wp_die( 'This claim link is invalid or has expired.', 'Cash Back Claim', array( 'response' => 400 ) );
		}
		check_admin_referer( 'gas_submit_cashback_claim_' . $payout->id );

		// Honeypot: pretend success without saving anything real.
		if ( ! empty( $_POST['gas_hp'] ) ) {
			self::render_thank_you( $payout );
		}

		$saved = self::save_payment_details( $payout->id, wp_unslash( $_POST ) );
		if ( ! $saved ) {
			wp_die( 'Please choose a payout method.', 'Cash Back Claim', array( 'response' => 400 ) );
		}

		GAS_Admin::audit_log( 'payout', $payout->id, 'cashback_claimed', array( 'customer_email' => $email ) );
		self::render_thank_you( $payout );
	}

	private static function render_thank_you( $payout ) {
		$css_url = plugins_url( 'assets/gas-frontend.css', GAS_PLUGIN_FILE );
		nocache_headers();
		?>
		<!doctype html>
		<html>
		<head>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title>Cash Back Claim &mdash; <?php echo esc_html( GAS_Settings::get( 'site_name' ) ); ?></title>
			<link rel="stylesheet" href="<?php echo esc_url( $css_url ); ?>">
			<style>body{max-width:560px;margin:2.5em auto;padding:0 1.2em;font-family:-apple-system,Segoe UI,Roboto,sans-serif;}</style>
		</head>
		<body>
			<h1>Thanks!</h1>
			<p class="gas-notice gas-notice-success">Got it — we'll send your cash back to the details you provided.</p>
		</body>
		</html>
		<?php
		exit;
	}
}
