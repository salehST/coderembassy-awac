<?php
/**
 * Accessible single-page admin entrypoint.
 *
 * @package CoderEmbassy\AWAC
 */

namespace CoderEmbassy\AWAC\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Registers and supplies the AWAC WooCommerce submenu screen.
 */
final class AdminPage {
	/**
	 * WordPress hook suffix for the AWAC screen.
	 *
	 * @var string
	 */
	private static $hook_suffix = '';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_menu', array( self::class, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
	}

	/**
	 * Add one shared React application beneath WooCommerce.
	 *
	 * @return void
	 */
	public static function add_menu() {
		self::$hook_suffix = (string) add_submenu_page(
			'woocommerce',
			__( 'CoderEmbassy AWAC', 'coderembassy-awac' ),
			__( 'AWAC', 'coderembassy-awac' ),
			'manage_woocommerce',
			'coderembassy-awac',
			array( self::class, 'render' )
		);
	}

	/**
	 * Render the single application mount.
	 *
	 * @return void
	 */
	public static function render() {
		echo '<div class="wrap ceaw-admin-wrap">';
		echo '<div id="ceaw-app" class="ceaw-app">';
		echo '<p>' . esc_html__( 'Loading CoderEmbassy AWAC...', 'coderembassy-awac' ) . '</p>';
		echo '</div></div>';
	}

	/**
	 * Enqueue compiled assets only on the AWAC screen.
	 *
	 * @param string $hook_suffix Current screen hook suffix.
	 * @return void
	 */
	public static function enqueue_assets( $hook_suffix ) {
		if ( self::$hook_suffix !== $hook_suffix ) {
			return;
		}

		$asset_file  = CEAW_PLUGIN_DIR . 'build/index.asset.php';
		$script_file = CEAW_PLUGIN_DIR . 'build/index.js';
		$style_file  = CEAW_PLUGIN_DIR . 'build/style-index.css';

		if ( ! is_readable( $asset_file ) || ! is_readable( $script_file ) || ! is_readable( $style_file ) ) {
			add_action( 'admin_notices', array( self::class, 'render_missing_assets_notice' ) );
			return;
		}

		$asset = include $asset_file;
		$asset = is_array( $asset ) ? $asset : array();

		wp_enqueue_script(
			'ceaw-admin',
			CEAW_PLUGIN_URL . 'build/index.js',
			isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array(),
			isset( $asset['version'] ) ? (string) $asset['version'] : CEAW_VERSION,
			true
		);
		// Refresh PHP's stat cache so a rebuilt stylesheet always receives a new
		// WordPress asset version, including on long-running local servers.
		clearstatcache( true, $style_file );

		wp_enqueue_style(
			'ceaw-admin',
			CEAW_PLUGIN_URL . 'build/style-index.css',
			array(),
			(string) filemtime( $style_file )
		);
		wp_style_add_data( 'ceaw-admin', 'rtl', 'replace' );

		wp_add_inline_script(
			'ceaw-admin',
			'window.ceawBoot = ' . wp_json_encode( self::boot_data(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';',
			'before'
		);
		wp_set_script_translations( 'ceaw-admin', 'coderembassy-awac', CEAW_PLUGIN_DIR . 'languages' );

		/**
		 * Allow separately packaged add-ons to extend the shared AWAC screen.
		 *
		 * @param string $hook_suffix Current AWAC screen hook suffix.
		 */
		do_action( 'ceaw_admin_assets_enqueued', $hook_suffix );
	}

	/**
	 * Supply JSON-native boot data without wp_localize_script coercion.
	 *
	 * @return array<string,mixed>
	 */
	public static function boot_data() {
		$user = wp_get_current_user();

		return array(
			'restUrl'      => untrailingslashit( rest_url( 'ceaw/v1' ) ),
			'siteUrl'      => home_url( '/' ),
			'siteOrigin'   => \CoderEmbassy\AWAC\Privacy\SameOriginPolicy::origin_for( home_url( '/' ) ),
			'nonce'        => wp_create_nonce( 'wp_rest' ),
			'logoLight'    => CEAW_PLUGIN_URL . 'logo-light.png',
			'logoDark'     => CEAW_PLUGIN_URL . 'logo-dark.png',
			'version'      => CEAW_VERSION,
			'safeMode'     => (bool) ceaw_is_safe_mode(),
			'locale'       => get_user_locale(),
			'capabilities' => array(
				'manageWooCommerce' => current_user_can( 'manage_woocommerce' ),
			),
			'user'         => array(
				'displayName' => (string) $user->display_name,
				'avatarUrl'   => (string) get_avatar_url( $user->ID, array( 'size' => 64 ) ),
			),
		);
	}

	/**
	 * Explain why an unpackaged development checkout cannot render the app.
	 *
	 * @return void
	 */
	public static function render_missing_assets_notice() {
		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'CoderEmbassy AWAC admin assets are missing. Run the production build or install a packaged release.', 'coderembassy-awac' );
		echo '</p></div>';
	}

	/**
	 * Static-only class.
	 */
	private function __construct() {}
}
