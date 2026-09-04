<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A single mailing-list/segmentation directory covering everyone this
 * plugin ever talks to — affiliates, customers, and fulfillment partners
 * — each tagged with a fundamental contact_type from the moment they're
 * recorded. Deliberately separate from (but cross-referencing) the
 * operational tables (codes/partners/leads): those stay the source of
 * truth for how the program actually runs, this is the source of truth
 * for who to reach and how to segment them for outreach.
 */
class GAS_Contacts {

	const TYPES = array( 'affiliate', 'customer', 'partner' );

	public static function init() {
		add_shortcode( 'gas_lead_magnet', array( __CLASS__, 'render_lead_magnet' ) );
		add_action( 'admin_post_gas_lead_magnet_optin', array( __CLASS__, 'handle_lead_magnet_optin' ) );
		add_action( 'admin_post_nopriv_gas_lead_magnet_optin', array( __CLASS__, 'handle_lead_magnet_optin' ) );
	}

	/**
	 * Records or refreshes a contact. On a brand-new email, contact_type
	 * is whatever the caller says. On an ALREADY-known email, contact_type
	 * is left alone rather than silently overwritten — e.g. an existing
	 * affiliate who later opts into a lead magnet stays type "affiliate",
	 * not quietly reclassified as "customer". Changing a contact's type
	 * on purpose is an explicit admin action (the Segments screen), not a
	 * side effect of them doing something else on the site.
	 */
	public static function upsert( $email, $contact_type, array $args = array() ) {
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return;
		}
		if ( ! in_array( $contact_type, self::TYPES, true ) ) {
			$contact_type = 'customer';
		}

		global $wpdb;
		$table    = GAS_DB::table( 'contacts' );
		$existing = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$table} WHERE email = %s", $email ) );
		$now      = current_time( 'mysql' );

		$data = array(
			'name'          => isset( $args['name'] ) ? sanitize_text_field( $args['name'] ) : '',
			'phone'         => isset( $args['phone'] ) ? sanitize_text_field( $args['phone'] ) : '',
			'related_table' => isset( $args['related_table'] ) ? sanitize_key( $args['related_table'] ) : null,
			'related_id'    => isset( $args['related_id'] ) ? absint( $args['related_id'] ) : null,
			'updated_at'    => $now,
		);

		if ( $existing ) {
			// Don't blank out a name/phone we already had just because
			// this particular call didn't pass one.
			if ( '' === $data['name'] ) {
				unset( $data['name'] );
			}
			if ( '' === $data['phone'] ) {
				unset( $data['phone'] );
			}
			$wpdb->update( $table, $data, array( 'id' => $existing->id ) );
			return (int) $existing->id;
		}

		$data['email']        = $email;
		$data['contact_type'] = $contact_type;
		$data['source']       = isset( $args['source'] ) ? sanitize_key( $args['source'] ) : 'manual';
		$data['subscribed']   = 1;
		$data['created_at']   = $now;
		$wpdb->insert( $table, $data );
		return (int) $wpdb->insert_id;
	}

	/* ---------------------------------------------------------------- *
	 * LEAD MAGNETS
	 * ---------------------------------------------------------------- */

	public static function render_lead_magnet( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts );
		$id   = absint( $atts['id'] );
		if ( ! $id ) {
			return '';
		}

		global $wpdb;
		$magnet = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . GAS_DB::table( 'lead_magnets' ) . ' WHERE id = %d AND active = 1', $id ) );
		if ( ! $magnet ) {
			return '';
		}

		if ( isset( $_GET['gas_magnet_sent'] ) && (int) $_GET['gas_magnet_sent'] === $id ) {
			return '<div class="gas-notice gas-notice-success"><p>Check your email for the download link!</p></div>';
		}

		$error = isset( $_GET['gas_error'] ) ? sanitize_text_field( wp_unslash( $_GET['gas_error'] ) ) : '';

		ob_start();
		if ( $error ) {
			echo '<div class="gas-notice gas-notice-error"><p>' . esc_html( $error ) . '</p></div>';
		}
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="gas-form gas-lead-magnet">
			<?php wp_nonce_field( 'gas_lead_magnet_optin' ); ?>
			<input type="hidden" name="action" value="gas_lead_magnet_optin">
			<input type="hidden" name="magnet_id" value="<?php echo esc_attr( $magnet->id ); ?>">
			<p style="position:absolute;left:-9999px;" aria-hidden="true">
				<label>Leave this field empty<input type="text" name="gas_hp" tabindex="-1" autocomplete="off"></label>
			</p>
			<h3><?php echo esc_html( $magnet->title ); ?></h3>
			<?php if ( $magnet->description ) : ?>
				<p><?php echo esc_html( $magnet->description ); ?></p>
			<?php endif; ?>
			<p>
				<label for="gas_magnet_email">Email</label><br>
				<input type="email" id="gas_magnet_email" name="email" required class="gas-input">
			</p>
			<p>
				<button type="submit" class="gas-button">Send it to me</button>
			</p>
		</form>
		<?php
		return ob_get_clean();
	}

	public static function handle_lead_magnet_optin() {
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'gas_lead_magnet_optin' ) ) {
			wp_die( 'Security check failed. Please go back and try again.' );
		}

		$redirect_back = wp_get_referer() ? wp_get_referer() : home_url( '/' );
		$magnet_id      = isset( $_POST['magnet_id'] ) ? absint( $_POST['magnet_id'] ) : 0;

		// Honeypot: pretend success without sending anything.
		if ( ! empty( $_POST['gas_hp'] ) ) {
			wp_safe_redirect( add_query_arg( 'gas_magnet_sent', $magnet_id, $redirect_back ) );
			exit;
		}

		$email = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		if ( ! is_email( $email ) ) {
			wp_safe_redirect( add_query_arg( 'gas_error', rawurlencode( 'Please enter a valid email.' ), $redirect_back ) );
			exit;
		}

		global $wpdb;
		$magnets_table = GAS_DB::table( 'lead_magnets' );
		$magnet        = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$magnets_table} WHERE id = %d AND active = 1", $magnet_id ) );
		if ( ! $magnet ) {
			wp_die( 'That download is no longer available.' );
		}

		self::upsert( $email, 'customer', array( 'source' => 'lead_magnet', 'related_table' => 'lead_magnets', 'related_id' => $magnet_id ) );

		$wpdb->query( $wpdb->prepare( "UPDATE {$magnets_table} SET download_count = download_count + 1 WHERE id = %d", $magnet_id ) );

		$download_url = wp_get_attachment_url( $magnet->attachment_id );
		wp_mail(
			$email,
			'Your download: ' . $magnet->title,
			"Here's your download link:\n\n{$download_url}\n\nThanks for your interest!"
		);

		wp_safe_redirect( add_query_arg( 'gas_magnet_sent', $magnet_id, $redirect_back ) );
		exit;
	}
}
