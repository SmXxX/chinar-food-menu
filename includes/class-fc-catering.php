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

	const GROUP = 'fc_catering_group';
	const PAGE  = 'fc-catering';

	public function init() {
		if ( ! FC_Settings::module( 'catering' ) ) {
			return;
		}

		// Admin: dedicated Catering page under the Food Customizer menu (only when
		// the module is on). Priority 22 so the top-level menu exists first.
		add_action( 'admin_menu', array( $this, 'add_menu' ), 22 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );

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
	/* Admin page (Food Customizer → Catering)                               */
	/* ===================================================================== */

	public function add_menu() {
		add_submenu_page(
			'food-customizer',
			__( 'Catering', 'food-customizer' ),
			__( 'Catering', 'food-customizer' ),
			'manage_woocommerce',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting( self::GROUP, 'fc_catering_payment_choice', array( 'type' => 'boolean', 'sanitize_callback' => array( $this, 'sanitize_bool' ), 'default' => 1 ) );
		register_setting( self::GROUP, 'fc_min_qty', array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 0 ) );
		register_setting( self::GROUP, 'fc_cat_min_rules', array( 'type' => 'array', 'sanitize_callback' => array( $this, 'sanitize_cat_rules' ), 'default' => array() ) );
	}

	public function sanitize_bool( $v ) {
		return empty( $v ) ? 0 : 1;
	}

	/** Per-category minimum rules: [ ['cat'=>slug,'qty'=>int], … ]. */
	public function sanitize_cat_rules( $in ) {
		$out = array();
		if ( ! is_array( $in ) ) {
			return $out;
		}
		foreach ( $in as $r ) {
			$cat = isset( $r['cat'] ) ? sanitize_title( $r['cat'] ) : '';
			$qty = isset( $r['qty'] ) ? absint( $r['qty'] ) : 0;
			if ( '' !== $cat && $qty > 0 ) {
				$out[] = array( 'cat' => $cat, 'qty' => $qty );
			}
		}
		return $out;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		$cats = is_array( $cats ) ? $cats : array();
		$build_opts = function ( $selected ) use ( $cats ) {
			$h = '<option value="">' . esc_html__( '— category —', 'food-customizer' ) . '</option>';
			foreach ( $cats as $t ) {
				$h .= '<option value="' . esc_attr( $t->slug ) . '"' . selected( $selected, $t->slug, false ) . '>' . esc_html( $t->name ) . '</option>';
			}
			return $h;
		};
		$row = function ( $i, $cat, $qty ) use ( $build_opts ) {
			return '<div class="fc-catmin-row" style="margin-bottom:6px;">'
				. '<select name="fc_cat_min_rules[' . esc_attr( $i ) . '][cat]">' . $build_opts( $cat ) . '</select> '
				. esc_html__( 'min', 'food-customizer' ) . ' '
				. '<input type="number" min="0" class="small-text" name="fc_cat_min_rules[' . esc_attr( $i ) . '][qty]" value="' . esc_attr( $qty ) . '" /> '
				. esc_html__( 'pcs each', 'food-customizer' )
				. ' <button type="button" class="button-link fc-catmin-del" title="' . esc_attr__( 'Remove', 'food-customizer' ) . '">&times;</button></div>';
		};
		$rules = self::cat_rules();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Catering', 'food-customizer' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php esc_html_e( 'Courier payment choice', 'food-customizer' ); ?></th>
						<td><label><input type="checkbox" name="fc_catering_payment_choice" value="1" <?php checked( (bool) get_option( 'fc_catering_payment_choice', 1 ), true ); ?> /> <?php esc_html_e( 'Under "Cash on delivery", let the customer choose cash or the courier\'s POS terminal', 'food-customizer' ); ?></label>
							<p class="description"><?php esc_html_e( 'Wording is editable in the Texts section (Pay choose / Pay cash / Pay pos).', 'food-customizer' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Minimum items per order', 'food-customizer' ); ?></th>
						<td><input type="number" min="0" name="fc_min_qty" value="<?php echo esc_attr( (int) get_option( 'fc_min_qty', 0 ) ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'The cart must contain at least this many items to check out. 0 = no minimum. Message: "Min qty msg" in Texts.', 'food-customizer' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Per-category minimums', 'food-customizer' ); ?></th>
						<td>
							<?php
							echo '<div id="fc-catmin-rows">';
							if ( empty( $rules ) ) {
								echo $row( 0, '', '' ); // phpcs:ignore WordPress.Security.EscapeOutput
							} else {
								foreach ( $rules as $i => $r ) {
									echo $row( (int) $i, $r['cat'], $r['qty'] ); // phpcs:ignore WordPress.Security.EscapeOutput
								}
							}
							echo '</div>';
							echo '<script type="text/template" id="fc-catmin-tpl">' . $row( '__i__', '', '' ) . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput
							?>
							<p><button type="button" class="button" id="fc-catmin-add">+ <?php esc_html_e( 'Add category', 'food-customizer' ); ?></button></p>
							<p class="description"><?php esc_html_e( 'Each product in the chosen category must be ordered in at least this quantity (e.g. bites ≥ 20). Add several categories, each with its own minimum. A per-product override is set in the product\'s Food Options box. The quantity selector on those products starts at the minimum. Message: "Cat min msg" in Texts.', 'food-customizer' ); ?></p>
							<script>
							( function ( $ ) {
								$( '#fc-catmin-add' ).on( 'click', function () {
									$( '#fc-catmin-rows' ).append( $( '#fc-catmin-tpl' ).html().replace( /__i__/g, Date.now() ) );
								} );
								$( document ).on( 'click', '.fc-catmin-del', function () {
									var $rows = $( '#fc-catmin-rows .fc-catmin-row' );
									if ( $rows.length <= 1 ) { $( this ).closest( '.fc-catmin-row' ).find( 'select,input' ).val( '' ); }
									else { $( this ).closest( '.fc-catmin-row' ).remove(); }
								} );
							} )( jQuery );
							</script>
						</td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
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

	/** All per-category minimum rules: [ ['cat'=>slug,'qty'=>int], … ]. */
	public static function cat_rules() {
		$out   = array();
		$rules = get_option( 'fc_cat_min_rules', array() );
		if ( is_array( $rules ) ) {
			foreach ( $rules as $r ) {
				$cat = isset( $r['cat'] ) ? (string) $r['cat'] : '';
				$qty = isset( $r['qty'] ) ? (int) $r['qty'] : 0;
				if ( '' !== $cat && $qty > 0 ) {
					$out[] = array( 'cat' => $cat, 'qty' => $qty );
				}
			}
		}
		// Backward-compat: migrate the old single-category setting if no rules exist yet.
		if ( empty( $out ) ) {
			$cat = (string) get_option( 'fc_cat_min_category', '' );
			$qty = (int) get_option( 'fc_cat_min_qty', 0 );
			if ( '' !== $cat && $qty > 0 ) {
				$out[] = array( 'cat' => $cat, 'qty' => $qty );
			}
		}
		return $out;
	}

	/**
	 * Effective minimum order quantity for a product (0 = none).
	 * A per-product override (Food Options box) wins; otherwise the highest
	 * applicable per-category minimum.
	 */
	public static function product_min_qty( $product_id ) {
		if ( ! FC_Settings::module( 'catering' ) ) {
			return 0;
		}
		$product_id = (int) $product_id;
		if ( ! $product_id ) {
			return 0;
		}
		$override = (int) get_post_meta( $product_id, '_food_min_qty', true );
		if ( $override > 0 ) {
			return $override;
		}
		$min = 0;
		foreach ( self::cat_rules() as $rule ) {
			if ( has_term( $rule['cat'], 'product_cat', $product_id ) ) {
				$min = max( $min, (int) $rule['qty'] );
			}
		}
		return $min;
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

		// #7 per-product minimums (per-category rules + per-product overrides)
		foreach ( WC()->cart->get_cart() as $item ) {
			$pid = ! empty( $item['product_id'] ) ? (int) $item['product_id'] : 0;
			if ( ! $pid ) {
				continue;
			}
			$min = self::product_min_qty( $pid );
			if ( $min > 0 && (int) $item['quantity'] < $min ) {
				$name  = isset( $item['data'] ) && is_object( $item['data'] ) ? $item['data']->get_name() : '';
				$out[] = str_replace(
					array( '%product%', '%min%' ),
					array( $name, $min ),
					FC_Settings::label( 'cat_min_msg' )
				);
			}
		}
		return array_values( array_unique( $out ) );
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
