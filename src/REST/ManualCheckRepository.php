<?php
/**
 * Guided manual-check definitions and immutable result history.
 *
 * @package CoderEmbassy\AWAC
 */

namespace CoderEmbassy\AWAC\REST;

defined( 'ABSPATH' ) || exit;

/**
 * Stores the free-tier guided WooCommerce accessibility checklist.
 */
final class ManualCheckRepository {
	/**
	 * Allowed result states.
	 *
	 * @var string[]
	 */
	public const STATUSES = array( 'not_tested', 'pass', 'fail', 'na' );

	/**
	 * Return the stable checklist definitions.
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function definitions() {
		return array(
			array(
				'key'         => 'keyboard_checkout',
				'category'    => 'Keyboard and focus',
				'label'       => 'Keyboard-only checkout',
				'description' => 'Complete the checkout journey using only the keyboard, including product choices, cart updates, and form submission.',
			),
			array(
				'key'         => 'focus_order',
				'category'    => 'Keyboard and focus',
				'label'       => 'Focus order',
				'description' => 'Confirm focus moves through controls in a logical order and never jumps behind the current task.',
			),
			array(
				'key'         => 'focus_traps',
				'category'    => 'Keyboard and focus',
				'label'       => 'Focus traps',
				'description' => 'Open menus, dialogs, cart drawers, and checkout panels; confirm focus is contained only when intended and can always be released.',
			),
			array(
				'key'         => 'announcements',
				'category'    => 'Feedback and recovery',
				'label'       => 'Status announcements',
				'description' => 'Confirm cart updates, validation results, loading changes, and successful actions are announced or otherwise discoverable.',
			),
			array(
				'key'         => 'error_recovery',
				'category'    => 'Feedback and recovery',
				'label'       => 'Error recovery',
				'description' => 'Submit incomplete or invalid checkout data and confirm errors identify the field, explain the problem, and support recovery.',
			),
			array(
				'key'         => 'zoom_reflow',
				'category'    => 'Visual and motion',
				'label'       => 'Zoom and reflow',
				'description' => 'Test 200% zoom and a narrow viewport; confirm content remains usable without overlapping or two-dimensional scrolling.',
			),
			array(
				'key'         => 'reduced_motion',
				'category'    => 'Visual and motion',
				'label'       => 'Reduced motion',
				'description' => 'Enable the operating system reduced-motion preference and confirm non-essential animation is reduced or removed.',
			),
			array(
				'key'         => 'screen_reader_journey',
				'category'    => 'Assistive technology',
				'label'       => 'Screen-reader journey',
				'description' => 'Use a screen reader to locate products, understand prices and controls, update the cart, and identify checkout errors.',
			),
			array(
				'key'         => 'payment_iframe_boundary',
				'category'    => 'Assistive technology',
				'label'       => 'Payment iframe boundary',
				'description' => 'If payment fields are hosted in an iframe, confirm the frame has a useful accessible name and the handoff is understandable.',
			),
			array(
				'key'         => 'target_size',
				'category'    => 'Controls and layout',
				'label'       => 'Target size',
				'description' => 'Check primary buttons, quantity controls, links, and close controls have comfortable touch targets with adequate spacing.',
			),
		);
	}

	/**
	 * Return the current checklist and an honest status summary.
	 *
	 * @return array<string,mixed>
	 */
	public static function all() {
		$current = self::active_rows();
		$items   = array();
		$summary = array(
			'not_tested' => 0,
			'pass'       => 0,
			'fail'       => 0,
			'na'         => 0,
		);

		foreach ( self::definitions() as $definition ) {
			$row     = isset( $current[ $definition['key'] ] ) ? self::normalize( $current[ $definition['key'] ] ) : self::empty_result( $definition['key'] );
			$items[] = array_merge( $definition, $row );
			++$summary[ $row['status'] ];
		}

		return array(
			'items'   => $items,
			'summary' => $summary,
			'total'   => count( $items ),
		);
	}

	/**
	 * Save a new immutable result row and deactivate its predecessor.
	 *
	 * @param string $check_key    Stable checklist key.
	 * @param string $status       not_tested, pass, fail, or na.
	 * @param string $notes        Tester notes.
	 * @param string $evidence_ref Optional evidence reference.
	 * @param int    $user_id      Current user ID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function save( $check_key, $status, $notes, $evidence_ref, $user_id ) {
		$definition = self::definition( $check_key );
		$status     = sanitize_key( $status );

		if ( null === $definition ) {
			return new \WP_Error( 'ceaw_manual_check_invalid', __( 'Choose a supported manual check.', 'coderembassy-awac' ), array( 'status' => 400 ) );
		}

		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new \WP_Error( 'ceaw_manual_check_status_invalid', __( 'Choose pass, fail, not tested, or not applicable.', 'coderembassy-awac' ), array( 'status' => 400 ) );
		}

		global $wpdb;
		$table  = $wpdb->prefix . 'ceaw_manual_checks';
		$latest = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE check_key = %s AND context = %s AND active = 1 ORDER BY id DESC LIMIT 1", $definition['key'], 'site' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Manual checklist state is stored in a plugin-local audit table.
		$data   = array(
			'check_key'      => $definition['key'],
			'context'        => 'site',
			'status'         => $status,
			'notes'          => substr( sanitize_textarea_field( (string) $notes ), 0, 4000 ),
			'tester_user_id' => absint( $user_id ),
			'tester_name'    => self::tester_name( $user_id ),
			'checked_at'     => gmdate( 'Y-m-d H:i:s' ),
			'evidence_ref'   => substr( sanitize_textarea_field( (string) $evidence_ref ), 0, 2000 ),
			'supersedes_id'  => $latest ? absint( $latest['id'] ) : 0,
			'active'         => 1,
		);

		if ( $latest ) {
			$wpdb->update( $table, array( 'active' => 0 ), array( 'id' => absint( $latest['id'] ) ), array( '%d' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The active checklist version is intentionally superseded.
		}

		$inserted = $wpdb->insert( $table, $data, array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Manual check results are append-only audit records.
		if ( false === $inserted ) {
			return new \WP_Error( 'ceaw_manual_check_store_failed', __( 'AWAC could not save this manual check.', 'coderembassy-awac' ), array( 'status' => 500 ) );
		}

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $wpdb->insert_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- The inserted plugin-local audit row is returned for REST output.
		return array_merge( $definition, self::normalize( $row ) );
	}

	/**
	 * Return the complete history for one check.
	 *
	 * @param string $check_key Stable checklist key.
	 * @return array<int,array<string,mixed>>
	 */
	public static function history( $check_key ) {
		$definition = self::definition( $check_key );
		if ( null === $definition ) {
			return array();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'ceaw_manual_checks';
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE check_key = %s AND context = %s ORDER BY id DESC", $definition['key'], 'site' ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Manual history is read from a plugin-local audit table.

		return array_map( array( self::class, 'normalize' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Find a checklist definition.
	 *
	 * @param string $check_key Stable key.
	 * @return array<string,string>|null
	 */
	private static function definition( $check_key ) {
		$key = sanitize_key( $check_key );
		foreach ( self::definitions() as $definition ) {
			if ( $definition['key'] === $key ) {
				return $definition;
			}
		}

		return null;
	}

	/**
	 * Fetch current active rows keyed by check key.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private static function active_rows() {
		global $wpdb;
		$table = $wpdb->prefix . 'ceaw_manual_checks';
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE context = 'site' AND active = 1 ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-controlled and the context is a fixed literal.
		$items = array();

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$items[ (string) $row['check_key'] ] = $row;
		}

		return $items;
	}

	/**
	 * Normalize a database row for REST output.
	 *
	 * @param array<string,mixed>|null $row Database row.
	 * @return array<string,mixed>
	 */
	private static function normalize( $row ) {
		return array(
			'id'             => isset( $row['id'] ) ? absint( $row['id'] ) : 0,
			'check_key'      => isset( $row['check_key'] ) ? sanitize_key( $row['check_key'] ) : '',
			'context'        => isset( $row['context'] ) ? sanitize_key( $row['context'] ) : 'site',
			'status'         => isset( $row['status'] ) && in_array( $row['status'], self::STATUSES, true ) ? $row['status'] : 'not_tested',
			'notes'          => isset( $row['notes'] ) ? (string) $row['notes'] : '',
			'tester_user_id' => isset( $row['tester_user_id'] ) ? absint( $row['tester_user_id'] ) : 0,
			'tester_name'    => isset( $row['tester_name'] ) ? (string) $row['tester_name'] : '',
			'checked_at'     => isset( $row['checked_at'] ) ? (string) $row['checked_at'] : '',
			'evidence_ref'   => isset( $row['evidence_ref'] ) ? (string) $row['evidence_ref'] : '',
			'supersedes_id'  => isset( $row['supersedes_id'] ) ? absint( $row['supersedes_id'] ) : 0,
			'active'         => isset( $row['active'] ) ? (bool) $row['active'] : false,
		);
	}

	/**
	 * Return an empty result for an untested definition.
	 *
	 * @param string $check_key Stable key.
	 * @return array<string,mixed>
	 */
	private static function empty_result( $check_key ) {
		return self::normalize( array( 'check_key' => $check_key ) );
	}

	/**
	 * Resolve a safe display name for the tester.
	 *
	 * @param int $user_id User ID.
	 * @return string
	 */
	private static function tester_name( $user_id ) {
		$user = get_userdata( absint( $user_id ) );
		return $user ? sanitize_text_field( $user->display_name ) : '';
	}

	/**
	 * Static-only class.
	 */
	private function __construct() {}
}
