<?php
/**
 * Custom single-product CONTENT for food products: a product.png-style layout
 * with the inline customizer. We only swap WooCommerce's content-single-product
 * template part, so the theme's own header/footer/wrappers (and dark styling)
 * render normally around it. Shares selection state with the modal (FCCore).
 *
 * @package FoodCustomizer
 */

defined( 'ABSPATH' ) || exit;

class FC_Single {

	public function init() {
		// Replace only the product content part — theme header/footer stay intact.
		add_filter( 'wc_get_template_part', array( $this, 'content_template' ), 99, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_filter( 'body_class', array( $this, 'body_class' ) );
	}

	/** Mark food-product pages so the CSS can apply the dark background. */
	public function body_class( $classes ) {
		if ( $this->is_food_product() ) {
			$classes[] = 'fc-food-single';
			if ( 'rtl' === FC_Settings::layout_direction() ) {
				$classes[] = 'fc-layout-rtl';
			}
		}
		return $classes;
	}

	/**
	 * Use the custom customizer layout ONLY for products that actually need it:
	 * variable products (variation pickers) or products with food options
	 * (removable ingredients / paid extras / variants). Plain simple products with
	 * nothing to customize fall through to the standard WooCommerce product page.
	 */
	private function is_food_product() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		if ( ! function_exists( 'is_product' ) || ! is_product() ) {
			return ( $cache = false );
		}
		$id      = get_queried_object_id();
		$product = $id ? wc_get_product( $id ) : null;
		if ( ! $product || ! $product->is_visible() ) {
			return ( $cache = false );
		}
		return ( $cache = ( $product->is_type( 'variable' ) || FC_Options::has_options( $product->get_id() ) ) );
	}

	/**
	 * Swap content-single-product.php for our customizer content.
	 *
	 * @param string $template Located template path.
	 * @param string $slug     e.g. 'content'.
	 * @param string $name     e.g. 'single-product'.
	 */
	public function content_template( $template, $slug, $name ) {
		if ( 'content' === $slug && 'single-product' === $name && $this->is_food_product() ) {
			$custom = FC_DIR . 'templates/content-single-product-fc.php';
			if ( file_exists( $custom ) ) {
				return $custom;
			}
		}
		return $template;
	}

	public function enqueue() {
		if ( ! $this->is_food_product() ) {
			return;
		}
		wp_enqueue_style( 'fc-product', FC_URL . 'assets/css/product.css', array( 'fc-modal' ), FC_VERSION );
		wp_enqueue_script( 'fc-product', FC_URL . 'assets/js/product.js', array( 'jquery', 'fc-core' ), FC_VERSION, true );
	}
}
