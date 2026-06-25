<?php
/**
 * WooCommerce customizations for virtual-only store with Discord login.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NK_Discord_WooCommerce {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// Only run if WooCommerce is active
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		// Replace WooCommerce login/register forms with Discord login
		add_action( 'woocommerce_login_form_end', array( $this, 'add_discord_button_woo_login' ) );
		add_filter( 'woocommerce_registration_redirect', array( $this, 'discord_registration_redirect' ) );

		// Remove ALL checkout fields — Discord identity only
		add_filter( 'woocommerce_checkout_fields', array( $this, 'remove_all_checkout_fields' ) );
		add_filter( 'woocommerce_enable_order_notes_field', '__return_false' );

		// Bypass email validation on checkout
		add_filter( 'woocommerce_checkout_process', array( $this, 'bypass_checkout_validation' ), 1 );
		add_filter( 'woocommerce_checkout_posted_data', array( $this, 'inject_discord_checkout_data' ) );

		// Show Discord identity on the checkout form
		add_action( 'woocommerce_checkout_before_customer_details', array( $this, 'show_discord_checkout_identity' ) );

		// Stamp Discord username onto the order after creation
		add_action( 'woocommerce_checkout_create_order', array( $this, 'stamp_discord_on_order' ), 10, 2 );

		// Show Discord username in admin order screen
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'show_discord_in_admin_order' ) );

		// Remove address-related My Account tabs
		add_filter( 'woocommerce_account_menu_items', array( $this, 'remove_address_tab' ) );

		// Skip the address edit page
		add_action( 'template_redirect', array( $this, 'redirect_address_page' ) );

		// Virtual-only orders skip 'processing' and go straight to 'completed'
		add_filter( 'woocommerce_order_item_needs_processing', array( $this, 'virtual_item_skip_processing' ), 10, 3 );

		// Require login to checkout — redirect to Discord login
		add_action( 'template_redirect', array( $this, 'require_discord_login_for_checkout' ) );

		// Replace "My Account" login/register with Discord button
		add_action( 'woocommerce_before_customer_login_form', array( $this, 'replace_myaccount_login' ) );

		// Display Discord info on My Account page
		add_action( 'woocommerce_account_dashboard', array( $this, 'show_discord_info_dashboard' ) );

		// Use Discord avatar as WooCommerce avatar
		add_filter( 'get_avatar_url', array( $this, 'use_discord_avatar' ), 10, 3 );

		// Disable WooCommerce emails that rely on billing email
		add_filter( 'woocommerce_email_recipient_customer_completed_order', array( $this, 'suppress_customer_email' ), 10, 2 );
		add_filter( 'woocommerce_email_recipient_customer_processing_order', array( $this, 'suppress_customer_email' ), 10, 2 );
		add_filter( 'woocommerce_email_recipient_customer_on_hold_order', array( $this, 'suppress_customer_email' ), 10, 2 );

		// Tell Stripe Payment Element that billing fields are handled — don't render its own
		add_filter( 'wc_stripe_upe_params', array( $this, 'override_stripe_params' ) );
		add_filter( 'wc_stripe_params', array( $this, 'override_stripe_params' ) );

		// Hide PayPal express buttons on product pages for non-logged-in users
		add_action( 'wp_enqueue_scripts', array( $this, 'block_paypal_for_guests' ), 99 );

		// ── Blocks checkout support ──────────────────────────────────────────
		// Pre-fill WC customer billing with Discord placeholder data
		add_action( 'template_redirect', array( $this, 'prefill_customer_billing' ), 5 );

		// Enqueue JS + CSS for blocks checkout
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_blocks_checkout_assets' ), 99 );

		// Stamp Discord data on orders placed via Store API (blocks checkout)
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'stamp_discord_on_store_api_order' ), 10, 2 );

		// Inject billing data into Store API checkout request before validation
		add_filter( 'rest_pre_dispatch', array( $this, 'inject_billing_into_store_api' ), 10, 3 );
	}

	/**
	 * Add Discord login button to WooCommerce login form.
	 */
	public function add_discord_button_woo_login() {
		$auth = NK_Discord_Auth::instance();
		$login_url = rest_url( 'nk-discord/v1/login' );
		$redirect = wc_get_page_permalink( 'myaccount' );
		$login_url = add_query_arg( 'redirect_to', urlencode( $redirect ), $login_url );

		echo '<div class="nk-discord-woo-login">';
		echo '<div class="nk-discord-login-separator"><span>' . esc_html__( 'or', 'nk-discord' ) . '</span></div>';
		echo '<a href="' . esc_url( $login_url ) . '" class="nk-discord-login-btn button">';
		echo self::get_discord_svg();
		echo ' ' . esc_html__( 'Login with Discord', 'nk-discord' ) . '</a>';
		echo '</div>';
	}

	/**
	 * Redirect WooCommerce registration to Discord.
	 */
	public function discord_registration_redirect( $redirect ) {
		return rest_url( 'nk-discord/v1/login' ) . '?redirect_to=' . urlencode( $redirect );
	}

	/**
	 * Remove ALL checkout fields — no billing, no shipping, no email.
	 * The customer is identified solely by their Discord account.
	 */
	public function remove_all_checkout_fields( $fields ) {
		$fields['billing']  = array();
		$fields['shipping'] = array();
		$fields['order']    = array();

		return $fields;
	}

	/**
	 * Bypass WooCommerce checkout validation that expects billing fields.
	 */
	public function bypass_checkout_validation() {
		// Clear any billing-related errors WooCommerce may have queued
		if ( ! is_user_logged_in() ) {
			wc_add_notice( __( 'You must be logged in with Discord to checkout.', 'nk-discord' ), 'error' );
			return;
		}
	}

	/**
	 * Inject Discord data into the posted checkout data so WooCommerce
	 * doesn't choke on the missing billing_email / name fields.
	 */
	public function inject_discord_checkout_data( $data ) {
		if ( is_user_logged_in() ) {
			$user_id        = get_current_user_id();
			$discord_user   = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_USER, true );
			$discord_id     = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_ID, true );
			$placeholder    = 'discord_' . $discord_id . '@noreply.9krp.com';

			$data['billing_first_name'] = $discord_user;
			$data['billing_last_name']  = '';
			$data['billing_email']      = $placeholder;
		}

		return $data;
	}

	/**
	 * Display the logged-in Discord identity at the top of the checkout form.
	 */
	public function show_discord_checkout_identity() {
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id      = get_current_user_id();
		$discord_user = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_USER, true );
		$avatar_url   = NK_Discord_User::get_avatar_url( $user_id );

		if ( ! $discord_user ) {
			return;
		}

		echo '<div class="nk-discord-checkout-identity">';
		echo '<h3>' . esc_html__( 'Purchasing as', 'nk-discord' ) . '</h3>';
		echo '<div class="nk-discord-profile">';
		if ( $avatar_url ) {
			echo '<img src="' . esc_url( $avatar_url ) . '" alt="Discord Avatar" class="nk-discord-avatar" />';
		}
		echo '<strong>' . esc_html( $discord_user ) . '</strong>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Stamp Discord username and ID onto the order at creation time.
	 */
	public function stamp_discord_on_order( $order, $data ) {
		$user_id = $order->get_user_id();
		if ( ! $user_id ) {
			return;
		}

		$discord_user = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_USER, true );
		$discord_id   = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_ID, true );

		if ( $discord_user ) {
			$order->update_meta_data( '_nk_discord_username', $discord_user );
		}
		if ( $discord_id ) {
			$order->update_meta_data( '_nk_discord_id', $discord_id );
		}
	}

	/**
	 * Show Discord customer info in the admin order detail screen.
	 */
	public function show_discord_in_admin_order( $order ) {
		$discord_user = $order->get_meta( '_nk_discord_username' );
		$discord_id   = $order->get_meta( '_nk_discord_id' );

		if ( ! $discord_user ) {
			return;
		}

		echo '<div class="order_data_column" style="margin-top:1em;">';
		echo '<h3>' . esc_html__( 'Discord Customer', 'nk-discord' ) . '</h3>';
		echo '<p><strong>' . esc_html( $discord_user ) . '</strong><br>';
		echo '<code>' . esc_html( $discord_id ) . '</code></p>';
		echo '</div>';
	}

	/**
	 * Suppress WooCommerce customer emails (they have no real email address).
	 */
	public function suppress_customer_email( $recipient, $order ) {
		if ( $order && strpos( $recipient, '@noreply.9krp.com' ) !== false ) {
			return '';
		}
		return $recipient;
	}

	/**
	 * Remove the "Addresses" tab from My Account.
	 */
	public function remove_address_tab( $items ) {
		unset( $items['edit-address'] );
		return $items;
	}

	/**
	 * Redirect /my-account/edit-address/ to my-account dashboard.
	 */
	public function redirect_address_page() {
		if ( is_wc_endpoint_url( 'edit-address' ) ) {
			wp_redirect( wc_get_page_permalink( 'myaccount' ) );
			exit;
		}
	}

	/**
	 * Tell WooCommerce that virtual items don't need processing.
	 *
	 * WooCommerce core only auto-completes items that are BOTH virtual
	 * AND downloadable. This store sells virtual-only (non-downloadable)
	 * products, so we override the per-item check: any virtual product
	 * returns false, which lets payment_complete() set the order to
	 * 'completed' immediately — firing woocommerce_order_status_completed
	 * for Discord tickets, roles, notifications, etc.
	 *
	 * @param bool       $needs_processing Whether the item needs processing.
	 * @param WC_Product $product          The product object.
	 * @param int        $order_id         The order ID.
	 * @return bool
	 */
	public function virtual_item_skip_processing( $needs_processing, $product, $order_id ) {
		if ( $product && $product->is_virtual() ) {
			return false;
		}

		return $needs_processing;
	}

	/**
	 * Require Discord login for checkout.
	 */
	public function require_discord_login_for_checkout() {
		if ( is_checkout() && ! is_user_logged_in() && ! is_wc_endpoint_url( 'order-pay' ) && ! is_wc_endpoint_url( 'order-received' ) ) {
			$login_url = rest_url( 'nk-discord/v1/login' );
			$login_url = add_query_arg( 'redirect_to', urlencode( wc_get_checkout_url() ), $login_url );
			wp_redirect( $login_url );
			exit;
		}
	}

	/**
	 * Replace the My Account login/register form with a Discord login.
	 */
	public function replace_myaccount_login() {
		if ( is_user_logged_in() ) {
			return;
		}

		$login_url = rest_url( 'nk-discord/v1/login' );
		$redirect  = wc_get_page_permalink( 'myaccount' );
		$login_url = add_query_arg( 'redirect_to', urlencode( $redirect ), $login_url );

		// Output Discord login and hide the default form via CSS
		echo '<style>.woocommerce-form-login, .woocommerce-form-register, .u-column1, .u-column2 { display: none !important; }</style>';
		echo '<div class="nk-discord-myaccount-login">';
		echo '<h2>' . esc_html__( 'Login to Your Account', 'nk-discord' ) . '</h2>';
		echo '<p>' . esc_html__( 'Sign in with your Discord account to access the store and manage your purchases.', 'nk-discord' ) . '</p>';
		echo '<a href="' . esc_url( $login_url ) . '" class="nk-discord-login-btn nk-discord-login-btn--large">';
		echo self::get_discord_svg();
		echo ' ' . esc_html__( 'Login with Discord', 'nk-discord' ) . '</a>';
		echo '</div>';
	}

	/**
	 * Show Discord account info on My Account dashboard.
	 */
	public function show_discord_info_dashboard() {
		$user_id    = get_current_user_id();
		$discord_id = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_ID, true );

		if ( ! $discord_id ) {
			// User not linked — show link button
			$login_url = rest_url( 'nk-discord/v1/login' );
			$redirect  = wc_get_page_permalink( 'myaccount' );
			$login_url = add_query_arg( 'redirect_to', urlencode( $redirect ), $login_url );

			echo '<div class="nk-discord-link-prompt">';
			echo '<p>' . esc_html__( 'Link your Discord account to access all store features.', 'nk-discord' ) . '</p>';
			echo '<a href="' . esc_url( $login_url ) . '" class="nk-discord-login-btn">';
			echo self::get_discord_svg();
			echo ' ' . esc_html__( 'Link Discord Account', 'nk-discord' ) . '</a>';
			echo '</div>';
			return;
		}

		$discord_user = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_USER, true );
		$avatar_url   = NK_Discord_User::get_avatar_url( $user_id );

		echo '<div class="nk-discord-account-info">';
		echo '<h3>' . esc_html__( 'Discord Account', 'nk-discord' ) . '</h3>';
		echo '<div class="nk-discord-profile">';
		if ( $avatar_url ) {
			echo '<img src="' . esc_url( $avatar_url ) . '" alt="Discord Avatar" class="nk-discord-avatar" />';
		}
		echo '<div class="nk-discord-details">';
		echo '<strong>' . esc_html( $discord_user ) . '</strong>';
		echo '<span class="nk-discord-id">' . esc_html( $discord_id ) . '</span>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Use Discord avatar as WordPress avatar.
	 */
	public function use_discord_avatar( $url, $id_or_email, $args ) {
		$user_id = 0;

		if ( is_numeric( $id_or_email ) ) {
			$user_id = absint( $id_or_email );
		} elseif ( is_string( $id_or_email ) ) {
			$user = get_user_by( 'email', $id_or_email );
			if ( $user ) {
				$user_id = $user->ID;
			}
		} elseif ( $id_or_email instanceof WP_User ) {
			$user_id = $id_or_email->ID;
		} elseif ( $id_or_email instanceof WP_Comment ) {
			$user_id = $id_or_email->user_id;
		}

		if ( $user_id ) {
			$discord_avatar = NK_Discord_User::get_avatar_url( $user_id );
			if ( $discord_avatar ) {
				return $discord_avatar;
			}
		}

		return $url;
	}

	/**
	 * Block PayPal express checkout buttons for non-logged-in users.
	 * PayPal PPCP renders buttons as iframes on product pages, allowing
	 * guests to pay without Discord login. This hides them entirely and
	 * places a clickable overlay that redirects to Discord OAuth.
	 */
	public function block_paypal_for_guests() {
		if ( is_user_logged_in() ) {
			return;
		}

		// Only on product pages and cart
		if ( ! is_product() && ! is_cart() ) {
			return;
		}

		$login_url = rest_url( 'nk-discord/v1/login' );
		$redirect  = get_permalink();
		$login_url = add_query_arg( 'redirect_to', urlencode( $redirect ), $login_url );

		$css = '
			/* Hide PayPal/GPay/ApplePay express buttons for guests */
			.ppc-button-wrapper,
			#ppc-button,
			.ppcp-messages,
			#ppcp-messages,
			.wc-ppcp-express-checkout,
			[id*="paypal-button"],
			.ppcp-button-apm {
				position: relative !important;
				pointer-events: none !important;
				opacity: 0.4 !important;
			}
		';

		wp_register_style( 'nk-discord-paypal-guest-block', false );
		wp_enqueue_style( 'nk-discord-paypal-guest-block' );
		wp_add_inline_style( 'nk-discord-paypal-guest-block', $css );

		$js = '
			(function() {
				"use strict";
				var loginUrl = ' . wp_json_encode( $login_url ) . ';

				function blockPayPal() {
					var selectors = [
						".ppc-button-wrapper",
						"#ppc-button",
						"[id*=paypal-button]",
						".ppcp-button-apm"
					];
					selectors.forEach(function(sel) {
						document.querySelectorAll(sel).forEach(function(el) {
							if (el.querySelector(".nk-pp-login-block")) return;
							el.style.position = "relative";
							var overlay = document.createElement("div");
							overlay.className = "nk-pp-login-block";
							overlay.style.cssText = "position:absolute;inset:0;z-index:9999;cursor:pointer;" +
								"background:rgba(0,0,0,0.55);border-radius:8px;" +
								"display:flex;align-items:center;justify-content:center;" +
								"font-size:12px;color:#fff;font-weight:600;letter-spacing:0.5px;pointer-events:auto;opacity:1;";
							overlay.innerHTML = "<span style=\"background:rgba(0,0,0,0.7);padding:6px 14px;border-radius:6px;\">Login with Discord to purchase</span>";
							overlay.addEventListener("click", function(e) {
								e.preventDefault();
								e.stopImmediatePropagation();
								window.location.href = loginUrl;
							});
							el.appendChild(overlay);
						});
					});
				}

				blockPayPal();
				var observer = new MutationObserver(blockPayPal);
				observer.observe(document.body, { childList: true, subtree: true });
			})();
		';

		wp_register_script( 'nk-discord-paypal-guest-block', '', array(), '', true );
		wp_enqueue_script( 'nk-discord-paypal-guest-block' );
		wp_add_inline_script( 'nk-discord-paypal-guest-block', $js );
	}

	/**
	 * Pre-fill the WC customer session with Discord placeholder billing data.
	 * The blocks checkout React app reads from WC()->customer on first load.
	 */
	public function prefill_customer_billing() {
		if ( ! is_checkout() || ! is_user_logged_in() ) {
			return;
		}

		$user_id      = get_current_user_id();
		$discord_user = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_USER, true );
		$discord_id   = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_ID, true );
		$base_country = WC()->countries->get_base_country();

		if ( ! $discord_user || ! WC()->customer ) {
			return;
		}

		$customer = WC()->customer;
		$customer->set_billing_first_name( $discord_user );
		$customer->set_billing_last_name( '-' );
		$customer->set_billing_email( 'discord_' . $discord_id . '@noreply.9krp.com' );
		$customer->set_billing_phone( '' );
		$customer->set_billing_country( $base_country );
		$customer->set_billing_address_1( 'N/A' );
		$customer->set_billing_address_2( '' );
		$customer->set_billing_city( 'London' );
		$customer->set_billing_state( '' );
		$customer->set_billing_postcode( 'SW1A 1AA' );
		$customer->save();
	}

	/**
	 * Enqueue JS + inline CSS for the WooCommerce Blocks checkout.
	 * Hides contact info, billing address, express checkout, and order notes.
	 * Dispatches Discord placeholder data into the wc/store/cart data store.
	 */
	public function enqueue_blocks_checkout_assets() {
		if ( ! is_checkout() ) {
			return;
		}

		// ── CSS: Hide unwanted blocks ────────────────────────────────────────
		$css = '
			/* Hide Contact Information block */
			.wc-block-checkout__contact-fields,
			.wp-block-woocommerce-checkout-contact-information-block,
			/* Hide Billing Address block */
			.wc-block-checkout__billing-fields,
			.wp-block-woocommerce-checkout-billing-address-block,
			/* Hide Shipping Address block */
			.wc-block-checkout__shipping-fields,
			.wp-block-woocommerce-checkout-shipping-address-block,
			/* Hide Shipping Method block */
			.wp-block-woocommerce-checkout-shipping-method-block,
			.wp-block-woocommerce-checkout-pickup-options-block,
			/* Hide Express Checkout block */
			.wc-block-components-express-payment,
			.wc-block-components-express-payment-continue-rule,
			/* Hide Order Notes block */
			.wc-block-checkout__order-notes,
			.wp-block-woocommerce-checkout-order-note-block,
			/* Hide classic Stripe express checkout (fallback) */
			#wc-stripe-express-checkout-element,
			.wc-stripe-express-checkout-element,
			#wc-stripe-payment-request-wrapper,
			.woocommerce-checkout #express-checkout-or-separator {
				display: none !important;
			}
		';
		wp_register_style( 'nk-discord-blocks-checkout', false );
		wp_enqueue_style( 'nk-discord-blocks-checkout' );
		wp_add_inline_style( 'nk-discord-blocks-checkout', $css );

		// ── JS: Always load — strips required attrs, clears validation,
		//    and pre-fills billing from Discord data when available ─────────
		$billing = array(
			'first_name' => 'Customer',
			'last_name'  => '-',
			'email'      => 'noreply@noreply.9krp.com',
			'phone'      => '',
			'country'    => WC()->countries->get_base_country(),
			'address_1'  => 'N/A',
			'address_2'  => '',
			'city'       => 'London',
			'state'      => '',
			'postcode'   => 'SW1A 1AA',
		);

		if ( is_user_logged_in() ) {
			$user_id      = get_current_user_id();
			$discord_user = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_USER, true );
			$discord_id   = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_ID, true );

			if ( $discord_user ) {
				$billing['first_name'] = $discord_user;
				$billing['email']      = 'discord_' . $discord_id . '@noreply.9krp.com';
			}
		}

		wp_enqueue_script(
			'nk-discord-blocks-checkout',
			NK_DISCORD_URL . 'assets/js/blocks-checkout.js',
			array(),
			(string) filemtime( NK_DISCORD_DIR . 'assets/js/blocks-checkout.js' ),
			true
		);
		wp_localize_script( 'nk-discord-blocks-checkout', 'nkDiscordCheckout', array(
			'billingAddress' => $billing,
		) );
	}

	/**
	 * Stamp Discord data on orders placed via the WooCommerce Store API (blocks checkout).
	 */
	public function stamp_discord_on_store_api_order( $order, $request ) {
		$user_id = $order->get_user_id();
		if ( ! $user_id ) {
			return;
		}

		$discord_user = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_USER, true );
		$discord_id   = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_ID, true );

		if ( $discord_user ) {
			$order->update_meta_data( '_nk_discord_username', $discord_user );
		}
		if ( $discord_id ) {
			$order->update_meta_data( '_nk_discord_id', $discord_id );
		}

		// Ensure billing data is set even if the hidden fields didn't submit cleanly
		$placeholder_email = 'discord_' . $discord_id . '@noreply.9krp.com';
		if ( ! $order->get_billing_email() || $order->get_billing_email() !== $placeholder_email ) {
			$order->set_billing_first_name( $discord_user ?: '' );
			$order->set_billing_last_name( '' );
			$order->set_billing_email( $placeholder_email );
		}
	}

	/**
	 * Inject billing address into the Store API checkout request before
	 * WooCommerce validates it. The billing form is hidden on the frontend,
	 * so the POST body may arrive with empty billing fields.
	 *
	 * Hooked to rest_pre_dispatch which fires BEFORE arg validation.
	 *
	 * @param mixed            $result  Response to replace the requested version with.
	 * @param \WP_REST_Server  $server  REST server instance.
	 * @param \WP_REST_Request $request Request used to generate the response.
	 * @return mixed
	 */
	public function inject_billing_into_store_api( $result, $server, $request ) {
		// Only act on Store API checkout POST requests
		$route = $request->get_route();
		if ( strpos( $route, '/wc/store' ) === false || strpos( $route, 'checkout' ) === false ) {
			return $result;
		}
		if ( $request->get_method() !== 'POST' ) {
			return $result;
		}
		if ( ! is_user_logged_in() ) {
			return $result;
		}

		$user_id      = get_current_user_id();
		$discord_user = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_USER, true );
		$discord_id   = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_ID, true );

		$base_country = WC()->countries ? WC()->countries->get_base_country() : 'GB';
		$billing = array(
			'first_name' => $discord_user ?: 'Customer',
			'last_name'  => '-',
			'email'      => $discord_id ? 'discord_' . $discord_id . '@noreply.9krp.com' : 'noreply@noreply.9krp.com',
			'phone'      => '',
			'country'    => $base_country,
			'address_1'  => 'N/A',
			'address_2'  => '',
			'city'       => 'London',
			'state'      => '',
			'postcode'   => 'SW1A 1AA',
		);

		// Read the raw body, modify billing_address, write it back, then
		// force WP to re-parse the JSON by resetting the internal flag.
		$raw_body = $request->get_body();
		$body_params = json_decode( $raw_body, true );
		if ( ! is_array( $body_params ) ) {
			$body_params = array();
		}

		if ( ! isset( $body_params['billing_address'] ) || ! is_array( $body_params['billing_address'] ) ) {
			$body_params['billing_address'] = array();
		}
		foreach ( $billing as $key => $value ) {
			if ( empty( $body_params['billing_address'][ $key ] ) ) {
				$body_params['billing_address'][ $key ] = $value;
			}
		}

		// Also fill shipping_address if present
		if ( isset( $body_params['shipping_address'] ) && is_array( $body_params['shipping_address'] ) ) {
			$ship_billing = $billing;
			unset( $ship_billing['email'] );
			foreach ( $ship_billing as $key => $value ) {
				if ( empty( $body_params['shipping_address'][ $key ] ) ) {
					$body_params['shipping_address'][ $key ] = $value;
				}
			}
		}

		// Write modified JSON body back and reset the parsed-JSON flag
		// so WP re-parses from the updated body.
		$request->set_body( wp_json_encode( $body_params ) );

		// Use reflection to reset the parsed_json flag so WP re-reads the body
		$ref = new \ReflectionProperty( $request, 'parsed_json' );
		$ref->setAccessible( true );
		$ref->setValue( $request, false );

		// Also reset the cached JSON params
		$pref = new \ReflectionProperty( $request, 'params' );
		$pref->setAccessible( true );
		$params = $pref->getValue( $request );
		$params['JSON'] = array();
		$pref->setValue( $request, $params );

		return $result;
	}

	/**
	 * Override Stripe UPE params so the Payment Element does not render
	 * its own Contact Information / Billing Address fields.
	 */
	public function override_stripe_params( $params ) {
		// Disable Optimized Checkout — it renders its own full billing/contact form
		$params['isOCEnabled'] = false;

		// Disable express checkout buttons (GPay/Link/Apple Pay) that collect billing
		$params['isExpressCheckoutEnabled'] = false;
		$params['isLinkEnabled']            = false;

		// Tell Stripe JS that all billing fields are present on the page
		$params['enabledBillingFields'] = array(
			'billing_first_name',
			'billing_last_name',
			'billing_email',
			'billing_phone',
			'billing_country',
			'billing_address_1',
			'billing_address_2',
			'billing_city',
			'billing_state',
			'billing_postcode',
		);

		// Pre-fill billing data from Discord so Stripe has what it needs
		if ( is_user_logged_in() ) {
			$user_id      = get_current_user_id();
			$discord_user = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_USER, true );
			$discord_id   = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_ID, true );

			$params['customerBillingData'] = array(
				'name'    => $discord_user ?: '',
				'email'   => 'discord_' . $discord_id . '@noreply.9krp.com',
				'phone'   => '',
				'address' => array(
					'country'     => WC()->countries->get_base_country(),
					'line1'       => '',
					'line2'       => '',
					'city'        => '',
					'state'       => '',
					'postal_code' => '',
				),
			);
		}

		return $params;
	}

	/**
	 * Get the Discord SVG icon markup.
	 */
	public static function get_discord_svg() {
		return '<svg width="20" height="15" viewBox="0 0 127.14 96.36" fill="none" xmlns="http://www.w3.org/2000/svg">
			<path d="M107.7,8.07A105.15,105.15,0,0,0,81.47,0a72.06,72.06,0,0,0-3.36,6.83A97.68,97.68,0,0,0,49,6.83,72.37,72.37,0,0,0,45.64,0,105.89,105.89,0,0,0,19.39,8.09C2.79,32.65-1.71,56.6.54,80.21h0A105.73,105.73,0,0,0,32.71,96.36,77.7,77.7,0,0,0,39.6,85.25a68.42,68.42,0,0,1-10.85-5.18c.91-.66,1.8-1.34,2.66-2a75.57,75.57,0,0,0,64.32,0c.87.71,1.76,1.39,2.66,2a68.68,68.68,0,0,1-10.87,5.19,77,77,0,0,0,6.89,11.1A105.25,105.25,0,0,0,126.6,80.22h0C129.24,52.84,122.09,29.11,107.7,8.07ZM42.45,65.69C36.18,65.69,31,60,31,53s5-12.74,11.43-12.74S54,46,53.89,53,48.84,65.69,42.45,65.69Zm42.24,0C78.41,65.69,73.25,60,73.25,53s5-12.74,11.44-12.74S96.23,46,96.12,53,91.08,65.69,84.69,65.69Z" fill="currentColor"/>
		</svg>';
	}
}
