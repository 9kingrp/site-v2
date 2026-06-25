<?php
/**
 * Shortcodes for Discord integration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NK_Discord_Shortcodes {

	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_shortcode( 'nk_discord_login', array( $this, 'discord_login_button' ) );
		add_shortcode( 'nk_discord_profile', array( $this, 'discord_profile' ) );
	}

	/**
	 * [nk_discord_login] — Renders a Discord login button.
	 *
	 * Attributes:
	 *   redirect_to - URL to redirect after login (default: current page)
	 *   label       - Button text (default: "Login with Discord")
	 *   class       - Additional CSS classes
	 */
	public function discord_login_button( $atts ) {
		$atts = shortcode_atts( array(
			'redirect_to' => '',
			'label'       => __( 'Login with Discord', 'nk-discord' ),
			'class'       => '',
		), $atts, 'nk_discord_login' );

		if ( is_user_logged_in() ) {
			$user_id      = get_current_user_id();
			$discord_user = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_USER, true );
			$avatar_url   = NK_Discord_User::get_avatar_url( $user_id );
			$display_name = $discord_user ? $discord_user : wp_get_current_user()->display_name;

			$classes = 'nk-discord-login-btn nk-discord-login-btn--logged-in';
			if ( $atts['class'] ) {
				$classes .= ' ' . esc_attr( $atts['class'] );
			}

			$account_url = get_permalink( get_option( 'woocommerce_myaccount_page_id' ) );

			$html = '<a href="' . esc_url( $account_url ) . '" class="' . esc_attr( $classes ) . '">';
			if ( $avatar_url ) {
				$html .= '<img src="' . esc_url( $avatar_url ) . '" alt="" class="nk-discord-btn-avatar" />';
			} else {
				$html .= NK_Discord_WooCommerce::get_discord_svg();
			}
			$html .= ' ' . esc_html( $display_name ) . '</a>';

			return $html;
		}

		$login_url = rest_url( 'nk-discord/v1/login' );
		$redirect  = $atts['redirect_to'] ? $atts['redirect_to'] : get_permalink();
		$login_url = add_query_arg( 'redirect_to', urlencode( $redirect ), $login_url );

		$classes = 'nk-discord-login-btn';
		if ( $atts['class'] ) {
			$classes .= ' ' . esc_attr( $atts['class'] );
		}

		$html  = '<a href="' . esc_url( $login_url ) . '" class="' . esc_attr( $classes ) . '">';
		$html .= NK_Discord_WooCommerce::get_discord_svg();
		$html .= ' ' . esc_html( $atts['label'] ) . '</a>';

		return $html;
	}

	/**
	 * [nk_discord_profile] — Displays the current user's Discord profile info.
	 */
	public function discord_profile( $atts ) {
		if ( ! is_user_logged_in() ) {
			return '';
		}

		$user_id      = get_current_user_id();
		$discord_id   = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_ID, true );
		$discord_user = get_user_meta( $user_id, NK_Discord_User::META_DISCORD_USER, true );

		if ( ! $discord_id ) {
			return '';
		}

		$avatar_url = NK_Discord_User::get_avatar_url( $user_id );

		$html = '<div class="nk-discord-profile-card">';
		if ( $avatar_url ) {
			$html .= '<img src="' . esc_url( $avatar_url ) . '" alt="' . esc_attr( $discord_user ) . '" class="nk-discord-avatar" />';
		}
		$html .= '<div class="nk-discord-profile-info">';
		$html .= '<strong class="nk-discord-name">' . esc_html( $discord_user ) . '</strong>';
		$html .= '</div>';
		$html .= '</div>';

		return $html;
	}
}
