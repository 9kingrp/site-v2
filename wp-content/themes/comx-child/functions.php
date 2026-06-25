<?php
/**
 * Child-Theme functions and definitions
 */

// Disable WooCommerce product image zoom
add_action( 'after_setup_theme', 'nk_disable_product_gallery_zoom', 100 );
function nk_disable_product_gallery_zoom() {
	remove_theme_support( 'wc-product-gallery-zoom' );
}

// Register a 16:9 thumbnail for product catalog images
add_action( 'after_setup_theme', 'nk_custom_image_sizes', 20 );
function nk_custom_image_sizes() {
	add_image_size( 'nk-product-16x9', 640, 360, true );
	add_image_size( 'nk-product-3x2', 640, 427, true );
}

// Helper: check if a product belongs to a category or any of its descendants
function nk_product_in_category_or_descendants( $product_id, $category_slug ) {
	$term = get_term_by( 'slug', $category_slug, 'product_cat' );
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

// Use the 16:9 size only for products in the Vehicles category (or its children)
add_filter( 'woocommerce_product_get_image', 'nk_wc_catalog_16x9_image', 10, 5 );
function nk_wc_catalog_16x9_image( $image, $product, $size, $attr, $placeholder ) {
	if ( is_product() ) {
		return $image;
	}
	if ( ! nk_product_in_category_or_descendants( $product->get_id(), 'vehicles' ) ) {
		return $image;
	}
	$thumb_id = $product->get_image_id();
	if ( $thumb_id ) {
		$image = wp_get_attachment_image( $thumb_id, 'nk-product-16x9', false, $attr );
	}
	return $image;
}

// Use the 3:2 size only for products in the Vouchers category (or its children)
add_filter( 'woocommerce_product_get_image', 'nk_wc_catalog_3x2_image', 10, 5 );
function nk_wc_catalog_3x2_image( $image, $product, $size, $attr, $placeholder ) {
	if ( is_product() ) {
		return $image;
	}
	if ( ! has_term( 'vouchers', 'product_cat', $product->get_id() ) ) {
		return $image;
	}
	$thumb_id = $product->get_image_id();
	if ( $thumb_id ) {
		$image = wp_get_attachment_image( $thumb_id, 'nk-product-3x2', false, $attr );
	}
	return $image;
}

// Add body class on Vehicles category archives (and children) for CSS targeting
add_filter( 'body_class', 'nk_vehicles_archive_body_class' );
function nk_vehicles_archive_body_class( $classes ) {
	if ( is_product_category() ) {
		$term = get_queried_object();
		if ( $term ) {
			$vehicles_term = get_term_by( 'slug', 'vehicles', 'product_cat' );
			if ( $vehicles_term && $term->term_id !== $vehicles_term->term_id && term_is_ancestor_of( $vehicles_term->term_id, $term->term_id, 'product_cat' ) ) {
				$classes[] = 'nk-vehicles-archive';
			}
		}
	}
	return $classes;
}

// Add body class on Vouchers category archives (and children) for CSS targeting
add_filter( 'body_class', 'nk_vouchers_archive_body_class' );
function nk_vouchers_archive_body_class( $classes ) {
	if ( is_product_category() ) {
		$term = get_queried_object();
		if ( $term ) {
			$vouchers_term = get_term_by( 'slug', 'vouchers', 'product_cat' );
			if ( $vouchers_term && ( $term->term_id === $vouchers_term->term_id || term_is_ancestor_of( $vouchers_term->term_id, $term->term_id, 'product_cat' ) ) ) {
				$classes[] = 'nk-vouchers-archive';
			}
		}
	}
	return $classes;
}

// Use the 3:2 size for all other (non-vehicle, non-voucher) products on listing pages
add_filter( 'woocommerce_product_get_image', 'nk_wc_catalog_default_3x2_image', 20, 5 );
function nk_wc_catalog_default_3x2_image( $image, $product, $size, $attr, $placeholder ) {
	if ( is_product() ) {
		return $image;
	}
	if ( nk_product_in_category_or_descendants( $product->get_id(), 'vehicles' ) ) {
		return $image;
	}
	if ( nk_product_in_category_or_descendants( $product->get_id(), 'vouchers' ) ) {
		return $image;
	}
	$thumb_id = $product->get_image_id();
	if ( $thumb_id ) {
		$image = wp_get_attachment_image( $thumb_id, 'nk-product-3x2', false, $attr );
	}
	return $image;
}

// Remove product meta (Categories, Product ID) from single product pages
add_action( 'init', 'nk_remove_product_meta' );
function nk_remove_product_meta() {
	remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40 );
}

// Display vehicle spawncode below payment options on single product pages
add_action( 'woocommerce_single_product_summary', 'nk_display_vehicle_spawncode', 60 );
function nk_display_vehicle_spawncode() {
	global $product;
	if ( ! $product || ! class_exists( 'NK_Discord_1of1' ) ) {
		return;
	}
	$spawncode = get_post_meta( $product->get_id(), NK_Discord_1of1::META_SPAWNCODE, true );
	if ( ! $spawncode ) {
		return;
	}
	echo '<div class="nk-vehicle-spawncode">';
	echo '<h3 class="nk-spawncode-label">Spawncode</h3>';
	echo '<p class="nk-spawncode-value">' . esc_html( $spawncode ) . '</p>';
	echo '</div>';
}

// Hide stock display on single product pages
add_filter( 'woocommerce_get_stock_html', 'nk_hide_single_product_stock', 10, 2 );
function nk_hide_single_product_stock( $html, $product ) {
	if ( is_product() ) {
		return '';
	}
	return $html;
}

// Add "Unavailable" overlay on product gallery when out of stock
add_action( 'woocommerce_before_single_product_summary', 'nk_out_of_stock_gallery_overlay', 5 );
function nk_out_of_stock_gallery_overlay() {
	global $product;
	if ( is_object( $product ) && ! $product->is_in_stock() ) {
		add_filter( 'woocommerce_single_product_image_gallery_classes', function( $classes ) {
			$classes[] = 'nk-out-of-stock-gallery';
			return $classes;
		});
	}
}

// Replace the default "Out of stock" label on archive pages with a diagonal overlay
add_action( 'comx_action_woocommerce_item_featured_link_start', 'nk_archive_out_of_stock_overlay', 1 );
function nk_archive_out_of_stock_overlay() {
	global $product;
	if ( is_object( $product ) && ! $product->is_in_stock() ) {
		remove_action( 'comx_action_woocommerce_item_featured_link_start', 'comx_woocommerce_add_out_of_stock_label' );
	}
}

// Add diagonal "UNAVAILABLE" overlay inside .post_featured on archive pages
add_action( 'comx_action_woocommerce_item_featured_end', 'nk_archive_unavailable_overlay_markup' );
function nk_archive_unavailable_overlay_markup() {
	global $product;
	if ( is_object( $product ) && ! $product->is_in_stock() ) {
		echo '<span class="nk-unavailable-overlay">UNAVAILABLE</span>';
	}
}

// Inject CSS for the diagonal "Unavailable" overlay (single + archive)
add_action( 'wp_head', 'nk_out_of_stock_overlay_css' );
function nk_out_of_stock_overlay_css() {
	?>
	<style>
		/* --- Single product page --- */
		.nk-out-of-stock-gallery .flex-viewport {
			position: relative;
		}
		.nk-out-of-stock-gallery .flex-viewport::after {
			content: "UNAVAILABLE";
			position: absolute;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			display: flex;
			align-items: center;
			justify-content: center;
			font-size: clamp(2rem, 5vw, 4rem);
			font-weight: 900;
			color: rgba(255, 0, 0, 0.85);
			letter-spacing: 0.15em;
			text-transform: uppercase;
			transform: rotate(-30deg);
			pointer-events: none;
			z-index: 10;
			text-shadow: 2px 2px 6px rgba(0, 0, 0, 0.7);
			-webkit-text-stroke: 1px rgba(0, 0, 0, 0.3);
		}

		/* --- Archive / category pages --- */
		.post_featured {
			position: relative;
			overflow: hidden;
		}
		.post_featured .outofstock_label {
			display: none;
		}
		.post_featured .nk-unavailable-overlay {
			position: absolute;
			top: 0;
			left: 0;
			width: 100%;
			height: 100%;
			display: flex;
			align-items: center;
			justify-content: center;
			font-size: clamp(1.5rem, 4vw, 3rem);
			font-weight: 900;
			color: rgba(255, 0, 0, 0.85);
			letter-spacing: 0.15em;
			text-transform: uppercase;
			transform: rotate(-30deg);
			pointer-events: none;
			z-index: 10;
			text-shadow: 2px 2px 6px rgba(0, 0, 0, 0.7);
			-webkit-text-stroke: 1px rgba(0, 0, 0, 0.3);
		}
	</style>
	<?php
}
?>