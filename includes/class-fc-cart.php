<?php
/**
 * Cart & order integration for customized items: applies the server-computed
 * price, renders the chosen options in cart/checkout, and persists them to the
 * order line item (kitchen ticket + order history).
 *
 * @package FoodCustomizer
 */

defined( 'ABSPATH' ) || exit;

class FC_Cart {

	public function init() {
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_price' ), 20, 1 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_in_cart' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_to_order' ), 10, 4 );
	}

	/** Force each customized cart line to use the server-computed unit price. */
	public function apply_price( $cart ) {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( isset( $item['fc']['unit_price'] ) && is_object( $item['data'] ) ) {
				$item['data']->set_price( (float) $item['fc']['unit_price'] );
			}
		}
	}

	/** Show variant / removed / added lines under the product in cart & checkout. */
	public function display_in_cart( $item_data, $cart_item ) {
		if ( empty( $cart_item['fc'] ) ) {
			return $item_data;
		}
		$fc = $cart_item['fc'];

		if ( ! empty( $fc['variant']['name'] ) ) {
			$item_data[] = array(
				'key'     => __( 'Option', 'food-customizer' ),
				'value'   => wc_clean( $fc['variant']['name'] ),
				'display' => '',
			);
		}

		if ( ! empty( $fc['removed'] ) ) {
			$names = wp_list_pluck( $fc['removed'], 'name' );
			$item_data[] = array(
				'key'     => __( 'Without', 'food-customizer' ),
				'value'   => wc_clean( implode( ', ', $names ) ),
				'display' => '',
			);
		}

		if ( ! empty( $fc['addons'] ) ) {
			$parts = array();
			foreach ( $fc['addons'] as $a ) {
				$parts[] = sprintf( '%s%s', $a['name'], $a['qty'] > 1 ? ' ×' . (int) $a['qty'] : '' );
			}
			$item_data[] = array(
				'key'     => __( 'Extras', 'food-customizer' ),
				'value'   => wc_clean( implode( ', ', $parts ) ),
				'display' => '',
			);
		}

		return $item_data;
	}

	/** Persist the customization onto the order line item. */
	public function save_to_order( $item, $cart_item_key, $values, $order ) {
		if ( empty( $values['fc'] ) ) {
			return;
		}
		$fc = $values['fc'];

		if ( ! empty( $fc['variant']['name'] ) ) {
			$item->add_meta_data( __( 'Option', 'food-customizer' ), $fc['variant']['name'], true );
		}
		if ( ! empty( $fc['removed'] ) ) {
			$item->add_meta_data( __( 'Without', 'food-customizer' ), implode( ', ', wp_list_pluck( $fc['removed'], 'name' ) ), true );
		}
		if ( ! empty( $fc['addons'] ) ) {
			$parts = array();
			foreach ( $fc['addons'] as $a ) {
				$parts[] = sprintf( '%s%s (+%s)', $a['name'], $a['qty'] > 1 ? ' ×' . (int) $a['qty'] : '', wc_price( (float) $a['line'] ) );
			}
			$item->add_meta_data( __( 'Extras', 'food-customizer' ), wp_strip_all_tags( implode( ', ', $parts ) ), true );
		}
	}
}
