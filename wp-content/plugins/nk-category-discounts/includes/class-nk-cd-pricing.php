<?php
/**
 * Applies active category discounts to WooCommerce prices.
 *
 * The discount is always calculated from the product's REGULAR price, so it
 * overrides any existing sale price while active.
 *
 * Voucher ("name your price") products are a special case: they have no fixed
 * regular price and their price is set from the customer-entered amount at cart
 * time. Those are handled separately in apply_voucher_discount().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NK_CD_Pricing {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register price filters.
	 */
	public function hooks() {
		// Simple / generic products.
		add_filter( 'woocommerce_product_get_price', array( $this, 'filter_price' ), 999, 2 );
		add_filter( 'woocommerce_product_get_sale_price', array( $this, 'filter_sale_price' ), 999, 2 );

		// Single variations.
		add_filter( 'woocommerce_product_variation_get_price', array( $this, 'filter_price' ), 999, 2 );
		add_filter( 'woocommerce_product_variation_get_sale_price', array( $this, 'filter_sale_price' ), 999, 2 );

		// Variable product price ranges (cached arrays).
		add_filter( 'woocommerce_variation_prices_price', array( $this, 'filter_variation_prices_price' ), 999, 3 );
		add_filter( 'woocommerce_variation_prices_sale_price', array( $this, 'filter_variation_prices_price' ), 999, 3 );

		// Make products show as "on sale" so the original price is struck through.
		add_filter( 'woocommerce_product_is_on_sale', array( $this, 'filter_is_on_sale' ), 999, 2 );

		// Vary the variation-price cache by the active discount signature.
		add_filter( 'woocommerce_get_variation_prices_hash', array( $this, 'filter_prices_hash' ), 999, 3 );

		// Voucher ("name your price") products: their price is set from the
		// customer-entered amount at cart time, so discount that amount AFTER
		// the voucher plugin (priority 20) has set it.
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_voucher_discount' ), 30, 1 );

		// Reflect the discount in the voucher "From £X" price label.
		add_filter( 'woocommerce_get_price_html', array( $this, 'filter_voucher_price_html' ), 20, 2 );
	}

	/**
	 * Apply discounts on the storefront, cart, checkout and AJAX/Store-API contexts,
	 * but NOT in the wp-admin product editor (so stored prices stay visible there).
	 *
	 * @return bool
	 */
	private function should_apply() {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return false;
		}
		return true;
	}

	/**
	 * Is this a voucher ("name your price") product? Those are priced dynamically
	 * at cart time and must not be touched by the regular-price-based filters.
	 *
	 * @param WC_Product $product
	 * @return bool
	 */
	private function is_voucher_product( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		if ( ! class_exists( 'NK_Discord_Voucher' ) ) {
			return false;
		}
		return NK_Discord_Voucher::is_voucher_product( $product->get_id() );
	}

	/**
	 * Compute a discounted price from a product's regular price.
	 *
	 * @param WC_Product   $product
	 * @param string|float $regular Optional explicit regular price.
	 * @return float|null Null when no discount applies.
	 */
	private function discounted( $product, $regular = null ) {
		$pct = NK_CD_Rules::get_discount_percentage( $product );
		if ( $pct <= 0 ) {
			return null;
		}
		if ( null === $regular ) {
			$regular = $product->get_regular_price();
		}
		if ( '' === $regular || null === $regular || ! is_numeric( $regular ) ) {
			return null;
		}
		return $this->apply_pct( (float) $regular, $pct );
	}

	/**
	 * Apply a percentage discount to a base amount, rounded to store precision.
	 *
	 * @param float $amount
	 * @param float $pct
	 * @return float
	 */
	private function apply_pct( $amount, $pct ) {
		$new = (float) $amount * ( 1 - ( $pct / 100 ) );
		$new = round( $new, wc_get_price_decimals() );
		return $new < 0 ? 0.0 : $new;
	}

	/**
	 * Filter the active price.
	 *
	 * @param string     $price
	 * @param WC_Product $product
	 * @return string
	 */
	public function filter_price( $price, $product ) {
		if ( ! $this->should_apply() || $this->is_voucher_product( $product ) ) {
			return $price;
		}
		$new = $this->discounted( $product );
		return ( null === $new ) ? $price : (string) $new;
	}

	/**
	 * Filter the sale price (so display + on-sale logic reflect the discount).
	 *
	 * @param string     $price
	 * @param WC_Product $product
	 * @return string
	 */
	public function filter_sale_price( $price, $product ) {
		if ( ! $this->should_apply() || $this->is_voucher_product( $product ) ) {
			return $price;
		}
		$new = $this->discounted( $product );
		return ( null === $new ) ? $price : (string) $new;
	}

	/**
	 * Filter individual variation prices inside the cached price range arrays.
	 *
	 * @param string     $price
	 * @param WC_Product $variation
	 * @param WC_Product $parent
	 * @return string
	 */
	public function filter_variation_prices_price( $price, $variation, $parent ) {
		if ( ! $this->should_apply() ) {
			return $price;
		}
		$regular = $variation->get_regular_price();
		$new     = $this->discounted( $variation, $regular );
		return ( null === $new ) ? $price : (string) $new;
	}

	/**
	 * Force on-sale status when a discount applies.
	 *
	 * @param bool       $on_sale
	 * @param WC_Product $product
	 * @return bool
	 */
	public function filter_is_on_sale( $on_sale, $product ) {
		if ( ! $this->should_apply() || $this->is_voucher_product( $product ) ) {
			return $on_sale;
		}
		if ( $product->is_type( 'variable' ) ) {
			// Leave variable parents to WooCommerce's own range comparison,
			// which already reflects our filtered variation prices.
			return $on_sale;
		}
		$new = $this->discounted( $product );
		if ( null !== $new ) {
			return true;
		}
		return $on_sale;
	}

	/**
	 * Add the active discount signature to the variation-price cache hash.
	 *
	 * @param array      $hash
	 * @param WC_Product $product
	 * @param bool       $for_display
	 * @return array
	 */
	public function filter_prices_hash( $hash, $product, $for_display ) {
		if ( ! $this->should_apply() ) {
			return $hash;
		}
		$sig = NK_CD_Rules::active_signature();
		if ( '' !== $sig ) {
			$hash[] = 'nk_cd:' . $sig;
		}
		return $hash;
	}

	/**
	 * Discount the customer-entered amount on voucher products at cart time.
	 * Runs after the voucher plugin (priority 20) has set the raw amount, and
	 * is idempotent because it always recomputes from the stored amount.
	 *
	 * @param WC_Cart $cart
	 */
	public function apply_voucher_discount( $cart ) {
		if ( ! $this->should_apply() ) {
			return;
		}
		if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_cart' ) ) {
			return;
		}
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item['nk_voucher_amount'] ) || empty( $cart_item['data'] ) ) {
				continue;
			}
			$product = $cart_item['data'];
			$pct     = NK_CD_Rules::get_discount_percentage( $product );
			if ( $pct <= 0 ) {
				continue;
			}
			$base = (float) $cart_item['nk_voucher_amount'];
			$product->set_price( $this->apply_pct( $base, $pct ) );
		}
	}

	/**
	 * Reflect the discount in the voucher "From £X" price label, showing the
	 * original minimum struck through next to the discounted minimum.
	 *
	 * @param string     $html
	 * @param WC_Product $product
	 * @return string
	 */
	public function filter_voucher_price_html( $html, $product ) {
		if ( ! $this->should_apply() || ! $this->is_voucher_product( $product ) ) {
			return $html;
		}
		$pct = NK_CD_Rules::get_discount_percentage( $product );
		if ( $pct <= 0 || ! method_exists( 'NK_Discord_Voucher', 'get_voucher_config' ) ) {
			return $html;
		}
		$config = NK_Discord_Voucher::get_voucher_config( $product->get_id() );
		if ( empty( $config['min'] ) || $config['min'] <= 0 ) {
			return $html;
		}
		$orig = (float) $config['min'];
		$new  = $this->apply_pct( $orig, $pct );
		return sprintf(
			/* translators: %s: formatted price */
			__( 'From %s', 'nk-category-discounts' ),
			wc_format_sale_price( wc_price( $orig ), wc_price( $new ) )
		);
	}
}
