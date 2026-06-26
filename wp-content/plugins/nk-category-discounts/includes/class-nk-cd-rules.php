<?php
/**
 * Rule storage + active-discount resolution.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NK_CD_Rules {

	/** Per-request memo of expanded category term ids per rule index. */
	private static $term_cache = array();

	/** Per-request memo of the best discount per product id. */
	private static $product_cache = array();

	/**
	 * Get all stored rules (raw).
	 *
	 * @return array
	 */
	public static function get_rules() {
		$rules = get_option( NK_CD_OPTION, array() );
		return is_array( $rules ) ? $rules : array();
	}

	/**
	 * Persist rules.
	 *
	 * @param array $rules
	 */
	public static function save_rules( $rules ) {
		update_option( NK_CD_OPTION, array_values( $rules ) );
		self::flush_cache();
	}

	/**
	 * Clear per-request memo. Also bumps a signature transient so WooCommerce
	 * variation price caches are invalidated when rules change.
	 */
	public static function flush_cache() {
		self::$term_cache    = array();
		self::$product_cache = array();
	}

	/**
	 * Is a single rule active right now (enabled + within its schedule window)?
	 *
	 * @param array $rule
	 * @return bool
	 */
	public static function is_rule_active( $rule ) {
		if ( empty( $rule['enabled'] ) ) {
			return false;
		}
		if ( ! isset( $rule['percentage'] ) || (float) $rule['percentage'] <= 0 ) {
			return false;
		}
		if ( empty( $rule['categories'] ) || ! is_array( $rule['categories'] ) ) {
			return false;
		}

		$tz  = wp_timezone();
		$now = current_datetime(); // DateTimeImmutable in site timezone.

		if ( ! empty( $rule['start'] ) ) {
			$start = self::parse_datetime( $rule['start'], $tz );
			if ( $start && $now < $start ) {
				return false;
			}
		}
		if ( ! empty( $rule['end'] ) ) {
			$end = self::parse_datetime( $rule['end'], $tz );
			if ( $end && $now > $end ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Parse a 'Y-m-d H:i' (or 'Y-m-d\TH:i') string into a DateTimeImmutable in the
	 * given timezone. Returns false on failure.
	 *
	 * @param string       $value
	 * @param DateTimeZone $tz
	 * @return DateTimeImmutable|false
	 */
	public static function parse_datetime( $value, $tz ) {
		$value = trim( str_replace( 'T', ' ', (string) $value ) );
		if ( '' === $value ) {
			return false;
		}
		$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i', $value, $tz );
		if ( false === $dt ) {
			// Tolerate seconds.
			$dt = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, $tz );
		}
		return $dt ? $dt : false;
	}

	/**
	 * Expand a rule's selected categories to include all descendant term ids.
	 *
	 * @param int   $index Rule index (for memoization).
	 * @param array $rule
	 * @return int[] term ids
	 */
	private static function get_rule_term_ids( $index, $rule ) {
		if ( isset( self::$term_cache[ $index ] ) ) {
			return self::$term_cache[ $index ];
		}
		$ids = array();
		foreach ( (array) $rule['categories'] as $cat_id ) {
			$cat_id = (int) $cat_id;
			if ( $cat_id <= 0 ) {
				continue;
			}
			$ids[] = $cat_id;
			$children = get_term_children( $cat_id, 'product_cat' );
			if ( ! is_wp_error( $children ) && ! empty( $children ) ) {
				foreach ( $children as $child_id ) {
					$ids[] = (int) $child_id;
				}
			}
		}
		$ids = array_values( array_unique( $ids ) );
		self::$term_cache[ $index ] = $ids;
		return $ids;
	}

	/**
	 * Should this product ever be eligible (i.e. not a subscription)?
	 *
	 * @param WC_Product $product
	 * @return bool
	 */
	public static function product_is_eligible_type( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return false;
		}
		// Exclude subscription products (per configuration).
		if ( class_exists( 'WC_Subscriptions_Product' ) && WC_Subscriptions_Product::is_subscription( $product ) ) {
			return false;
		}
		$type = $product->get_type();
		if ( is_string( $type ) && false !== strpos( $type, 'subscription' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Resolve the best (highest) active discount percentage for a product.
	 * Matching is by the product's own category ids against each active rule's
	 * selected categories expanded to include descendants.
	 *
	 * For variations, the parent product's categories are used.
	 *
	 * @param WC_Product $product
	 * @return float Percentage (0 = no discount).
	 */
	public static function get_discount_percentage( $product ) {
		if ( ! self::product_is_eligible_type( $product ) ) {
			return 0.0;
		}

		// Resolve the id that carries the product_cat terms (parent for variations).
		$cat_source_id = $product->get_id();
		if ( $product->is_type( 'variation' ) ) {
			$parent_id = $product->get_parent_id();
			if ( $parent_id ) {
				$cat_source_id = $parent_id;
			}
		}

		if ( isset( self::$product_cache[ $cat_source_id ] ) ) {
			return self::$product_cache[ $cat_source_id ];
		}

		$product_term_ids = wc_get_product_term_ids( $cat_source_id, 'product_cat' );
		if ( empty( $product_term_ids ) ) {
			self::$product_cache[ $cat_source_id ] = 0.0;
			return 0.0;
		}

		$best  = 0.0;
		$rules = self::get_rules();
		foreach ( $rules as $index => $rule ) {
			if ( ! self::is_rule_active( $rule ) ) {
				continue;
			}
			$rule_term_ids = self::get_rule_term_ids( $index, $rule );
			if ( empty( $rule_term_ids ) ) {
				continue;
			}
			if ( array_intersect( $product_term_ids, $rule_term_ids ) ) {
				$pct = (float) $rule['percentage'];
				if ( $pct > $best ) {
					$best = $pct;
				}
			}
		}

		$best = min( 100.0, max( 0.0, $best ) );
		self::$product_cache[ $cat_source_id ] = $best;
		return $best;
	}

	/**
	 * Signature of currently-active rules, used to vary WooCommerce variation
	 * price caches so cached ranges don't go stale when a sale starts/ends.
	 *
	 * @return string
	 */
	public static function active_signature() {
		$parts = array();
		foreach ( self::get_rules() as $index => $rule ) {
			if ( self::is_rule_active( $rule ) ) {
				$cats = implode( '.', array_map( 'intval', (array) $rule['categories'] ) );
				$parts[] = $index . ':' . (float) $rule['percentage'] . ':' . $cats;
			}
		}
		return implode( '|', $parts );
	}
}
