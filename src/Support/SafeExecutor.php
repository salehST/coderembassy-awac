<?php
/**
 * Fail-safe operation wrapper.
 *
 * @package CoderEmbassy\AWAC
 */

namespace CoderEmbassy\AWAC\Support;

use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Prevents optional AWAC operations from taking down the storefront.
 */
final class SafeExecutor {
	/**
	 * Run an operation and return a caller-selected fallback on failure.
	 *
	 * @param callable $operation Operation to execute.
	 * @param mixed    $fallback  Failure return value.
	 * @param array    $context   Logging context.
	 * @return mixed
	 */
	public static function run( callable $operation, $fallback = null, array $context = array() ) {
		try {
			return $operation();
		} catch ( Throwable $throwable ) {
			self::log( $throwable, $context );

			return $fallback;
		}
	}

	/**
	 * Log a caught failure without exposing request or scan contents.
	 *
	 * @param Throwable $throwable Failure.
	 * @param array     $context   Non-sensitive context.
	 * @return void
	 */
	private static function log( Throwable $throwable, array $context ) {
		$safe_context = array(
			'source'    => 'awac',
			'exception' => get_class( $throwable ),
		);

		foreach ( array( 'operation', 'component', 'run_id' ) as $allowed_key ) {
			if ( isset( $context[ $allowed_key ] ) && is_scalar( $context[ $allowed_key ] ) ) {
				$safe_context[ $allowed_key ] = (string) $context[ $allowed_key ];
			}
		}

		// Exception messages can contain selectors, URLs, or request data. Never
		// write them to a log from this generic boundary.
		$message = 'AWAC operation failed. See the non-sensitive context and exception class.';

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, $safe_context );

			return;
		}

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Static-only class.
	 */
	private function __construct() {}
}
