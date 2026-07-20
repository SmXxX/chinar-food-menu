<?php
/**
 * Front-end price display: append the informational BGN amount next to the
 * EUR price WooCommerce renders (shop loop + single product = "main product").
 * Respects the global dual-currency toggle. BGN is display-only.
 *
 * @package FoodCustomizer
 */

defined( 'ABSPATH' ) || exit;

class FC_Price_Display {

	public function init() {
		add_filter( 'woocommerce_get_price_html', array( $this, 'append_bgn' ), 20, 2 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue() {
		wp_enqueue_style( 'fc-frontend', FC_URL . 'assets/css/frontend.css', array(), FC_VERSION );
	}

	/**
	 * @param string     $html    The price HTML WooCommerce built (EUR).
	 * @param WC_Product $product The product.
	 * @return string
	 */
	public function append_bgn( $html, $product ) {
		if ( ! FC_Currency::dual_enabled() ) {
			return $html;
		}
		if ( ! $product instanceof WC_Product ) {
			return $html;
		}
		// Don't process in wp-admin screens (except AJAX front-end fragments).
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $html;
		}
		// Avoid double-appending if the filter runs on already-processed HTML.
		if ( false !== strpos( $html, 'fc-price-bgn' ) ) {
			return $html;
		}

		// Variable / range prices: show a BGN range when min != max.
		if ( $product->is_type( 'variable' ) ) {
			$min = (float) wc_get_price_to_display( $product, array( 'price' => $product->get_variation_price( 'min', true ) ) );
			$max = (float) wc_get_price_to_display( $product, array( 'price' => $product->get_variation_price( 'max', true ) ) );
			if ( $min <= 0 && $max <= 0 ) {
				return $html;
			}
			if ( abs( $min - $max ) < 0.0001 ) {
				return $html . ' ' . FC_Currency::bgn_only( $min );
			}
			$range = '<span class="fc-price-bgn">('
				. esc_html( number_format( FC_Currency::to_bgn( $min ), 2, ',', ' ' ) )
				. '&nbsp;&ndash;&nbsp;'
				. esc_html( number_format( FC_Currency::to_bgn( $max ), 2, ',', ' ' ) )
				. ' ' . esc_html( _x( 'лв', 'BGN currency suffix', 'food-customizer' ) ) . ')</span>';
			return $html . ' ' . $range;
		}

		$price = wc_get_price_to_display( $product );
		if ( '' === $price || null === $price || (float) $price <= 0 ) {
			return $html;
		}
		return $html . ' ' . FC_Currency::bgn_only( (float) $price );
	}
}
