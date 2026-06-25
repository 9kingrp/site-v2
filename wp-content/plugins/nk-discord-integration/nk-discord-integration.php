<?php
/**
 * Plugin Name: Nine Kings — Discord Integration
 * Description: Discord OAuth2 login, WooCommerce virtual store optimizations, and Discord notifications for a FiveM asset store.
 * Version: 1.0.0
 * Author: Nine Kings
 * Text Domain: nk-discord
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'NK_DISCORD_VERSION', '1.1.0' );
define( 'NK_DISCORD_FILE', __FILE__ );
define( 'NK_DISCORD_DIR', plugin_dir_path( __FILE__ ) );
define( 'NK_DISCORD_URL', plugin_dir_url( __FILE__ ) );

// Load plugin files
require_once NK_DISCORD_DIR . 'includes/class-nk-discord-settings.php';
require_once NK_DISCORD_DIR . 'includes/class-nk-discord-auth.php';
require_once NK_DISCORD_DIR . 'includes/class-nk-discord-user.php';
require_once NK_DISCORD_DIR . 'includes/class-nk-discord-woocommerce.php';
require_once NK_DISCORD_DIR . 'includes/class-nk-discord-shortcodes.php';
require_once NK_DISCORD_DIR . 'includes/class-nk-discord-notifications.php';
require_once NK_DISCORD_DIR . 'includes/class-nk-discord-welcome.php';
require_once NK_DISCORD_DIR . 'includes/class-nk-discord-roles.php';
require_once NK_DISCORD_DIR . 'includes/class-nk-discord-packages.php';
require_once NK_DISCORD_DIR . 'includes/class-nk-discord-voucher.php';
require_once NK_DISCORD_DIR . 'includes/class-nk-discord-1of1.php';
require_once NK_DISCORD_DIR . 'includes/class-nk-discord-blacklist.php';
require_once NK_DISCORD_DIR . 'includes/class-nk-discord-bogo.php';
require_once NK_DISCORD_DIR . 'includes/class-nk-discord-staff-perks.php';

/**
 * Initialize the plugin.
 */
function nk_discord_init() {
	NK_Discord_Settings::instance();
	NK_Discord_Auth::instance();
	NK_Discord_User::instance();
	NK_Discord_WooCommerce::instance();
	NK_Discord_Shortcodes::instance();
	NK_Discord_Notifications::instance();
	NK_Discord_Welcome::instance();
	NK_Discord_Roles::instance();
	NK_Discord_Packages::instance();
	NK_Discord_Voucher::instance();
	NK_Discord_1of1::instance();
	NK_Discord_Blacklist::instance();
	NK_Discord_BOGO::instance();
	NK_Discord_Staff_Perks::instance();
}
add_action( 'plugins_loaded', 'nk_discord_init' );

/**
 * Activation hook.
 */
function nk_discord_activate() {
	// Add the 'customer' role capabilities if needed
	flush_rewrite_rules();
}
register_activation_hook( __FILE__, 'nk_discord_activate' );

/**
 * Deactivation hook.
 */
function nk_discord_deactivate() {
	flush_rewrite_rules();
}
register_deactivation_hook( __FILE__, 'nk_discord_deactivate' );
