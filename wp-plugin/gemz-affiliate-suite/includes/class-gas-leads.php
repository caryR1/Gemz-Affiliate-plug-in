<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * On-site lead capture, for partners whose fulfillment_mode is
 * 'lead_capture' rather than the default 'redirect'. GAS_Redirect sends
 * visitors here instead of straight to an external destination_url. Which
 * PARTNER this lead belongs to is read from the gas_campaign_id cookie
 * GAS_Redirect always sets on a valid campaign link; which AFFILIATE (if
 * any) gets credited is read separately from the gas_affiliate_code
 * cookie — the two are independent since campaigns 2026-09-08, so a
 * visitor with no (or a stale) ?ref= still reaches a valid form as long
 * as the campaign itself resolved.
 */
class GAS_Leads {

	const SETTABLE_STATUSES = array( 'accepted', 'in_progress', 'completed', 'lost' );

	public static function init() {
		add_shortcode( 'gas_lead_form', array( __CLASS__, 'render_form' ) );
		add_action( 'init', array( __CLASS__, 'maybe_create_page' ) );
		add_action( 'admin_post_gas_submit_lead', array( __CLASS__, 'handle_submit' ) );
		add_action( 'admin_post_nopriv_gas_submit_lead', array( __CLASS__, 'handle_submit' ) );
		add_action( 'admin_post_gas_update_lead_status', array( __CLASS__, 'handle_update_status' ) );
		add_action( 'admin_post_gas_assign_lead_partner', array( __CLASS__, 'handle_assign_partner' ) );
		add_action( 'admin_post_gas_unassign_lead_partner', array( __CLASS__, 'handle_unassign_partner' ) );
		add_action( 'gas_daily_stale_lead_check', array( __CLASS__, 'check_stale_leads' ) );
	}

	/**
	 * Flags leads that have sat in an active (non-terminal) status for too
	 * long without any update, and pings the site admin so a follow-up
	 * actually happens instead of a lead silently rotting. Runs daily via
	 * wp_schedule_event (see gemz-affiliate-suite.php's plugins_loaded
	 * safety net, so this keeps working after a plain file redeploy
	 * without a manual reactivate). Ported from gemz-referral-crm's
	 * GRC_Admin::check_stale_leads().
	 */
	public static function check_stale_leads() {
		global $wpdb;
		$leads_table    = GAS_DB::table( 'leads' );
		$partners_table = GAS_DB::table( 'partners' );

		$stale_days = (int) apply_filters( 'gas_stale_lead_days', 5 );
		$cutoff     = gmdate( 'Y-m-d H:i:s', strtotime( "-{$stale_days} days", current_time( 'timestamp' ) ) );

		$stale_leads = $wpdb->get_results( $wpdb->prepare( "
			SELECT l.*, p.name AS partner_name
			FROM {$leads_table} l
			LEFT JOIN {$partners_table} p ON p.id = l.partner_id
			WHERE l.status IN ('new','accepted','in_progress')
			AND COALESCE(l.updated_at, l.created_at) < %s
		", $cutoff ) );

		foreach ( $stale_leads as $lead ) {
			$wpdb->update( $leads_table, array( 'status' => 'stale', 'updated_at' => current_time( 'mysql' ) ), array( 'id' => $lead->id ) );

			wp_mail(
				get_option( 'admin_email' ),
				'Stale lead: ' . $lead->customer_name,
				"A lead hasn't been updated in {$stale_days}+ days and has been marked stale.\n\nCustomer: {$lead->customer_name}\nPartner: " . ( $lead->partner_name ?: 'Unknown partner' ) . "\nReceived: {$lead->created_at}\n\nFollow up with the partner, or check in wp-admin under Leads."
			);

			GAS_Admin::audit_log( 'lead', $lead->id, 'marked_stale', array( 'days_inactive' => $stale_days ) );
		}
	}

	/**
	 * Auto-create the "Get a Quote" page once, same pattern as
	 * GAS_Frontend::maybe_create_pages() for the signup/dashboard pages.
	 */
	public static function maybe_create_page() {
		GAS_Help::create_or_adopt_page( 'gas_lead_page_id', 'Get a Quote', 'get-a-quote', '[gas_lead_form]' );
	}

	public static function page_url() {
		$id = get_option( 'gas_lead_page_id' );
		return $id ? get_permalink( $id ) : home_url( '/get-a-quote/' );
	}

	/**
	 * The exact TCPA disclosure a customer checks (and that gets stored
	 * verbatim in consent_text at submit time, as proof of what they
	 * actually agreed to) before a partner calls or texts them. Not
	 * legal advice — standard prior-express-written-consent language,
	 * worth a real attorney pass before this program scales up outreach
	 * volume. Kept generic (site_name/partner_label, not hardcoded
	 * "solar") since this is the shared plugin.
	 */
	public static function consent_label() {
		// Business name, not the site's marketing name (site_name) — this
		// is a legal consent notice naming who's actually authorized to
		// call/text, same reasoning as compliance_footer() preferring
		// business_name; falls back to site_name only if a site hasn't
		// filled that in yet. Cary's own wording (2026-09-11), simplified
		// from the original TCPA boilerplate at his direct request —
		// still not legal advice; the autodialer/prerecorded-voice and
		// "not required to receive service" specifics that used to be
		// spelled out here are gone, worth an attorney's read on whether
		// this shorter version still covers what a real robocall/SMS
		// campaign would need.
		$business_name = GAS_Settings::get( 'business_name' ) ?: GAS_Settings::get( 'site_name' );
		return 'By submitting this form, you grant ' . $business_name . ' and its associated ' . GAS_Settings::get( 'partner_label' ) . ' permission to call and/or text in relation to this quote.';
	}

	/**
	 * The affiliate to credit, if any — optional. An organic visitor (no
	 * ?ref= on the campaign link, or a stale/invalid one) still reaches
	 * this form with no code at all; that's a valid, expected case now
	 * that the code is separate from which partner/campaign a lead
	 * belongs to (see get_campaign_from_cookie() below for that part).
	 */
	private static function get_code_from_cookie() {
		if ( empty( $_COOKIE[ GAS_Redirect::COOKIE_NAME ] ) ) {
			return null;
		}
		global $wpdb;
		$code  = sanitize_text_field( wp_unslash( $_COOKIE[ GAS_Redirect::COOKIE_NAME ] ) );
		$codes = GAS_DB::table( 'codes' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$codes} WHERE code = %s", $code ) );
	}

	/**
	 * Which partner this lead belongs to is now resolved via the CAMPAIGN
	 * the visitor arrived through (GAS_Redirect sets this cookie
	 * independent of whether a ?ref= code resolved) — codes no longer
	 * carry a partner_id of their own for self-signup affiliates, so this
	 * (not the code) is the required part of a valid lead-capture visit.
	 */
	private static function get_campaign_from_cookie() {
		if ( empty( $_COOKIE[ GAS_Redirect::CAMPAIGN_COOKIE_NAME ] ) ) {
			return null;
		}
		return GAS_Campaigns::get( absint( $_COOKIE[ GAS_Redirect::CAMPAIGN_COOKIE_NAME ] ) );
	}

	public static function render_form() {
		if ( isset( $_GET['gas_lead'] ) && 'success' === $_GET['gas_lead'] ) {
			return '<div class="gas-notice gas-notice-success"><p>Thanks &mdash; we\'ve got your information and will be in touch shortly.</p></div>';
		}

		$campaign = self::get_campaign_from_cookie();
		if ( ! $campaign ) {
			return '<div class="gas-notice"><p>We couldn\'t find a referral link for this visit. Please use the link that was shared with you.</p></div>';
		}
		$code = self::get_code_from_cookie(); // optional — organic visits have none

		global $wpdb;
		$partner = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . GAS_DB::table( 'partners' ) . ' WHERE id = %d', $campaign->partner_id ) );
		if ( ! $partner || 'lead_capture' !== $partner->fulfillment_mode ) {
			return '<div class="gas-notice"><p>This link isn\'t set up to take requests directly &mdash; please use the link that was shared with you.</p></div>';
		}

		$error = isset( $_GET['gas_error'] ) ? sanitize_text_field( wp_unslash( $_GET['gas_error'] ) ) : '';

		ob_start();
		if ( $error ) {
			echo '<div class="gas-notice gas-notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}
		// Wrapped in a real card (2026-09-11) rather than bare form fields
		// floating on the page — this shortcode was silently missing from
		// STYLED_SHORTCODES until the same day (see the note there), so
		// gas-frontend.css never even loaded here; this is the first time
		// the panel treatment every other GAS-rendered page already gets
		// has actually applied to Get a Quote.
		echo '<div class="gas-panel gas-quote-panel">';
		$quote_image_id = GAS_Settings::get( 'quote_page_image_id' );
		if ( ! empty( $quote_image_id ) ) {
			echo wp_get_attachment_image( $quote_image_id, 'medium_large', false, array( 'style' => 'width:100%;height:auto;border-radius:8px;margin-bottom:1.2em;' ) );
		}
		$quote_intro = GAS_Settings::get( 'quote_page_intro' );
		if ( ! empty( $quote_intro ) ) {
			echo '<div class="gas-lead-intro"><p>' . nl2br( esc_html( $quote_intro ) ) . '</p></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gas-form">
			<?php wp_nonce_field( 'gas_submit_lead' ); ?>
			<input type="hidden" name="action" value="gas_submit_lead">
			<input type="hidden" name="campaign_id" value="<?php echo esc_attr( $campaign->id ); ?>">
			<?php if ( $code ) : ?>
				<input type="hidden" name="code" value="<?php echo esc_attr( $code->code ); ?>">
			<?php endif; ?>
			<p style="position:absolute;left:-9999px;" aria-hidden="true">
				<label>Leave this field empty<input type="text" name="gas_hp" tabindex="-1" autocomplete="off"></label>
			</p>

			<p>
				<label for="gas_lead_name">Your name</label><br>
				<input type="text" id="gas_lead_name" name="name" required class="gas-input">
			</p>
			<p>
				<label for="gas_lead_address">Address</label><br>
				<input type="text" id="gas_lead_address" name="address" class="gas-input">
			</p>
			<p>
				<label for="gas_lead_state">State</label><br>
				<select id="gas_lead_state" name="state" class="gas-input"><?php echo GAS_DB::state_dropdown_options(); ?></select>
				<span class="gas-fineprint">Lets us match you to a partner that actually covers your area.</span>
			</p>
			<p>
				<label for="gas_lead_phone">Phone</label><br>
				<input type="tel" id="gas_lead_phone" name="phone" class="gas-input">
			</p>
			<p>
				<label for="gas_lead_email">Email</label><br>
				<input type="email" id="gas_lead_email" name="email" class="gas-input">
			</p>
			<p id="gas-consent-row" style="display:none;">
				<label><input type="checkbox" id="gas_consent_call_text" name="consent_call_text" value="1"> <strong>Permission to call or text:</strong> <?php echo esc_html( self::consent_label() ); ?></label>
			</p>
			<script>
				(function() {
					var phone   = document.getElementById('gas_lead_phone');
					var row     = document.getElementById('gas-consent-row');
					var consent = document.getElementById('gas_consent_call_text');
					function sync() {
						var needed = phone.value.trim() !== '';
						row.style.display = needed ? '' : 'none';
						if ( consent ) { consent.required = needed; }
					}
					phone.addEventListener('input', sync);
					sync();
				})();
			</script>
			<?php if ( $partner->requires_appointment ) : ?>
				<p>
					<label for="gas_lead_appointment">Preferred appointment time</label><br>
					<input type="datetime-local" id="gas_lead_appointment" name="appointment_at" required class="gas-input">
				</p>
			<?php endif; ?>
			<p>
				<button type="submit" class="gas-button">Submit</button>
			</p>
			<p class="gas-fineprint" style="text-align:center;">Free, no-obligation quote &mdash; we'll be in touch soon.</p>
		</form>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function handle_submit() {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'gas_submit_lead' ) ) {
			wp_die( 'Security check failed. Please go back and try again.' );
		}

		$redirect_back = wp_get_referer() ? wp_get_referer() : self::page_url();

		// Honeypot: pretend success without creating anything.
		if ( ! empty( $_POST['gas_hp'] ) ) {
			wp_safe_redirect( add_query_arg( 'gas_lead', 'success', $redirect_back ) );
			exit;
		}

		$fail = function( $msg ) use ( $redirect_back ) {
			wp_safe_redirect( add_query_arg( 'gas_error', rawurlencode( $msg ), $redirect_back ) );
			exit;
		};

		global $wpdb;
		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
		$campaign    = $campaign_id ? GAS_Campaigns::get( $campaign_id ) : null;
		if ( ! $campaign ) {
			$fail( 'Something went wrong identifying your referral link. Please try again from the original link.' );
		}

		// The affiliate to credit, if any — optional, same as at render
		// time; an organic (no-ref) submission is a valid lead, just with
		// no one to pay a commission on it.
		$code_str = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		$code     = $code_str ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . GAS_DB::table( 'codes' ) . ' WHERE code = %s', $code_str ) ) : null;

		$partner = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . GAS_DB::table( 'partners' ) . ' WHERE id = %d', $campaign->partner_id ) );
		if ( ! $partner || 'lead_capture' !== $partner->fulfillment_mode ) {
			$fail( 'This link isn\'t set up to take requests directly.' );
		}

		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$phone   = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$address = isset( $_POST['address'] ) ? sanitize_text_field( wp_unslash( $_POST['address'] ) ) : '';
		// Real dropdown as of 2026-09-10 (was missing entirely before —
		// the exact gap Solar flagged the same day coverage-matching
		// couldn't use leads from this form). array_key_exists against
		// GAS_DB::us_states() rather than trusting the raw POST value, so
		// a tampered request can't inject an arbitrary string here.
		$state_raw = isset( $_POST['state'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['state'] ) ) ) : '';
		$state     = array_key_exists( $state_raw, GAS_DB::us_states() ) ? $state_raw : '';

		if ( '' === $name ) {
			$fail( 'Please enter your name.' );
		}
		if ( '' === $email && '' === $phone ) {
			$fail( 'Please provide an email or phone number so we can reach you.' );
		}

		// TCPA: a phone number means a partner may call/text it, so we
		// need this specific person's own consent on file first — see
		// consent_label() and the note on leads.consent_* in
		// class-gas-db.php. Only required when a phone was actually
		// given; an email-only submission has nothing to consent to here.
		$consented = ! empty( $_POST['consent_call_text'] );
		if ( '' !== $phone && ! $consented ) {
			$fail( 'Please check the box to agree to be contacted by phone/text, or leave the phone field blank.' );
		}

		$appointment_at = null;
		if ( $partner->requires_appointment ) {
			$raw = isset( $_POST['appointment_at'] ) ? sanitize_text_field( wp_unslash( $_POST['appointment_at'] ) ) : '';
			if ( '' === $raw ) {
				$fail( 'Please choose a preferred appointment time.' );
			}
			$timestamp = strtotime( $raw );
			if ( ! $timestamp ) {
				$fail( 'That appointment time didn\'t look valid &mdash; please try again.' );
			}
			$appointment_at = gmdate( 'Y-m-d H:i:s', $timestamp );
		}

		$wpdb->insert(
			GAS_DB::table( 'leads' ),
			array(
				'partner_id'     => $partner->id,
				'code_id'        => $code ? $code->id : null,
				'campaign_id'    => $campaign->id,
				'customer_name'  => $name,
				'customer_email' => $email,
				'customer_phone' => $phone,
				'customer_address' => $address,
				'customer_state' => $state,
				'appointment_at' => $appointment_at,
				'status'         => 'new',
				'created_at'     => current_time( 'mysql' ),
				'consent_call_text' => $consented ? 1 : 0,
				'consent_text'      => $consented ? self::consent_label() : null,
				'consent_at'        => $consented ? current_time( 'mysql' ) : null,
				'consent_ip'        => $consented ? GAS_Fraud::get_client_ip() : null,
			)
		);

		if ( $email ) {
			GAS_Contacts::upsert( $email, 'customer', array( 'name' => $name, 'phone' => $phone, 'source' => 'lead_form', 'related_table' => 'leads', 'related_id' => $wpdb->insert_id ) );
		}

		$consent_line = $phone
			? ( $consented ? 'Yes, recorded ' . current_time( 'mysql' ) . ' from IP ' . GAS_Fraud::get_client_ip() : 'NO — do not call/text this number.' )
			: 'N/A (no phone provided)';

		wp_mail(
			get_option( 'admin_email' ),
			'New lead: ' . $name . ' for ' . $partner->name,
			"A new lead came in via " . GAS_Settings::get( 'site_name' ) . ".\n\nName: {$name}\nAddress: {$address}\nEmail: {$email}\nPhone: {$phone}\nCall/text consent: {$consent_line}\nPartner: {$partner->name}\nReferral code: " . ( $code ? $code->code : '(none — organic visit)' ) . ( $appointment_at ? "\nRequested appointment: {$appointment_at}" : '' )
		);

		wp_safe_redirect( add_query_arg( 'gas_lead', 'success', self::page_url() ) );
		exit;
	}

	/**
	 * Admin-side status update — the first-touch triage gate a lead has to
	 * clear (out of 'new') before it's visible to the partner's own status
	 * dropdown in the Partner Portal. Also the trigger point for relaying
	 * the lead to the partner (see relay_lead_to_partner()).
	 */
	public static function handle_update_status() {
		if ( ! current_user_can( 'gas_manage_leads' ) ) {
			wp_die( 'Not allowed.' );
		}
		$id = isset( $_POST['lead_id'] ) ? absint( $_POST['lead_id'] ) : 0;
		check_admin_referer( 'gas_update_lead_status_' . $id );

		$status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
		if ( ! in_array( $status, self::SETTABLE_STATUSES, true ) ) {
			wp_die( 'Invalid status.' );
		}

		global $wpdb;
		$leads_table = GAS_DB::table( 'leads' );
		$lead        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$leads_table} WHERE id = %d", $id ) );

		$wpdb->update(
			$leads_table,
			array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $id )
		);
		GAS_Admin::audit_log( 'lead', $id, 'status_changed', array( 'status' => $status ) );

		// Relay to the partner only the first time a lead clears the 'new'
		// gate — that's the one moment this admin action is actually
		// telling the partner something new, not just moving it further
		// along a pipeline the partner already has full visibility into.
		if ( $lead && 'new' === $lead->status ) {
			self::relay_lead_to_partner( $lead );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=gas-leads&updated=1' ) );
		exit;
	}

	/**
	 * Admin action for the one case the normal cookie-attributed lead flow
	 * doesn't cover: a referral submitted through the merged signup/refer
	 * page (see GAS_Frontend::create_referral_lead()) arrives with no
	 * partner yet, by design — an affiliate never picks their own partner,
	 * on any project this plugin runs. This is where an admin actually
	 * makes that match after the fact, same moment we now know for the
	 * first time whether the assigned partner needs an appointment, so
	 * that's also the earliest honest moment to propose one to the
	 * customer — never invented sooner, and never left for the customer
	 * to guess at.
	 */
	public static function handle_assign_partner() {
		if ( ! current_user_can( self::manage_cap() ) ) {
			wp_die( 'Not allowed.' );
		}
		$lead_id = isset( $_POST['lead_id'] ) ? absint( $_POST['lead_id'] ) : 0;
		check_admin_referer( 'gas_assign_lead_partner_' . $lead_id );

		$partner_id  = isset( $_POST['partner_id'] ) ? absint( $_POST['partner_id'] ) : 0;
		$proposed_at = isset( $_POST['proposed_at'] ) ? sanitize_text_field( wp_unslash( $_POST['proposed_at'] ) ) : '';
		$backup_at   = isset( $_POST['backup_at'] ) ? sanitize_text_field( wp_unslash( $_POST['backup_at'] ) ) : '';

		if ( ! $lead_id || ! $partner_id ) {
			wp_die( 'Missing lead or partner.' );
		}

		self::assign_partner( $lead_id, $partner_id, $proposed_at, $backup_at );

		wp_safe_redirect( admin_url( 'admin.php?page=gas-leads&updated=1' ) );
		exit;
	}

	private static function manage_cap() {
		return apply_filters( 'gas_leads_manage_cap', 'gas_manage_leads' );
	}

	/**
	 * Matches a lead to a partner and tells the customer — with a real
	 * proposed appointment time (plus a backup) if that partner requires
	 * one, or a simpler "you've been matched" note if not. Also relays
	 * the lead to the partner the same way any other freshly-assigned
	 * lead already is, so this doesn't become a second, less-visible path.
	 */
	public static function assign_partner( $lead_id, $partner_id, $proposed_at = '', $backup_at = '' ) {
		global $wpdb;
		$leads_table = GAS_DB::table( 'leads' );
		$lead        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$leads_table} WHERE id = %d", $lead_id ) );
		$partner     = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . GAS_DB::table( 'partners' ) . ' WHERE id = %d', $partner_id ) );
		if ( ! $lead || ! $partner ) {
			return false;
		}

		$proposed_fmt = $proposed_at ? self::format_datetime_local( $proposed_at ) : '';
		$backup_fmt   = $backup_at ? self::format_datetime_local( $backup_at ) : '';

		$wpdb->update(
			$leads_table,
			array(
				'partner_id' => $partner_id,
				'status'     => 'accepted',
				'updated_at' => current_time( 'mysql' ),
				'notes'      => $proposed_fmt ? trim( ( $lead->notes ? $lead->notes . "\n" : '' ) . "Proposed appointment: {$proposed_fmt}" . ( $backup_fmt ? " (backup: {$backup_fmt})" : '' ) ) : $lead->notes,
			),
			array( 'id' => $lead_id )
		);

		if ( $lead->customer_email ) {
			$site_name = GAS_Settings::get( 'site_name' );
			if ( $partner->requires_appointment && $proposed_fmt ) {
				$body = "Hi {$lead->customer_name},\n\nGood news — you've been matched with {$partner->name}. We'd like to propose an appointment:\n\nPreferred: {$proposed_fmt}" . ( $backup_fmt ? "\nBackup: {$backup_fmt}" : '' ) . "\n\nReply to this email to confirm one of these times, or suggest another that works better for you.";
			} else {
				$body = "Hi {$lead->customer_name},\n\nGood news — you've been matched with {$partner->name}. They'll be reaching out to you directly with next steps.";
			}
			wp_mail( $lead->customer_email, 'You\'ve been matched with a ' . GAS_Settings::get( 'partner_label' ), $body . GAS_Settings::compliance_footer( $lead->customer_email ) );
		}

		self::relay_lead_to_partner( $lead );
		GAS_Admin::audit_log( 'lead', $lead_id, 'partner_assigned', array( 'partner_id' => $partner_id ) );

		return true;
	}

	/**
	 * Breaks an existing partner assignment and sends the lead back to the
	 * same "needs matching" state a fresh, never-assigned lead is in —
	 * added 2026-09-10 after Cary flagged a real gap: once assign_partner()
	 * ran, there was no way to undo it, whether the match was made in
	 * error or the partner themselves didn't want the lead. Reuses
	 * `status = 'new'` deliberately rather than inventing a separate
	 * "declined" status: that's already exactly what "needs an admin to
	 * match a partner" means everywhere else in this class (see
	 * render_leads_page()'s partner-select form, shown whenever
	 * partner_id is empty) and assign_partner() re-relays to whichever
	 * partner is picked next regardless of the lead's prior status, so no
	 * other code needed to change to make re-matching work. The old
	 * partner isn't just silently dropped — it's recorded in notes so
	 * whoever re-matches this lead has the context.
	 */
	public static function unassign_partner( $lead_id, $reason = '' ) {
		global $wpdb;
		$leads_table = GAS_DB::table( 'leads' );
		$lead        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$leads_table} WHERE id = %d", $lead_id ) );
		if ( ! $lead ) {
			return false;
		}

		$old_partner_name = '(none)';
		if ( $lead->partner_id ) {
			$old_partner_name = $wpdb->get_var( $wpdb->prepare( 'SELECT name FROM ' . GAS_DB::table( 'partners' ) . ' WHERE id = %d', $lead->partner_id ) ) ?: 'a deleted partner';
		}

		$note_line = 'Unassigned from ' . $old_partner_name . ' on ' . current_time( 'mysql' ) . ( $reason ? " ({$reason})" : '' ) . '.';

		$wpdb->update(
			$leads_table,
			array(
				'partner_id' => 0,
				'status'     => 'new',
				'updated_at' => current_time( 'mysql' ),
				'notes'      => trim( ( $lead->notes ? $lead->notes . "\n" : '' ) . $note_line ),
			),
			array( 'id' => $lead_id )
		);

		GAS_Admin::audit_log( 'lead', $lead_id, 'partner_unassigned', array( 'old_partner_id' => $lead->partner_id, 'reason' => $reason ) );

		// Otherwise this could silently sit unassigned indefinitely — the
		// same visibility a brand-new lead gets via the "New lead" email,
		// just for the re-matching case instead of the first-match one.
		wp_mail(
			get_option( 'admin_email' ),
			'Lead needs rematching: ' . $lead->customer_name,
			"A lead was unassigned from {$old_partner_name} and needs to be matched to a partner again.\n\nCustomer: {$lead->customer_name}\n" . ( $reason ? "Reason: {$reason}\n" : '' ) . "\nMatch it to a new partner in wp-admin under Leads."
		);

		return true;
	}

	/**
	 * Admin-side correction for a match made in error — the counterpart
	 * to handle_assign_partner(), for a lead that's already assigned.
	 */
	public static function handle_unassign_partner() {
		if ( ! current_user_can( self::manage_cap() ) ) {
			wp_die( 'Not allowed.' );
		}
		$lead_id = isset( $_POST['lead_id'] ) ? absint( $_POST['lead_id'] ) : 0;
		check_admin_referer( 'gas_unassign_lead_partner_' . $lead_id );

		if ( ! $lead_id ) {
			wp_die( 'Missing lead.' );
		}

		self::unassign_partner( $lead_id, 'Unassigned by admin' );

		wp_safe_redirect( admin_url( 'admin.php?page=gas-leads&updated=1' ) );
		exit;
	}

	private static function format_datetime_local( $raw ) {
		$timestamp = strtotime( $raw );
		return $timestamp ? date_i18n( 'l, F j, Y \a\t g:ia', $timestamp ) : $raw;
	}

	/**
	 * Emails the assigned partner the lead's details directly, once —
	 * previously the only way a partner ever found out about a lead was
	 * logging into their own portal and noticing a new row. Silently does
	 * nothing if the partner has no email on file (same as their portal
	 * account itself — nothing to relay to).
	 */
	private static function relay_lead_to_partner( $lead ) {
		global $wpdb;
		$partner = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . GAS_DB::table( 'partners' ) . ' WHERE id = %d', $lead->partner_id ) );
		if ( ! $partner || empty( $partner->email ) ) {
			return;
		}

		// TCPA: tell the partner plainly whether THEY have permission to
		// call/text this number — they're the one actually dialing, so
		// this can't just live in wp-admin where they'll never see it.
		if ( ! $lead->customer_phone ) {
			$consent_line = 'N/A (no phone on file)';
		} elseif ( $lead->consent_call_text ) {
			$consent_line = 'Yes — this customer agreed to be called/texted at this number (recorded ' . $lead->consent_at . ').';
		} else {
			$consent_line = 'NOT ON FILE — this lead was referred by someone else, not submitted by the customer themselves. Do not autodial or text this number until you\'ve gotten the customer\'s own consent (a live, non-automated call is a separate question — check with your own compliance team).';
		}

		$body = "A new lead has been assigned to you via " . GAS_Settings::get( 'site_name' ) . ".\n\n"
			. "Name: {$lead->customer_name}\n"
			. 'Address: ' . ( $lead->customer_address ?: '(not provided)' ) . "\n"
			. 'Phone: ' . ( $lead->customer_phone ?: '(not provided)' ) . "\n"
			. "Call/text consent: {$consent_line}\n"
			. 'Email: ' . ( $lead->customer_email ?: '(not provided)' ) . "\n"
			. ( $lead->appointment_at ? "Requested appointment: {$lead->appointment_at}\n" : '' )
			. "\nLog in to your Partner Portal to update this lead's status as you work it: " . GAS_Partner_Portal::page_url();

		// Compliance footer (incl. unsubscribe) added 2026-09-08 — this was
		// the one contact-facing template deliberately left without it
		// when the footer first shipped (scoped to "customer AND
		// affiliate-facing" only, not partner); broadened once Cary
		// confirmed he wants it on all three contact types, not just two.
		wp_mail( $partner->email, 'New lead: ' . $lead->customer_name, $body . GAS_Settings::compliance_footer( $partner->email ) );
		GAS_Admin::audit_log( 'lead', $lead->id, 'relayed_to_partner', array( 'partner_email' => $partner->email ) );
	}
}
