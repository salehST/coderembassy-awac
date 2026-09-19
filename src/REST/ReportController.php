<?php
/**
 * Free accessible progress-report REST contract.
 *
 * @package CoderEmbassy\AWAC
 */

namespace CoderEmbassy\AWAC\REST;

use CoderEmbassy\AWAC\Reports\ReportRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Supplies the evidence payload used by the semantic Reports workspace.
 */
final class ReportController {
	/**
	 * Register REST hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register the Free-safe report route.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			RestController::NAMESPACE,
			'/report',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_report' ),
				'permission_callback' => 'ceaw_rest_can_manage',
			)
		);
	}

	/**
	 * Return the current evidence report.
	 *
	 * @return \WP_REST_Response
	 */
	public static function get_report() {
		return rest_ensure_response( ReportRepository::build() );
	}
}
