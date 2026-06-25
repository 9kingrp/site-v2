<?php
/**
 * Staff Perks — Discord role-based benefits for staff members.
 *
 * Features:
 *  1. 15% discount on all purchases for users with the Staff Discord role.
 *
 * Staff detection is done by querying the Discord API for the user's guild member
 * roles and checking against the configured Staff Role ID.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NK_Discord_Staff_Perks {

	private static $instance = null;

	/* ── User meta keys ────────────────────────────────────────────────── */
	const META_IS_STAFF_CACHE       = '_nk_discord_is_staff';
	const META_STAFF_CACHE_EXPIRES  = '_nk_discord_staff_cache_expires';

	/* ── Cache duration (1 hour) ───────────────────────────────────────── */
	const CACHE_TTL = 3600;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// ── 15% staff discount on all purchases ──────────────────────────
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_staff_vehicle_discount' ) );

		// ── Display staff perks info on My Account dashboard ──────────────
		add_action( 'woocommerce_account_dashboard', array( $this, 'display_staff_dashboard_info' ), 20 );

		// ── Show staff discount badge on product archive pages ─────────────
		add_action( 'woocommerce_after_shop_loop_item_title', array( $this, 'display_staff_badge_archive' ), 15 );

		// ── Cart notice about staff discount ──────────────────────────────
		add_action( 'woocommerce_before_cart', array( $this, 'cart_staff_notice' ) );

		// ── Enqueue staff perks CSS ───────────────────────────────────────
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// ── Refresh staff status on login ─────────────────────────────────
		add_action( 'wp_login', array( $this, 'refresh_staff_status_on_login' ), 10, 2 );

		// ── Handle force-refresh query param early ───────────────────────
		add_action( 'init', array( $this, 'maybe_force_refresh_staff_cache' ) );
	}

	/* =====================================================================
	   STAFF DETECTION
	   ===================================================================== */

	/**
	 * Check if a user is a member of the staff Discord server.
	 * Uses a cached value to avoid hitting the Discord API on every page load.
	 */
	public static function is_staff( $user_id = null ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		if ( ! $user_id ) {
			error_log( '[NK Staff Perks] is_staff: No user ID — returning false.' );
			return false;
		}

		$staff_guild_id = NK_Discord_Settings::get( 'staff_guild_id' );
		if ( ! $staff_guild_id ) {
			error_log( '[NK Staff Perks] is_staff: staff_guild_id setting is empty — returning false.' );
			return false;
		}

		// Check cache
		$cache_expires = get_user_meta( $user_id, self::META_STAFF_CACHE_EXPIRES, true );
		if ( $cache_expires && time() < (int) $cache_expires ) {
			$cached = get_user_meta( $user_id, self::META_IS_STAFF_CACHE, true );
			error_log( '[NK Staff Perks] is_staff: Using cached value for user ' . $user_id . ': ' . $cached );
			return ( $cached === 'yes' );
		}

		// Fetch from Discord API
		error_log( '[NK Staff Perks] is_staff: Cache expired/missing for user ' . $user_id . ', querying Discord API...' );
		$is_staff = self::check_discord_staff_guild_membership( $user_id, $staff_guild_id );

		// Cache the result
		update_user_meta( $user_id, self::META_IS_STAFF_CACHE, $is_staff ? 'yes' : 'no' );
		update_user_meta( $user_id, self::META_STAFF_CACHE_EXPIRES, time() + self::CACHE_TTL );

		error_log( '[NK Staff Perks] is_staff: Discord API result for user ' . $user_id . ': ' . ( $is_staff ? 'YES' : 'NO' ) );
		return $is_staff;
	}

	/**
	 * Query the Discord API to check if a user is a member of the staff guild.
	 * Uses the Bot token — the bot must also be in the staff Discord server.
	 */
	private static function check_discord_staff_guild_membership( $user_id, $staff_guild_id ) {
		$discord_id = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_ID, true );
		if ( ! $discord_id ) {
			error_log( '[NK Staff Perks] Guild check: No Discord ID stored for WP user ' . $user_id );
			return false;
		}

		$bot_token = NK_Discord_Settings::get( 'bot_token' );
		if ( ! $bot_token ) {
			error_log( '[NK Staff Perks] Guild check: No bot_token configured.' );
			return false;
		}

		error_log( '[NK Staff Perks] Guild check: Querying guild=' . $staff_guild_id . ' for discord_id=' . $discord_id );

		// Check if the user is a member of the staff guild
		$url = 'https://discord.com/api/v10/guilds/' . $staff_guild_id . '/members/' . $discord_id;

		$response = wp_remote_get( $url, array(
			'headers' => array(
				'Authorization' => 'Bot ' . $bot_token,
			),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			error_log( '[NK Discord] Staff guild check error: ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		error_log( '[NK Staff Perks] Guild check HTTP ' . $code . ' for URL: ' . $url );
		error_log( '[NK Staff Perks] Guild check response body: ' . $body );

		// 200 = user is a member; 404 = user is not in that guild
		if ( $code === 200 ) {
			return true;
		}

		return false;
	}

	/**
	 * Handle ?nk_refresh_staff=1 early on init, before cart calculation.
	 */
	public function maybe_force_refresh_staff_cache() {
		if ( ! isset( $_GET['nk_refresh_staff'] ) || $_GET['nk_refresh_staff'] !== '1' ) {
			return;
		}
		if ( ! is_user_logged_in() ) {
			return;
		}
		$user_id = get_current_user_id();
		error_log( '[NK Staff Perks] Force-refresh (init): Clearing cache for user ' . $user_id );
		delete_user_meta( $user_id, self::META_STAFF_CACHE_EXPIRES );
		delete_user_meta( $user_id, self::META_IS_STAFF_CACHE );
	}

	/**
	 * Refresh staff status when user logs in.
	 */
	public function refresh_staff_status_on_login( $user_login, $user ) {
		// Invalidate cache so it refreshes on next check
		delete_user_meta( $user->ID, self::META_STAFF_CACHE_EXPIRES );
	}

	/* =====================================================================
	   15% STAFF DISCOUNT ON ALL PURCHASES
	   ===================================================================== */

	/**
	 * Apply a 15% discount on all products in the cart for staff members.
	 */
	public function apply_staff_vehicle_discount( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		if ( ! is_user_logged_in() ) {
			return;
		}

		if ( ! self::is_staff() ) {
			return;
		}

		$discount = 0.0;

		foreach ( $cart->get_cart() as $cart_item ) {
			$price = (float) $cart_item['data']->get_price();
			$qty   = (int) $cart_item['quantity'];
			$discount += ( $price * 0.15 ) * $qty;
		}

		if ( $discount <= 0 ) {
			return;
		}

		$cart->add_fee(
			__( 'Staff Discount (15%)', 'nk-discord' ),
			-$discount,
			false
		);
	}

	/* =====================================================================
	   FRONTEND UI — Single Product Page
	   ===================================================================== */

	/**
	 * Display staff discount badge on archive pages.
	 */
	public function display_staff_badge_archive() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		if ( ! self::is_staff() ) {
			return;
		}

		global $product;
		if ( ! $product ) {
			return;
		}

		echo '<span class="nk-staff-discount-badge">' . esc_html__( '15% Staff Discount', 'nk-discord' ) . '</span>';
	}

	/* =====================================================================
	   FRONTEND UI — My Account Dashboard
	   ===================================================================== */

	/**
	 * Display staff perks information on the My Account dashboard.
	 */
	public function display_staff_dashboard_info() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();
		if ( ! self::is_staff( $user_id ) ) {
			return;
		}

		echo '<div class="nk-staff-perks-dashboard">';
		echo '<h3>' . esc_html__( 'Staff Perks', 'nk-discord' ) . ' 🎖️</h3>';
		echo '<div class="nk-staff-perks-list">';

		// 15% discount info
		echo '<div class="nk-staff-perk-item">';
		echo '<strong>' . esc_html__( '15% Discount on All Purchases', 'nk-discord' ) . '</strong>';
		echo '<p>' . esc_html__( 'Automatically applied to all purchases at checkout.', 'nk-discord' ) . '</p>';
		echo '</div>';

		echo '</div>';
		echo '</div>';
	}

	/* =====================================================================
	   CART UI
	   ===================================================================== */

	/**
	 * Show a notice in the cart about staff discount.
	 */
	public function cart_staff_notice() {
		if ( ! is_user_logged_in() || ! self::is_staff() ) {
			return;
		}

		if ( WC()->cart->get_cart_contents_count() > 0 ) {
			wc_print_notice(
				__( '🎖️ Staff Discount: 15% off all purchases has been applied automatically.', 'nk-discord' ),
				'success'
			);
		}
	}

	/* =====================================================================
	   ASSETS
	   ===================================================================== */

	/**
	 * Enqueue CSS for staff perks UI.
	 */
	public function enqueue_assets() {
		if ( ! is_user_logged_in() || ! self::is_staff() ) {
			return;
		}

		wp_enqueue_style(
			'nk-discord-staff-perks',
			NK_DISCORD_URL . 'assets/css/staff-perks.css',
			array(),
			NK_DISCORD_VERSION
		);
	}
}
