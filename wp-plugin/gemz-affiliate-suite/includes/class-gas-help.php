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
				<h3>Your referral link(s)</h3>
				<p>You have one personal referral code, and your dashboard shows a ready-to-share link built from it for each active <?php echo esc_html( $partner_label ); ?> campaign — sometimes more than one, if we're running more than one campaign right now. Anyone who clicks one of your links and later does business with that <?php echo esc_html( $partner_label ); ?> gets tracked back to you automatically. A new campaign shows up on your dashboard the moment it goes live — nothing you need to do to unlock it.</p>
			</div>

			<div class="gas-panel">
				<h3>Recruiting your own team</h3>
				<p>Your dashboard also shows a second link for inviting other people to become affiliates themselves. Anyone who signs up through that link becomes part of your team, and you earn a bonus on their sales going forward — and again, a smaller bonus, on sales made by people <em>they</em> recruit. This works up to two levels deep below you.</p>
			</div>

			<div class="gas-panel">
				<h3>How earnings are figured</h3>
				<p>Every sale generates a commission that's split a fixed way across up to three tiers: the affiliate who made the sale, that affiliate's recruiter, and the recruiter's own recruiter (if there is one). Your dashboard shows your estimated earnings range for each tier while the current month is still open, and switches to your exact final amount once the month closes.</p>
			</div>

			<div class="gas-panel">
				<h3>Your team view</h3>
				<p>The "Your team" section on your dashboard shows everyone you've personally recruited, and everyone they've recruited in turn, with enough contact info to reach out and help them get started.</p>
			</div>

			<div class="gas-panel">
				<h3>Payment info</h3>
				<p>Enter how you'd like to be paid under Payment Info on your dashboard. This is only ever visible to you — <?php echo esc_html( $site_name ); ?> only ever sees a masked summary, never your full account details.</p>
			</div>

			<div class="gas-panel">
				<h3>Changing your password</h3>
				<p>Use the password field near the bottom of your dashboard. You'll need your current password to set a new one.</p>
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
				<h3>Changing your password</h3>
				<p>Use the password field near the bottom of your dashboard. You'll need your current password to set a new one.</p>
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
