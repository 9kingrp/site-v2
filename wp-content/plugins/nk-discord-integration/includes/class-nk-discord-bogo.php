<?php
/**
 * Buy One Get One Free — Vehicles only.
 *
 * For every two vehicle products in the cart the cheapest one is free.
 * Configurable via WooCommerce → BOGO Vehicles admin page:
 *   - Enable / disable the promotion.
 *   - Start date and end date (promotion runs across multiple days).
 *   - "Limit to once per day" toggle — each user may only benefit once
 *     per calendar day (resets at midnight in the configured timezone).
 *
 * While the promotion is active, all other coupons / discounts are blocked.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NK_Discord_BOGO {

	private static $instance = null;

	/* ── Option keys ────────────────────────────────────────────────────── */
	const OPTION_KEY = 'nk_bogo_settings';

	/* ── User-meta key for daily usage tracking ─────────────────────────── */
	const META_LAST_BOGO_DATE = '_nk_bogo_last_used_date';

	/* ── Cart fee identifier ────────────────────────────────────────────── */
	const FEE_ID = 'nk_bogo_vehicle_discount';

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

		// ── Admin settings page ──────────────────────────────────────────
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// ── Cart: apply BOGO discount as a negative fee ──────────────────
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'apply_bogo_discount' ) );

		// ── Block all other coupons while BOGO is active ─────────────────
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'block_coupons' ), 10, 3 );
		add_filter( 'woocommerce_coupons_enabled', array( $this, 'disable_coupon_field' ) );

		// ── Record usage on successful order ─────────────────────────────
		add_action( 'woocommerce_order_status_completed', array( $this, 'record_bogo_usage' ) );
		add_action( 'woocommerce_order_status_processing', array( $this, 'record_bogo_usage' ) );

		// ── Display a notice in the cart when BOGO is active ─────────────
		add_action( 'woocommerce_before_cart', array( $this, 'cart_bogo_notice' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'cart_bogo_notice' ) );
	}

	/* =====================================================================
	   HELPERS
	   ===================================================================== */

	/**
	 * Get saved settings with defaults.
	 */
	public static function get_settings() {
		$defaults = array(
			'enabled'        => 'no',
			'start_date'     => '',
			'end_date'       => '',
			'once_per_day'   => 'no',
			'timezone'       => 'Europe/London',
			'min_vehicles'   => 2,
		);
		$saved = get_option( self::OPTION_KEY, array() );
		return wp_parse_args( $saved, $defaults );
	}

	/**
	 * Is the BOGO promotion currently active?
	 */
	public static function is_promotion_active() {
		$s = self::get_settings();

		if ( 'yes' !== $s['enabled'] ) {
			return false;
		}

		$tz  = new DateTimeZone( $s['timezone'] ? $s['timezone'] : 'Europe/London' );
		$now = new DateTime( 'now', $tz );

		if ( $s['start_date'] ) {
			$start = DateTime::createFromFormat( 'Y-m-d', $s['start_date'], $tz );
			if ( $start ) {
				$start->setTime( 0, 0, 0 );
				if ( $now < $start ) {
					return false;
				}
			}
		}

		if ( $s['end_date'] ) {
			$end = DateTime::createFromFormat( 'Y-m-d', $s['end_date'], $tz );
			if ( $end ) {
				$end->setTime( 23, 59, 59 );
				if ( $now > $end ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Has the current user already used the BOGO today?
	 */
	public static function user_used_today( $user_id = null ) {
		if ( ! $user_id ) {
			$user_id = get_current_user_id();
		}
		if ( ! $user_id ) {
			return false;
		}

		$s = self::get_settings();
		if ( 'yes' !== $s['once_per_day'] ) {
			return false; // No daily limit configured.
		}

		$tz    = new DateTimeZone( $s['timezone'] ? $s['timezone'] : 'Europe/London' );
		$today = ( new DateTime( 'now', $tz ) )->format( 'Y-m-d' );
		$last  = get_user_meta( $user_id, self::META_LAST_BOGO_DATE, true );

		return ( $last === $today );
	}

	/**
	 * Check if a product belongs to the "vehicles" category (or any descendant).
	 * Reuses the child-theme helper if available, otherwise does its own check.
	 */
	public static function is_vehicle_product( $product_id ) {
		if ( function_exists( 'nk_product_in_category_or_descendants' ) ) {
			return nk_product_in_category_or_descendants( $product_id, 'vehicles' );
		}

		$term = get_term_by( 'slug', 'vehicles', 'product_cat' );
		if ( ! $term ) {
			return false;
		}
		if ( has_term( $term->term_id, 'product_cat', $product_id ) ) {
			return true;
		}
		$children = get_term_children( $term->term_id, 'product_cat' );
		if ( is_wp_error( $children ) || empty( $children ) ) {
			return false;
		}
		foreach ( $children as $child_id ) {
			if ( has_term( $child_id, 'product_cat', $product_id ) ) {
				return true;
			}
		}
		return false;
	}

	/* =====================================================================
	   CART — Apply BOGO Discount
	   ===================================================================== */

	/**
	 * For every pair of vehicle items in the cart, the cheapest one is free.
	 *
	 * Logic:
	 *  1. Collect all vehicle cart items and their effective prices.
	 *  2. Sort cheapest-first.
	 *  3. For every 2 vehicles, the cheaper one (first in pair) is free.
	 *  4. Apply a single negative fee equal to the total discount.
	 */
	public function apply_bogo_discount( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		if ( ! self::is_promotion_active() ) {
			return;
		}

		// If once-per-day is enabled and user already used it today, skip.
		if ( self::user_used_today() ) {
			return;
		}

		// Gather vehicle items with their prices.
		$vehicle_prices = array();
		foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
			$product_id = $cart_item['product_id'];
			if ( ! self::is_vehicle_product( $product_id ) ) {
				continue;
			}
			$price = (float) $cart_item['data']->get_price();
			// Account for quantity — each unit is a separate "item" for BOGO pairing.
			$qty = (int) $cart_item['quantity'];
			for ( $i = 0; $i < $qty; $i++ ) {
				$vehicle_prices[] = $price;
			}
		}

		// Need at least N vehicles to qualify.
		$s            = self::get_settings();
		$min_vehicles = max( 2, (int) $s['min_vehicles'] );

		if ( count( $vehicle_prices ) < $min_vehicles ) {
			return;
		}

		// Sort ascending so cheapest items come first.
		sort( $vehicle_prices, SORT_NUMERIC );

		// Only the single cheapest vehicle is free (one per transaction).
		$discount = $vehicle_prices[0];

		if ( $discount <= 0 ) {
			return;
		}

		$cart->add_fee(
			__( 'BOGO: Free Vehicle', 'nk-discord' ),
			-$discount,
			false // Not taxable.
		);
	}

	/* =====================================================================
	   BLOCK COUPONS DURING PROMOTION
	   ===================================================================== */

	/**
	 * Invalidate any coupon while BOGO is active.
	 */
	public function block_coupons( $valid, $coupon, $discount ) {
		if ( self::is_promotion_active() && ! self::user_used_today() ) {
			throw new Exception(
				__( 'Coupons cannot be used during the Buy One Get One Free promotion.', 'nk-discord' )
			);
		}
		return $valid;
	}

	/**
	 * Hide the "Have a coupon?" field entirely while BOGO is active.
	 */
	public function disable_coupon_field( $enabled ) {
		if ( self::is_promotion_active() && ! self::user_used_today() ) {
			return false;
		}
		return $enabled;
	}

	/* =====================================================================
	   RECORD USAGE
	   ===================================================================== */

	/**
	 * After a successful order, record that the user used BOGO today.
	 */
	public function record_bogo_usage( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		// Check if this order actually had the BOGO discount applied.
		$has_bogo_fee = false;
		foreach ( $order->get_fees() as $fee ) {
			if ( strpos( $fee->get_name(), 'BOGO' ) !== false ) {
				$has_bogo_fee = true;
				break;
			}
		}
		if ( ! $has_bogo_fee ) {
			return;
		}

		$user_id = $order->get_user_id();
		if ( ! $user_id ) {
			return;
		}

		$s     = self::get_settings();
		$tz    = new DateTimeZone( $s['timezone'] ? $s['timezone'] : 'Europe/London' );
		$today = ( new DateTime( 'now', $tz ) )->format( 'Y-m-d' );

		update_user_meta( $user_id, self::META_LAST_BOGO_DATE, $today );
	}

	/* =====================================================================
	   CART / CHECKOUT NOTICE
	   ===================================================================== */

	/**
	 * Show an info notice when BOGO is active.
	 */
	public function cart_bogo_notice() {
		if ( ! self::is_promotion_active() ) {
			return;
		}

		if ( self::user_used_today() ) {
			wc_print_notice(
				__( 'You have already used the Buy One Get One Free offer today. It will reset at midnight.', 'nk-discord' ),
				'notice'
			);
			return;
		}

		$s = self::get_settings();
		$min = (int) $s['min_vehicles'];
		wc_print_notice(
			sprintf(
				/* translators: %d: number of vehicles required */
				__( '🎉 Buy One Get One Free on Vehicles! Add %d or more vehicles and the cheapest is free. No other coupons may be applied during this promotion.', 'nk-discord' ),
				$min
			),
			'success'
		);
	}

	/* =====================================================================
	   ADMIN — Settings Page
	   ===================================================================== */

	/**
	 * Add a submenu page under WooCommerce.
	 */
	public function add_admin_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'BOGO Vehicles', 'nk-discord' ),
			__( 'BOGO Vehicles', 'nk-discord' ),
			'manage_woocommerce',
			'nk-bogo-vehicles',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register settings.
	 */
	public function register_settings() {
		register_setting( 'nk_bogo_settings_group', self::OPTION_KEY, array( $this, 'sanitize' ) );
	}

	/**
	 * Sanitize settings before save.
	 */
	public function sanitize( $input ) {
		$clean = array();
		$clean['enabled']      = ( ! empty( $input['enabled'] ) ) ? 'yes' : 'no';
		$clean['start_date']   = isset( $input['start_date'] ) ? sanitize_text_field( $input['start_date'] ) : '';
		$clean['end_date']     = isset( $input['end_date'] ) ? sanitize_text_field( $input['end_date'] ) : '';
		$clean['once_per_day'] = ( ! empty( $input['once_per_day'] ) ) ? 'yes' : 'no';
		$clean['timezone']     = isset( $input['timezone'] ) ? sanitize_text_field( $input['timezone'] ) : 'Europe/London';
		$clean['min_vehicles'] = isset( $input['min_vehicles'] ) ? max( 2, absint( $input['min_vehicles'] ) ) : 2;
		return $clean;
	}

	/**
	 * Render the admin settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$s = self::get_settings();
		$active = self::is_promotion_active();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Buy One Get One Free — Vehicles', 'nk-discord' ); ?></h1>

			<?php if ( $active ) : ?>
				<div class="notice notice-success"><p><strong><?php esc_html_e( 'Promotion is currently ACTIVE.', 'nk-discord' ); ?></strong></p></div>
			<?php else : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'Promotion is currently inactive.', 'nk-discord' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php settings_fields( 'nk_bogo_settings_group' ); ?>

				<table class="form-table" role="presentation">
					<!-- Enable -->
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable Promotion', 'nk-discord' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[enabled]" value="1" <?php checked( $s['enabled'], 'yes' ); ?> />
								<?php esc_html_e( 'Activate BOGO: cheapest vehicle is free for every pair in the cart.', 'nk-discord' ); ?>
							</label>
						</td>
					</tr>

					<!-- Minimum Vehicles -->
					<tr>
						<th scope="row"><label for="nk_bogo_min_vehicles"><?php esc_html_e( 'Vehicles Required', 'nk-discord' ); ?></label></th>
						<td>
							<input type="number" id="nk_bogo_min_vehicles" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[min_vehicles]" value="<?php echo esc_attr( $s['min_vehicles'] ); ?>" min="2" step="1" style="width:80px;" />
							<p class="description"><?php esc_html_e( 'Number of vehicles a customer must add to their cart before the cheapest one is free. Minimum 2.', 'nk-discord' ); ?></p>
						</td>
					</tr>

					<!-- Start Date -->
					<tr>
						<th scope="row"><label for="nk_bogo_start"><?php esc_html_e( 'Start Date', 'nk-discord' ); ?></label></th>
						<td>
							<input type="date" id="nk_bogo_start" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[start_date]" value="<?php echo esc_attr( $s['start_date'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave blank to start immediately when enabled.', 'nk-discord' ); ?></p>
						</td>
					</tr>

					<!-- End Date -->
					<tr>
						<th scope="row"><label for="nk_bogo_end"><?php esc_html_e( 'End Date', 'nk-discord' ); ?></label></th>
						<td>
							<input type="date" id="nk_bogo_end" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[end_date]" value="<?php echo esc_attr( $s['end_date'] ); ?>" />
							<p class="description"><?php esc_html_e( 'Leave blank for no end date. Promotion ends at 23:59:59 on this date.', 'nk-discord' ); ?></p>
						</td>
					</tr>

					<!-- Once per day -->
					<tr>
						<th scope="row"><?php esc_html_e( 'Limit to Once Per Day', 'nk-discord' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[once_per_day]" value="1" <?php checked( $s['once_per_day'], 'yes' ); ?> />
								<?php esc_html_e( 'Each user can only receive the BOGO discount once per calendar day. Resets at midnight.', 'nk-discord' ); ?>
							</label>
						</td>
					</tr>

					<!-- Timezone -->
					<tr>
						<th scope="row"><label for="nk_bogo_tz"><?php esc_html_e( 'Timezone', 'nk-discord' ); ?></label></th>
						<td>
							<select id="nk_bogo_tz" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[timezone]">
								<?php
								$timezones = timezone_identifiers_list();
								foreach ( $timezones as $tz ) {
									printf(
										'<option value="%s" %s>%s</option>',
										esc_attr( $tz ),
										selected( $s['timezone'], $tz, false ),
										esc_html( $tz )
									);
								}
								?>
							</select>
							<p class="description"><?php esc_html_e( 'Used for date boundaries and the daily usage reset at midnight.', 'nk-discord' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<hr />
			<h2><?php esc_html_e( 'How It Works', 'nk-discord' ); ?></h2>
			<ul style="list-style:disc;margin-left:2em;">
				<li><?php esc_html_e( 'Applies only to products in the "Vehicles" category (including subcategories).', 'nk-discord' ); ?></li>
				<li><?php echo esc_html( sprintf( __( 'For every %d vehicles in the cart, the cheapest one is free.', 'nk-discord' ), (int) $s['min_vehicles'] ) ); ?></li>
				<li><?php esc_html_e( 'The discount appears as a "BOGO: Free Vehicle" fee line in the cart.', 'nk-discord' ); ?></li>
				<li><?php esc_html_e( 'When "Limit to Once Per Day" is enabled, each user may benefit once per calendar day; the limit resets at midnight in the configured timezone.', 'nk-discord' ); ?></li>
				<li><?php esc_html_e( 'While the promotion is active, all coupon codes are disabled.', 'nk-discord' ); ?></li>
			</ul>
		</div>
		<?php
	}
}
