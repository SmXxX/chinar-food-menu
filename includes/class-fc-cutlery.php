<?php
/**
 * "Cutlery & napkins" toggle on the checkout page.
 *
 * Backed by a real (hidden) WooCommerce product so the owner can edit its name
 * and price in Products. On checkout it shows only as a checkbox + quantity
 * stepper; ticking it adds the product to the order as a normal line item, so it
 * appears on the order, emails, and the kitchen ticket. Unticked by default.
 *
 * @package FoodCustomizer
 */

defined( 'ABSPATH' ) || exit;

class FC_Cutlery {

	const OPT_ENABLED = 'fc_cutlery_enabled';
	const OPT_PRODUCT = 'fc_cutlery_product_id';
	const MAX_QTY     = 20;

	public function init() {
		if ( ! self::is_enabled() ) {
			return;
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'woocommerce_review_order_before_payment', array( $this, 'render_control' ) );
		add_action( 'wp_ajax_fc_set_cutlery', array( $this, 'ajax_set_cutlery' ) );
		add_action( 'wp_ajax_nopriv_fc_set_cutlery', array( $this, 'ajax_set_cutlery' ) );
	}

	public static function is_enabled() {
		return (bool) get_option( self::OPT_ENABLED, 0 );
	}

	/**
	 * Resolve the cutlery product, creating a hidden one the first time it's
	 * needed. The owner edits its name/price afterwards in Products.
	 */
	public static function get_product_id() {
		$id = (int) get_option( self::OPT_PRODUCT, 0 );
		if ( $id ) {
			$p = wc_get_product( $id );
			if ( $p && $p->exists() && 'trash' !== $p->get_status() ) {
				return $id;
			}
		}
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return 0;
		}
		$p = new WC_Product_Simple();
		$p->set_name( __( 'Cutlery & napkins', 'food-customizer' ) );
		$p->set_status( 'publish' );
		$p->set_catalog_visibility( 'hidden' ); // never shown in the shop grid.
		$p->set_regular_price( '0' );
		$p->set_price( '0' );
		$p->set_virtual( true );           // no shipping.
		$p->set_sold_individually( false );
		$new = (int) $p->save();
		if ( $new ) {
			update_option( self::OPT_PRODUCT, $new );
		}
		return $new;
	}

	public function enqueue() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		wp_enqueue_style( 'fc-checkout', FC_URL . 'assets/css/checkout.css', array(), FC_VERSION );
		wp_enqueue_script( 'fc-checkout', FC_URL . 'assets/js/checkout.js', array( 'jquery' ), FC_VERSION, true );
		wp_localize_script( 'fc-checkout', 'FC_CUTLERY', array(
			'ajax_url' => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'fc_cutlery' ),
			'max'      => self::MAX_QTY,
		) );
	}

	/** Cutlery quantity currently in the cart (0 = not added). */
	private static function current_qty() {
		$pid = (int) get_option( self::OPT_PRODUCT, 0 );
		if ( ! $pid || ! WC()->cart ) {
			return 0;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( (int) $item['product_id'] === $pid ) {
				return (int) $item['quantity'];
			}
		}
		return 0;
	}

	/** Find the cart item key for the cutlery product, or null. */
	private static function cart_key( $pid ) {
		if ( ! WC()->cart ) {
			return null;
		}
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( (int) $item['product_id'] === $pid ) {
				return $key;
			}
		}
		return null;
	}

	public function render_control() {
		$qty     = self::current_qty();
		$checked = $qty > 0;
		$display = $checked ? $qty : 1;
		?>
		<div class="fc-cutlery">
			<label class="fc-cutlery-row">
				<input type="checkbox" class="fc-cutlery-cb"<?php checked( $checked ); ?> />
				<span class="fc-cutlery-label"><?php echo esc_html( FC_Settings::label( 'cutlery' ) ); ?></span>
			</label>
			<span class="fc-cutlery-stepper"<?php echo $checked ? '' : ' hidden'; ?>>
				<button type="button" class="fc-cutlery-minus" aria-label="&minus;">&minus;</button>
				<span class="fc-cutlery-qty"><?php echo esc_html( $display ); ?></span>
				<button type="button" class="fc-cutlery-plus" aria-label="+">+</button>
			</span>
		</div>
		<?php
	}

	public function ajax_set_cutlery() {
		check_ajax_referer( 'fc_cutlery', 'nonce' );

		$checked = isset( $_POST['checked'] ) && '1' === (string) $_POST['checked'];
		$qty     = isset( $_POST['qty'] ) ? absint( $_POST['qty'] ) : 1;
		$qty     = max( 1, min( self::MAX_QTY, $qty ) );

		$pid = self::get_product_id();
		if ( ! $pid || ! WC()->cart ) {
			wp_send_json_error( array( 'message' => __( 'Something went wrong. Please try again.', 'food-customizer' ) ) );
		}

		$key = self::cart_key( $pid );
		if ( $checked ) {
			if ( $key ) {
				WC()->cart->set_quantity( $key, $qty, true );
			} else {
				WC()->cart->add_to_cart( $pid, $qty );
			}
		} elseif ( $key ) {
			WC()->cart->remove_cart_item( $key );
		}

		wp_send_json_success( array( 'qty' => self::current_qty() ) );
	}
}
