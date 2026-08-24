<?php
/**
 * Plugin Name: Jetlinez Invoice for WooCommerce
 * Plugin URI:  https://my.jetlinez.com
 * Description: ارسال خودکار وضعیت سفارش، فاکتور PDF و گزارش‌های روزانه و هفتگی ووکامرس از طریق واتساپ جتلاینز، با پشتیبانی از PeproDev Ultimate Invoice و حالت جایگزین متنی.
 * Version:     1.9.0
 * Author:      Jetlinez
 * Author URI:  https://my.jetlinez.com
 * Update URI:  https://plugins.sobelz.ir/jetlinez-woocommerce-invoice
 * Text Domain: jetlinez-woocommerce-invoice
 * Domain Path: /languages
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 7.0
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

defined( 'ABSPATH' ) || exit;

define( 'JLWI_VERSION', '1.9.0' );
define( 'JLWI_FILE', __FILE__ );
define( 'JLWI_DIR', plugin_dir_path( __FILE__ ) );
define( 'JLWI_URL', plugin_dir_url( __FILE__ ) );
define( 'JLWI_OPTION', 'jlwi_settings' );
define( 'JLWI_REPORT_STATE_OPTION', 'jlwi_daily_report_state' );
define( 'JLWI_WEEKLY_REPORT_STATE_OPTION', 'jlwi_weekly_report_state' );
define( 'JLWI_TEXT_DOMAIN', 'jetlinez-woocommerce-invoice' );

require_once JLWI_DIR . 'includes/updater/class-sobelz-plugin-updater.php';
require_once JLWI_DIR . 'includes/class-jlwi-settings.php';
require_once JLWI_DIR . 'includes/class-jlwi-report-customers.php';
require_once JLWI_DIR . 'includes/class-jlwi-api-client.php';
require_once JLWI_DIR . 'includes/class-jlwi-template.php';
require_once JLWI_DIR . 'includes/class-jlwi-sender.php';
require_once JLWI_DIR . 'includes/class-jlwi-daily-report.php';
require_once JLWI_DIR . 'includes/class-jlwi-weekly-report.php';
require_once JLWI_DIR . 'includes/class-jlwi-admin.php';
require_once JLWI_DIR . 'includes/class-jlwi-plugin.php';

\Sobelz\PluginUpdater\V1\Updater::register(
	array(
		'plugin_file' => JLWI_FILE,
		'slug'        => 'jetlinez-woocommerce-invoice',
		'update_uri'  => 'https://plugins.sobelz.ir/jetlinez-woocommerce-invoice',
	)
);

register_activation_hook( JLWI_FILE, array( 'JLWI_Plugin', 'activate' ) );
register_deactivation_hook( JLWI_FILE, array( 'JLWI_Plugin', 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', JLWI_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	static function () {
		JLWI_Plugin::instance();
	},
	20
);
