<?php
/**
 * Single-page shop: [fc_shop] shortcode rendering category pills + an AJAX
 * product grid. Product cards reuse the standard WooCommerce loop functions so
 * the dual-currency price and the "Customize" button integrate automatically.
 *
 * @package FoodCustomizer
 */

defined( 'ABSPATH' ) || exit;

class FC_Menu {

	public function init() {
		add_shortcode( 'fc_shop', array( $this, 'shortcode' ) );
		// When time-scheduled categories exist, don't let the page cache freeze the
		// shop at a moment in time — keep it fresh so windows open/close on schedule.
		add_action( 'template_redirect', array( $this, 'maybe_nocache_schedule' ) );

		// Outside its time window, a scheduled category's products can't be bought.
		add_filter( 'woocommerce_is_purchasable', array( $this, 'filter_purchasable' ), 10, 2 );
		add_filter( 'woocommerce_variation_is_purchasable', array( $this, 'filter_variation_purchasable' ), 10, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate_add_to_cart' ), 10, 3 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart_time' ) );

		// If the WooCommerce "Shop" page uses our [fc_shop] menu, hide the default
		// product-archive loop (result count, sorting, grid) so only the menu shows.
		add_action( 'pre_get_posts', array( $this, 'maybe_empty_shop_query' ) );
		add_action( 'wp', array( $this, 'maybe_strip_shop_loop' ) );

		add_action( 'wp_ajax_fc_load_products', array( $this, 'ajax_load_products' ) );
		add_action( 'wp_ajax_nopriv_fc_load_products', array( $this, 'ajax_load_products' ) );
		add_action( 'wp_ajax_fc_add_variation', array( $this, 'ajax_add_variation' ) );
		add_action( 'wp_ajax_nopriv_fc_add_variation', array( $this, 'ajax_add_variation' ) );
		add_action( 'wp_ajax_fc_get_variations', array( $this, 'ajax_get_variations' ) );
		add_action( 'wp_ajax_nopriv_fc_get_variations', array( $this, 'ajax_get_variations' ) );
		add_action( 'wp_ajax_fc_add_simple', array( $this, 'ajax_add_simple' ) );
		add_action( 'wp_ajax_nopriv_fc_add_simple', array( $this, 'ajax_add_simple' ) );
	}

	/** Does the WooCommerce Shop page contain our [fc_shop] menu shortcode? */
	private function shop_uses_fc_shop() {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return false;
		}
		$shop_id = wc_get_page_id( 'shop' );
		if ( $shop_id <= 0 ) {
			return false;
		}
		return has_shortcode( (string) get_post_field( 'post_content', $shop_id ), 'fc_shop' );
	}

	/** Empty the default shop archive query when the page shows our menu instead. */
	public function maybe_empty_shop_query( $query ) {
		if ( is_admin() || ! $query->is_main_query() || ! $query->is_post_type_archive( 'product' ) ) {
			return;
		}
		if ( $this->shop_uses_fc_shop() ) {
			$query->set( 'post__in', array( 0 ) );
		}
	}

	/** Remove the leftover archive chrome (count / sorting / "no products" text). */
	public function maybe_strip_shop_loop() {
		if ( is_admin() || ! function_exists( 'is_shop' ) || ! is_shop() || ! $this->shop_uses_fc_shop() ) {
			return;
		}
		remove_action( 'woocommerce_no_products_found', 'wc_no_products_found', 10 );
		remove_action( 'woocommerce_before_shop_loop', 'woocommerce_result_count', 20 );
		remove_action( 'woocommerce_before_shop_loop', 'woocommerce_catalog_ordering', 30 );
		remove_action( 'woocommerce_after_shop_loop', 'woocommerce_pagination', 10 );
	}

	/** Add a simple product to the cart (from a "combine with" card). */
	public function ajax_add_simple() {
		check_ajax_referer( 'fc_modal', 'nonce' );
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$qty        = isset( $_POST['qty'] ) ? max( 1, absint( $_POST['qty'] ) ) : 1;
		$product    = $product_id ? wc_get_product( $product_id ) : null;
		if ( ! $product || ! $product->is_purchasable() || $product->is_type( 'variable' ) ) {
			wp_send_json_error( array( 'message' => __( 'Product not available.', 'food-customizer' ) ) );
		}
		if ( ! WC()->cart->add_to_cart( $product_id, $qty ) ) {
			wp_send_json_error( array( 'message' => FC_Options::cart_error( __( 'Could not add to cart.', 'food-customizer' ) ) ) );
		}
		wp_send_json_success( array(
			'cart_count' => WC()->cart->get_cart_contents_count(),
			'fragments'  => apply_filters( 'woocommerce_add_to_cart_fragments', array() ),
		) );
	}

	/** Add a chosen product variation to the cart (from the inline card selector). */
	public function ajax_add_variation() {
		check_ajax_referer( 'fc_modal', 'nonce' );
		$product_id   = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$variation_id = isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : 0;
		$qty          = isset( $_POST['qty'] ) ? max( 1, absint( $_POST['qty'] ) ) : 1;

		$variation = $variation_id ? wc_get_product( $variation_id ) : null;
		if ( ! $variation || ! $variation->is_type( 'variation' ) || (int) $variation->get_parent_id() !== $product_id ) {
			wp_send_json_error( array( 'message' => __( 'Please choose the options.', 'food-customizer' ) ) );
		}

		// Variation attribute map, e.g. [ 'attribute_избери-тесто' => 'value' ].
		$var_attrs = array();
		foreach ( $variation->get_variation_attributes() as $k => $v ) {
			$var_attrs[ $k ] = $v;
		}

		$added = WC()->cart->add_to_cart( $product_id, $qty, $variation_id, $var_attrs );
		if ( ! $added ) {
			wp_send_json_error( array( 'message' => FC_Options::cart_error( __( 'Could not add to cart.', 'food-customizer' ) ) ) );
		}
		wp_send_json_success( array(
			'cart_count' => WC()->cart->get_cart_contents_count(),
			'fragments'  => apply_filters( 'woocommerce_add_to_cart_fragments', array() ),
		) );
	}

	/** [fc_shop cat="" columns="3" limit="100"] */
	public function shortcode( $atts ) {
		$atts = shortcode_atts( array(
			'cat'     => '',    // default category slug ('' = All)
			'columns' => 3,
			'limit'   => 100,
		), $atts, 'fc_shop' );

		$cats      = $this->get_categories();
		$show_tabs = (bool) get_option( 'fc_show_category_tabs', 1 );
		$show_all  = (bool) get_option( 'fc_show_all_tab', 1 );
		$slugs     = wp_list_pluck( $cats, 'slug' );

		// Initial category: shortcode att → admin default → 'all'.
		$current = $atts['cat'] ? sanitize_title( $atts['cat'] ) : (string) get_option( 'fc_default_category', 'all' );
		if ( '' === $current ) {
			$current = 'all';
		}
		// If "All" is hidden (or the chosen default is invalid), fall back sensibly.
		if ( 'all' === $current && ! $show_all && ! empty( $slugs ) ) {
			$current = $slugs[0];
		}
		if ( 'all' !== $current && ! in_array( $current, $slugs, true ) ) {
			$current = ( $show_all || empty( $slugs ) ) ? 'all' : $slugs[0];
		}

		ob_start();
		?>
		<div class="fc-shop" data-columns="<?php echo esc_attr( (int) $atts['columns'] ); ?>" data-limit="<?php echo esc_attr( (int) $atts['limit'] ); ?>">
			<?php if ( $show_tabs && ! empty( $cats ) ) : ?>
			<div class="fc-pills-wrap">
			<div class="fc-pills" role="tablist">
				<?php if ( $show_all ) : ?>
					<button type="button" class="fc-pill<?php echo 'all' === $current ? ' is-active' : ''; ?>" data-cat="all"><?php esc_html_e( 'All', 'food-customizer' ); ?></button>
				<?php endif; ?>
				<?php foreach ( $cats as $cat ) : ?>
					<button type="button" class="fc-pill<?php echo $current === $cat->slug ? ' is-active' : ''; ?>" data-cat="<?php echo esc_attr( $cat->slug ); ?>"><?php echo esc_html( $cat->name ); ?></button>
				<?php endforeach; ?>
			</div>
			</div>
			<?php endif; ?>
			<div class="fc-grid" style="--fc-cols:<?php echo esc_attr( (int) $atts['columns'] ); ?>">
				<?php echo $this->render_products( $current, (int) $atts['limit'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	public function ajax_load_products() {
		check_ajax_referer( 'fc_modal', 'nonce' );
		$cat   = isset( $_POST['cat'] ) ? sanitize_title( wp_unslash( $_POST['cat'] ) ) : 'all';
		$limit = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 100;
		wp_send_json_success( array( 'html' => $this->render_products( $cat, $limit ) ) );
	}

	/* --------------------------------------------------------------------- */

	/**
	 * Category slugs hidden right now — manual hides (uncategorized hidden by
	 * default) plus any on a daily schedule that are currently outside their window.
	 */
	public static function hidden_categories() {
		$h = get_option( 'fc_hidden_categories', array( 'uncategorized' ) );
		$h = is_array( $h ) ? array_map( 'strval', $h ) : array();
		return array_values( array_unique( array_merge( $h, self::scheduled_hidden_slugs() ) ) );
	}

	/** Slugs whose daily availability window doesn't include the current time. */
	public static function scheduled_hidden_slugs() {
		$sched = get_option( 'fc_category_schedules', array() );
		if ( ! is_array( $sched ) || empty( $sched ) ) {
			return array();
		}
		$now = self::to_minutes( current_time( 'H:i' ) ); // site timezone.
		$out = array();
		foreach ( $sched as $slug => $win ) {
			$from = isset( $win['from'] ) ? (string) $win['from'] : '';
			$to   = isset( $win['to'] ) ? (string) $win['to'] : '';
			if ( '' === $from || '' === $to ) {
				continue; // no complete window = always available.
			}
			$f = self::to_minutes( $from );
			$t = self::to_minutes( $to );
			$visible = ( $f <= $t ) ? ( $now >= $f && $now <= $t ) : ( $now >= $f || $now <= $t );
			if ( ! $visible ) {
				$out[] = (string) $slug;
			}
		}
		return $out;
	}

	/** Keep the shop page uncached while category schedules are configured. */
	public function maybe_nocache_schedule() {
		if ( is_admin() ) {
			return;
		}
		$sched = get_option( 'fc_category_schedules', array() );
		if ( empty( $sched ) || ! is_array( $sched ) ) {
			return;
		}
		$post = get_post();
		$has_shop = is_front_page() || ( $post instanceof WP_Post && has_shortcode( (string) $post->post_content, 'fc_shop' ) );
		if ( ! $has_shop ) {
			return;
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
		}
	}

	/** "HH:MM" → minutes since midnight. */
	private static function to_minutes( $hhmm ) {
		$parts = explode( ':', (string) $hhmm );
		return ( isset( $parts[0] ) ? (int) $parts[0] * 60 : 0 ) + ( isset( $parts[1] ) ? (int) $parts[1] : 0 );
	}

	/**
	 * Is this product in a scheduled category that is closed right now, with no
	 * currently-open category to fall back on? If so it can't be purchased.
	 */
	public static function is_product_time_blocked( $product_id ) {
		$off = self::scheduled_hidden_slugs();
		if ( empty( $off ) ) {
			return false; // nothing scheduled off → zero overhead.
		}
		$slugs = wp_get_post_terms( (int) $product_id, 'product_cat', array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $slugs ) || empty( $slugs ) ) {
			return false;
		}
		if ( ! array_intersect( $slugs, $off ) ) {
			return false; // not in any closed category.
		}
		$open = array_diff( $slugs, self::hidden_categories() );
		return empty( $open ); // blocked only if it has no currently-open category.
	}

	/** The from/to window of the closed category the product belongs to. */
	private static function product_block_window( $product_id ) {
		$sched = get_option( 'fc_category_schedules', array() );
		$off   = self::scheduled_hidden_slugs();
		$slugs = wp_get_post_terms( (int) $product_id, 'product_cat', array( 'fields' => 'slugs' ) );
		if ( is_wp_error( $slugs ) ) {
			return null;
		}
		foreach ( $slugs as $s ) {
			if ( in_array( $s, $off, true ) && isset( $sched[ $s ]['from'], $sched[ $s ]['to'] ) ) {
				return array( 'from' => $sched[ $s ]['from'], 'to' => $sched[ $s ]['to'] );
			}
		}
		return null;
	}

	/** Customer-facing "available from X to Y" message for a blocked product. */
	public static function unavailable_message( $product_id ) {
		$msg = FC_Settings::label( 'unavailable_time' );
		$win = self::product_block_window( $product_id );
		if ( $win ) {
			$msg = str_replace( array( '%from%', '%to%' ), array( $win['from'], $win['to'] ), $msg );
		}
		return $msg;
	}

	public function filter_purchasable( $purchasable, $product ) {
		if ( $purchasable && $product instanceof WC_Product && self::is_product_time_blocked( $product->get_id() ) ) {
			return false;
		}
		return $purchasable;
	}

	public function filter_variation_purchasable( $purchasable, $variation ) {
		if ( $purchasable && $variation instanceof WC_Product && self::is_product_time_blocked( $variation->get_parent_id() ) ) {
			return false;
		}
		return $purchasable;
	}

	public function validate_add_to_cart( $passed, $product_id, $qty ) {
		if ( self::is_product_time_blocked( $product_id ) ) {
			wc_add_notice( self::unavailable_message( $product_id ), 'error' );
			return false;
		}
		return $passed;
	}

	public function check_cart_time() {
		if ( ( is_admin() && ! wp_doing_ajax() ) || ! WC()->cart ) {
			return;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( self::is_product_time_blocked( $item['product_id'] ) ) {
				$msg = self::unavailable_message( $item['product_id'] );
				if ( ! ( function_exists( 'wc_has_notice' ) && wc_has_notice( $msg, 'error' ) ) ) {
					wc_add_notice( $msg, 'error' );
				}
			}
		}
	}

	private function get_categories() {
		$terms = get_terms( array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'orderby'    => 'menu_order',
			'order'      => 'ASC',
		) );
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		$hidden = self::hidden_categories();
		if ( ! empty( $hidden ) ) {
			$terms = array_values( array_filter( $terms, function ( $t ) use ( $hidden ) {
				return ! in_array( $t->slug, $hidden, true );
			} ) );
		}
		// Custom tab order (admin drag-sort): listed slugs first, any others after.
		$order = (array) get_option( 'fc_category_order', array() );
		if ( ! empty( $order ) ) {
			$pos = array_flip( array_values( array_map( 'strval', $order ) ) );
			usort( $terms, function ( $a, $b ) use ( $pos ) {
				$ai = isset( $pos[ $a->slug ] ) ? $pos[ $a->slug ] : PHP_INT_MAX;
				$bi = isset( $pos[ $b->slug ] ) ? $pos[ $b->slug ] : PHP_INT_MAX;
				return $ai <=> $bi;
			} );
		}
		return $terms;
	}

	private function render_products( $cat_slug, $limit ) {
		$args = array(
			'status'     => 'publish',
			'limit'      => $limit > 0 ? $limit : 100,
			'orderby'    => array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
			'visibility' => 'visible',
		);
		if ( $cat_slug && 'all' !== $cat_slug ) {
			$args['category'] = array( $cat_slug );
		} else {
			// "All" → only products in visible (non-hidden) categories. This also
			// keeps uncategorised (or any hidden category's) products out.
			$visible = wp_list_pluck( $this->get_categories(), 'slug' );
			if ( ! empty( $visible ) ) {
				$args['category'] = $visible;
			}
		}
		$products = wc_get_products( $args );

		if ( empty( $products ) ) {
			return '<p class="fc-empty">' . esc_html__( 'No items in this category yet.', 'food-customizer' ) . '</p>';
		}

		$out = '';
		foreach ( $products as $product ) {
			$out .= $this->render_card( $product );
		}
		return $out;
	}

	private function render_card( $product ) {
		if ( ! $product instanceof WC_Product || ! $product->is_visible() ) {
			return '';
		}
		// Set the loop global so WC template functions target this product.
		$GLOBALS['product'] = $product;
		$post_object        = get_post( $product->get_id() );
		setup_postdata( $GLOBALS['post'] = $post_object ); // phpcs:ignore

		$img   = $product->get_image( 'woocommerce_thumbnail', array( 'class' => 'fc-card-img' ) );
		$link  = get_permalink( $product->get_id() );
		$title = $product->get_name();
		$desc  = wp_trim_words( wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ), 16 );
		// Products imported with "Грамаж: 400гр." in the description — drop the label, keep the value.
		$desc  = trim( preg_replace( '/Грамаж\s*:\s*/u', '', $desc ) );

		// Allergens are intentionally NOT shown on the menu cards — only on the
		// product page. Keep the card clean.
		$allergen = '';

		// Weight/size line — rendered inside the footer so it always sits just above
		// the price, regardless of how many lines the product name takes.
		$weight_html = $desc ? '<div class="fc-card-weight">' . esc_html( $desc ) . '</div>' : '';

		$pid = $product->get_id();
		if ( $product->is_type( 'variable' ) ) {
			// Inline variation selector: pick options in the card, see the exact price.
			$foot = $this->render_variable_foot( $product, $weight_html );
		} elseif ( ! FC_Options::has_options( $pid ) && $product->is_purchasable() && $product->is_in_stock() ) {
			// Plain simple product → quantity stepper + add button (add several easily).
			// Rental/limited-stock items (Manage stock ON): cap the stepper at the
			// remaining quantity and show a "само N left" note.
			$stock = ( $product->managing_stock() && null !== $product->get_stock_quantity() ) ? max( 0, (int) $product->get_stock_quantity() ) : 0;
			$stock_note = $stock > 0 ? '<div class="fc-card-stock">' . sprintf( esc_html__( 'only %d left', 'food-customizer' ), $stock ) . '</div>' : '';
			$foot = '<div class="fc-card-foot">'
				. '<div class="fc-card-priceblock">' . $weight_html . '<span class="fc-card-price">' . $product->get_price_html() . '</span>' . $stock_note . '</div>'
				. '<div class="fc-card-buy" data-stock="' . esc_attr( $stock ) . '">'
				. '<span class="fc-card-qty"><button type="button" class="fc-cq-minus" aria-label="&minus;">&minus;</button><span class="fc-cq-val">1</span><button type="button" class="fc-cq-plus" aria-label="+">+</button></span>'
				. '<button type="button" class="fc-card-add button" data-product-id="' . esc_attr( $pid ) . '">' . esc_html( FC_Settings::label( 'add_to_cart' ) ) . '</button>'
				. '</div></div>';
		} else {
			// Products with options link to the page; out-of-stock keep WC's default.
			ob_start();
			woocommerce_template_loop_add_to_cart(); // fires our loop filter → link or default add.
			$button = ob_get_clean();
			$foot   = '<div class="fc-card-foot">'
				. '<div class="fc-card-priceblock">' . $weight_html . '<span class="fc-card-price">' . $product->get_price_html() . '</span></div>'
				. $button . '</div>';
		}

		wp_reset_postdata();

		return sprintf(
			'<div class="fc-card">'
			. '<a href="%1$s" class="fc-card-imgwrap" tabindex="-1">%2$s</a>'
			. '<div class="fc-card-body">'
			. '<h3 class="fc-card-title"><a href="%1$s">%3$s</a></h3>'
			. '%4$s'
			. '%5$s'
			. '</div></div>',
			esc_url( $link ),
			$img,
			esc_html( $title ),
			$allergen,
			$foot
		);
	}

	/**
	 * Footer for a variable product: a "from" price and a "Select options" button
	 * that opens the variation popup (variations.js → fc_get_variations).
	 */
	private function render_variable_foot( $product, $weight_html = '' ) {
		// get_variation_prices() is cached by WooCommerce (transient) and far cheaper
		// than get_available_variations(), which builds a full data array (images,
		// attributes, availability) for every variation on every page load.
		$price_map = $product->get_variation_prices( true );
		$prices    = array();
		if ( ! empty( $price_map['price'] ) ) {
			foreach ( $price_map['price'] as $p ) {
				if ( '' !== $p && (float) $p > 0 ) {
					$prices[] = (float) $p;
				}
			}
		}
		if ( empty( $prices ) ) {
			return '<div class="fc-card-foot">'
				. '<div class="fc-card-priceblock">' . $weight_html . '<span class="fc-card-price">' . $product->get_price_html() . '</span></div>'
				. '</div>';
		}
		$min = min( $prices );

		return '<div class="fc-card-foot fc-card-foot--var">'
			. '<div class="fc-card-priceblock">' . $weight_html
			. '<span class="fc-card-price"><span class="fc-from">' . esc_html( FC_Settings::label( 'from' ) ) . '</span> ' . wp_kses_post( FC_Currency::format( $min ) ) . '</span></div>'
			. '<a href="' . esc_url( get_permalink( $product->get_id() ) ) . '" class="button fc-var-link">' . esc_html( FC_Settings::label( 'select_options' ) ) . '</a>'
			. '</div>';
	}

	/** AJAX: full variation data for the "Select options" popup. */
	public function ajax_get_variations() {
		check_ajax_referer( 'fc_modal', 'nonce' );
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$product    = $product_id ? wc_get_product( $product_id ) : null;
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			wp_send_json_error( array( 'message' => __( 'Product not available.', 'food-customizer' ) ) );
		}

		$variations = array();
		foreach ( $product->get_available_variations() as $vd ) {
			if ( empty( $vd['variation_id'] ) || false === $vd['display_price'] ) {
				continue;
			}
			// Normalize attribute keys (WC url-encodes non-ASCII keys) so they match
			// the attribute definitions below (attribute_ + sanitize_title).
			$attrs = array();
			foreach ( (array) $vd['attributes'] as $k => $v ) {
				$attrs[ urldecode( $k ) ] = $v;
			}
			$variations[] = array(
				'id'    => (int) $vd['variation_id'],
				'price' => (float) $vd['display_price'],
				'attrs' => $attrs,
			);
		}
		usort( $variations, function ( $a, $b ) {
			return $a['price'] <=> $b['price'];
		} );

		$attributes = array();
		foreach ( $product->get_variation_attributes() as $attr_name => $options ) {
			$attributes[] = array(
				'name'    => wc_attribute_label( $attr_name ),
				'key'     => urldecode( 'attribute_' . sanitize_title( $attr_name ) ),
				'options' => array_values( array_filter( (array) $options, 'strlen' ) ),
			);
		}

		wp_send_json_success( array(
			'id'          => $product_id,
			'name'        => $product->get_name(),
			'image'       => wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_single' ) ?: wc_placeholder_img_src(),
			'description' => wp_kses_post( $product->get_short_description() ?: $product->get_description() ),
			'attributes'  => $attributes,
			'variations'  => $variations,
			'default'     => $variations ? $variations[0]['attrs'] : (object) array(),
		) );
	}
}
