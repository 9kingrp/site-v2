<?php
/**
 * Package products — lets a product offer a choice from a specific category.
 *
 * Admin: adds a "Package Options" tab in the product editor where you can
 *        select a source category and a Discord category ID for ticket channels.
 * Frontend: renders a visual picker (name + image) on the single product page.
 * Cart/Order: persists the chosen product as line-item meta.
 * Discord: creates a ticket channel in the configured Discord category when
 *          an order containing a package is completed, and posts order details.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NK_Discord_Packages {

	private static $instance = null;

	const META_PACKAGE_ENABLED          = '_nk_package_enabled';
	const META_PACKAGE_CATEGORY         = '_nk_package_category';
	const META_PACKAGE_DISCORD_CATEGORY = '_nk_package_discord_category';
	const META_PACKAGE_LABEL            = '_nk_package_label';

	const DISCORD_API = 'https://discord.com/api/v10';

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

		// ── Admin: product editor ────────────────────────────────────────────
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_product_data_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_product_data_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_product_meta' ) );

		// ── Frontend: single product page ────────────────────────────────────
		// Visual picker renders BEFORE the <form> so it sits outside Elementor's flex wrapper
		add_action( 'woocommerce_before_add_to_cart_form', array( $this, 'render_package_options' ) );
		add_action( 'woocommerce_single_product_summary', array( $this, 'render_package_options_fallback' ), 25 );
		// Hidden input goes INSIDE the <form> so it submits with add-to-cart
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'inject_package_hidden_input' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

		// ── Cart: validate & store selection ─────────────────────────────────
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );

		// ── Order: persist to order line-item meta ───────────────────────────
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_item_meta' ), 10, 4 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'save_order_item_meta_blocks' ), 10, 2 );

		// ── Discord ticket channel on order completion ──────────────────────
		add_action( 'woocommerce_order_status_completed', array( $this, 'create_ticket_channel' ), 25, 1 );

		// ── Validation ───────────────────────────────────────────────────────
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_package_selection' ), 10, 3 );
	}

	/* =====================================================================
	   ADMIN — Product Data Tab & Panel
	   ===================================================================== */

	/**
	 * Add a "Package Options" tab to the product data meta box.
	 */
	public function add_product_data_tab( $tabs ) {
		$tabs['nk_package'] = array(
			'label'    => __( 'Package Options', 'nk-discord' ),
			'target'   => 'nk_package_options',
			'class'    => array( 'show_if_simple', 'show_if_virtual' ),
			'priority' => 75,
		);
		return $tabs;
	}

	/**
	 * Render the Package Options panel.
	 */
	public function render_product_data_panel() {
		global $post;
		$product_id = $post->ID;

		$enabled  = get_post_meta( $product_id, self::META_PACKAGE_ENABLED, true );
		$category = get_post_meta( $product_id, self::META_PACKAGE_CATEGORY, true );
		$discord_cat = get_post_meta( $product_id, self::META_PACKAGE_DISCORD_CATEGORY, true );
		$label       = get_post_meta( $product_id, self::META_PACKAGE_LABEL, true );

		$categories = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => false,
		) );
		?>
		<div id="nk_package_options" class="panel woocommerce_options_panel">
			<div class="options_group">
				<?php
				woocommerce_wp_checkbox( array(
					'id'          => self::META_PACKAGE_ENABLED,
					'label'       => __( 'Enable Package', 'nk-discord' ),
					'description' => __( 'Allow the customer to choose a product from a category when purchasing.', 'nk-discord' ),
					'value'       => $enabled,
				) );

				woocommerce_wp_text_input( array(
					'id'          => self::META_PACKAGE_LABEL,
					'label'       => __( 'Selection Label', 'nk-discord' ),
					'description' => __( 'Label shown above the product picker, e.g. "Choose your vehicle".', 'nk-discord' ),
					'desc_tip'    => true,
					'placeholder' => __( 'Choose an option', 'nk-discord' ),
				) );
				?>

				<p class="form-field">
					<label for="<?php echo esc_attr( self::META_PACKAGE_CATEGORY ); ?>">
						<?php esc_html_e( 'Source Category', 'nk-discord' ); ?>
					</label>
					<select id="<?php echo esc_attr( self::META_PACKAGE_CATEGORY ); ?>"
							name="<?php echo esc_attr( self::META_PACKAGE_CATEGORY ); ?>"
							class="wc-enhanced-select"
							style="width:50%;">
						<option value=""><?php esc_html_e( '— Select a category —', 'nk-discord' ); ?></option>
						<?php foreach ( $categories as $cat ) : ?>
							<option value="<?php echo esc_attr( $cat->term_id ); ?>"
								<?php selected( $category, $cat->term_id ); ?>>
								<?php echo esc_html( $cat->name ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</p>

				<?php
				woocommerce_wp_text_input( array(
					'id'          => self::META_PACKAGE_DISCORD_CATEGORY,
					'label'       => __( 'Discord Category ID', 'nk-discord' ),
					'description' => __( 'The Discord category (channel) ID where ticket channels will be created. Right-click the category in Discord → Copy Channel ID.', 'nk-discord' ),
					'desc_tip'    => true,
					'type'        => 'text',
				) );
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Save package meta when the product is saved.
	 */
	public function save_product_meta( $post_id ) {
		$enabled = isset( $_POST[ self::META_PACKAGE_ENABLED ] ) ? 'yes' : 'no';
		update_post_meta( $post_id, self::META_PACKAGE_ENABLED, $enabled );

		if ( isset( $_POST[ self::META_PACKAGE_CATEGORY ] ) ) {
			update_post_meta( $post_id, self::META_PACKAGE_CATEGORY, absint( $_POST[ self::META_PACKAGE_CATEGORY ] ) );
		}

		if ( isset( $_POST[ self::META_PACKAGE_DISCORD_CATEGORY ] ) ) {
			update_post_meta( $post_id, self::META_PACKAGE_DISCORD_CATEGORY, sanitize_text_field( $_POST[ self::META_PACKAGE_DISCORD_CATEGORY ] ) );
		}

		if ( isset( $_POST[ self::META_PACKAGE_LABEL ] ) ) {
			update_post_meta( $post_id, self::META_PACKAGE_LABEL, sanitize_text_field( $_POST[ self::META_PACKAGE_LABEL ] ) );
		}
	}

	/* =====================================================================
	   FRONTEND — Product Option Picker
	   ===================================================================== */

	/**
	 * Check if a product is a package product.
	 */
	public static function is_package_product( $product_id ) {
		return 'yes' === get_post_meta( $product_id, self::META_PACKAGE_ENABLED, true );
	}

	/**
	 * Get the choosable products for a package product.
	 */
	public static function get_package_products( $product_id ) {
		$category_id = get_post_meta( $product_id, self::META_PACKAGE_CATEGORY, true );
		if ( ! $category_id ) {
			return array();
		}

		$args = array(
			'post_type'      => 'product',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'tax_query'      => array(
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => absint( $category_id ),
				),
			),
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		$query    = new WP_Query( $args );
		$products = array();

		foreach ( $query->posts as $post ) {
			$product = wc_get_product( $post->ID );
			if ( ! $product ) {
				continue;
			}

			$image_id  = $product->get_image_id();
			$image_url = $image_id
				? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' )
				: wc_placeholder_img_src( 'woocommerce_thumbnail' );

			$products[] = array(
				'id'    => $product->get_id(),
				'name'  => $product->get_name(),
				'image' => $image_url,
			);
		}

		wp_reset_postdata();

		return $products;
	}

	/**
	 * Render the visual package option picker on the single product page.
	 * Hooked to woocommerce_before_add_to_cart_form so it sits OUTSIDE
	 * the <form> and Elementor's flex wrapper.
	 */
	public function render_package_options() {
		if ( $this->picker_rendered ) {
			return;
		}

		global $product;

		if ( ! $product || ! self::is_package_product( $product->get_id() ) ) {
			return;
		}

		$options = self::get_package_products( $product->get_id() );
		if ( empty( $options ) ) {
			return;
		}

		$this->picker_rendered = true;

		$label = get_post_meta( $product->get_id(), self::META_PACKAGE_LABEL, true );
		if ( ! $label ) {
			$label = __( 'Choose an option', 'nk-discord' );
		}
		?>
		<div class="nk-package-picker" id="nk-package-picker">
			<label class="nk-package-picker__label"><?php echo esc_html( $label ); ?></label>
			<div class="nk-package-picker__grid">
				<?php foreach ( $options as $opt ) : ?>
					<div class="nk-package-option"
						 data-product-id="<?php echo esc_attr( $opt['id'] ); ?>"
						 tabindex="0"
						 role="radio"
						 aria-checked="false"
						 aria-label="<?php echo esc_attr( $opt['name'] ); ?>">
						<div class="nk-package-option__image">
							<img src="<?php echo esc_url( $opt['image'] ); ?>"
								 alt="<?php echo esc_attr( $opt['name'] ); ?>"
								 loading="lazy" />
						</div>
						<span class="nk-package-option__name"><?php echo esc_html( $opt['name'] ); ?></span>
						<span class="nk-package-option__check">
							<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
						</span>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Inject the hidden input INSIDE the <form> so it submits with add-to-cart.
	 * The visual picker (outside the form) updates this field via JS.
	 */
	public function inject_package_hidden_input() {
		global $product;

		if ( ! $product || ! self::is_package_product( $product->get_id() ) ) {
			return;
		}
		?>
		<input type="hidden" name="nk_package_choice" id="nk_package_choice" value="" />
		<?php
	}

	/**
	 * Track whether the picker has already been rendered (prevent duplicates).
	 */
	private $picker_rendered = false;

	/**
	 * Fallback render via woocommerce_single_product_summary.
	 * Only fires if the primary hook (woocommerce_before_add_to_cart_button)
	 * didn't render the picker.
	 */
	public function render_package_options_fallback() {
		if ( $this->picker_rendered ) {
			return;
		}
		$this->render_package_options();
	}

	/**
	 * Enqueue frontend CSS and JS.
	 *
	 * Assets are tiny and no-op when the picker HTML is absent,
	 * so we enqueue broadly to avoid Elementor / theme template
	 * timing issues with is_product().
	 */
	public function enqueue_frontend_assets() {
		wp_enqueue_style(
			'nk-package-picker',
			NK_DISCORD_URL . 'assets/css/package-picker.css',
			array(),
			(string) filemtime( NK_DISCORD_DIR . 'assets/css/package-picker.css' )
		);

		wp_enqueue_script(
			'nk-package-picker',
			NK_DISCORD_URL . 'assets/js/package-picker.js',
			array( 'jquery' ),
			(string) filemtime( NK_DISCORD_DIR . 'assets/js/package-picker.js' ),
			true
		);
	}

	/* =====================================================================
	   CART — Validate, Store & Display Selection
	   ===================================================================== */

	/**
	 * Validate that a package product has a selection before adding to cart.
	 */
	public function validate_package_selection( $passed, $product_id, $quantity ) {
		if ( ! self::is_package_product( $product_id ) ) {
			return $passed;
		}

		if ( empty( $_REQUEST['nk_package_choice'] ) ) {
			wc_add_notice( __( 'Please select an option before adding to cart.', 'nk-discord' ), 'error' );
			return false;
		}

		$choice_id = absint( $_REQUEST['nk_package_choice'] );
		$product   = wc_get_product( $choice_id );
		if ( ! $product ) {
			wc_add_notice( __( 'Invalid option selected.', 'nk-discord' ), 'error' );
			return false;
		}

		return $passed;
	}

	/**
	 * Attach the chosen product ID to the cart item data.
	 */
	public function add_cart_item_data( $cart_item_data, $product_id ) {
		if ( ! self::is_package_product( $product_id ) ) {
			return $cart_item_data;
		}

		if ( ! empty( $_REQUEST['nk_package_choice'] ) ) {
			$cart_item_data['nk_package_choice'] = absint( $_REQUEST['nk_package_choice'] );
		}

		return $cart_item_data;
	}

	/**
	 * Display the chosen product in the cart.
	 */
	public function display_cart_item_data( $item_data, $cart_item ) {
		if ( ! empty( $cart_item['nk_package_choice'] ) ) {
			$chosen = wc_get_product( $cart_item['nk_package_choice'] );
			if ( $chosen ) {
				$label = get_post_meta( $cart_item['product_id'], self::META_PACKAGE_LABEL, true );
				if ( ! $label ) {
					$label = __( 'Selected Option', 'nk-discord' );
				}

				$item_data[] = array(
					'key'   => $label,
					'value' => $chosen->get_name(),
				);
			}
		}

		return $item_data;
	}

	/* =====================================================================
	   ORDER — Persist Choice to Order Line-Item Meta
	   ===================================================================== */

	/**
	 * Save the chosen product to the order line-item (classic checkout).
	 */
	public function save_order_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( ! empty( $values['nk_package_choice'] ) ) {
			$chosen = wc_get_product( $values['nk_package_choice'] );
			if ( $chosen ) {
				$item->add_meta_data( '_nk_package_choice_id', $chosen->get_id(), true );
				$item->add_meta_data( '_nk_package_choice_name', $chosen->get_name(), true );
			}
		}
	}

	/**
	 * Save the chosen product to order line-items (blocks checkout / Store API).
	 */
	public function save_order_item_meta_blocks( $order, $request ) {
		$cart = WC()->cart;
		if ( ! $cart ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			$cart_items = $cart->get_cart();
			foreach ( $cart_items as $cart_item ) {
				if (
					$cart_item['product_id'] === $item->get_product_id() &&
					! empty( $cart_item['nk_package_choice'] )
				) {
					$chosen = wc_get_product( $cart_item['nk_package_choice'] );
					if ( $chosen && ! $item->get_meta( '_nk_package_choice_id' ) ) {
						$item->add_meta_data( '_nk_package_choice_id', $chosen->get_id(), true );
						$item->add_meta_data( '_nk_package_choice_name', $chosen->get_name(), true );
						$item->save();
					}
					break;
				}
			}
		}
	}

	/* =====================================================================
	   DISCORD — Ticket Channel on Order Completion
	   ===================================================================== */

	/**
	 * Create a Discord ticket channel for each package item in a completed order.
	 *
	 * Flow:
	 * 1. Create a text channel inside the configured Discord category.
	 * 2. Post an embed with the full order details as the first message.
	 */
	public function create_ticket_channel( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$guild_id  = NK_Discord_Settings::get( 'guild_id' );
		$bot_token = NK_Discord_Settings::get( 'bot_token' );

		if ( ! $guild_id || ! $bot_token ) {
			error_log( '[NK Discord] Cannot create ticket channel — missing guild_id or bot_token.' );
			return;
		}

		$user_id      = $order->get_user_id();
		$discord_user = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_USER, true );
		$discord_id   = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_ID, true );

		$customer_display = $discord_user
			? "{$discord_user} (`{$discord_id}`)"
			: ( $order->get_billing_first_name() ?: 'Unknown' );

		foreach ( $order->get_items() as $item ) {
			$product_id = $item->get_product_id();

			if ( ! self::is_package_product( $product_id ) ) {
				continue;
			}

			$discord_category_id = get_post_meta( $product_id, self::META_PACKAGE_DISCORD_CATEGORY, true );
			if ( ! $discord_category_id ) {
				continue;
			}

			$choice_name = $item->get_meta( '_nk_package_choice_name' );
			$choice_id   = $item->get_meta( '_nk_package_choice_id' );

			// Build a clean channel name (Discord allows lowercase, digits, hyphens)
			$channel_name = 'order-' . $order_id . '-' . sanitize_title( $discord_user ?: 'customer' );
			$channel_name = substr( $channel_name, 0, 100 ); // Discord max 100 chars

			// 1. Create the channel via Bot API
			$channel_id = $this->discord_create_channel( $guild_id, $bot_token, $discord_category_id, $channel_name );
			if ( ! $channel_id ) {
				continue;
			}

			// 2. Post the order details embed as the first message
			$fields = array(
				array(
					'name'   => 'Customer',
					'value'  => $customer_display,
					'inline' => true,
				),
				array(
					'name'   => 'Order',
					'value'  => '#' . $order_id,
					'inline' => true,
				),
				array(
					'name'   => 'Package',
					'value'  => $item->get_name(),
					'inline' => false,
				),
			);

			if ( $choice_name ) {
				$fields[] = array(
					'name'   => 'Selected Option',
					'value'  => $choice_name,
					'inline' => false,
				);
			}

			$fields[] = array(
				'name'   => 'Total',
				'value'  => wp_strip_all_tags( $order->get_formatted_order_total() ),
				'inline' => true,
			);

			$embed = array(
				'title'       => 'New Package Order',
				'description' => 'A package purchase requires attention.',
				'color'       => 0xFD6D01, // Nine Kings orange
				'fields'      => $fields,
				'timestamp'   => gmdate( 'c' ),
				'footer'      => array(
					'text' => 'Nine Kings Store',
				),
			);

			// Include the chosen product image as thumbnail if available
			if ( $choice_id ) {
				$chosen_product = wc_get_product( $choice_id );
				if ( $chosen_product ) {
					$img_id  = $chosen_product->get_image_id();
					$img_url = $img_id ? wp_get_attachment_image_url( $img_id, 'medium' ) : '';
					if ( $img_url ) {
						$embed['thumbnail'] = array( 'url' => $img_url );
					}
				}
			}

			$this->discord_send_message( $bot_token, $channel_id, $embed );
		}
	}

	/**
	 * Create a text channel inside a Discord category via the Bot API.
	 *
	 * @param string $guild_id            Discord guild (server) ID.
	 * @param string $bot_token           Discord bot token.
	 * @param string $parent_category_id  Discord category channel ID.
	 * @param string $channel_name        Name for the new channel.
	 * @return string|false               New channel ID on success, false on failure.
	 */
	private function discord_create_channel( $guild_id, $bot_token, $parent_category_id, $channel_name ) {
		$url = self::DISCORD_API . "/guilds/{$guild_id}/channels";

		$payload = array(
			'name'      => $channel_name,
			'type'      => 0, // GUILD_TEXT
			'parent_id' => $parent_category_id,
		);

		$response = wp_remote_post( $url, array(
			'headers' => array(
				'Authorization' => 'Bot ' . $bot_token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			error_log( '[NK Discord] Create channel error: ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			error_log( '[NK Discord] Create channel HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
			return false;
		}

		if ( ! empty( $body['id'] ) ) {
			return $body['id'];
		}

		return false;
	}

	/**
	 * Send an embed message to a Discord channel via the Bot API.
	 *
	 * @param string $bot_token  Discord bot token.
	 * @param string $channel_id Discord channel ID.
	 * @param array  $embed      Embed data.
	 * @return bool
	 */
	private function discord_send_message( $bot_token, $channel_id, $embed ) {
		$url = self::DISCORD_API . "/channels/{$channel_id}/messages";

		$payload = array(
			'embeds' => array( $embed ),
		);

		$response = wp_remote_post( $url, array(
			'headers' => array(
				'Authorization' => 'Bot ' . $bot_token,
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $payload ),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			error_log( '[NK Discord] Send message error: ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			error_log( '[NK Discord] Send message HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
			return false;
		}

		return true;
	}
}
