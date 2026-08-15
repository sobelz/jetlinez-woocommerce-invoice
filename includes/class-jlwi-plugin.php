<?php
/**
 * Main plugin bootstrap.
 *
 * @package JetlinezWooCommerceInvoice
 */

defined( 'ABSPATH' ) || exit;

final class JLWI_Plugin {

	/** @var JLWI_Plugin|null */
	private static $instance = null;

	/** @var JLWI_Sender|null */
	private $sender = null;

	/** @var JLWI_Admin|null */
	private $admin = null;

	/** @var JLWI_Daily_Report|null */
	private $daily_report = null;

	/**
	 * Singleton accessor.
	 *
	 * @return JLWI_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Activation defaults.
	 *
	 * @return void
	 */
	public static function activate() {
		if ( false === get_option( JLWI_OPTION, false ) ) {
			add_option( JLWI_OPTION, JLWI_Settings::defaults(), '', 'no' );
		}

		JLWI_Daily_Report::reschedule();
	}

	/**
	 * Remove report scheduling when the plugin is deactivated.
	 *
	 * @return void
	 */
	public static function deactivate() {
		JLWI_Daily_Report::clear_schedule();
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		load_plugin_textdomain(
			JLWI_TEXT_DOMAIN,
			false,
			dirname( plugin_basename( JLWI_FILE ) ) . '/languages/'
		);

		add_filter( 'plugin_action_links_' . plugin_basename( JLWI_FILE ), array( $this, 'plugin_action_links' ) );

		$this->admin = new JLWI_Admin();
		$this->admin->register_hooks();

		if ( ! $this->is_woocommerce_active() ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		$this->sender = new JLWI_Sender();
		$this->sender->register_hooks();

		$this->daily_report = new JLWI_Daily_Report();
		$this->daily_report->register_hooks();
	}

	/**
	 * Is WooCommerce available?
	 *
	 * @return bool
	 */
	private function is_woocommerce_active() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_order' );
	}

	/**
	 * Settings link on Plugins screen.
	 *
	 * @param array $links Existing links.
	 * @return array
	 */
	public function plugin_action_links( $links ) {
		$url = class_exists( 'WooCommerce' ) ? admin_url( 'admin.php?page=jlwi-settings' ) : admin_url( 'options-general.php?page=jlwi-settings' );
		array_unshift(
			$links,
			'<a href="' . esc_url( $url ) . '">' . esc_html__( 'تنظیمات', JLWI_TEXT_DOMAIN ) . '</a>'
		);

		return $links;
	}

	/**
	 * WooCommerce dependency notice.
	 *
	 * @return void
	 */
	public function woocommerce_missing_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		echo esc_html__( 'افزونه Jetlinez Invoice برای اجرا به WooCommerce فعال نیاز دارد.', JLWI_TEXT_DOMAIN );
		echo '</p></div>';
	}
}
