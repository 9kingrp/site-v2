<?php
/**
 * 1of1 Vehicle — Discord ticket approval gate.
 *
 * Adds a product-level "1of1 Vehicle" flag. When enabled, customers must
 * confirm they have an approved Discord ticket before adding to cart.
 * The ticket channel name is validated against the Discord guild via Bot API.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NK_Discord_1of1 {

	private static $instance = null;

	const META_IS_1OF1            = '_nk_1of1_vehicle';
	const META_REQUIRES_APPROVAL  = '_nk_discord_approval_required';
	const META_TICKET_CHANNEL     = '_nk_1of1_ticket';
	const META_SPAWNCODE          = '_nk_vehicle_spawncode';
	const DISCORD_API             = 'https://discord.com/api/v10';

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

		// Product editor: add 1of1 checkbox
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'add_product_field' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_field' ) );

		// REST API: ticket validation endpoint
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );

		// Frontend: enqueue modal assets on single product pages
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Server-side: validate ticket before add-to-cart
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 3 );

		// Store ticket ID on cart item
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 3 );

		// Persist ticket ID through cart session
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );

		// Save ticket ID to order item meta
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_item_meta' ), 10, 4 );

		// Also handle Store API (blocks checkout) order line items
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'stamp_ticket_on_store_api_order' ), 20, 2 );
	}

	/* ───────────────────────────────────────────────────────────────────
	 * Product editor
	 * ─────────────────────────────────────────────────────────────────── */

	public function add_product_field() {
		woocommerce_wp_checkbox( array(
			'id'          => self::META_IS_1OF1,
			'label'       => __( '1of1 Vehicle', 'nk-discord' ),
			'description' => __( 'Mark as a 1of1 vehicle (also enables Discord ticket approval).', 'nk-discord' ),
		) );

		woocommerce_wp_checkbox( array(
			'id'          => self::META_REQUIRES_APPROVAL,
			'label'       => __( 'Requires Discord Approval', 'nk-discord' ),
			'description' => __( 'Require Discord ticket approval before this product can be added to cart.', 'nk-discord' ),
		) );

		woocommerce_wp_text_input( array(
			'id'          => self::META_SPAWNCODE,
			'label'       => __( 'Vehicle Spawncode', 'nk-discord' ),
			'description' => __( 'In-game spawncode for this vehicle. Displayed in Discord purchase notifications.', 'nk-discord' ),
			'desc_tip'    => true,
			'type'        => 'text',
		) );
	}

	public function save_product_field( $post_id ) {
		$value = isset( $_POST[ self::META_IS_1OF1 ] ) ? 'yes' : 'no';
		update_post_meta( $post_id, self::META_IS_1OF1, $value );

		$approval = isset( $_POST[ self::META_REQUIRES_APPROVAL ] ) ? 'yes' : 'no';
		update_post_meta( $post_id, self::META_REQUIRES_APPROVAL, $approval );

		if ( isset( $_POST[ self::META_SPAWNCODE ] ) ) {
			update_post_meta( $post_id, self::META_SPAWNCODE, sanitize_text_field( $_POST[ self::META_SPAWNCODE ] ) );
		}
	}

	/* ───────────────────────────────────────────────────────────────────
	 * Helper: is product a 1of1?
	 * ─────────────────────────────────────────────────────────────────── */

	public static function is_1of1( $product_id ) {
		return get_post_meta( $product_id, self::META_IS_1OF1, true ) === 'yes';
	}

	/* ───────────────────────────────────────────────────────────────────
	 * Helper: does product require Discord approval (1of1 OR generic)?
	 * ─────────────────────────────────────────────────────────────────── */

	public static function requires_discord_approval( $product_id ) {
		if ( self::is_1of1( $product_id ) ) {
			return true;
		}
		return get_post_meta( $product_id, self::META_REQUIRES_APPROVAL, true ) === 'yes';
	}

	/* ───────────────────────────────────────────────────────────────────
	 * REST endpoint: POST nk-discord/v1/validate-ticket
	 * ─────────────────────────────────────────────────────────────────── */

	public function register_rest_routes() {
		register_rest_route( 'nk-discord/v1', '/validate-ticket', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'rest_validate_ticket' ),
			'permission_callback' => function () {
				return is_user_logged_in();
			},
			'args'                => array(
				'ticket_name' => array(
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
				'channel_id' => array(
					'required'          => false,
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );
	}

	/**
	 * Validate that a ticket channel exists in the configured Discord guild.
	 *
	 * Ticket Tool typically names channels like "ticket-username" or
	 * "ticket-0001". We search all channels in the ticket category
	 * (or the whole guild) for a name match.
	 */
	public function rest_validate_ticket( $request ) {
		$ticket_name = strtolower( trim( $request->get_param( 'ticket_name' ) ) );
		$channel_id  = trim( $request->get_param( 'channel_id' ) );

		if ( empty( $ticket_name ) && empty( $channel_id ) ) {
			return new WP_REST_Response( array(
				'valid'   => false,
				'message' => __( 'Please enter a ticket channel name or channel ID.', 'nk-discord' ),
			), 400 );
		}

		$bot_token   = NK_Discord_Settings::get( 'bot_token' );
		$guild_id    = NK_Discord_Settings::get( 'guild_id' );
		$category_id = NK_Discord_Settings::get( 'ticket_category_id' );

		if ( ! $bot_token || ! $guild_id ) {
			return new WP_REST_Response( array(
				'valid'   => false,
				'message' => __( 'Server configuration error. Please contact support.', 'nk-discord' ),
			), 500 );
		}

		// Fetch all guild channels
		$url = self::DISCORD_API . "/guilds/{$guild_id}/channels";

		$response = wp_remote_get( $url, array(
			'headers' => array(
				'Authorization' => 'Bot ' . $bot_token,
			),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			error_log( '[NK Discord 1of1] Channel fetch error: ' . $response->get_error_message() );
			return new WP_REST_Response( array(
				'valid'   => false,
				'message' => __( 'Could not connect to Discord. Please try again.', 'nk-discord' ),
			), 502 );
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			error_log( '[NK Discord 1of1] Channel fetch HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
			return new WP_REST_Response( array(
				'valid'   => false,
				'message' => __( 'Discord API error. Please try again later.', 'nk-discord' ),
			), 502 );
		}

		$channels = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $channels ) ) {
			return new WP_REST_Response( array(
				'valid'   => false,
				'message' => __( 'Unexpected Discord response.', 'nk-discord' ),
			), 502 );
		}

		// Search for a matching channel by ID or name
		$found = false;
		foreach ( $channels as $channel ) {
			// Type 0 = text channel
			if ( (int) $channel['type'] !== 0 ) {
				continue;
			}

			// If category is configured, only search within it
			if ( $category_id && isset( $channel['parent_id'] ) && $channel['parent_id'] !== $category_id ) {
				continue;
			}

			// Match by channel ID
			if ( ! empty( $channel_id ) && isset( $channel['id'] ) && $channel['id'] === $channel_id ) {
				$found = true;
				break;
			}

			// Match by channel name
			if ( ! empty( $ticket_name ) && strtolower( $channel['name'] ) === $ticket_name ) {
				$found = true;
				break;
			}
		}

		if ( $found ) {
			return new WP_REST_Response( array(
				'valid'   => true,
				'message' => __( 'Ticket verified successfully!', 'nk-discord' ),
			), 200 );
		}

		return new WP_REST_Response( array(
			'valid'   => false,
			'message' => __( 'No matching ticket found. Please check the ticket name or channel ID and try again.', 'nk-discord' ),
		), 200 );
	}

	/* ───────────────────────────────────────────────────────────────────
	 * Frontend assets
	 * ─────────────────────────────────────────────────────────────────── */

	public function enqueue_assets() {
		if ( ! is_product() ) {
			return;
		}

		global $post;
		if ( ! $post || ! self::requires_discord_approval( $post->ID ) ) {
			return;
		}

		$discord_ticket_url = NK_Discord_Settings::get( 'discord_ticket_url' );
		$is_1of1            = self::is_1of1( $post->ID );

		wp_enqueue_style(
			'nk-1of1-modal',
			NK_DISCORD_URL . 'assets/css/1of1-modal.css',
			array(),
			(string) filemtime( NK_DISCORD_DIR . 'assets/css/1of1-modal.css' )
		);

		wp_enqueue_script(
			'nk-1of1-modal',
			NK_DISCORD_URL . 'assets/js/1of1-modal.js',
			array( 'jquery' ),
			(string) filemtime( NK_DISCORD_DIR . 'assets/js/1of1-modal.js' ),
			true
		);

		// Context-aware i18n: 1of1 vehicles get specific branding, others get generic
		if ( $is_1of1 ) {
			$title       = __( '1of1 Vehicle — Approval Required', 'nk-discord' );
			$description = __( 'This is a 1of1 vehicle and requires prior approval via a Discord ticket before purchasing.', 'nk-discord' );
			$badge       = '1 of 1';
		} else {
			$title       = __( 'Discord Approval Required', 'nk-discord' );
			$description = __( 'This product requires prior approval via a Discord ticket before purchasing.', 'nk-discord' );
			$badge       = '';
		}

		wp_localize_script( 'nk-1of1-modal', 'nk1of1', array(
			'restUrl'          => rest_url( 'nk-discord/v1/validate-ticket' ),
			'nonce'            => wp_create_nonce( 'wp_rest' ),
			'discordTicketUrl' => $discord_ticket_url ?: 'https://discord.com',
			'productId'        => $post->ID,
			'is1of1'           => $is_1of1,
			'i18n'             => array(
				'title'           => $title,
				'description'     => $description,
				'badge'           => $badge,
				'question'        => __( 'Have you already been approved in a Discord ticket?', 'nk-discord' ),
				'yesLabel'        => __( 'Yes, I have approval', 'nk-discord' ),
				'noLabel'         => __( 'No, I need to open a ticket', 'nk-discord' ),
				'ticketLabel'     => __( 'Enter your ticket channel name or channel ID', 'nk-discord' ),
				'ticketHint'      => __( 'You can paste the channel name (e.g. ticket-username) or the numeric channel ID.', 'nk-discord' ),
				'ticketPlaceholder' => __( 'e.g. ticket-username or 1234567890123456789', 'nk-discord' ),
				'verifyBtn'       => __( 'Verify & Add to Cart', 'nk-discord' ),
				'verifying'       => __( 'Verifying…', 'nk-discord' ),
				'cancelBtn'       => __( 'Cancel', 'nk-discord' ),
				'redirectMsg'     => __( 'Redirecting you to Discord to open a ticket…', 'nk-discord' ),
				'error'           => __( 'Something went wrong. Please try again.', 'nk-discord' ),
			),
		) );
	}

	/* ───────────────────────────────────────────────────────────────────
	 * Server-side add-to-cart validation
	 * ─────────────────────────────────────────────────────────────────── */

	public function validate_add_to_cart( $passed, $product_id, $quantity ) {
		if ( ! self::requires_discord_approval( $product_id ) ) {
			return $passed;
		}

		$ticket = isset( $_REQUEST['nk_1of1_ticket'] ) ? sanitize_text_field( $_REQUEST['nk_1of1_ticket'] ) : '';

		if ( empty( $ticket ) ) {
			wc_add_notice( __( 'This product requires an approved Discord ticket. Please use the approval modal.', 'nk-discord' ), 'error' );
			return false;
		}

		return $passed;
	}

	/* ───────────────────────────────────────────────────────────────────
	 * Cart item data — persist ticket through session
	 * ─────────────────────────────────────────────────────────────────── */

	public function add_cart_item_data( $cart_item_data, $product_id, $variation_id ) {
		if ( ! self::requires_discord_approval( $product_id ) ) {
			return $cart_item_data;
		}

		$ticket = isset( $_REQUEST['nk_1of1_ticket'] ) ? sanitize_text_field( $_REQUEST['nk_1of1_ticket'] ) : '';
		if ( $ticket ) {
			$cart_item_data['nk_1of1_ticket'] = $ticket;
		}

		return $cart_item_data;
	}

	public function display_cart_item_data( $item_data, $cart_item ) {
		if ( ! empty( $cart_item['nk_1of1_ticket'] ) ) {
			$item_data[] = array(
				'key'   => __( 'Approved Ticket', 'nk-discord' ),
				'value' => sanitize_text_field( $cart_item['nk_1of1_ticket'] ),
			);
		}
		return $item_data;
	}

	/* ───────────────────────────────────────────────────────────────────
	 * Save ticket to order item meta (classic checkout)
	 * ─────────────────────────────────────────────────────────────────── */

	public function save_order_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( ! empty( $values['nk_1of1_ticket'] ) ) {
			$item->add_meta_data( '_nk_1of1_ticket', sanitize_text_field( $values['nk_1of1_ticket'] ), true );
		}
	}

	/**
	 * For blocks checkout — copy ticket from cart session to order items.
	 */
	public function stamp_ticket_on_store_api_order( $order, $request ) {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			$cart_items = $cart->get_cart();
			foreach ( $cart_items as $cart_item ) {
				if ( $cart_item['product_id'] == $item->get_product_id() && ! empty( $cart_item['nk_1of1_ticket'] ) ) {
					$item->add_meta_data( '_nk_1of1_ticket', sanitize_text_field( $cart_item['nk_1of1_ticket'] ), true );
					$item->save();
					break;
				}
			}
		}
	}
}
