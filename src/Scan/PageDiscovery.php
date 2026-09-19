<?php
/**
 * Initial WooCommerce page discovery and URL classification.
 *
 * @package CoderEmbassy\AWAC
 */

namespace CoderEmbassy\AWAC\Scan;

use CoderEmbassy\AWAC\Privacy\Redactor;

defined( 'ABSPATH' ) || exit;

/**
 * Finds a bounded initial purchase-flow page set without crawling remotely.
 */
final class PageDiscovery {
	/**
	 * Return the initial pages AWAC can safely queue.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function initial_pages() {
		$pages = array();
		self::add_page( $pages, home_url( '/' ), 'home' );

		if ( function_exists( 'wc_get_page_id' ) && function_exists( 'get_permalink' ) ) {
			self::add_page( $pages, get_permalink( wc_get_page_id( 'shop' ) ), 'shop' );
			self::add_page( $pages, get_permalink( wc_get_page_id( 'cart' ) ), 'cart' );
			self::add_page( $pages, get_permalink( wc_get_page_id( 'checkout' ) ), 'checkout' );
		}

		if ( function_exists( 'wc_get_products' ) ) {
			foreach ( array( 'simple', 'variable' ) as $type ) {
				$products = wc_get_products(
					array(
						'type'   => $type,
						'status' => 'publish',
						'limit'  => 1,
					)
				);

				if ( is_array( $products ) && ! empty( $products[0] ) && is_object( $products[0] ) && method_exists( $products[0], 'get_permalink' ) ) {
					self::add_page( $pages, $products[0]->get_permalink(), 'product_' . $type );
				}
			}
		}

		return array_values( $pages );
	}

	/**
	 * Classify a same-origin page URL using stable WordPress/WooCommerce data.
	 *
	 * @param string $url Page URL.
	 * @return string
	 */
	public static function classify_url( $url ) {
		$normalized = Redactor::url_for_storage( $url );
		$home       = Redactor::url_for_storage( home_url( '/' ) );

		if ( '' !== $normalized && $normalized === $home ) {
			return 'home';
		}

		if ( function_exists( 'wc_get_page_id' ) && function_exists( 'get_permalink' ) ) {
			$page_types = array(
				'shop'     => wc_get_page_id( 'shop' ),
				'cart'     => wc_get_page_id( 'cart' ),
				'checkout' => wc_get_page_id( 'checkout' ),
			);

			foreach ( $page_types as $type => $page_id ) {
				$page_url = Redactor::url_for_storage( get_permalink( $page_id ) );
				if ( '' !== $normalized && '' !== $page_url && $normalized === $page_url ) {
					return $type;
				}
			}
		}

		if ( function_exists( 'url_to_postid' ) && function_exists( 'wc_get_product' ) ) {
			$product_id = absint( url_to_postid( $url ) );
			$product    = $product_id > 0 ? wc_get_product( $product_id ) : false;
			if ( is_object( $product ) && method_exists( $product, 'is_type' ) ) {
				return $product->is_type( 'variable' ) ? 'product_variable' : 'product_simple';
			}
		}

		return 'other';
	}

	/**
	 * Add one normalized page, suppressing duplicate URLs.
	 *
	 * @param array<int,array<string,string>> $pages Page accumulator.
	 * @param string                          $url Candidate URL.
	 * @param string                          $type Page type.
	 * @return void
	 */
	private static function add_page( array &$pages, $url, $type ) {
		$normalized = Redactor::url_for_storage( $url );
		if ( '' === $normalized ) {
			return;
		}

		foreach ( $pages as $page ) {
			if ( $page['url'] === $normalized ) {
				return;
			}
		}

		$pages[] = array(
			'url'         => $normalized,
			'page_type'   => sanitize_key( $type ),
			'scenario'    => 'initial',
			'viewport'    => 'desktop',
			'markup_mode' => 'unknown',
		);
	}

	/**
	 * Static-only class.
	 */
	private function __construct() {}
}
