<?php
/**
 * Cart and checkout markup detection.
 *
 * @package CoderEmbassy\AWAC
 */

namespace CoderEmbassy\AWAC\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Distinguishes block markup from classic shortcodes for fix gating.
 */
final class CheckoutMarkup {
	public const BLOCKS  = 'blocks';
	public const CLASSIC = 'classic';
	public const UNKNOWN = 'unknown';

	/**
	 * Detect markup from saved page content.
	 *
	 * Blocks take precedence if mixed content is found because classic-only
	 * remediation must never run blindly against a block-rendered checkout.
	 *
	 * @param string $content   Saved post content.
	 * @param string $page_type cart or checkout.
	 * @return string
	 */
	public static function detect_content( $content, $page_type ) {
		$configuration = self::configuration( $page_type );

		if ( null === $configuration || ! is_string( $content ) ) {
			return self::UNKNOWN;
		}

		if ( self::contains_block( $content, $configuration['block'] ) ) {
			return self::BLOCKS;
		}

		if ( self::contains_shortcode( $content, $configuration['shortcode'] ) ) {
			return self::CLASSIC;
		}

		return self::UNKNOWN;
	}

	/**
	 * Detect the configured WooCommerce cart or checkout page.
	 *
	 * @param string $page_type cart or checkout.
	 * @return string
	 */
	public static function detect_page( $page_type ) {
		if ( ! function_exists( 'wc_get_page_id' ) || ! function_exists( 'get_post_field' ) ) {
			return self::UNKNOWN;
		}

		$page_id = (int) wc_get_page_id( $page_type );

		if ( $page_id <= 0 ) {
			return self::UNKNOWN;
		}

		return self::detect_content( (string) get_post_field( 'post_content', $page_id ), $page_type );
	}

	/**
	 * Whether a classic-markup-only fix may be considered.
	 *
	 * This does not replace issue detection, capability checks, dry-run checks,
	 * licensing, or safe-mode checks.
	 *
	 * @param string $context Detected markup context.
	 * @return bool
	 */
	public static function allows_classic_fix( $context ) {
		return self::CLASSIC === $context;
	}

	/**
	 * Get block and shortcode names for a page type.
	 *
	 * @param string $page_type cart or checkout.
	 * @return array<string, string>|null
	 */
	private static function configuration( $page_type ) {
		$configurations = array(
			'cart'     => array(
				'block'     => 'woocommerce/cart',
				'shortcode' => 'woocommerce_cart',
			),
			'checkout' => array(
				'block'     => 'woocommerce/checkout',
				'shortcode' => 'woocommerce_checkout',
			),
		);

		return isset( $configurations[ $page_type ] ) ? $configurations[ $page_type ] : null;
	}

	/**
	 * Detect a serialized block, using Core's parser when available.
	 *
	 * @param string $content    Saved post content.
	 * @param string $block_name Fully qualified block name.
	 * @return bool
	 */
	private static function contains_block( $content, $block_name ) {
		if ( function_exists( 'has_block' ) ) {
			return has_block( $block_name, $content );
		}

		return false !== strpos( $content, '<!-- wp:' . $block_name );
	}

	/**
	 * Detect a classic shortcode, using Core's parser when available.
	 *
	 * @param string $content   Saved post content.
	 * @param string $shortcode Shortcode tag.
	 * @return bool
	 */
	private static function contains_shortcode( $content, $shortcode ) {
		if ( function_exists( 'has_shortcode' ) ) {
			return has_shortcode( $content, $shortcode );
		}

		return 1 === preg_match( '/\[' . preg_quote( $shortcode, '/' ) . '(?:\s|\]|\/)/', $content );
	}

	/**
	 * Static-only class.
	 */
	private function __construct() {}
}
