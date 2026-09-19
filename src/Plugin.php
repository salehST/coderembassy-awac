<?php
/**
 * Main plugin coordinator.
 *
 * @package CoderEmbassy\AWAC
 */

namespace CoderEmbassy\AWAC;

use CoderEmbassy\AWAC\Admin\AdminPage;
use CoderEmbassy\AWAC\Compatibility\WooCommerceFeatures;
use CoderEmbassy\AWAC\Database\Migrator;
use CoderEmbassy\AWAC\REST\RestController;
use CoderEmbassy\AWAC\REST\RunnerController;
use CoderEmbassy\AWAC\REST\ReportController;
use CoderEmbassy\AWAC\Runner\FrontEndRunner;
use CoderEmbassy\AWAC\Runner\ScanHygiene;
use CoderEmbassy\AWAC\Support\SafeMode;

defined( 'ABSPATH' ) || exit;

/**
 * Registers foundation services without doing work in the constructor.
 */
final class Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance;

	/**
	 * Whether hooks have already been registered.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Get the plugin instance.
	 *
	 * @return self
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public function boot() {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		WooCommerceFeatures::register();
		SafeMode::register_notice();
		AdminPage::register();
		RestController::register();
		ReportController::register();
		RunnerController::register();
		ScanHygiene::register();
		FrontEndRunner::register();

		add_action( 'plugins_loaded', array( Migrator::class, 'maybe_migrate' ), 5 );
		add_action( 'plugins_loaded', array( $this, 'check_dependencies' ), 20 );
		add_action( 'admin_notices', array( Migrator::class, 'render_admin_notice' ) );
	}

	/**
	 * Report a missing WooCommerce dependency without breaking the site.
	 *
	 * WordPress enforces the Requires Plugins header on modern installs. This
	 * runtime check protects upgrades and older WordPress installations.
	 *
	 * @return void
	 */
	public function check_dependencies() {
		if ( class_exists( 'WooCommerce' ) ) {
			return;
		}

		add_action(
			'admin_notices',
			static function () {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}

				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'CoderEmbassy AWAC requires WooCommerce to be installed and active.', 'coderembassy-awac' );
				echo '</p></div>';
			}
		);
	}

	/**
	 * Disallow direct construction.
	 */
	private function __construct() {}
}
