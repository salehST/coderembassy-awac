<?php
/**
 * Versioned database migration runner.
 *
 * @package CoderEmbassy\AWAC
 */

namespace CoderEmbassy\AWAC\Database;

use RuntimeException;
use Throwable;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades AWAC tables.
 */
final class Migrator {
	private const VERSION_OPTION = 'ceaw_db_version';
	private const ERROR_OPTION   = 'ceaw_db_migration_error';
	private const LOCK_OPTION    = 'ceaw_db_migration_lock';
	private const LOCK_TTL       = 300;

	/**
	 * Activation callback.
	 *
	 * @param bool $network_wide Whether activation was network-wide.
	 * @return void
	 */
	public static function activate( $network_wide = false ) {
		unset( $network_wide );
		self::migrate();
	}

	/**
	 * Run migrations after an update, since activation hooks do not run then.
	 *
	 * @return void
	 */
	public static function maybe_migrate() {
		$installed = (string) get_option( self::VERSION_OPTION, '0.0.0' );

		if ( version_compare( $installed, CEAW_DB_VERSION, '<' ) ) {
			self::migrate();
		}
	}

	/**
	 * Report a migration failure without exposing raw database errors.
	 *
	 * @return void
	 */
	public static function render_admin_notice() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! get_option( self::ERROR_OPTION, false ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'CoderEmbassy AWAC could not finish its database setup. Auditing and remediation are unavailable until the database error is resolved.', 'coderembassy-awac' );
		echo '</p></div>';
	}

	/**
	 * Execute pending migrations under a short-lived option lock.
	 *
	 * @return bool Whether this process completed the migration.
	 */
	public static function migrate() {
		if ( ! self::acquire_lock() ) {
			return false;
		}

		try {
			$installed = (string) get_option( self::VERSION_OPTION, '0.0.0' );

			foreach ( self::migrations() as $version => $migration ) {
				if ( version_compare( $installed, $version, '<' ) ) {
					call_user_func( $migration );
					self::verify_schema();
					update_option( self::VERSION_OPTION, $version, false );
					$installed = $version;
				}
			}

			delete_option( self::ERROR_OPTION );

			return version_compare( $installed, CEAW_DB_VERSION, '>=' );
		} catch ( Throwable $throwable ) {
			update_option(
				self::ERROR_OPTION,
				array(
					'code'        => 'schema_migration_failed',
					'message'     => $throwable->getMessage(),
					'occurred_at' => gmdate( 'Y-m-d H:i:s' ),
				),
				false
			);

			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()->error(
					'AWAC database migration failed: ' . $throwable->getMessage(),
					array( 'source' => 'awac' )
				);
			}

			return false;
		} finally {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Ordered migration map.
	 *
	 * @return array<string, callable>
	 */
	private static function migrations() {
		return array(
			'1.0.0' => array( self::class, 'migrate_to_1_0_0' ),
		);
	}

	/**
	 * Create the initial seven-table schema.
	 *
	 * @return void
	 */
	public static function migrate_to_1_0_0() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( Schema::statements( $wpdb->prefix, $wpdb->get_charset_collate() ) );
	}

	/**
	 * Verify every expected table before recording the new schema version.
	 *
	 * @return void
	 * @throws RuntimeException When an expected table is missing.
	 */
	private static function verify_schema() {
		global $wpdb;

		foreach ( Schema::table_names( $wpdb->prefix ) as $table ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

			if ( $found !== $table ) {
				throw new RuntimeException( sprintf( 'Expected AWAC table was not created: %s', $table ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal exception text, never rendered directly.
			}
		}
	}

	/**
	 * Acquire a simple atomic migration lock, replacing stale locks.
	 *
	 * @return bool
	 */
	private static function acquire_lock() {
		$now = time();

		if ( add_option( self::LOCK_OPTION, $now, '', false ) ) {
			return true;
		}

		$locked_at = (int) get_option( self::LOCK_OPTION, 0 );

		if ( $locked_at > 0 && ( $now - $locked_at ) < self::LOCK_TTL ) {
			return false;
		}

		delete_option( self::LOCK_OPTION );

		return add_option( self::LOCK_OPTION, $now, '', false );
	}

	/**
	 * Static-only class.
	 */
	private function __construct() {}
}
