<?php
/**
 * Phase 5 issue lifecycle and dismissal repository.
 *
 * @package CoderEmbassy\AWAC
 */

namespace CoderEmbassy\AWAC\REST;

defined( 'ABSPATH' ) || exit;

/**
 * Reads canonical issue state and records audit-grade dispositions.
 */
final class IssueRepository {
	/**
	 * Query canonical issues with lifecycle and runner filters.
	 *
	 * @param array<string,mixed> $query Validated query.
	 * @return array<string,mixed>
	 */
	public static function query( array $query ) {
		global $wpdb;

		$page     = max( 1, (int) ( $query['page'] ?? 1 ) );
		$per_page = max( 1, min( 100, (int) ( $query['per_page'] ?? 20 ) ) );
		$offset   = ( $page - 1 ) * $per_page;
		$order    = 'asc' === strtolower( (string) ( $query['order'] ?? 'desc' ) ) ? 'ASC' : 'DESC';
		$from     = $wpdb->prefix . 'ceaw_issues';
		$runs     = $wpdb->prefix . 'ceaw_scan_runs';
		$pages    = $wpdb->prefix . 'ceaw_scan_pages';
		$where    = array( '1 = 1' );
		$values   = array();

		$filters = array(
			'lifecycle_status' => array( 'i.lifecycle_status', array( 'open', 'resolved', 'regressed' ) ),
			'rule_source'      => array( 'i.rule_source', array( 'axe', 'ceaw' ) ),
			'impact'           => array( 'i.impact', array( 'critical', 'serious', 'moderate', 'minor' ) ),
			'disposition'      => array( 'i.disposition', array( 'actionable', 'dismissed', 'accepted', 'needs_review' ) ),
			'profile'          => array( 'r.profile', array( 'guest', 'admin' ) ),
		);
		foreach ( $filters as $key => $definition ) {
			$value = sanitize_key( $query[ $key ] ?? '' );
			if ( '' !== $value && in_array( $value, $definition[1], true ) ) {
				$where[]  = $definition[0] . ' = %s';
				$values[] = $value;
			}
		}

		$where_sql = implode( ' AND ', $where );
		$join_sql  = "FROM {$from} i LEFT JOIN {$runs} r ON r.id = i.last_seen_run_id LEFT JOIN {$pages} p ON p.id = i.last_page_id";
		$count_sql = "SELECT COUNT(*) {$join_sql} WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table names and filter columns are plugin-controlled.
		$count_sql = $wpdb->prepare( $count_sql, $values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Filter values are prepared.
		$total     = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Issue count is a prepared read-only aggregate.

		$list_sql = "SELECT i.*, r.profile, r.mode, p.page_url, p.page_type, p.viewport {$join_sql} WHERE {$where_sql} ORDER BY i.last_seen_at {$order}, i.id {$order} LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Order is allowlisted and table names are plugin-controlled.
		$list_sql = $wpdb->prepare( $list_sql, array_merge( $values, array( $per_page, $offset ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Filter and pagination values are prepared.
		$rows     = $wpdb->get_results( $list_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Issues are a prepared read-only list.

		return array(
			'items'       => array_map( array( self::class, 'normalize_row' ), is_array( $rows ) ? $rows : array() ),
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $total > 0 ? (int) ceil( $total / $per_page ) : 0,
		);
	}

	/**
	 * Return honest dashboard counts and a compact seven-day trend.
	 *
	 * @return array<string,mixed>
	 */
	public static function summary() {
		global $wpdb;

		$table  = $wpdb->prefix . 'ceaw_issues';
		$open   = "lifecycle_status IN ('open','regressed')";
		$counts = array(
			'open_issues'      => self::count_where( $table, $open ),
			'open_critical'    => self::count_where( $table, "{$open} AND impact = 'critical'" ),
			'affected_pages'   => self::distinct_count( $table, 'page_key', $open ),
			'new_regressions'  => self::count_where( $table, "lifecycle_status = 'regressed'" ),
			'resolved_30_days' => self::count_where( $table, "lifecycle_status = 'resolved' AND resolved_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY" ),
			'human_review'     => self::count_where( $table, "{$open} AND disposition = 'needs_review'" ),
			'dismissed'        => self::count_where( $table, "disposition = 'dismissed'" ),
		);

		$trend = array();
		for ( $days_ago = 6; $days_ago >= 0; $days_ago-- ) {
			$day      = gmdate( 'Y-m-d', strtotime( '-' . $days_ago . ' days' ) );
			$next_day = gmdate( 'Y-m-d', strtotime( ( 1 - $days_ago ) . ' days' ) );
			$trend[]  = array(
				'date'     => $day,
				'findings' => self::count_where( $table, $wpdb->prepare( 'last_seen_at >= %s AND last_seen_at < %s', $day . ' 00:00:00', $next_day . ' 00:00:00' ) ), // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- The returned clause is prepared before interpolation.
			);
		}

		$counts['trend'] = $trend;
		return $counts;
	}

	/**
	 * Dismiss an issue with a required note.
	 *
	 * @param int    $issue_id Issue ID.
	 * @param int    $user_id  User ID.
	 * @param string $note     Required reason.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function dismiss( $issue_id, $user_id, $note ) {
		global $wpdb;

		$issue = self::find_issue( $issue_id );
		if ( ! is_array( $issue ) ) {
			return new \WP_Error( 'ceaw_issue_not_found', __( 'That issue could not be found.', 'coderembassy-awac' ), array( 'status' => 404 ) );
		}

		$note = trim( sanitize_textarea_field( $note ) );
		if ( strlen( $note ) < 3 ) {
			return new \WP_Error( 'ceaw_dismissal_note_required', __( 'Add a short reason before dismissing an issue.', 'coderembassy-awac' ), array( 'status' => 400 ) );
		}

		if ( 'dismissed' === $issue['disposition'] && self::has_active_dismissal( $issue['fingerprint'] ) ) {
			return new \WP_Error( 'ceaw_issue_already_dismissed', __( 'This issue is already dismissed.', 'coderembassy-awac' ), array( 'status' => 409 ) );
		}

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Dismissal history is an immutable audit log.
			$wpdb->prefix . 'ceaw_dismissals',
			array(
				'issue_id'     => (int) $issue['id'],
				'fingerprint'  => $issue['fingerprint'],
				'user_id'      => absint( $user_id ),
				'dismissed_at' => gmdate( 'Y-m-d H:i:s' ),
				'note'         => $note,
			),
			array( '%d', '%s', '%d', '%s', '%s' )
		);

		if ( ! $wpdb->insert_id ) {
			return new \WP_Error( 'ceaw_dismissal_store_failed', __( 'AWAC could not record that dismissal.', 'coderembassy-awac' ), array( 'status' => 500 ) );
		}

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Canonical disposition follows the audit log write.
			$wpdb->prefix . 'ceaw_issues',
			array( 'disposition' => 'dismissed' ),
			array( 'id' => (int) $issue['id'] ),
			array( '%s' ),
			array( '%d' )
		);

		return array(
			'issue_id'    => (int) $issue['id'],
			'disposition' => 'dismissed',
		);
	}

	/**
	 * Reinstate an issue and close its active dismissal records.
	 *
	 * @param int $issue_id  Issue ID.
	 * @param int $user_id   User ID.
	 * @return array<string,mixed>|\WP_Error
	 */
	public static function reinstate( $issue_id, $user_id ) {
		global $wpdb;

		$issue = self::find_issue( $issue_id );
		if ( ! is_array( $issue ) ) {
			return new \WP_Error( 'ceaw_issue_not_found', __( 'That issue could not be found.', 'coderembassy-awac' ), array( 'status' => 404 ) );
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Active dismissals are closed in one prepared audit update.
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}ceaw_dismissals SET reinstated_at = %s, reinstated_by = %d WHERE fingerprint = %s AND reinstated_at IS NULL", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-controlled.
				$now,
				absint( $user_id ),
				$issue['fingerprint']
			)
		);
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Canonical disposition follows the dismissal audit update.
			$wpdb->prefix . 'ceaw_issues',
			array( 'disposition' => 'actionable' ),
			array( 'id' => (int) $issue['id'] ),
			array( '%s' ),
			array( '%d' )
		);

		return array(
			'issue_id'    => (int) $issue['id'],
			'disposition' => 'actionable',
		);
	}

	/**
	 * Return the dismissal history for an issue.
	 *
	 * @param int $issue_id Issue ID.
	 * @return array<int,array<string,mixed>>
	 */
	public static function dismissals( $issue_id ) {
		global $wpdb;
		$issue = self::find_issue( $issue_id );
		if ( ! is_array( $issue ) ) {
			return array();
		}

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Dismissal log is a prepared read-only audit view.
			$wpdb->prepare( "SELECT id, user_id, dismissed_at, note, reinstated_at, reinstated_by FROM {$wpdb->prefix}ceaw_dismissals WHERE fingerprint = %s ORDER BY dismissed_at DESC", $issue['fingerprint'] ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-controlled.
			ARRAY_A
		);

		return array_map(
			static function ( $row ) {
				return array(
					'id'            => (int) $row['id'],
					'user_id'       => (int) $row['user_id'],
					'dismissed_at'  => mysql2date( 'c', $row['dismissed_at'], false ),
					'note'          => sanitize_textarea_field( $row['note'] ),
					'reinstated_at' => empty( $row['reinstated_at'] ) ? null : mysql2date( 'c', $row['reinstated_at'], false ),
					'reinstated_by' => (int) $row['reinstated_by'],
				);
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Return the bounded dismissal audit log for reports.
	 *
	 * @param int $limit Maximum rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function dismissal_history( $limit = 500 ) {
		global $wpdb;

		$limit = max( 1, min( 500, absint( $limit ) ) );
		$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Report evidence is a bounded, read-only plugin-local query.
			$wpdb->prepare(
				"SELECT id, issue_id, fingerprint, user_id, dismissed_at, note, reinstated_at, reinstated_by FROM {$wpdb->prefix}ceaw_dismissals ORDER BY dismissed_at DESC, id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-controlled and the limit is prepared.
				$limit
			),
			ARRAY_A
		);

		return array_map(
			static function ( $row ) {
				return array(
					'id'            => absint( $row['id'] ?? 0 ),
					'issue_id'      => absint( $row['issue_id'] ?? 0 ),
					'fingerprint'   => sanitize_key( $row['fingerprint'] ?? '' ),
					'user_id'       => absint( $row['user_id'] ?? 0 ),
					'dismissed_at'  => empty( $row['dismissed_at'] ) ? null : mysql2date( 'c', $row['dismissed_at'], false ),
					'note'          => sanitize_textarea_field( $row['note'] ?? '' ),
					'reinstated_at' => empty( $row['reinstated_at'] ) ? null : mysql2date( 'c', $row['reinstated_at'], false ),
					'reinstated_by' => absint( $row['reinstated_by'] ?? 0 ),
				);
			},
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * Resolve issues absent from every page in a completed run.
	 *
	 * @param int $run_id Run ID.
	 * @return void
	 */
	public static function finalize_run( $run_id ) {
		global $wpdb;
		$pages_table = $wpdb->prefix . 'ceaw_scan_pages';
		$issues      = $wpdb->prefix . 'ceaw_issues';
		$page_keys   = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT page_key FROM {$pages_table} WHERE run_id = %d", absint( $run_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Completed-run page keys are a prepared plugin-local read.
		if ( empty( $page_keys ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $page_keys ), '%s' ) );
		$args         = array_merge( array( gmdate( 'Y-m-d H:i:s' ), absint( $run_id ) ), $page_keys );
		$sql          = "UPDATE {$issues} SET lifecycle_status = 'resolved', resolved_at = %s WHERE last_seen_run_id <> %d AND page_key IN ({$placeholders}) AND lifecycle_status IN ('open','regressed')"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and placeholder list are plugin-controlled.
		$wpdb->query( $wpdb->prepare( $sql, $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Lifecycle update uses prepared run/page values.
	}

	/**
	 * Normalize one issue row for REST.
	 *
	 * @param array<string,mixed> $row Database row.
	 * @return array<string,mixed>
	 */
	public static function normalize_row( array $row ) {
		return array(
			'id'               => (int) ( $row['id'] ?? 0 ),
			'fingerprint'      => sanitize_key( $row['fingerprint'] ?? '' ),
			'page_key'         => sanitize_key( $row['page_key'] ?? '' ),
			'page_url'         => esc_url_raw( $row['page_url'] ?? '' ),
			'page_type'        => sanitize_key( $row['page_type'] ?? '' ),
			'scenario'         => sanitize_key( $row['scenario'] ?? '' ),
			'rule_id'          => sanitize_key( $row['rule_id'] ?? '' ),
			'rule_source'      => sanitize_key( $row['rule_source'] ?? '' ),
			'wcag_tags'        => self::decode_json( $row['wcag_tags'] ?? '' ),
			'en301549_refs'    => self::decode_json( $row['en301549_refs'] ?? '' ),
			'impact'           => sanitize_key( $row['impact'] ?? '' ),
			'targets'          => self::decode_json( $row['targets'] ?? '' ),
			'selector'         => sanitize_text_field( $row['selector'] ?? '' ),
			'html_excerpt'     => sanitize_textarea_field( $row['html_excerpt'] ?? '' ),
			'failure_summary'  => sanitize_textarea_field( $row['failure_summary'] ?? '' ),
			'help_url'         => esc_url_raw( $row['help_url'] ?? '' ),
			'lifecycle_status' => sanitize_key( $row['lifecycle_status'] ?? '' ),
			'disposition'      => sanitize_key( $row['disposition'] ?? '' ),
			'fixable'          => (bool) (int) ( $row['fixable'] ?? 0 ),
			'first_seen_at'    => self::date_or_null( $row['first_seen_at'] ?? '' ),
			'last_seen_at'     => self::date_or_null( $row['last_seen_at'] ?? '' ),
			'resolved_at'      => self::date_or_null( $row['resolved_at'] ?? '' ),
			'profile'          => sanitize_key( $row['profile'] ?? '' ),
			'mode'             => sanitize_key( $row['mode'] ?? '' ),
			'viewport'         => sanitize_key( $row['viewport'] ?? '' ),
		);
	}

	/**
	 * Find one canonical issue.
	 *
	 * @param int $issue_id Issue ID.
	 * @return array<string,mixed>|null
	 */
	private static function find_issue( $issue_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}ceaw_issues WHERE id = %d LIMIT 1", absint( $issue_id ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared plugin-local issue lookup.
		return is_array( $row ) ? $row : null;
	}

	/**
	 * Check whether a fingerprint has an active dismissal.
	 *
	 * @param string $fingerprint Issue fingerprint.
	 * @return bool
	 */
	private static function has_active_dismissal( $fingerprint ) {
		global $wpdb;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}ceaw_dismissals WHERE fingerprint = %s AND reinstated_at IS NULL LIMIT 1", $fingerprint ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prepared plugin-local dismissal lookup.
		return (int) $value > 0;
	}

	/**
	 * Count rows matching a fixed SQL predicate.
	 *
	 * @param string $table Table name.
	 * @param string $where Fixed predicate.
	 * @return int
	 */
	private static function count_where( $table, $where ) {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table and predicates are plugin-controlled; dynamic values are prepared by callers.
	}

	/**
	 * Count distinct values matching a fixed SQL predicate.
	 *
	 * @param string $table  Table name.
	 * @param string $column Fixed column name.
	 * @param string $where  Fixed predicate.
	 * @return int
	 */
	private static function distinct_count( $table, $column, $where ) {
		global $wpdb;
		return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT {$column}) FROM {$table} WHERE {$where}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table, column, and predicates are plugin-controlled.
	}

	/**
	 * Decode stored JSON arrays.
	 *
	 * @param string $value JSON value.
	 * @return array<int,mixed>
	 */
	private static function decode_json( $value ) {
		$decoded = json_decode( (string) $value, true );
		return is_array( $decoded ) ? array_values( $decoded ) : array();
	}

	/**
	 * Normalize an optional UTC date.
	 *
	 * @param string $value MySQL date.
	 * @return string|null
	 */
	private static function date_or_null( $value ) {
		return empty( $value ) ? null : mysql2date( 'c', (string) $value, false );
	}

	/**
	 * Static-only class.
	 */
	private function __construct() {}
}
