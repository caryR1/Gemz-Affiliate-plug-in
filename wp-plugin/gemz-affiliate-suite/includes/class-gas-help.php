<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plain-language explainer for affiliates, covering everything shipped
 * so far (referral link, recruiting/tiers, dashboard). Content lives in
 * code (like the rest of the plugin's front-end text) rather than being
 * admin-editable, so it stays in sync as features change — a lighter
 * approach than gemz-referral-crm's fuller help-docs system, appropriate
 * since GAS doesn't (yet) have campaigns/variants or the other things
 * GRC's version documents.
 */
class GAS_Help {

	public static function init() {
		add_shortcode( 'gas_help', array( __CLASS__, 'render' ) );
		add_shortcode( 'gas_partner_help', array( __CLASS__, 'render_partner_help' ) );
		add_shortcode( 'gas_faq', array( __CLASS__, 'render_faq' ) );
		add_action( 'init', array( __CLASS__, 'maybe_create_page' ) );
	}

	public static function maybe_create_page() {
		self::create_or_adopt_page( 'gas_help_page_id', 'Affiliate Help', 'affiliate-help', '[gas_help]' );
		self::create_or_adopt_page( 'gas_partner_help_page_id', 'Partner Help', 'partner-help', '[gas_partner_help]' );
		self::create_or_adopt_page( 'gas_faq_page_id', 'FAQ', 'faq', '[gas_faq]' );
	}

	/**
	 * If a page already exists at this slug (e.g. a site's own pre-built
	 * FAQ page, unrelated to this plugin), ADOPT its ID rather than
	 * creating a second page — a blind create-if-option-missing approach
	 * silently produced duplicate "-2"/"-3" pages the first time this ran
	 * on a site that already had real content at that slug. Never
	 * overwrites an adopted page's existing content; only ever writes
	 * the shortcode content when actually creating a brand-new page.
	 */
	public static function create_or_adopt_page( $option_key, $title, $slug, $shortcode_content ) {
		if ( get_option( $option_key ) ) {
			return;
		}

		$existing = get_page_by_path( $slug );
		if ( $existing ) {
			update_option( $option_key, $existing->ID );
			return;
		}

		$id = wp_insert_post( array(
			'post_title'   => $title,
			'post_name'    => $slug,
			'post_content' => $shortcode_content,
			'post_status'  => 'publish',
			'post_type'    => 'page',
		) );
		if ( $id && ! is_wp_error( $id ) ) {
			update_option( $option_key, $id );
		}
	}

	public static function page_url() {
		$id = get_option( 'gas_help_page_id' );
		return $id ? get_permalink( $id ) : home_url( '/affiliate-help/' );
	}

	public static function partner_page_url() {
		$id = get_option( 'gas_partner_help_page_id' );
		return $id ? get_permalink( $id ) : home_url( '/partner-help/' );
	}

	public static function faq_page_url() {
		$id = get_option( 'gas_faq_page_id' );
		return $id ? get_permalink( $id ) : home_url( '/faq/' );
	}

	public static function render() {
		$partner_label = GAS_Settings::get( 'partner_label' );
		$site_name     = GAS_Settings::get( 'site_name' );

		ob_start();
		?>
		<div class="gas-dashboard gas-help-doc">
			<p><a href="<?php echo esc_url( GAS_Frontend::signup_url() ); ?>">&larr; Back to your dashboard</a></p>

			<div class="gas-panel">
				<h3>Finding your way around</h3>
				<p>Your affiliate area is a few linked pages, with a menu at the top of each one to move between them: <strong>Overview</strong> is your landing page with quick stats, <strong>My Links &amp; Earnings</strong> has your link(s), marketing materials, and earnings by tier, <strong>My Team</strong> shows everyone you've recruited, and <strong>Account</strong> holds your password, payment info, tax info, and dashboard color.</p>
			</div>

			<div class="gas-panel">
				<h3>Your referral link(s)</h3>
				<p>You have one personal referral code, and My Links &amp; Earnings shows a ready-to-share link built from it for each active <?php echo esc_html( $partner_label ); ?> campaign — sometimes more than one, if we're running more than one campaign right now. Anyone who clicks one of your links and later does business with that <?php echo esc_html( $partner_label ); ?> gets tracked back to you automatically. A new campaign shows up the moment it goes live — nothing you need to do to unlock it.</p>
			</div>

			<div class="gas-panel">
				<h3>Adding a referral by hand</h3>
				<p>Would rather not wait for someone to click your link? "Add a referral" on My Links &amp; Earnings lets you enter a friend or customer's info directly — it's attached to your code the same as a real click-through. The public "Refer a friend" page offers the same shortcut, pre-filled with your details, when you're logged in.</p>
				<p>Enter their state so we can match them to a <?php echo esc_html( $partner_label ); ?> that covers their area — or, if you already know who's the right fit, pick that <?php echo esc_html( $partner_label ); ?> directly instead of leaving it to the state match.</p>
			</div>

			<div class="gas-panel">
				<h3>Recruiting your own team</h3>
				<p>My Team shows a link for inviting other people to become affiliates themselves. Anyone who signs up through it becomes part of your team, and you earn a bonus on their sales going forward — and again, a smaller bonus, on sales made by people <em>they</em> recruit. This works up to two levels deep below you. If you already know who you want to add, "Add a team member" on the same page creates their account directly — they get the normal set-your-password email, same as if they'd signed up themselves.</p>
			</div>

			<div class="gas-panel">
				<h3>How earnings are figured</h3>
				<p>Every sale generates a commission that's split a fixed way across up to three tiers: the affiliate who made the sale, that affiliate's recruiter, and the recruiter's own recruiter (if there is one). My Links &amp; Earnings shows your estimated earnings range for each tier while the current month is still open, and switches to your exact final amount once the month closes.</p>
			</div>

			<div class="gas-panel">
				<h3>Your team view</h3>
				<p>My Team shows everyone you've personally recruited, and everyone they've recruited in turn, with enough contact info to reach out and help them get started.</p>
			</div>

			<div class="gas-panel">
				<h3>Payment info</h3>
				<p>Enter how you'd like to be paid under Payment Info on your Account page. This is only ever visible to you — <?php echo esc_html( $site_name ); ?> only ever sees a masked summary, never your full account details.</p>
			</div>

			<div class="gas-panel">
				<h3>Tax information (required before you're paid)</h3>
				<p>Before we can send you any payout, we need a one-time W-9 (US) or W-8BEN (non-US) form on file — the same Tax Information section on your Account page as Payment Info. This is standard for anyone earning referral income, not something specific to you. We only ever see a masked summary of what's on file, never your tax ID.</p>
			</div>

			<div class="gas-panel">
				<h3>Minimum payout amount</h3>
				<p>Payouts only go out once your unpaid balance reaches $50. Below that, nothing is lost — your balance just carries forward automatically to the next payout run. If you're ever held back for this reason (or for missing tax info above), we'll email you directly to let you know.</p>
			</div>

			<div class="gas-panel">
				<h3>Referring yourself</h3>
				<p><strong>Allowed:</strong> using your own referral link for a personal purchase. There's no rule against it, and you'll earn your normal commission on the sale. If that <?php echo esc_html( $partner_label ); ?> also offers buyer cash back, you'd get a separate email with a link to claim it, the same as any other referred customer would.</p>
				<p><strong>Not allowed:</strong> creating more than one account to stack extra recruiting bonuses on what's really one person's own business.</p>
			</div>

			<div class="gas-panel">
				<h3>Marketing materials</h3>
				<p>If we've made images or other materials available for your current campaigns, they show up in a Marketing Materials section on My Links &amp; Earnings, ready to download and use when promoting your link.</p>
			</div>

			<div class="gas-panel">
				<h3>Changing your password</h3>
				<p>Use the password field near the bottom of your Account page. You'll need your current password to set a new one.</p>
			</div>

			<div class="gas-panel">
				<h3>Unsubscribing from emails</h3>
				<p>Every email we send has an unsubscribe link at the bottom. Using it stops future emails to that address — it doesn't affect your account, your link, or your payouts.</p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	public static function render_partner_help() {
		$partner_label = GAS_Settings::get( 'partner_label' );
		$site_name     = GAS_Settings::get( 'site_name' );

		ob_start();
		?>
		<div class="gas-dashboard gas-help-doc">
			<p><a href="<?php echo esc_url( GAS_Partner_Portal::page_url() ); ?>">&larr; Back to your dashboard</a></p>

			<div class="gas-panel">
				<h3>Your deal pipeline</h3>
				<p>Every lead sent to you shows up in your dashboard with its current status. Use the status dropdown next to each lead to move it along as the project progresses — Accepted &rarr; In Progress &rarr; Completed, or Lost if it doesn't work out. Keep this current; it's how <?php echo esc_html( $site_name ); ?> knows a sale actually happened.</p>
			</div>

			<div class="gas-panel">
				<h3>What "new" leads mean</h3>
				<p>A lead shown without a status dropdown is already on your account, but is still waiting on a first look from an admin before it's yours to work. Once that happens, the dropdown appears and you can start moving it through the pipeline.</p>
			</div>

			<div class="gas-panel">
				<h3>Sending a lead back</h3>
				<p>Not a fit for you? Use the Decline button next to any lead (unless it's already marked Completed) to send it back for rematching — it comes off your queue right away.</p>
			</div>

			<div class="gas-panel">
				<h3>Changing your password</h3>
				<p>Use the password field near the bottom of your dashboard. You'll need your current password to set a new one.</p>
			</div>

			<div class="gas-panel">
				<h3>Unsubscribing from emails</h3>
				<p>Every email we send has an unsubscribe link at the bottom. Using it stops future emails to that address — it doesn't affect your account, your leads, or how leads keep being assigned to you.</p>
			</div>

			<div class="gas-panel">
				<h3>Questions?</h3>
				<p>Contact <?php echo esc_html( $site_name ); ?> directly if anything here doesn't match what you're seeing, or if you think you're missing a lead you should have.</p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Customer-facing FAQ: explains the ecosystem itself (what an
	 * affiliate/agent and a fulfillment partner are, how to become
	 * either) without duplicating the fuller agent/partner help docs —
	 * this is the bridge for someone who's never heard of either role.
	 * Renders as a native <details>/<summary> accordion (collapsed by
	 * default, click to expand) — no JS needed, works with either theme.
	 */
	public static function render_faq() {
		$partner_label = GAS_Settings::get( 'partner_label' );
		$site_name     = GAS_Settings::get( 'site_name' );

		$faqs = array(
			'What is ' . $site_name . '?' => 'We connect people to trusted ' . $partner_label . 's, and reward the people who make the introduction.',
			'What is an affiliate?' => 'Anyone who shares their personal referral link and earns a commission when it leads to a sale. It\'s free to join, and there\'s a signup link at the bottom of this page.',
			'What is a ' . $partner_label . '?' => 'The business that actually does the work once you\'re referred — installer, builder, or service provider, depending on the project. ' . $site_name . ' vets and works with them directly; you never need to pick one yourself.',
			'How do I become an affiliate?' => 'Sign up using the "Become an Affiliate" link on this site — it takes under a minute, no approval wait.',
			'How do I become a ' . $partner_label . '?' => 'Contact ' . $site_name . ' directly to discuss a partnership.',
			'Is it free?' => 'Yes — there\'s never a cost to sign up as an affiliate or to be referred as a customer.',
		);

		ob_start();
		?>
		<div class="gas-faq">
			<?php foreach ( $faqs as $question => $answer ) : ?>
				<details class="gas-faq-item" style="margin-bottom:0.75em;">
					<summary style="cursor:pointer;font-weight:600;"><?php echo esc_html( $question ); ?></summary>
					<p style="margin-top:0.5em;"><?php echo esc_html( $answer ); ?></p>
				</details>
			<?php endforeach; ?>
			<p><a href="<?php echo esc_url( GAS_Frontend::signup_url() ); ?>">Become an affiliate &rarr;</a></p>
		</div>
		<?php
		return ob_get_clean();
	}
}
