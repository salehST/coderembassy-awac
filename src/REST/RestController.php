<?php
/**
 * Initial AWAC REST API.
 *
 * @package CoderEmbassy\AWAC
 */

namespace CoderEmbassy\AWAC\REST;

use CoderEmbassy\AWAC\Runner\SafeProductPolicy;
use CoderEmbassy\AWAC\Settings\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Registers Phase 2 settings and scan-run routes.
 */
final class RestController {
	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'ceaw/v1';

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register all Phase 2 routes with explicit argument schemas.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_settings' ),
					'permission_callback' => 'ceaw_rest_can_manage',
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'update_settings' ),
					'permission_callback' => 'ceaw_rest_can_manage',
					'args'                => self::settings_args(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/scan-runs',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_scan_runs' ),
				'permission_callback' => 'ceaw_rest_can_manage',
				'args'                => self::scan_run_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/issues',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_issues' ),
				'permission_callback' => 'ceaw_rest_can_manage',
				'args'                => self::issue_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/issues/summary',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_issue_summary' ),
				'permission_callback' => 'ceaw_rest_can_manage',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/issues/(?P<issue_id>\d+)/dismiss',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'dismiss_issue' ),
				'permission_callback' => 'ceaw_rest_can_manage',
				'args'                => self::dismiss_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/issues/(?P<issue_id>\d+)/reinstate',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'reinstate_issue' ),
				'permission_callback' => 'ceaw_rest_can_manage',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/issues/(?P<issue_id>\d+)/dismissals',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_dismissals' ),
				'permission_callback' => 'ceaw_rest_can_manage',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/manual-checks',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_manual_checks' ),
					'permission_callback' => 'ceaw_rest_can_manage',
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( self::class, 'save_manual_check' ),
					'permission_callback' => 'ceaw_rest_can_manage',
					'args'                => self::manual_check_args(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/manual-checks/(?P<check_key>[a-z0-9_-]+)/history',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'get_manual_check_history' ),
				'permission_callback' => 'ceaw_rest_can_manage',
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/statement',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( self::class, 'get_statement' ),
					'permission_callback' => 'ceaw_rest_can_manage',
				),
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( self::class, 'save_statement' ),
					'permission_callback' => 'ceaw_rest_can_manage',
					'args'                => self::statement_args(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/statement/publish',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'publish_statement' ),
				'permission_callback' => 'ceaw_rest_can_manage',
				'args'                => self::statement_args(),
			)
		);
	}

	/**
	 * Return current settings.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_settings() {
		return rest_ensure_response( self::settings_payload( Settings::get() ) );
	}

	/**
	 * Apply a partial settings update.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function update_settings( $request ) {
		$patch = array();
		foreach ( array_keys( self::settings_args() ) as $key ) {
			if ( $request->has_param( $key ) ) {
				$patch[ $key ] = $request->get_param( $key );
			}
		}

		if ( isset( $patch['safe_product_id'] ) && (int) $patch['safe_product_id'] > 0 && ! SafeProductPolicy::allows_id( $patch['safe_product_id'] ) ) {
			return new \WP_Error(
				'ceaw_safe_product_invalid',
				__( 'Choose a published, in-stock simple product without backorders or purchase restrictions.', 'coderembassy-awac' ),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response( self::settings_payload( Settings::update( $patch ) ) );
	}

	/**
	 * Return filtered scan-run history.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function get_scan_runs( $request ) {
		$query = array();
		foreach ( self::scan_run_args() as $key => $schema ) {
			$query[ $key ] = $request->get_param( $key );
			if ( null === $query[ $key ] && array_key_exists( 'default', $schema ) ) {
				$query[ $key ] = $schema['default'];
			}
		}

		return rest_ensure_response( ScanRunRepository::query( $query ) );
	}

	/**
	 * Return filtered canonical issues.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function get_issues( $request ) {
		$query = array();
		foreach ( array_keys( self::issue_args() ) as $key ) {
			$query[ $key ] = $request->get_param( $key );
		}

		return rest_ensure_response( IssueRepository::query( $query ) );
	}

	/**
	 * Return honest issue metrics and a seven-day trend.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_issue_summary() {
		return rest_ensure_response( IssueRepository::summary() );
	}

	/**
	 * Record an issue dismissal.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function dismiss_issue( $request ) {
		$result = IssueRepository::dismiss( $request->get_param( 'issue_id' ), get_current_user_id(), $request->get_param( 'note' ) );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Reinstate an issue.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function reinstate_issue( $request ) {
		$result = IssueRepository::reinstate( $request->get_param( 'issue_id' ), get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Return the dismissal audit log for an issue.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function get_dismissals( $request ) {
		return rest_ensure_response( array( 'items' => IssueRepository::dismissals( $request->get_param( 'issue_id' ) ) ) );
	}

	/**
	 * Return the guided manual checklist and summary.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_manual_checks() {
		return rest_ensure_response( ManualCheckRepository::all() );
	}

	/**
	 * Store one manual-check result.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_manual_check( $request ) {
		$result = ManualCheckRepository::save(
			$request->get_param( 'check_key' ),
			$request->get_param( 'status' ),
			$request->get_param( 'notes' ),
			$request->get_param( 'evidence_ref' ),
			get_current_user_id()
		);

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Return immutable history for one manual check.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public static function get_manual_check_history( $request ) {
		return rest_ensure_response( array( 'items' => ManualCheckRepository::history( $request->get_param( 'check_key' ) ) ) );
	}

	/**
	 * Return the editable statement draft.
	 *
	 * @return WP_REST_Response
	 */
	public static function get_statement() {
		return rest_ensure_response( StatementRepository::get() );
	}

	/**
	 * Save a statement draft.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function save_statement( $request ) {
		$input  = self::statement_input( $request );
		$result = StatementRepository::save( $input, get_current_user_id() );

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Publish the statement as a WordPress page.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function publish_statement( $request ) {
		if ( ! current_user_can( 'publish_pages' ) ) {
			return new \WP_Error( 'ceaw_statement_publish_forbidden', __( 'Your account can edit the statement but cannot publish WordPress pages.', 'coderembassy-awac' ), array( 'status' => 403 ) );
		}

		$result = StatementRepository::publish( self::statement_input( $request ), get_current_user_id() );
		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Manual-check request schema.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function manual_check_args() {
		return array(
			'check_key'    => array(
				'type'              => 'string',
				'pattern'           => '^[a-z0-9_-]+$',
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
			),
			'status'       => self::required_enum_arg( ManualCheckRepository::STATUSES ),
			'notes'        => array(
				'type'              => 'string',
				'maxLength'         => 4000,
				'default'           => '',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'evidence_ref' => array(
				'type'              => 'string',
				'maxLength'         => 2000,
				'default'           => '',
				'sanitize_callback' => 'sanitize_textarea_field',
			),
		);
	}

	/**
	 * Statement request schema.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function statement_args() {
		return array(
			'organization_name'     => array(
				'type'              => 'string',
				'maxLength'         => 191,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'website_url'           => array(
				'type'              => 'string',
				'format'            => 'uri',
				'maxLength'         => 2048,
				'sanitize_callback' => 'esc_url_raw',
			),
			'contact_email'         => array(
				'type'              => 'string',
				'format'            => 'email',
				'maxLength'         => 191,
				'sanitize_callback' => 'sanitize_email',
			),
			'last_reviewed'         => array(
				'type'              => 'string',
				'maxLength'         => 10,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'known_limitations'     => array(
				'type'              => 'string',
				'maxLength'         => 2000,
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'feedback_instructions' => array(
				'type'              => 'string',
				'maxLength'         => 2000,
				'sanitize_callback' => 'sanitize_textarea_field',
			),
			'content'               => array(
				'type'              => 'string',
				'maxLength'         => 30000,
				'sanitize_callback' => 'wp_kses_post',
			),
			'regenerate'            => array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
		);
	}

	/**
	 * Extract statement input without passing REST metadata to the repository.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return array<string,mixed>
	 */
	private static function statement_input( $request ) {
		$input = array();
		foreach ( array_keys( self::statement_args() ) as $key ) {
			if ( $request->has_param( $key ) ) {
				$input[ $key ] = $request->get_param( $key );
			}
		}

		return $input;
	}

	/**
	 * Settings route argument schema.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function settings_args() {
		return array(
			'retention_days'   => array(
				'description'       => __( 'Days to retain scan data.', 'coderembassy-awac' ),
				'type'              => 'integer',
				'minimum'           => 30,
				'maximum'           => 3650,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'default_viewport' => array(
				'description'       => __( 'Default viewport used by future scan runners.', 'coderembassy-awac' ),
				'type'              => 'string',
				'enum'              => Settings::VIEWPORTS,
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'safe_product_id'  => array(
				'description'       => __( 'Safe simple product reserved for isolated synthetic cart checks.', 'coderembassy-awac' ),
				'type'              => 'integer',
				'minimum'           => 0,
				'default'           => 0,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Scan-run route argument schema.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function scan_run_args() {
		return array(
			'page'     => array(
				'type'              => 'integer',
				'minimum'           => 1,
				'default'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'per_page' => array(
				'type'              => 'integer',
				'minimum'           => 1,
				'maximum'           => 100,
				'default'           => 20,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'status'   => self::optional_enum_arg( array( 'pending', 'running', 'completed', 'failed', 'cancelled' ) ),
			'profile'  => self::optional_enum_arg( array( 'guest', 'admin' ) ),
			'mode'     => self::optional_enum_arg( array( 'token', 'iframe', 'adminbar' ) ),
			'order'    => array(
				'type'              => 'string',
				'enum'              => array( 'asc', 'desc' ),
				'default'           => 'desc',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Issue query arguments.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function issue_args() {
		return array(
			'page'             => array(
				'type'              => 'integer',
				'minimum'           => 1,
				'default'           => 1,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'per_page'         => array(
				'type'              => 'integer',
				'minimum'           => 1,
				'maximum'           => 100,
				'default'           => 20,
				'sanitize_callback' => 'absint',
				'validate_callback' => 'rest_validate_request_arg',
			),
			'lifecycle_status' => self::optional_enum_arg( array( 'open', 'resolved', 'regressed' ) ),
			'rule_source'      => self::optional_enum_arg( array( 'axe', 'ceaw' ) ),
			'impact'           => self::optional_enum_arg( array( 'critical', 'serious', 'moderate', 'minor' ) ),
			'disposition'      => self::optional_enum_arg( array( 'actionable', 'dismissed', 'accepted', 'needs_review' ) ),
			'profile'          => self::optional_enum_arg( array( 'guest', 'admin' ) ),
			'order'            => array(
				'type'              => 'string',
				'enum'              => array( 'asc', 'desc' ),
				'default'           => 'desc',
				'sanitize_callback' => 'sanitize_key',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Dismissal request arguments.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function dismiss_args() {
		return array(
			'note' => array(
				'type'              => 'string',
				'minLength'         => 3,
				'maxLength'         => 1000,
				'required'          => true,
				'sanitize_callback' => 'sanitize_textarea_field',
				'validate_callback' => 'rest_validate_request_arg',
			),
		);
	}

	/**
	 * Build an optional enum argument definition.
	 *
	 * @param string[] $values Allowed values.
	 * @return array<string,mixed>
	 */
	private static function optional_enum_arg( array $values ) {
		return array(
			'type'              => 'string',
			'enum'              => $values,
			'sanitize_callback' => 'sanitize_key',
			'validate_callback' => 'rest_validate_request_arg',
		);
	}

	/**
	 * Build a required enum argument definition.
	 *
	 * @param string[] $values Allowed values.
	 * @return array<string,mixed>
	 */
	private static function required_enum_arg( array $values ) {
		return array(
			'type'              => 'string',
			'enum'              => $values,
			'required'          => true,
			'sanitize_callback' => 'sanitize_key',
			'validate_callback' => 'rest_validate_request_arg',
		);
	}

	/**
	 * Wrap settings with stable metadata for the shell.
	 *
	 * @param array<string,mixed> $settings Normalized settings.
	 * @return array<string,mixed>
	 */
	private static function settings_payload( array $settings ) {
		return array(
			'settings' => $settings,
			'meta'     => array(
				'version'  => CEAW_VERSION,
				'safeMode' => (bool) ceaw_is_safe_mode(),
			),
		);
	}

	/**
	 * Static-only class.
	 */
	private function __construct() {}
}
