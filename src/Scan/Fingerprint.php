<?php
/**
 * Stable issue fingerprinting.
 *
 * @package CoderEmbassy\AWAC
 */

namespace CoderEmbassy\AWAC\Scan;

use CoderEmbassy\AWAC\Privacy\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Creates page-specific issue identities for regression tracking.
 */
final class Fingerprint {
	/**
	 * Create a SHA-256 issue fingerprint.
	 *
	 * @param string $normalized_page_url Privacy-normalized page URL.
	 * @param string $page_type          Page type.
	 * @param string $scenario           Runner scenario.
	 * @param string $rule_source        axe or ceaw.
	 * @param string $rule_id            Rule identifier.
	 * @param string $primary_target     Normalized primary target/selector.
	 * @return string
	 */
	public static function create( $normalized_page_url, $page_type, $scenario, $rule_source, $rule_id, $primary_target ) {
		$parts = array(
			Redactor::page_key( $normalized_page_url ),
			self::normalize_token( $page_type ),
			self::normalize_token( $scenario ),
			self::normalize_token( $rule_source ),
			self::normalize_token( $rule_id ),
			self::normalize_target( $primary_target ),
		);

		return hash( 'sha256', implode( "\x1f", $parts ) );
	}

	/**
	 * Normalize an enum-like token.
	 *
	 * @param string $value Token.
	 * @return string
	 */
	private static function normalize_token( $value ) {
		return strtolower( trim( (string) $value ) );
	}

	/**
	 * Normalize selector whitespace while preserving case-sensitive names.
	 *
	 * @param string $target Selector or target path.
	 * @return string
	 */
	private static function normalize_target( $target ) {
		$target = trim( (string) $target );
		$target = preg_replace( '/\s+/u', ' ', $target );

		return is_string( $target ) ? $target : '';
	}

	/**
	 * Static-only class.
	 */
	private function __construct() {}
}
