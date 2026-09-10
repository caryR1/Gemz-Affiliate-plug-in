<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GAS_Redirect {

	const COOKIE_NAME = 'gas_affiliate_code';
	const COOKIE_DAYS = 180;

	// Separate cookie for recruiting (an affiliate inviting someone to
	// become an affiliate themselves), distinct from the customer-referral
	// cookie above — the two can be live at once (e.g. someone clicks a
	// recruiting link, decides not to sign up yet, but later clicks the
	// same person's customer link too) without one clobbering the other.
	const SPONSOR_COOKIE_NAME = 'gas_sponsor_code';

	// Which campaign a click landed through — set independently of
	// COOKIE_NAME above, since a campaign link can be clicked with no
	// (or an invalid) ?ref=, and we still want that organic traffic
	// attributed to a campaign/partner even with no affiliate credited.
	const CAMPAIGN_COOKIE_NAME = 'gas_campaign_id';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_redirect' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_join_redirect' ) );
	}

	/**
	 * /go/{tracking_slug} now resolves a CAMPAIGN (see GAS_Campaigns),
	 * not a code directly — ported from GRC's link architecture,
	 * 2026-09-08. The affiliate's own stable code rides along as a
	 * ?ref= query arg instead of being part of the path itself, e.g.
	 * /go/go-solar-power-direct-link?ref=jane-smith. /join/{code} is
	 * unrelated to campaigns (it's always been about recruiting a new
	 * affiliate via another affiliate's own code) and is untouched.
	 */
	public static function add_rewrite_rule() {
		add_rewrite_rule( '^go/([a-zA-Z0-9_-]+)/?$', 'index.php?gas_campaign_slug=$matches[1]', 'top' );
		add_rewrite_rule( '^join/([a-zA-Z0-9_-]+)/?$', 'index.php?gas_sponsor=$matches[1]', 'top' );
	}

	public static function add_query_var( $vars ) {
		$vars[] = 'gas_campaign_slug';
		$vars[] = 'gas_sponsor';
		return $vars;
	}

	/**
	 * On /join/{code}: a recruiting link, not a customer-referral one.
	 * Sets the sponsor cookie and sends the visitor to the signup page —
	 * GAS_Frontend::handle_signup() reads the cookie to set the new
	 * affiliate's sponsor_code_id. Unknown/inactive codes fall through to
	 * signup with no sponsor attributed, same spirit as an unknown /go/
	 * code 404ing rather than silently attributing to nothing.
	 */
	public static function handle_join_redirect() {
		$code = get_query_var( 'gas_sponsor' );
		if ( empty( $code ) ) {
			return;
		}

		global $wpdb;
		$codes_table = GAS_DB::table( 'codes' );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, code, active, wp_user_id FROM {$codes_table} WHERE code = %s", $code ) );

		if ( $row && $row->active ) {
			$is_self = $row->wp_user_id && is_user_logged_in() && get_current_user_id() === (int) $row->wp_user_id;
			if ( ! $is_self ) {
				setcookie(
					self::SPONSOR_COOKIE_NAME,
					$row->code,
					array(
						'expires'  => time() + self::COOKIE_DAYS * DAY_IN_SECONDS,
						'path'     => '/',
						'secure'   => is_ssl(),
						'httponly' => true,
						'samesite' => 'Lax',
					)
				);
			}
		}

		wp_redirect( GAS_Frontend::signup_url(), 302 );
		exit;
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * On /go/{tracking_slug}?ref={code}&variant={id}: resolve the
	 * campaign by slug, optionally the affiliate by ref, log the click,
	 * and redirect. Two-hop resolution ported from GRC's
	 * GRC_Public::maybe_redirect_tracking_link(): slug -> campaign is the
	 * hard requirement (unknown/paused slug falls through to a normal
	 * 404, same as an unknown code used to); ref -> affiliate is
	 * best-effort (an organic click with no ref, or a stale/invalid one,
	 * still resolves the campaign and still gets logged — it's just not
	 * credited to anyone), matching GRC's "ref is opaque until lead
	 * creation" philosophy but applied at click time since GAS, unlike
	 * GRC, already logs clicks here rather than only at lead-creation.
	 */
	public static function handle_redirect() {
		$slug = get_query_var( 'gas_campaign_slug' );
		if ( empty( $slug ) ) {
			return;
		}

		$campaign = GAS_Campaigns::get_by_slug( $slug );
		if ( ! $campaign ) {
			return; // Unknown or paused campaign: let WP 404 normally.
		}

		global $wpdb;
		$partners_table = GAS_DB::table( 'partners' );
		$partner        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$partners_table} WHERE id = %d", $campaign->partner_id ) );

		$code = null;
		if ( ! empty( $_GET['ref'] ) ) {
			$codes_table = GAS_DB::table( 'codes' );
			$code = $wpdb->get_row( $wpdb->prepare(
				"SELECT id, code, wp_user_id FROM {$codes_table} WHERE code = %s AND active = 1",
				sanitize_text_field( wp_unslash( $_GET['ref'] ) )
			) );
		}

		// Self-referral is allowed as of 2026-09-08 (Cary's call): payouts
		// only fire on a partner-confirmed completed sale from a fixed
		// commission pool, so one real person being both the affiliate and
		// the customer on a single real transaction doesn't cost the
		// partner anything or manufacture new money — it's a reallocation
		// within a pool that was already fixed, not free money. The actual
		// risk (sockpuppet accounts stacking multiple sponsor tiers of that
		// same fixed pool) is guarded separately at payout time — see
		// GAS_Payouts' tier-stacking audit flag — not here at click time.
		// This class still has a SEPARATE self-referral guard in
		// handle_join_redirect() for the /join/ recruiting link, which is
		// intentionally untouched: that one guards against an affiliate
		// creating a second account under their own sponsorship, which is
		// exactly the tier-stacking concern, not the legitimate
		// buy-from-yourself case this guard used to block.

		// The campaign cookie is set independent of whether ref resolved,
		// so an organic (no-ref) click through a campaign link still
		// attributes to that campaign/partner for reporting purposes.
		setcookie(
			self::CAMPAIGN_COOKIE_NAME,
			(string) $campaign->id,
			array(
				'expires'  => time() + self::COOKIE_DAYS * DAY_IN_SECONDS,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);

		if ( $code ) {
			// Last-touch attribution: overwrite any existing cookie unconditionally,
			// so whichever code was clicked most recently is the one that counts,
			// for up to COOKIE_DAYS. Simple overwrite is what makes this last-touch
			// rather than first-touch — no extra logic needed.
			setcookie(
				self::COOKIE_NAME,
				$code->code,
				array(
					'expires'  => time() + self::COOKIE_DAYS * DAY_IN_SECONDS,
					'path'     => '/',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);
		}

		self::maybe_log_click( $campaign, $code, $partner );

		// A campaign can override where it lands (a custom on-site page);
		// otherwise it falls through to the partner's own normal
		// fulfillment setup, unchanged from before campaigns existed.
		if ( ! empty( $campaign->landing_page_id ) ) {
			$landing_page_id = $campaign->landing_page_id;

			// An explicit ?variant= overrides the campaign's own landing
			// page, as long as it actually belongs to THIS campaign —
			// falls back to the campaign's default silently on any
			// mismatch (wrong campaign, deleted variant) rather than
			// erroring, so a stale or tampered variant param never
			// breaks the link. Ported from GRC's identical guard.
			if ( ! empty( $_GET['variant'] ) ) {
				$variants_table = GAS_DB::table( 'campaign_variants' );
				$variant_page_id = $wpdb->get_var( $wpdb->prepare(
					"SELECT landing_page_id FROM {$variants_table} WHERE id = %d AND campaign_id = %d",
					absint( $_GET['variant'] ), $campaign->id
				) );
				if ( $variant_page_id ) {
					$landing_page_id = $variant_page_id;
				}
			}

			$target = add_query_arg( 'campaign_id', $campaign->id, get_permalink( $landing_page_id ) );
			if ( $code ) {
				$target = add_query_arg( 'ref', $code->code, $target );
			}
			wp_redirect( $target, 302 );
			exit;
		}

		if ( ! $partner ) {
			wp_redirect( home_url( '/' ), 302 );
			exit;
		}

		// Lead-capture partners take the visitor to an on-site form instead
		// of an external destination — the form reads the campaign/ref
		// cookies we just set for attribution, so no query-string handoff
		// is needed.
		if ( 'lead_capture' === $partner->fulfillment_mode ) {
			wp_redirect( GAS_Leads::page_url(), 302 );
			exit;
		}

		if ( ! empty( $partner->destination_url ) ) {
			wp_redirect( esc_url_raw( $partner->destination_url ), 302 );
			exit;
		}

		// No destination configured yet (referral link not approved):
		// send the visitor somewhere sane instead of a dead page.
		wp_redirect( home_url( '/' ), 302 );
		exit;
	}

	/**
	 * Logs the click, unless this exact visitor already clicked this exact
	 * campaign today — so refreshing the page (or a slow double-click)
	 * doesn't inflate the count shown on the affiliate's dashboard and the
	 * admin Click Log. Deliberately still a full historical row per unique
	 * visitor/day (not just a counter), since the Click Log screen shows
	 * IP/user-agent detail per row. $code may be null (organic click, no
	 * ref, or an invalid one) — code_id/code are stored as the 0/''
	 * sentinel in that case, same convention GAS already uses elsewhere
	 * for "unassigned."
	 *
	 * Two fraud-filtering checks added 2026-09-08, both skip LOGGING only
	 * — the visitor is still redirected either way, this just keeps a
	 * bot or an IP-burst from inflating an affiliate's credited click
	 * count: a known bot/crawler User-Agent never gets logged at all, and
	 * an IP already at today's per-campaign click cap (a real signal of
	 * automated traffic, distinct from the per-visitor dedup above) is
	 * silently dropped rather than logged.
	 */
	private static function maybe_log_click( $campaign, $code, $partner ) {
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( GAS_Fraud::is_bot_user_agent( $user_agent ) ) {
			return;
		}

		$ip = GAS_Fraud::get_client_ip();
		if ( GAS_Fraud::click_rate_limited( $ip, $campaign->id ) ) {
			return;
		}

		global $wpdb;
		$clicks_table = GAS_DB::table( 'clicks' );
		$hash         = self::visitor_hash();
		$today        = current_time( 'Y-m-d' );

		$already = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$clicks_table}
				 WHERE campaign_id = %d AND visitor_hash = %s AND DATE(clicked_at) = %s
				 LIMIT 1",
				$campaign->id,
				$hash,
				$today
			)
		);
		if ( $already ) {
			return;
		}

		GAS_Fraud::record_click( $ip, $campaign->id );

		// Log the click regardless of whether a destination URL is set yet,
		// so early testing/traffic is still captured.
		$wpdb->insert(
			$clicks_table,
			array(
				'code_id'      => $code ? $code->id : 0,
				'code'         => $code ? $code->code : '',
				'partner_id'   => $partner ? $partner->id : null,
				'campaign_id'  => $campaign->id,
				'clicked_at'   => current_time( 'mysql' ),
				'ip_address'   => $ip,
				'user_agent'   => substr( $user_agent, 0, 255 ),
				'visitor_hash' => $hash,
			)
		);
	}

	/**
	 * Not real identity — just enough to dedup one visitor's repeat clicks
	 * within a day. Hashed (never stored raw) and never used for anything
	 * beyond this dedup check.
	 */
	private static function visitor_hash() {
		$ip = GAS_Fraud::get_client_ip();
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return hash( 'sha256', $ip . '|' . $ua . '|' . wp_salt() );
	}

}
