<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-end fulfillment partner self-service dashboard — ported from
 * gemz-referral-crm's GRC_Partner_Dashboard. Looks up the partner record
 * by the CURRENT logged-in user's id, never by a posted partner_id, so a
 * partner can only ever see and update their own leads.
 */
class GAS_Partner_Portal {

	public static function init() {
		add_shortcode( 'gas_partner_dashboard', array( __CLASS__, 'render_dashboard' ) );
		add_action( 'init', array( __CLASS__, 'maybe_create_page' ) );
		add_action( 'admin_post_gas_partner_login', array( __CLASS__, 'handle_login' ) );
		add_action( 'admin_post_nopriv_gas_partner_login', array( __CLASS__, 'handle_login' ) );
		add_action( 'admin_post_gas_partner_update_lead_status', array( __CLASS__, 'handle_update_lead_status' ) );
		add_action( 'admin_post_gas_partner_change_password', array( __CLASS__, 'handle_change_password' ) );
	}

	/**
	 * Auto-create the Partner Portal page once, same pattern as
	 * GAS_Frontend::maybe_create_pages() and GAS_Leads::maybe_create_page().
	 */
	public static function maybe_create_page() {
		if ( get_option( 'gas_partner_page_id' ) ) {
			return;
		}
		$id = wp_insert_post( array(
			'post_title'   => 'Partner Portal',
			'post_name'    => 'partner-portal',
			'post_content' => '[gas_partner_dashboard]',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		) );
		if ( $id && ! is_wp_error( $id ) ) {
			update_option( 'gas_partner_page_id', $id );
		}
	}

	public static function page_url() {
		$id = get_option( 'gas_partner_page_id' );
		return $id ? get_permalink( $id ) : home_url( '/partner-portal/' );
	}

	private static function get_current_partner() {
		if ( ! is_user_logged_in() ) {
			return null;
		}
		global $wpdb;
		$table = GAS_DB::table( 'partners' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE user_id = %d", get_current_user_id() ) );
	}

	public static function render_dashboard() {
		if ( ! is_user_logged_in() ) {
			return self::render_login_form();
		}

		$partner = self::get_current_partner();
		if ( ! $partner ) {
			return '<div class="gas-notice">This portal is for fulfillment partners only. <a href="' . esc_url( wp_logout_url( self::page_url() ) ) . '">Log out</a> or contact us if you think this is a mistake.</div>';
		}

		global $wpdb;
		$leads_table = GAS_DB::table( 'leads' );
		$leads = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$leads_table} WHERE partner_id = %d ORDER BY created_at DESC LIMIT 100",
			$partner->id
		) );

		$total_leads  = count( $leads );
		$closed_leads = 0;
		foreach ( $leads as $l ) {
			if ( 'completed' === $l->status ) {
				$closed_leads++;
			}
		}
		$success_rate = $total_leads > 0 ? round( ( $closed_leads / $total_leads ) * 100 ) . '%' : '&mdash;';

		$status_updated   = ! empty( $_GET['updated'] );
		$password_changed = ! empty( $_GET['password_changed'] );
		$password_error   = isset( $_GET['password_error'] ) ? sanitize_text_field( wp_unslash( $_GET['password_error'] ) ) : '';

		ob_start();

		echo '<div class="gas-dashboard">';
		echo '<p>Welcome back, ' . esc_html( $partner->name ) . '. <a href="' . esc_url( wp_logout_url( self::page_url() ) ) . '">Log out</a> &middot; <a href="' . esc_url( GAS_Help::partner_page_url() ) . '">Help</a></p>';

		echo '<div class="gas-stat-row">';
		echo '<div class="gas-stat"><span class="gas-stat-num">' . esc_html( $total_leads ) . '</span><span class="gas-stat-label">Total leads</span></div>';
		echo '<div class="gas-stat"><span class="gas-stat-num">' . esc_html( $closed_leads ) . '</span><span class="gas-stat-label">Completed</span></div>';
		echo '<div class="gas-stat"><span class="gas-stat-num">' . esc_html( $success_rate ) . '</span><span class="gas-stat-label">Success rate</span></div>';
		echo '</div>';

		echo '<h2>Your leads</h2>';
		if ( $status_updated ) {
			echo '<div class="gas-notice gas-notice-success"><p>Status updated.</p></div>';
		}

		if ( ! $leads ) {
			echo '<p>No leads yet &mdash; they\'ll show up here as they come in.</p>';
		} else {
			echo '<table class="gas-portal-table" style="width:100%;border-collapse:collapse;"><thead><tr><th>Customer</th><th>Contact</th><th>Appointment</th><th>Status</th><th>Received</th></tr></thead><tbody>';
			foreach ( $leads as $l ) {
				echo '<tr>';
				echo '<td>' . esc_html( $l->customer_name ) . '</td>';
				echo '<td>' . esc_html( $l->customer_email ) . ( $l->customer_email && $l->customer_phone ? '<br>' : '' ) . esc_html( $l->customer_phone ) . '</td>';
				echo '<td>' . ( $l->appointment_at ? esc_html( $l->appointment_at ) : '&mdash;' ) . '</td>';
				echo '<td>';
				if ( in_array( $l->status, GAS_Leads::SETTABLE_STATUSES, true ) ) {
					echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
					wp_nonce_field( 'gas_partner_update_lead_status_' . $l->id );
					echo '<input type="hidden" name="action" value="gas_partner_update_lead_status">';
					echo '<input type="hidden" name="lead_id" value="' . esc_attr( $l->id ) . '">';
					echo '<select name="status" onchange="this.form.submit()">';
					foreach ( GAS_Leads::SETTABLE_STATUSES as $s ) {
						echo '<option value="' . esc_attr( $s ) . '"' . selected( $l->status, $s, false ) . '>' . esc_html( ucwords( str_replace( '_', ' ', $s ) ) ) . '</option>';
					}
					echo '</select>';
					echo '<noscript><button type="submit" class="button">Update</button></noscript>';
					echo '</form>';
				} else {
					// 'new' hasn't been matched to this partner's queue yet
					// by an admin — nothing for the partner to set until then.
					echo esc_html( ucwords( str_replace( '_', ' ', $l->status ) ) );
				}
				echo '</td>';
				echo '<td>' . esc_html( $l->created_at ) . '</td>';
				echo '</tr>';
			}
			echo '</tbody></table>';
		}

		echo '<h2>Change your password</h2>';
		if ( $password_changed ) {
			echo '<p class="gas-notice gas-notice-success">Your password was updated.</p>';
		} elseif ( $password_error ) {
			echo '<p class="gas-notice gas-notice-error">' . esc_html( $password_error ) . '</p>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gas-form">
			<?php wp_nonce_field( 'gas_partner_change_password' ); ?>
			<input type="hidden" name="action" value="gas_partner_change_password">
			<p>
				<label for="gas_partner_current_password">Current password</label><br>
				<input type="password" id="gas_partner_current_password" name="current_password" required class="gas-input">
			</p>
			<p>
				<label for="gas_partner_new_password">New password</label><br>
				<input type="password" id="gas_partner_new_password" name="new_password" required minlength="8" class="gas-input">
			</p>
			<p>
				<label for="gas_partner_new_password2">Confirm new password</label><br>
				<input type="password" id="gas_partner_new_password2" name="new_password2" required minlength="8" class="gas-input">
			</p>
			<p><button type="submit" class="gas-button">Update password</button></p>
		</form>
		<?php
		echo '</div>';

		return ob_get_clean();
	}

	private static function render_login_form() {
		ob_start();
		$error = isset( $_GET['gas_error'] ) ? sanitize_text_field( wp_unslash( $_GET['gas_error'] ) ) : '';
		if ( $error ) {
			echo '<div class="gas-notice gas-notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gas-form">
			<?php wp_nonce_field( 'gas_partner_login' ); ?>
			<input type="hidden" name="action" value="gas_partner_login">
			<p>
				<label for="gas_partner_username">Email or username</label><br>
				<input type="text" id="gas_partner_username" name="gas_username" required class="gas-input">
			</p>
			<p>
				<label for="gas_partner_login_password">Password</label><br>
				<input type="password" id="gas_partner_login_password" name="gas_login_password" required class="gas-input">
			</p>
			<p><button type="submit" class="gas-button">Log in</button></p>
			<p class="gas-fineprint">
				Forgot your password? <a href="<?php echo esc_url( wp_lostpassword_url( self::page_url() ) ); ?>">Reset it</a>.
			</p>
		</form>
		<?php
		return ob_get_clean();
	}

	public static function handle_login() {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'gas_partner_login' ) ) {
			wp_die( 'Security check failed. Please go back and try again.' );
		}

		$creds = array(
			'user_login'    => isset( $_POST['gas_username'] ) ? sanitize_text_field( wp_unslash( $_POST['gas_username'] ) ) : '',
			'user_password' => isset( $_POST['gas_login_password'] ) ? (string) $_POST['gas_login_password'] : '',
			'remember'      => true,
		);

		$user = wp_signon( $creds, is_ssl() );

		if ( is_wp_error( $user ) ) {
			wp_safe_redirect( add_query_arg( 'gas_error', rawurlencode( 'Login failed: check your email/username and password.' ), self::page_url() ) );
			exit;
		}

		wp_safe_redirect( self::page_url() );
		exit;
	}

	/**
	 * A partner can only ever move their OWN leads — the lead's partner_id
	 * is checked against their own partner record, never trusted from the
	 * posted lead_id alone, so tampering with the id can't touch someone
	 * else's lead.
	 */
	public static function handle_update_lead_status() {
		$partner = self::get_current_partner();
		if ( ! $partner || ! current_user_can( 'gas_update_own_lead_status' ) ) {
			wp_die( 'Not allowed.' );
		}

		$lead_id = isset( $_POST['lead_id'] ) ? absint( $_POST['lead_id'] ) : 0;
		check_admin_referer( 'gas_partner_update_lead_status_' . $lead_id );

		$status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
		if ( ! in_array( $status, GAS_Leads::SETTABLE_STATUSES, true ) ) {
			wp_die( 'Invalid status.' );
		}

		global $wpdb;
		$leads_table = GAS_DB::table( 'leads' );
		$lead        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$leads_table} WHERE id = %d", $lead_id ) );

		if ( ! $lead || (int) $lead->partner_id !== (int) $partner->id ) {
			wp_die( 'That lead does not belong to your account.' );
		}

		$wpdb->update(
			$leads_table,
			array( 'status' => $status, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $lead_id )
		);
		GAS_Admin::audit_log( 'lead', $lead_id, 'status_changed_by_partner', array( 'status' => $status ) );

		wp_safe_redirect( add_query_arg( 'updated', '1', self::page_url() ) );
		exit;
	}

	public static function handle_change_password() {
		if ( ! is_user_logged_in() ) {
			wp_die( 'Please log in first.' );
		}
		check_admin_referer( 'gas_partner_change_password' );

		$user_id   = get_current_user_id();
		$user      = wp_get_current_user();
		$current   = isset( $_POST['current_password'] ) ? (string) $_POST['current_password'] : '';
		$new_pass  = isset( $_POST['new_password'] ) ? (string) $_POST['new_password'] : '';
		$new_pass2 = isset( $_POST['new_password2'] ) ? (string) $_POST['new_password2'] : '';

		$fail = function( $msg ) {
			wp_safe_redirect( add_query_arg( 'password_error', rawurlencode( $msg ), self::page_url() ) );
			exit;
		};

		if ( ! wp_check_password( $current, $user->user_pass, $user_id ) ) {
			$fail( 'Current password is incorrect.' );
		}
		if ( strlen( $new_pass ) < 8 || $new_pass !== $new_pass2 ) {
			$fail( 'New password must be at least 8 characters and match its confirmation.' );
		}

		wp_set_password( $new_pass, $user_id );

		// wp_set_password() invalidates the current session, so log back in.
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id );

		wp_safe_redirect( add_query_arg( 'password_changed', '1', self::page_url() ) );
		exit;
	}
}
