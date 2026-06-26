<?php
/**
 * Applies active category discounts to WooCommerce prices.
 *
 * The discount is always calculated from the product's REGULAR price, so it
 * overrides any existing sale price while active.
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
		$new = (float) $regular * ( 1 - ( $pct / 100 ) );
		$new = round( $new, wc_get_price_decimals() );
		if ( $new < 0 ) {
			$new = 0.0;
		}
		return $new;
	}

	/**
	 * Filter the active price.
	 *
	 * @param string     $price
	 * @param WC_Product $product
	 * @return string
	 */
	public function filter_price( $price, $product ) {
		if ( ! $this->should_apply() ) {
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
		if ( ! $this->should_apply() ) {
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
		if ( ! $this->should_apply() ) {
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
}
