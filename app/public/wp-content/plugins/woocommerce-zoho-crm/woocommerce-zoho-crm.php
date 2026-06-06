<?php
/**
 * Plugin Name: WooCommerce Zoho CRM Integration
 * Description: Sync WooCommerce orders to Zoho CRM as Contacts and Deals/Leads.
 * Version: 1.1.5
 * Author: Mohanad Abdellah
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 7.0
 * Text Domain: woocommerce-zoho-crm
 *
 * @package WooCommerce_Zoho_CRM
 */

defined( 'ABSPATH' ) || exit;

define( 'WCZC_VERSION', '1.1.5' );
define( 'WCZC_PLUGIN_FILE', __FILE__ );
define( 'WCZC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

require_once WCZC_PLUGIN_DIR . 'includes/class-wczc-logger.php';
require_once WCZC_PLUGIN_DIR . 'includes/class-wczc-zoho-api.php';
require_once WCZC_PLUGIN_DIR . 'includes/class-wczc-oauth.php';
require_once WCZC_PLUGIN_DIR . 'includes/class-wczc-order-handler.php';
require_once WCZC_PLUGIN_DIR . 'includes/class-wczc-settings.php';

/**
 * Bootstrap plugin after WooCommerce loads.
 */
function wczc_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action(
			'admin_notices',
			static function () {
				echo '<div class="notice notice-error"><p>';
				echo esc_html__( 'WooCommerce Zoho CRM Integration requires WooCommerce to be active.', 'woocommerce-zoho-crm' );
				echo '</p></div>';
			}
		);
		return;
	}

	WCZC_Settings::maybe_migrate_local_config();
	WCZC_Settings::init();
	WCZC_OAuth::init();
	WCZC_Order_Handler::init();
}
add_action( 'plugins_loaded', 'wczc_init' );

register_activation_hook(
	__FILE__,
	static function () {
		if ( ! get_option( 'wczc_settings' ) ) {
			add_option( 'wczc_settings', WCZC_Settings::defaults() );
		}
		set_transient( 'wczc_activation_redirect', 1, MINUTE_IN_SECONDS );
	}
);
