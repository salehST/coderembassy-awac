<?php
/**
 * Safe test-product policy.
 *
 * @package CoderEmbassy\AWAC
 */

namespace CoderEmbassy\AWAC\Runner;

defined( 'ABSPATH' ) || exit;

/**
 * Excludes product types and states that are unsafe for synthetic carts.
 */
final class SafeProductPolicy {
	/**
	 * Evaluate a normalized product description.
	 *
	 * @param array<string,mixed> $product Product facts.
	 * @return bool
	 */
	public static function allows( array $product ) {
		return 'simple' === ( $product['type'] ?? '' )
			&& 'publish' === ( $product['status'] ?? '' )
			&& ! empty( $product['purchasable'] )
			&& ! empty( $product['in_stock'] )
			&& empty( $product['backorders'] )
			&& empty( $product['sold_individually'] )
			&& empty( $product['restricted'] );
	}

	/**
	 * Determine whether a WooCommerce product ID is safe.
	 *
	 * @param int $product_id Product ID.
	 * @return bool
	 */
	public static function allows_id( $product_id ) {
		if ( ! function_exists( 'wc_get_product' ) || absint( $product_id ) < 1 ) {
			return false;
		}

		$product = wc_get_product( absint( $product_id ) );
		if ( ! $product ) {
			return false;
		}

		return self::allows(
			array(
				'type'              => $product->get_type(),
				'status'            => $product->get_status(),
				'purchasable'       => $product->is_purchasable(),
				'in_stock'          => $product->is_in_stock(),
				'backorders'        => $product->backorders_allowed(),
				'sold_individually' => $product->is_sold_individually(),
				'restricted'        => 'hidden' === $product->get_catalog_visibility() || post_password_required( $product->get_id() ),
			)
		);
	}

	/**
	 * Return a bounded list for the Settings selector.
	 *
	 * @return array<int,array{id:int,name:string,type:string}>
	 */
	public static function options() {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return array();
		}

		$products = wc_get_products(
			array(
				'limit'   => 100,
				'orderby' => 'name',
				'order'   => 'ASC',
				'status'  => 'publish',
				'type'    => 'simple',
			)
		);
		$options  = array();

		foreach ( $products as $product ) {
			if ( self::allows_id( $product->get_id() ) ) {
				$options[] = array(
					'id'   => (int) $product->get_id(),
					'name' => sanitize_text_field( $product->get_name() ),
					'type' => sanitize_key( $product->get_type() ),
				);
			}
		}

		return $options;
	}

	/**
	 * Static-only class.
	 */
	private function __construct() {}
}
