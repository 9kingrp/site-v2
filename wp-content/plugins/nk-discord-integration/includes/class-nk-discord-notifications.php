<?php
/**
 * Discord webhook notifications for WooCommerce events.
 *
 * Sends embedded messages to Discord channels when:
 * - A purchase is completed
 * - A subscription is activated, renewed, or cancelled
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NK_Discord_Notifications {

	private static $instance = null;

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

		// Order completed
		add_action( 'woocommerce_order_status_completed', array( $this, 'notify_order_completed' ), 20, 1 );

		// Subscription events (WooCommerce Subscriptions)
		add_action( 'woocommerce_subscription_status_active', array( $this, 'notify_subscription_activated' ), 20, 1 );
		add_action( 'woocommerce_subscription_renewal_payment_complete', array( $this, 'notify_subscription_renewed' ), 20, 2 );
		add_action( 'woocommerce_subscription_status_cancelled', array( $this, 'notify_subscription_cancelled' ), 20, 1 );
		add_action( 'woocommerce_subscription_status_expired', array( $this, 'notify_subscription_expired' ), 20, 1 );
		add_action( 'woocommerce_subscription_status_on-hold', array( $this, 'notify_subscription_on_hold' ), 20, 1 );
	}

	/**
	 * Notify on order completion.
	 */
	public function notify_order_completed( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$webhook_url = NK_Discord_Settings::get( 'webhook_purchases' );
		if ( ! $webhook_url ) {
			return;
		}

		$user_id      = $order->get_user_id();
		$discord_user = $this->get_discord_display( $user_id );
		$items        = $this->format_order_items( $order );
		$total        = html_entity_decode( wp_strip_all_tags( $order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' );

		$embed = array(
			'title'       => 'New Purchase Completed',
			'description' => "Order **#{$order_id}** has been completed.",
			'color'       => 0x57F287, // Green
			'fields'      => array(
				array(
					'name'   => 'Total',
					'value'  => $total,
					'inline' => true,
				),
				array(
					'name'   => 'Items',
					'value'  => $items,
					'inline' => false,
				),
			),
			'timestamp'   => gmdate( 'c' ),
			'footer'      => array(
				'text' => 'Nine Kings Store',
			),
		);

		// Discord author block — avatar + clickable profile link
		$author = $this->get_discord_author( $user_id );
		if ( $author ) {
			$embed['author'] = $author;
		}

		// Add the first product's image as embed thumbnail
		$thumb_url = $this->get_first_item_image( $order );
		if ( $thumb_url ) {
			$embed['thumbnail'] = array( 'url' => $thumb_url );
		}

		$this->send_webhook( $webhook_url, $embed );
	}

	/**
	 * Notify on subscription activation.
	 */
	public function notify_subscription_activated( $subscription ) {
		$webhook_url = NK_Discord_Settings::get( 'webhook_subscriptions' );
		if ( ! $webhook_url ) {
			return;
		}

		$user_id      = $subscription->get_user_id();
		$discord_user = $this->get_discord_display( $user_id );
		$items        = $this->format_subscription_items( $subscription );

		$embed = array(
			'title'       => 'Subscription Activated',
			'description' => "Subscription **#{$subscription->get_id()}** is now active.",
			'color'       => 0x5865F2, // Discord blurple
			'fields'      => array(
				array(
					'name'   => 'Recurring Total',
					'value'  => html_entity_decode( wp_strip_all_tags( $subscription->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' ),
					'inline' => true,
				),
				array(
					'name'   => 'Items',
					'value'  => $items,
					'inline' => false,
				),
			),
			'timestamp'   => gmdate( 'c' ),
			'footer'      => array(
				'text' => 'Nine Kings Store',
			),
		);

		$author = $this->get_discord_author( $user_id );
		if ( $author ) {
			$embed['author'] = $author;
		}

		$this->send_webhook( $webhook_url, $embed );
	}

	/**
	 * Notify on subscription renewal.
	 */
	public function notify_subscription_renewed( $subscription, $renewal_order ) {
		$webhook_url = NK_Discord_Settings::get( 'webhook_subscriptions' );
		if ( ! $webhook_url ) {
			return;
		}

		$user_id      = $subscription->get_user_id();
		$discord_user = $this->get_discord_display( $user_id );

		$embed = array(
			'title'       => 'Subscription Renewed',
			'description' => "Subscription **#{$subscription->get_id()}** has been renewed.",
			'color'       => 0x57F287, // Green
			'fields'      => array(
				array(
					'name'   => 'Renewal Order',
					'value'  => '#' . $renewal_order->get_id(),
					'inline' => true,
				),
				array(
					'name'   => 'Amount',
					'value'  => html_entity_decode( wp_strip_all_tags( $renewal_order->get_formatted_order_total() ), ENT_QUOTES, 'UTF-8' ),
					'inline' => true,
				),
			),
			'timestamp'   => gmdate( 'c' ),
			'footer'      => array(
				'text' => 'Nine Kings Store',
			),
		);

		$author = $this->get_discord_author( $user_id );
		if ( $author ) {
			$embed['author'] = $author;
		}

		$this->send_webhook( $webhook_url, $embed );
	}

	/**
	 * Notify on subscription cancellation.
	 */
	public function notify_subscription_cancelled( $subscription ) {
		$webhook_url = NK_Discord_Settings::get( 'webhook_subscriptions' );
		if ( ! $webhook_url ) {
			return;
		}

		$user_id      = $subscription->get_user_id();
		$discord_user = $this->get_discord_display( $user_id );
		$items        = $this->format_subscription_items( $subscription );

		$embed = array(
			'title'       => 'Subscription Cancelled',
			'description' => "Subscription **#{$subscription->get_id()}** has been cancelled.",
			'color'       => 0xED4245, // Red
			'fields'      => array(
				array(
					'name'   => 'Items',
					'value'  => $items,
					'inline' => false,
				),
			),
			'timestamp'   => gmdate( 'c' ),
			'footer'      => array(
				'text' => 'Nine Kings Store',
			),
		);

		$author = $this->get_discord_author( $user_id );
		if ( $author ) {
			$embed['author'] = $author;
		}

		$this->send_webhook( $webhook_url, $embed );
	}

	/**
	 * Notify on subscription expiration.
	 */
	public function notify_subscription_expired( $subscription ) {
		$webhook_url = NK_Discord_Settings::get( 'webhook_subscriptions' );
		if ( ! $webhook_url ) {
			return;
		}

		$user_id      = $subscription->get_user_id();

		$embed = array(
			'title'       => 'Subscription Expired',
			'description' => "Subscription **#{$subscription->get_id()}** has expired.",
			'color'       => 0xFEE75C, // Yellow
			'fields'      => array(),
			'timestamp'   => gmdate( 'c' ),
			'footer'      => array(
				'text' => 'Nine Kings Store',
			),
		);

		$author = $this->get_discord_author( $user_id );
		if ( $author ) {
			$embed['author'] = $author;
		}

		$this->send_webhook( $webhook_url, $embed );
	}

	/**
	 * Notify on subscription placed on hold.
	 */
	public function notify_subscription_on_hold( $subscription ) {
		$webhook_url = NK_Discord_Settings::get( 'webhook_subscriptions' );
		if ( ! $webhook_url ) {
			return;
		}

		$user_id      = $subscription->get_user_id();

		$embed = array(
			'title'       => 'Subscription On Hold',
			'description' => "Subscription **#{$subscription->get_id()}** has been placed on hold.",
			'color'       => 0xFEE75C, // Yellow
			'fields'      => array(),
			'timestamp'   => gmdate( 'c' ),
			'footer'      => array(
				'text' => 'Nine Kings Store',
			),
		);

		$author = $this->get_discord_author( $user_id );
		if ( $author ) {
			$embed['author'] = $author;
		}

		$this->send_webhook( $webhook_url, $embed );
	}

	/**
	 * Send a Discord webhook with an embed.
	 */
	private function send_webhook( $webhook_url, $embed ) {
		if ( ! $webhook_url ) {
			return false;
		}

		$payload = array(
			'embeds' => array( $embed ),
		);

		$response = wp_remote_post( $webhook_url, array(
			'headers' => array(
				'Content-Type' => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			error_log( '[NK Discord] Webhook error: ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			error_log( '[NK Discord] Webhook HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
			return false;
		}

		return true;
	}

	/**
	 * Get the Discord display string for a user (username + ID).
	 */
	private function get_discord_display( $user_id ) {
		$discord_user = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_USER, true );
		$discord_id   = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_ID, true );

		if ( $discord_user && $discord_id ) {
			return "{$discord_user} (`{$discord_id}`)";
		}

		$user = get_user_by( 'ID', $user_id );
		return $user ? $user->display_name : 'Unknown';
	}

	/**
	 * Build a Discord embed author block with avatar and profile link.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array|null  Author array for the embed, or null if no Discord data.
	 */
	private function get_discord_author( $user_id ) {
		$discord_user = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_USER, true );
		$discord_id   = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_ID, true );

		if ( ! $discord_user || ! $discord_id ) {
			return null;
		}

		$author = array(
			'name' => $discord_user,
			'url'  => 'https://discord.com/users/' . $discord_id,
		);

		$avatar_url = NK_Discord_User::get_avatar_url( $user_id );
		if ( $avatar_url ) {
			$author['icon_url'] = $avatar_url;
		}

		return $author;
	}

	/**
	 * Get the featured image URL of the first line item product.
	 *
	 * @param WC_Order|WC_Subscription $order
	 * @return string|false  Image URL or false.
	 */
	private function get_first_item_image( $order ) {
		foreach ( $order->get_items() as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$image_id = $product->get_image_id();
			if ( $image_id ) {
				$url = wp_get_attachment_image_url( $image_id, 'medium' );
				if ( $url ) {
					return $url;
				}
			}
		}
		return false;
	}

	/**
	 * Format order items into a string for the embed.
	 */
	private function format_order_items( $order ) {
		$lines = array();
		foreach ( $order->get_items() as $item ) {
			$qty       = $item->get_quantity();
			$name      = $item->get_name();
			$spawncode = get_post_meta( $item->get_product_id(), NK_Discord_1of1::META_SPAWNCODE, true );
			if ( $spawncode ) {
				$lines[] = "• {$name} x{$qty} — `{$spawncode}`";
			} else {
				$lines[] = "• {$name} x{$qty}";
			}
		}
		return implode( "\n", $lines ) ?: 'No items';
	}

	/**
	 * Format subscription items into a string for the embed.
	 */
	private function format_subscription_items( $subscription ) {
		$lines = array();
		foreach ( $subscription->get_items() as $item ) {
			$name = $item->get_name();
			$lines[] = "• {$name}";
		}
		return implode( "\n", $lines ) ?: 'No items';
	}
}
