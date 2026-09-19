<?php
/**
 * Database schema definitions.
 *
 * @package CoderEmbassy\AWAC
 */

namespace CoderEmbassy\AWAC\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Produces dbDelta-compatible table definitions.
 *
 * WordPress does not reliably support foreign-key constraints through
 * dbDelta. Relationship columns are therefore indexed logical references.
 */
final class Schema {
	/**
	 * Plugin table suffixes.
	 *
	 * @var string[]
	 */
	private const TABLES = array(
		'ceaw_scan_runs',
		'ceaw_scan_pages',
		'ceaw_issues',
		'ceaw_issue_occurrences',
		'ceaw_dismissals',
		'ceaw_fixes',
		'ceaw_manual_checks',
	);

	/**
	 * Get fully qualified table names.
	 *
	 * @param string $prefix WordPress database prefix.
	 * @return string[]
	 */
	public static function table_names( $prefix ) {
		return array_map(
			static function ( $suffix ) use ( $prefix ) {
				return $prefix . $suffix;
			},
			self::TABLES
		);
	}

	/**
	 * Get all CREATE TABLE statements.
	 *
	 * All timestamps are stored in UTC. Enum-like varchar values are validated
	 * by repositories before insertion so migrations remain portable across the
	 * MySQL and MariaDB versions supported by WordPress.
	 *
	 * @param string $prefix          WordPress database prefix.
	 * @param string $charset_collate wpdb charset/collation clause.
	 * @return string[]
	 */
	public static function statements( $prefix, $charset_collate ) {
		$tables = self::table_names( $prefix );

		return array(
			"CREATE TABLE {$tables[0]} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				started_at datetime NOT NULL,
				finished_at datetime DEFAULT NULL,
				profile varchar(16) NOT NULL,
				mode varchar(16) NOT NULL,
				axe_version varchar(16) NOT NULL,
				ruleset_version varchar(16) NOT NULL,
				standard varchar(16) NOT NULL,
				en301549_version varchar(16) NOT NULL,
				trigger_type varchar(24) NOT NULL DEFAULT 'manual',
				status varchar(16) NOT NULL DEFAULT 'pending',
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				error_code varchar(64) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY started_at (started_at),
				KEY status_started (status,started_at),
				KEY profile_mode (profile,mode)
			) {$charset_collate};",
			"CREATE TABLE {$tables[1]} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				run_id bigint(20) unsigned NOT NULL,
				page_url varchar(2048) NOT NULL,
				page_key char(64) NOT NULL,
				page_type varchar(32) NOT NULL,
				scenario varchar(64) NOT NULL DEFAULT 'initial',
				viewport varchar(16) NOT NULL DEFAULT 'desktop',
				markup_context varchar(16) NOT NULL DEFAULT 'unknown',
				issues_critical int(10) unsigned NOT NULL DEFAULT 0,
				issues_serious int(10) unsigned NOT NULL DEFAULT 0,
				issues_moderate int(10) unsigned NOT NULL DEFAULT 0,
				issues_minor int(10) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY run_id (run_id),
				KEY page_key (page_key),
				KEY page_type_scenario (page_type,scenario),
				UNIQUE KEY run_page_state (run_id,page_key,scenario,viewport)
			) {$charset_collate};",
			"CREATE TABLE {$tables[2]} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				fingerprint char(64) NOT NULL,
				page_key char(64) NOT NULL,
				page_type varchar(32) NOT NULL,
				scenario varchar(64) NOT NULL DEFAULT 'initial',
				rule_id varchar(128) NOT NULL,
				rule_source varchar(8) NOT NULL DEFAULT 'axe',
				wcag_tags longtext NOT NULL,
				en301549_refs longtext NOT NULL,
				impact varchar(16) NOT NULL DEFAULT '',
				targets longtext NOT NULL,
				selector text NOT NULL,
				html_excerpt text NOT NULL,
				failure_summary text NOT NULL,
				help_url varchar(500) NOT NULL DEFAULT '',
				component_type varchar(64) NOT NULL DEFAULT '',
				component_name varchar(128) NOT NULL DEFAULT '',
				component_version varchar(32) NOT NULL DEFAULT '',
				lifecycle_status varchar(16) NOT NULL DEFAULT 'open',
				disposition varchar(16) NOT NULL DEFAULT 'actionable',
				remediation varchar(16) NOT NULL DEFAULT 'none',
				first_seen_at datetime NOT NULL,
				last_seen_at datetime NOT NULL,
				resolved_at datetime DEFAULT NULL,
				first_seen_run_id bigint(20) unsigned NOT NULL,
				last_seen_run_id bigint(20) unsigned NOT NULL,
				last_page_id bigint(20) unsigned NOT NULL,
				fixable tinyint(1) NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				UNIQUE KEY fingerprint (fingerprint),
				KEY page_state (page_key,scenario),
				KEY last_seen_run_id (last_seen_run_id),
				KEY status_impact (lifecycle_status,impact),
				KEY disposition (disposition)
			) {$charset_collate};",
			"CREATE TABLE {$tables[3]} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				issue_id bigint(20) unsigned NOT NULL,
				fingerprint char(64) NOT NULL,
				run_id bigint(20) unsigned NOT NULL,
				page_id bigint(20) unsigned NOT NULL,
				observed_at datetime NOT NULL,
				impact varchar(16) NOT NULL DEFAULT '',
				targets longtext NOT NULL,
				selector text NOT NULL,
				html_excerpt text NOT NULL,
				failure_summary text NOT NULL,
				help_url varchar(500) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				UNIQUE KEY run_page_issue (run_id,page_id,fingerprint),
				KEY issue_id (issue_id),
				KEY fingerprint (fingerprint),
				KEY run_id (run_id),
				KEY page_id (page_id)
			) {$charset_collate};",
			"CREATE TABLE {$tables[4]} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				issue_id bigint(20) unsigned NOT NULL,
				fingerprint char(64) NOT NULL,
				user_id bigint(20) unsigned NOT NULL,
				dismissed_at datetime NOT NULL,
				note text NOT NULL,
				reinstated_at datetime DEFAULT NULL,
				reinstated_by bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY issue_id (issue_id),
				KEY fingerprint (fingerprint),
				KEY active_dismissal (fingerprint,reinstated_at),
				KEY user_id (user_id)
			) {$charset_collate};",
			"CREATE TABLE {$tables[5]} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				fix_time datetime NOT NULL,
				fix_type varchar(64) NOT NULL,
				target varchar(512) NOT NULL,
				context varchar(64) NOT NULL DEFAULT '',
				issue_id bigint(20) unsigned NOT NULL,
				issue_fingerprint char(64) NOT NULL,
				before_state longtext NOT NULL,
				active tinyint(1) NOT NULL DEFAULT 1,
				PRIMARY KEY  (id),
				KEY fix_type (fix_type),
				KEY issue_id (issue_id),
				KEY issue_fingerprint (issue_fingerprint),
				KEY active (active)
			) {$charset_collate};",
			"CREATE TABLE {$tables[6]} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				check_key varchar(128) NOT NULL,
				context varchar(128) NOT NULL DEFAULT 'site',
				status varchar(16) NOT NULL DEFAULT 'not_tested',
				notes text NOT NULL,
				tester_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				tester_name varchar(191) NOT NULL DEFAULT '',
				checked_at datetime NOT NULL,
				evidence_ref text NOT NULL,
				supersedes_id bigint(20) unsigned NOT NULL DEFAULT 0,
				active tinyint(1) NOT NULL DEFAULT 1,
				PRIMARY KEY  (id),
				KEY check_context (check_key,context),
				KEY status (status),
				KEY active (active),
				KEY checked_at (checked_at)
			) {$charset_collate};",
		);
	}

	/**
	 * Static-only class.
	 */
	private function __construct() {}
}
