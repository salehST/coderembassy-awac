<?php
/**
 * Evidence-first progress report data.
 *
 * @package CoderEmbassy\AWAC
 */

namespace CoderEmbassy\AWAC\Reports;

use CoderEmbassy\AWAC\REST\IssueRepository;
use CoderEmbassy\AWAC\REST\ManualCheckRepository;
use CoderEmbassy\AWAC\REST\ScanRunRepository;
use CoderEmbassy\AWAC\REST\StatementRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Composes the report from the canonical evidence repositories.
 */
final class ReportRepository {
	const MAX_FINDINGS   = 100;
	const MAX_RUNS       = 100;
	const MAX_DISMISSALS = 500;

	/**
	 * Build the current evidence report.
	 *
	 * The Free report contains accessible progress evidence. Pro-only exports
	 * receive fix history and the EN 301 549 matrix through the same contract.
	 *
	 * @return array<string,mixed>
	 */
	public static function build() {
		$issues = IssueRepository::query(
			array(
				'page'             => 1,
				'per_page'         => self::MAX_FINDINGS,
				'order'            => 'desc',
				'lifecycle_status' => '',
				'rule_source'      => '',
				'impact'           => '',
				'disposition'      => '',
				'profile'          => '',
			)
		);
		$runs   = ScanRunRepository::query(
			array(
				'page'     => 1,
				'per_page' => self::MAX_RUNS,
				'order'    => 'desc',
				'status'   => '',
				'profile'  => '',
				'mode'     => '',
			)
		);
		$manual = ManualCheckRepository::all();
		$items  = isset( $issues['items'] ) && is_array( $issues['items'] ) ? $issues['items'] : array();

		$report = array(
			'meta'           => self::meta(),
			'scope'          => array(
				'findings_total'     => absint( $issues['total'] ?? count( $items ) ),
				'findings_included'  => count( $items ),
				'findings_truncated' => absint( $issues['total'] ?? count( $items ) ) > count( $items ),
				'runs_total'         => absint( $runs['total'] ?? 0 ),
				'runs_included'      => isset( $runs['items'] ) && is_array( $runs['items'] ) ? count( $runs['items'] ) : 0,
			),
			'summary'        => IssueRepository::summary(),
			'findings'       => $items,
			'change_history' => isset( $runs['items'] ) && is_array( $runs['items'] ) ? $runs['items'] : array(),
			'manual_checks'  => $manual,
			'dismissals'     => IssueRepository::dismissal_history( self::MAX_DISMISSALS ),
			'human_review'   => self::filter_by_disposition( $items, 'needs_review' ),
			'exclusions'     => self::exclusions( $items ),
			'statement'      => self::statement_meta(),
			'limitations'    => self::limitations(),
		);

		return $report;
	}

	/**
	 * Build compact CSV rows from report findings.
	 *
	 * @param array<string,mixed> $report Report payload.
	 * @return array<int,array<int,string>>
	 */
	public static function csv_rows( array $report ) {
		$rows = array(
			array( 'fingerprint', 'page_url', 'page_type', 'scenario', 'impact', 'rule_id', 'rule_source', 'lifecycle_status', 'disposition', 'wcag_tags', 'en301549_refs', 'last_seen_at' ),
		);

		foreach ( isset( $report['findings'] ) && is_array( $report['findings'] ) ? $report['findings'] : array() as $finding ) {
			$rows[] = array(
				(string) ( $finding['fingerprint'] ?? '' ),
				(string) ( $finding['page_url'] ?? '' ),
				(string) ( $finding['page_type'] ?? '' ),
				(string) ( $finding['scenario'] ?? '' ),
				(string) ( $finding['impact'] ?? '' ),
				(string) ( $finding['rule_id'] ?? '' ),
				(string) ( $finding['rule_source'] ?? '' ),
				(string) ( $finding['lifecycle_status'] ?? '' ),
				(string) ( $finding['disposition'] ?? '' ),
				self::csv_value( $finding['wcag_tags'] ?? array() ),
				self::csv_value( $finding['en301549_refs'] ?? array() ),
				(string) ( $finding['last_seen_at'] ?? '' ),
			);
		}

		return $rows;
	}

	/**
	 * Report metadata.
	 *
	 * @return array<string,string>
	 */
	private static function meta() {
		return array(
			'generated_at'     => gmdate( 'c' ),
			'plugin_version'   => defined( 'CEAW_VERSION' ) ? CEAW_VERSION : '',
			'axe_version'      => defined( 'CEAW_AXE_VERSION' ) ? CEAW_AXE_VERSION : '',
			'ruleset_version'  => defined( 'CEAW_RULESET_VERSION' ) ? CEAW_RULESET_VERSION : '',
			'standard'         => defined( 'CEAW_STANDARD_VERSION' ) ? CEAW_STANDARD_VERSION : 'wcag22aa',
			'en301549_version' => defined( 'CEAW_EN301549_VERSION' ) ? CEAW_EN301549_VERSION : '3.2.1',
			'site_url'         => esc_url_raw( home_url( '/' ) ),
		);
	}

	/**
	 * Keep statement context small and avoid duplicating editable HTML.
	 *
	 * @return array<string,mixed>
	 */
	private static function statement_meta() {
		$statement = StatementRepository::get();
		return array(
			'status'       => sanitize_key( $statement['status'] ?? 'draft' ),
			'page_id'      => absint( $statement['page_id'] ?? 0 ),
			'page_url'     => esc_url_raw( $statement['page_url'] ?? '' ),
			'updated_at'   => sanitize_text_field( $statement['updated_at'] ?? '' ),
			'published_at' => sanitize_text_field( $statement['published_at'] ?? '' ),
		);
	}

	/**
	 * Honest report limitations.
	 *
	 * @return string[]
	 */
	private static function limitations() {
		return array(
			__( 'This report summarizes the pages and states that AWAC has evidence for; it is not a compliance certificate or legal conclusion.', 'coderembassy-awac' ),
			__( 'Automated results are bounded by the selected runner profiles, viewport, ruleset version, and stored scan history.', 'coderembassy-awac' ),
			__( 'Manual checks and assistive-technology review remain necessary for a complete accessibility assessment.', 'coderembassy-awac' ),
		);
	}

	/**
	 * Filter findings by disposition.
	 *
	 * @param array<int,array<string,mixed>> $items Findings.
	 * @param string                         $value Disposition.
	 * @return array<int,array<string,mixed>>
	 */
	private static function filter_by_disposition( array $items, $value ) {
		return array_values(
			array_filter(
				$items,
				static function ( $item ) use ( $value ) {
					return (string) ( $item['disposition'] ?? '' ) === $value;
				}
			)
		);
	}

	/**
	 * Return dismissed and accepted findings as explicit exclusions.
	 *
	 * @param array<int,array<string,mixed>> $items Findings.
	 * @return array<int,array<string,mixed>>
	 */
	private static function exclusions( array $items ) {
		return array_values(
			array_filter(
				$items,
				static function ( $item ) {
					return in_array( $item['disposition'] ?? '', array( 'dismissed', 'accepted' ), true );
				}
			)
		);
	}

	/**
	 * Encode one CSV cell from a bounded list.
	 *
	 * @param mixed $value Candidate list.
	 * @return string
	 */
	private static function csv_value( $value ) {
		return is_array( $value ) ? implode( '; ', array_map( 'sanitize_text_field', $value ) ) : sanitize_text_field( $value );
	}

	/**
	 * Static-only class.
	 */
	private function __construct() {}
}
