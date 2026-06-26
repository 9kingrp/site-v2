<?php
/**
 * Plugin Name: NK Category Discounts
 * Description: Schedule percentage discounts on selected WooCommerce product categories. Overrides any other product sale prices while active. Subscription products are excluded.
 * Version:     1.0.0
 * Author:      NK
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * Text Domain: nk-category-discounts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NK_CD_VERSION', '1.0.0' );
define( 'NK_CD_OPTION', 'nk_cd_rules' );
define( 'NK_CD_FILE', __FILE__ );
define( 'NK_CD_DIR', plugin_dir_path( __FILE__ ) );
define( 'NK_CD_URL', plugin_dir_url( __FILE__ ) );

require_once NK_CD_DIR . 'includes/class-nk-cd-rules.php';
require_once NK_CD_DIR . 'includes/class-nk-cd-pricing.php';
require_once NK_CD_DIR . 'includes/class-nk-cd-admin.php';

/**
 * Bootstrap once all plugins (incl. WooCommerce) are loaded.
 */
function nk_cd_init() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		add_action( 'admin_notices', 'nk_cd_missing_wc_notice' );
		return;
	}

	NK_CD_Pricing::instance()->hooks();

	if ( is_admin() ) {
		NK_CD_Admin::instance()->hooks();
	}
}
add_action( 'plugins_loaded', 'nk_cd_init', 20 );

function nk_cd_missing_wc_notice() {
	echo '<div class="notice notice-error"><p>';
	echo esc_html__( 'NK Category Discounts requires WooCommerce to be active.', 'nk-category-discounts' );
	echo '</p></div>';
}

/**
 * Declare HPOS / custom order tables compatibility (this plugin does not touch orders,
 * but declaring compatibility avoids the incompatibility warning).
 */
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', NK_CD_FILE, true );
	}
} );
