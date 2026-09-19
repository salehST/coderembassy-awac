<?php
/**
 * Plugin settings storage and normalization.
 *
 * @package CoderEmbassy\AWAC
 */

namespace CoderEmbassy\AWAC\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Owns the small Phase 2 settings contract.
 */
final class Settings {
	/**
	 * WordPress option name.
	 *
	 * @var string
	 */
	const OPTION_NAME = 'ceaw_settings';

	/**
	 * Supported viewport values.
	 *
	 * @var string[]
	 */
	const VIEWPORTS = array( 'desktop', 'mobile' );

	/**
	 * Get normalized settings.
	 *
	 * @return array{retention_days:int,default_viewport:string,safe_product_id:int}
	 */
	public static function get() {
		return self::normalize( get_option( self::OPTION_NAME, array() ) );
	}

	/**
	 * Apply a validated partial update.
	 *
	 * @param array<string,mixed> $patch Requested changes.
	 * @return array{retention_days:int,default_viewport:string,safe_product_id:int}
	 */
	public static function update( array $patch ) {
		$settings = array_merge( self::get(), self::sanitize_patch( $patch ) );
		$settings = self::normalize( $settings );

		update_option( self::OPTION_NAME, $settings, false );

		return $settings;
	}

	/**
	 * Normalize stored data and fill defaults.
	 *
	 * @param mixed $value Stored value.
	 * @return array{retention_days:int,default_viewport:string,safe_product_id:int}
	 */
	public static function normalize( $value ) {
		$value = is_array( $value ) ? $value : array();
		$days  = isset( $value['retention_days'] ) ? (int) $value['retention_days'] : CEAW_DEFAULT_RETENTION_DAYS;
		$days  = max( 30, min( 3650, $days ) );

		$viewport = isset( $value['default_viewport'] ) ? (string) $value['default_viewport'] : 'desktop';
		if ( ! in_array( $viewport, self::VIEWPORTS, true ) ) {
			$viewport = 'desktop';
		}

		return array(
			'retention_days'   => $days,
			'default_viewport' => $viewport,
			'safe_product_id'  => isset( $value['safe_product_id'] ) ? max( 0, (int) $value['safe_product_id'] ) : 0,
		);
	}

	/**
	 * Keep only recognized patch fields and coerce REST-native values.
	 *
	 * Route validation rejects invalid client input. This second boundary keeps
	 * direct callers and old stored values safe.
	 *
	 * @param array<string,mixed> $patch Requested changes.
	 * @return array<string,int|string>
	 */
	public static function sanitize_patch( array $patch ) {
		$clean = array();

		if ( array_key_exists( 'retention_days', $patch ) ) {
			$clean['retention_days'] = max( 30, min( 3650, (int) $patch['retention_days'] ) );
		}

		if (
			array_key_exists( 'default_viewport', $patch ) &&
			in_array( (string) $patch['default_viewport'], self::VIEWPORTS, true )
		) {
			$clean['default_viewport'] = (string) $patch['default_viewport'];
		}

		if ( array_key_exists( 'safe_product_id', $patch ) ) {
			$clean['safe_product_id'] = max( 0, (int) $patch['safe_product_id'] );
		}

		return $clean;
	}

	/**
	 * Static-only class.
	 */
	private function __construct() {}
}
