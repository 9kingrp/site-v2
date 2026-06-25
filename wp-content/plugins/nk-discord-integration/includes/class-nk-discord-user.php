<?php
/**
 * Handles WordPress user creation and management for Discord-authenticated users.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NK_Discord_User {

	private static $instance = null;

	const META_DISCORD_ID     = '_nk_discord_id';
	const META_DISCORD_USER   = '_nk_discord_username';
	const META_DISCORD_AVATAR = '_nk_discord_avatar';
	const META_DISCORD_EMAIL  = '_nk_discord_email';
	const META_DISCORD_TOKEN  = '_nk_discord_access_token';
	const META_DISCORD_REFRESH = '_nk_discord_refresh_token';
	const META_DISCORD_EXPIRES = '_nk_discord_token_expires';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'show_user_profile', array( $this, 'display_discord_info' ) );
		add_action( 'edit_user_profile', array( $this, 'display_discord_info' ) );

		// Add Discord ID column to users list
		add_filter( 'manage_users_columns', array( $this, 'add_discord_column' ) );
		add_filter( 'manage_users_custom_column', array( $this, 'render_discord_column' ), 10, 3 );
	}

	/**
	 * Find existing WP user by Discord ID, or create a new one.
	 *
	 * @param array $discord_user Discord user data from API.
	 * @param array $token_data   OAuth2 token response.
	 * @return WP_User|WP_Error
	 */
	public static function find_or_create( $discord_user, $token_data ) {
		$discord_id  = $discord_user['id'];
		$username    = $discord_user['username'];
		$avatar      = isset( $discord_user['avatar'] ) ? $discord_user['avatar'] : '';
		$global_name = isset( $discord_user['global_name'] ) ? $discord_user['global_name'] : $username;

		// Look up existing user by Discord ID
		$existing_users = get_users( array(
			'meta_key'   => self::META_DISCORD_ID,
			'meta_value' => $discord_id,
			'number'     => 1,
		) );

		if ( ! empty( $existing_users ) ) {
			$wp_user = $existing_users[0];
			// Update stored data
			self::update_user_meta( $wp_user->ID, $discord_user, $token_data );
			return $wp_user;
		}

		// Create a new user — no email collected, use noreply placeholder
		$user_login = sanitize_user( 'discord_' . $discord_id, true );

		// Ensure unique username
		$base_login = $user_login;
		$counter = 1;
		while ( username_exists( $user_login ) ) {
			$user_login = $base_login . '_' . $counter;
			$counter++;
		}

		$user_data = array(
			'user_login'   => $user_login,
			'user_pass'    => wp_generate_password( 32, true, true ),
			'user_email'   => 'discord_' . $discord_id . '@noreply.9krp.com',
			'display_name' => $global_name,
			'nickname'     => $global_name,
			'role'         => 'customer', // WooCommerce customer role
		);

		$user_id = wp_insert_user( $user_data );

		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		self::update_user_meta( $user_id, $discord_user, $token_data );

		do_action( 'nk_discord_user_created', $user_id, $discord_user );

		return get_user_by( 'ID', $user_id );
	}

	/**
	 * Update user meta with Discord data.
	 */
	private static function update_user_meta( $user_id, $discord_user, $token_data ) {
		update_user_meta( $user_id, self::META_DISCORD_ID, $discord_user['id'] );
		update_user_meta( $user_id, self::META_DISCORD_USER, $discord_user['username'] );

		if ( isset( $discord_user['avatar'] ) ) {
			update_user_meta( $user_id, self::META_DISCORD_AVATAR, $discord_user['avatar'] );
		}

		if ( isset( $discord_user['email'] ) ) {
			update_user_meta( $user_id, self::META_DISCORD_EMAIL, $discord_user['email'] );
		}

		if ( isset( $token_data['access_token'] ) ) {
			update_user_meta( $user_id, self::META_DISCORD_TOKEN, $token_data['access_token'] );
		}

		if ( isset( $token_data['refresh_token'] ) ) {
			update_user_meta( $user_id, self::META_DISCORD_REFRESH, $token_data['refresh_token'] );
		}

		if ( isset( $token_data['expires_in'] ) ) {
			update_user_meta( $user_id, self::META_DISCORD_EXPIRES, time() + intval( $token_data['expires_in'] ) );
		}

		// Update display name with Discord global name
		if ( isset( $discord_user['global_name'] ) && $discord_user['global_name'] ) {
			wp_update_user( array(
				'ID'           => $user_id,
				'display_name' => $discord_user['global_name'],
			) );
		}
	}

	/**
	 * Get the Discord avatar URL for a user.
	 */
	public static function get_avatar_url( $user_id ) {
		$discord_id = get_user_meta( $user_id, self::META_DISCORD_ID, true );
		$avatar     = get_user_meta( $user_id, self::META_DISCORD_AVATAR, true );

		if ( $discord_id && $avatar ) {
			$ext = ( strpos( $avatar, 'a_' ) === 0 ) ? 'gif' : 'png';
			return "https://cdn.discordapp.com/avatars/{$discord_id}/{$avatar}.{$ext}?size=256";
		}

		return '';
	}

	/**
	 * Display Discord info on the user profile page in admin.
	 */
	public function display_discord_info( $user ) {
		$discord_id   = get_user_meta( $user->ID, self::META_DISCORD_ID, true );
		$discord_user = get_user_meta( $user->ID, self::META_DISCORD_USER, true );

		if ( ! $discord_id ) {
			return;
		}

		$avatar_url = self::get_avatar_url( $user->ID );
		?>
		<h3><?php esc_html_e( 'Discord Account', 'nk-discord' ); ?></h3>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Discord Username', 'nk-discord' ); ?></th>
				<td>
					<?php if ( $avatar_url ) : ?>
						<img src="<?php echo esc_url( $avatar_url ); ?>" width="32" height="32" style="border-radius:50%;vertical-align:middle;margin-right:8px;" />
					<?php endif; ?>
					<strong><?php echo esc_html( $discord_user ); ?></strong>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Discord ID', 'nk-discord' ); ?></th>
				<td><code><?php echo esc_html( $discord_id ); ?></code></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Add Discord ID column to the users list table.
	 */
	public function add_discord_column( $columns ) {
		$columns['discord_id'] = __( 'Discord', 'nk-discord' );
		return $columns;
	}

	/**
	 * Render the Discord column content.
	 */
	public function render_discord_column( $value, $column_name, $user_id ) {
		if ( 'discord_id' === $column_name ) {
			$discord_user = get_user_meta( $user_id, self::META_DISCORD_USER, true );
			$discord_id   = get_user_meta( $user_id, self::META_DISCORD_ID, true );
			if ( $discord_user ) {
				return esc_html( $discord_user ) . '<br><small>' . esc_html( $discord_id ) . '</small>';
			}
			return '—';
		}
		return $value;
	}
}
