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
		add_action( 'init', array( __CLASS__, 'maybe_create_page' ) );
	}

	public static function maybe_create_page() {
		if ( get_option( 'gas_help_page_id' ) ) {
			return;
		}
		$id = wp_insert_post( array(
			'post_title'   => 'Affiliate Help',
			'post_name'    => 'affiliate-help',
			'post_content' => '[gas_help]',
			'post_status'  => 'publish',
			'post_type'    => 'page',
		) );
		if ( $id && ! is_wp_error( $id ) ) {
			update_option( 'gas_help_page_id', $id );
		}
	}

	public static function page_url() {
		$id = get_option( 'gas_help_page_id' );
		return $id ? get_permalink( $id ) : home_url( '/affiliate-help/' );
	}

	public static function render() {
		$partner_label = GAS_Settings::get( 'partner_label' );
		$site_name     = GAS_Settings::get( 'site_name' );

		ob_start();
		?>
		<div class="gas-dashboard gas-help-doc">
			<p><a href="<?php echo esc_url( GAS_Frontend::signup_url() ); ?>">&larr; Back to your dashboard</a></p>

			<h3>Your referral link</h3>
			<p>Every affiliate gets a unique referral link, shown at the top of your dashboard. Anyone who clicks it and later does business with a <?php echo esc_html( $partner_label ); ?> gets tracked back to you automatically — you never need to tell anyone which <?php echo esc_html( $partner_label ); ?> to use, that part is handled separately.</p>

			<h3>Recruiting your own team</h3>
			<p>Your dashboard also shows a second link for inviting other people to become affiliates themselves. Anyone who signs up through that link becomes part of your team, and you earn a bonus on their sales going forward — and again, a smaller bonus, on sales made by people <em>they</em> recruit. This works up to two levels deep below you.</p>

			<h3>How earnings are figured</h3>
			<p>Every sale generates a commission that's split a fixed way across up to three tiers: the affiliate who made the sale, that affiliate's recruiter, and the recruiter's own recruiter (if there is one). Your dashboard shows your estimated earnings range for each tier while the current month is still open, and switches to your exact final amount once the month closes.</p>

			<h3>Your team view</h3>
			<p>The "Your team" section on your dashboard shows everyone you've personally recruited, and everyone they've recruited in turn, with enough contact info to reach out and help them get started.</p>

			<h3>Payment info</h3>
			<p>Enter how you'd like to be paid under Payment Info on your dashboard. This is only ever visible to you — <?php echo esc_html( $site_name ); ?> only ever sees a masked summary, never your full account details.</p>

			<h3>Changing your password</h3>
			<p>Use the password field near the bottom of your dashboard. You'll need your current password to set a new one.</p>
		</div>
		<?php
		return ob_get_clean();
	}
}
