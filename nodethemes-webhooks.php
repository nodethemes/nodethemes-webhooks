<?php
/**
 * Plugin Name:       NodeThemes Webhooks - Reliable WooCommerce Webhooks for n8n, Make & Zapier
 * Plugin URI:        https://nodethemes.com/
 * Description:       Send WooCommerce events (new order, paid, status changes, cancellations) to n8n, Make, Zapier or any URL. Deliveries run in the background with automatic retries, HMAC signatures, a searchable delivery log and one-click resend, so no order is lost when your automation server is down.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Requires Plugins:  woocommerce
 * Author:            NodeThemes
 * Author URI:        https://nodethemes.com/
 * License:           GPL-2.0-or-later
 * Text Domain:       nodethemes-webhooks
 * Update URI:        https://nodethemes.com/nodethemes-webhooks
 * WC requires at least: 8.0
 * WC tested up to:   11.2
 *
 * @package NodeThemes_Webhooks
 */

defined( 'ABSPATH' ) || exit;

if ( defined( 'NTWH_VERSION' ) ) {
	return;
}

define( 'NTWH_VERSION', '1.0.0' );
define( 'NTWH_DB_VERSION', '1' );
define( 'NTWH_FILE', __FILE__ );
define( 'NTWH_DIR', plugin_dir_path( __FILE__ ) );
define( 'NTWH_URL', plugin_dir_url( __FILE__ ) );
define( 'NTWH_BASENAME', plugin_basename( __FILE__ ) );

foreach ( array( 'class-ntwh-db', 'pro-gate', 'class-ntwh-endpoints', 'class-ntwh-payload', 'class-ntwh-dispatcher', 'class-ntwh-events', 'class-ntwh-admin', 'class-ntwh-order-box' ) as $ntwh_f ) {
	require_once NTWH_DIR . 'includes/' . $ntwh_f . '.php';
}
unset( $ntwh_f );

// Free updates from nodethemes.com.
require_once NTWH_DIR . 'includes/updater.php';
NTWH_Free_Updater::boot( NTWH_FILE, NTWH_VERSION, 'https://nodethemes.com/' );

// HPOS + block checkout compatibility.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', NTWH_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', NTWH_FILE, true );
		}
	}
);

register_activation_hook( __FILE__, array( 'NTWH_Core', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'NTWH_Core', 'deactivate' ) );

/**
 * Bootstrap.
 */
final class NTWH_Core {

	/**
	 * Boot after all plugins are loaded.
	 */
	public static function boot() {
		if ( get_option( 'ntwh_db_version' ) !== NTWH_DB_VERSION ) {
			NTWH_DB::install();
		}
		add_action( 'ntwh_cleanup', array( 'NTWH_DB', 'cleanup' ) );
		add_action( 'init', array( __CLASS__, 'ensure_cron' ) );

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'missing_woo' ) );
			return;
		}
		NTWH_Dispatcher::init();
		NTWH_Events::init();
		if ( is_admin() ) {
			NTWH_Admin::init();
			NTWH_Order_Box::init();
		}
		do_action( 'ntwh_loaded' ); // The Pro add-on hooks in here.
	}

	/**
	 * Activation.
	 */
	public static function activate() {
		NTWH_DB::install();
		self::ensure_cron();
	}

	/**
	 * Deactivation: stop background jobs (queued deliveries stay in the log and are resumed on reactivation).
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'ntwh_cleanup' );
		wp_clear_scheduled_hook( 'ntwh_heal' );
	}

	/**
	 * Daily log cleanup.
	 */
	public static function ensure_cron() {
		if ( ! wp_next_scheduled( 'ntwh_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'ntwh_cleanup' );
		}
	}

	/**
	 * WooCommerce is required.
	 */
	public static function missing_woo() {
		if ( current_user_can( 'activate_plugins' ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'NodeThemes Webhooks needs WooCommerce to be installed and active.', 'nodethemes-webhooks' ) . '</p></div>';
		}
	}
}
add_action( 'plugins_loaded', array( 'NTWH_Core', 'boot' ) );
