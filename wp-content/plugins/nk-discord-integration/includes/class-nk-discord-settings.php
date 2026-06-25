<?php
/**
 * Admin settings page for Discord integration.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NK_Discord_Settings {

	private static $instance = null;

	const OPTION_GROUP = 'nk_discord_settings';
	const OPTION_NAME  = 'nk_discord_options';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Get a specific option value.
	 */
	public static function get( $key, $default = '' ) {
		$options = get_option( self::OPTION_NAME, array() );
		return isset( $options[ $key ] ) ? $options[ $key ] : $default;
	}

	/**
	 * Add the settings page under the Settings menu.
	 */
	public function add_admin_menu() {
		add_options_page(
			__( 'Nine Kings Discord', 'nk-discord' ),
			__( 'Nine Kings Discord', 'nk-discord' ),
			'manage_options',
			'nk-discord-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register all settings fields.
	 */
	public function register_settings() {
		register_setting( self::OPTION_GROUP, self::OPTION_NAME, array( $this, 'sanitize_options' ) );

		// --- Discord OAuth2 Section ---
		add_settings_section(
			'nk_discord_oauth',
			__( 'Discord OAuth2 Settings', 'nk-discord' ),
			function() {
				echo '<p>' . esc_html__( 'Configure your Discord Application credentials. Get these from the Discord Developer Portal.', 'nk-discord' ) . '</p>';
			},
			'nk-discord-settings'
		);

		$this->add_field( 'client_id', __( 'Client ID', 'nk-discord' ), 'nk_discord_oauth' );
		$this->add_field( 'client_secret', __( 'Client Secret', 'nk-discord' ), 'nk_discord_oauth', 'password' );
		$this->add_field( 'bot_token', __( 'Bot Token', 'nk-discord' ), 'nk_discord_oauth', 'password' );
		$this->add_field( 'guild_id', __( 'Guild (Server) ID', 'nk-discord' ), 'nk_discord_oauth' );

		// --- Redirect URI display ---
		add_settings_field(
			'nk_discord_redirect_uri',
			__( 'Redirect URI', 'nk-discord' ),
			function() {
				$url = rest_url( 'nk-discord/v1/callback' );
				echo '<code>' . esc_html( $url ) . '</code>';
				echo '<p class="description">' . esc_html__( 'Add this URL as a redirect in your Discord Application OAuth2 settings.', 'nk-discord' ) . '</p>';
			},
			'nk-discord-settings',
			'nk_discord_oauth'
		);

		// --- Discord Notifications Section ---
		add_settings_section(
			'nk_discord_notifications',
			__( 'Discord Notifications', 'nk-discord' ),
			function() {
				echo '<p>' . esc_html__( 'Configure Discord webhook URLs for store notifications.', 'nk-discord' ) . '</p>';
			},
			'nk-discord-settings'
		);

		$this->add_field( 'webhook_purchases', __( 'Purchases Webhook URL', 'nk-discord' ), 'nk_discord_notifications', 'url' );
		$this->add_field( 'webhook_subscriptions', __( 'Subscriptions Webhook URL', 'nk-discord' ), 'nk_discord_notifications', 'url' );

		// --- Welcome Channel Section ---
		add_settings_section(
			'nk_discord_welcome',
			__( 'Welcome Channel', 'nk-discord' ),
			function() {
				echo '<p>' . esc_html__( 'Greet new members in your Discord server. Requires a small Node listener bot — see the listener/ folder in this plugin for setup.', 'nk-discord' ) . '</p>';
				$endpoint = rest_url( 'nk-discord/v1/member-joined' );
				echo '<p><strong>' . esc_html__( 'Listener endpoint:', 'nk-discord' ) . '</strong> <code>' . esc_html( $endpoint ) . '</code></p>';
			},
			'nk-discord-settings'
		);

		$this->add_field( 'welcome_webhook_url', __( 'Welcome Webhook URL', 'nk-discord' ), 'nk_discord_welcome', 'url',
			__( 'Discord webhook URL for the welcome channel. Create it via Channel Settings → Integrations → Webhooks.', 'nk-discord' )
		);

		// Member counter offset — lets you align with an existing population (e.g. you already have 1561 members).
		$this->add_field( 'welcome_member_count_offset', __( 'Member Count Offset', 'nk-discord' ), 'nk_discord_welcome', 'text',
			__( 'Added to the internal join counter when displaying the member number. If your server already has 1561 members, set this to 1561.', 'nk-discord' )
		);

		// Message template — uses {username}, {mention}, {member_number}, {server_name}.
		add_settings_field(
			'nk_discord_welcome_message_template',
			__( 'Message Template', 'nk-discord' ),
			function() {
				$value = self::get( 'welcome_message_template', "Hello! {mention} Welcome to **Nine Kings x TMC V2!** You are member number **{member_number}**!" );
				printf(
					'<textarea name="%s[welcome_message_template]" rows="4" cols="60" class="large-text code">%s</textarea>',
					esc_attr( self::OPTION_NAME ),
					esc_textarea( $value )
				);
				echo '<p class="description">' . esc_html__( 'Placeholders: {username}, {mention}, {member_number}, {server_name}.', 'nk-discord' ) . '</p>';
			},
			'nk-discord-settings',
			'nk_discord_welcome'
		);

		// Embed title (the bold headline at the top of the welcome card).
		$this->add_field( 'welcome_embed_title', __( 'Embed Title', 'nk-discord' ), 'nk_discord_welcome', 'text',
			__( 'Bold headline shown at the top of the welcome message. Defaults to "Welcome to Nine Kings x TMC V2".', 'nk-discord' )
		);

		// Listener shared secret — used to authenticate the Node bot calling the WP REST endpoint.
		add_settings_field(
			'nk_discord_welcome_listener_secret',
			__( 'Listener Shared Secret', 'nk-discord' ),
			function() {
				$value = self::get( 'welcome_listener_secret' );
				printf(
					'<input type="text" name="%s[welcome_listener_secret]" value="%s" class="regular-text" autocomplete="off" />',
					esc_attr( self::OPTION_NAME ),
					esc_attr( $value )
				);
				echo '<p class="description">' . esc_html__( 'Random secret the listener bot must send in the X-NK-Secret header. Generate a long random string and paste the same value into the bot config.', 'nk-discord' ) . '</p>';
			},
			'nk-discord-settings',
			'nk_discord_welcome'
		);

		// --- Role Mapping Section ---
		add_settings_section(
			'nk_discord_roles',
			__( 'Discord Role Mapping', 'nk-discord' ),
			function() {
				echo '<p>' . esc_html__( 'Map WooCommerce product purchases to Discord role IDs. Configure individual products in the product editor.', 'nk-discord' ) . '</p>';
			},
			'nk-discord-settings'
		);

		$this->add_field( 'customer_role_id', __( 'Customer Role ID', 'nk-discord' ), 'nk_discord_roles', 'text',
			__( 'Discord role to assign to any user who makes a purchase.', 'nk-discord' )
		);

		$this->add_field( 'staff_guild_id', __( 'Staff Discord Server ID', 'nk-discord' ), 'nk_discord_roles', 'text',
			__( 'Guild ID of the separate staff Discord server. Any member of this server receives a 15% discount on all purchases and one free vehicle per calendar month.', 'nk-discord' )
		);

		// --- 1of1 Vehicle / Ticket Approval Section ---
		add_settings_section(
			'nk_discord_tickets',
			__( '1of1 Vehicle — Ticket Approval', 'nk-discord' ),
			function() {
				echo '<p>' . esc_html__( 'Configure Discord ticket validation for 1of1 vehicle purchases.', 'nk-discord' ) . '</p>';
			},
			'nk-discord-settings'
		);

		$this->add_field( 'ticket_category_id', __( 'Ticket Category ID', 'nk-discord' ), 'nk_discord_tickets', 'text',
			__( 'Discord category channel ID where Ticket Tool creates ticket channels. Used to narrow the search.', 'nk-discord' )
		);
		$this->add_field( 'discord_ticket_url', __( 'Discord Ticket URL', 'nk-discord' ), 'nk_discord_tickets', 'url',
			__( 'URL to open a new ticket in Discord (e.g. a channel link or Ticket Tool panel link).', 'nk-discord' )
		);
	}

	/**
	 * Helper to add a settings field.
	 */
	private function add_field( $key, $label, $section, $type = 'text', $description = '' ) {
		add_settings_field(
			'nk_discord_' . $key,
			$label,
			function() use ( $key, $type, $description ) {
				$value = self::get( $key );
				$input_type = ( $type === 'password' ) ? 'password' : 'text';
				printf(
					'<input type="%s" name="%s[%s]" value="%s" class="regular-text" autocomplete="off" />',
					esc_attr( $input_type ),
					esc_attr( self::OPTION_NAME ),
					esc_attr( $key ),
					esc_attr( $value )
				);
				if ( $description ) {
					echo '<p class="description">' . esc_html( $description ) . '</p>';
				}
			},
			'nk-discord-settings',
			$section
		);
	}

	/**
	 * Sanitize options before saving.
	 */
	public function sanitize_options( $input ) {
		$sanitized = array();
		$text_fields = array( 'client_id', 'client_secret', 'bot_token', 'guild_id', 'customer_role_id', 'staff_guild_id', 'ticket_category_id', 'welcome_member_count_offset', 'welcome_embed_title', 'welcome_listener_secret' );
		$url_fields  = array( 'webhook_purchases', 'webhook_subscriptions', 'discord_ticket_url', 'welcome_webhook_url' );
		$textarea_fields = array( 'welcome_message_template' );

		foreach ( $text_fields as $field ) {
			$sanitized[ $field ] = isset( $input[ $field ] ) ? sanitize_text_field( $input[ $field ] ) : '';
		}

		foreach ( $url_fields as $field ) {
			$sanitized[ $field ] = isset( $input[ $field ] ) ? esc_url_raw( $input[ $field ] ) : '';
		}

		foreach ( $textarea_fields as $field ) {
			$sanitized[ $field ] = isset( $input[ $field ] ) ? sanitize_textarea_field( $input[ $field ] ) : '';
		}

		return $sanitized;
	}

	/**
	 * Render the settings page.
	 */
	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( 'nk-discord-settings' );
				submit_button();
				?>
			</form>
		</div>
		<?php
	}
}
