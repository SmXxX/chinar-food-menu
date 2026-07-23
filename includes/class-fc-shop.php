<?php
/**
 * Front-end shop integration: the "Customize" button on product cards, asset
 * loading, and the AJAX endpoints powering the customization modal.
 *
 * @package FoodCustomizer
 */

defined( 'ABSPATH' ) || exit;

class FC_Shop {

	public function init() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );

		// Swap the loop add-to-cart button for a "Customize" button on products with options.
		add_filter( 'woocommerce_loop_add_to_cart_link', array( $this, 'customize_button' ), 20, 3 );

		// AJAX: fetch a product's options (logged-in + guests).
		add_action( 'wp_ajax_fc_get_options', array( $this, 'ajax_get_options' ) );
		add_action( 'wp_ajax_nopriv_fc_get_options', array( $this, 'ajax_get_options' ) );

		// AJAX: add a customized product to the cart.
		add_action( 'wp_ajax_fc_add_to_cart', array( $this, 'ajax_add_to_cart' ) );
		add_action( 'wp_ajax_nopriv_fc_add_to_cart', array( $this, 'ajax_add_to_cart' ) );

		// Floating mini-cart (count + total), updated live via cart fragments.
		add_action( 'wp_footer', array( $this, 'render_fly_cart' ) );
		add_filter( 'woocommerce_add_to_cart_fragments', array( $this, 'fly_cart_fragment' ) );
	}


	/** Floating cart HTML (used both in the footer and as a refreshable fragment). */
	private function fly_cart_html() {
		$count = ( WC()->cart ) ? WC()->cart->get_cart_contents_count() : 0;
		$total = ( WC()->cart ) ? WC()->cart->get_cart_subtotal() : '';
		$hidden = $count < 1 ? ' fc-fly-hidden' : '';
		$svg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg>';
		ob_start();
		?>
		<a href="<?php echo esc_url( wc_get_cart_url() ); ?>" class="fc-fly-cart<?php echo esc_attr( $hidden ); ?>" aria-label="<?php esc_attr_e( 'View cart', 'food-customizer' ); ?>">
			<span class="fc-fly-ico" aria-hidden="true"><?php echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput ?></span>
			<span class="fc-fly-count"><?php echo esc_html( $count ); ?></span>
			<?php if ( $total ) : ?><span class="fc-fly-total"><?php echo wp_kses_post( $total ); ?></span><?php endif; ?>
		</a>
		<?php
		return ob_get_clean();
	}

	public function render_fly_cart() {
		if ( is_admin() || ! function_exists( 'is_cart' ) || is_cart() || is_checkout() ) {
			return;
		}
		echo $this->fly_cart_html(); // phpcs:ignore WordPress.Security.EscapeOutput -- built/escaped above.
	}

	public function fly_cart_fragment( $fragments ) {
		$fragments['.fc-fly-cart'] = $this->fly_cart_html();
		return $fragments;
	}

	/**
	 * Only load the shop/modal assets where products or the shop actually appear —
	 * not on every page. Keeps generic pages (account, etc.) lean.
	 */
	private function needs_assets() {
		if ( is_admin() ) {
			return false;
		}
		if ( is_front_page() || is_cart() || is_checkout() ) {
			return true;
		}
		if ( function_exists( 'is_woocommerce' ) && is_woocommerce() ) {
			return true; // shop, product, product category/tag archives.
		}
		$post = get_post();
		return $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'fc_shop' );
	}

	public function enqueue() {
		if ( ! $this->needs_assets() ) {
			return;
		}
		wp_enqueue_style( 'fc-modal', FC_URL . 'assets/css/modal.css', array(), FC_VERSION );
		wp_enqueue_style( 'fc-shop', FC_URL . 'assets/css/shop.css', array(), FC_VERSION );
		// Shared core carries state/pricing/persistence + the localized FC_DATA.
		wp_enqueue_script( 'fc-core', FC_URL . 'assets/js/fc-core.js', array(), FC_VERSION, true );
		wp_enqueue_script( 'fc-modal', FC_URL . 'assets/js/modal.js', array( 'jquery', 'fc-core' ), FC_VERSION, true );
		wp_enqueue_script( 'fc-shop', FC_URL . 'assets/js/shop.js', array( 'jquery', 'fc-modal' ), FC_VERSION, true );
		wp_enqueue_script( 'fc-variations', FC_URL . 'assets/js/variations.js', array( 'jquery', 'fc-core' ), FC_VERSION, true );
		wp_localize_script( 'fc-core', 'FC_DATA', array(
			'ajax_url'   => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'fc_modal' ),
			'dual'       => FC_Currency::dual_enabled() ? 1 : 0,
			'rate'       => FC_EUR_BGN_RATE,
			'cart_url'   => wc_get_cart_url(),
			'currency'   => get_woocommerce_currency_symbol(),
			'i18n'       => array(
				'add_to_cart'    => FC_Settings::label( 'add_to_cart' ),
				'select_options' => FC_Settings::label( 'select_options' ),
				'total'          => FC_Settings::label( 'final_price' ),
				'allergens'      => FC_Settings::label( 'allergens' ),
				'ingredients'    => FC_Settings::label( 'ingredients' ),
				'choose'         => FC_Settings::label( 'choose' ),
				'without'        => FC_Settings::label( 'without' ),
				'remove'         => FC_Settings::label( 'without' ),
				'extras'         => FC_Settings::label( 'extras' ),
				'combine'        => FC_Settings::label( 'combine' ),
				'added'          => FC_Settings::label( 'added' ),
				'customize'      => __( 'Customize', 'food-customizer' ),
				'error'          => __( 'Something went wrong. Please try again.', 'food-customizer' ),
				'lv'             => _x( 'лв', 'BGN currency suffix', 'food-customizer' ),
			),
		) );
	}

	/**
	 * Replace the loop add-to-cart link with a "Customize" trigger for products
	 * that have food options. Products without options keep the normal button.
	 */
	public function customize_button( $html, $product, $args ) {
		if ( ! $product instanceof WC_Product ) {
			return $html;
		}
		if ( ! FC_Options::has_options( $product->get_id() ) ) {
			return $html;
		}
		// Link straight to the product page (no modal) — the customizer lives there.
		$classes = isset( $args['class'] ) ? $args['class'] : 'button';
		return sprintf(
			'<a href="%s" class="%s">%s</a>',
			esc_url( get_permalink( $product->get_id() ) ),
			esc_attr( $classes ),
			esc_html( FC_Settings::label( 'select_options' ) )
		);
	}

	/* --------------------------------------------------------------------- */
	/* AJAX                                                                  */
	/* --------------------------------------------------------------------- */

	public function ajax_get_options() {
		check_ajax_referer( 'fc_modal', 'nonce' );
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$product    = $product_id ? wc_get_product( $product_id ) : null;

		if ( ! $product || ! $product->is_purchasable() ) {
			wp_send_json_error( array( 'message' => __( 'Product not available.', 'food-customizer' ) ) );
		}

		wp_send_json_success( FC_Options::payload( $product_id ) );
	}

	public function ajax_add_to_cart() {
		check_ajax_referer( 'fc_modal', 'nonce' );

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$qty        = isset( $_POST['qty'] ) ? max( 1, absint( $_POST['qty'] ) ) : 1;
		$product    = $product_id ? wc_get_product( $product_id ) : null;

		if ( ! $product || ! $product->is_purchasable() ) {
			wp_send_json_error( array( 'message' => __( 'Product not available.', 'food-customizer' ) ) );
		}

		// Raw selection from the client (validated server-side in build_selection).
		$raw = array(
			'variant_id' => isset( $_POST['variant_id'] ) ? sanitize_text_field( wp_unslash( $_POST['variant_id'] ) ) : '',
			'removed'    => isset( $_POST['removed'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['removed'] ) ) : array(),
			'addons'     => array(),
			'wc'         => array(),
		);
		if ( isset( $_POST['addons'] ) && is_array( $_POST['addons'] ) ) {
			foreach ( wp_unslash( $_POST['addons'] ) as $id => $q ) {
				$raw['addons'][ sanitize_text_field( $id ) ] = absint( $q );
			}
		}
		if ( isset( $_POST['wc'] ) && is_array( $_POST['wc'] ) ) {
			foreach ( wp_unslash( $_POST['wc'] ) as $k => $v ) {
				$raw['wc'][ sanitize_text_field( $k ) ] = sanitize_text_field( $v );
			}
		}

		$selection = FC_Options::build_selection( $product_id, $raw );

		// Variable products: a variation must be fully chosen. Never silently add the
		// parent (or a default) when options are missing/ambiguous.
		if ( $product->is_type( 'variable' ) && empty( $selection['variation_id'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Please choose the options.', 'food-customizer' ) ) );
		}

		// Attach as cart item data; a unique hash forces distinct cart lines per config.
		$fc = array(
			'unit_price' => $selection['unit_price'],
			'variant'    => $selection['variant'],
			'removed'    => $selection['removed'],
			'addons'     => $selection['addons'],
		);
		$cart_item_data = array(
			'fc'      => $fc,
			'fc_hash' => md5( wp_json_encode( $fc ) . microtime() ),
		);

		if ( ! empty( $selection['variation_id'] ) ) {
			$added = WC()->cart->add_to_cart( $product_id, $qty, $selection['variation_id'], $selection['variation_attrs'], $cart_item_data );
		} else {
			$added = WC()->cart->add_to_cart( $product_id, $qty, 0, array(), $cart_item_data );
		}
		if ( ! $added ) {
			wp_send_json_error( array( 'message' => FC_Options::cart_error( __( 'Could not add to cart.', 'food-customizer' ) ) ) );
		}

		wp_send_json_success( array(
			'cart_count' => WC()->cart->get_cart_contents_count(),
			'cart_total' => WC()->cart->get_cart_total(),
			'fragments'  => $this->cart_fragments(),
		) );
	}

	/** Standard WooCommerce refreshable fragments so mini-carts update. */
	private function cart_fragments() {
		$fragments = apply_filters( 'woocommerce_add_to_cart_fragments', array() );
		return is_array( $fragments ) ? $fragments : array();
	}
}
