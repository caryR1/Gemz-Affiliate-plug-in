<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fraud-filtering helpers (2026-09-08) — proportionate, no-paid-API
 * improvements over "email admin when something looks off": IP rate
 * limiting on signups and click logging, basic bot User-Agent filtering,
 * and a disposable-email-domain check on affiliate signup. Honeypot
 * fields were audited separately and are already consistent across every
 * public form (signup, signup-or-refer, lead submit, lead-magnet opt-in)
 * — no changes needed there.
 *
 * Deliberately NOT in scope: IP-intelligence (datacenter/VPN/proxy
 * detection) needs a paid API and is a possible future item, not this
 * pass.
 */
class GAS_Fraud {

	/**
	 * Small, maintained-by-us blocklist of common disposable/temporary
	 * email domains — no paid API needed. Not exhaustive (new disposable
	 * services appear constantly), but catches the well-known, most
	 * commonly abused ones. Add to this list as new ones show up in
	 * practice; check `notes` (has this partner said fraud is a problem)
	 * and the audit log before assuming a false positive is happening.
	 */
	private static $disposable_domains = array(
		'mailinator.com', 'guerrillamail.com', '10minutemail.com', 'tempmail.com',
		'temp-mail.org', 'throwawaymail.com', 'yopmail.com', 'trashmail.com',
		'getnada.com', 'fakeinbox.com', 'sharklasers.com', 'dispostable.com',
		'maildrop.cc', 'mintemail.com', 'mailnesia.com', 'spamgourmet.com',
		'guerrillamailblock.com', 'mytemp.email', 'moakt.com', 'emailondeck.com',
		'mailcatch.com', 'inboxbear.com', 'tempinbox.com', 'burnermail.io',
	);

	public static function is_disposable_email( $email ) {
		$domain = strtolower( trim( substr( strrchr( $email, '@' ), 1 ) ) );
		return in_array( $domain, self::$disposable_domains, true );
	}

	/**
	 * Small, non-exhaustive list of substrings found in known crawler/bot
	 * User-Agent strings. Checked case-insensitively. False negatives
	 * (a bot that doesn't self-identify) are expected and fine — this
	 * catches the common, honest crawlers (search engines, uptime
	 * monitors, social-media link-preview fetchers, headless-browser
	 * defaults) that would otherwise inflate click counts, not a serious
	 * adversary trying to hide.
	 */
	private static $bot_ua_substrings = array(
		'bot', 'crawl', 'spider', 'slurp', 'facebookexternalhit', 'headlesschrome',
		'phantomjs', 'curl/', 'wget/', 'python-requests', 'go-http-client',
		'monitor', 'pingdom', 'uptimerobot', 'ahrefsbot', 'semrushbot', 'mj12bot',
		'linkedinbot', 'whatsapp', 'telegrambot', 'discordbot', 'skypeuripreview',
	);

	public static function is_bot_user_agent( $user_agent ) {
		if ( '' === $user_agent ) {
			return false; // an empty UA is unusual but not itself proof of a bot
		}
		$ua = strtolower( $user_agent );
		foreach ( self::$bot_ua_substrings as $needle ) {
			if ( false !== strpos( $ua, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Caps affiliate signups per IP per calendar day — a real signal a
	 * burst of accounts from one IP is automated, not organic. Uses a
	 * transient (not a DB table) since this is a cheap, self-expiring
	 * counter, not data anyone needs to query or report on later.
	 */
	const MAX_SIGNUPS_PER_IP_PER_DAY = 5;

	public static function signup_rate_limited( $ip ) {
		if ( '' === $ip ) {
			return false; // can't rate-limit what we can't identify
		}
		$count = (int) get_transient( self::signup_transient_key( $ip ) );
		return $count >= self::MAX_SIGNUPS_PER_IP_PER_DAY;
	}

	public static function record_signup_attempt( $ip ) {
		if ( '' === $ip ) {
			return;
		}
		$key   = self::signup_transient_key( $ip );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, DAY_IN_SECONDS );
	}

	private static function signup_transient_key( $ip ) {
		return 'gas_signup_ct_' . md5( $ip );
	}

	/**
	 * Caps how many clicks from one IP get LOGGED (and therefore credited/
	 * counted) against one campaign per day — separate from, and looser
	 * than, the existing per-visitor-hash-per-day dedup in
	 * GAS_Redirect::maybe_log_click() (which caps ONE visitor's repeat
	 * clicks at 1/day). This catches the gap that dedup doesn't: many
	 * DIFFERENT visitor-hashes (e.g. rotating User-Agent) all coming from
	 * the SAME IP hammering the same campaign — a real signal, not
	 * something the per-visitor dedup was ever meant to catch. The
	 * visitor is still redirected either way; only the click LOG entry
	 * (and therefore the affiliate's credited click count) is capped.
	 */
	const MAX_CLICKS_PER_IP_PER_CAMPAIGN_PER_DAY = 20;

	public static function click_rate_limited( $ip, $campaign_id ) {
		if ( '' === $ip ) {
			return false;
		}
		$count = (int) get_transient( self::click_transient_key( $ip, $campaign_id ) );
		return $count >= self::MAX_CLICKS_PER_IP_PER_CAMPAIGN_PER_DAY;
	}

	public static function record_click( $ip, $campaign_id ) {
		if ( '' === $ip ) {
			return;
		}
		$key   = self::click_transient_key( $ip, $campaign_id );
		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, DAY_IN_SECONDS );
	}

	private static function click_transient_key( $ip, $campaign_id ) {
		return 'gas_click_ct_' . md5( $ip . '|' . $campaign_id );
	}

	/**
	 * Shared IP-detection helper — moved here from GAS_Redirect (which had
	 * its own private copy) so signup rate-limiting and click rate-limiting
	 * use the exact same logic rather than two copies drifting apart.
	 */
	public static function get_client_ip() {
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
