<?php
/**
 * Front-end runner bootstrap and admin-bar fallback.
 *
 * @package CoderEmbassy\AWAC
 */

namespace CoderEmbassy\AWAC\Runner;

use CoderEmbassy\AWAC\Privacy\SameOriginPolicy;
use CoderEmbassy\AWAC\REST\RestController;
use CoderEmbassy\AWAC\Scan\PageDiscovery;

defined( 'ABSPATH' ) || exit;

/**
 * Loads runner code only for an explicit signed/admin action.
 */
final class FrontEndRunner {
	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_bar_menu', array( self::class, 'add_admin_bar_item' ), 90 );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue' ), 1 );
		add_action( 'send_headers', array( self::class, 'send_runner_headers' ), 1 );
	}

	/**
	 * Add the authenticated same-page fallback.
	 *
	 * @param \WP_Admin_Bar $admin_bar WordPress admin bar.
	 * @return void
	 */
	public static function add_admin_bar_item( $admin_bar ) {
		if ( is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$current = self::current_url( array( 'ceaw_runner', 'ceaw_adminbar', 'ceaw_admin_preview', 'ceaw_admin_preview_id' ) );
		if ( '' === $current ) {
			return;
		}

		$admin_bar->add_node(
			array(
				'id'    => 'ceaw-scan-this-page',
				'title' => esc_html__( 'Scan this page', 'coderembassy-awac' ),
				'href'  => esc_url( add_query_arg( 'ceaw_adminbar', '1', $current ) ),
				'meta'  => array(
					'title' => esc_attr__( 'Open the AWAC admin-bar runner for this page', 'coderembassy-awac' ),
				),
			)
		);
	}

	/**
	 * Enqueue the small runner client and its scoped status panel.
	 *
	 * @return void
	 */
	public static function enqueue() {
		$mode          = self::request_mode();
		$token         = self::request_token();
		$claims        = '' === $token ? null : RunnerStore::inspect_invitation( $token );
		$admin_preview = isset( $_GET['ceaw_admin_preview'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is a transport selector; the authenticated parent redeems the invitation.
			? '1' === sanitize_text_field( wp_unslash( $_GET['ceaw_admin_preview'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is a transport selector; the authenticated parent redeems the invitation.
			: false;

		if ( '' === $mode ) {
			return;
		}

		$asset_file = CEAW_PLUGIN_DIR . 'build/runner.asset.php';
		$script     = CEAW_PLUGIN_DIR . 'build/runner.js';
		$style      = CEAW_PLUGIN_DIR . 'build/style-runner.css';
		$axe_script = CEAW_PLUGIN_DIR . 'assets/vendor/axe/axe.min.js';
		if ( ! is_readable( $asset_file ) || ! is_readable( $script ) || ! is_readable( $style ) || ! is_readable( $axe_script ) ) {
			return;
		}

		$asset = include $asset_file;
		$asset = is_array( $asset ) ? $asset : array();
		ScanHygiene::mark_active();

		wp_enqueue_style( 'ceaw-runner', CEAW_PLUGIN_URL . 'build/style-runner.css', array(), (string) filemtime( $style ) );
		wp_enqueue_script( 'ceaw-axe', CEAW_PLUGIN_URL . 'assets/vendor/axe/axe.min.js', array(), CEAW_AXE_VERSION, false );
		wp_enqueue_script(
			'ceaw-runner',
			CEAW_PLUGIN_URL . 'build/runner.js',
			array_merge( array( 'ceaw-axe' ), isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array() ),
			isset( $asset['version'] ) ? (string) $asset['version'] : CEAW_VERSION,
			false
		);
		wp_add_inline_script(
			'ceaw-runner',
			'window.ceawRunnerBoot = ' . wp_json_encode(
				array(
					'restUrl'      => untrailingslashit( rest_url( RestController::NAMESPACE ) ),
					'nonce'        => 'adminbar' === $mode ? wp_create_nonce( 'wp_rest' ) : '',
					'mode'         => $mode,
					'adminPreview' => $admin_preview,
					'token'        => $token,
					'invitationId' => is_array( $claims ) ? (string) $claims['jti'] : '',
					'pageUrl'      => self::current_url( array( 'ceaw_runner', 'ceaw_adminbar', 'ceaw_admin_preview', 'ceaw_admin_preview_id' ) ),
					'parentOrigin' => SameOriginPolicy::origin_for( home_url( '/' ) ),
					'viewport'     => 'desktop',
					'pageType'     => PageDiscovery::classify_url( self::current_url( array( 'ceaw_runner', 'ceaw_adminbar', 'ceaw_admin_preview', 'ceaw_admin_preview_id' ) ) ),
					'axeVersion'   => CEAW_AXE_VERSION,
					'phase'        => 4,
				)
			) . ';',
			'before'
		);
		wp_set_script_translations( 'ceaw-runner', 'coderembassy-awac', CEAW_PLUGIN_DIR . 'languages' );
	}

	/**
	 * Prevent runner tokens from leaking through referrers or caches.
	 *
	 * @return void
	 */
	public static function send_runner_headers() {
		if ( '' === self::request_mode() ) {
			return;
		}

		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
	}

	/**
	 * Determine the explicit runner mode for this request.
	 *
	 * @return string
	 */
	private static function request_mode() {
		$token = self::request_token();
		if ( '' !== $token ) {
			$claims = RunnerStore::inspect_invitation( $token );
			// Invalid bearer input must not activate scan hygiene or enqueue the
			// runner on an ordinary storefront request.
			return is_array( $claims ) ? (string) $claims['mode'] : '';
		}

		$adminbar = isset( $_GET['ceaw_adminbar'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is a transport selector; the mutating REST request verifies its nonce.
			? sanitize_text_field( wp_unslash( $_GET['ceaw_adminbar'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This is a transport selector; the mutating REST request verifies its nonce.
			: '';

		return '1' === $adminbar && current_user_can( 'manage_woocommerce' )
			? 'adminbar'
			: '';
	}

	/**
	 * Read the signed runner token.
	 *
	 * @return string
	 */
	private static function request_token() {
		return isset( $_GET['ceaw_runner'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This signed bearer token is the runner's transport credential.
			? sanitize_text_field( wp_unslash( $_GET['ceaw_runner'] ) ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- This signed bearer token is the runner's transport credential.
			: '';
	}

	/**
	 * Build the exact current same-origin URL from the trusted site origin.
	 *
	 * @param string[] $ignored_params Transport parameters to remove.
	 * @return string
	 */
	private static function current_url( array $ignored_params ) {
		$request_uri = isset( $_SERVER['REQUEST_URI'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
			: '/';
		$candidate   = SameOriginPolicy::origin_for( home_url( '/' ) ) . $request_uri;

		return SameOriginPolicy::canonical_url( $candidate, home_url( '/' ), $ignored_params );
	}

	/**
	 * Static-only class.
	 */
	private function __construct() {}
}
