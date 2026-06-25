<?php
/**
 * Discord ID blacklist — prevents specific Discord users from logging in,
 * adding items to cart, and completing checkout.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NK_Discord_Blacklist {

	private static $instance = null;

	/** WordPress option key that stores the blacklist entries. */
	const OPTION_KEY = 'nk_discord_blacklist';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		// ── Admin UI ─────────────────────────────────────────────────────
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// ── Frontend modal ──────────────────────────────────────────────
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_modal_assets' ) );

		// ── OAuth gate — block login before WP user is created / signed in
		add_filter( 'nk_discord_pre_login', array( $this, 'block_login' ), 10, 2 );

		// ── WooCommerce gates ────────────────────────────────────────────
		if ( class_exists( 'WooCommerce' ) ) {
			// Block add-to-cart
			add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'block_add_to_cart' ), 10, 2 );

			// Block classic checkout
			add_action( 'woocommerce_checkout_process', array( $this, 'block_checkout' ), 0 );

			// Block Store API / blocks checkout
			add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'block_store_api_checkout' ), 1, 2 );
		}
	}

	/* ──────────────────────────────────────────────────────────────────────
	 * Blacklist helpers
	 * ────────────────────────────────────────────────────────────────────*/

	/**
	 * Return the full blacklist as an associative array: discord_id => reason.
	 */
	public static function get_list() {
		$raw = get_option( self::OPTION_KEY, '' );
		return self::parse_list( $raw );
	}

	/**
	 * Check whether a Discord ID is blacklisted.
	 *
	 * @param  string $discord_id
	 * @return bool
	 */
	public static function is_blacklisted( $discord_id ) {
		if ( empty( $discord_id ) ) {
			return false;
		}
		$list = self::get_list();
		return isset( $list[ $discord_id ] );
	}

	/**
	 * Get the reason string for a blacklisted ID (empty string if none).
	 */
	public static function get_reason( $discord_id ) {
		$list = self::get_list();
		return isset( $list[ $discord_id ] ) ? $list[ $discord_id ] : '';
	}

	/**
	 * Check if the currently logged-in user is blacklisted.
	 */
	public static function is_current_user_blacklisted() {
		if ( ! is_user_logged_in() ) {
			return false;
		}
		$discord_id = get_user_meta( get_current_user_id(), NK_Discord_User::META_DISCORD_ID, true );
		return self::is_blacklisted( $discord_id );
	}

	/**
	 * Parse the raw textarea value into an array of discord_id => reason.
	 * Accepted formats per line:
	 *   123456789012345678
	 *   123456789012345678 | Chargeback in City X
	 */
	private static function parse_list( $raw ) {
		$entries = array();
		if ( empty( $raw ) ) {
			return $entries;
		}

		$lines = preg_split( '/\r?\n/', trim( $raw ) );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue; // skip blank lines and comments
			}
			if ( strpos( $line, '|' ) !== false ) {
				list( $id, $reason ) = array_map( 'trim', explode( '|', $line, 2 ) );
			} else {
				$id     = $line;
				$reason = '';
			}
			// Discord IDs are numeric snowflakes
			$id = preg_replace( '/[^0-9]/', '', $id );
			if ( '' !== $id ) {
				$entries[ $id ] = $reason;
			}
		}

		return $entries;
	}

	/* ──────────────────────────────────────────────────────────────────────
	 * Gate hooks
	 * ────────────────────────────────────────────────────────────────────*/

	/**
	 * Block blacklisted Discord users at the OAuth callback (before WP login).
	 * Expects the NK_Discord_Auth class to apply this filter.
	 *
	 * @param  bool|WP_Error $result       Pass-through (true = allow).
	 * @param  array         $discord_user Discord API user object.
	 * @return bool|WP_Error
	 */
	public function block_login( $result, $discord_user ) {
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$discord_id = isset( $discord_user['id'] ) ? $discord_user['id'] : '';
		if ( self::is_blacklisted( $discord_id ) ) {
			$reason = self::get_reason( $discord_id );
			$message = __( 'Your Discord account has been blocked from accessing this store.', 'nk-discord' );
			if ( $reason ) {
				$message .= ' ' . sprintf( __( 'Reason: %s', 'nk-discord' ), $reason );
			}
			return new WP_Error( 'nk_discord_blacklisted', $message );
		}

		return $result;
	}

	/**
	 * Block add-to-cart for blacklisted users.
	 */
	public function block_add_to_cart( $passed, $product_id ) {
		if ( self::is_current_user_blacklisted() ) {
			wc_add_notice( __( 'Your account has been blocked from making purchases. Please contact support if you believe this is an error.', 'nk-discord' ), 'error' );
			return false;
		}
		return $passed;
	}

	/**
	 * Block classic WooCommerce checkout for blacklisted users.
	 */
	public function block_checkout() {
		if ( self::is_current_user_blacklisted() ) {
			wc_add_notice( __( 'Your account has been blocked from making purchases. Please contact support if you believe this is an error.', 'nk-discord' ), 'error' );
		}
	}

	/**
	 * Block Store API (blocks) checkout for blacklisted users.
	 */
	public function block_store_api_checkout( $order, $request ) {
		if ( self::is_current_user_blacklisted() ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
				'nk_discord_blacklisted',
				__( 'Your account has been blocked from making purchases. Please contact support if you believe this is an error.', 'nk-discord' ),
				403
			);
		}
	}

	/* ──────────────────────────────────────────────────────────────────────
	 * Frontend modal assets
	 * ────────────────────────────────────────────────────────────────────*/

	/**
	 * Enqueue the blacklist modal JS + CSS on the frontend when the
	 * nk_blacklisted query parameter is present (set by the OAuth callback).
	 */
	public function enqueue_modal_assets() {
		if ( ! isset( $_GET['nk_blacklisted'] ) ) {
			return;
		}

		wp_enqueue_style(
			'nk-discord-blacklist-modal',
			NK_DISCORD_URL . 'assets/css/blacklist-modal.css',
			array(),
			(string) filemtime( NK_DISCORD_DIR . 'assets/css/blacklist-modal.css' )
		);

		wp_enqueue_script(
			'nk-discord-blacklist-modal',
			NK_DISCORD_URL . 'assets/js/blacklist-modal.js',
			array(),
			(string) filemtime( NK_DISCORD_DIR . 'assets/js/blacklist-modal.js' ),
			true
		);
	}

	/* ──────────────────────────────────────────────────────────────────────
	 * Admin settings
	 * ────────────────────────────────────────────────────────────────────*/

	/**
	 * Add a dedicated Blacklist sub-page under the existing Nine Kings Discord menu.
	 */
	public function add_admin_page() {
		add_options_page(
			__( 'Discord Blacklist', 'nk-discord' ),
			__( 'Discord Blacklist', 'nk-discord' ),
			'manage_options',
			'nk-discord-blacklist',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the option.
	 */
	public function register_settings() {
		register_setting( 'nk_discord_blacklist_group', self::OPTION_KEY, array(
			'type'              => 'string',
			'sanitize_callback' => array( $this, 'sanitize_blacklist' ),
			'default'           => '',
		) );
	}

	/**
	 * Sanitize: keep only lines that look like valid entries.
	 */
	public function sanitize_blacklist( $input ) {
		if ( ! is_string( $input ) ) {
			return '';
		}

		$lines  = preg_split( '/\r?\n/', $input );
		$clean  = array();

		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			// Allow comment lines starting with #
			if ( 0 === strpos( $line, '#' ) ) {
				$clean[] = $line;
				continue;
			}
			// Extract ID portion and validate it looks like a Discord snowflake
			if ( strpos( $line, '|' ) !== false ) {
				list( $id, $reason ) = array_map( 'trim', explode( '|', $line, 2 ) );
			} else {
				$id     = $line;
				$reason = '';
			}
			$id = preg_replace( '/[^0-9]/', '', $id );
			if ( strlen( $id ) >= 17 ) { // Discord snowflakes are 17-20 digits
				$clean[] = $reason ? $id . ' | ' . sanitize_text_field( $reason ) : $id;
			}
		}

		return implode( "\n", $clean );
	}

	/**
	 * Render the blacklist admin page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$list  = self::get_list();
		$count = count( $list );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Discord Blacklist', 'nk-discord' ); ?></h1>
			<p><?php esc_html_e( 'Block specific Discord users from logging in and making purchases. Blacklisted users will be denied access at login, add-to-cart, and checkout.', 'nk-discord' ); ?></p>

			<form method="post" action="options.php">
				<?php settings_fields( 'nk_discord_blacklist_group' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row">
							<label for="nk_discord_blacklist"><?php esc_html_e( 'Blacklisted Discord IDs', 'nk-discord' ); ?></label>
						</th>
						<td>
							<textarea
								id="nk_discord_blacklist"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>"
								rows="12"
								cols="70"
								class="large-text code"
								placeholder="123456789012345678 | Chargeback in City X&#10;987654321098765432"
							><?php echo esc_textarea( get_option( self::OPTION_KEY, '' ) ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'One Discord ID per line. Optionally add a reason after a pipe character:', 'nk-discord' ); ?>
								<code>123456789012345678 | Chargeback</code><br>
								<?php esc_html_e( 'Lines starting with # are treated as comments.', 'nk-discord' ); ?>
							</p>
							<?php if ( $count > 0 ) : ?>
								<p><strong><?php printf( esc_html__( '%d Discord ID(s) currently blacklisted.', 'nk-discord' ), $count ); ?></strong></p>
							<?php endif; ?>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save Blacklist', 'nk-discord' ) ); ?>
			</form>
		</div>
		<?php
	}
}
