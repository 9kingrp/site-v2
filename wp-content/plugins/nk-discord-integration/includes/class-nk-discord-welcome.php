<?php
/**
 * Welcome channel handler.
 *
 * Receives "member joined" notifications from a companion Node listener bot
 * (see the listener/ folder), increments an internal counter, and posts a
 * welcome embed to a Discord webhook.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NK_Discord_Welcome {

	private static $instance = null;

	const OPTION_MEMBER_COUNT = 'nk_discord_welcome_member_count';
	const SECRET_HEADER       = 'X-NK-Secret';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register the REST endpoint the listener bot will call.
	 */
	public function register_routes() {
		register_rest_route( 'nk-discord/v1', '/member-joined', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_member_joined' ),
			'permission_callback' => array( $this, 'check_secret' ),
			'args'                => array(
				'discord_id' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'username'   => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'avatar_url' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'esc_url_raw',
				),
				'guild_name' => array(
					'required'          => false,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		) );

		// Lightweight test endpoint so admins can preview the message without a real join.
		register_rest_route( 'nk-discord/v1', '/welcome-test', array(
			'methods'             => 'POST',
			'callback'            => array( $this, 'handle_welcome_test' ),
			'permission_callback' => function() {
				return current_user_can( 'manage_options' );
			},
		) );
	}

	/**
	 * Verify the shared secret sent by the listener bot.
	 */
	public function check_secret( WP_REST_Request $request ) {
		$expected = NK_Discord_Settings::get( 'welcome_listener_secret' );
		if ( empty( $expected ) ) {
			return new WP_Error( 'nk_discord_no_secret', 'Listener secret not configured.', array( 'status' => 503 ) );
		}

		$provided = $request->get_header( 'x_nk_secret' );
		if ( ! is_string( $provided ) || ! hash_equals( $expected, $provided ) ) {
			return new WP_Error( 'nk_discord_bad_secret', 'Invalid or missing listener secret.', array( 'status' => 401 ) );
		}

		return true;
	}

	/**
	 * Handle a member-joined event from the listener bot.
	 */
	public function handle_member_joined( WP_REST_Request $request ) {
		$discord_id = $request->get_param( 'discord_id' );
		$username   = $request->get_param( 'username' );
		$avatar_url = $request->get_param( 'avatar_url' );
		$guild_name = $request->get_param( 'guild_name' );

		$member_number = $this->increment_member_count();

		$sent = $this->send_welcome_message( array(
			'discord_id'    => $discord_id,
			'username'      => $username,
			'avatar_url'    => $avatar_url,
			'guild_name'    => $guild_name,
			'member_number' => $member_number,
		) );

		if ( ! $sent ) {
			return new WP_REST_Response( array(
				'ok'            => false,
				'member_number' => $member_number,
				'error'         => 'Webhook send failed. Check the WordPress error log.',
			), 502 );
		}

		return new WP_REST_Response( array(
			'ok'            => true,
			'member_number' => $member_number,
		), 200 );
	}

	/**
	 * Admin preview — sends a sample welcome with the current logged-in user's data.
	 */
	public function handle_welcome_test( WP_REST_Request $request ) {
		$user = wp_get_current_user();

		$discord_id = get_user_meta( $user->ID, NK_Discord_User::META_DISCORD_ID, true );
		$username   = get_user_meta( $user->ID, NK_Discord_User::META_DISCORD_USER, true );
		$avatar_url = NK_Discord_User::get_avatar_url( $user->ID );

		// Use a fake "next" member number for preview (don't increment the real counter).
		$preview_number = (int) get_option( self::OPTION_MEMBER_COUNT, 0 ) + 1
			+ (int) NK_Discord_Settings::get( 'welcome_member_count_offset', 0 );

		$sent = $this->send_welcome_message( array(
			'discord_id'    => $discord_id ?: '0',
			'username'      => $username ?: $user->display_name,
			'avatar_url'    => $avatar_url ?: '',
			'guild_name'    => '',
			'member_number' => $preview_number,
			'is_preview'    => true,
		) );

		return new WP_REST_Response( array(
			'ok'             => (bool) $sent,
			'preview_number' => $preview_number,
		), $sent ? 200 : 502 );
	}

	/**
	 * Increment the internal join counter and return the user-facing member number.
	 */
	private function increment_member_count() {
		$count = (int) get_option( self::OPTION_MEMBER_COUNT, 0 ) + 1;
		update_option( self::OPTION_MEMBER_COUNT, $count, false );

		$offset = (int) NK_Discord_Settings::get( 'welcome_member_count_offset', 0 );
		return $count + $offset;
	}

	/**
	 * Build and send the welcome embed.
	 *
	 * @param array $data discord_id, username, avatar_url, guild_name, member_number, is_preview
	 * @return bool
	 */
	private function send_welcome_message( array $data ) {
		$webhook_url = NK_Discord_Settings::get( 'welcome_webhook_url' );
		if ( ! $webhook_url ) {
			error_log( '[NK Discord] Welcome webhook URL not configured.' );
			return false;
		}

		$server_name = $data['guild_name'] ?: get_bloginfo( 'name' );
		$mention     = $data['discord_id'] ? '<@' . $data['discord_id'] . '>' : '@' . $data['username'];

		$template = NK_Discord_Settings::get(
			'welcome_message_template',
			'Hello! {mention} Welcome to **Nine Kings x TMC V2!** You are member number **{member_number}**!'
		);

		$body = strtr( $template, array(
			'{username}'      => $data['username'],
			'{mention}'       => $mention,
			'{member_number}' => (string) $data['member_number'],
			'{server_name}'   => $server_name,
		) );

		$title = NK_Discord_Settings::get( 'welcome_embed_title', 'Welcome to Nine Kings x TMC V2' );
		if ( ! empty( $data['is_preview'] ) ) {
			$title .= ' (preview)';
		}

		$embed = array(
			'title'       => $title,
			'description' => $body,
			'color'       => 0xFEE75C, // Yellow accent — matches the screenshot
			'timestamp'   => gmdate( 'c' ),
			'footer'      => array(
				'text' => $server_name,
			),
		);

		if ( ! empty( $data['avatar_url'] ) ) {
			$embed['thumbnail'] = array( 'url' => $data['avatar_url'] );
		}

		return $this->send_webhook( $webhook_url, $embed );
	}

	/**
	 * POST a Discord embed to a webhook URL.
	 */
	private function send_webhook( $webhook_url, $embed ) {
		$payload = array(
			'embeds' => array( $embed ),
		);

		$response = wp_remote_post( $webhook_url, array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $payload ),
			'timeout' => 15,
		) );

		if ( is_wp_error( $response ) ) {
			error_log( '[NK Discord] Welcome webhook error: ' . $response->get_error_message() );
			return false;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			error_log( '[NK Discord] Welcome webhook HTTP ' . $code . ': ' . wp_remote_retrieve_body( $response ) );
			return false;
		}

		return true;
	}
}
