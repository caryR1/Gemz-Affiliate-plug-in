<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GAS_Frontend {

	public static function init() {
		add_shortcode( 'gas_affiliate_signup', array( __CLASS__, 'render_signup' ) );
		add_shortcode( 'gas_affiliate_dashboard', array( __CLASS__, 'render_dashboard' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'init', array( __CLASS__, 'maybe_create_pages' ) );
		add_action( 'admin_post_gas_affiliate_signup', array( __CLASS__, 'handle_signup' ) );
		add_action( 'admin_post_nopriv_gas_affiliate_signup', array( __CLASS__, 'handle_signup' ) );
		add_action( 'admin_post_gas_affiliate_login', array( __CLASS__, 'handle_login' ) );
		add_action( 'admin_post_nopriv_gas_affiliate_login', array( __CLASS__, 'handle_login' ) );
		add_action( 'admin_post_gas_change_password', array( __CLASS__, 'handle_change_password' ) );
		add_action( 'admin_post_gas_save_payment_info', array( __CLASS__, 'handle_save_payment_info' ) );
	}

	public static function enqueue_assets() {
		if ( is_singular() ) {
			global $post;
			if ( $post && ( has_shortcode( $post->post_content, 'gas_affiliate_signup' ) || has_shortcode( $post->post_content, 'gas_affiliate_dashboard' ) ) ) {
				wp_enqueue_style( 'gas-frontend', plugins_url( 'assets/gas-frontend.css', GAS_PLUGIN_FILE ), array(), GAS_VERSION );
			}
		}
	}

	/**
	 * Auto-create the signup and dashboard pages, once, similar to how
	 * WooCommerce creates its Cart/Checkout pages on first run.
	 */
	public static function maybe_create_pages() {
		if ( ! get_option( 'gas_signup_page_id' ) ) {
			$id = wp_insert_post( array(
				'post_title'   => 'Become an Affiliate',
				'post_name'    => 'become-an-affiliate',
				'post_content' => '[gas_affiliate_signup]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			) );
			if ( $id && ! is_wp_error( $id ) ) {
				update_option( 'gas_signup_page_id', $id );
			}
		}
		if ( ! get_option( 'gas_dashboard_page_id' ) ) {
			$id = wp_insert_post( array(
				'post_title'   => 'Affiliate Dashboard',
				'post_name'    => 'affiliate-dashboard',
				'post_content' => '[gas_affiliate_dashboard]',
				'post_status'  => 'publish',
				'post_type'    => 'page',
			) );
			if ( $id && ! is_wp_error( $id ) ) {
				update_option( 'gas_dashboard_page_id', $id );
			}
		}
	}

	private static function dashboard_url() {
		$id = get_option( 'gas_dashboard_page_id' );
		return $id ? get_permalink( $id ) : home_url( '/affiliate-dashboard/' );
	}

	public static function signup_url() {
		$id = get_option( 'gas_signup_page_id' );
		return $id ? get_permalink( $id ) : home_url( '/become-an-affiliate/' );
	}

	private static function get_active_partners() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT id, name FROM ' . GAS_DB::table( 'partners' ) . ' ORDER BY name ASC' );
	}

	/**
	 * Resolves the sponsor cookie (set by GAS_Redirect::handle_join_redirect()
	 * on a /join/{code} visit) to that code's id, if it's still a real,
	 * active code — used once at signup to set the new affiliate's
	 * sponsor_code_id for multi-tier commission overrides.
	 */
	private static function get_sponsor_code_id() {
		if ( empty( $_COOKIE[ GAS_Redirect::SPONSOR_COOKIE_NAME ] ) ) {
			return null;
		}
		global $wpdb;
		$code  = sanitize_text_field( wp_unslash( $_COOKIE[ GAS_Redirect::SPONSOR_COOKIE_NAME ] ) );
		$table = GAS_DB::table( 'codes' );
		$id    = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE code = %s AND active = 1", $code ) );
		return $id ? (int) $id : null;
	}

	private static function generate_unique_code( $name ) {
		global $wpdb;
		$table = GAS_DB::table( 'codes' );
		$base  = sanitize_title( $name );
		if ( '' === $base ) {
			$base = 'affiliate';
		}
		$code = $base;
		$i    = 0;
		while ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE code = %s", $code ) ) ) {
			$i++;
			$code = $base . '-' . $i;
		}
		return $code;
	}

	/* ---------------------------------------------------------------- *
	 * SIGNUP
	 * ---------------------------------------------------------------- */

	public static function render_signup() {
		if ( is_user_logged_in() && GAS_Roles::is_affiliate() ) {
			return '<div class="gas-notice">You already have an affiliate account. <a href="' . esc_url( self::dashboard_url() ) . '">Go to your dashboard &rarr;</a></div>';
		}

		ob_start();

		if ( isset( $_GET['gas_signup'] ) && 'success' === $_GET['gas_signup'] ) {
			echo '<div class="gas-notice gas-notice-success"><p>You\'re in! Your referral link is live now.</p><p><a href="' . esc_url( self::dashboard_url() ) . '">Go to your dashboard &rarr;</a></p></div>';
			return ob_get_clean();
		}

		$error = isset( $_GET['gas_error'] ) ? sanitize_text_field( wp_unslash( $_GET['gas_error'] ) ) : '';
		if ( $error ) {
			echo '<div class="gas-notice gas-notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}

		$require_partner = (bool) GAS_Settings::get( 'require_partner_at_signup' );
		$partner_label   = GAS_Settings::get( 'partner_label' );
		$partners        = $require_partner ? self::get_active_partners() : array();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gas-form">
			<?php wp_nonce_field( 'gas_affiliate_signup' ); ?>
			<input type="hidden" name="action" value="gas_affiliate_signup">
			<p style="position:absolute;left:-9999px;" aria-hidden="true">
				<label>Leave this field empty<input type="text" name="gas_hp" tabindex="-1" autocomplete="off"></label>
			</p>

			<p>
				<label for="gas_name">Your name</label><br>
				<input type="text" id="gas_name" name="name" required class="gas-input">
			</p>
			<p>
				<label for="gas_email">Email</label><br>
				<input type="email" id="gas_email" name="email" required class="gas-input">
			</p>
			<?php if ( $require_partner ) : ?>
			<p>
				<label for="gas_partner">Which <?php echo esc_html( $partner_label ); ?> do you want to promote?</label><br>
				<select id="gas_partner" name="partner_id" required class="gas-input">
					<option value="">-- choose one --</option>
					<?php foreach ( $partners as $p ) : ?>
						<option value="<?php echo esc_attr( $p->id ); ?>"><?php echo esc_html( $p->name ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<?php endif; ?>
			<p>
				<label for="gas_password">Choose a password</label><br>
				<input type="password" id="gas_password" name="password" required minlength="8" class="gas-input">
			</p>
			<p>
				<label for="gas_password2">Confirm password</label><br>
				<input type="password" id="gas_password2" name="password2" required minlength="8" class="gas-input">
			</p>
			<p>
				<button type="submit" class="gas-button">Sign up</button>
			</p>
			<p class="gas-fineprint">Already have an account? <a href="<?php echo esc_url( self::dashboard_url() ); ?>">Log in on your dashboard</a>.</p>
		</form>
		<?php
		return ob_get_clean();
	}

	public static function handle_signup() {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'gas_affiliate_signup' ) ) {
			wp_die( 'Security check failed. Please go back and try again.' );
		}

		$redirect_back = wp_get_referer() ? wp_get_referer() : self::signup_url();

		// Honeypot: if filled, silently pretend success without creating anything.
		if ( ! empty( $_POST['gas_hp'] ) ) {
			wp_safe_redirect( add_query_arg( 'gas_signup', 'success', $redirect_back ) );
			exit;
		}

		$require_partner = (bool) GAS_Settings::get( 'require_partner_at_signup' );

		$name       = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$partner_id = isset( $_POST['partner_id'] ) ? absint( $_POST['partner_id'] ) : 0;
		$password   = isset( $_POST['password'] ) ? (string) $_POST['password'] : '';
		$password2  = isset( $_POST['password2'] ) ? (string) $_POST['password2'] : '';

		$fail = function( $msg ) use ( $redirect_back ) {
			wp_safe_redirect( add_query_arg( 'gas_error', rawurlencode( $msg ), $redirect_back ) );
			exit;
		};

		if ( '' === $name || ! is_email( $email ) || ( $require_partner && ! $partner_id ) || strlen( $password ) < 8 ) {
			$fail( 'Please fill in every field. Passwords need to be at least 8 characters.' );
		}
		if ( $password !== $password2 ) {
			$fail( 'Passwords do not match.' );
		}
		if ( email_exists( $email ) ) {
			$fail( 'That email is already registered. Try logging in instead.' );
		}

		$username = self::generate_unique_username( $email );

		$user_id = wp_insert_user( array(
			'user_login'   => $username,
			'user_email'   => $email,
			'user_pass'    => $password,
			'display_name' => $name,
			'first_name'   => $name,
			'role'         => GAS_Roles::ROLE,
		) );

		if ( is_wp_error( $user_id ) ) {
			$fail( 'Could not create account: ' . $user_id->get_error_message() );
		}

		update_user_meta( $user_id, 'gas_status', 'active' );

		$code = self::generate_unique_code( $name );

		global $wpdb;
		$site_name       = GAS_Settings::get( 'site_name' );
		$sponsor_code_id = self::get_sponsor_code_id();

		if ( $require_partner ) {
			$partners_table = GAS_DB::table( 'partners' );
			$partner        = $wpdb->get_row( $wpdb->prepare( "SELECT default_cut_type, default_cut_value FROM {$partners_table} WHERE id = %d", $partner_id ) );
			$cut_type       = $partner && 'flat' === $partner->default_cut_type ? 'flat' : 'percent';
			$cut_value      = $partner ? (float) $partner->default_cut_value : 0;

			$wpdb->insert(
				GAS_DB::table( 'codes' ),
				array(
					'code'               => $code,
					'sub_affiliate_name' => $name,
					'partner_id'         => $partner_id,
					'wp_user_id'         => $user_id,
					'sponsor_code_id'    => $sponsor_code_id,
					'status'             => 'active',
					'cut_type'           => $cut_type,
					'cut_value'          => $cut_value,
					'active'             => 1,
					'notes'              => 'Self-signup, live immediately at the partner\'s default cut rate.',
					'created_at'         => current_time( 'mysql' ),
				)
			);

			wp_mail(
				get_option( 'admin_email' ),
				'New affiliate joined: ' . $name,
				"A new affiliate signed up and is live immediately.\n\nName: {$name}\nEmail: {$email}\nCode: {$code}\nCut rate applied: " . ( 'flat' === $cut_type ? '$' . number_format( $cut_value, 2 ) . ' flat' : $cut_value . '%' ) . "\n\nYou can suspend them or adjust their rate anytime in wp-admin under {$site_name} > Affiliates."
			);
		} else {
			$wpdb->insert(
				GAS_DB::table( 'codes' ),
				array(
					'code'               => $code,
					'sub_affiliate_name' => $name,
					'partner_id'         => 0,
					'wp_user_id'         => $user_id,
					'sponsor_code_id'    => $sponsor_code_id,
					'status'             => 'active',
					'cut_type'           => 'percent',
					'cut_value'          => 0,
					'active'             => 1,
					'notes'              => "Self-signup, live immediately. No partner assigned yet — match to a partner and set the cut rate from {$site_name} > Codes.",
					'created_at'         => current_time( 'mysql' ),
				)
			);

			wp_mail(
				get_option( 'admin_email' ),
				'New affiliate joined: ' . $name,
				"A new affiliate signed up and is live immediately, but has no partner assigned yet.\n\nName: {$name}\nEmail: {$email}\nCode: {$code}\n\nMatch them to a partner and set their cut rate in wp-admin under {$site_name} > Codes."
			);
		}

		// One-time attribution: clear the sponsor cookie now that it's been
		// applied, so it can't also attribute some later, unrelated signup
		// in the same browser (e.g. a shared/kiosk device).
		if ( $sponsor_code_id ) {
			setcookie( GAS_Redirect::SPONSOR_COOKIE_NAME, '', array( 'expires' => time() - HOUR_IN_SECONDS, 'path' => '/' ) );
		}

		// Log them in so their dashboard is ready immediately.
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id );

		wp_safe_redirect( add_query_arg( 'gas_signup', 'success', self::signup_url() ) );
		exit;
	}

	private static function generate_unique_username( $email ) {
		$base = sanitize_user( current( explode( '@', $email ) ), true );
		if ( '' === $base ) {
			$base = 'affiliate';
		}
		$username = $base;
		$i        = 0;
		while ( username_exists( $username ) ) {
			$i++;
			$username = $base . $i;
		}
		return $username;
	}

	/* ---------------------------------------------------------------- *
	 * LOGIN
	 * ---------------------------------------------------------------- */

	public static function handle_login() {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'gas_affiliate_login' ) ) {
			wp_die( 'Security check failed. Please go back and try again.' );
		}

		$creds = array(
			'user_login'    => isset( $_POST['gas_username'] ) ? sanitize_text_field( wp_unslash( $_POST['gas_username'] ) ) : '',
			'user_password' => isset( $_POST['gas_login_password'] ) ? (string) $_POST['gas_login_password'] : '',
			'remember'      => true,
		);

		$user = wp_signon( $creds, is_ssl() );

		if ( is_wp_error( $user ) ) {
			wp_safe_redirect( add_query_arg( 'gas_error', rawurlencode( 'Login failed: check your email/username and password.' ), self::dashboard_url() ) );
			exit;
		}

		wp_safe_redirect( self::dashboard_url() );
		exit;
	}

	/* ---------------------------------------------------------------- *
	 * DASHBOARD
	 * ---------------------------------------------------------------- */

	public static function render_dashboard() {
		if ( ! is_user_logged_in() ) {
			return self::render_login_form();
		}

		if ( ! GAS_Roles::is_affiliate() ) {
			return '<div class="gas-notice">This dashboard is for affiliates only. <a href="' . esc_url( wp_logout_url( self::signup_url() ) ) . '">Log out</a> and sign up as an affiliate, or contact us if you think this is a mistake.</div>';
		}

		$user_id = get_current_user_id();
		$user    = wp_get_current_user();
		$status  = get_user_meta( $user_id, 'gas_status', true ) ?: 'active';

		ob_start();

		if ( isset( $_GET['gas_notice'] ) ) {
			$notices = array(
				'password_updated' => 'Password updated.',
				'payment_updated'  => 'Payment information saved.',
			);
			$key = sanitize_text_field( wp_unslash( $_GET['gas_notice'] ) );
			if ( isset( $notices[ $key ] ) ) {
				echo '<div class="gas-notice gas-notice-success"><p>' . esc_html( $notices[ $key ] ) . '</p></div>';
			}
		}
		if ( isset( $_GET['gas_error'] ) ) {
			echo '<div class="gas-notice gas-notice-error"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['gas_error'] ) ) ) . '</p></div>';
		}

		echo '<div class="gas-dashboard">';
		echo '<p>Welcome back, ' . esc_html( $user->display_name ) . '. <a href="' . esc_url( wp_logout_url( self::dashboard_url() ) ) . '">Log out</a></p>';

		if ( 'suspended' === $status ) {
			echo '<div class="gas-notice gas-notice-error">Your affiliate account is currently suspended and your link is inactive. Contact us if you have questions.</div>';
		}

		self::render_stats_section( $user_id );
		self::render_password_section();
		self::render_payment_section( $user_id );

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
			<?php wp_nonce_field( 'gas_affiliate_login' ); ?>
			<input type="hidden" name="action" value="gas_affiliate_login">
			<p>
				<label for="gas_username">Email or username</label><br>
				<input type="text" id="gas_username" name="gas_username" required class="gas-input">
			</p>
			<p>
				<label for="gas_login_password">Password</label><br>
				<input type="password" id="gas_login_password" name="gas_login_password" required class="gas-input">
			</p>
			<p><button type="submit" class="gas-button">Log in</button></p>
			<p class="gas-fineprint">
				Not an affiliate yet? <a href="<?php echo esc_url( self::signup_url() ); ?>">Sign up here</a>.
				Forgot your password? <a href="<?php echo esc_url( wp_lostpassword_url( self::dashboard_url() ) ); ?>">Reset it</a>.
			</p>
		</form>
		<?php
		return ob_get_clean();
	}

	private static function render_stats_section( $user_id ) {
		global $wpdb;
		$codes_table    = GAS_DB::table( 'codes' );
		$partners_table = GAS_DB::table( 'partners' );
		$clicks_table   = GAS_DB::table( 'clicks' );

		$codes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT c.*, p.name AS partner_name FROM {$codes_table} c
				 LEFT JOIN {$partners_table} p ON p.id = c.partner_id
				 WHERE c.wp_user_id = %d ORDER BY c.created_at ASC",
				$user_id
			)
		);

		echo '<h2>Your links</h2>';
		if ( ! $codes ) {
			echo '<p>No referral links yet.</p>';
			return;
		}

		$totals = GAS_Payouts::totals_for_affiliate( $user_id );

		foreach ( $codes as $c ) {
			$link         = home_url( '/go/' . rawurlencode( $c->code ) . '/' );
			$recruit_link = home_url( '/join/' . rawurlencode( $c->code ) . '/' );
			$click_count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$clicks_table} WHERE code_id = %d", $c->id ) );

			echo '<div class="gas-code-card">';
			echo '<p><strong>' . esc_html( $c->partner_name ?: '(unassigned)' ) . '</strong> &mdash; status: ' . esc_html( $c->status ) . '</p>';
			echo '<p>Your link: <code>' . esc_html( $link ) . '</code></p>';
			echo '<p>Invite others to become an affiliate too, and earn a bonus on their sales: <code>' . esc_html( $recruit_link ) . '</code></p>';
			echo '<div class="gas-stat-row">';
			echo '<div class="gas-stat"><span class="gas-stat-num">' . esc_html( $click_count ) . '</span><span class="gas-stat-label">Clicks</span></div>';
			echo '</div>';
			echo '</div>';
		}

		echo '<div class="gas-stat-row">';
		echo '<div class="gas-stat"><span class="gas-stat-num">$' . esc_html( number_format( $totals['unpaid'], 2 ) ) . '</span><span class="gas-stat-label">Unpaid balance</span></div>';
		echo '<div class="gas-stat"><span class="gas-stat-num">$' . esc_html( number_format( $totals['paid'], 2 ) ) . '</span><span class="gas-stat-label">Paid to date</span></div>';
		echo '</div>';

		if ( $totals['override_unpaid'] > 0 || $totals['override_paid'] > 0 ) {
			echo '<p class="gas-fineprint">Of which $' . esc_html( number_format( $totals['override_unpaid'] + $totals['override_paid'], 2 ) ) . ' is from people you\'ve recruited.</p>';
		}
	}

	private static function render_password_section() {
		?>
		<h2>Change password</h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gas-form">
			<?php wp_nonce_field( 'gas_change_password' ); ?>
			<input type="hidden" name="action" value="gas_change_password">
			<p>
				<label for="gas_current_password">Current password</label><br>
				<input type="password" id="gas_current_password" name="current_password" required class="gas-input">
			</p>
			<p>
				<label for="gas_new_password">New password</label><br>
				<input type="password" id="gas_new_password" name="new_password" required minlength="8" class="gas-input">
			</p>
			<p>
				<label for="gas_new_password2">Confirm new password</label><br>
				<input type="password" id="gas_new_password2" name="new_password2" required minlength="8" class="gas-input">
			</p>
			<p><button type="submit" class="gas-button">Update password</button></p>
		</form>
		<?php
	}

	public static function handle_change_password() {
		if ( ! is_user_logged_in() ) {
			wp_die( 'Please log in first.' );
		}
		check_admin_referer( 'gas_change_password' );

		$user_id   = get_current_user_id();
		$user      = wp_get_current_user();
		$current   = isset( $_POST['current_password'] ) ? (string) $_POST['current_password'] : '';
		$new_pass  = isset( $_POST['new_password'] ) ? (string) $_POST['new_password'] : '';
		$new_pass2 = isset( $_POST['new_password2'] ) ? (string) $_POST['new_password2'] : '';

		$fail = function( $msg ) {
			wp_safe_redirect( add_query_arg( 'gas_error', rawurlencode( $msg ), self::dashboard_url() ) );
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

		wp_safe_redirect( add_query_arg( 'gas_notice', 'password_updated', self::dashboard_url() ) );
		exit;
	}

	/* ---------------------------------------------------------------- *
	 * PAYMENT / PAYOUT DETAILS
	 * ---------------------------------------------------------------- */

	private static function render_payment_section( $user_id ) {
		$d = GAS_Payouts::get_details( $user_id );
		?>
		<h2>Payment information</h2>
		<p class="gas-fineprint">Tell us how you'd like to be paid. This is only ever visible to you and used to send your payouts &mdash; the admin only sees a masked summary.</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gas-form" id="gas-payment-form">
			<?php wp_nonce_field( 'gas_save_payment_info' ); ?>
			<input type="hidden" name="action" value="gas_save_payment_info">

			<p>
				<label for="gas_payout_method">Payout method</label><br>
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

			<p><button type="submit" class="gas-button">Save</button></p>
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
		<?php
	}

	public static function handle_save_payment_info() {
		if ( ! is_user_logged_in() ) {
			wp_die( 'Please log in first.' );
		}
		check_admin_referer( 'gas_save_payment_info' );

		$user_id = get_current_user_id();
		GAS_Payouts::save_details( $user_id, wp_unslash( $_POST ) );

		wp_safe_redirect( add_query_arg( 'gas_notice', 'payment_updated', self::dashboard_url() ) );
		exit;
	}
}
