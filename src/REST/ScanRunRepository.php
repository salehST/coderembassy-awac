<?php
/**
 * Read-only scan-run query service.
 *
 * @package CoderEmbassy\AWAC
 */

namespace CoderEmbassy\AWAC\REST;

defined( 'ABSPATH' ) || exit;

/**
 * Retrieves normalized run history without exposing SQL details to routes.
 */
final class ScanRunRepository {
	/**
	 * Query scan runs.
	 *
	 * @param array<string,mixed> $query Validated query arguments.
	 * @return array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,total_pages:int}
	 */
	public static function query( array $query ) {
		global $wpdb;

		$page     = max( 1, (int) $query['page'] );
		$per_page = max( 1, min( 100, (int) $query['per_page'] ) );
		$offset   = ( $page - 1 ) * $per_page;
		$order    = 'asc' === strtolower( (string) $query['order'] ) ? 'ASC' : 'DESC';
		$table    = $wpdb->prefix . 'ceaw_scan_runs';
		$where    = array( '1 = 1' );
		$values   = array();

		foreach ( array( 'status', 'profile', 'mode' ) as $filter ) {
			if ( ! empty( $query[ $filter ] ) ) {
				$where[]  = $filter . ' = %s';
				$values[] = (string) $query[ $filter ];
			}
		}

		$where_sql = implode( ' AND ', $where );
		$count_sql = "SELECT COUNT(*) FROM %i WHERE {$where_sql}"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Clause contains fixed column names and placeholders only.
		$count_sql = $wpdb->prepare( $count_sql, array_merge( array( $table ), $values ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Dynamic clause contains fixed columns and prepared values only.
		$total     = (int) $wpdb->get_var( $count_sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only history count is prepared and must reflect current runs.

		$list_sql = "SELECT id, started_at, finished_at, profile, mode, axe_version, ruleset_version, standard, en301549_version, trigger_type, status, created_by, error_code FROM %i WHERE {$where_sql} ORDER BY started_at {$order}, id {$order} LIMIT %d OFFSET %d"; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Order is allowlisted and clause contains fixed columns/placeholders.
		// Dynamic clause contains fixed columns and prepared values only.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		$list_sql = $wpdb->prepare(
			$list_sql,
			array_merge( array( $table ), $values, array( $per_page, $offset ) )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $list_sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-only history must reflect current runs.

		return array(
			'items'       => array_map( array( self::class, 'normalize_row' ), is_array( $rows ) ? $rows : array() ),
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => $total > 0 ? (int) ceil( $total / $per_page ) : 0,
		);
	}

	/**
	 * Cast database strings to the public REST schema.
	 *
	 * @param array<string,mixed> $row Database row.
	 * @return array<string,mixed>
	 */
	public static function normalize_row( array $row ) {
		return array(
			'id'               => (int) $row['id'],
			'started_at'       => self::format_date( $row['started_at'] ),
			'finished_at'      => empty( $row['finished_at'] ) ? null : self::format_date( $row['finished_at'] ),
			'profile'          => sanitize_key( $row['profile'] ),
			'mode'             => sanitize_key( $row['mode'] ),
			'axe_version'      => sanitize_text_field( $row['axe_version'] ),
			'ruleset_version'  => sanitize_text_field( $row['ruleset_version'] ),
			'standard'         => sanitize_key( $row['standard'] ),
			'en301549_version' => sanitize_text_field( $row['en301549_version'] ),
			'trigger_type'     => sanitize_key( $row['trigger_type'] ),
			'status'           => sanitize_key( $row['status'] ),
			'created_by'       => (int) $row['created_by'],
			'error_code'       => sanitize_key( $row['error_code'] ),
		);
	}

	/**
	 * Format a stored UTC timestamp as RFC 3339.
	 *
	 * @param string $date MySQL UTC date.
	 * @return string
	 */
	private static function format_date( $date ) {
		return mysql2date( 'c', (string) $date, false );
	}

	/**
	 * Static-only class.
	 */
	private function __construct() {}
}
