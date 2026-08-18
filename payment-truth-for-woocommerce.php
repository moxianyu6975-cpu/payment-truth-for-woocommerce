<?php
/**
 * Plugin Name: Payment Truth for WooCommerce
 * Description: Reconciles WooCommerce orders with Stripe and reports payment status, amount, and currency mismatches before they become lost revenue.
 * Version:     0.1.0
 * Author:      PluginMosaic
 * Author URI:  https://profiles.wordpress.org/pluginmosaic/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: payment-truth-for-woocommerce
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Tested up to: 7.0
 * WC requires at least: 8.2
 * WC tested up to: 10.9
 *
 * @package PaymentTruthForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'PTWC_VERSION', '0.1.0' );
define( 'PTWC_DB_VERSION', '1' );
define( 'PTWC_FILE', __FILE__ );
define( 'PTWC_PATH', plugin_dir_path( __FILE__ ) );
define( 'PTWC_URL', plugin_dir_url( __FILE__ ) );

require_once PTWC_PATH . 'includes/class-ptwc-installer.php';
require_once PTWC_PATH . 'includes/class-ptwc-repository.php';
require_once PTWC_PATH . 'includes/class-ptwc-reconciler.php';
require_once PTWC_PATH . 'includes/class-ptwc-stripe-provider.php';
require_once PTWC_PATH . 'includes/class-ptwc-notifier.php';
require_once PTWC_PATH . 'includes/class-ptwc-scanner.php';
require_once PTWC_PATH . 'includes/class-ptwc-admin.php';
require_once PTWC_PATH . 'includes/class-ptwc-plugin.php';

register_activation_hook( PTWC_FILE, array( 'PTWC_Installer', 'activate' ) );
register_deactivation_hook( PTWC_FILE, array( 'PTWC_Installer', 'deactivate' ) );

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( '\\Automattic\\WooCommerce\\Utilities\\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
				'custom_order_tables',
				PTWC_FILE,
				true
			);
		}
	}
);

/**
 * Start after WooCommerce and payment gateway plugins are available.
 *
 * @return void
 */
function ptwc_bootstrap() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'ptwc_missing_woocommerce_notice' );
		return;
	}

	PTWC_Plugin::instance()->run();
}
add_action( 'plugins_loaded', 'ptwc_bootstrap', 30 );

/**
 * Explain why the plugin cannot start without WooCommerce.
 *
 * @return void
 */
function ptwc_missing_woocommerce_notice() {
	if ( ! current_user_can( 'activate_plugins' ) ) {
		return;
	}
	?>
	<div class="notice notice-error">
		<p><?php esc_html_e( 'Payment Truth for WooCommerce requires WooCommerce to be installed and active.', 'payment-truth-for-woocommerce' ); ?></p>
	</div>
	<?php
}
