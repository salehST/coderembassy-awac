<?php
/**
 * Public helper functions.
 *
 * @package CoderEmbassy\AWAC
 */

use CoderEmbassy\AWAC\Support\SafeExecutor;
use CoderEmbassy\AWAC\Support\SafeMode;

if ( ! function_exists( 'ceaw_rest_can_manage' ) ) {
	/**
	 * Authorize every AWAC REST route with one shared capability check.
	 *
	 * @return true|WP_Error
	 */
	function ceaw_rest_can_manage() {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			return true;
		}

		$message = is_user_logged_in()
			? __( 'You do not have permission to manage WooCommerce accessibility audits.', 'coderembassy-awac' )
			: __( 'Sign in to manage WooCommerce accessibility audits.', 'coderembassy-awac' );

		return new WP_Error(
			'ceaw_rest_forbidden',
			$message,
			array( 'status' => is_user_logged_in() ? 403 : 401 )
		);
	}
}

if ( ! function_exists( 'ceaw_is_safe_mode' ) ) {
	/**
	 * Determine whether remediation safe mode is active.
	 *
	 * @return bool
	 */
	function ceaw_is_safe_mode() {
		return SafeMode::is_active();
	}
}

if ( ! function_exists( 'ceaw_safe' ) ) {
	/**
	 * Run an AWAC operation without allowing it to take down the storefront.
	 *
	 * This is not an authorization boundary. Callers must validate permissions,
	 * nonces, tokens, and safe-mode state before invoking the operation.
	 *
	 * @param callable $operation Operation to execute.
	 * @param mixed    $fallback  Value returned when the operation fails.
	 * @param array    $context   Structured logging context.
	 * @return mixed
	 */
	function ceaw_safe( callable $operation, $fallback = null, array $context = array() ) {
		return SafeExecutor::run( $operation, $fallback, $context );
	}
}
