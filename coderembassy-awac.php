<?php

/**
 * Plugin Name:       CoderEmbassy AWAC — Accessibility for WooCommerce
 * Plugin URI:        https://github.com/salehST/coderembassy-awac
 * Description:       Audits WooCommerce shopping journeys for accessibility issues and records local evidence for human review.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            CoderEmbassy
 * Author URI:        https://coderembassy.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       coderembassy-awac
 * Domain Path:       /languages
 * WC requires at least: 8.3
 * WC tested up to:   10.9
 *
 * @package CoderEmbassy\AWAC
 */

defined('ABSPATH') || exit;

define('CEAW_VERSION', '1.0.0');
define('CEAW_DB_VERSION', '1.0.0');
define('CEAW_AXE_VERSION', '4.11.4');
define('CEAW_RULESET_VERSION', '1.0.0');
define('CEAW_STANDARD_VERSION', 'wcag22aa');
define('CEAW_EN301549_VERSION', '3.2.1');
define('CEAW_DEFAULT_RETENTION_DAYS', 365);
define('CEAW_PLUGIN_FILE', __FILE__);
define('CEAW_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CEAW_PLUGIN_URL', plugin_dir_url(__FILE__));

$ceaw_autoloader = CEAW_PLUGIN_DIR . 'vendor/autoload.php';

if (! is_readable($ceaw_autoloader)) {
	add_action(
		'admin_notices',
		static function () {
			if (! current_user_can('activate_plugins')) {
				return;
			}

			echo '<div class="notice notice-error"><p>';
			echo esc_html__('CoderEmbassy AWAC cannot start because its Composer dependencies are missing. Install production dependencies or use a packaged release.', 'coderembassy-awac');
			echo '</p></div>';
		}
	);

	return;
}

require_once $ceaw_autoloader;

register_activation_hook(__FILE__, array(CoderEmbassy\AWAC\Database\Migrator::class, 'activate'));

CoderEmbassy\AWAC\Plugin::instance()->boot();
