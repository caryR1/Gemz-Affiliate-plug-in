<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * On-site lead capture, for partners whose fulfillment_mode is
 * 'lead_capture' rather than the default 'redirect'. GAS_Redirect sends
 * visitors here instead of straight to an external destination_url; the
 * attributing code is read from the same gas_affiliate_code cookie the
 * redirect handler already sets, so this form needs no query-string
 * attribution of its own.
 */
class GAS_Leads {

	const SETTABLE_STATUSES = array( 'accepted', 'in_progress', 'completed', 'lost' );

	public static function init() {
		add_shortcode( 'gas_lead_form', array( __CLASS__, 'render_form' ) );
		add_action( 'init', array( __CLASS__, 'maybe_create_page' ) );
		add_action( 'admin_post_gas_submit_lead', array( __CLASS__, 'handle_submit' ) );
		add_action( 'admin_post_nopriv_gas_submit_lead', array( __CLASS__, 'handle_submit' ) );
		add_action( 'admin_post_gas_update_lead_status', array( __CLASS__, 'handle_update_status' ) );
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
		if ( get_option( 'gas_lead_page_id' ) ) {
			return;
		}
		$id = wp_insert_post( array(
			'post_title'   => 'Get a Quote',
			'post_name'    => 'get-a-quote',
			'post_content' => '[gas_lead_form]',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		) );
		if ( $id && ! is_wp_error( $id ) ) {
			update_option( 'gas_lead_page_id', $id );
		}
	}

	public static function page_url() {
		$id = get_option( 'gas_lead_page_id' );
		return $id ? get_permalink( $id ) : home_url( '/get-a-quote/' );
	}

	private static function get_code_from_cookie() {
		if ( empty( $_COOKIE[ GAS_Redirect::COOKIE_NAME ] ) ) {
			return null;
		}
		global $wpdb;
		$code  = sanitize_text_field( wp_unslash( $_COOKIE[ GAS_Redirect::COOKIE_NAME ] ) );
		$codes = GAS_DB::table( 'codes' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$codes} WHERE code = %s", $code ) );
	}

	public static function render_form() {
		if ( isset( $_GET['gas_lead'] ) && 'success' === $_GET['gas_lead'] ) {
			return '<div class="gas-notice gas-notice-success"><p>Thanks &mdash; we\'ve got your information and will be in touch shortly.</p></div>';
		}

		$code = self::get_code_from_cookie();
		if ( ! $code ) {
			return '<div class="gas-notice"><p>We couldn\'t find a referral link for this visit. Please use the link that was shared with you.</p></div>';
		}

		global $wpdb;
		$partner = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . GAS_DB::table( 'partners' ) . ' WHERE id = %d', $code->partner_id ) );
		if ( ! $partner || 'lead_capture' !== $partner->fulfillment_mode ) {
			return '<div class="gas-notice"><p>This link isn\'t set up to take requests directly &mdash; please use the link that was shared with you.</p></div>';
		}

		$error = isset( $_GET['gas_error'] ) ? sanitize_text_field( wp_unslash( $_GET['gas_error'] ) ) : '';

		ob_start();
		if ( $error ) {
			echo '<div class="gas-notice gas-notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gas-form">
			<?php wp_nonce_field( 'gas_submit_lead' ); ?>
			<input type="hidden" name="action" value="gas_submit_lead">
			<input type="hidden" name="code" value="<?php echo esc_attr( $code->code ); ?>">
			<p style="position:absolute;left:-9999px;" aria-hidden="true">
				<label>Leave this field empty<input type="text" name="gas_hp" tabindex="-1" autocomplete="off"></label>
			</p>

			<p>
				<label for="gas_lead_name">Your name</label><br>
				<input type="text" id="gas_lead_name" name="name" required class="gas-input">
			</p>
			<p>
				<label for="gas_lead_email">Email</label><br>
				<input type="email" id="gas_lead_email" name="email" class="gas-input">
			</p>
			<p>
				<label for="gas_lead_phone">Phone</label><br>
				<input type="tel" id="gas_lead_phone" name="phone" class="gas-input">
			</p>
			<?php if ( $partner->requires_appointment ) : ?>
				<p>
					<label for="gas_lead_appointment">Preferred appointment time</label><br>
					<input type="datetime-local" id="gas_lead_appointment" name="appointment_at" required class="gas-input">
				</p>
			<?php endif; ?>
			<p>
				<button type="submit" class="gas-button">Submit</button>
			</p>
		</form>
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
		$code_str = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		$codes    = GAS_DB::table( 'codes' );
		$code     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$codes} WHERE code = %s", $code_str ) );
		if ( ! $code ) {
			$fail( 'Something went wrong identifying your referral link. Please try again from the original link.' );
		}

		$partner = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . GAS_DB::table( 'partners' ) . ' WHERE id = %d', $code->partner_id ) );
		if ( ! $partner || 'lead_capture' !== $partner->fulfillment_mode ) {
			$fail( 'This link isn\'t set up to take requests directly.' );
		}

		$name  = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';

		if ( '' === $name ) {
			$fail( 'Please enter your name.' );
		}
		if ( '' === $email && '' === $phone ) {
			$fail( 'Please provide an email or phone number so we can reach you.' );
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
				'code_id'        => $code->id,
				'customer_name'  => $name,
				'customer_email' => $email,
				'customer_phone' => $phone,
				'appointment_at' => $appointment_at,
				'status'         => 'new',
				'created_at'     => current_time( 'mysql' ),
			)
		);

		if ( $email ) {
			GAS_Contacts::upsert( $email, 'customer', array( 'name' => $name, 'phone' => $phone, 'source' => 'lead_form', 'related_table' => 'leads', 'related_id' => $wpdb->insert_id ) );
		}

		wp_mail(
			get_option( 'admin_email' ),
			'New lead: ' . $name . ' for ' . $partner->name,
			"A new lead came in via " . GAS_Settings::get( 'site_name' ) . ".\n\nName: {$name}\nEmail: {$email}\nPhone: {$phone}\nPartner: {$partner->name}\nReferral code: {$code->code}" . ( $appointment_at ? "\nRequested appointment: {$appointment_at}" : '' )
		);

		wp_safe_redirect( add_query_arg( 'gas_lead', 'success', self::page_url() ) );
		exit;
	}

	/**
	 * Admin-only manual status update for now (phase 1). A partner
	 * self-service portal mirroring gemz-referral-crm's GRC_Partner_Dashboard
	 * is planned as the next phase — this gives Cary a way to track leads
	 * in the meantime.
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
		$wpdb->update(
			GAS_DB::table( 'leads' ),
			array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $id )
		);
		GAS_Admin::audit_log( 'lead', $id, 'status_changed', array( 'status' => $status ) );

		wp_safe_redirect( admin_url( 'admin.php?page=gas-leads&updated=1' ) );
		exit;
	}
}
