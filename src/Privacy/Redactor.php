<?php
/**
 * Scan-result privacy utilities.
 *
 * @package CoderEmbassy\AWAC
 */

namespace CoderEmbassy\AWAC\Privacy;

defined( 'ABSPATH' ) || exit;

/**
 * Removes likely personal and payment data before persistence.
 *
 * Redaction is deliberately conservative. Scan runners must also avoid
 * collecting form values and payment-frame content at the source.
 */
final class Redactor {
	private const REDACTED = '[REDACTED]';

	/**
	 * Redact sensitive values from arbitrary scan text.
	 *
	 * @param string $value Input value.
	 * @return string
	 */
	public static function text( $value ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}

		if ( 1 !== preg_match( '//u', $value ) ) {
			return '';
		}

		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value );
		$value = preg_replace( '/\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b/iu', '[REDACTED_EMAIL]', $value );
		$value = preg_replace(
			'/\b\d{1,6}\s+(?:[\pL\pN.\'\-]+\s+){0,6}(?:street|st|road|rd|avenue|ave|lane|ln|drive|dr|boulevard|blvd|court|ct|place|pl|highway|hwy)\b[^\r\n<,]*/iu',
			'[REDACTED_ADDRESS]',
			$value
		);
		$value = preg_replace_callback(
			'/(?<![\pL\pN])(?:\+?\d[\d\s().\-]{6,}\d)(?![\pL\pN])/u',
			static function ( $matches ) {
				$digits = preg_replace( '/\D+/', '', $matches[0] );

				if ( strlen( $digits ) >= 13 && strlen( $digits ) <= 19 ) {
					return '[REDACTED_PAYMENT_NUMBER]';
				}

				if ( strlen( $digits ) >= 7 && strlen( $digits ) <= 15 ) {
					return '[REDACTED_PHONE]';
				}

				return $matches[0];
			},
			$value
		);

		$sensitive_key = '(?:pass(?:word)?|email|e-mail|phone|tel|address(?:_?\d)?|billing(?:_[a-z0-9_]+)?|shipping(?:_[a-z0-9_]+)?|card(?:_[a-z0-9_]+)?|cc(?:_[a-z0-9_]+)?|cvv|cvc|nonce|token|secret)';
		$value         = preg_replace(
			'/(["\']?' . $sensitive_key . '["\']?\s*(?:=|:)\s*)(["\'])(.*?)(\2)/iu',
			'$1$2' . self::REDACTED . '$4',
			$value
		);
		$value         = preg_replace(
			'/(' . $sensitive_key . '=)[^&\s"\']+/iu',
			'$1' . self::REDACTED,
			$value
		);

		return is_string( $value ) ? $value : '';
	}

	/**
	 * Redact an HTML excerpt and bound its stored size.
	 *
	 * Every form value is removed, even if its field name does not look
	 * sensitive. The returned markup remains untrusted and must be escaped when
	 * rendered.
	 *
	 * @param string $html      HTML excerpt.
	 * @param int    $max_bytes Maximum bytes to retain.
	 * @return string
	 */
	public static function html_excerpt( $html, $max_bytes = 2048 ) {
		if ( ! is_string( $html ) || '' === $html ) {
			return '';
		}

		$html = preg_replace_callback(
			'/<(?:input|option)\b[^>]*>/iu',
			static function ( $matches ) {
				return preg_replace(
					'/\svalue\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/iu',
					' value="' . self::REDACTED . '"',
					$matches[0]
				);
			},
			$html
		);
		$html = preg_replace(
			'/(<textarea\b[^>]*>).*?(<\/textarea\s*>)/isu',
			'$1' . self::REDACTED . '$2',
			$html
		);
		$html = self::text( $html );

		return self::limit_bytes( $html, $max_bytes );
	}

	/**
	 * Normalize a URL for storage without credentials, fragments, or sensitive
	 * query parameters.
	 *
	 * @param string $url URL to normalize.
	 * @return string Empty when the URL is not HTTP(S) or is malformed.
	 */
	public static function url_for_storage( $url ) {
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			return '';
		}

		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( (string) $parts['scheme'] );

		if ( 'http' !== $scheme && 'https' !== $scheme ) {
			return '';
		}

		$host  = strtolower( rtrim( (string) $parts['host'], '.' ) );
		$port  = isset( $parts['port'] ) ? (int) $parts['port'] : null;
		$path  = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
		$query = array();

		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
			$query = self::remove_sensitive_query_values( $query );
			ksort( $query );
		}

		$normalized = $scheme . '://' . $host;

		if ( null !== $port && ! ( 80 === $port && 'http' === $scheme ) && ! ( 443 === $port && 'https' === $scheme ) ) {
			$normalized .= ':' . $port;
		}

		$normalized .= '/' === substr( $path, 0, 1 ) ? $path : '/' . $path;

		if ( ! empty( $query ) ) {
			$normalized .= '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );
		}

		return $normalized;
	}

	/**
	 * Generate the page identity included in issue fingerprints.
	 *
	 * @param string $normalized_url URL previously normalized for storage.
	 * @return string
	 */
	public static function page_key( $normalized_url ) {
		return hash( 'sha256', (string) $normalized_url );
	}

	/**
	 * Recursively remove sensitive query parameters.
	 *
	 * @param array $query Query parameters.
	 * @return array
	 */
	private static function remove_sensitive_query_values( array $query ) {
		foreach ( $query as $key => $value ) {
			if ( self::is_sensitive_key( $key ) ) {
				unset( $query[ $key ] );
				continue;
			}

			if ( is_array( $value ) ) {
				$query[ $key ] = self::remove_sensitive_query_values( $value );
			} elseif ( is_string( $value ) ) {
				$query[ $key ] = self::text( $value );
			}
		}

		return $query;
	}

	/**
	 * Determine whether a field/query key can carry private data.
	 *
	 * @param string|int $key Key.
	 * @return bool
	 */
	private static function is_sensitive_key( $key ) {
		return 1 === preg_match(
			'/(?:pass|email|e-?mail|phone|tel|address|billing|shipping|card|cc|cvv|cvc|nonce|token|secret|auth|customer|session|key)/i',
			(string) $key
		);
	}

	/**
	 * Safely truncate to a byte limit without splitting a UTF-8 sequence.
	 *
	 * @param string $value     Input value.
	 * @param int    $max_bytes Maximum byte length.
	 * @return string
	 */
	private static function limit_bytes( $value, $max_bytes ) {
		$max_bytes = max( 0, (int) $max_bytes );

		if ( strlen( $value ) <= $max_bytes ) {
			return $value;
		}

		if ( function_exists( 'mb_strcut' ) ) {
			return mb_strcut( $value, 0, $max_bytes, 'UTF-8' );
		}

		return substr( $value, 0, $max_bytes );
	}

	/**
	 * Static-only class.
	 */
	private function __construct() {}
}
