<?php
/**
 * CoderEmbassy AWAC uninstall routine.
 *
 * Data is retained by default to protect audit evidence. A site owner must
 * explicitly define CEAW_REMOVE_DATA_ON_UNINSTALL as true before uninstalling
 * to request permanent removal.
 *
 * @package CoderEmbassy\AWAC
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! defined( 'CEAW_REMOVE_DATA_ON_UNINSTALL' ) || true !== CEAW_REMOVE_DATA_ON_UNINSTALL ) {
	return;
}

global $wpdb;

$ceaw_table_suffixes = array(
	'ceaw_scan_runs',
	'ceaw_scan_pages',
	'ceaw_issues',
	'ceaw_issue_occurrences',
	'ceaw_dismissals',
	'ceaw_fixes',
	'ceaw_manual_checks',
);

foreach ( $ceaw_table_suffixes as $ceaw_table_suffix ) {
	$ceaw_table = $wpdb->prefix . $ceaw_table_suffix;
	$wpdb->query( "DROP TABLE IF EXISTS `{$ceaw_table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Explicit opt-in uninstall cleanup.
}

delete_option( 'ceaw_db_version' );
delete_option( 'ceaw_db_migration_error' );
delete_option( 'ceaw_db_migration_lock' );
delete_option( 'ceaw_settings' );
