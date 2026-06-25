<?php
/**
 * Discord role assignment/removal via Bot API.
 *
 * Assigns Discord roles on purchase/subscription activation.
 * Removes Discord roles on subscription cancellation/expiration.
 * Supports per-product role mapping via product meta.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NK_Discord_Roles {

	private static $instance = null;

	const META_PRODUCT_ROLE = '_nk_discord_role_id';
	const DISCORD_API       = 'https://discord.com/api/v10';

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

		// Add role field to product editor
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'add_product_role_field' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_role_field' ) );

		// Assign roles on order completion
		add_action( 'woocommerce_order_status_completed', array( $this, 'assign_roles_for_order' ), 30, 1 );

		// Subscription role management
		add_action( 'woocommerce_subscription_status_active', array( $this, 'assign_roles_for_subscription' ), 30, 1 );
		add_action( 'woocommerce_subscription_status_cancelled', array( $this, 'remove_roles_for_subscription' ), 30, 1 );
		add_action( 'woocommerce_subscription_status_expired', array( $this, 'remove_roles_for_subscription' ), 30, 1 );
		add_action( 'woocommerce_subscription_status_on-hold', array( $this, 'remove_roles_for_subscription' ), 30, 1 );
	}

	/**
	 * Add Discord Role ID field to product editor.
	 */
	public function add_product_role_field() {
		woocommerce_wp_text_input( array(
			'id'          => self::META_PRODUCT_ROLE,
			'label'       => __( 'Discord Role ID', 'nk-discord' ),
			'description' => __( 'Discord role to assign when this product is purchased. Leave blank for no role assignment.', 'nk-discord' ),
			'desc_tip'    => true,
			'type'        => 'text',
		) );
	}

	/**
	 * Save the Discord Role ID for a product.
	 */
	public function save_product_role_field( $post_id ) {
		if ( isset( $_POST[ self::META_PRODUCT_ROLE ] ) ) {
			update_post_meta( $post_id, self::META_PRODUCT_ROLE, sanitize_text_field( $_POST[ self::META_PRODUCT_ROLE ] ) );
		}
	}

	/**
	 * Assign Discord roles for all items in a completed order.
	 */
	public function assign_roles_for_order( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$user_id    = $order->get_user_id();
		$discord_id = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_ID, true );

		if ( ! $discord_id ) {
			return;
		}

		// Assign the global customer role
		$customer_role = NK_Discord_Settings::get( 'customer_role_id' );
		if ( $customer_role ) {
			$this->add_role( $discord_id, $customer_role );
		}

		// Assign per-product roles
		foreach ( $order->get_items() as $item ) {
			$product_id = $item->get_product_id();
			$role_id    = get_post_meta( $product_id, self::META_PRODUCT_ROLE, true );
			if ( $role_id ) {
				$this->add_role( $discord_id, $role_id );
			}
		}
	}

	/**
	 * Assign Discord roles for subscription items.
	 */
	public function assign_roles_for_subscription( $subscription ) {
		$user_id    = $subscription->get_user_id();
		$discord_id = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_ID, true );

		if ( ! $discord_id ) {
			return;
		}

		foreach ( $subscription->get_items() as $item ) {
			$product_id = $item->get_product_id();
			$role_id    = get_post_meta( $product_id, self::META_PRODUCT_ROLE, true );
			if ( $role_id ) {
				$this->add_role( $discord_id, $role_id );
			}
		}
	}

	/**
	 * Remove Discord roles when subscription is cancelled/expired/on-hold.
	 */
	public function remove_roles_for_subscription( $subscription ) {
		$user_id    = $subscription->get_user_id();
		$discord_id = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_ID, true );

		if ( ! $discord_id ) {
			return;
		}

		foreach ( $subscription->get_items() as $item ) {
			$product_id = $item->get_product_id();
			$role_id    = get_post_meta( $product_id, self::META_PRODUCT_ROLE, true );
			if ( $role_id ) {
				$this->remove_role( $discord_id, $role_id );
			}
		}
	}

	/**
	 * Add a Discord role to a user via the Bot API.
	 */
	public function add_role( $discord_user_id, $role_id ) {
		$guild_id  = NK_Discord_Settings::get( 'guild_id' );
		$bot_token = NK_Discord_Settings::get( 'bot_token' );

		if ( ! $guild_id || ! $bot_token ) {
			error_log( '[NK Discord] Cannot assign role — missing guild_id or bot_token.' );
			return false;
		}

		$url = self::DISCORD_API . "/guilds/{$guild_id}/members/{$discord_user_id}/roles/{$role_id}";

		$response = wp_remote_request( $url, array(
			'method'  => 'PUT',
			'headers' => array(
				'Authorization' => 'Bot ' . $bot_token,
				'Content-Type'  => 'application/json',
			),
			'body'    => '',
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			error_log( '[NK Discord] Role assign error: ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code === 204 ) {
			return true;
		}

		error_log( '[NK Discord] Role assign HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
		return false;
	}

	/**
	 * Remove a Discord role from a user via the Bot API.
	 */
	public function remove_role( $discord_user_id, $role_id ) {
		$guild_id  = NK_Discord_Settings::get( 'guild_id' );
		$bot_token = NK_Discord_Settings::get( 'bot_token' );

		if ( ! $guild_id || ! $bot_token ) {
			error_log( '[NK Discord] Cannot remove role — missing guild_id or bot_token.' );
			return false;
		}

		$url = self::DISCORD_API . "/guilds/{$guild_id}/members/{$discord_user_id}/roles/{$role_id}";

		$response = wp_remote_request( $url, array(
			'method'  => 'DELETE',
			'headers' => array(
				'Authorization' => 'Bot ' . $bot_token,
				'Content-Type'  => 'application/json',
			),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			error_log( '[NK Discord] Role remove error: ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code === 204 ) {
			return true;
		}

		error_log( '[NK Discord] Role remove HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
		return false;
	}
}
