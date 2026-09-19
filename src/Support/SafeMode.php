<?php
/**
 * Remediation safe-mode state.
 *
 * @package CoderEmbassy\AWAC
 */

namespace CoderEmbassy\AWAC\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Centralizes the CEAW_SAFE_MODE gate.
 */
final class SafeMode {
	/**
	 * Register the administrator-facing safe-mode notice.
	 *
	 * @return void
	 */
	public static function register_notice() {
		add_action( 'admin_notices', array( self::class, 'render_notice' ) );
	}

	/**
	 * Make the global remediation shutdown visible to store managers.
	 *
	 * @return void
	 */
	public static function render_notice() {
		if ( ! self::is_active() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'CoderEmbassy AWAC safe mode is active. All remediation features are inert; auditing and reports remain available.', 'coderembassy-awac' );
		echo '</p></div>';
	}

	/**
	 * Determine whether all AWAC fixes must remain inert.
	 *
	 * @return bool
	 */
	public static function is_active() {
		return defined( 'CEAW_SAFE_MODE' ) && true === CEAW_SAFE_MODE;
	}

	/**
	 * Determine whether a remediation operation may be attempted.
	 *
	 * Licensing and authorization are separate checks.
	 *
	 * @return bool
	 */
	public static function allows_remediation() {
		return ! self::is_active();
	}

	/**
	 * Static-only class.
	 */
	private function __construct() {}
}
