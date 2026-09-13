<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GAS_Frontend {

	public static function init() {
		add_shortcode( 'gas_affiliate_signup', array( __CLASS__, 'render_signup' ) );
		// Dashboard restructure (2026-09-10, Cary's ask after user-testing
		// feedback): one long page split into 4 — Overview stays on the
		// original shortcode/page (least disruptive, since dashboard_url()
		// is baked into emails/redirects everywhere already), the other 3
		// are new. See render_dashboard_subnav() for how they're tied
		// together, and DASHBOARD_FAMILY_SHORTCODES below for where a
		// logged-in affiliate's personal theme choice applies.
		add_shortcode( 'gas_affiliate_dashboard', array( __CLASS__, 'render_dashboard' ) );
		add_shortcode( 'gas_affiliate_links', array( __CLASS__, 'render_links_page' ) );
		add_shortcode( 'gas_affiliate_team', array( __CLASS__, 'render_team_page' ) );
		add_shortcode( 'gas_affiliate_account', array( __CLASS__, 'render_account_page' ) );
		add_shortcode( 'gas_signup_or_refer', array( __CLASS__, 'render_signup_or_refer' ) );
		add_shortcode( 'gas_team_payout_example', array( __CLASS__, 'render_team_payout_example' ) );
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
		add_action( 'admin_post_gas_save_tax_info', array( __CLASS__, 'handle_save_tax_info' ) );
		add_action( 'admin_post_gas_save_dashboard_theme', array( __CLASS__, 'handle_save_dashboard_theme' ) );
		add_action( 'admin_post_gas_dashboard_add_referral', array( __CLASS__, 'handle_dashboard_add_referral' ) );
		add_action( 'admin_post_gas_dashboard_add_team_member', array( __CLASS__, 'handle_dashboard_add_team_member' ) );
	}

	/**
	 * The 4 dashboard-family shortcodes, together — used to decide where a
	 * logged-in affiliate's personal dashboard-color preference applies
	 * (every one of "their" pages, not just Overview) and to build the
	 * shared subnav. Order here is the display order in that subnav.
	 */
	const DASHBOARD_FAMILY = array(
		'gas_affiliate_dashboard' => array( 'label' => 'Overview', 'url_fn' => 'dashboard_url' ),
		'gas_affiliate_links'     => array( 'label' => 'My Links & Earnings', 'url_fn' => 'links_url' ),
		'gas_affiliate_team'      => array( 'label' => 'My Team', 'url_fn' => 'team_url' ),
		'gas_affiliate_account'   => array( 'label' => 'Account', 'url_fn' => 'account_url' ),
	);

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

	/**
	 * Every shortcode whose output actually uses gas-frontend.css classes
	 * (.gas-form/.gas-panel/.gas-table/.gas-stat-row/etc.) — kept as one
	 * list rather than a handful of hardcoded checks after finding TWO
	 * separate real bugs from the old approach: first (2026-09-06) a check
	 * that only looked at post_content and missed Elementor pages
	 * entirely (see post_has_shortcode() above, which now handles both),
	 * second (2026-09-10) a hardcoded shortcode list that simply never
	 * included gas_partner_dashboard/gas_help/gas_partner_help/gas_faq —
	 * meaning the Partner Portal and every Help page never loaded this
	 * stylesheet AT ALL, silently, since the day each was built — and
	 * third (2026-09-11, caught auditing the whole plugin after noticing
	 * the same thing on Get a Quote): gas_lead_form and gas_lead_magnet
	 * were ALSO missing, meaning the entire Get a Quote page and every
	 * lead-magnet opt-in widget have been rendering as bare, unstyled
	 * HTML — no card, no spacing, plain browser-default inputs and
	 * button — since the day each was built. Add any new shortcode that
	 * uses these classes here, not as a one-off check.
	 */
	const STYLED_SHORTCODES = array(
		'gas_affiliate_signup',
		'gas_affiliate_dashboard',
		'gas_affiliate_links',
		'gas_affiliate_team',
		'gas_affiliate_account',
		'gas_signup_or_refer',
		'gas_partner_dashboard',
		'gas_help',
		'gas_partner_help',
		'gas_faq',
		'gas_lead_form',
		'gas_lead_magnet',
		'gas_team_payout_example',
	);

	public static function enqueue_assets() {
		if ( is_singular() ) {
			global $post;
			foreach ( self::STYLED_SHORTCODES as $tag ) {
				if ( self::post_has_shortcode( $post, $tag ) ) {
					wp_enqueue_style( 'gas-frontend', plugins_url( 'assets/gas-frontend.css', GAS_PLUGIN_FILE ), array(), GAS_VERSION );
					// This site's chosen color theme (2026-09-10) — every
					// themeable color in gas-frontend.css keys off these
					// --gas-accent* custom properties, so overriding them
					// here is the entire mechanism, no per-page CSS needed.
					// An affiliate's own dashboard is the one exception: if
					// they've picked a personal theme (see
					// render_theme_preference_section()), it overrides the
					// site default on their dashboard view only — every
					// other page (signup, help, partner portal, etc.) always
					// uses the site-wide theme regardless of who's logged in.
					// Resolves the SAME effective user render_dashboard()
					// does (real affiliate id even under admin preview, via
					// GAS_Roles::get_admin_preview()) so an admin previewing
					// an affiliate sees that affiliate's own chosen theme,
					// not their own admin account's (which has none set).
					$css_vars   = GAS_Settings::theme_css_vars();
					$view_as_id = 0;
					if ( array_key_exists( $tag, self::DASHBOARD_FAMILY ) ) {
						$preview = GAS_Roles::get_admin_preview();
						if ( $preview && 'agent' === $preview['type'] ) {
							$view_as_id = (int) $preview['id'];
						} elseif ( is_user_logged_in() ) {
							$view_as_id = get_current_user_id();
						}
					}
					if ( $view_as_id ) {
						$user_theme = get_user_meta( $view_as_id, 'gas_dashboard_theme', true );
						if ( $user_theme && isset( GAS_Settings::THEMES[ $user_theme ] ) ) {
							$css_vars = GAS_Settings::theme_css_vars_for( $user_theme );
						}
					}
					wp_add_inline_style( 'gas-frontend', $css_vars );
					break;
				}
			}
			// Dashicons is normally admin-only — the per-link capability
			// icons (see render_capability_icons()) and the share-button
			// row reuse it on the front end instead of adding a new
			// icon-font dependency. Checked against every dashboard-family
			// page, not just Overview/Links & Earnings: the Team page's
			// "invite someone" row also calls render_share_buttons() (its
			// recruit-link share row) — a bug caught in review, since those
			// icons would silently render as missing glyphs without this.
			foreach ( array_keys( self::DASHBOARD_FAMILY ) as $family_tag ) {
				if ( self::post_has_shortcode( $post, $family_tag ) ) {
					wp_enqueue_style( 'dashicons' );
					break;
				}
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
		// 3 new pages from the 2026-09-10 dashboard restructure — same
		// auto-create-once pattern as every other GAS page.
		GAS_Help::create_or_adopt_page( 'gas_links_page_id', 'My Links & Earnings', 'my-links-earnings', '[gas_affiliate_links]' );
		GAS_Help::create_or_adopt_page( 'gas_team_page_id', 'My Team', 'my-team', '[gas_affiliate_team]' );
		// Not "my-account" — that's WooCommerce's own page on most
		// installs (including Home's), would silently adopt/collide with
		// it via create_or_adopt_page()'s existing-slug check.
		GAS_Help::create_or_adopt_page( 'gas_account_page_id', 'Affiliate Account', 'affiliate-account', '[gas_affiliate_account]' );
	}

	public static function dashboard_url() {
		$id = get_option( 'gas_dashboard_page_id' );
		return $id ? get_permalink( $id ) : home_url( '/affiliate-dashboard/' );
	}

	public static function links_url() {
		$id = get_option( 'gas_links_page_id' );
		return $id ? get_permalink( $id ) : home_url( '/my-links-earnings/' );
	}

	public static function team_url() {
		$id = get_option( 'gas_team_page_id' );
		return $id ? get_permalink( $id ) : home_url( '/my-team/' );
	}

	public static function account_url() {
		$id = get_option( 'gas_account_page_id' );
		return $id ? get_permalink( $id ) : home_url( '/affiliate-account/' );
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

	/**
	 * The display name for whoever's sponsor cookie is currently set (see
	 * get_sponsor_code_id() above) — used at GET-time on the signup page
	 * itself (2026-09-12, Cary's ask) so a visitor can SEE who they're
	 * about to be attributed to before submitting, and back out if it's
	 * wrong. Deliberately a separate read from get_sponsor_code_id() being
	 * used at POST-time in handle_signup() — same cookie, same lookup
	 * logic, just called at a different point in the request lifecycle.
	 */
	private static function get_sponsor_display_name() {
		$id = self::get_sponsor_code_id();
		if ( ! $id ) {
			return null;
		}
		global $wpdb;
		$table = GAS_DB::table( 'codes' );
		$name  = $wpdb->get_var( $wpdb->prepare( "SELECT sub_affiliate_name FROM {$table} WHERE id = %d", $id ) );
		return $name ? $name : null;
	}

	/**
	 * Attribution-awareness banner for every public entry point that can
	 * create a brand-new affiliate account — render_signup() (Become an
	 * Affiliate) and the non-logged-in branch of render_signup_or_refer()
	 * (Refer a Friend's "Sign Up as an Affiliate" side), 2026-09-12. The
	 * whole point of the multi-tier system is that a new affiliate gets
	 * linked to whoever actually invited them (sponsor_code_id), but
	 * someone who lands on either page directly (bookmarked, googled,
	 * typed the URL) instead of via the inviter's own link signs up with
	 * NO sponsor, silently breaking the chain the inviter was expecting to
	 * be credited for. Naming who they'll be attributed to (real name, or
	 * "System Admin" when there's no sponsor cookie) gives them a chance
	 * to back out and go find the real link instead. Not shown on the
	 * logged-in-affiliate branch of render_signup_or_refer(), since that
	 * path never creates a new account.
	 *
	 * Styled 2026-09-12 to match the dashboard's own admin-preview banner
	 * (see the "Previewing X's dashboard" notice in render_dashboard()) —
	 * a light-yellow callout with an amber left border, one short line —
	 * rather than a full-bleed orange/brown warning block. Cary's ask:
	 * one header-weight line ("You are signing up under X"), 1-2 lines
	 * max total.
	 */
	private static function render_invited_by_notice() {
		$sponsor_name = self::get_sponsor_display_name();
		$who          = $sponsor_name ? esc_html( $sponsor_name ) : 'System Admin';
		return '<div class="gas-invited-by-notice"><strong>You are signing up under ' . $who . '.</strong> Not them? Use the referral link they shared with you instead.</div>';
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

		echo self::render_invited_by_notice();

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
				<label><input type="checkbox" name="agree_terms" value="1" required> <?php echo self::agreement_checkbox_label(); ?></label>
			</p>
			<p>
				<button type="submit" class="gas-button">Sign up</button>
			</p>
			<p class="gas-fineprint">Already have an account? <a href="<?php echo esc_url( self::dashboard_url() ); ?>">Log in on your dashboard</a>.</p>
		</form>
		<?php
		return ob_get_clean();
	}

	/**
	 * Shared markup for the agreement-acceptance checkbox label, used by
	 * both signup forms. Links to program_terms_url when set; falls back
	 * to plain text (no link) if a site hasn't published its terms page
	 * yet, rather than linking to a blank/broken URL.
	 */
	private static function agreement_checkbox_label() {
		$terms = GAS_Settings::get( 'program_terms_url' );
		if ( $terms ) {
			return 'I agree to the <a href="' . esc_url( $terms ) . '" target="_blank" rel="noopener">Affiliate Program Agreement</a>.';
		}
		return 'I agree to the Affiliate Program Agreement.';
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
		if ( empty( $_POST['agree_terms'] ) ) {
			$fail( 'Please check the box to agree to the Affiliate Program Agreement.' );
		}
		if ( email_exists( $email ) ) {
			$fail( 'That email is already registered. Try logging in instead.' );
		}
		if ( GAS_Fraud::is_disposable_email( $email ) ) {
			$fail( 'Please use a permanent email address — temporary/disposable email services aren\'t accepted for affiliate signup.' );
		}
		$signup_ip = GAS_Fraud::get_client_ip();
		if ( GAS_Fraud::signup_rate_limited( $signup_ip ) ) {
			$fail( 'Too many signups from this connection today — please try again tomorrow, or contact us if you think this is a mistake.' );
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
		GAS_Fraud::record_signup_attempt( $signup_ip );

		update_user_meta( $user_id, 'gas_status', 'active' );
		update_user_meta( $user_id, GAS_Payouts::META_SIGNUP_IP, $signup_ip );
		update_user_meta( $user_id, 'gas_agreement_accepted_at', current_time( 'mysql' ) );
		if ( '' !== $phone ) {
			update_user_meta( $user_id, 'gas_phone', $phone );
		}

		global $wpdb;
		$site_name       = GAS_Settings::get( 'site_name' );
		$sponsor_code_id = self::get_sponsor_code_id();

		// One stable code per affiliate, campaign-agnostic — ported from
		// GRC's link architecture, 2026-09-08. The affiliate never
		// chooses (or needs to be matched to) a specific partner at all:
		// their one code works with any active campaign's link
		// immediately (see GAS_Campaigns::build_link()), no admin step
		// needed for the common case, and no "which partner is this
		// affiliate assigned to" concept exists anymore.
		$code_row = self::get_or_create_code_for_user( $user_id, $name, $sponsor_code_id );

		GAS_Contacts::upsert( $email, 'affiliate', array(
			'name'          => $name,
			'phone'         => $phone,
			'source'        => 'signup',
			'related_table' => 'codes',
			'related_id'    => $code_row->id,
		) );

		wp_mail(
			get_option( 'admin_email' ),
			'New affiliate joined: ' . $name,
			"A new affiliate signed up and is live immediately.\n\nName: {$name}\nEmail: {$email}\nPhone: " . ( $phone ?: '(not provided)' ) . "\nReferral code: {$code_row->code}\n\nTheir dashboard already shows a working link for every active campaign — no matching step needed."
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
		$pools = self::approved_partner_agent_pools();
		if ( ! $pools ) {
			return null;
		}
		$tier1_pct = (float) GAS_Settings::get( 'tier1_split_percent' );
		$estimates = array_map(
			function( $pool ) use ( $tier1_pct ) {
				return GAS_Payouts::round_up_to_ten( $pool * ( $tier1_pct / 100 ) );
			},
			$pools
		);
		return array( 'min' => min( $estimates ), 'max' => max( $estimates ) );
	}

	/**
	 * The agent-pool dollar amount (gross minus cashback/fulfillment cut,
	 * before the tier split) for every approved partner with enough data
	 * to price — shared by estimated_payout_range() (tier 1 only) and
	 * estimated_team_payout_ranges() (all 3 tiers), so both stay based on
	 * the exact same live partner set instead of two SQL queries that
	 * could silently drift apart.
	 */
	private static function approved_partner_agent_pools() {
		global $wpdb;
		$rows = $wpdb->get_results(
			'SELECT payout_type, payout_amount, payout_percent, typical_sale_amount, agent_pool_type, agent_pool_value FROM ' . GAS_DB::table( 'partners' ) . " WHERE outreach_status = 'approved'"
		);

		$pools = array();
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
			if ( $agent_pool > 0 ) {
				$pools[] = $agent_pool;
			}
		}
		return $pools;
	}

	/**
	 * Same idea as estimated_payout_range() but all 3 recruiting tiers at
	 * once, for the team-building page's payout example (2026-09-12,
	 * Cary's ask): rather than hardcoding a dollar example that goes
	 * stale the moment a partner's rate changes or a second partner is
	 * added, this is computed live off the same real partner data every
	 * time the page renders. Returns null when there's no priceable
	 * partner yet (caller falls back to generic copy).
	 */
	public static function estimated_team_payout_ranges() {
		$pools = self::approved_partner_agent_pools();
		if ( ! $pools ) {
			return null;
		}
		$pcts = array(
			'tier1' => (float) GAS_Settings::get( 'tier1_split_percent' ),
			'tier2' => (float) GAS_Settings::get( 'tier2_split_percent' ),
			'tier3' => (float) GAS_Settings::get( 'tier3_split_percent' ),
		);
		$out = array();
		foreach ( $pcts as $tier => $pct ) {
			$estimates    = array_map(
				function( $pool ) use ( $pct ) {
					return GAS_Payouts::round_up_to_ten( $pool * ( $pct / 100 ) );
				},
				$pools
			);
			$out[ $tier ] = array( 'min' => min( $estimates ), 'max' => max( $estimates ) );
		}
		return $out;
	}

	/**
	 * Formats a min/max range as the existing site convention: collapse to
	 * one number when they round the same (the common one-partner case —
	 * "between $490 and $490" reads like a bug), otherwise "$X-$Y". Shared
	 * by the payout-range copy and the new team-payout-example shortcode
	 * so both display ranges identically.
	 */
	private static function format_dollar_range( $range ) {
		$min_fmt = number_format( $range['min'], 0 );
		$max_fmt = number_format( $range['max'], 0 );
		return $min_fmt === $max_fmt ? '$' . $min_fmt : '$' . $min_fmt . '-$' . $max_fmt;
	}

	/**
	 * [gas_team_payout_example] — live tier1/2/3 dollar figures for the
	 * team-building page, always computed from current approved-partner
	 * data (see estimated_team_payout_ranges()) rather than a hardcoded
	 * number in the page copy, so it never goes stale as partners are
	 * added or a rate changes.
	 */
	public static function render_team_payout_example() {
		$ranges = self::estimated_team_payout_ranges();
		if ( ! $ranges ) {
			return '<p class="gas-team-payout-example">Exact payout depends on the partner — you\'ll see the real number once you\'re matched.</p>';
		}
		return '<p class="gas-team-payout-example">Right now, a completed referral pays <strong>' . esc_html( self::format_dollar_range( $ranges['tier1'] ) ) . '</strong> to you directly, <strong>' . esc_html( self::format_dollar_range( $ranges['tier2'] ) ) . '</strong> to whoever brought you in, and <strong>' . esc_html( self::format_dollar_range( $ranges['tier3'] ) ) . '</strong> two levels up &mdash; recalculated live, so this always reflects the current rate.</p>';
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
		return self::insert_code_row( $user_id, $name, 0, $sponsor_code_id, 'Self-signup, live immediately. No partner assigned yet.' );
	}

	private static function insert_code_row( $user_id, $name, $partner_id, $sponsor_code_id, $notes ) {
		global $wpdb;
		$codes_table = GAS_DB::table( 'codes' );
		$code        = self::generate_unique_code( $name );
		$wpdb->insert(
			$codes_table,
			array(
				'code'               => $code,
				'sub_affiliate_name' => $name,
				'partner_id'         => $partner_id,
				'wp_user_id'         => $user_id,
				'sponsor_code_id'    => $sponsor_code_id,
				'status'             => 'active',
				'active'             => 1,
				'notes'              => $notes,
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

		// Wrapped in the same card treatment as Get a Quote (2026-09-12,
		// Cary's ask — "format Refer a Friend to look like Get a Quote,
		// keeping its own picture") for visual consistency between the
		// two lead-capture-style pages. The page's own hero/infographic
		// images live in this page's Elementor content OUTSIDE this
		// shortcode's output, so they're untouched by this — only the
		// shortcode's own form area gets the panel styling.
		echo '<div class="gas-panel gas-quote-panel">';

		$range = self::estimated_payout_range();
		$noun  = GAS_Settings::get( 'conversion_noun' );
		echo '<div class="gas-payout-range">';
		if ( $range ) {
			$min_fmt = number_format( $range['min'], 0 );
			$max_fmt = number_format( $range['max'], 0 );
			if ( $min_fmt === $max_fmt ) {
				// Compare the FORMATTED values, not the raw floats — with
				// only one real partner (or several that happen to net the
				// same rounded number), "between $490 and $490" read like a
				// bug, not a real range. One partner is the common case
				// right now, so this isn't an edge case worth ignoring.
				echo '<p>Earn <strong>$' . esc_html( $min_fmt ) . '</strong> per completed ' . esc_html( $noun ) . ' you refer.</p>';
			} else {
				echo '<p>Earn between <strong>$' . esc_html( $min_fmt ) . '</strong> and <strong>$' . esc_html( $max_fmt ) . '</strong> per completed ' . esc_html( $noun ) . ' you refer.</p>';
			}
		} else {
			echo '<p>Get paid for every completed ' . esc_html( $noun ) . ' you refer &mdash; exact amounts depend on the partner, and you\'ll see your rate once you\'re matched.</p>';
		}
		echo '</div>';

		// Cary's ask (2026-09-11): a logged-in affiliate visiting this
		// public page already has an account — showing them name/email/
		// phone/password/agree-terms fields to refer a friend is asking
		// them to re-register themselves. Reuses render_add_referral_section()
		// (the dashboard's own "Add a referral" panel, friend-fields only,
		// posts to gas_dashboard_add_referral, attaches to their existing
		// code) rather than a second form+handler — same fraud/rate-limit
		// posture as the dashboard version, nothing new to secure. Only
		// an actual affiliate gets this treatment; anyone else logged in
		// (a customer, a partner, an admin browsing) still sees the full
		// public form below.
		$logged_in_affiliate = is_user_logged_in() && GAS_Roles::is_affiliate();

		if ( $logged_in_affiliate ) {
			$current_user = wp_get_current_user();
			echo '<p>Referring as <strong>' . esc_html( $current_user->display_name ) . '</strong> &mdash; we already have your details on file, just add your friend\'s info below.</p>';
			self::render_add_referral_section( $current_user->ID, false, true );
		} else {
			echo self::render_invited_by_notice();
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
						<select id="gas_friend_state" name="friend_state" class="gas-input"><?php echo GAS_DB::state_dropdown_options(); ?></select>
						<span class="gas-fineprint">Lets us match them to a partner that actually covers their area.</span>
					</p>
				</div>

				<p>
					<label><input type="checkbox" name="agree_terms" value="1" required> <?php echo self::agreement_checkbox_label(); ?></label>
				</p>
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
		}
		echo '</div>';
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
			// Validated against GAS_DB::us_states() rather than trusted
			// as-typed, now that this is a real dropdown (2026-09-10) —
			// a tampered request can't inject an arbitrary string here.
			$friend_state_raw = isset( $_POST['friend_state'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['friend_state'] ) ) ) : '';
			$friend_state      = array_key_exists( $friend_state_raw, GAS_DB::us_states() ) ? $friend_state_raw : '';
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
					"Hi {$existing_affiliate->display_name},\n\nSomeone just referred {$friend_name} using your details on {$site_name}. It's been added to your account under your referral code {$code->code}.\n\nLog in to your dashboard to keep an eye on it: " . self::dashboard_url() . GAS_Settings::compliance_footer( $existing_affiliate->user_email )
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
		if ( empty( $_POST['agree_terms'] ) ) {
			$fail( 'Please check the box to agree to the Affiliate Program Agreement.' );
		}
		if ( GAS_Fraud::is_disposable_email( $email ) ) {
			$fail( 'Please use a permanent email address — temporary/disposable email services aren\'t accepted for affiliate signup.' );
		}
		$signup_ip = GAS_Fraud::get_client_ip();
		if ( GAS_Fraud::signup_rate_limited( $signup_ip ) ) {
			$fail( 'Too many signups from this connection today — please try again tomorrow, or contact us if you think this is a mistake.' );
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
		GAS_Fraud::record_signup_attempt( $signup_ip );

		update_user_meta( $user_id, 'gas_status', 'active' );
		update_user_meta( $user_id, GAS_Payouts::META_SIGNUP_IP, $signup_ip );
		update_user_meta( $user_id, 'gas_agreement_accepted_at', current_time( 'mysql' ) );
		if ( '' !== $phone ) {
			update_user_meta( $user_id, 'gas_phone', $phone );
		}

		// One stable code per affiliate, campaign-agnostic — see the
		// matching comment in handle_signup() above.
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
			"Hi {$name},\n\nYour affiliate account is live. Your referral link and dashboard are ready here: " . self::dashboard_url() . "{$referral_note}\n\nOne quick thing — please confirm your email so we know it's really you: {$verify_link}\n\nYour dashboard already shows a working link for every active campaign — nothing else to wait on." . GAS_Settings::compliance_footer( $email )
		);

		$admin_extra = $is_referral ? "\nAlso referred: {$friend_name} / " . ( $friend_email ?: '(no email)' ) . ' / ' . ( $friend_phone ?: '(no phone)' ) . " (match the referral under Leads)" : '';
		wp_mail(
			get_option( 'admin_email' ),
			'New affiliate joined: ' . $name,
			"A new affiliate signed up and is live immediately.\n\nName: {$name}\nEmail: {$email}\nPhone: " . ( $phone ?: '(not provided)' ) . "\nReferral code: {$code_row->code}{$admin_extra}"
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
	private static function create_referral_lead( $code, $friend_name, $friend_email, $friend_phone, $friend_address = '', $friend_state = '', $partner_id = 0 ) {
		global $wpdb;
		// consent_call_text intentionally left at its default (0/not
		// consented) here — the affiliate is submitting their FRIEND's
		// phone number, not their own, and TCPA consent has to come from
		// the person actually being called/texted. See the note on
		// leads.consent_* in class-gas-db.php and
		// GAS_Leads::relay_lead_to_partner(), which warns whoever gets
		// this lead assigned to them not to autodial/text it.
		$wpdb->insert(
			GAS_DB::table( 'leads' ),
			array(
				// $partner_id lets the referring affiliate pick the
				// partner directly (2026-09-10, Cary's ask — "we need to
				// be able to select which fulfillment partner to add them
				// to") instead of always landing unassigned for an admin
				// to sort out later. Still defaults to 0/unassigned for
				// every OTHER caller of this method (the public
				// refer-a-friend page doesn't collect a partner choice),
				// so nothing else changes behavior.
				'partner_id'      => $partner_id,
				'code_id'         => $code->id,
				'customer_name'   => $friend_name,
				'customer_email'  => $friend_email,
				'customer_phone'  => $friend_phone,
				'customer_address' => $friend_address,
				'customer_state'  => $friend_state,
				'status'          => $partner_id ? 'accepted' : 'new',
				'created_at'      => current_time( 'mysql' ),
			)
		);
		$lead_id = $wpdb->insert_id;

		// A partner was explicitly chosen — relay to them the SAME way an
		// admin's manual assignment does (GAS_Leads::assign_partner()),
		// rather than the coverage-matching guess below, which only
		// makes sense when nobody's picked a partner yet.
		if ( $partner_id ) {
			GAS_Leads::assign_partner( $lead_id, $partner_id );
		}

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
				"Hi {$friend_name},\n\n{$code->sub_affiliate_name} thought you'd want to know about {$site_name}. We'll be in touch shortly with next steps.\n\nIf you have any questions in the meantime, feel free to reach out, or just ask {$code->sub_affiliate_name} directly since they already know what this is about." . GAS_Settings::compliance_footer( $friend_email )
			);
		}

		if ( $friend_state && ! $partner_id ) {
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
	 * DASHBOARD (4 pages as of 2026-09-10 — see DASHBOARD_FAMILY above)
	 * ---------------------------------------------------------------- */

	/**
	 * Shared open sequence for all 4 dashboard-family pages — login check,
	 * admin-preview resolution, suspended/notice banners, the welcome bar,
	 * and the subnav. Was all duplicated inline in render_dashboard()
	 * before this split; factored out so the other 3 pages don't each
	 * re-implement (and risk drifting on) the exact same cache/preview/
	 * suspension logic. Returns `array(false, $html_to_return_directly)`
	 * for a not-logged-in/not-an-affiliate/gone case — caller should
	 * `return` that html immediately, page not rendered. Otherwise
	 * returns `array(true, array($user_id, $user, $is_previewing))` with
	 * output buffering already started (caller echoes page-specific
	 * content next, then calls dashboard_page_close()).
	 */
	private static function dashboard_page_open( $active_tag ) {
		// Never let a page-cache/CDN layer serve this response to anyone
		// but the exact visitor who requested it — every one of these
		// pages is entirely per-user content. See the 2026-09-10 LiteSpeed
		// bug this guards against: nocache_headers() alone wasn't enough
		// on this host, needs LiteSpeed Cache's own explicit API too.
		nocache_headers();
		do_action( 'litespeed_control_set_nocache', 'gas dashboard is per-user content' );

		if ( ! is_user_logged_in() ) {
			return array( false, self::render_login_form() );
		}

		$preview       = GAS_Roles::get_admin_preview();
		$is_previewing = $preview && 'agent' === $preview['type'];

		if ( ! $is_previewing && ! GAS_Roles::is_affiliate() ) {
			return array( false, '<div class="gas-notice">This dashboard is for affiliates only. <a href="' . esc_url( wp_logout_url( self::signup_url() ) ) . '">Log out</a> and sign up as an affiliate, or contact us if you think this is a mistake.</div>' );
		}

		if ( $is_previewing ) {
			$user_id = (int) $preview['id'];
			$user    = get_userdata( $user_id );
			if ( ! $user ) {
				return array( false, '<div class="gas-notice">That affiliate account no longer exists.</div>' );
			}
		} else {
			$user_id = get_current_user_id();
			$user    = wp_get_current_user();
		}
		$status = get_user_meta( $user_id, 'gas_status', true ) ?: 'active';

		ob_start();

		if ( isset( $_GET['gas_notice'] ) ) {
			$notices = array(
				'password_updated'        => 'Password updated.',
				'payment_updated'         => 'Payment information saved.',
				'tax_info_updated'        => 'Tax information submitted — thank you.',
				'dashboard_theme_updated' => 'Dashboard color updated.',
				'referral_added'          => 'Referral added — thanks for the introduction!',
				'team_member_added'       => 'Team member added — they\'ll get an email shortly to set their password.',
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

		self::render_dashboard_subnav( $active_tag );

		if ( $is_previewing ) {
			echo '<div class="gas-notice" style="border-left:4px solid #d98500;padding:8px 12px;background:#fff8e5;">';
			echo 'Previewing <strong>' . esc_html( $user->display_name ) . '\'s</strong> dashboard (read-only). ';
			echo '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=gas_stop_admin_preview' ), 'gas_stop_admin_preview' ) ) . '">Stop previewing</a>';
			echo '</div>';
		}

		if ( 'suspended' === $status ) {
			echo '<div class="gas-notice gas-notice-error">Your affiliate account is currently suspended and your link is inactive. Contact us if you have questions.</div>';
		}

		return array( true, array( $user_id, $user, $is_previewing ) );
	}

	/**
	 * Closes the `<div class="gas-dashboard">` opened by dashboard_page_open()
	 * and appends the tap-to-reveal script for any popover icons on the
	 * page (harmless no-op if none rendered) — same script every
	 * dashboard-family page needs, kept in one place.
	 */
	private static function dashboard_page_close() {
		echo '</div>';
		?>
		<script>
			(function() {
				document.querySelectorAll('.gas-popover-icon').forEach(function(el) {
					el.addEventListener('click', function(e) {
						e.stopPropagation();
						var wasOpen = el.classList.contains('gas-open');
						document.querySelectorAll('.gas-popover-icon.gas-open').forEach(function(o) { o.classList.remove('gas-open'); });
						if (!wasOpen) { el.classList.add('gas-open'); }
					});
				});
				document.addEventListener('click', function() {
					document.querySelectorAll('.gas-popover-icon.gas-open').forEach(function(o) { o.classList.remove('gas-open'); });
				});
				// Share row: "Copy Link"/"Instagram" buttons write to the
				// clipboard client-side (there's no server-side share
				// action to submit) — Instagram also opens Instagram
				// afterward since it has no real share-by-URL intent, see
				// render_share_buttons()'s own comment for why.
				document.querySelectorAll('.gas-share-copy, .gas-share-instagram').forEach(function(btn) {
					btn.addEventListener('click', function() {
						var text = btn.getAttribute('data-copy-text') || '';
						var label = btn.querySelector('.gas-share-label');
						var original = label ? label.textContent : '';
						navigator.clipboard.writeText(text).then(function() {
							if (label) { label.textContent = 'Copied!'; setTimeout(function() { label.textContent = original; }, 2000); }
							if (btn.classList.contains('gas-share-instagram')) {
								window.open('https://www.instagram.com/', '_blank', 'noopener');
							}
						});
					});
				});
			})();
		</script>
		<?php
	}

	/**
	 * The nav bar shown at the top of every dashboard-family page, so an
	 * affiliate can move between Overview/Links & Earnings/Team/Account —
	 * added 2026-09-10 as part of splitting one long page into 4 (Cary's
	 * ask, after real user-testing feedback that the single-page version
	 * buried money and team info under settings-type content).
	 */
	private static function render_dashboard_subnav( $active_tag ) {
		echo '<nav class="gas-dashboard-nav" aria-label="Dashboard sections">';
		foreach ( self::DASHBOARD_FAMILY as $tag => $info ) {
			$url   = call_user_func( array( __CLASS__, $info['url_fn'] ) );
			$class = 'gas-dashboard-nav-link' . ( $tag === $active_tag ? ' gas-dashboard-nav-active' : '' );
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( $url ) . '"' . ( $tag === $active_tag ? ' aria-current="page"' : '' ) . '>' . esc_html( $info['label'] ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * How many people this affiliate has personally recruited (direct),
	 * and how many their recruits have in turn recruited (indirect) —
	 * shared by the Overview hero stat and the Team page's own fuller
	 * breakdown so the two numbers can never drift apart from being
	 * computed two different ways.
	 */
	private static function get_downline_counts( $user_id ) {
		global $wpdb;
		$codes_table = GAS_DB::table( 'codes' );
		$my_code_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$codes_table} WHERE wp_user_id = %d", $user_id ) );
		if ( ! $my_code_ids ) {
			return array( 0, 0 );
		}
		$placeholders = implode( ',', array_fill( 0, count( $my_code_ids ), '%d' ) );
		$direct_ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$codes_table} WHERE sponsor_code_id IN ({$placeholders})", $my_code_ids ) );
		if ( ! $direct_ids ) {
			return array( 0, 0 );
		}
		$placeholders2 = implode( ',', array_fill( 0, count( $direct_ids ), '%d' ) );
		$indirect_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$codes_table} WHERE sponsor_code_id IN ({$placeholders2})", $direct_ids ) );
		return array( count( $direct_ids ), $indirect_count );
	}

	/**
	 * OVERVIEW — the "at a glance" landing page (same shortcode/page this
	 * plugin has always used, dashboard_url(), left unchanged on purpose:
	 * it's baked into login redirects, signup-confirmation emails, and
	 * "go to your dashboard" copy throughout the plugin already, so
	 * repointing it would mean hunting down every one of those). Before
	 * 2026-09-10 this page WAS the entire dashboard, every section
	 * stacked in one long scroll with no hierarchy — money buried inside
	 * a "Your links" panel, "Change password" given the same visual
	 * weight as your unpaid balance. This is now just the hero: the
	 * numbers that matter, and your link if you only have the one —
	 * everything else lives on its own page now, reachable from the
	 * subnav above.
	 */
	public static function render_dashboard() {
		list( $ok, $data ) = self::dashboard_page_open( 'gas_affiliate_dashboard' );
		if ( ! $ok ) {
			return $data;
		}
		list( $user_id, $user, $is_previewing ) = $data;

		global $wpdb;
		$my_code  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . GAS_DB::table( 'codes' ) . " WHERE wp_user_id = %d ORDER BY created_at ASC LIMIT 1", $user_id ) );
		$campaigns = $my_code ? GAS_Campaigns::get_active_for_approved_partners() : array();
		$totals   = $my_code ? GAS_Payouts::totals_for_affiliate( $user_id ) : array( 'unpaid' => 0, 'paid' => 0 );
		list( $direct_count, $indirect_count ) = self::get_downline_counts( $user_id );

		echo '<div class="gas-panel gas-hero">';
		echo '<h2>Your business at a glance</h2>';
		echo '<div class="gas-stat-row">';
		echo '<div class="gas-stat"><span class="gas-stat-num">$' . esc_html( number_format( $totals['unpaid'], 2 ) ) . '</span><span class="gas-stat-label">Unpaid balance</span></div>';
		echo '<div class="gas-stat"><span class="gas-stat-num">$' . esc_html( number_format( $totals['paid'], 2 ) ) . '</span><span class="gas-stat-label">Paid to date</span></div>';
		echo '<div class="gas-stat"><span class="gas-stat-num">' . esc_html( count( $campaigns ) ) . '</span><span class="gas-stat-label">Active link' . ( 1 === count( $campaigns ) ? '' : 's' ) . '</span></div>';
		echo '<div class="gas-stat"><span class="gas-stat-num">' . esc_html( $direct_count + $indirect_count ) . '</span><span class="gas-stat-label">People on your team</span></div>';
		echo '</div>';
		echo '<p><a href="' . esc_url( self::links_url() ) . '">See the full earnings breakdown by tier &rarr;</a> &middot; <a href="' . esc_url( self::team_url() ) . '">See your team &rarr;</a></p>';
		echo '</div>';

		if ( ! $my_code ) {
			// no-op: nothing to show yet, avoid an empty/confusing panel
		} elseif ( 1 === count( $campaigns ) ) {
			// The common case (one partner) — show the real share widget
			// right here so the single most useful action (share your
			// link) doesn't need a second page visit. 2+ partners means
			// "which one" is a real question, so that case just points to
			// Links & Earnings instead of guessing which one to feature.
			$c    = $campaigns[0];
			$link = GAS_Campaigns::build_link( $c, $my_code->code );
			echo '<div class="gas-panel">';
			echo '<h2>Your link</h2>';
			echo '<p><strong>' . esc_html( $c->partner_alias ) . '</strong></p>';
			echo '<p>Your link: <code>' . esc_html( $link ) . '</code></p>';
			echo self::render_share_buttons( $link, 'Check this out:' );
			echo '<p class="gas-fineprint"><a href="' . esc_url( self::links_url() ) . '">Full details, service info, and marketing materials &rarr;</a></p>';
			echo '</div>';
		} elseif ( count( $campaigns ) > 1 ) {
			echo '<div class="gas-panel">';
			echo '<h2>Your links</h2>';
			echo '<p>You have ' . esc_html( count( $campaigns ) ) . ' active links. <a href="' . esc_url( self::links_url() ) . '">See them all, with sharing options &rarr;</a></p>';
			echo '</div>';
		} else {
			echo '<div class="gas-panel"><h2>Your links</h2><p>No referral links yet' . ( $my_code ? ' — check back once a partner campaign is active' : '' ) . '.</p></div>';
		}

		self::dashboard_page_close();
		return ob_get_clean();
	}

	/**
	 * MY LINKS & EARNINGS — every promotable link (alias, coverage,
	 * services, share buttons, click count) plus the earnings-by-tier
	 * breakdown, on one page since they're really one story ("here's what
	 * you're promoting and what it's earned you"), not two disconnected
	 * ones like the pre-2026-09-10 layout had them. "Add a referral"
	 * lives here too (moved from Team the same day, on Cary's direct
	 * correction) — referring a CUSTOMER is a links/earnings action;
	 * recruiting a new AFFILIATE (Team's "Add a team member") is a
	 * different thing entirely, even though both used to look similar.
	 */
	public static function render_links_page() {
		list( $ok, $data ) = self::dashboard_page_open( 'gas_affiliate_links' );
		if ( ! $ok ) {
			return $data;
		}
		list( $user_id, $user, $is_previewing ) = $data;

		self::render_stats_section( $user_id );
		self::render_marketing_assets_section( $user_id );
		self::render_add_referral_section( $user_id, $is_previewing );

		self::dashboard_page_close();
		return ob_get_clean();
	}

	/**
	 * MY TEAM — the downline tree plus both ways to grow it: share a
	 * join-link for someone to self-signup, or add them directly
	 * yourself via render_add_team_member_section() (2026-09-10 — real
	 * account creation, they get the normal "set your password" email,
	 * not just a link handed to them).
	 */
	public static function render_team_page() {
		list( $ok, $data ) = self::dashboard_page_open( 'gas_affiliate_team' );
		if ( ! $ok ) {
			return $data;
		}
		list( $user_id, $user, $is_previewing ) = $data;

		self::render_downline_section( $user_id );
		self::render_add_team_member_section( $user_id, $is_previewing );

		self::dashboard_page_close();
		return ob_get_clean();
	}

	/**
	 * ACCOUNT — everything administrative (dashboard color, password,
	 * payment, tax) lives here now, separated from performance content so
	 * neither competes with the other for attention.
	 */
	public static function render_account_page() {
		list( $ok, $data ) = self::dashboard_page_open( 'gas_affiliate_account' );
		if ( ! $ok ) {
			return $data;
		}
		list( $user_id, $user, $is_previewing ) = $data;

		self::render_theme_preference_section( $user_id, $is_previewing );
		self::render_password_section( $is_previewing );
		self::render_payment_section( $user_id, $is_previewing );
		self::render_tax_section( $user_id, $is_previewing );

		self::dashboard_page_close();
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

	/**
	 * Rewritten 2026-09-08 for the campaigns architecture: an affiliate
	 * has exactly ONE code (fetched once below), and "their links" is now
	 * that code combined with every active campaign for an approved
	 * partner — see GAS_Campaigns::build_link()/get_active_for_approved_partners().
	 * There's no more per-partner matching step to wait on, so the old
	 * "here's a partner you don't have a link for yet" self-serve section
	 * (render_missing_partner_links()/handle_get_partner_link()) no longer
	 * has anything to do and was removed along with it.
	 */
	private static function render_stats_section( $user_id ) {
		global $wpdb;
		$codes_table  = GAS_DB::table( 'codes' );
		$clicks_table = GAS_DB::table( 'clicks' );

		$my_code = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$codes_table} WHERE wp_user_id = %d ORDER BY created_at ASC LIMIT 1", $user_id ) );
		$campaigns = $my_code ? GAS_Campaigns::get_active_for_approved_partners() : array();

		echo '<div class="gas-panel">';
		echo '<h2>Your links</h2>';
		if ( ! $my_code || ! $campaigns ) {
			echo '<p>No referral links yet' . ( $my_code ? ' — check back once a partner campaign is active' : '' ) . '.</p>';
		} else {
			$totals = GAS_Payouts::totals_for_affiliate( $user_id );

			foreach ( $campaigns as $c ) {
				$link        = GAS_Campaigns::build_link( $c, $my_code->code );
				$click_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$clicks_table} WHERE campaign_id = %d AND code_id = %d", $c->id, $my_code->id ) );

				echo '<div class="gas-code-card">';
				// Alias only, never $c->partner_name — an affiliate is
				// deliberately never shown which real fulfillment partner
				// sits behind their link (they could go around us and
				// deal with the partner directly). Same reason the blurb
				// popover and "See full spotlight" link that used to be
				// here are gone: both are real-name-identifying admin
				// copy that can't be made alias-safe automatically. See
				// the note on partners.partner_alias in class-gas-db.php.
				echo '<p><strong>' . esc_html( $c->partner_alias ) . '</strong></p>';

				if ( $c->partner_state ) {
					$states = implode( ', ', array_map( 'trim', explode( ',', $c->partner_state ) ) );
					echo '<p class="gas-fineprint"><strong>Coverage area:</strong> ' . esc_html( $states ) . '</p>';
				}

				$icons = self::render_capability_icons( $c->partner_capability_tags, $c->partner_requires_appointment );
				if ( $icons ) {
					// Labeled explicitly (2026-09-10, Cary's ask) rather
					// than left as bare icons a visitor has to hover/tap to
					// understand — "label liberally" applied here.
					echo '<p class="gas-capability-icons"><strong>Services:</strong> ' . $icons . '</p>';
				}

				echo '<p><strong>Your link:</strong> <code>' . esc_html( $link ) . '</code></p>';
				echo self::render_share_buttons( $link, 'Check this out:' );

				$variants = GAS_Campaigns::get_variants_for( $c->id );
				if ( $variants ) {
					echo '<p class="gas-fineprint">Or a specific version:</p><ul class="gas-fineprint">';
					foreach ( $variants as $v ) {
						echo '<li>' . esc_html( $v->variant_name ) . ': <code>' . esc_html( GAS_Campaigns::build_link( $c, $my_code->code, $v->id ) ) . '</code></li>';
					}
					echo '</ul>';
				}

				echo '<div class="gas-stat-row">';
				echo '<div class="gas-stat"><span class="gas-stat-num">' . esc_html( $click_count ) . '</span><span class="gas-stat-label">Clicks on this link</span></div>';
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
		echo '</div>';

		if ( $my_code ) {
			self::render_pending_and_finalized_section( $user_id );
		}
	}

	/**
	 * Downloadable marketing collateral (2026-09-08) — global assets plus
	 * anything scoped to a partner/campaign this affiliate currently has
	 * an active link for. Renders nothing at all if there's nothing to
	 * show, rather than an empty "Marketing Materials" heading.
	 */
	private static function render_marketing_assets_section( $user_id ) {
		$campaigns = GAS_Campaigns::get_active_for_approved_partners();
		if ( ! $campaigns ) {
			return;
		}
		$partner_ids  = array_values( array_unique( wp_list_pluck( $campaigns, 'partner_id' ) ) );
		$campaign_ids = wp_list_pluck( $campaigns, 'id' );

		$assets = GAS_Marketing_Assets::get_for_affiliate( $partner_ids, $campaign_ids );
		if ( ! $assets ) {
			return;
		}

		echo '<div class="gas-panel">';
		echo '<h2>Marketing materials</h2>';
		echo '<p class="gas-fineprint">Ready-to-use images for promoting your link.</p>';
		echo '<div class="gas-stat-row">';
		foreach ( $assets as $a ) {
			$url   = wp_get_attachment_url( $a->attachment_id );
			$thumb = wp_get_attachment_image( $a->attachment_id, 'medium' );
			if ( ! $url || ! $thumb ) {
				continue; // attachment was deleted from the media library
			}
			echo '<div style="text-align:center;">' . $thumb . '<br><a href="' . esc_url( $url ) . '" download class="gas-fineprint">' . esc_html( $a->title ) . ' &darr;</a></div>';
		}
		echo '</div>';
		echo '</div>';
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

		echo '<div class="gas-panel">';
		echo '<h2>Earnings by tier</h2>';
		echo '<p class="gas-fineprint">This month is still open, so it shows an estimated range rather than an exact amount. Once the month closes, it moves into your finalized total below with the exact dollar amount you earned.</p>';

		echo '<div class="gas-table-wrap"><table class="gas-table"><thead><tr><th>Tier</th><th>This month (pending)</th><th>Finalized (prior months)</th></tr></thead><tbody>';
		foreach ( array( 1, 2, 3 ) as $tier ) {
			$count = $pending[ $tier ];
			$range = GAS_Payouts::tier_dollar_range( $tier );

			echo '<tr><td>Tier ' . esc_html( $tier ) . '</td><td>';
			if ( 0 === $count ) {
				echo '&mdash;';
			} elseif ( $range ) {
				$min_fmt = number_format( $range['min'] * $count, 2 );
				$max_fmt = number_format( $range['max'] * $count, 2 );
				echo esc_html( $count ) . ' sale' . ( 1 === $count ? '' : 's' ) . ' &mdash; est. $' . esc_html( $min_fmt );
				// Same fix as the signup page's payout range: compare the
				// FORMATTED values, not the raw floats, and only show a
				// second number if it's actually different — with one
				// partner (today's common case) min and max are identical,
				// and "$490.00–$490.00" reads like a bug, not a real range.
				if ( $min_fmt !== $max_fmt ) {
					echo '&ndash;$' . esc_html( $max_fmt );
				}
			} else {
				echo esc_html( $count ) . ' sale' . ( 1 === $count ? '' : 's' );
			}
			echo '</td><td>$' . esc_html( number_format( $finalized[ $tier ], 2 ) ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
		echo '</div>';
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

		$my_code    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$codes_table} WHERE wp_user_id = %d ORDER BY created_at ASC LIMIT 1", $user_id ) );
		$my_code_ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$codes_table} WHERE wp_user_id = %d", $user_id ) );

		echo '<div class="gas-panel">';
		echo '<h2>Your team</h2>';
		echo '<p class="gas-fineprint">People you\'ve personally recruited, and the people they\'ve recruited in turn — your own tree only.</p>';

		if ( ! $my_code_ids ) {
			echo '</div>';
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

		echo '<div class="gas-stat-row">';
		echo '<div class="gas-stat"><span class="gas-stat-num">' . esc_html( count( $direct ) ) . '</span><span class="gas-stat-label">Direct recruits</span></div>';
		echo '<div class="gas-stat"><span class="gas-stat-num">' . esc_html( count( $indirect ) ) . '</span><span class="gas-stat-label">Their recruits</span></div>';
		echo '</div>';

		if ( $direct ) {
			// Grouped as a real tree (2026-09-10 — was a flat table before,
			// with a text "Direct"/"Their recruit" column and no way to
			// tell WHICH direct recruit an indirect one actually belongs
			// under). Group indirect recruits by their sponsor_code_id and
			// interleave them right after their own direct-recruit parent,
			// visually indented, instead of two disconnected blocks.
			$indirect_by_sponsor = array();
			foreach ( $indirect as $i ) {
				$indirect_by_sponsor[ $i->sponsor_code_id ][] = $i;
			}

			echo '<div class="gas-table-wrap"><table class="gas-table"><thead><tr><th>Name</th><th>Contact</th><th>Level</th></tr></thead><tbody>';
			foreach ( $direct as $d ) {
				self::render_downline_row( $d, 'Direct recruit' );
				foreach ( $indirect_by_sponsor[ $d->id ] ?? array() as $child ) {
					self::render_downline_row( $child, 'Recruited by ' . $d->sub_affiliate_name, true );
				}
			}
			echo '</tbody></table></div>';
		}

		if ( $my_code ) {
			// Moved here from the Links & Earnings page (2026-09-10) —
			// recruiting another affiliate belongs with the rest of team-
			// building, not tucked into a page about promotable links.
			$recruit_link = home_url( '/join/' . rawurlencode( $my_code->code ) . '/' );
			echo '<p><strong>Invite someone to become an affiliate</strong> too, and earn a bonus on their sales: <code>' . esc_html( $recruit_link ) . '</code></p>';
			echo self::render_share_buttons( $recruit_link, 'Join me as an affiliate:' );
		}

		echo '</div>';
	}

	/**
	 * Lets an affiliate add a referral for a friend directly from their own
	 * dashboard, instead of having to leave it and re-enter their own
	 * name/email on the public /refer-a-friend page (the only way to do
	 * this before). Reuses create_referral_lead() — same lead row, same
	 * emails — just skips the "find me by email" step since we already
	 * know who's logged in (or, under admin preview, who's being
	 * previewed; unlike the password/theme sections this one stays live
	 * during preview so an admin can add a referral on an affiliate's
	 * behalf, e.g. one called in over the phone). Rolled up behind a
	 * <details>/<summary> disclosure (2026-09-10, Cary's ask: "rolled
	 * up... a plus, click here to enter details") rather than a custom JS
	 * toggle — native, works with no script, keyboard/screen-reader
	 * accessible for free. Same pattern used for
	 * render_add_team_member_section() right below, so both "grow my
	 * business" actions on this page look and behave identically.
	 * $open (2026-09-11) lets a caller start the <details> expanded —
	 * used by render_signup_or_refer() on the dedicated public
	 * Refer-a-Friend page, where this IS the whole page for a logged-in
	 * affiliate, not one of several rolled-up dashboard actions; the
	 * dashboard call site stays collapsed (2-arg call, default false).
	 */
	private static function render_add_referral_section( $user_id, $is_previewing = false, $open = false ) {
		global $wpdb;
		$my_code = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . GAS_DB::table( 'codes' ) . ' WHERE wp_user_id = %d ORDER BY created_at ASC LIMIT 1', $user_id ) );
		if ( ! $my_code ) {
			// A real, if rare, edge case (an account created/attached
			// outside normal self-signup with no code row yet — see
			// SWAP-with-HOMES.md's mySolarTest note) — silently rendering
			// nothing here would leave a logged-in affiliate on the
			// Refer-a-Friend page staring at just the "Referring as X"
			// line with no form and no explanation.
			if ( $open ) {
				echo '<div class="gas-panel"><p class="gas-notice gas-notice-error">We couldn\'t find a referral code on your account yet — contact us and we\'ll get that sorted.</p></div>';
			}
			return;
		}
		// Alias, never real name — same rule as everywhere else an
		// affiliate can see a partner (see the note on partners.partner_alias
		// in class-gas-db.php). Only approved partners are offered, same
		// set an affiliate could actually get matched with anyway.
		$partners = $wpdb->get_results( "SELECT id, partner_alias FROM " . GAS_DB::table( 'partners' ) . " WHERE outreach_status = 'approved' ORDER BY partner_alias ASC" );

		echo '<div class="gas-panel"><details class="gas-disclosure"' . ( $open ? ' open' : '' ) . '><summary><span class="gas-disclosure-plus" aria-hidden="true">+</span> Add a referral <span class="gas-fineprint">— click to enter their details</span></summary>';
		echo '<div class="gas-disclosure-body">';
		if ( $is_previewing ) {
			echo '<p class="gas-fineprint">You\'re adding this to <strong>' . esc_html( get_userdata( $user_id )->display_name ) . '\'s</strong> account, not your own.</p>';
		} else {
			echo '<p class="gas-fineprint">Know someone who\'d be a good fit? Add them here and we\'ll take it from there.</p>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gas-form">
			<?php wp_nonce_field( 'gas_dashboard_add_referral' ); ?>
			<input type="hidden" name="action" value="gas_dashboard_add_referral">
			<p>
				<label for="gas_ref_name">Their name</label><br>
				<input type="text" id="gas_ref_name" name="friend_name" required class="gas-input">
			</p>
			<p>
				<label for="gas_ref_email">Their email</label><br>
				<input type="email" id="gas_ref_email" name="friend_email" class="gas-input">
			</p>
			<p>
				<label for="gas_ref_phone">Their phone</label><br>
				<input type="tel" id="gas_ref_phone" name="friend_phone" class="gas-input">
			</p>
			<p class="gas-fineprint">At least one of email or phone is required.</p>
			<p>
				<label for="gas_ref_address">Their address (optional)</label><br>
				<input type="text" id="gas_ref_address" name="friend_address" class="gas-input">
			</p>
			<p>
				<label for="gas_ref_state">Their state</label><br>
				<select id="gas_ref_state" name="friend_state" class="gas-input"><?php echo GAS_DB::state_dropdown_options(); ?></select>
				<span class="gas-fineprint">Lets us match them to a partner that actually covers their area.</span>
			</p>
			<p>
				<label for="gas_ref_partner">Which <?php echo esc_html( GAS_Settings::get( 'partner_label' ) ); ?>? (optional)</label><br>
				<select id="gas_ref_partner" name="partner_id" class="gas-input">
					<option value="0">— Let us match them by state instead —</option>
					<?php foreach ( $partners as $p ) : ?>
						<option value="<?php echo esc_attr( $p->id ); ?>"><?php echo esc_html( $p->partner_alias ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="gas-fineprint">Pick one only if you already know who's the right fit — otherwise leave this on "let us match them" and their state above will decide.</span>
			</p>
			<p>
				<button type="submit" class="gas-button">Add referral</button>
			</p>
		</form>
		<?php
		echo '</div></details></div>';
	}

	public static function handle_dashboard_add_referral() {
		if ( ! is_user_logged_in() ) {
			wp_die( 'Please log in first.' );
		}
		check_admin_referer( 'gas_dashboard_add_referral' );

		$preview       = GAS_Roles::get_admin_preview();
		$is_previewing = $preview && 'agent' === $preview['type'];

		if ( ! $is_previewing && ! GAS_Roles::is_affiliate() ) {
			wp_die( 'This is for affiliates only.' );
		}
		$user_id = $is_previewing ? (int) $preview['id'] : get_current_user_id();

		// Moved to Links & Earnings (2026-09-10) — referring a customer is
		// part of "my links" now, not "my team" (team is for recruiting
		// other affiliates — see render_add_team_member_section() below).
		$redirect_back = add_query_arg( 'gas_notice', 'referral_added', self::links_url() );
		$fail          = function( $msg ) use ( $redirect_back ) {
			wp_safe_redirect( add_query_arg( 'gas_error', rawurlencode( $msg ), remove_query_arg( 'gas_notice', $redirect_back ) ) );
			exit;
		};

		global $wpdb;
		$code = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . GAS_DB::table( 'codes' ) . ' WHERE wp_user_id = %d ORDER BY created_at ASC LIMIT 1', $user_id ) );
		if ( ! $code ) {
			$fail( 'No referral code found on this account yet.' );
		}

		$friend_name    = isset( $_POST['friend_name'] ) ? sanitize_text_field( wp_unslash( $_POST['friend_name'] ) ) : '';
		$friend_email   = isset( $_POST['friend_email'] ) ? sanitize_email( wp_unslash( $_POST['friend_email'] ) ) : '';
		$friend_phone   = isset( $_POST['friend_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['friend_phone'] ) ) : '';
		$friend_address = isset( $_POST['friend_address'] ) ? sanitize_text_field( wp_unslash( $_POST['friend_address'] ) ) : '';
		$friend_state_raw = isset( $_POST['friend_state'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_POST['friend_state'] ) ) ) : '';
		$friend_state     = array_key_exists( $friend_state_raw, GAS_DB::us_states() ) ? $friend_state_raw : '';

		// Explicit partner choice (2026-09-10, Cary's ask) — validated
		// against real approved partners, not trusted as a raw ID, so a
		// tampered request can't assign a lead to an unapproved/made-up
		// partner row.
		$partner_id = isset( $_POST['partner_id'] ) ? absint( $_POST['partner_id'] ) : 0;
		if ( $partner_id ) {
			$is_approved = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM " . GAS_DB::table( 'partners' ) . " WHERE id = %d AND outreach_status = 'approved'", $partner_id ) );
			if ( ! $is_approved ) {
				$partner_id = 0;
			}
		}

		if ( '' === $friend_name || ( '' === $friend_email && '' === $friend_phone ) ) {
			$fail( 'Please enter a name and at least an email or phone number.' );
		}

		self::create_referral_lead( $code, $friend_name, $friend_email, $friend_phone, $friend_address, $friend_state, $partner_id );

		wp_safe_redirect( $redirect_back );
		exit;
	}

	/**
	 * Same rolled-up <details> pattern as render_add_referral_section()
	 * above — but this one creates a real affiliate account directly
	 * under the current affiliate as sponsor, rather than a lead. Added
	 * 2026-09-10 per Cary: "it's a normal flow to add a team member,
	 * they get an email" — mirrors handle_signup()'s own account-creation
	 * logic (same role, same get_or_create_code_for_user()/sponsor
	 * wiring), except the new member can't set their own password in the
	 * moment (the affiliate adding them doesn't know one to set), so this
	 * uses the same wp_generate_password()+retrieve_password() pattern
	 * GAS_Roles::provision_partner_account() already uses for partner
	 * accounts — WordPress's own standard "set your new password" email.
	 */
	private static function render_add_team_member_section( $user_id, $is_previewing = false ) {
		global $wpdb;
		$my_code = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . GAS_DB::table( 'codes' ) . ' WHERE wp_user_id = %d ORDER BY created_at ASC LIMIT 1', $user_id ) );
		if ( ! $my_code ) {
			return;
		}

		echo '<div class="gas-panel"><details class="gas-disclosure"><summary><span class="gas-disclosure-plus" aria-hidden="true">+</span> Add a team member <span class="gas-fineprint">— click to enter their details</span></summary>';
		echo '<div class="gas-disclosure-body">';
		if ( $is_previewing ) {
			echo '<p class="gas-fineprint">You\'re adding this to <strong>' . esc_html( get_userdata( $user_id )->display_name ) . '\'s</strong> team, not your own.</p>';
		} else {
			echo '<p class="gas-fineprint">Met someone in person, or they\'d rather not sign themselves up? Add them directly — they\'ll get an email to set their own password, same as anyone who signs up themselves.</p>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gas-form">
			<?php wp_nonce_field( 'gas_dashboard_add_team_member' ); ?>
			<input type="hidden" name="action" value="gas_dashboard_add_team_member">
			<p>
				<label for="gas_tm_name">Their name</label><br>
				<input type="text" id="gas_tm_name" name="member_name" required class="gas-input">
			</p>
			<p>
				<label for="gas_tm_email">Their email</label><br>
				<input type="email" id="gas_tm_email" name="member_email" required class="gas-input">
			</p>
			<p>
				<label for="gas_tm_phone">Their phone (optional)</label><br>
				<input type="tel" id="gas_tm_phone" name="member_phone" class="gas-input">
			</p>
			<p>
				<button type="submit" class="gas-button">Add team member</button>
			</p>
		</form>
		<?php
		echo '</div></details></div>';
	}

	public static function handle_dashboard_add_team_member() {
		if ( ! is_user_logged_in() ) {
			wp_die( 'Please log in first.' );
		}
		check_admin_referer( 'gas_dashboard_add_team_member' );

		$preview       = GAS_Roles::get_admin_preview();
		$is_previewing = $preview && 'agent' === $preview['type'];

		if ( ! $is_previewing && ! GAS_Roles::is_affiliate() ) {
			wp_die( 'This is for affiliates only.' );
		}
		$sponsor_user_id = $is_previewing ? (int) $preview['id'] : get_current_user_id();

		$redirect_back = add_query_arg( 'gas_notice', 'team_member_added', self::team_url() );
		$fail          = function( $msg ) use ( $redirect_back ) {
			wp_safe_redirect( add_query_arg( 'gas_error', rawurlencode( $msg ), remove_query_arg( 'gas_notice', $redirect_back ) ) );
			exit;
		};

		global $wpdb;
		$sponsor_code = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . GAS_DB::table( 'codes' ) . ' WHERE wp_user_id = %d ORDER BY created_at ASC LIMIT 1', $sponsor_user_id ) );
		if ( ! $sponsor_code ) {
			$fail( 'No referral code found on this account yet.' );
		}

		$name  = isset( $_POST['member_name'] ) ? sanitize_text_field( wp_unslash( $_POST['member_name'] ) ) : '';
		$email = isset( $_POST['member_email'] ) ? sanitize_email( wp_unslash( $_POST['member_email'] ) ) : '';
		$phone = isset( $_POST['member_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['member_phone'] ) ) : '';

		if ( '' === $name || ! is_email( $email ) ) {
			$fail( 'Please enter their name and a valid email address.' );
		}
		if ( email_exists( $email ) ) {
			$fail( 'That email is already registered to an account — ask them to log in instead, or use a different email.' );
		}
		if ( GAS_Fraud::is_disposable_email( $email ) ) {
			$fail( 'Please use a permanent email address — temporary/disposable email services aren\'t accepted.' );
		}
		// This creates a real account and emails a stranger's inbox
		// unprompted — the exact same abuse shape handle_signup() already
		// guards against (mass account creation), just from a logged-in
		// affiliate instead of an anonymous visitor. Reuses the same
		// IP-keyed daily cap rather than leaving this endpoint as the one
		// unlimited way to spam arbitrary email addresses with "you're an
		// affiliate" + a WordPress password-reset link.
		$signup_ip = GAS_Fraud::get_client_ip();
		if ( GAS_Fraud::signup_rate_limited( $signup_ip ) ) {
			$fail( 'Too many accounts created from this connection today — please try again tomorrow, or contact us if you think this is a mistake.' );
		}

		$username = self::generate_unique_username( $email );
		$new_user_id = wp_insert_user( array(
			'user_login'   => $username,
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 24 ),
			'display_name' => $name,
			'first_name'   => $name,
			'role'         => GAS_Roles::ROLE,
		) );
		if ( is_wp_error( $new_user_id ) ) {
			$fail( 'Could not create account: ' . $new_user_id->get_error_message() );
		}
		GAS_Fraud::record_signup_attempt( $signup_ip );

		update_user_meta( $new_user_id, 'gas_status', 'active' );
		if ( '' !== $phone ) {
			update_user_meta( $new_user_id, 'gas_phone', $phone );
		}
		// Deliberately NOT setting gas_agreement_accepted_at here — this
		// affiliate is consenting to the program terms on their OWN
		// account's behalf, but can't accept an agreement for someone
		// else any more than they can give TCPA consent for someone
		// else's phone number (same principle as friend_state/consent in
		// create_referral_lead() above). They see and can accept it
		// themselves once they log in.

		$new_code = self::insert_code_row( $new_user_id, $name, 0, $sponsor_code->id, 'Added directly by their sponsor (' . $sponsor_code->sub_affiliate_name . '), not self-signup.' );

		GAS_Contacts::upsert( $email, 'affiliate', array(
			'name'          => $name,
			'phone'         => $phone,
			'source'        => 'added_by_sponsor',
			'related_table' => 'codes',
			'related_id'    => $new_code->id,
		) );

		// WordPress's own standard "set your new password" email — same
		// mechanism GAS_Roles::provision_partner_account() already uses,
		// since the sponsor adding this person has no password to hand
		// them.
		retrieve_password( $email );

		// A second, plain-language welcome separate from WP's own reset
		// email (which only talks about passwords) — explains WHY they're
		// getting this at all and what happens next.
		$site_name = GAS_Settings::get( 'site_name' );
		wp_mail(
			$email,
			"You're an affiliate with {$site_name}",
			"Hi {$name},\n\n{$sponsor_code->sub_affiliate_name} added you as an affiliate with {$site_name}. Check your email for a separate message from WordPress with a link to set your password — once that's done, log in here to see your dashboard and referral link:\n\n" . self::dashboard_url() . GAS_Settings::compliance_footer( $email )
		);

		wp_mail(
			get_option( 'admin_email' ),
			'New affiliate added by a sponsor: ' . $name,
			"{$sponsor_code->sub_affiliate_name} added a new team member directly from their dashboard.\n\nName: {$name}\nEmail: {$email}\nPhone: " . ( $phone ?: '(not provided)' ) . "\nReferral code: {$new_code->code}\nSponsor code: {$sponsor_code->code}"
		);

		wp_safe_redirect( $redirect_back );
		exit;
	}

	/**
	 * 5 one-tap share options for a link (2026-09-10, Cary's ask) —
	 * WhatsApp, Facebook, and SMS/Email are real "share via URL" intents
	 * (open with the message pre-filled); Copy Link and Instagram are
	 * handled client-side (see the script in dashboard_page_close()).
	 * Instagram deliberately does NOT get a real share-intent link —
	 * unlike the others, Instagram has no public "share this URL with a
	 * pre-filled message" endpoint at all (by design: they don't allow
	 * clickable links in ordinary posts/captions either, only in bio or a
	 * story link sticker) — so it copies the message+link to the
	 * clipboard and opens Instagram, rather than faking a button that
	 * would silently do nothing. Icons render in the SITE'S theme color
	 * (var(--gas-accent)), not each platform's own brand color, per
	 * Cary's direct request, so they read as "this site's share buttons,"
	 * not a row of competing platform logos.
	 */
	private static function render_share_buttons( $link, $message ) {
		$text_with_link = $message . ' ' . $link;
		$whatsapp = 'https://wa.me/?text=' . rawurlencode( $text_with_link );
		$facebook = 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $link );
		$sms      = 'sms:?body=' . rawurlencode( $text_with_link );
		$email    = 'mailto:?subject=' . rawurlencode( $message ) . '&body=' . rawurlencode( $text_with_link );

		$html  = '<div class="gas-share-row" role="group" aria-label="Share this link">';
		$html .= '<button type="button" class="gas-share-btn gas-share-copy" data-copy-text="' . esc_attr( $link ) . '"><span class="dashicons dashicons-admin-page" aria-hidden="true"></span><span class="gas-share-label">Copy Link</span></button>';
		$html .= '<a class="gas-share-btn" href="' . esc_url( $whatsapp ) . '" target="_blank" rel="noopener"><span class="dashicons dashicons-format-chat" aria-hidden="true"></span><span class="gas-share-label">WhatsApp</span></a>';
		$html .= '<a class="gas-share-btn" href="' . esc_url( $facebook ) . '" target="_blank" rel="noopener"><span class="dashicons dashicons-facebook-alt" aria-hidden="true"></span><span class="gas-share-label">Facebook</span></a>';
		$html .= '<a class="gas-share-btn" href="' . esc_url( $sms ) . '"><span class="dashicons dashicons-smartphone" aria-hidden="true"></span><span class="gas-share-label">Text Message</span></a>';
		$html .= '<a class="gas-share-btn" href="' . esc_url( $email ) . '"><span class="dashicons dashicons-email" aria-hidden="true"></span><span class="gas-share-label">Email</span></a>';
		$html .= '<button type="button" class="gas-share-btn gas-share-instagram" data-copy-text="' . esc_attr( $text_with_link ) . '"><span class="dashicons dashicons-camera" aria-hidden="true"></span><span class="gas-share-label">Instagram</span></button>';
		$html .= '</div>';
		return $html;
	}

	/**
	 * One tap/click-to-reveal icon with a hidden text popover — shared by
	 * the partner blurb icon and each capability tag icon so both use the
	 * same interaction (no separate persistent legend needed, per spec).
	 * Works via CSS :hover/:focus for mouse and keyboard, plus the small
	 * script in dashboard_page_close() for a plain tap on touch devices.
	 */
	private static function render_popover_icon( $dashicon, $aria_label, $popover_text, $extra_class = '' ) {
		return '<span class="gas-popover-icon dashicons ' . esc_attr( $dashicon ) . ( $extra_class ? ' ' . esc_attr( $extra_class ) : '' ) . '" tabindex="0" role="button" aria-label="' . esc_attr( $aria_label ) . '"><span class="gas-popover">' . esc_html( $popover_text ) . '</span></span>';
	}

	/**
	 * Renders one small icon per ticked capability tag on this partner
	 * (fixed, curated list — see GAS_DB::capability_tags()), plus an
	 * "Appointment required" icon derived from the existing
	 * `requires_appointment` field rather than duplicating it as a
	 * manually-ticked tag. Unknown/stale tag slugs (e.g. left over after
	 * the approved list changes) are silently skipped rather than shown
	 * as a broken icon.
	 */
	private static function render_capability_icons( $capability_tags_csv, $requires_appointment ) {
		$all_tags = GAS_DB::capability_tags();
		$selected = $capability_tags_csv ? array_map( 'trim', explode( ',', $capability_tags_csv ) ) : array();

		$html = '';
		foreach ( $selected as $slug ) {
			if ( ! isset( $all_tags[ $slug ] ) ) {
				continue;
			}
			$html .= self::render_popover_icon( $all_tags[ $slug ]['icon'], $all_tags[ $slug ]['label'], $all_tags[ $slug ]['label'], 'gas-capability-icon' );
		}
		if ( $requires_appointment ) {
			$html .= self::render_popover_icon( 'dashicons-calendar-alt', 'Appointment required', 'Appointment required', 'gas-capability-icon' );
		}
		return $html;
	}

	private static function render_downline_row( $code, $level, $indented = false ) {
		$user  = $code->wp_user_id ? get_userdata( $code->wp_user_id ) : null;
		$phone = $code->wp_user_id ? get_user_meta( $code->wp_user_id, 'gas_phone', true ) : '';
		echo '<tr' . ( $indented ? ' class="gas-team-indent"' : '' ) . '>';
		echo '<td>' . ( $indented ? '&#8627; ' : '' ) . esc_html( $code->sub_affiliate_name ) . '</td>';
		echo '<td>' . ( $user ? esc_html( $user->user_email ) : '' ) . ( $phone ? '<br>' . esc_html( $phone ) : '' ) . '</td>';
		echo '<td>' . esc_html( $level ) . '</td>';
		echo '</tr>';
	}

	/**
	 * Lets an affiliate pick their own dashboard color, independent of
	 * this site's configured theme (GAS_Settings::get('theme')) — added
	 * 2026-09-10 per Cary's direct request. Purely cosmetic/personal: it
	 * only changes how THIS affiliate's own dashboard renders for them
	 * (see the enqueue_assets() override), never the public-facing site
	 * theme every other visitor/page still uses. Stored as the
	 * `gas_dashboard_theme` user meta key; empty means "use the site
	 * theme," not "green" specifically, so it stays correct automatically
	 * if the site's own theme is ever changed later.
	 */
	private static function render_theme_preference_section( $user_id, $is_previewing = false ) {
		echo '<div class="gas-panel">';
		echo '<h2>Dashboard color</h2>';
		if ( $is_previewing ) {
			echo '<p class="gas-fineprint">Disabled while previewing &mdash; this affiliate\'s own choice is already applied above, changing it here would affect their account, not yours.</p>';
			echo '</div>';
			return;
		}
		$current = get_user_meta( $user_id, 'gas_dashboard_theme', true ) ?: GAS_Settings::get( 'theme' );
		echo '<p class="gas-fineprint">Only changes how your own dashboard looks to you &mdash; the rest of the site stays as-is.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'gas_save_dashboard_theme' );
		echo '<input type="hidden" name="action" value="gas_save_dashboard_theme">';
		echo '<div style="display:flex;gap:1em;flex-wrap:wrap;margin:.6em 0 1em;">';
		foreach ( GAS_Settings::THEMES as $key => $palette ) {
			$checked = checked( $current, $key, false );
			echo '<label style="display:flex;align-items:center;gap:.5em;border:1.5px solid ' . ( $current === $key ? esc_attr( $palette['accent'] ) : '#dcdcde' ) . ';border-radius:8px;padding:.6em 1em;cursor:pointer;">';
			echo '<input type="radio" name="theme" value="' . esc_attr( $key ) . '"' . $checked . '> ';
			echo '<span style="display:inline-block;width:16px;height:16px;border-radius:50%;background:' . esc_attr( $palette['accent'] ) . ';border:1px solid rgba(0,0,0,.15);"></span> ';
			echo esc_html( $palette['label'] );
			echo '</label>';
		}
		echo '</div>';
		echo '<p><button type="submit" class="gas-button">Save color</button></p>';
		echo '</form>';
		echo '</div>';
	}

	public static function handle_save_dashboard_theme() {
		if ( ! is_user_logged_in() ) {
			wp_die( 'Please log in first.' );
		}
		check_admin_referer( 'gas_save_dashboard_theme' );

		$theme = isset( $_POST['theme'] ) ? sanitize_text_field( wp_unslash( $_POST['theme'] ) ) : '';
		if ( array_key_exists( $theme, GAS_Settings::THEMES ) ) {
			update_user_meta( get_current_user_id(), 'gas_dashboard_theme', $theme );
		}

		wp_safe_redirect( add_query_arg( 'gas_notice', 'dashboard_theme_updated', self::account_url() ) );
		exit;
	}

	private static function render_password_section( $is_previewing = false ) {
		echo '<div class="gas-panel">';
		echo '<h2>Change password</h2>';
		if ( $is_previewing ) {
			echo '<p class="gas-fineprint">Disabled while previewing &mdash; this would change your own admin password, not this affiliate\'s.</p>';
			echo '</div>';
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
		echo '</div>';
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
			wp_safe_redirect( add_query_arg( 'gas_error', rawurlencode( $msg ), self::account_url() ) );
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

		wp_safe_redirect( add_query_arg( 'gas_notice', 'password_updated', self::account_url() ) );
		exit;
	}

	/* ---------------------------------------------------------------- *
	 * PAYMENT / PAYOUT DETAILS
	 * ---------------------------------------------------------------- */

	private static function render_payment_section( $user_id, $is_previewing = false ) {
		echo '<div class="gas-panel">';
		echo '<h2>Payment information</h2>';
		if ( $is_previewing ) {
			echo '<p class="gas-fineprint">Hidden while previewing &mdash; payout details are only ever visible to the affiliate themselves, never to an admin, even in preview mode.</p>';
			echo '</div>';
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
		echo '</div>';
	}

	public static function handle_save_payment_info() {
		if ( ! is_user_logged_in() ) {
			wp_die( 'Please log in first.' );
		}
		check_admin_referer( 'gas_save_payment_info' );

		$user_id = get_current_user_id();
		GAS_Payouts::save_details( $user_id, wp_unslash( $_POST ) );

		wp_safe_redirect( add_query_arg( 'gas_notice', 'payment_updated', self::account_url() ) );
		exit;
	}

	/* ---------------------------------------------------------------- *
	 * TAX INFO (2026-09-08) — required on file before any payout goes
	 * out; see GAS_Payouts::affiliates_with_unpaid_balance() for the
	 * enforcement side. Same "affiliate's own dashboard writes it, admin
	 * never can" pattern as payment info above.
	 * ---------------------------------------------------------------- */

	private static function render_tax_section( $user_id, $is_previewing = false ) {
		echo '<div class="gas-panel">';
		echo '<h2>Tax information</h2>';
		if ( $is_previewing ) {
			echo '<p class="gas-fineprint">Hidden while previewing &mdash; same as payment info, this is only ever visible to the affiliate themselves.</p>';
			echo '</div>';
			return;
		}
		$t = GAS_Payouts::get_tax_info( $user_id );
		?>
		<p class="gas-fineprint">Required on file before we can send you any payout — a one-time form, standard for anyone earning referral income in the US. This is only ever visible to you and used for tax reporting; the admin only sees whether it's on file, never your tax ID.</p>
		<?php if ( $t['submitted_at'] ) : ?>
			<p class="gas-notice gas-notice-success">On file: <?php echo esc_html( 'w9' === $t['form_type'] ? 'Form W-9 (US person)' : 'Form W-8BEN (non-US person)' ); ?>, submitted <?php echo esc_html( $t['submitted_at'] ); ?>. Submitting the form again below replaces this.</p>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gas-form" id="gas-tax-form">
			<?php wp_nonce_field( 'gas_save_tax_info' ); ?>
			<input type="hidden" name="action" value="gas_save_tax_info">
			<p>
				<label for="gas_tax_form_type">Are you a US person or business?</label><br>
				<select id="gas_tax_form_type" name="tax_form_type" class="gas-input">
					<option value="w9" <?php selected( $t['form_type'], 'w9' ); ?>>Yes — Form W-9</option>
					<option value="w8ben" <?php selected( $t['form_type'], 'w8ben' ); ?>>No — Form W-8BEN</option>
				</select>
			</p>
			<p>
				<label for="gas_tax_legal_name">Full legal name (as it appears on your tax return)</label><br>
				<input type="text" id="gas_tax_legal_name" name="tax_legal_name" required class="gas-input" value="<?php echo esc_attr( $t['legal_name'] ); ?>">
			</p>
			<p>
				<label for="gas_tax_id" id="gas_tax_id_label">SSN or EIN</label><br>
				<input type="text" id="gas_tax_id" name="tax_id" class="gas-input" value="<?php echo esc_attr( $t['tax_id'] ); ?>">
				<span class="gas-fineprint" id="gas_tax_id_hint">Required for a W-9.</span>
			</p>
			<p>
				<label for="gas_tax_country">Country of tax residence</label><br>
				<input type="text" id="gas_tax_country" name="tax_country" required class="gas-input" value="<?php echo esc_attr( $t['country'] ); ?>" placeholder="e.g. United States">
			</p>
			<p><button type="submit" class="gas-button">Submit tax information</button></p>
		</form>
		<script>
			(function() {
				var typeSel = document.getElementById('gas_tax_form_type');
				var idLabel = document.getElementById('gas_tax_id_label');
				var idHint  = document.getElementById('gas_tax_id_hint');
				function sync() {
					var isW9 = typeSel.value === 'w9';
					idLabel.textContent = isW9 ? 'SSN or EIN' : 'Foreign tax ID (if any)';
					idHint.textContent  = isW9 ? 'Required for a W-9.' : 'Optional for a W-8BEN.';
				}
				typeSel.addEventListener('change', sync);
				sync();
			})();
		</script>
		<?php
		echo '</div>';
	}

	public static function handle_save_tax_info() {
		if ( ! is_user_logged_in() ) {
			wp_die( 'Please log in first.' );
		}
		check_admin_referer( 'gas_save_tax_info' );

		$user_id = get_current_user_id();
		$saved   = GAS_Payouts::save_tax_info( $user_id, wp_unslash( $_POST ) );

		if ( ! $saved ) {
			wp_safe_redirect( add_query_arg( 'gas_error', rawurlencode( 'Please fill in your name, tax ID type, and country of residence — a US person also needs an SSN or EIN.' ), self::account_url() ) );
			exit;
		}

		wp_safe_redirect( add_query_arg( 'gas_notice', 'tax_info_updated', self::account_url() ) );
		exit;
	}
}
