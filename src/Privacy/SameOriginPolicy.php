<?php
/**
 * Same-origin scan URL policy.
 *
 * @package CoderEmbassy\AWAC
 */

namespace CoderEmbassy\AWAC\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Prevents scan runners from being used as cross-origin request proxies.
 */
final class SameOriginPolicy {
	/**
	 * Determine whether an absolute candidate URL has the exact same origin as
	 * the configured site URL.
	 *
	 * Origin comparison includes normalized scheme, host, and effective port.
	 * URLs containing embedded credentials are always rejected.
	 *
	 * @param string $candidate Candidate scan URL.
	 * @param string $site_url  Trusted WordPress site URL.
	 * @return bool
	 */
	public static function allows( $candidate, $site_url ) {
		$candidate_parts = self::parse_http_url( $candidate );
		$site_parts      = self::parse_http_url( $site_url );

		if ( null === $candidate_parts || null === $site_parts ) {
			return false;
		}

		if ( isset( $candidate_parts['user'] ) || isset( $candidate_parts['pass'] ) ) {
			return false;
		}

		return self::origin( $candidate_parts ) === self::origin( $site_parts );
	}

	/**
	 * Validate and privacy-normalize a scan URL for storage.
	 *
	 * @param string $candidate Candidate scan URL.
	 * @param string $site_url  Trusted WordPress site URL.
	 * @return string Empty when the URL is not allowed.
	 */
	public static function url_for_storage( $candidate, $site_url ) {
		if ( ! self::allows( $candidate, $site_url ) ) {
			return '';
		}

		return Redactor::url_for_storage( $candidate );
	}

	/**
	 * Canonicalize an allowlisted URL for exact runner-target comparison.
	 *
	 * Query parameters are sorted so browser and server serialization order does
	 * not create false mismatches. Runner transport parameters may be excluded
	 * without weakening the exact path/query allowlist.
	 *
	 * @param string   $candidate      Candidate scan URL.
	 * @param string   $site_url       Trusted WordPress site URL.
	 * @param string[] $ignored_params Query parameters to remove.
	 * @return string Empty when the candidate is not allowed.
	 */
	public static function canonical_url( $candidate, $site_url, array $ignored_params = array() ) {
		if ( ! self::allows( $candidate, $site_url ) ) {
			return '';
		}

		$parts = self::parse_http_url( $candidate );
		if ( null === $parts ) {
			return '';
		}

		$path = isset( $parts['path'] ) && '' !== $parts['path'] ? (string) $parts['path'] : '/';
		if ( '/' !== substr( $path, 0, 1 ) || preg_match( '/[\x00-\x1F\x7F]/', $path ) ) {
			return '';
		}

		$query = array();
		if ( ! empty( $parts['query'] ) ) {
			wp_parse_str( (string) $parts['query'], $query );
		}

		foreach ( $ignored_params as $ignored_param ) {
			unset( $query[ (string) $ignored_param ] );
		}

		self::sort_query( $query );
		$query_string = http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.urlencode_urlencode -- RFC 3986 encoding is required for a stable signed allowlist value.

		return self::origin_for( $candidate ) . $path . ( '' === $query_string ? '' : '?' . $query_string );
	}

	/**
	 * Return the canonical origin for a trusted HTTP(S) URL.
	 *
	 * @param string $url Trusted URL.
	 * @return string Empty for an invalid URL.
	 */
	public static function origin_for( $url ) {
		$parts = self::parse_http_url( $url );
		if ( null === $parts ) {
			return '';
		}

		$port   = isset( $parts['port'] ) ? (int) $parts['port'] : self::default_port( $parts['scheme'] );
		$origin = $parts['scheme'] . '://' . $parts['host'];

		return self::default_port( $parts['scheme'] ) === $port ? $origin : $origin . ':' . $port;
	}

	/**
	 * Parse an absolute HTTP(S) URL.
	 *
	 * @param string $url URL.
	 * @return array|null
	 */
	private static function parse_http_url( $url ) {
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return null;
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return null;
		}

		$scheme = strtolower( (string) $parts['scheme'] );

		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return null;
		}

		$parts['scheme'] = $scheme;
		$parts['host']   = strtolower( rtrim( (string) $parts['host'], '.' ) );

		return $parts;
	}

	/**
	 * Build a canonical origin tuple.
	 *
	 * @param array $parts Parsed URL.
	 * @return string
	 */
	private static function origin( array $parts ) {
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : self::default_port( $parts['scheme'] );

		return $parts['scheme'] . '://' . $parts['host'] . ':' . $port;
	}

	/**
	 * Get the standard port for a supported scheme.
	 *
	 * @param string $scheme http or https.
	 * @return int
	 */
	private static function default_port( $scheme ) {
		return 'https' === $scheme ? 443 : 80;
	}

	/**
	 * Recursively sort query keys before signing or comparing a URL.
	 *
	 * @param array<string,mixed> $query Query data.
	 * @return void
	 */
	private static function sort_query( array &$query ) {
		ksort( $query );

		foreach ( $query as &$value ) {
			if ( is_array( $value ) ) {
				self::sort_query( $value );
			}
		}
		unset( $value );
	}

	/**
	 * Static-only class.
	 */
	private function __construct() {}
}
