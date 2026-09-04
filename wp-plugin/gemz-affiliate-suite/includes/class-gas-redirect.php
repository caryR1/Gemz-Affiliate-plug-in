<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GAS_Redirect {

	const COOKIE_NAME = 'gas_affiliate_code';
	const COOKIE_DAYS = 180;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_var' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_redirect' ) );
	}

	public static function add_rewrite_rule() {
		add_rewrite_rule( '^go/([a-zA-Z0-9_-]+)/?$', 'index.php?gas_code=$matches[1]', 'top' );
	}

	public static function add_query_var( $vars ) {
		$vars[] = 'gas_code';
		return $vars;
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}

	/**
	 * On /go/{code}: look up the code, log the click, redirect to the
	 * partner's real referral URL. Unknown codes fall through untouched
	 * (WordPress will 404 normally, no click is logged).
	 */
	public static function handle_redirect() {
		$code = get_query_var( 'gas_code' );
		if ( empty( $code ) ) {
			return;
		}

		global $wpdb;
		$codes_table    = GAS_DB::table( 'codes' );
		$partners_table = GAS_DB::table( 'partners' );

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT c.id AS code_id, c.code, c.partner_id, c.active, c.wp_user_id, p.destination_url
				 FROM {$codes_table} c
				 LEFT JOIN {$partners_table} p ON p.id = c.partner_id
				 WHERE c.code = %s",
				$code
			)
		);

		if ( ! $row || ! $row->active ) {
			return; // Unknown or disabled code: let WP 404 normally.
		}

		// Self-referral guard: an affiliate clicking their own link while
		// logged in as themselves doesn't get a cookie or a logged click —
		// otherwise they could trivially inflate their own click count or
		// (if a sale were later attributed automatically) their own payout.
		$is_self = $row->wp_user_id && is_user_logged_in() && get_current_user_id() === (int) $row->wp_user_id;

		if ( ! $is_self ) {
			// Last-touch attribution: overwrite any existing cookie unconditionally,
			// so whichever code was clicked most recently is the one that counts,
			// for up to COOKIE_DAYS. Simple overwrite is what makes this last-touch
			// rather than first-touch — no extra logic needed.
			setcookie(
				self::COOKIE_NAME,
				$row->code,
				array(
					'expires'  => time() + self::COOKIE_DAYS * DAY_IN_SECONDS,
					'path'     => '/',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				)
			);

			self::maybe_log_click( $row );
		}

		if ( ! empty( $row->destination_url ) ) {
			wp_redirect( esc_url_raw( $row->destination_url ), 302 );
			exit;
		}

		// No destination configured yet (referral link not approved):
		// send the visitor somewhere sane instead of a dead page.
		wp_redirect( home_url( '/' ), 302 );
		exit;
	}

	/**
	 * Logs the click, unless this exact visitor already clicked this exact
	 * code today — so refreshing the page (or a slow double-click) doesn't
	 * inflate the count shown on the affiliate's dashboard and the admin
	 * Click Log. Deliberately still a full historical row per unique
	 * visitor/day (not just a counter), since the Click Log screen shows
	 * IP/user-agent detail per row.
	 */
	private static function maybe_log_click( $row ) {
		global $wpdb;
		$clicks_table = GAS_DB::table( 'clicks' );
		$hash         = self::visitor_hash();
		$today        = current_time( 'Y-m-d' );

		$already = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$clicks_table}
				 WHERE code_id = %d AND visitor_hash = %s AND DATE(clicked_at) = %s
				 LIMIT 1",
				$row->code_id,
				$hash,
				$today
			)
		);
		if ( $already ) {
			return;
		}

		// Log the click regardless of whether a destination URL is set yet,
		// so early testing/traffic is still captured.
		$wpdb->insert(
			$clicks_table,
			array(
				'code_id'      => $row->code_id,
				'code'         => $row->code,
				'partner_id'   => $row->partner_id,
				'clicked_at'   => current_time( 'mysql' ),
				'ip_address'   => self::get_client_ip(),
				'user_agent'   => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '',
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
		$ip = self::get_client_ip();
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return hash( 'sha256', $ip . '|' . $ua . '|' . wp_salt() );
	}

	private static function get_client_ip() {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
				$ip = trim( explode( ',', $ip )[0] );
				if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
					return $ip;
				}
			}
		}
		return '';
	}
}
