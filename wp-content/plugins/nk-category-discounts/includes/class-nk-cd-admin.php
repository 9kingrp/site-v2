<?php
/**
 * Admin settings screen for managing category discount rules.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class NK_CD_Admin {

	private static $instance = null;
	const PAGE_SLUG = 'nk-category-discounts';
	const NONCE     = 'nk_cd_save_rules';

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function hooks() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( NK_CD_FILE ), array( $this, 'action_links' ) );
	}

	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Category Discounts', 'nk-category-discounts' ),
			__( 'Category Discounts', 'nk-category-discounts' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function action_links( $links ) {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Settings', 'nk-category-discounts' ) . '</a>' );
		return $links;
	}

	public function assets( $hook ) {
		if ( 'woocommerce_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'nk-cd-admin', NK_CD_URL . 'assets/admin.css', array(), NK_CD_VERSION );
		wp_enqueue_script( 'nk-cd-admin', NK_CD_URL . 'assets/admin.js', array(), NK_CD_VERSION, true );
	}

	/**
	 * Handle form submission.
	 */
	public function maybe_save() {
		if ( ! isset( $_POST['nk_cd_submit'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'nk-category-discounts' ) );
		}
		check_admin_referer( self::NONCE );

		$raw     = isset( $_POST['rules'] ) && is_array( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : array();
		$cleaned = array();

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$categories = array();
			if ( ! empty( $row['categories'] ) && is_array( $row['categories'] ) ) {
				foreach ( $row['categories'] as $cid ) {
					$cid = (int) $cid;
					if ( $cid > 0 ) {
						$categories[] = $cid;
					}
				}
			}
			$categories = array_values( array_unique( $categories ) );

			$percentage = isset( $row['percentage'] ) ? (float) $row['percentage'] : 0.0;
			$percentage = max( 0.0, min( 100.0, $percentage ) );

			$start = $this->sanitize_dt( isset( $row['start'] ) ? $row['start'] : '' );
			$end   = $this->sanitize_dt( isset( $row['end'] ) ? $row['end'] : '' );

			$label = isset( $row['label'] ) ? sanitize_text_field( $row['label'] ) : '';

			// Skip entirely empty rows.
			if ( '' === $label && empty( $categories ) && $percentage <= 0 ) {
				continue;
			}

			$cleaned[] = array(
				'label'      => $label,
				'categories' => $categories,
				'percentage' => $percentage,
				'start'      => $start,
				'end'        => $end,
				'enabled'    => ! empty( $row['enabled'] ),
			);
		}

		NK_CD_Rules::save_rules( $cleaned );

		add_settings_error( 'nk_cd', 'saved', __( 'Discount rules saved.', 'nk-category-discounts' ), 'updated' );
		set_transient( 'nk_cd_notice', 1, 30 );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&updated=1' ) );
		exit;
	}

	/**
	 * Validate a datetime-local value into 'Y-m-d H:i' or '' if invalid/empty.
	 *
	 * @param string $value
	 * @return string
	 */
	private function sanitize_dt( $value ) {
		$value = sanitize_text_field( (string) $value );
		if ( '' === $value ) {
			return '';
		}
		$dt = NK_CD_Rules::parse_datetime( $value, wp_timezone() );
		if ( ! $dt ) {
			return '';
		}
		return $dt->format( 'Y-m-d H:i' );
	}

	/**
	 * Build the multi-select <option> markup for product categories (hierarchical).
	 *
	 * @param int[] $selected Selected term ids.
	 * @return string
	 */
	private function category_options_html( $selected = array() ) {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}

		// Group by parent for recursive rendering.
		$by_parent = array();
		foreach ( $terms as $term ) {
			$by_parent[ (int) $term->parent ][] = $term;
		}

		$selected = array_map( 'intval', (array) $selected );
		return $this->walk_category_options( $by_parent, 0, 0, $selected );
	}

	private function walk_category_options( $by_parent, $parent_id, $depth, $selected ) {
		if ( empty( $by_parent[ $parent_id ] ) ) {
			return '';
		}
		$out = '';
		foreach ( $by_parent[ $parent_id ] as $term ) {
			$prefix = str_repeat( '&nbsp;&nbsp;&nbsp;', $depth );
			$sel    = in_array( (int) $term->term_id, $selected, true ) ? ' selected="selected"' : '';
			$out   .= '<option value="' . esc_attr( $term->term_id ) . '"' . $sel . '>'
				. $prefix . esc_html( $term->name )
				. '</option>';
			$out   .= $this->walk_category_options( $by_parent, (int) $term->term_id, $depth + 1, $selected );
		}
		return $out;
	}

	/**
	 * Render one rule row.
	 *
	 * @param string $index Row index or '__INDEX__' for the JS template.
	 * @param array  $rule
	 * @param bool   $is_template
	 */
	private function render_row( $index, $rule, $is_template = false ) {
		$name        = 'rules[' . $index . ']';
		$label       = isset( $rule['label'] ) ? $rule['label'] : '';
		$percentage  = isset( $rule['percentage'] ) ? $rule['percentage'] : '';
		$start       = isset( $rule['start'] ) ? str_replace( ' ', 'T', $rule['start'] ) : '';
		$end         = isset( $rule['end'] ) ? str_replace( ' ', 'T', $rule['end'] ) : '';
		$enabled     = ! empty( $rule['enabled'] );
		$categories  = isset( $rule['categories'] ) ? (array) $rule['categories'] : array();
		$status_html = $this->status_badge( $rule, $is_template );

		$options = $this->category_options_html( $categories );
		?>
		<div class="nk-cd-rule<?php echo $is_template ? ' nk-cd-template' : ''; ?>"<?php echo $is_template ? ' style="display:none;"' : ''; ?>>
			<div class="nk-cd-rule-head">
				<label class="nk-cd-enabled">
					<input type="checkbox" name="<?php echo esc_attr( $name ); ?>[enabled]" value="1" <?php checked( $enabled ); ?> />
					<?php esc_html_e( 'Enabled', 'nk-category-discounts' ); ?>
				</label>
				<span class="nk-cd-status"><?php echo $status_html; // phpcs:ignore ?></span>
				<button type="button" class="button-link nk-cd-remove" aria-label="<?php esc_attr_e( 'Remove rule', 'nk-category-discounts' ); ?>">&times; <?php esc_html_e( 'Remove', 'nk-category-discounts' ); ?></button>
			</div>

			<div class="nk-cd-grid">
				<p class="nk-cd-field nk-cd-field-label">
					<label><?php esc_html_e( 'Name (optional)', 'nk-category-discounts' ); ?></label>
					<input type="text" name="<?php echo esc_attr( $name ); ?>[label]" value="<?php echo esc_attr( $label ); ?>" placeholder="<?php esc_attr_e( 'e.g. Summer Sale', 'nk-category-discounts' ); ?>" />
				</p>

				<p class="nk-cd-field nk-cd-field-pct">
					<label><?php esc_html_e( 'Discount %', 'nk-category-discounts' ); ?></label>
					<input type="number" min="0" max="100" step="0.01" name="<?php echo esc_attr( $name ); ?>[percentage]" value="<?php echo esc_attr( $percentage ); ?>" />
				</p>

				<p class="nk-cd-field nk-cd-field-start">
					<label><?php esc_html_e( 'Start (optional)', 'nk-category-discounts' ); ?></label>
					<input type="datetime-local" name="<?php echo esc_attr( $name ); ?>[start]" value="<?php echo esc_attr( $start ); ?>" />
				</p>

				<p class="nk-cd-field nk-cd-field-end">
					<label><?php esc_html_e( 'End (optional)', 'nk-category-discounts' ); ?></label>
					<input type="datetime-local" name="<?php echo esc_attr( $name ); ?>[end]" value="<?php echo esc_attr( $end ); ?>" />
				</p>

				<p class="nk-cd-field nk-cd-field-cats">
					<label><?php esc_html_e( 'Categories', 'nk-category-discounts' ); ?></label>
					<select name="<?php echo esc_attr( $name ); ?>[categories][]" multiple size="6" class="nk-cd-cats">
						<?php echo $options; // phpcs:ignore WordPress.Security.EscapeOutput ?>
					</select>
					<span class="description"><?php esc_html_e( 'Ctrl/Cmd-click to select multiple. Products in any selected category (and its sub-categories) get the discount.', 'nk-category-discounts' ); ?></span>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Small live/scheduled/expired badge.
	 */
	private function status_badge( $rule, $is_template ) {
		if ( $is_template ) {
			return '';
		}
		if ( empty( $rule['enabled'] ) ) {
			return '<span class="nk-cd-badge nk-cd-badge-off">' . esc_html__( 'Disabled', 'nk-category-discounts' ) . '</span>';
		}
		if ( NK_CD_Rules::is_rule_active( $rule ) ) {
			return '<span class="nk-cd-badge nk-cd-badge-live">' . esc_html__( 'Live now', 'nk-category-discounts' ) . '</span>';
		}
		// Enabled but not active: figure out why.
		$tz  = wp_timezone();
		$now = current_datetime();
		if ( ! empty( $rule['start'] ) ) {
			$start = NK_CD_Rules::parse_datetime( $rule['start'], $tz );
			if ( $start && $now < $start ) {
				return '<span class="nk-cd-badge nk-cd-badge-sched">' . esc_html__( 'Scheduled', 'nk-category-discounts' ) . '</span>';
			}
		}
		if ( ! empty( $rule['end'] ) ) {
			$end = NK_CD_Rules::parse_datetime( $rule['end'], $tz );
			if ( $end && $now > $end ) {
				return '<span class="nk-cd-badge nk-cd-badge-exp">' . esc_html__( 'Ended', 'nk-category-discounts' ) . '</span>';
			}
		}
		return '<span class="nk-cd-badge nk-cd-badge-off">' . esc_html__( 'Inactive', 'nk-category-discounts' ) . '</span>';
	}

	/**
	 * Render the whole page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$rules    = NK_CD_Rules::get_rules();
		$now_str  = current_datetime()->format( 'Y-m-d H:i' );
		$tz_name  = wp_timezone_string();
		?>
		<div class="wrap nk-cd-wrap">
			<h1><?php esc_html_e( 'Category Discounts', 'nk-category-discounts' ); ?></h1>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Discount rules saved.', 'nk-category-discounts' ); ?></p></div>
			<?php endif; ?>

			<p class="description">
				<?php
				printf(
					/* translators: 1: current site time, 2: timezone name */
					esc_html__( 'Discounts are applied from the product\'s regular price and override any existing sale price while active. Subscription products are excluded. Times use the site timezone: %1$s (current site time: %2$s).', 'nk-category-discounts' ),
					'<strong>' . esc_html( $tz_name ) . '</strong>',
					'<strong>' . esc_html( $now_str ) . '</strong>'
				);
				?>
			</p>

			<form method="post" action="">
				<?php wp_nonce_field( self::NONCE ); ?>

				<div id="nk-cd-rules">
					<?php
					if ( empty( $rules ) ) {
						// Render one blank row to start with.
						$this->render_row( '0', array( 'enabled' => true ) );
					} else {
						foreach ( $rules as $i => $rule ) {
							$this->render_row( (string) $i, $rule );
						}
					}
					?>
				</div>

				<?php
				// Hidden template used by JS to add new rows.
				$this->render_row( '__INDEX__', array( 'enabled' => true ), true );
				?>

				<p class="nk-cd-actions">
					<button type="button" class="button" id="nk-cd-add"><?php esc_html_e( '+ Add discount rule', 'nk-category-discounts' ); ?></button>
				</p>

				<p class="submit">
					<button type="submit" name="nk_cd_submit" value="1" class="button button-primary"><?php esc_html_e( 'Save changes', 'nk-category-discounts' ); ?></button>
				</p>
			</form>
		</div>
		<?php
	}
}
