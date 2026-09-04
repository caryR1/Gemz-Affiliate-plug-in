<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class GAS_Roles {

	const ROLE = 'gas_affiliate';

	public static function add_role() {
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, 'Affiliate', array( 'read' => true ) );
		}
	}

	public static function remove_role() {
		remove_role( self::ROLE );
	}

	public static function is_affiliate( $user = null ) {
		$user = $user ? $user : wp_get_current_user();
		return $user && in_array( self::ROLE, (array) $user->roles, true );
	}
}
