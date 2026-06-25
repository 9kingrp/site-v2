<?php
/**
 * Voucher products — "Name Your Price" style custom amount.
 *
 * Admin: adds a "Voucher" tab in the product editor with enable checkbox,
 *        min amount, max amount, and step fields.
 * Frontend: renders a currency input on the single product page.
 * Cart: overrides the line-item price with the customer's chosen amount.
 * Order: persists the voucher amount as order line-item meta.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NK_Discord_Voucher {

	private static $instance = null;

	const META_VOUCHER_ENABLED = '_nk_voucher_enabled';
	const META_VOUCHER_MIN     = '_nk_voucher_min';
	const META_VOUCHER_MAX     = '_nk_voucher_max';
	const META_VOUCHER_STEP    = '_nk_voucher_step';

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
		add_action( 'woocommerce_before_add_to_cart_button', array( $this, 'render_amount_input' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

		// ── Price display: show "From £X" instead of a fixed price ──────────
		add_filter( 'woocommerce_get_price_html', array( $this, 'voucher_price_html' ), 10, 2 );

		// ── Make voucher products purchasable even without a regular price ──
		add_filter( 'woocommerce_is_purchasable', array( $this, 'force_purchasable' ), 10, 2 );
		add_filter( 'woocommerce_product_get_price', array( $this, 'force_placeholder_price' ), 10, 2 );

		// ── Hide quantity input — voucher amount replaces quantity ──────────
		add_filter( 'woocommerce_is_sold_individually', array( $this, 'force_sold_individually' ), 10, 2 );

		// ── Body class for CSS targeting ─────────────────────────────────────
		add_filter( 'body_class', array( $this, 'add_voucher_body_class' ) );

		// ── Cart: validate, store amount, override price ─────────────────────
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_voucher_amount' ), 10, 3 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'set_cart_item_price' ), 20, 1 );

		// ── Order: persist to order line-item meta ───────────────────────────
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_item_meta' ), 10, 4 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'save_order_item_meta_blocks' ), 10, 2 );
	}

	/* =====================================================================
	   ADMIN — Product Data Tab & Panel
	   ===================================================================== */

	/**
	 * Add a "Voucher" tab to the product data meta box.
	 */
	public function add_product_data_tab( $tabs ) {
		$tabs['nk_voucher'] = array(
			'label'    => __( 'Voucher', 'nk-discord' ),
			'target'   => 'nk_voucher_options',
			'class'    => array( 'show_if_simple', 'show_if_virtual' ),
			'priority' => 76,
		);
		return $tabs;
	}

	/**
	 * Render the Voucher panel.
	 */
	public function render_product_data_panel() {
		global $post;
		$product_id = $post->ID;

		$enabled = get_post_meta( $product_id, self::META_VOUCHER_ENABLED, true );
		$min     = get_post_meta( $product_id, self::META_VOUCHER_MIN, true );
		$max     = get_post_meta( $product_id, self::META_VOUCHER_MAX, true );
		$step    = get_post_meta( $product_id, self::META_VOUCHER_STEP, true );
		?>
		<div id="nk_voucher_options" class="panel woocommerce_options_panel">
			<div class="options_group">
				<?php
				woocommerce_wp_checkbox( array(
					'id'          => self::META_VOUCHER_ENABLED,
					'label'       => __( 'Enable Voucher', 'nk-discord' ),
					'description' => __( 'Let the customer choose their own price / voucher amount.', 'nk-discord' ),
					'value'       => $enabled,
				) );

				woocommerce_wp_text_input( array(
					'id'                => self::META_VOUCHER_MIN,
					'label'             => __( 'Minimum Amount', 'nk-discord' ),
					'description'       => __( 'The lowest amount the customer can enter. Leave blank for no minimum.', 'nk-discord' ),
					'desc_tip'          => true,
					'type'              => 'number',
					'custom_attributes' => array( 'step' => '0.01', 'min' => '0' ),
					'value'             => $min,
				) );

				woocommerce_wp_text_input( array(
					'id'                => self::META_VOUCHER_MAX,
					'label'             => __( 'Maximum Amount', 'nk-discord' ),
					'description'       => __( 'The highest amount the customer can enter. Leave blank for no maximum.', 'nk-discord' ),
					'desc_tip'          => true,
					'type'              => 'number',
					'custom_attributes' => array( 'step' => '0.01', 'min' => '0' ),
					'value'             => $max,
				) );

				woocommerce_wp_text_input( array(
					'id'                => self::META_VOUCHER_STEP,
					'label'             => __( 'Step', 'nk-discord' ),
					'description'       => __( 'Increment step for the amount input (e.g. 1, 0.50, 5). Defaults to 1.', 'nk-discord' ),
					'desc_tip'          => true,
					'type'              => 'number',
					'custom_attributes' => array( 'step' => '0.01', 'min' => '0.01' ),
					'placeholder'       => '1',
					'value'             => $step,
				) );
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Save voucher meta when the product is saved.
	 */
	public function save_product_meta( $post_id ) {
		$enabled = isset( $_POST[ self::META_VOUCHER_ENABLED ] ) ? 'yes' : 'no';
		update_post_meta( $post_id, self::META_VOUCHER_ENABLED, $enabled );

		$fields = array( self::META_VOUCHER_MIN, self::META_VOUCHER_MAX, self::META_VOUCHER_STEP );
		foreach ( $fields as $field ) {
			if ( isset( $_POST[ $field ] ) && '' !== $_POST[ $field ] ) {
				update_post_meta( $post_id, $field, wc_format_decimal( $_POST[ $field ] ) );
			} else {
				delete_post_meta( $post_id, $field );
			}
		}
	}

	/* =====================================================================
	   HELPERS
	   ===================================================================== */

	/**
	 * Check if a product is a voucher product.
	 */
	public static function is_voucher_product( $product_id ) {
		return 'yes' === get_post_meta( $product_id, self::META_VOUCHER_ENABLED, true );
	}

	/**
	 * Get voucher config for a product.
	 */
	public static function get_voucher_config( $product_id ) {
		$min  = get_post_meta( $product_id, self::META_VOUCHER_MIN, true );
		$max  = get_post_meta( $product_id, self::META_VOUCHER_MAX, true );
		$step = get_post_meta( $product_id, self::META_VOUCHER_STEP, true );

		return array(
			'min'  => '' !== $min ? floatval( $min ) : null,
			'max'  => '' !== $max ? floatval( $max ) : null,
			'step' => '' !== $step ? floatval( $step ) : 1,
		);
	}

	/* =====================================================================
	   FRONTEND — Amount Input & Price Display
	   ===================================================================== */

	/**
	 * Show "From £X" on voucher products instead of a fixed price.
	 */
	public function voucher_price_html( $price_html, $product ) {
		if ( ! self::is_voucher_product( $product->get_id() ) ) {
			return $price_html;
		}

		$config = self::get_voucher_config( $product->get_id() );
		if ( null !== $config['min'] && $config['min'] > 0 ) {
			return sprintf(
				/* translators: %s: formatted minimum price */
				__( 'From %s', 'nk-discord' ),
				wc_price( $config['min'] )
			);
		}

		return __( 'Enter your amount', 'nk-discord' );
	}

	/**
	 * Render the custom amount input on the single product page.
	 * Hooked to woocommerce_before_add_to_cart_button so it sits inside the <form>.
	 */
	public function render_amount_input() {
		global $product;

		if ( ! $product || ! self::is_voucher_product( $product->get_id() ) ) {
			return;
		}

		$config   = self::get_voucher_config( $product->get_id() );
		$currency = get_woocommerce_currency_symbol();

		$attrs = '';
		if ( null !== $config['min'] ) {
			$attrs .= ' min="' . esc_attr( $config['min'] ) . '"';
		}
		if ( null !== $config['max'] ) {
			$attrs .= ' max="' . esc_attr( $config['max'] ) . '"';
		}
		$attrs .= ' step="' . esc_attr( $config['step'] ) . '"';

		$default_value = null !== $config['min'] ? $config['min'] : '';
		?>
		<div class="nk-voucher-amount" id="nk-voucher-amount">
			<label class="nk-voucher-amount__label" for="nk_voucher_amount">
				<?php esc_html_e( 'Voucher Amount', 'nk-discord' ); ?>
			</label>
			<div class="nk-voucher-amount__wrap">
				<span class="nk-voucher-amount__currency"><?php echo esc_html( $currency ); ?></span>
				<input type="number"
					   id="nk_voucher_amount"
					   name="nk_voucher_amount"
					   class="nk-voucher-amount__input"
					   value="<?php echo esc_attr( $default_value ); ?>"
					   placeholder="0.00"
					   inputmode="decimal"
					   <?php echo $attrs; ?>
					   required />
			</div>
			<?php if ( null !== $config['min'] || null !== $config['max'] ) : ?>
				<span class="nk-voucher-amount__hint">
					<?php
					if ( null !== $config['min'] && null !== $config['max'] ) {
						printf(
							/* translators: 1: min amount 2: max amount */
							esc_html__( 'Enter an amount between %1$s and %2$s', 'nk-discord' ),
							wp_strip_all_tags( wc_price( $config['min'] ) ),
							wp_strip_all_tags( wc_price( $config['max'] ) )
						);
					} elseif ( null !== $config['min'] ) {
						printf(
							/* translators: %s: min amount */
							esc_html__( 'Minimum amount: %s', 'nk-discord' ),
							wp_strip_all_tags( wc_price( $config['min'] ) )
						);
					} elseif ( null !== $config['max'] ) {
						printf(
							/* translators: %s: max amount */
							esc_html__( 'Maximum amount: %s', 'nk-discord' ),
							wp_strip_all_tags( wc_price( $config['max'] ) )
						);
					}
					?>
				</span>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Enqueue frontend CSS and JS for voucher products.
	 */
	public function enqueue_frontend_assets() {
		if ( ! is_product() ) {
			return;
		}

		$product_id = get_the_ID();
		if ( ! $product_id || ! self::is_voucher_product( $product_id ) ) {
			return;
		}

		wp_enqueue_style(
			'nk-voucher-amount',
			NK_DISCORD_URL . 'assets/css/voucher-amount.css',
			array(),
			(string) filemtime( NK_DISCORD_DIR . 'assets/css/voucher-amount.css' )
		);

		wp_enqueue_script(
			'nk-voucher-amount',
			NK_DISCORD_URL . 'assets/js/voucher-amount.js',
			array( 'jquery' ),
			(string) filemtime( NK_DISCORD_DIR . 'assets/js/voucher-amount.js' ),
			true
		);
	}

	/**
	 * Force voucher products to be purchasable even when no regular price is set.
	 */
	public function force_purchasable( $purchasable, $product ) {
		if ( self::is_voucher_product( $product->get_id() ) ) {
			return true;
		}
		return $purchasable;
	}

	/**
	 * Return a placeholder price (0) for voucher products so WooCommerce
	 * doesn't treat them as unpurchasable. The real price is set at cart time.
	 */
	public function force_placeholder_price( $price, $product ) {
		if ( self::is_voucher_product( $product->get_id() ) && ( '' === $price || is_null( $price ) ) ) {
			return '0';
		}
		return $price;
	}

	/**
	 * Mark voucher products as sold individually so WooCommerce
	 * does not render the quantity input on the product page.
	 */
	public function force_sold_individually( $sold_individually, $product ) {
		if ( self::is_voucher_product( $product->get_id() ) ) {
			return true;
		}
		return $sold_individually;
	}

	/**
	 * Add a body class on single voucher product pages for CSS targeting.
	 */
	public function add_voucher_body_class( $classes ) {
		if ( is_product() ) {
			$product_id = get_the_ID();
			if ( $product_id && self::is_voucher_product( $product_id ) ) {
				$classes[] = 'nk-voucher-product';
			}
		}
		return $classes;
	}

	/* =====================================================================
	   CART — Validate, Store & Override Price
	   ===================================================================== */

	/**
	 * Validate the voucher amount before adding to cart.
	 */
	public function validate_voucher_amount( $passed, $product_id, $quantity ) {
		if ( ! self::is_voucher_product( $product_id ) ) {
			return $passed;
		}

		$amount = isset( $_REQUEST['nk_voucher_amount'] ) ? floatval( $_REQUEST['nk_voucher_amount'] ) : 0;
		$config = self::get_voucher_config( $product_id );

		if ( $amount <= 0 ) {
			wc_add_notice( __( 'Please enter a valid voucher amount.', 'nk-discord' ), 'error' );
			return false;
		}

		if ( null !== $config['min'] && $amount < $config['min'] ) {
			wc_add_notice(
				sprintf(
					__( 'The minimum voucher amount is %s.', 'nk-discord' ),
					wp_strip_all_tags( wc_price( $config['min'] ) )
				),
				'error'
			);
			return false;
		}

		if ( null !== $config['max'] && $amount > $config['max'] ) {
			wc_add_notice(
				sprintf(
					__( 'The maximum voucher amount is %s.', 'nk-discord' ),
					wp_strip_all_tags( wc_price( $config['max'] ) )
				),
				'error'
			);
			return false;
		}

		return $passed;
	}

	/**
	 * Attach the voucher amount to the cart item data.
	 */
	public function add_cart_item_data( $cart_item_data, $product_id ) {
		if ( ! self::is_voucher_product( $product_id ) ) {
			return $cart_item_data;
		}

		if ( isset( $_REQUEST['nk_voucher_amount'] ) ) {
			$cart_item_data['nk_voucher_amount'] = floatval( $_REQUEST['nk_voucher_amount'] );
		}

		return $cart_item_data;
	}

	/**
	 * Display the voucher amount in the cart / checkout summary.
	 */
	public function display_cart_item_data( $item_data, $cart_item ) {
		if ( ! empty( $cart_item['nk_voucher_amount'] ) ) {
			$item_data[] = array(
				'key'   => __( 'Voucher Amount', 'nk-discord' ),
				'value' => wp_strip_all_tags( wc_price( $cart_item['nk_voucher_amount'] ) ),
			);
		}

		return $item_data;
	}

	/**
	 * Override the cart item price with the customer's chosen voucher amount.
	 */
	public function set_cart_item_price( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		if ( did_action( 'woocommerce_before_calculate_totals' ) >= 2 ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( ! empty( $cart_item['nk_voucher_amount'] ) ) {
				$cart_item['data']->set_price( floatval( $cart_item['nk_voucher_amount'] ) );
			}
		}
	}

	/* =====================================================================
	   ORDER — Persist Voucher Amount to Order Line-Item Meta
	   ===================================================================== */

	/**
	 * Save the voucher amount to the order line-item (classic checkout).
	 */
	public function save_order_item_meta( $item, $cart_item_key, $values, $order ) {
		if ( ! empty( $values['nk_voucher_amount'] ) ) {
			$item->add_meta_data( '_nk_voucher_amount', floatval( $values['nk_voucher_amount'] ), true );
		}
	}

	/**
	 * Save the voucher amount to order line-items (blocks checkout / Store API).
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
					! empty( $cart_item['nk_voucher_amount'] )
				) {
					if ( ! $item->get_meta( '_nk_voucher_amount' ) ) {
						$item->add_meta_data( '_nk_voucher_amount', floatval( $cart_item['nk_voucher_amount'] ), true );
						$item->save();
					}
					break;
				}
			}
		}
	}
}
