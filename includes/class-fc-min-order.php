<?php
/**
 * Minimum order total.
 *
 * When the cart is below the admin-set minimum, a clear message is shown on the
 * cart and checkout and the order cannot be completed. The amount is set on the
 * Food Customizer settings page (EUR); the message is editable in the Texts
 * section and supports %min% (the minimum) and %remaining% (how much more).
 *
 * We deliberately do NOT hook woocommerce_check_cart_items: an error there makes
 * WooCommerce hide the whole checkout form and show a generic "there were
 * problems with your cart" message. Instead we render our own message and block
 * only on submit (woocommerce_checkout_process), so the form still shows.
 *
 * @package FoodCustomizer
 */

defined( 'ABSPATH' ) || exit;

class FC_Min_Order {

	const OPT      = 'fc_min_order';
	const OPT_GATE = 'fc_min_order_gate';

	public function init() {
		add_action( 'woocommerce_before_cart', array( $this, 'cart_message' ), 5 );
		add_action( 'woocommerce_checkout_before_customer_details', array( $this, 'checkout_message' ), 1 );
		// The order is always blocked at submission while under the minimum.
		add_action( 'woocommerce_checkout_process', array( $this, 'block_submit' ) );
		// Optional stricter gate: hide the cart's "Proceed to checkout" button AND
		// redirect the checkout page back to the cart (so the URL can't be used either).
		if ( self::gate_enabled() ) {
			add_action( 'woocommerce_before_cart', array( $this, 'maybe_hide_proceed' ) );
			add_action( 'template_redirect', array( $this, 'gate_checkout' ) );
		}
	}

	public static function minimum() {
		return (float) get_option( self::OPT, 0 );
	}

	public static function gate_enabled() {
		return (bool) get_option( self::OPT_GATE, 0 );
	}

	private function cart_total() {
		if ( ! WC()->cart ) {
			return 0.0;
		}
		return (float) WC()->cart->get_displayed_subtotal();
	}

	private function is_below() {
		$min = self::minimum();
		return $min > 0 && WC()->cart && $this->cart_total() < $min;
	}

	/** The resolved message with the amounts filled in. */
	private function message() {
		$min       = self::minimum();
		$remaining = max( 0, $min - $this->cart_total() );
		$msg = FC_Settings::label( 'min_order_msg' );
		return str_replace(
			array( '%min%', '%remaining%' ),
			array( FC_Currency::format_plain( $min ), FC_Currency::format_plain( $remaining ) ),
			$msg
		);
	}

	private function box() {
		return '<div class="fc-min-order-notice" role="alert">' . esc_html( $this->message() ) . '</div>';
	}

	public function cart_message() {
		if ( $this->is_below() ) {
			echo $this->box(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in box().
		}
	}

	public function checkout_message() {
		if ( $this->is_below() ) {
			echo $this->box(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in box().
		}
	}

	/** Prevent the order from being placed while under the minimum. */
	public function block_submit() {
		if ( $this->is_below() ) {
			wc_add_notice( $this->message(), 'error' );
		}
	}

	/** Stricter gate: remove the cart's "Proceed to checkout" button. */
	public function maybe_hide_proceed() {
		if ( $this->is_below() ) {
			remove_action( 'woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout', 20 );
		}
	}

	/**
	 * Stricter gate: if someone opens the checkout URL directly while under the
	 * minimum, send them back to the cart with the message. Order-received /
	 * order-pay endpoints are left alone so paid/pending orders still work.
	 */
	public function gate_checkout() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url() ) {
			return;
		}
		if ( ! $this->is_below() ) {
			return;
		}
		if ( ! wc_has_notice( $this->message(), 'error' ) ) {
			wc_add_notice( $this->message(), 'error' );
		}
		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}
}
