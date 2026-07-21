<?php
/**
 * Catering module: checkout options that only apply when the Catering module is
 * enabled (Food Customizer → Modules). Phase 1:
 *   - Payment sub-choice under "Cash on delivery" (cash vs courier POS terminal).
 *   - Minimum total cart quantity (e.g. at least 30 items).
 *   - Per-category minimum quantity (e.g. every "bites" product ≥ 20).
 *
 * Messages are rendered directly (not via woocommerce_check_cart_items, which
 * would hide the whole checkout form) and the order is blocked on submit — same
 * approach as FC_Min_Order.
 *
 * @package FoodCustomizer
 */

defined( 'ABSPATH' ) || exit;

class FC_Catering {

	public function init() {
		if ( ! FC_Settings::module( 'catering' ) ) {
			return;
		}

		// --- #1 Payment sub-choice (cash vs courier POS), shown under COD -------
		add_filter( 'woocommerce_gateway_description', array( $this, 'cod_description' ), 10, 2 );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_payment_choice' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_payment_choice' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'admin_show_payment_choice' ) );
		add_filter( 'woocommerce_email_order_meta_fields', array( $this, 'email_payment_choice' ), 10, 3 );

		// --- #5 Minimum total cart quantity ------------------------------------
		// --- #7 Per-category minimum quantity ----------------------------------
		add_action( 'woocommerce_before_cart', array( $this, 'qty_notices' ), 6 );
		add_action( 'woocommerce_checkout_before_customer_details', array( $this, 'qty_notices' ), 2 );
		add_action( 'woocommerce_checkout_process', array( $this, 'block_on_qty' ) );
		// Below a minimum, don't just block the final submit — stop the customer from
		// proceeding to checkout at all: hide the cart's "Proceed" button and redirect
		// the checkout URL back to the cart.
		add_action( 'woocommerce_before_cart', array( $this, 'maybe_hide_proceed' ), 6 );
		add_action( 'template_redirect', array( $this, 'gate_checkout' ) );
	}

	/* ===================================================================== */
	/* #1 Payment sub-choice                                                 */
	/* ===================================================================== */

	private static function pos_enabled() {
		return (bool) get_option( 'fc_catering_payment_choice', 1 );
	}

	/** The two options, keyed by value. */
	private static function pay_options() {
		return array(
			'cash' => FC_Settings::label( 'pay_cash' ),
			'pos'  => FC_Settings::label( 'pay_pos' ),
		);
	}

	/**
	 * Inject the cash/POS choice INTO the "Cash on delivery" description box, so it
	 * sits under "Наложен платеж" and shows only when that method is selected
	 * (WooCommerce reveals the payment box automatically — no custom JS needed).
	 * Built as a single line so wpautop (run on the description) can't mangle it.
	 */
	public function cod_description( $description, $gateway_id ) {
		if ( 'cod' !== $gateway_id || ! self::pos_enabled() ) {
			return $description;
		}
		$html = '<div class="fc-pay-choice"><span class="fc-pay-choice-label">' . esc_html( FC_Settings::label( 'pay_choose' ) ) . '</span>';
		foreach ( self::pay_options() as $val => $label ) {
			$html .= '<label class="fc-pay-opt"><input type="radio" name="fc_pay_type" value="' . esc_attr( $val ) . '"' . checked( 'cash', $val, false ) . '><span class="fc-pay-opt-text">' . esc_html( $label ) . '</span></label>';
		}
		$html .= '</div>';
		return $description . $html;
	}

	public function validate_payment_choice() {
		if ( ! self::pos_enabled() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$method = isset( $_POST['payment_method'] ) ? sanitize_text_field( wp_unslash( $_POST['payment_method'] ) ) : '';
		if ( 'cod' !== $method ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$val = isset( $_POST['fc_pay_type'] ) ? sanitize_text_field( wp_unslash( $_POST['fc_pay_type'] ) ) : '';
		if ( ! array_key_exists( $val, self::pay_options() ) ) {
			wc_add_notice( FC_Settings::label( 'pay_choose' ), 'error' );
		}
	}

	public function save_payment_choice( $order, $data ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$val = isset( $_POST['fc_pay_type'] ) ? sanitize_text_field( wp_unslash( $_POST['fc_pay_type'] ) ) : '';
		$opts = self::pay_options();
		if ( isset( $opts[ $val ] ) ) {
			$order->update_meta_data( '_fc_pay_type', $val );
			$order->update_meta_data( '_fc_pay_type_label', $opts[ $val ] );
		}
	}

	public function admin_show_payment_choice( $order ) {
		$label = $order->get_meta( '_fc_pay_type_label' );
		if ( $label ) {
			echo '<p><strong>' . esc_html( FC_Settings::label( 'pay_choose' ) ) . ':</strong> ' . esc_html( $label ) . '</p>';
		}
	}

	public function email_payment_choice( $fields, $sent_to_admin, $order ) {
		$label = is_object( $order ) ? $order->get_meta( '_fc_pay_type_label' ) : '';
		if ( $label ) {
			$fields['fc_pay_type'] = array(
				'label' => FC_Settings::label( 'pay_choose' ),
				'value' => $label,
			);
		}
		return $fields;
	}

	/* ===================================================================== */
	/* #5 / #7 Quantity minimums                                             */
	/* ===================================================================== */

	private static function min_total() {
		return (int) get_option( 'fc_min_qty', 0 );
	}

	private static function cat_rule() {
		$cat = (string) get_option( 'fc_cat_min_category', '' );
		$qty = (int) get_option( 'fc_cat_min_qty', 0 );
		return ( '' !== $cat && $qty > 0 ) ? array( 'cat' => $cat, 'qty' => $qty ) : null;
	}

	/** Collect any active quantity violations as ready-to-show messages. */
	private function violations() {
		$out = array();
		if ( ! WC()->cart ) {
			return $out;
		}

		// #5 total cart quantity
		$min_total = self::min_total();
		if ( $min_total > 0 ) {
			$count = WC()->cart->get_cart_contents_count();
			if ( $count < $min_total ) {
				$out[] = str_replace(
					array( '%min%', '%count%' ),
					array( $min_total, $count ),
					FC_Settings::label( 'min_qty_msg' )
				);
			}
		}

		// #7 per-category minimum (each product in the category)
		$rule = self::cat_rule();
		if ( $rule ) {
			foreach ( WC()->cart->get_cart() as $item ) {
				$pid = ! empty( $item['product_id'] ) ? (int) $item['product_id'] : 0;
				if ( $pid && has_term( $rule['cat'], 'product_cat', $pid ) && (int) $item['quantity'] < $rule['qty'] ) {
					$name  = isset( $item['data'] ) && is_object( $item['data'] ) ? $item['data']->get_name() : '';
					$out[] = str_replace(
						array( '%product%', '%min%' ),
						array( $name, $rule['qty'] ),
						FC_Settings::label( 'cat_min_msg' )
					);
				}
			}
		}
		return $out;
	}

	public function qty_notices() {
		foreach ( $this->violations() as $msg ) {
			echo '<div class="fc-min-order-notice" role="alert">' . esc_html( $msg ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	public function block_on_qty() {
		foreach ( $this->violations() as $msg ) {
			wc_add_notice( $msg, 'error' );
		}
	}

	/** Hide the cart's "Proceed to checkout" button while a minimum isn't met. */
	public function maybe_hide_proceed() {
		if ( $this->violations() ) {
			remove_action( 'woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout', 20 );
		}
	}

	/** Block direct checkout access while a minimum isn't met — send back to cart. */
	public function gate_checkout() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_wc_endpoint_url() ) {
			return;
		}
		$violations = $this->violations();
		if ( empty( $violations ) ) {
			return;
		}
		foreach ( $violations as $msg ) {
			if ( ! wc_has_notice( $msg, 'error' ) ) {
				wc_add_notice( $msg, 'error' );
			}
		}
		wp_safe_redirect( wc_get_cart_url() );
		exit;
	}
}
