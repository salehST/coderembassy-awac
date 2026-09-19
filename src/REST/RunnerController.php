<?php
/**
 * Phase 4 browser-runner and scan-result REST contracts.
 *
 * @package CoderEmbassy\AWAC
 */

namespace CoderEmbassy\AWAC\REST;

use CoderEmbassy\AWAC\Runner\RunnerStore;
use CoderEmbassy\AWAC\Runner\SafeProductPolicy;
use CoderEmbassy\AWAC\Runner\ScanHygiene;
use CoderEmbassy\AWAC\Scan\PageDiscovery;
use CoderEmbassy\AWAC\Scan\ScanResultRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Exposes one-time invitations and bounded runner lifecycle events.
 */
final class RunnerController {
	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
	}

	/**
	 * Register runner routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			RestController::NAMESPACE,
			'/runner/invitations',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'create_invitation' ),
				'permission_callback' => 'ceaw_rest_can_manage',
				'args'                => self::invitation_args(),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/runner/redeem',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'redeem' ),
				'permission_callback' => '__return_true',
				'args'                => self::redeem_args(),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/runner/admin-preview/redeem',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'redeem_admin_preview' ),
				'permission_callback' => 'ceaw_rest_can_manage',
				'args'                => self::redeem_args(),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/runner/adminbar',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'create_adminbar_session' ),
				'permission_callback' => 'ceaw_rest_can_manage',
				'args'                => self::adminbar_args(),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/runner/sessions/(?P<session_id>[a-f0-9]{32})/events',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'record_event' ),
				'permission_callback' => '__return_true',
				'args'                => self::event_args(),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/runner/sessions/(?P<session_id>[a-f0-9]{32})/scan',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'submit_scan' ),
				'permission_callback' => '__return_true',
				'args'                => self::scan_args(),
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/runner/safe-products',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'safe_products' ),
				'permission_callback' => 'ceaw_rest_can_manage',
			)
		);

		register_rest_route(
			RestController::NAMESPACE,
			'/scan/initial-pages',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( self::class, 'initial_pages' ),
				'permission_callback' => 'ceaw_rest_can_manage',
			)
		);
	}

	/**
	 * Create an invitation for a private guest or admin iframe runner.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create_invitation( $request ) {
		$result = RunnerStore::create_invitation(
			$request->get_param( 'target_url' ),
			$request->get_param( 'mode' ),
			$request->get_param( 'viewport' ),
			get_current_user_id()
		);

		return is_wp_error( $result ) ? $result : rest_ensure_response( $result );
	}

	/**
	 * Consume a one-time link and establish an isolated scan session.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function redeem( $request ) {
		$claims = RunnerStore::inspect_invitation( $request->get_param( 'token' ) );
		if ( null === $claims ) {
			return new \WP_Error( 'ceaw_runner_invitation_invalid', __( 'This runner link is invalid or has expired.', 'coderembassy-awac' ), array( 'status' => 401 ) );
		}

		if ( 'token' === $claims['mode'] && is_user_logged_in() ) {
			return new \WP_Error( 'ceaw_runner_private_required', __( 'Open this guest runner link in a private or incognito window where you are signed out.', 'coderembassy-awac' ), array( 'status' => 409 ) );
		}

		if ( 'iframe' === $claims['mode'] && ! current_user_can( 'manage_woocommerce' ) ) {
			return new \WP_Error( 'ceaw_runner_admin_required', __( 'Sign in with permission to manage WooCommerce before using this admin preview.', 'coderembassy-awac' ), array( 'status' => 403 ) );
		}

		$result = RunnerStore::redeem( $request->get_param( 'token' ), $request->get_param( 'page_url' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		ScanHygiene::begin( $result['credential'], strtotime( $result['expires_at'] ) );

		return rest_ensure_response( $result );
	}

	/**
	 * Consume an iframe invitation from the authenticated admin page.
	 *
	 * The parent admin screen redeems the invitation with its authenticated
	 * REST request, then hands the short-lived session to the same-origin iframe
	 * over postMessage. This avoids relying on third-party iframe cookie access
	 * while keeping the capability check on the WordPress admin request.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function redeem_admin_preview( $request ) {
		$claims = RunnerStore::inspect_invitation( $request->get_param( 'token' ) );
		if ( null === $claims || 'iframe' !== $claims['mode'] ) {
			return new \WP_Error( 'ceaw_runner_invitation_invalid', __( 'This admin preview link is invalid or has expired.', 'coderembassy-awac' ), array( 'status' => 401 ) );
		}

		$result = RunnerStore::redeem( $request->get_param( 'token' ), $request->get_param( 'page_url' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		ScanHygiene::begin( $result['credential'], strtotime( $result['expires_at'] ) );

		return rest_ensure_response( $result );
	}

	/**
	 * Persist one bounded initial-state browser scan.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function submit_scan( $request ) {
		$session_id = sanitize_key( $request->get_param( 'session_id' ) );
		$credential = $request->get_header( 'X-CEAW-Runner-Credential' );
		$page_url   = $request->get_param( 'page_url' );
		$record     = RunnerStore::credential_record( $credential, $session_id, $page_url );

		if ( null === $record ) {
			return new \WP_Error( 'ceaw_runner_credential_invalid', __( 'The runner credential is invalid or has expired.', 'coderembassy-awac' ), array( 'status' => 401 ) );
		}

		if ( empty( $record['run_id'] ) ) {
			return new \WP_Error( 'ceaw_scan_run_missing', __( 'AWAC could not attach this scan to a run.', 'coderembassy-awac' ), array( 'status' => 409 ) );
		}

		$results = self::normalize_results_for_tier( $request->get_param( 'results' ) );
		$result  = ScanResultRepository::store_page(
			$record['run_id'],
			$page_url,
			PageDiscovery::classify_url( $page_url ),
			$request->get_param( 'scenario' ),
			$request->get_param( 'viewport' ),
			$request->get_param( 'markup_context' ),
			$results
		);

		if ( is_wp_error( $result ) ) {
			ScanResultRepository::finish_run( $record['run_id'], 'failed', $result->get_error_code() );
			if ( ! empty( $record['queue_id'] ) ) {
				do_action( 'ceaw_change_watch_queue_failed', $record['queue_id'], $record['run_id'] );
			}
			return $result;
		}

		if ( rest_sanitize_boolean( $request->get_param( 'final' ) ) ) {
			ScanResultRepository::finish_run( $record['run_id'] );
			if ( ! empty( $record['queue_id'] ) ) {
				do_action( 'ceaw_change_watch_queue_completed', $record['queue_id'], $record['run_id'] );
			}
			$result['status'] = 'completed';
		} else {
			$result['status'] = 'running';
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Start the authenticated front-end fallback session.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function create_adminbar_session( $request ) {
		$result = RunnerStore::create_adminbar_session(
			$request->get_param( 'page_url' ),
			$request->get_param( 'viewport' ),
			get_current_user_id()
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		ScanHygiene::begin( $result['credential'], strtotime( $result['expires_at'] ) );

		return rest_ensure_response( $result );
	}

	/**
	 * Accept a narrowly scoped runner lifecycle event.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function record_event( $request ) {
		$credential = $request->get_header( 'X-CEAW-Runner-Credential' );
		$result     = RunnerStore::record_event(
			$credential,
			$request->get_param( 'session_id' ),
			$request->get_param( 'page_url' ),
			$request->get_param( 'state' ),
			$request->get_param( 'error_code' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( ! $result['can_scan'] || in_array( $result['state'], array( 'failed', 'closed' ), true ) ) {
			ScanHygiene::cleanup( $credential );
		}

		return rest_ensure_response( $result );
	}

	/**
	 * Return safe, bounded simple-product options.
	 *
	 * @return \WP_REST_Response
	 */
	public static function safe_products() {
		return rest_ensure_response( array( 'items' => SafeProductPolicy::options() ) );
	}

	/**
	 * Return the bounded initial page set.
	 *
	 * @return \WP_REST_Response
	 */
	public static function initial_pages() {
		return rest_ensure_response( array( 'items' => PageDiscovery::initial_pages() ) );
	}

	/**
	 * Invitation schema.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function invitation_args() {
		return array(
			'target_url' => self::url_arg(),
			'mode'       => self::enum_arg( array( 'token', 'iframe' ) ),
			'viewport'   => self::viewport_arg(),
		);
	}

	/**
	 * Redemption schema.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function redeem_args() {
		return array(
			'token'    => array(
				'type'              => 'string',
				'minLength'         => 20,
				'maxLength'         => 4096,
				'required'          => true,
				'sanitize_callback' => 'sanitize_text_field',
			),
			'page_url' => self::url_arg(),
		);
	}

	/**
	 * Admin-bar schema.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function adminbar_args() {
		return array(
			'page_url' => self::url_arg(),
			'viewport' => self::viewport_arg(),
		);
	}

	/**
	 * Runner event schema.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function event_args() {
		return array(
			'session_id' => array(
				'type'              => 'string',
				'pattern'           => '^[a-f0-9]{32}$',
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
			),
			'page_url'   => self::url_arg(),
			'state'      => self::enum_arg( array( 'ready', 'failed', 'closed' ) ),
			'error_code' => array(
				'type'              => 'string',
				'maxLength'         => 64,
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
			),
		);
	}

	/**
	 * Initial scan result schema.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function scan_args() {
		return array(
			'session_id'     => array(
				'type'              => 'string',
				'pattern'           => '^[a-f0-9]{32}$',
				'required'          => true,
				'sanitize_callback' => 'sanitize_key',
			),
			'page_url'       => self::url_arg(),
			'scenario'       => array(
				'type'              => 'string',
				'maxLength'         => 64,
				'default'           => 'initial',
				'sanitize_callback' => 'sanitize_key',
			),
			'viewport'       => self::viewport_arg(),
			'markup_context' => self::enum_arg( array( 'classic', 'blocks', 'unknown' ) ),
			'results'        => array(
				'type'     => 'object',
				'required' => true,
			),
			'final'          => array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => 'rest_sanitize_boolean',
			),
		);
	}

	/**
	 * Keep Tier-2 observations on the Pro side of the server boundary.
	 *
	 * Free runner submissions retain Tier-1 data but cannot inject stateful
	 * findings into the canonical issue store.
	 *
	 * @param mixed $results Browser result envelope.
	 * @return array<string,mixed>
	 */
	private static function normalize_results_for_tier( $results ) {
		$results = is_array( $results ) ? $results : array();
		$results = apply_filters( 'ceaw_runner_normalize_results', $results );
		$results = is_array( $results ) ? $results : array();

		unset( $results['stateful'] );
		return $results;
	}

	/**
	 * Required same-origin URL argument.
	 *
	 * @return array<string,mixed>
	 */
	private static function url_arg() {
		return array(
			'type'              => 'string',
			'format'            => 'uri',
			'maxLength'         => 2048,
			'required'          => true,
			'sanitize_callback' => 'esc_url_raw',
			'validate_callback' => 'rest_validate_request_arg',
		);
	}

	/**
	 * Required enum argument.
	 *
	 * @param string[] $values Allowed values.
	 * @return array<string,mixed>
	 */
	private static function enum_arg( array $values ) {
		return array(
			'type'              => 'string',
			'enum'              => $values,
			'required'          => true,
			'sanitize_callback' => 'sanitize_key',
			'validate_callback' => 'rest_validate_request_arg',
		);
	}

	/**
	 * Viewport argument.
	 *
	 * @return array<string,mixed>
	 */
	private static function viewport_arg() {
		return self::enum_arg( array( 'desktop', 'mobile' ) );
	}

	/**
	 * Static-only class.
	 */
	private function __construct() {}
}
