<?php
/**
 * Short-lived signed runner token codec.
 *
 * @package CoderEmbassy\AWAC
 */

namespace CoderEmbassy\AWAC\Runner;

defined( 'ABSPATH' ) || exit;

/**
 * Signs compact JSON claims without exposing WordPress nonces or admin access.
 */
final class TokenSigner {
	/**
	 * Sign runner claims.
	 *
	 * @param array<string,mixed> $claims Token claims.
	 * @param string              $secret HMAC secret.
	 * @return string
	 */
	public static function sign( array $claims, $secret ) {
		$payload   = self::base64url_encode( wp_json_encode( $claims ) );
		$signature = hash_hmac( 'sha256', $payload, (string) $secret, true );

		return $payload . '.' . self::base64url_encode( $signature );
	}

	/**
	 * Verify and decode a signed token.
	 *
	 * @param string   $token  Compact token.
	 * @param string   $secret HMAC secret.
	 * @param int|null $now    Current timestamp for deterministic tests.
	 * @return array<string,mixed>|null
	 */
	public static function verify( $token, $secret, $now = null ) {
		if ( ! is_string( $token ) || strlen( $token ) > 4096 ) {
			return null;
		}

		$parts = explode( '.', $token );
		if ( 2 !== count( $parts ) || '' === $parts[0] || '' === $parts[1] ) {
			return null;
		}

		$expected  = hash_hmac( 'sha256', $parts[0], (string) $secret, true );
		$signature = self::base64url_decode( $parts[1] );
		if ( false === $signature || ! hash_equals( $expected, $signature ) ) {
			return null;
		}

		$json = self::base64url_decode( $parts[0] );
		if ( false === $json ) {
			return null;
		}

		$claims = json_decode( $json, true );
		if ( ! is_array( $claims ) || empty( $claims['exp'] ) || empty( $claims['jti'] ) ) {
			return null;
		}

		$current_time = null === $now ? time() : (int) $now;
		if ( (int) $claims['exp'] < $current_time ) {
			return null;
		}

		return $claims;
	}

	/**
	 * Encode binary data for a URL-safe compact token.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	private static function base64url_encode( $value ) {
		return rtrim( strtr( base64_encode( (string) $value ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encoding is part of a signed token format, not obfuscation.
	}

	/**
	 * Decode URL-safe base64 with strict validation.
	 *
	 * @param string $value Encoded value.
	 * @return string|false
	 */
	private static function base64url_decode( $value ) {
		if ( ! preg_match( '/^[A-Za-z0-9_-]+$/', (string) $value ) ) {
			return false;
		}

		$padding = strlen( $value ) % 4;
		if ( $padding > 0 ) {
			$value .= str_repeat( '=', 4 - $padding );
		}

		return base64_decode( strtr( $value, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding is part of a signed token format, not obfuscation.
	}

	/**
	 * Static-only class.
	 */
	private function __construct() {}
}
