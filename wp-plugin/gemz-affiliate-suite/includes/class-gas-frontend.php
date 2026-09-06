<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GAS_Frontend {

	public static function init() {
		add_shortcode( 'gas_affiliate_signup', array( __CLASS__, 'render_signup' ) );
		add_shortcode( 'gas_affiliate_dashboard', array( __CLASS__, 'render_dashboard' ) );
		add_shortcode( 'gas_signup_or_refer', array( __CLASS__, 'render_signup_or_refer' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'init', array( __CLASS__, 'maybe_create_pages' ) );
		add_action( 'admin_post_gas_affiliate_signup', array( __CLASS__, 'handle_signup' ) );
		add_action( 'admin_post_nopriv_gas_affiliate_signup', array( __CLASS__, 'handle_signup' ) );
		add_action( 'admin_post_gas_signup_or_refer', array( __CLASS__, 'handle_signup_or_refer' ) );
		add_action( 'admin_post_nopriv_gas_signup_or_refer', array( __CLASS__, 'handle_signup_or_refer' ) );
		add_action( 'admin_post_gas_verify_email', array( __CLASS__, 'handle_verify_email' ) );
		add_action( 'admin_post_nopriv_gas_verify_email', array( __CLASS__, 'handle_verify_email' ) );
		add_action( 'admin_post_gas_affiliate_login', array( __CLASS__, 'handle_login' ) );
		add_action( 'admin_post_nopriv_gas_affiliate_login', array( __CLASS__, 'handle_login' ) );
		add_action( 'admin_post_gas_change_password', array( __CLASS__, 'handle_change_password' ) );
		add_action( 'admin_post_gas_save_payment_info', array( __CLASS__, 'handle_save_payment_info' ) );
	}

	/**
	 * True if the given shortcode appears on this post — checked against
	 * post_content AND, since Elementor pages store widget content
	 * (including a "shortcode" widget's shortcode text) in _elementor_data
	 * meta rather than post_content, that meta value too. Without the
	 * second check this silently misses every Elementor page that embeds
	 * one of these shortcodes via a Shortcode widget, which is how both
	 * the merged signup/refer page and earlier Solar pages actually do it.
	 */
	private static function post_has_shortcode( $post, $tag ) {
		if ( ! $post ) {
			return false;
		}
		if ( has_shortcode( $post->post_content, $tag ) ) {
			return true;
		}
		$elementor_data = get_post_meta( $post->ID, '_elementor_data', true );
		return $elementor_data && false !== strpos( $elementor_data, '[' . $tag );
	}

	public static function enqueue_assets() {
		if ( is_singular() ) {
			global $post;
			if ( self::post_has_shortcode( $post, 'gas_affiliate_signup' ) || self::post_has_shortcode( $post, 'gas_affiliate_dashboard' ) || self::post_has_shortcode( $post, 'gas_signup_or_refer' ) ) {
				wp_enqueue_style( 'gas-frontend', plugins_url( 'assets/gas-frontend.css', GAS_PLUGIN_FILE ), array(), GAS_VERSION );
			}
		}
	}

	/**
	 * Auto-create the signup and dashboard pages, once, similar to how
	 * WooCommerce creates its Cart/Checkout pages on first run.
	 */
	/**
	 * Adopts an existing page at these slugs rather than blindly creating
	 * a new one — the same collision this plugin already hit once on the
	 * Help/FAQ pages (both Solar and Home had real pre-existing content at
	 * those slugs, and the naive "create if my option isn't set" check
	 * silently produced an orphaned duplicate). Applied here defensively
	 * even though no live collision has happened on these particular
	 * slugs yet.
	 */
	public static function maybe_create_pages() {
		GAS_Help::create_or_adopt_page( 'gas_signup_page_id', 'Become an Affiliate', 'become-an-affiliate', '[gas_affiliate_signup]' );
		GAS_Help::create_or_adopt_page( 'gas_dashboard_page_id', 'Affiliate Dashboard', 'affiliate-dashboard', '[gas_affiliate_dashboard]' );
	}

	public static function dashboard_url() {
		$id = get_option( 'gas_dashboard_page_id' );
		return $id ? get_permalink( $id ) : home_url( '/affiliate-dashboard/' );
	}

	public static function signup_url() {
		$id = get_option( 'gas_signup_page_id' );
		return $id ? get_permalink( $id ) : home_url( '/become-an-affiliate/' );
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
			<p>
				<label for="gas_phone">Phone (optional)</label><br>
				<input type="tel" id="gas_phone" name="phone" class="gas-input">
			</p>
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

		$name       = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email      = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$phone      = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$password   = isset( $_POST['password'] ) ? (string) $_POST['password'] : '';
		$password2  = isset( $_POST['password2'] ) ? (string) $_POST['password2'] : '';

		$fail = function( $msg ) use ( $redirect_back ) {
			wp_safe_redirect( add_query_arg( 'gas_error', rawurlencode( $msg ), $redirect_back ) );
			exit;
		};

		if ( '' === $name || ! is_email( $email ) || strlen( $password ) < 8 ) {
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
		if ( '' !== $phone ) {
			update_user_meta( $user_id, 'gas_phone', $phone );
		}

		$code = self::generate_unique_code( $name );

		global $wpdb;
		$site_name       = GAS_Settings::get( 'site_name' );
		$sponsor_code_id = self::get_sponsor_code_id();

		// An affiliate never chooses (or sees) which fulfillment partner
		// handles their referrals, on any project this plugin runs — an
		// admin always matches them to a partner afterward, from the
		// Codes screen. No exceptions.
		$wpdb->insert(
			GAS_DB::table( 'codes' ),
			array(
				'code'               => $code,
				'sub_affiliate_name' => $name,
				'partner_id'         => 0,
				'wp_user_id'         => $user_id,
				'sponsor_code_id'    => $sponsor_code_id,
				'status'             => 'active',
				'active'             => 1,
				'notes'              => "Self-signup, live immediately. No partner assigned yet — match them to a partner from {$site_name} > Codes.",
				'created_at'         => current_time( 'mysql' ),
			)
		);

		GAS_Contacts::upsert( $email, 'affiliate', array(
			'name'          => $name,
			'phone'         => $phone,
			'source'        => 'signup',
			'related_table' => 'codes',
			'related_id'    => $wpdb->insert_id,
		) );

		wp_mail(
			get_option( 'admin_email' ),
			'New affiliate joined: ' . $name,
			"A new affiliate signed up and is live immediately, but has no partner assigned yet.\n\nName: {$name}\nEmail: {$email}\nPhone: " . ( $phone ?: '(not provided)' ) . "\nCode: {$code}\n\nMatch them to a partner in wp-admin under {$site_name} > Codes."
		);

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

	/* ---------------------------------------------------------------- *
	 * SIGN UP / REFER A FRIEND (merged page)
	 * ---------------------------------------------------------------- */

	/**
	 * A rough, honest dollar estimate for the page's "earn between $X and
	 * $Y" copy, computed the same way a real payout actually is —
	 * GAS_Payouts::compute()'s gross -> agent_pool -> tier1_split_percent
	 * chain — rather than the older default_cut_type/default_cut_value
	 * fields, which turned out to be vestigial: compute() never reads
	 * them at all. Using them here would have made this page promise a
	 * number the real payout math doesn't actually produce. This
	 * estimates tier 1 only (a lone affiliate with no sponsor, the
	 * common case) since tier 2/3 depend on a specific recruiting chain
	 * that doesn't exist yet for a first-time visitor to this page.
	 * Percent-based partners are converted to a dollar figure using
	 * their typical_sale_amount; a partner missing that figure (or with
	 * no payout configured at all) is simply excluded from the range
	 * rather than distorting it with a guess. Returns null when there
	 * isn't enough data yet — the caller falls back to generic copy
	 * rather than showing a "$0-$0" range.
	 */
	public static function estimated_payout_range() {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT payout_type, payout_amount, payout_percent, typical_sale_amount, agent_pool_type, agent_pool_value FROM ' . GAS_DB::table( 'partners' ) . " WHERE outreach_status = 'approved'"
		);

		$tier1_pct = (float) GAS_Settings::get( 'tier1_split_percent' );
		$estimates = array();

		foreach ( $rows as $r ) {
			if ( 'flat' === $r->payout_type ) {
				$gross = (float) $r->payout_amount;
			} elseif ( $r->typical_sale_amount ) {
				$gross = (float) $r->typical_sale_amount * ( (float) $r->payout_percent / 100 );
			} else {
				continue;
			}
			if ( $gross <= 0 ) {
				continue;
			}

			$agent_pool = self::partner_agent_pool( $r, $gross );
			$tier1      = $agent_pool * ( $tier1_pct / 100 );
			if ( $tier1 > 0 ) {
				$estimates[] = $tier1;
			}
		}

		if ( ! $estimates ) {
			return null;
		}
		return array( 'min' => min( $estimates ), 'max' => max( $estimates ) );
	}

	/**
	 * Same math as GAS_Payouts::agent_pool_amount(), duplicated here
	 * rather than called directly since that method takes a full
	 * partner row object with slightly different fields than this
	 * lighter SELECT — kept in sync by hand; if that method's formula
	 * ever changes, update this one too.
	 */
	private static function partner_agent_pool( $partner, $gross ) {
		$type  = isset( $partner->agent_pool_type ) ? $partner->agent_pool_type : 'percent';
		$value = isset( $partner->agent_pool_value ) && '' !== $partner->agent_pool_value ? (float) $partner->agent_pool_value : 100;
		if ( 'flat' === $type ) {
			return min( $value, $gross );
		}
		return $gross * ( $value / 100 );
	}

	/**
	 * Finds an existing affiliate account by email, if any — used so the
	 * merged form can attach a new referral to someone's existing account
	 * instead of erroring or creating a duplicate. Returns null if the
	 * email isn't registered, or isn't an affiliate.
	 */
	private static function find_affiliate_by_email( $email ) {
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return null;
		}
		$user_meta_role = in_array( GAS_Roles::ROLE, (array) $user->roles, true );
		return $user_meta_role ? $user : null;
	}

	private static function get_or_create_code_for_user( $user_id, $name, $sponsor_code_id ) {
		global $wpdb;
		$codes_table = GAS_DB::table( 'codes' );
		$existing    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$codes_table} WHERE wp_user_id = %d ORDER BY created_at ASC LIMIT 1", $user_id ) );
		if ( $existing ) {
			return $existing;
		}
		$code = self::generate_unique_code( $name );
		$wpdb->insert(
			$codes_table,
			array(
				'code'               => $code,
				'sub_affiliate_name' => $name,
				'partner_id'         => 0,
				'wp_user_id'         => $user_id,
				'sponsor_code_id'    => $sponsor_code_id,
				'status'             => 'active',
				'active'             => 1,
				'notes'              => 'Self-signup, live immediately. No partner assigned yet.',
				'created_at'         => current_time( 'mysql' ),
			)
		);
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$codes_table} WHERE id = %d", $wpdb->insert_id ) );
	}

	private static function generate_verify_token( $user_id ) {
		$token = wp_generate_password( 32, false );
		update_user_meta( $user_id, 'gas_email_verify_token', $token );
		return add_query_arg(
			array( 'action' => 'gas_verify_email', 'uid' => $user_id, 'token' => $token ),
			admin_url( 'admin-post.php' )
		);
	}

	public static function handle_verify_email() {
		$user_id = isset( $_GET['uid'] ) ? absint( $_GET['uid'] ) : 0;
		$token   = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';
		$stored  = $user_id ? get_user_meta( $user_id, 'gas_email_verify_token', true ) : '';

		if ( $user_id && $token && $stored && hash_equals( (string) $stored, $token ) ) {
			update_user_meta( $user_id, 'gas_email_verified', 1 );
			delete_user_meta( $user_id, 'gas_email_verify_token' );
			wp_safe_redirect( add_query_arg( 'gas_verified', '1', self::dashboard_url() ) );
		} else {
			wp_safe_redirect( add_query_arg( 'gas_verified', '0', self::dashboard_url() ) );
		}
		exit;
	}

	public static function render_signup_or_refer() {
		ob_start();

		if ( isset( $_GET['gas_signup'] ) && 'success' === $_GET['gas_signup'] ) {
			echo '<div class="gas-notice gas-notice-success"><p>You\'re in! Your referral link is live now.</p><p><a href="' . esc_url( self::dashboard_url() ) . '">Go to your dashboard &rarr;</a></p></div>';
			return ob_get_clean();
		}
		if ( isset( $_GET['gas_signup'] ) && 'attached' === $_GET['gas_signup'] ) {
			echo '<div class="gas-notice gas-notice-success"><p>Since you already have an affiliate account, we\'ve added this referral to it &mdash; log in to your dashboard to see it.</p><p><a href="' . esc_url( self::dashboard_url() ) . '">Go to your dashboard &rarr;</a></p></div>';
			return ob_get_clean();
		}

		$error = isset( $_GET['gas_error'] ) ? sanitize_text_field( wp_unslash( $_GET['gas_error'] ) ) : '';
		if ( $error ) {
			echo '<div class="gas-notice gas-notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}

		$range = self::estimated_payout_range();
		echo '<div class="gas-payout-range">';
		if ( $range ) {
			echo '<p>Earn between <strong>$' . esc_html( number_format( $range['min'], 0 ) ) . '</strong> and <strong>$' . esc_html( number_format( $range['max'], 0 ) ) . '</strong> per completed installation you refer.</p>';
		} else {
			echo '<p>Get paid for every completed installation you refer &mdash; exact amounts depend on the partner, and you\'ll see your rate once you\'re matched.</p>';
		}
		echo '</div>';
		?>
		<div class="gas-signup-toggle">
			<button type="button" class="gas-toggle-btn gas-toggle-active" data-mode="signup">Sign Up as an Affiliate</button>
			<button type="button" class="gas-toggle-btn" data-mode="refer">Refer a Friend</button>
		</div>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gas-form" id="gas-signup-or-refer-form">
			<?php wp_nonce_field( 'gas_signup_or_refer' ); ?>
			<input type="hidden" name="action" value="gas_signup_or_refer">
			<input type="hidden" name="is_referral" id="gas_is_referral" value="0">
			<p style="position:absolute;left:-9999px;" aria-hidden="true">
				<label>Leave this field empty<input type="text" name="gas_hp" tabindex="-1" autocomplete="off"></label>
			</p>

			<p>
				<label for="gas_name">Your name</label><br>
				<input type="text" id="gas_name" name="name" required class="gas-input">
			</p>
			<p>
				<label for="gas_email">Your email</label><br>
				<input type="email" id="gas_email" name="email" required class="gas-input">
			</p>
			<p>
				<label for="gas_phone">Your phone (optional)</label><br>
				<input type="tel" id="gas_phone" name="phone" class="gas-input">
			</p>
			<p>
				<label for="gas_password">Choose a password</label><br>
				<input type="password" id="gas_password" name="password" required minlength="8" class="gas-input">
			</p>
			<p class="gas-fineprint">Already have an account? Password is only needed the first time &mdash; if this email already has one, whatever you type here is ignored and your existing account is used instead.</p>
			<p>
				<label for="gas_password2">Confirm password</label><br>
				<input type="password" id="gas_password2" name="password2" class="gas-input">
			</p>

			<div class="gas-referral-fields" style="display:none;">
				<h3>Who are you referring?</h3>
				<p>
					<label for="gas_friend_name">Their name</label><br>
					<input type="text" id="gas_friend_name" name="friend_name" class="gas-input">
				</p>
				<p>
					<label for="gas_friend_email">Their email</label><br>
					<input type="email" id="gas_friend_email" name="friend_email" class="gas-input">
				</p>
				<p>
					<label for="gas_friend_phone">Their phone (optional)</label><br>
					<input type="tel" id="gas_friend_phone" name="friend_phone" class="gas-input">
				</p>
				<p>
					<label for="gas_friend_address">Their address (optional)</label><br>
					<input type="text" id="gas_friend_address" name="friend_address" class="gas-input">
				</p>
				<p>
					<label for="gas_friend_state">Their state</label><br>
					<input type="text" id="gas_friend_state" name="friend_state" maxlength="2" placeholder="e.g. FL" style="text-transform:uppercase;" class="gas-input">
					<span class="gas-fineprint">Lets us match them to a partner that actually covers their area.</span>
				</p>
			</div>

			<p>
				<button type="submit" class="gas-button" id="gas-submit-btn">Sign up</button>
			</p>
			<p class="gas-fineprint">Already have an account? <a href="<?php echo esc_url( self::dashboard_url() ); ?>">Log in on your dashboard</a>.</p>
		</form>
		<script>
			(function() {
				var buttons  = document.querySelectorAll('.gas-toggle-btn');
				var referral = document.querySelector('.gas-referral-fields');
				var isRef    = document.getElementById('gas_is_referral');
				var submit   = document.getElementById('gas-submit-btn');
				buttons.forEach(function(btn) {
					btn.addEventListener('click', function() {
						buttons.forEach(function(b) { b.classList.remove('gas-toggle-active'); });
						btn.classList.add('gas-toggle-active');
						var refer = btn.getAttribute('data-mode') === 'refer';
						referral.style.display = refer ? '' : 'none';
						isRef.value = refer ? '1' : '0';
						submit.textContent = refer ? 'Refer & Sign Up' : 'Sign up';
					});
				});
			})();
		</script>
		<?php
		return ob_get_clean();
	}

	public static function handle_signup_or_refer() {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'gas_signup_or_refer' ) ) {
			wp_die( 'Security check failed. Please go back and try again.' );
		}

		$redirect_back = wp_get_referer() ? wp_get_referer() : self::signup_url();

		if ( ! empty( $_POST['gas_hp'] ) ) {
			wp_safe_redirect( add_query_arg( 'gas_signup', 'success', $redirect_back ) );
			exit;
		}

		$fail = function( $msg ) use ( $redirect_back ) {
			wp_safe_redirect( add_query_arg( 'gas_error', rawurlencode( $msg ), $redirect_back ) );
			exit;
		};

		$name        = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email       = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$phone       = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$password    = isset( $_POST['password'] ) ? (string) $_POST['password'] : '';
		$password2   = isset( $_POST['password2'] ) ? (string) $_POST['password2'] : '';
		$is_referral = ! empty( $_POST['is_referral'] );

		if ( '' === $name || ! is_email( $email ) ) {
			$fail( 'Please enter your name and a valid email.' );
		}

		$friend_name    = '';
		$friend_email   = '';
		$friend_phone   = '';
		$friend_address = '';
		$friend_state   = '';
		if ( $is_referral ) {
			$friend_name    = isset( $_POST['friend_name'] ) ? sanitize_text_field( wp_unslash( $_POST['friend_name'] ) ) : '';
			$friend_email   = isset( $_POST['friend_email'] ) ? sanitize_email( wp_unslash( $_POST['friend_email'] ) ) : '';
			$friend_phone   = isset( $_POST['friend_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['friend_phone'] ) ) : '';
			$friend_address = isset( $_POST['friend_address'] ) ? sanitize_text_field( wp_unslash( $_POST['friend_address'] ) ) : '';
			$friend_state   = isset( $_POST['friend_state'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['friend_state'] ) ) ) : '';
			if ( '' === $friend_name || ( '' === $friend_email && '' === $friend_phone ) ) {
				$fail( 'Please enter your friend\'s name and at least an email or phone number.' );
			}
		}

		global $wpdb;
		$site_name       = GAS_Settings::get( 'site_name' );
		$sponsor_code_id = self::get_sponsor_code_id();

		// Reusing an existing account: deliberately never touches that
		// account's password or logs the submitter in as that user — this
		// form is public and unauthenticated, so treating "knows the
		// email address" as proof of identity would be an account
		// takeover waiting to happen. We just attach the referral and
		// email the real account holder about it.
		$existing_affiliate = self::find_affiliate_by_email( $email );

		if ( $existing_affiliate ) {
			$user_id = $existing_affiliate->ID;
			$code    = self::get_or_create_code_for_user( $user_id, $existing_affiliate->display_name, $sponsor_code_id );

			if ( $is_referral ) {
				self::create_referral_lead( $code, $friend_name, $friend_email, $friend_phone, $friend_address, $friend_state );
				wp_mail(
					$existing_affiliate->user_email,
					'New referral added to your account',
					"Hi {$existing_affiliate->display_name},\n\nSomeone just referred {$friend_name} using your details on {$site_name}. It's been added to your account under your referral code {$code->code}.\n\nLog in to your dashboard to keep an eye on it: " . self::dashboard_url()
				);
				wp_mail(
					get_option( 'admin_email' ),
					'Referral added to existing affiliate: ' . $existing_affiliate->display_name,
					"Existing affiliate: {$existing_affiliate->display_name} ({$existing_affiliate->user_email})\nCode: {$code->code}\nReferred: {$friend_name} / " . ( $friend_email ?: '(no email)' ) . ' / ' . ( $friend_phone ?: '(no phone)' ) . "\n\nMatch a partner to this referral in wp-admin under {$site_name} > Leads."
				);
			}

			wp_safe_redirect( add_query_arg( 'gas_signup', 'attached', self::signup_url() ) );
			exit;
		}

		// Brand new affiliate — password is required here since this
		// branch actually creates the account.
		if ( strlen( $password ) < 8 ) {
			$fail( 'Please choose a password of at least 8 characters.' );
		}
		if ( $password !== $password2 ) {
			$fail( 'Passwords do not match.' );
		}

		$username = self::generate_unique_username( $email );
		$user_id  = wp_insert_user( array(
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
		if ( '' !== $phone ) {
			update_user_meta( $user_id, 'gas_phone', $phone );
		}

		$code_row = self::get_or_create_code_for_user( $user_id, $name, $sponsor_code_id );

		GAS_Contacts::upsert( $email, 'affiliate', array(
			'name'          => $name,
			'phone'         => $phone,
			'source'        => 'signup',
			'related_table' => 'codes',
			'related_id'    => $code_row->id,
		) );

		$referral_note = '';
		if ( $is_referral ) {
			self::create_referral_lead( $code_row, $friend_name, $friend_email, $friend_phone, $friend_address, $friend_state );
			$referral_note = "\n\nYou also referred {$friend_name} — they'll get their own note from us, and you'll see this in your dashboard once a partner is matched.";
		}

		$verify_link = self::generate_verify_token( $user_id );
		wp_mail(
			$email,
			"Welcome to {$site_name}",
			"Hi {$name},\n\nYour affiliate account is live. Your referral link and dashboard are ready here: " . self::dashboard_url() . "{$referral_note}\n\nOne quick thing — please confirm your email so we know it's really you: {$verify_link}\n\nNo partner is assigned to your account yet; we'll match you to one shortly and you'll see it reflected on your dashboard."
		);

		wp_mail(
			get_option( 'admin_email' ),
			'New affiliate joined: ' . $name,
			"A new affiliate signed up and is live immediately, but has no partner assigned yet.\n\nName: {$name}\nEmail: {$email}\nPhone: " . ( $phone ?: '(not provided)' ) . "\nCode: {$code_row->code}" . ( $is_referral ? "\nAlso referred: {$friend_name} / " . ( $friend_email ?: '(no email)' ) . ' / ' . ( $friend_phone ?: '(no phone)' ) : '' ) . "\n\nMatch them to a partner in wp-admin under {$site_name} > Codes" . ( $is_referral ? ' (and match the referral under Leads)' : '' ) . '.'
		);

		if ( $sponsor_code_id ) {
			setcookie( GAS_Redirect::SPONSOR_COOKIE_NAME, '', array( 'expires' => time() - HOUR_IN_SECONDS, 'path' => '/' ) );
		}

		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id );

		wp_safe_redirect( add_query_arg( 'gas_signup', 'success', self::signup_url() ) );
		exit;
	}

	/**
	 * Records a friend referred through the merged page as a lead with no
	 * partner yet (partner_id 0, the same "unassigned" sentinel codes()
	 * already uses) — an admin matches a partner afterward from the Leads
	 * screen, same as they already do for fresh affiliate signups on the
	 * Codes screen. Sends the referred friend their own welcome note
	 * immediately; a real appointment-time proposal (when the eventually-
	 * assigned partner requires one) goes out separately, at the moment
	 * admin actually assigns that partner — see GAS_Leads::assign_partner()
	 * — since which partner it is, and whether they need an appointment,
	 * genuinely isn't known yet at referral time.
	 */
	private static function create_referral_lead( $code, $friend_name, $friend_email, $friend_phone, $friend_address = '', $friend_state = '' ) {
		global $wpdb;
		$wpdb->insert(
			GAS_DB::table( 'leads' ),
			array(
				'partner_id'      => 0,
				'code_id'         => $code->id,
				'customer_name'   => $friend_name,
				'customer_email'  => $friend_email,
				'customer_phone'  => $friend_phone,
				'customer_address' => $friend_address,
				'customer_state'  => $friend_state,
				'status'          => 'new',
				'created_at'      => current_time( 'mysql' ),
			)
		);
		$lead_id = $wpdb->insert_id;

		if ( $friend_email ) {
			GAS_Contacts::upsert( $friend_email, 'customer', array(
				'name'          => $friend_name,
				'phone'         => $friend_phone,
				'source'        => 'referral',
				'related_table' => 'leads',
				'related_id'    => $lead_id,
			) );

			$site_name = GAS_Settings::get( 'site_name' );
			wp_mail(
				$friend_email,
				"{$code->sub_affiliate_name} referred you to {$site_name}",
				"Hi {$friend_name},\n\n{$code->sub_affiliate_name} thought you'd want to know about {$site_name}. We'll be in touch shortly with next steps.\n\nIf you have any questions in the meantime, feel free to reach out, or just ask {$code->sub_affiliate_name} directly since they already know what this is about."
			);
		}

		if ( $friend_state ) {
			self::notify_coverage_match( $lead_id, $friend_name, $friend_state );
		}

		return $lead_id;
	}

	/**
	 * True if a partner's `state` field (one or more comma-separated
	 * 2-letter codes, e.g. "FL,TX,GA,CA" for a multi-state partner) lists
	 * the given state. Case/whitespace-tolerant since this data is
	 * hand-entered in wp-admin.
	 */
	private static function partner_covers_state( $partner, $state ) {
		if ( empty( $partner->state ) || '' === $state ) {
			return false;
		}
		$codes = array_map( 'trim', explode( ',', strtoupper( $partner->state ) ) );
		return in_array( strtoupper( $state ), $codes, true );
	}

	/**
	 * Names of approved partners whose coverage includes this state —
	 * purely informational (a suggestion for whoever matches the lead to
	 * a partner from the Leads screen), never used to auto-assign. The
	 * "admin always matches a partner, never the system" rule holds even
	 * when the match is this obvious.
	 */
	private static function partners_covering_state( $state ) {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT name, state FROM " . GAS_DB::table( 'partners' ) . " WHERE outreach_status = 'approved'" );
		$matches = array();
		foreach ( $rows as $r ) {
			if ( self::partner_covers_state( $r, $state ) ) {
				$matches[] = $r->name;
			}
		}
		return $matches;
	}

	/**
	 * Lets Cary know right away whether an incoming referral falls inside
	 * any approved partner's coverage — a suggestion to speed up matching
	 * it from the Leads screen if so, or an explicit heads-up if not (the
	 * "no compatible partner found" case Cary asked for), since that's
	 * the moment he'd want to go find a new partner for that area rather
	 * than discover the gap only when he happens to look at Leads.
	 */
	private static function notify_coverage_match( $lead_id, $friend_name, $state ) {
		$matches   = self::partners_covering_state( $state );
		$site_name = GAS_Settings::get( 'site_name' );

		if ( $matches ) {
			$body = "Referral from {$friend_name} (state: {$state}) matches coverage for: " . implode( ', ', $matches ) . ".\n\nMatch them to a partner from {$site_name} > Leads.";
			$subject = "Referral matched to a partner's coverage: {$friend_name}";
		} else {
			$body = "Referral from {$friend_name} is in {$state}, which no currently approved partner covers.\n\nEither find/add a partner for this area, or handle this referral manually. It's sitting unassigned under {$site_name} > Leads either way.";
			$subject = "No compatible partner found for a referral in {$state}";
		}

		wp_mail( get_option( 'admin_email' ), $subject, $body );
		GAS_Admin::audit_log( 'lead', $lead_id, $matches ? 'coverage_matched' : 'no_coverage_match', array( 'state' => $state, 'matches' => $matches ) );
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

		$preview       = GAS_Roles::get_admin_preview();
		$is_previewing = $preview && 'agent' === $preview['type'];

		if ( ! $is_previewing && ! GAS_Roles::is_affiliate() ) {
			return '<div class="gas-notice">This dashboard is for affiliates only. <a href="' . esc_url( wp_logout_url( self::signup_url() ) ) . '">Log out</a> and sign up as an affiliate, or contact us if you think this is a mistake.</div>';
		}

		if ( $is_previewing ) {
			$user_id = (int) $preview['id'];
			$user    = get_userdata( $user_id );
			if ( ! $user ) {
				return '<div class="gas-notice">That affiliate account no longer exists.</div>';
			}
		} else {
			$user_id = get_current_user_id();
			$user    = wp_get_current_user();
		}
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
		echo '<p>Welcome back, ' . esc_html( $user->display_name ) . '. <a href="' . esc_url( wp_logout_url( self::dashboard_url() ) ) . '">Log out</a> &middot; <a href="' . esc_url( GAS_Help::page_url() ) . '">Help</a></p>';

		if ( $is_previewing ) {
			echo '<div class="gas-notice" style="border-left:4px solid #d98500;padding:8px 12px;background:#fff8e5;">';
			echo 'Previewing <strong>' . esc_html( $user->display_name ) . '\'s</strong> dashboard (read-only). ';
			echo '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gas_stop_admin_preview' ), 'gas_stop_admin_preview' ) ) . '">Stop previewing</a>';
			echo '</div>';
		}

		if ( 'suspended' === $status ) {
			echo '<div class="gas-notice gas-notice-error">Your affiliate account is currently suspended and your link is inactive. Contact us if you have questions.</div>';
		}

		self::render_stats_section( $user_id );
		self::render_downline_section( $user_id );
		self::render_password_section( $is_previewing );
		self::render_payment_section( $user_id, $is_previewing );

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

		self::render_pending_and_finalized_section( $user_id );
	}

	/**
	 * Pending (this month, still open) vs. finalized (prior months, exact)
	 * earnings by tier. Pending is deliberately shown as a range (count ×
	 * tier_dollar_range()) rather than an exact figure — only once a month
	 * closes does the dashboard show the real dollar amount, matching the
	 * admin-vs-affiliate display split decided for this feature.
	 */
	private static function render_pending_and_finalized_section( $user_id ) {
		$pending   = GAS_Payouts::pending_tier_counts( $user_id );
		$finalized = GAS_Payouts::finalized_tier_totals( $user_id );

		echo '<h2>Earnings by tier</h2>';
		echo '<p class="gas-fineprint">This month is still open, so it shows an estimated range rather than an exact amount. Once the month closes, it moves into your finalized total below with the exact dollar amount you earned.</p>';

		echo '<table class="widefat striped"><thead><tr><th>Tier</th><th>This month (pending)</th><th>Finalized (prior months)</th></tr></thead><tbody>';
		foreach ( array( 1, 2, 3 ) as $tier ) {
			$count = $pending[ $tier ];
			$range = GAS_Payouts::tier_dollar_range( $tier );

			echo '<tr><td>Tier ' . esc_html( $tier ) . '</td><td>';
			if ( 0 === $count ) {
				echo '&mdash;';
			} elseif ( $range ) {
				echo esc_html( $count ) . ' sale' . ( 1 === $count ? '' : 's' ) . ' &mdash; est. $' . esc_html( number_format( $range['min'] * $count, 2 ) ) . '&ndash;$' . esc_html( number_format( $range['max'] * $count, 2 ) );
			} else {
				echo esc_html( $count ) . ' sale' . ( 1 === $count ? '' : 's' );
			}
			echo '</td><td>$' . esc_html( number_format( $finalized[ $tier ], 2 ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * An affiliate's own downline — people they personally recruited
	 * (their direct tier-2 team) and, one level further, the people THOSE
	 * recruits brought in (tier 3). Strictly this affiliate's own tree
	 * going downward: found via sponsor_code_id chains rooted at their
	 * own code(s), never a sideways lookup into anyone else's downline.
	 */
	private static function render_downline_section( $user_id ) {
		global $wpdb;
		$codes_table = GAS_DB::table( 'codes' );

		$my_code_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$codes_table} WHERE wp_user_id = %d", $user_id ) );
		if ( ! $my_code_ids ) {
			return;
		}
		$placeholders = implode( ',', array_fill( 0, count( $my_code_ids ), '%d' ) );

		$direct = $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM {$codes_table} WHERE sponsor_code_id IN ({$placeholders})",
			$my_code_ids
		) );

		$direct_ids = wp_list_pluck( $direct, 'id' );
		$indirect   = array();
		if ( $direct_ids ) {
			$placeholders2 = implode( ',', array_fill( 0, count( $direct_ids ), '%d' ) );
			$indirect = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$codes_table} WHERE sponsor_code_id IN ({$placeholders2})",
				$direct_ids
			) );
		}

		echo '<h2>Your team</h2>';
		echo '<p class="gas-fineprint">People you\'ve personally recruited, and the people they\'ve recruited in turn — your own tree only.</p>';
		echo '<div class="gas-stat-row">';
		echo '<div class="gas-stat"><span class="gas-stat-num">' . esc_html( count( $direct ) ) . '</span><span class="gas-stat-label">Direct recruits</span></div>';
		echo '<div class="gas-stat"><span class="gas-stat-num">' . esc_html( count( $indirect ) ) . '</span><span class="gas-stat-label">Their recruits</span></div>';
		echo '</div>';

		if ( $direct ) {
			echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Contact</th><th>Level</th></tr></thead><tbody>';
			foreach ( $direct as $d ) {
				self::render_downline_row( $d, 'Direct' );
			}
			foreach ( $indirect as $i ) {
				self::render_downline_row( $i, 'Their recruit' );
			}
			echo '</tbody></table>';
		}
	}

	private static function render_downline_row( $code, $level ) {
		$user  = $code->wp_user_id ? get_userdata( $code->wp_user_id ) : null;
		$phone = $code->wp_user_id ? get_user_meta( $code->wp_user_id, 'gas_phone', true ) : '';
		echo '<tr>';
		echo '<td>' . esc_html( $code->sub_affiliate_name ) . '</td>';
		echo '<td>' . ( $user ? esc_html( $user->user_email ) : '' ) . ( $phone ? '<br>' . esc_html( $phone ) : '' ) . '</td>';
		echo '<td>' . esc_html( $level ) . '</td>';
		echo '</tr>';
	}

	private static function render_password_section( $is_previewing = false ) {
		echo '<h2>Change password</h2>';
		if ( $is_previewing ) {
			echo '<p class="gas-fineprint">Disabled while previewing &mdash; this would change your own admin password, not this affiliate\'s.</p>';
			return;
		}
		?>
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

	private static function render_payment_section( $user_id, $is_previewing = false ) {
		echo '<h2>Payment information</h2>';
		if ( $is_previewing ) {
			echo '<p class="gas-fineprint">Hidden while previewing &mdash; payout details are only ever visible to the affiliate themselves, never to an admin, even in preview mode.</p>';
			return;
		}
		$d = GAS_Payouts::get_details( $user_id );
		?>
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
