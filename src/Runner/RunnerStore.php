<?php
/**
 * Short-lived runner invitation and credential storage.
 *
 * @package CoderEmbassy\AWAC
 */

namespace CoderEmbassy\AWAC\Runner;

use CoderEmbassy\AWAC\Privacy\SameOriginPolicy;
use CoderEmbassy\AWAC\Scan\ScanResultRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Issues one-time invitations and narrowly scoped runner credentials.
 */
final class RunnerStore {
	const INVITATION_TTL = 600;
	const SESSION_TTL    = 900;

	/**
	 * Create a one-time guest or iframe invitation.
	 *
	 * @param string $target_url Target page.
	 * @param string $mode       token or iframe.
	 * @param string $viewport   desktop or mobile.
	 * @param int    $created_by User ID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function create_invitation( $target_url, $mode, $viewport, $created_by ) {
		return self::create_invitation_internal( $target_url, $mode, $viewport, $created_by, 'manual', 0 );
	}

	/**
	 * Create a Pro queue invitation with a queued scan trigger.
	 *
	 * @param string $target_url Target page.
	 * @param string $mode       token or iframe.
	 * @param string $viewport   desktop or mobile.
	 * @param int    $created_by User ID.
	 * @param int    $queue_id   Queue item ID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function create_queued_invitation( $target_url, $mode, $viewport, $created_by, $queue_id ) {
		return self::create_invitation_internal( $target_url, $mode, $viewport, $created_by, 'queued', $queue_id );
	}

	/**
	 * Create one invitation with an explicit internal trigger.
	 *
	 * @param string $target_url Target page.
	 * @param string $mode       token or iframe.
	 * @param string $viewport   desktop or mobile.
	 * @param int    $created_by User ID.
	 * @param string $trigger    manual or queued.
	 * @param int    $queue_id   Queue item ID.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function create_invitation_internal( $target_url, $mode, $viewport, $created_by, $trigger, $queue_id ) {
		$mode       = sanitize_key( $mode );
		$viewport   = sanitize_key( $viewport );
		$trigger    = 'queued' === sanitize_key( $trigger ) ? 'queued' : 'manual';
		$queue_id   = absint( $queue_id );
		$target_url = SameOriginPolicy::canonical_url( $target_url, home_url( '/' ) );

		if ( '' === $target_url ) {
			return new \WP_Error( 'ceaw_runner_target_not_allowed', __( 'Choose a page on this exact WordPress site.', 'coderembassy-awac' ), array( 'status' => 400 ) );
		}

		if ( ! in_array( $mode, array( 'token', 'iframe' ), true ) ) {
			return new \WP_Error( 'ceaw_runner_mode_invalid', __( 'Choose a supported runner mode.', 'coderembassy-awac' ), array( 'status' => 400 ) );
		}

		if ( ! in_array( $viewport, array( 'desktop', 'mobile' ), true ) ) {
			return new \WP_Error( 'ceaw_runner_viewport_invalid', __( 'Choose a supported audit viewport.', 'coderembassy-awac' ), array( 'status' => 400 ) );
		}

		$now     = time();
		$jti     = self::random_id();
		$profile = 'token' === $mode ? 'guest' : 'admin';
		$record  = array(
			'invitation_id' => $jti,
			'target_url'    => $target_url,
			'target_hash'   => hash( 'sha256', $target_url ),
			'mode'          => $mode,
			'profile'       => $profile,
			'viewport'      => $viewport,
			'created_by'    => absint( $created_by ),
			'trigger'       => $trigger,
			'queue_id'      => $queue_id,
			'created_at'    => $now,
			'expires_at'    => $now + self::INVITATION_TTL,
		);
		$token   = TokenSigner::sign(
			array(
				'typ'         => 'invitation',
				'jti'         => $jti,
				'iat'         => $now,
				'exp'         => $record['expires_at'],
				'mode'        => $mode,
				'target_hash' => $record['target_hash'],
			),
			self::secret()
		);

		if ( ! set_transient( self::invitation_key( $jti ), $record, self::INVITATION_TTL ) ) {
			return new \WP_Error( 'ceaw_runner_invitation_store_failed', __( 'AWAC could not create the runner link. Try again.', 'coderembassy-awac' ), array( 'status' => 500 ) );
		}

		return array(
			'invitation_id' => $jti,
			'launch_url'    => add_query_arg( 'ceaw_runner', $token, $target_url ),
			'target_url'    => $target_url,
			'mode'          => $mode,
			'profile'       => $profile,
			'viewport'      => $viewport,
			'expires_at'    => gmdate( 'c', $record['expires_at'] ),
			'expires_in'    => self::INVITATION_TTL,
		);
	}

	/**
	 * Decode a valid invitation without consuming it.
	 *
	 * @param string $token Signed invitation.
	 * @return array<string,mixed>|null
	 */
	public static function inspect_invitation( $token ) {
		$claims = TokenSigner::verify( $token, self::secret() );

		if (
			! is_array( $claims ) ||
			'invitation' !== ( $claims['typ'] ?? '' ) ||
			! in_array( $claims['mode'] ?? '', array( 'token', 'iframe' ), true ) ||
			! preg_match( '/^[a-f0-9]{32}$/', (string) ( $claims['jti'] ?? '' ) ) ||
			! preg_match( '/^[a-f0-9]{64}$/', (string) ( $claims['target_hash'] ?? '' ) )
		) {
			return null;
		}

		return $claims;
	}

	/**
	 * Consume an invitation and return a session-scoped credential.
	 *
	 * @param string $token    Signed invitation.
	 * @param string $page_url Browser page URL with transport parameters.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function redeem( $token, $page_url ) {
		$claims = self::inspect_invitation( $token );
		if ( null === $claims ) {
			return new \WP_Error( 'ceaw_runner_invitation_invalid', __( 'This runner link is invalid or has expired.', 'coderembassy-awac' ), array( 'status' => 401 ) );
		}

		$target_url = SameOriginPolicy::canonical_url( $page_url, home_url( '/' ), array( 'ceaw_runner', 'ceaw_admin_preview', 'ceaw_admin_preview_id' ) );
		if ( '' === $target_url || ! hash_equals( (string) $claims['target_hash'], hash( 'sha256', $target_url ) ) ) {
			return new \WP_Error( 'ceaw_runner_target_mismatch', __( 'This runner link is not valid for the current page.', 'coderembassy-awac' ), array( 'status' => 403 ) );
		}

		$key    = self::invitation_key( (string) $claims['jti'] );
		$record = get_transient( $key );
		if ( ! is_array( $record ) || ! hash_equals( (string) $record['target_hash'], (string) $claims['target_hash'] ) ) {
			return new \WP_Error( 'ceaw_runner_invitation_used', __( 'This one-time runner link has already been used or expired.', 'coderembassy-awac' ), array( 'status' => 409 ) );
		}

		delete_transient( $key );

		return self::create_session( $record );
	}

	/**
	 * Create an authenticated admin-bar session without a public invitation.
	 *
	 * @param string $page_url   Exact current page.
	 * @param string $viewport   desktop or mobile.
	 * @param int    $created_by User ID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function create_adminbar_session( $page_url, $viewport, $created_by ) {
		$page_url = SameOriginPolicy::canonical_url( $page_url, home_url( '/' ), array( 'ceaw_adminbar' ) );
		$viewport = sanitize_key( $viewport );

		if ( '' === $page_url ) {
			return new \WP_Error( 'ceaw_runner_target_not_allowed', __( 'The current page is outside this WordPress site.', 'coderembassy-awac' ), array( 'status' => 400 ) );
		}

		if ( ! in_array( $viewport, array( 'desktop', 'mobile' ), true ) ) {
			return new \WP_Error( 'ceaw_runner_viewport_invalid', __( 'Choose a supported audit viewport.', 'coderembassy-awac' ), array( 'status' => 400 ) );
		}

		return self::create_session(
			array(
				'invitation_id' => '',
				'target_url'    => $page_url,
				'target_hash'   => hash( 'sha256', $page_url ),
				'mode'          => 'adminbar',
				'profile'       => 'admin',
				'viewport'      => $viewport,
				'created_by'    => absint( $created_by ),
			)
		);
	}

	/**
	 * Validate a runner credential and optional session/target constraints.
	 *
	 * @param string $credential Signed credential.
	 * @param string $session_id Expected session ID.
	 * @param string $page_url   Expected page URL.
	 * @return array<string,mixed>|null
	 */
	public static function credential_record( $credential, $session_id = '', $page_url = '' ) {
		$claims = TokenSigner::verify( $credential, self::secret() );
		if (
			! is_array( $claims ) ||
			'credential' !== ( $claims['typ'] ?? '' ) ||
			'runner_event' !== ( $claims['scope'] ?? '' ) ||
			! preg_match( '/^[a-f0-9]{32}$/', (string) ( $claims['jti'] ?? '' ) ) ||
			! preg_match( '/^[a-f0-9]{32}$/', (string) ( $claims['sid'] ?? '' ) )
		) {
			return null;
		}

		if ( '' !== $session_id && ! hash_equals( (string) $claims['sid'], $session_id ) ) {
			return null;
		}

		$record = get_transient( self::session_key( (string) $claims['jti'] ) );
		if ( ! is_array( $record ) || ! hash_equals( (string) $record['session_id'], (string) $claims['sid'] ) ) {
			return null;
		}

		if ( '' !== $page_url ) {
			$canonical = SameOriginPolicy::canonical_url( $page_url, home_url( '/' ), array( 'ceaw_runner', 'ceaw_adminbar', 'ceaw_admin_preview', 'ceaw_admin_preview_id' ) );
			if ( '' === $canonical || ! hash_equals( (string) $record['target_hash'], hash( 'sha256', $canonical ) ) ) {
				return null;
			}
		}

		$record['credential_jti'] = (string) $claims['jti'];

		return $record;
	}

	/**
	 * Store a bounded runner lifecycle event.
	 *
	 * @param string $credential Signed credential.
	 * @param string $session_id Session ID.
	 * @param string $page_url   Exact page URL.
	 * @param string $state      ready, failed, or closed.
	 * @param string $error_code Allowlisted machine code.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function record_event( $credential, $session_id, $page_url, $state, $error_code = '' ) {
		$record = self::credential_record( $credential, $session_id, $page_url );
		if ( null === $record ) {
			return new \WP_Error( 'ceaw_runner_credential_invalid', __( 'The runner credential is invalid or has expired.', 'coderembassy-awac' ), array( 'status' => 401 ) );
		}

		$state = sanitize_key( $state );
		if ( ! in_array( $state, array( 'ready', 'failed', 'closed' ), true ) ) {
			return new \WP_Error( 'ceaw_runner_state_invalid', __( 'The runner reported an unsupported state.', 'coderembassy-awac' ), array( 'status' => 400 ) );
		}

		$record['state']      = $state;
		$record['updated_at'] = time();
		$record['error_code'] = substr( sanitize_key( $error_code ), 0, 64 );
		if ( 'failed' === $state && ! empty( $record['run_id'] ) ) {
			ScanResultRepository::finish_run( $record['run_id'], 'failed', $record['error_code'] );
			if ( ! empty( $record['queue_id'] ) ) {
				do_action( 'ceaw_change_watch_queue_failed', $record['queue_id'], $record['run_id'] );
			}
		}
		$remaining            = max( 1, (int) $record['expires_at'] - time() );
		$credential_jti       = (string) $record['credential_jti'];
		$response             = $record;
		$response['phase']    = 4;
		$response['can_scan'] = true;
		$response['message']  = __( 'Runner connection verified. The initial accessibility scan is ready.', 'coderembassy-awac' );

		unset( $record['credential_jti'] );
		if ( 'closed' === $state ) {
			delete_transient( self::session_key( $credential_jti ) );
		} else {
			set_transient( self::session_key( $credential_jti ), $record, $remaining );
		}

		return $response;
	}

	/**
	 * Convert an invitation record into a signed session credential.
	 *
	 * @param array<string,mixed> $record Invitation/session seed.
	 * @return array<string,mixed>|\WP_Error
	 */
	private static function create_session( array $record ) {
		$now            = time();
		$session_id     = self::random_id();
		$credential_jti = self::random_id();
		$expires_at     = $now + self::SESSION_TTL;
		$run_id         = ScanResultRepository::start_run( $record['profile'], $record['mode'], $record['created_by'], $record['trigger'] ?? 'manual' );
		$session        = array(
			'session_id'    => $session_id,
			'invitation_id' => (string) ( $record['invitation_id'] ?? '' ),
			'target_url'    => (string) $record['target_url'],
			'target_hash'   => (string) $record['target_hash'],
			'mode'          => (string) $record['mode'],
			'profile'       => (string) $record['profile'],
			'viewport'      => (string) $record['viewport'],
			'created_by'    => (int) $record['created_by'],
			'state'         => 'issued',
			'created_at'    => $now,
			'updated_at'    => $now,
			'expires_at'    => $expires_at,
			'error_code'    => '',
			'run_id'        => $run_id,
			'queue_id'      => absint( $record['queue_id'] ?? 0 ),
		);
		$credential     = TokenSigner::sign(
			array(
				'typ'         => 'credential',
				'jti'         => $credential_jti,
				'sid'         => $session_id,
				'iat'         => $now,
				'exp'         => $expires_at,
				'scope'       => 'runner_event',
				'mode'        => $session['mode'],
				'target_hash' => $session['target_hash'],
			),
			self::secret()
		);

		if ( ! set_transient( self::session_key( $credential_jti ), $session, self::SESSION_TTL ) ) {
			return new \WP_Error( 'ceaw_runner_session_store_failed', __( 'AWAC could not start the runner session. Try again.', 'coderembassy-awac' ), array( 'status' => 500 ) );
		}

		return array_merge(
			$session,
			array(
				'credential' => $credential,
				'expires_at' => gmdate( 'c', $expires_at ),
				'expires_in' => self::SESSION_TTL,
				'phase'      => 3,
				'can_scan'   => false,
			)
		);
	}

	/**
	 * Generate a 128-bit lowercase identifier.
	 *
	 * @return string
	 */
	private static function random_id() {
		try {
			return bin2hex( random_bytes( 16 ) );
		} catch ( \Exception $exception ) {
			return str_replace( '-', '', wp_generate_uuid4() );
		}
	}

	/**
	 * Build a deployment-specific HMAC secret.
	 *
	 * @return string
	 */
	private static function secret() {
		return wp_salt( 'nonce' ) . '|ceaw-runner-v1';
	}

	/**
	 * Invitation transient key.
	 *
	 * @param string $jti Token identifier.
	 * @return string
	 */
	private static function invitation_key( $jti ) {
		return 'ceaw_inv_' . hash( 'sha256', (string) $jti );
	}

	/**
	 * Session transient key.
	 *
	 * @param string $jti Credential identifier.
	 * @return string
	 */
	private static function session_key( $jti ) {
		return 'ceaw_session_' . hash( 'sha256', (string) $jti );
	}

	/**
	 * Static-only class.
	 */
	private function __construct() {}
}
