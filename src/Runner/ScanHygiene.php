<?php
/**
 * Scan-session isolation and side-effect suppression.
 *
 * @package CoderEmbassy\AWAC
 */

namespace CoderEmbassy\AWAC\Runner;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps AWAC traffic out of the shopper session and blocks stock mutation.
 */
final class ScanHygiene {
	const CONTEXT_COOKIE = 'ceaw_scan_session';

	/**
	 * Request-local active marker.
	 *
	 * @var bool
	 */
	private static $active = false;

	/**
	 * Register supported WooCommerce suppression boundaries.
	 *
	 * @return void
	 */
	public static function register() {
		add_filter( 'woocommerce_cookie', array( self::class, 'isolate_session_cookie' ), 1 );
		add_filter( 'woocommerce_can_reduce_order_stock', array( self::class, 'block_when_active' ), 1 );
		add_filter( 'woocommerce_payment_complete_reduce_order_stock', array( self::class, 'block_when_active' ), 1 );
		add_filter( 'woocommerce_hold_stock_for_checkout', array( self::class, 'block_when_active' ), 1 );
		add_filter( 'wp_headers', array( self::class, 'scan_headers' ) );
	}

	/**
	 * Activate hygiene for this request.
	 *
	 * @return void
	 */
	public static function mark_active() {
		self::$active = true;
	}

	/**
	 * Whether this request belongs to a verified scan session.
	 *
	 * @return bool
	 */
	public static function is_active() {
		if ( self::$active ) {
			return true;
		}

		$credential = self::context_credential();
		if ( '' === $credential ) {
			return false;
		}

		self::$active = null !== RunnerStore::credential_record( $credential );

		return self::$active;
	}

	/**
	 * Start a short-lived isolated WooCommerce session context.
	 *
	 * @param string $credential Signed runner credential.
	 * @param int    $expires_at Expiry timestamp.
	 * @return void
	 */
	public static function begin( $credential, $expires_at ) {
		self::mark_active();

		if ( function_exists( 'wc_setcookie' ) ) {
			wc_setcookie( self::CONTEXT_COOKIE, $credential, (int) $expires_at, is_ssl(), true );
		}
	}

	/**
	 * Clear the isolated cart and both scan cookies after a run.
	 *
	 * @param string $credential Signed runner credential.
	 * @return void
	 */
	public static function cleanup( $credential ) {
		self::mark_active();

		if ( function_exists( 'WC' ) && WC() ) {
			if ( WC()->cart ) {
				WC()->cart->empty_cart( false );
			}
			if ( WC()->session && is_callable( array( WC()->session, 'destroy_session' ) ) ) {
				WC()->session->destroy_session();
			}
		}

		if ( function_exists( 'wc_setcookie' ) ) {
			wc_setcookie( self::CONTEXT_COOKIE, '', time() - HOUR_IN_SECONDS, is_ssl(), true );
			wc_setcookie( self::isolated_cookie_name( $credential ), '', time() - HOUR_IN_SECONDS, is_ssl(), true );
		}
	}

	/**
	 * Use a credential-specific WooCommerce cookie instead of the shopper cart.
	 *
	 * @param string $cookie_name Default WooCommerce session cookie name.
	 * @return string
	 */
	public static function isolate_session_cookie( $cookie_name ) {
		$credential = self::context_credential();

		return '' === $credential || null === RunnerStore::credential_record( $credential )
			? $cookie_name
			: self::isolated_cookie_name( $credential );
	}

	/**
	 * Block a supported WooCommerce side effect during scan traffic.
	 *
	 * @param bool $allowed Current decision.
	 * @return bool
	 */
	public static function block_when_active( $allowed ) {
		return self::is_active() ? false : $allowed;
	}

	/**
	 * Add an integration-readable scan marker without exposing credentials.
	 *
	 * @param array<string,string> $headers Response headers.
	 * @return array<string,string>
	 */
	public static function scan_headers( array $headers ) {
		if ( self::is_active() ) {
			$headers['X-CEAW-Scan-Session'] = '1';
		}

		return $headers;
	}

	/**
	 * Read the HTTP-only context credential.
	 *
	 * @return string
	 */
	private static function context_credential() {
		return isset( $_COOKIE[ self::CONTEXT_COOKIE ] )
			? sanitize_text_field( wp_unslash( $_COOKIE[ self::CONTEXT_COOKIE ] ) )
			: '';
	}

	/**
	 * Build the isolated WooCommerce session cookie name.
	 *
	 * @param string $credential Signed credential.
	 * @return string
	 */
	private static function isolated_cookie_name( $credential ) {
		return 'wp_woocommerce_session_' . COOKIEHASH . '_ceaw_' . substr( hash( 'sha256', $credential ), 0, 12 );
	}

	/**
	 * Static-only class.
	 */
	private function __construct() {}
}
