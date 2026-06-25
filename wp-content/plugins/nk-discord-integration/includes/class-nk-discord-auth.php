<?php
/**
 * Handles Discord OAuth2 authentication flow.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NK_Discord_Auth {

	private static $instance = null;

	const DISCORD_API      = 'https://discord.com/api/v10';
	const AUTHORIZE_URL    = 'https://discord.com/api/oauth2/authorize';
	const TOKEN_URL        = 'https://discord.com/api/oauth2/token';
	const USER_URL         = 'https://discord.com/api/v10/users/@me';
	const GUILDS_URL       = 'https://discord.com/api/v10/users/@me/guilds';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'login_form', array( $this, 'add_discord_login_button' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'login_enqueue_scripts', array( $this, 'enqueue_login_styles' ) );

		// Redirect default registration to Discord login
		add_action( 'login_form_register', array( $this, 'redirect_registration' ) );
	}

	/**
	 * Register REST API routes for the OAuth2 callback.
	 */
	public function register_routes() {
		register_rest_route( 'nk-discord/v1', '/callback', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_callback' ),
			'permission_callback' => '__return_true',
		) );

		register_rest_route( 'nk-discord/v1', '/login', array(
			'methods'             => 'GET',
			'callback'            => array( $this, 'handle_login_redirect' ),
			'permission_callback' => '__return_true',
		) );
	}

	/**
	 * Build the Discord authorize URL.
	 */
	public function get_authorize_url( $redirect_to = '' ) {
		$client_id    = NK_Discord_Settings::get( 'client_id' );
		$redirect_uri = rest_url( 'nk-discord/v1/callback' );

		$state = wp_create_nonce( 'nk_discord_oauth' );

		if ( $redirect_to ) {
			// Store redirect destination in a transient keyed by the state
			set_transient( 'nk_discord_redirect_' . $state, $redirect_to, 600 );
		}

		$params = array(
			'client_id'     => $client_id,
			'redirect_uri'  => $redirect_uri,
			'response_type' => 'code',
			'scope'         => 'identify guilds',
			'state'         => $state,
			'prompt'        => 'none',
		);

		return self::AUTHORIZE_URL . '?' . http_build_query( $params );
	}

	/**
	 * Handle the /login redirect — sends user to Discord.
	 */
	public function handle_login_redirect( WP_REST_Request $request ) {
		$redirect_to = $request->get_param( 'redirect_to' );
		$url = $this->get_authorize_url( $redirect_to ? $redirect_to : home_url() );

		wp_redirect( $url );
		exit;
	}

	/**
	 * Handle the OAuth2 callback from Discord.
	 */
	public function handle_callback( WP_REST_Request $request ) {
		$code  = $request->get_param( 'code' );
		$state = $request->get_param( 'state' );
		$error = $request->get_param( 'error' );

		// Handle user denial or error
		if ( $error || empty( $code ) ) {
			wp_redirect( wp_login_url() . '?discord_error=denied' );
			exit;
		}

		// Verify state nonce
		if ( ! wp_verify_nonce( $state, 'nk_discord_oauth' ) ) {
			wp_redirect( wp_login_url() . '?discord_error=invalid_state' );
			exit;
		}

		// Exchange code for access token
		$token_data = $this->exchange_code( $code );
		if ( is_wp_error( $token_data ) ) {
			wp_redirect( wp_login_url() . '?discord_error=token_exchange' );
			exit;
		}

		// Get Discord user info
		$discord_user = $this->get_discord_user( $token_data['access_token'] );
		if ( is_wp_error( $discord_user ) ) {
			wp_redirect( wp_login_url() . '?discord_error=user_fetch' );
			exit;
		}

		// Allow other modules (e.g. blacklist) to block login before user creation
		$pre_login = apply_filters( 'nk_discord_pre_login', true, $discord_user );
		if ( is_wp_error( $pre_login ) ) {
			wp_redirect( home_url( '?nk_blacklisted=1' ) );
			exit;
		}

		// Login or register the WordPress user
		$wp_user = NK_Discord_User::find_or_create( $discord_user, $token_data );
		if ( is_wp_error( $wp_user ) ) {
			wp_redirect( wp_login_url() . '?discord_error=user_create' );
			exit;
		}

		// Log the user in
		wp_set_current_user( $wp_user->ID );
		wp_set_auth_cookie( $wp_user->ID, true );
		do_action( 'wp_login', $wp_user->user_login, $wp_user );

		// Redirect
		$redirect_to = get_transient( 'nk_discord_redirect_' . $state );
		delete_transient( 'nk_discord_redirect_' . $state );

		if ( ! $redirect_to ) {
			$redirect_to = wc_get_page_permalink( 'myaccount' );
			if ( ! $redirect_to ) {
				$redirect_to = home_url();
			}
		}

		wp_redirect( $redirect_to );
		exit;
	}

	/**
	 * Exchange the authorization code for an access token.
	 */
	private function exchange_code( $code ) {
		$response = wp_remote_post( self::TOKEN_URL, array(
			'body' => array(
				'client_id'     => NK_Discord_Settings::get( 'client_id' ),
				'client_secret' => NK_Discord_Settings::get( 'client_secret' ),
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => rest_url( 'nk-discord/v1/callback' ),
			),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['error'] ) ) {
			return new WP_Error( 'discord_token_error', $body['error_description'] ?? $body['error'] );
		}

		return $body;
	}

	/**
	 * Fetch the Discord user profile using the access token.
	 */
	private function get_discord_user( $access_token ) {
		$response = wp_remote_get( self::USER_URL, array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $access_token,
			),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( isset( $body['code'] ) && $body['code'] === 0 ) {
			return new WP_Error( 'discord_user_error', $body['message'] ?? 'Failed to fetch Discord user.' );
		}

		return $body;
	}

	/**
	 * Add a "Login with Discord" button to the WP login form.
	 */
	public function add_discord_login_button() {
		$login_url = rest_url( 'nk-discord/v1/login' );

		// Pass through any redirect_to parameter
		if ( isset( $_GET['redirect_to'] ) ) {
			$login_url = add_query_arg( 'redirect_to', urlencode( $_GET['redirect_to'] ), $login_url );
		}

		echo '<div class="nk-discord-login-separator"><span>' . esc_html__( 'or', 'nk-discord' ) . '</span></div>';
		echo '<a href="' . esc_url( $login_url ) . '" class="nk-discord-login-btn">';
		echo NK_Discord_WooCommerce::get_discord_svg();
		echo ' ' . esc_html__( 'Login with Discord', 'nk-discord' ) . '</a>';

		// Show error messages
		if ( isset( $_GET['discord_error'] ) ) {
			$messages = array(
				'denied'        => __( 'Discord login was cancelled.', 'nk-discord' ),
				'invalid_state' => __( 'Invalid login state. Please try again.', 'nk-discord' ),
				'token_exchange' => __( 'Failed to authenticate with Discord. Please try again.', 'nk-discord' ),
				'user_fetch'    => __( 'Could not retrieve your Discord profile. Please try again.', 'nk-discord' ),
				'user_create'   => __( 'Failed to create your account. Please try again.', 'nk-discord' ),
				'blacklisted'   => __( 'Your Discord account has been blocked from accessing this store. Contact support if you believe this is an error.', 'nk-discord' ),
			);
			$error_key = sanitize_text_field( $_GET['discord_error'] );
			$message = isset( $messages[ $error_key ] ) ? $messages[ $error_key ] : __( 'An unknown error occurred.', 'nk-discord' );
			echo '<div class="nk-discord-error">' . esc_html( $message ) . '</div>';
		}
	}

	/**
	 * Redirect default WP registration to Discord login.
	 */
	public function redirect_registration() {
		$login_url = rest_url( 'nk-discord/v1/login' );
		wp_redirect( $login_url );
		exit;
	}

	/**
	 * Enqueue front-end styles.
	 */
	public function enqueue_styles() {
		wp_enqueue_style( 'nk-discord-auth', NK_DISCORD_URL . 'assets/css/discord-auth.css', array(), (string) filemtime( NK_DISCORD_DIR . 'assets/css/discord-auth.css' ) );
	}

	/**
	 * Enqueue login page styles.
	 */
	public function enqueue_login_styles() {
		wp_enqueue_style( 'nk-discord-login', NK_DISCORD_URL . 'assets/css/discord-login.css', array(), (string) filemtime( NK_DISCORD_DIR . 'assets/css/discord-login.css' ) );
	}
}
