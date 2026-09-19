<?php
/**
 * Phase 4 scan-run and evidence persistence.
 *
 * @package CoderEmbassy\AWAC
 */

namespace CoderEmbassy\AWAC\Scan;

use CoderEmbassy\AWAC\Privacy\Redactor;
use CoderEmbassy\AWAC\REST\IssueRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Stores bounded browser results in the regression-ready AWAC tables.
 */
final class ScanResultRepository {
	/**
	 * Start a scan run.
	 *
	 * @param string $profile    guest or admin.
	 * @param string $mode       token, iframe, or adminbar.
	 * @param int    $created_by User ID.
	 * @param string $trigger    manual, queued, or change_watch.
	 * @return int
	 */
	public static function start_run( $profile, $mode, $created_by, $trigger = 'manual' ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'insert' ) ) {
			return 0;
		}

		$now   = gmdate( 'Y-m-d H:i:s' );
		$table = $wpdb->prefix . 'ceaw_scan_runs';
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Scan runs are the plugin's primary persisted audit record.
			$table,
			array(
				'started_at'       => $now,
				'profile'          => in_array( $profile, array( 'guest', 'admin' ), true ) ? $profile : 'guest',
				'mode'             => in_array( $mode, array( 'token', 'iframe', 'adminbar' ), true ) ? $mode : 'token',
				'axe_version'      => defined( 'CEAW_AXE_VERSION' ) ? CEAW_AXE_VERSION : '4.11.4',
				'ruleset_version'  => defined( 'CEAW_RULESET_VERSION' ) ? CEAW_RULESET_VERSION : '1.0.0',
				'standard'         => defined( 'CEAW_STANDARD_VERSION' ) ? CEAW_STANDARD_VERSION : 'wcag22aa',
				'en301549_version' => defined( 'CEAW_EN301549_VERSION' ) ? CEAW_EN301549_VERSION : '3.2.1',
				'trigger_type'     => in_array( $trigger, array( 'manual', 'queued', 'change_watch' ), true ) ? $trigger : 'manual',
				'status'           => 'running',
				'created_by'       => absint( $created_by ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * Mark a run as complete or failed.
	 *
	 * @param int    $run_id     Run ID.
	 * @param string $status     completed or failed.
	 * @param string $error_code Bounded machine code.
	 * @return bool
	 */
	public static function finish_run( $run_id, $status = 'completed', $error_code = '' ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'update' ) || absint( $run_id ) < 1 ) {
			return false;
		}

		$table  = $wpdb->prefix . 'ceaw_scan_runs';
		$status = 'failed' === $status ? 'failed' : 'completed';

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Run lifecycle state is intentionally persisted immediately.
			$table,
			array(
				'finished_at' => gmdate( 'Y-m-d H:i:s' ),
				'status'      => $status,
				'error_code'  => substr( sanitize_key( $error_code ), 0, 64 ),
			),
			array( 'id' => absint( $run_id ) ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( false !== $updated && 'completed' === $status ) {
			IssueRepository::finalize_run( $run_id );
		}

		return false !== $updated;
	}

	/**
	 * Return the trigger type for a scan run.
	 *
	 * @param int $run_id Scan run ID.
	 * @return string
	 */
	public static function run_trigger( $run_id ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || absint( $run_id ) < 1 ) {
			return '';
		}

		$table = $wpdb->prefix . 'ceaw_scan_runs';
		return sanitize_key(
			$wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trigger lookup is a prepared plugin-local read used for alert gating.
				$wpdb->prepare( "SELECT trigger_type FROM {$table} WHERE id = %d LIMIT 1", absint( $run_id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-controlled.
			)
		);
	}

	/**
	 * Persist one page's axe and custom findings.
	 *
	 * @param int                 $run_id       Run ID.
	 * @param string              $page_url     Browser page URL.
	 * @param string              $page_type    Page type.
	 * @param string              $scenario     Scenario name.
	 * @param string              $viewport     Viewport profile.
	 * @param string              $markup_mode  classic, blocks, or unknown.
	 * @param array<string,mixed> $results      Browser result envelope.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function store_page( $run_id, $page_url, $page_type, $scenario, $viewport, $markup_mode, array $results ) {
		global $wpdb;

		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'insert' ) ) {
			return new \WP_Error( 'ceaw_scan_storage_unavailable', __( 'AWAC could not access its scan storage.', 'coderembassy-awac' ), array( 'status' => 500 ) );
		}

		$normalized_url = Redactor::url_for_storage( $page_url );
		if ( '' === $normalized_url ) {
			return new \WP_Error( 'ceaw_scan_url_invalid', __( 'The scan page URL is not valid for storage.', 'coderembassy-awac' ), array( 'status' => 400 ) );
		}

		$run_id      = absint( $run_id );
		$page_type   = self::page_type( $page_type );
		$scenario    = self::scenario( $scenario );
		$viewport    = in_array( $viewport, array( 'desktop', 'mobile' ), true ) ? $viewport : 'desktop';
		$markup_mode = in_array( $markup_mode, array( 'classic', 'blocks' ), true ) ? $markup_mode : 'unknown';
		$findings    = self::normalize_findings( $results );
		$now         = gmdate( 'Y-m-d H:i:s' );
		$page_table  = $wpdb->prefix . 'ceaw_scan_pages';

		$counts = array(
			'critical' => 0,
			'serious'  => 0,
			'moderate' => 0,
			'minor'    => 0,
		);
		foreach ( $findings as $finding ) {
			if ( isset( $counts[ $finding['impact'] ] ) ) {
				++$counts[ $finding['impact'] ];
			}
		}

		$page_data    = array(
			'run_id'          => $run_id,
			'page_url'        => $normalized_url,
			'page_key'        => Redactor::page_key( $normalized_url ),
			'page_type'       => $page_type,
			'scenario'        => $scenario,
			'viewport'        => $viewport,
			'markup_context'  => $markup_mode,
			'issues_critical' => $counts['critical'],
			'issues_serious'  => $counts['serious'],
			'issues_moderate' => $counts['moderate'],
			'issues_minor'    => $counts['minor'],
			'created_at'      => $now,
		);
		$page_formats = array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s' );

		$inserted = $wpdb->insert( $page_table, $page_data, $page_formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Page evidence is the plugin's primary persisted audit record.
		$page_id  = false === $inserted ? 0 : (int) $wpdb->insert_id;
		if ( 0 === $page_id ) {
			$page_id = (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read-after-insert lookup resolves the plugin's unique logical page key.
				$wpdb->prepare(
					"SELECT id FROM {$page_table} WHERE run_id = %d AND page_key = %s AND scenario = %s AND viewport = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-controlled.
					$run_id,
					$page_data['page_key'],
					$scenario,
					$viewport
				)
			);
		}

		if ( $page_id < 1 ) {
			return new \WP_Error( 'ceaw_scan_page_store_failed', __( 'AWAC could not store this scanned page.', 'coderembassy-awac' ), array( 'status' => 500 ) );
		}

		$issue_ids = array();
		foreach ( $findings as $finding ) {
			$issue = self::store_issue( $run_id, $page_id, $normalized_url, $page_type, $scenario, $finding, $now );
			if ( is_wp_error( $issue ) ) {
				return $issue;
			}
			$issue_ids[] = $issue;
		}

		return array(
			'page_id'         => $page_id,
			'run_id'          => $run_id,
			'page_type'       => $page_type,
			'issues'          => count( $issue_ids ),
			'counts'          => $counts,
			'stored_at'       => gmdate( 'c' ),
			'axe_version'     => defined( 'CEAW_AXE_VERSION' ) ? CEAW_AXE_VERSION : '4.11.4',
			'ruleset_version' => defined( 'CEAW_RULESET_VERSION' ) ? CEAW_RULESET_VERSION : '1.0.0',
		);
	}

	/**
	 * Normalize browser output before it reaches SQL or fingerprints.
	 *
	 * @param array<string,mixed> $results Browser result envelope.
	 * @return array<int,array<string,mixed>>
	 */
	public static function normalize_findings( array $results ) {
		$findings = array();
		$axe      = isset( $results['axe'] ) && is_array( $results['axe'] ) ? $results['axe'] : array();
		$custom   = isset( $results['custom'] ) && is_array( $results['custom'] ) ? $results['custom'] : array();

		$violations = isset( $axe['violations'] ) && is_array( $axe['violations'] ) ? $axe['violations'] : array();
		foreach ( $violations as $violation ) {
			if ( ! is_array( $violation ) ) {
				continue;
			}

			$nodes = isset( $violation['nodes'] ) && is_array( $violation['nodes'] ) ? $violation['nodes'] : array( array() );
			foreach ( $nodes as $node ) {
				$node       = is_array( $node ) ? $node : array();
				$targets    = isset( $node['target'] ) && is_array( $node['target'] ) ? $node['target'] : array();
				$findings[] = self::finding(
					'axe',
					isset( $violation['id'] ) ? $violation['id'] : 'axe-unknown',
					isset( $violation['impact'] ) ? $violation['impact'] : 'moderate',
					isset( $violation['tags'] ) && is_array( $violation['tags'] ) ? $violation['tags'] : array(),
					$targets,
					isset( $node['html'] ) ? $node['html'] : '',
					isset( $node['failureSummary'] ) ? $node['failureSummary'] : ( isset( $violation['help'] ) ? $violation['help'] : '' ),
					isset( $violation['helpUrl'] ) ? $violation['helpUrl'] : '',
					false
				);
			}
		}

		foreach ( $custom as $finding ) {
			if ( ! is_array( $finding ) ) {
				continue;
			}
			$findings[] = self::finding(
				'ceaw',
				isset( $finding['rule_id'] ) ? $finding['rule_id'] : 'ceaw-unknown',
				isset( $finding['impact'] ) ? $finding['impact'] : 'moderate',
				isset( $finding['wcag_tags'] ) && is_array( $finding['wcag_tags'] ) ? $finding['wcag_tags'] : array(),
				isset( $finding['targets'] ) && is_array( $finding['targets'] ) ? $finding['targets'] : array(),
				isset( $finding['html'] ) ? $finding['html'] : '',
				isset( $finding['failure_summary'] ) ? $finding['failure_summary'] : '',
				isset( $finding['help_url'] ) ? $finding['help_url'] : '',
				! empty( $finding['fixable'] )
			);
		}

		return array_slice( $findings, 0, 250 );
	}

	/**
	 * Build a normalized finding row.
	 *
	 * @param string   $source       axe or ceaw.
	 * @param string   $rule_id      Rule ID.
	 * @param string   $impact       Severity.
	 * @param string[] $wcag_tags    WCAG tags.
	 * @param array    $targets      Target paths.
	 * @param string   $html         Untrusted node HTML.
	 * @param string   $summary      Failure summary.
	 * @param string   $help_url     Help URL.
	 * @param bool     $fixable      Whether a curated fix exists.
	 * @return array<string,mixed>
	 */
	private static function finding( $source, $rule_id, $impact, array $wcag_tags, array $targets, $html, $summary, $help_url, $fixable ) {
		$targets = array_values(
			array_filter(
				array_map(
					static function ( $target ) {
						return is_array( $target ) ? implode( ' ', array_map( 'sanitize_text_field', $target ) ) : sanitize_text_field( $target );
					},
					$targets
				),
				static function ( $target ) {
					return '' !== $target;
				}
			)
		);
		$impact  = in_array( strtolower( (string) $impact ), array( 'critical', 'serious', 'moderate', 'minor' ), true ) ? strtolower( (string) $impact ) : 'moderate';
		$tags    = array_values( array_unique( array_filter( array_map( 'sanitize_key', $wcag_tags ) ) ) );

		return array(
			'rule_id'         => substr( sanitize_key( $rule_id ), 0, 128 ),
			'rule_source'     => 'ceaw' === $source ? 'ceaw' : 'axe',
			'wcag_tags'       => $tags,
			'en301549_refs'   => self::en301549_refs( $tags ),
			'impact'          => $impact,
			'targets'         => $targets,
			'selector'        => isset( $targets[0] ) ? substr( $targets[0], 0, 2000 ) : '',
			'html_excerpt'    => Redactor::html_excerpt( $html ),
			'failure_summary' => Redactor::text( $summary ),
			'help_url'        => self::help_url( $help_url ),
			'fixable'         => $fixable ? 1 : 0,
		);
	}

	/**
	 * Store a canonical issue and immutable occurrence.
	 *
	 * @param int                 $run_id       Run ID.
	 * @param int                 $page_id      Page ID.
	 * @param string              $page_url     Normalized page URL.
	 * @param string              $page_type    Page type.
	 * @param string              $scenario     Scenario.
	 * @param array<string,mixed> $finding      Normalized finding.
	 * @param string              $now          UTC timestamp.
	 * @return int|\WP_Error
	 */
	private static function store_issue( $run_id, $page_id, $page_url, $page_type, $scenario, array $finding, $now ) {
		global $wpdb;

		$issue_table      = $wpdb->prefix . 'ceaw_issues';
		$occurrence_table = $wpdb->prefix . 'ceaw_issue_occurrences';
		$fingerprint      = Fingerprint::create( $page_url, $page_type, $scenario, $finding['rule_source'], $finding['rule_id'], $finding['selector'] );
		$existing         = $wpdb->get_row( $wpdb->prepare( "SELECT id, lifecycle_status FROM {$issue_table} WHERE fingerprint = %s LIMIT 1", $fingerprint ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fingerprint lookup is a prepared plugin-local query.
		$is_new           = ! is_array( $existing ) || empty( $existing['id'] );

		$issue_data = array(
			'fingerprint'      => $fingerprint,
			'page_key'         => Redactor::page_key( $page_url ),
			'page_type'        => $page_type,
			'scenario'         => $scenario,
			'rule_id'          => $finding['rule_id'],
			'rule_source'      => $finding['rule_source'],
			'wcag_tags'        => self::json( $finding['wcag_tags'] ),
			'en301549_refs'    => self::json( $finding['en301549_refs'] ),
			'impact'           => $finding['impact'],
			'targets'          => self::json( $finding['targets'] ),
			'selector'         => $finding['selector'],
			'html_excerpt'     => $finding['html_excerpt'],
			'failure_summary'  => $finding['failure_summary'],
			'help_url'         => $finding['help_url'],
			'lifecycle_status' => is_array( $existing ) && 'resolved' === ( $existing['lifecycle_status'] ?? '' ) ? 'regressed' : 'open',
			'last_seen_at'     => $now,
			'last_seen_run_id' => $run_id,
			'last_page_id'     => $page_id,
			'fixable'          => $finding['fixable'],
		);

		if ( is_array( $existing ) && ! empty( $existing['id'] ) ) {
			$issue_id = (int) $existing['id'];
			$updated  = $wpdb->update( $issue_table, $issue_data, array( 'id' => $issue_id ), self::issue_formats(), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Canonical issue state is intentionally updated per finding.
			if ( false === $updated ) {
				return new \WP_Error( 'ceaw_issue_update_failed', __( 'AWAC could not update a scan issue.', 'coderembassy-awac' ), array( 'status' => 500 ) );
			}
		} else {
			$issue_data['first_seen_at']     = $now;
			$issue_data['first_seen_run_id'] = $run_id;
			$issue_id                        = false === $wpdb->insert( $issue_table, $issue_data, self::issue_formats( true ) ) ? 0 : (int) $wpdb->insert_id; // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- New canonical issue evidence is persisted in the plugin table.
			if ( $issue_id < 1 ) {
				return new \WP_Error( 'ceaw_issue_store_failed', __( 'AWAC could not store a scan issue.', 'coderembassy-awac' ), array( 'status' => 500 ) );
			}
		}

		if ( $is_new && 'critical' === $finding['impact'] && 'change_watch' === self::run_trigger( $run_id ) ) {
			do_action(
				'ceaw_critical_finding_recorded',
				$run_id,
				array_merge(
					$finding,
					array(
						'fingerprint' => $fingerprint,
						'page_url'    => $page_url,
						'issue_id'    => $issue_id,
					)
				)
			);
		}

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Immutable occurrence evidence is written with a prepared INSERT IGNORE.
			$wpdb->prepare(
				"INSERT IGNORE INTO {$occurrence_table} (issue_id, fingerprint, run_id, page_id, observed_at, impact, targets, selector, html_excerpt, failure_summary, help_url) VALUES (%d, %s, %d, %d, %s, %s, %s, %s, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is plugin-controlled.
				$issue_id,
				$fingerprint,
				$run_id,
				$page_id,
				$now,
				$finding['impact'],
				self::json( $finding['targets'] ),
				$finding['selector'],
				$finding['html_excerpt'],
				$finding['failure_summary'],
				$finding['help_url']
			)
		); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Evidence is intentionally immutable and prepared.

		return $issue_id;
	}

	/**
	 * Map axe WCAG tags to EN 301 549 clause references.
	 *
	 * @param string[] $tags WCAG tags.
	 * @return string[]
	 */
	private static function en301549_refs( array $tags ) {
		$refs = array();
		foreach ( $tags as $tag ) {
			if ( 1 === preg_match( '/^wcag([1-4])([0-9])([0-9])$/', $tag, $matches ) ) {
				$refs[] = '9.' . $matches[1] . '.' . $matches[2] . '.' . $matches[3];
			}
		}

		return array_values( array_unique( $refs ) );
	}

	/**
	 * Allowlist help URLs and cap their size.
	 *
	 * @param string $url Candidate URL.
	 * @return string
	 */
	private static function help_url( $url ) {
		$url = esc_url_raw( $url );
		return preg_match( '#^https?://#i', $url ) ? substr( $url, 0, 500 ) : '';
	}

	/**
	 * Encode JSON with a test-friendly fallback.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function json( $value ) {
		return function_exists( 'wp_json_encode' ) ? wp_json_encode( $value ) : (string) json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Pure-test fallback; WordPress uses wp_json_encode.
	}

	/**
	 * Normalize page type.
	 *
	 * @param string $value Page type.
	 * @return string
	 */
	private static function page_type( $value ) {
		$allowed = array( 'home', 'shop', 'product_simple', 'product_variable', 'cart', 'checkout', 'other' );
		$value   = sanitize_key( $value );
		return in_array( $value, $allowed, true ) ? $value : 'other';
	}

	/**
	 * Normalize scenario.
	 *
	 * @param string $value Scenario.
	 * @return string
	 */
	private static function scenario( $value ) {
		$value = sanitize_key( $value );
		return '' !== $value && strlen( $value ) <= 64 ? $value : 'initial';
	}

	/**
	 * SQL formats for canonical issue updates/inserts.
	 *
	 * @param bool $include_first_seen Include first-seen columns.
	 * @return string[]
	 */
	private static function issue_formats( $include_first_seen = false ) {
		$formats = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d' );
		if ( $include_first_seen ) {
			$formats[] = '%s';
			$formats[] = '%d';
		}
		return $formats;
	}

	/**
	 * Static-only class.
	 */
	private function __construct() {}
}
