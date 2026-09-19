<?php
/**
 * WooCommerce feature compatibility declarations.
 *
 * @package CoderEmbassy\AWAC
 */

namespace CoderEmbassy\AWAC\Compatibility;

defined( 'ABSPATH' ) || exit;

/**
 * Declares compatibility without loading WooCommerce classes prematurely.
 */
final class WooCommerceFeatures {
	/**
	 * Register compatibility hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'before_woocommerce_init', array( self::class, 'declare' ) );
	}

	/**
	 * Declare HPOS and Cart/Checkout Blocks compatibility.
	 *
	 * @return void
	 */
	public static function declare() {
		$features_class = '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil';

		if ( ! class_exists( $features_class ) ) {
			return;
		}

		$features_class::declare_compatibility( 'custom_order_tables', CEAW_PLUGIN_FILE, true );
		$features_class::declare_compatibility( 'cart_checkout_blocks', CEAW_PLUGIN_FILE, true );
	}

	/**
	 * Static-only class.
	 */
	private function __construct() {}
}
