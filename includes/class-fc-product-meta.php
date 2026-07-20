<?php
/**
 * Per-product "Food Options" configuration: adds a meta box to the WooCommerce
 * product edit screen and saves ingredients, removables, paid additions,
 * variant radios and allergens as product meta.
 *
 * @package FoodCustomizer
 */

defined( 'ABSPATH' ) || exit;

class FC_Product_Meta {

	public function init() {
		add_action( 'add_meta_boxes', array( $this, 'add_meta_box' ) );
		add_action( 'save_post_product', array( $this, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue( $hook ) {
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		$screen = get_current_screen();
		if ( ! $screen || 'product' !== $screen->post_type ) {
			return;
		}
		wp_enqueue_style( 'fc-admin', FC_URL . 'assets/css/admin.css', array(), FC_VERSION );
		wp_enqueue_script( 'fc-admin', FC_URL . 'assets/js/admin.js', array( 'jquery' ), FC_VERSION, true );
	}

	public function add_meta_box() {
		add_meta_box(
			'fc_food_options',
			__( 'Food Options (Customizer)', 'food-customizer' ),
			array( $this, 'render' ),
			'product',
			'normal',
			'high'
		);
	}

	/* --------------------------------------------------------------------- */
	/* Render                                                                */
	/* --------------------------------------------------------------------- */

	public function render( $post ) {
		wp_nonce_field( 'fc_save_food_options', 'fc_food_options_nonce' );

		$ingredients = (array) get_post_meta( $post->ID, FC_META_INGREDIENTS, true );
		$removable   = (array) get_post_meta( $post->ID, FC_META_REMOVABLE, true );
		$addons      = (array) get_post_meta( $post->ID, FC_META_ADDONS, true );
		$variants    = (array) get_post_meta( $post->ID, FC_META_VARIANTS, true );
		$allergens   = (array) get_post_meta( $post->ID, FC_META_ALLERGENS, true );

		echo '<div class="fc-box">';

		// --- Ingredients + removable flag ---------------------------------
		echo '<h3>' . esc_html__( 'Ingredients', 'food-customizer' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Default ingredients. Tick "Removable" to let customers opt out (free).', 'food-customizer' ) . '</p>';
		echo '<table class="fc-repeat" data-repeat="ingredients"><tbody>';
		if ( empty( $ingredients ) ) {
			$ingredients = array( array( 'id' => '', 'name' => '' ) );
		}
		foreach ( $ingredients as $ing ) {
			$id  = isset( $ing['id'] ) ? $ing['id'] : '';
			$rm  = $id && in_array( (string) $id, array_map( 'strval', $removable ), true );
			$this->row_ingredient( $ing['name'] ?? '', $id, $rm );
		}
		echo '</tbody></table>';
		echo '<button type="button" class="button fc-add" data-target="ingredients">+ ' . esc_html__( 'Add ingredient', 'food-customizer' ) . '</button>';

		// --- Paid additions ----------------------------------------------
		echo '<hr><h3>' . esc_html__( 'Paid additions', 'food-customizer' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Extras the customer can add. Price is per unit (EUR). Max qty optional (0 = unlimited).', 'food-customizer' ) . '</p>';
		echo '<table class="fc-repeat" data-repeat="addons"><thead><tr><th>' . esc_html__( 'Name', 'food-customizer' ) . '</th><th>' . esc_html__( 'Price (EUR)', 'food-customizer' ) . '</th><th>' . esc_html__( 'Max qty', 'food-customizer' ) . '</th><th></th></tr></thead><tbody>';
		if ( empty( $addons ) ) {
			$addons = array( array( 'name' => '', 'price' => '', 'max_qty' => '' ) );
		}
		foreach ( $addons as $a ) {
			$this->row_addon( $a['name'] ?? '', $a['price'] ?? '', $a['max_qty'] ?? '' );
		}
		echo '</tbody></table>';
		echo '<button type="button" class="button fc-add" data-target="addons">+ ' . esc_html__( 'Add addition', 'food-customizer' ) . '</button>';

		// --- Variant radios ----------------------------------------------
		echo '<hr><h3>' . esc_html__( 'Size / variant options', 'food-customizer' ) . '</h3>';
		echo '<p class="description">' . esc_html__( 'Optional. Radio choices that set the base price (e.g. Standard, Double protein). Leave empty to disable for this product. One default.', 'food-customizer' ) . '</p>';
		echo '<table class="fc-repeat" data-repeat="variants"><thead><tr><th>' . esc_html__( 'Name', 'food-customizer' ) . '</th><th>' . esc_html__( 'Price (EUR)', 'food-customizer' ) . '</th><th>' . esc_html__( 'Default', 'food-customizer' ) . '</th><th></th></tr></thead><tbody>';
		if ( empty( $variants ) ) {
			$variants = array( array( 'name' => '', 'price' => '', 'is_default' => 1 ) );
		}
		foreach ( $variants as $v ) {
			$this->row_variant( $v['name'] ?? '', $v['price'] ?? '', ! empty( $v['is_default'] ) );
		}
		echo '</tbody></table>';
		echo '<button type="button" class="button fc-add" data-target="variants">+ ' . esc_html__( 'Add variant', 'food-customizer' ) . '</button>';

		// --- Allergens ----------------------------------------------------
		echo '<hr><h3>' . esc_html__( 'Allergens (EU 14)', 'food-customizer' ) . '</h3>';
		echo '<div class="fc-allergens">';
		foreach ( FC_Allergens::all() as $key => $label ) {
			$checked = in_array( $key, $allergens, true );
			printf(
				'<label class="fc-allergen"><input type="checkbox" name="fc_allergens[]" value="%s" %s /> %s</label>',
				esc_attr( $key ),
				checked( $checked, true, false ),
				esc_html( $label )
			);
		}
		echo '</div>';

		echo '</div>'; // .fc-box
	}

	private function row_ingredient( $name, $id, $removable ) {
		$id = $id ? $id : uniqid( 'ing_' );
		echo '<tr class="fc-row">';
		echo '<td><input type="hidden" name="fc_ing_id[]" value="' . esc_attr( $id ) . '" />';
		echo '<input type="text" class="regular-text" name="fc_ing_name[]" value="' . esc_attr( $name ) . '" placeholder="' . esc_attr__( 'e.g. Tomatoes', 'food-customizer' ) . '" /></td>';
		echo '<td><label><input type="checkbox" name="fc_ing_removable[]" value="' . esc_attr( $id ) . '" ' . checked( $removable, true, false ) . ' /> ' . esc_html__( 'Removable', 'food-customizer' ) . '</label></td>';
		echo '<td><button type="button" class="button-link fc-remove" aria-label="' . esc_attr__( 'Remove', 'food-customizer' ) . '">&times;</button></td>';
		echo '</tr>';
	}

	private function row_addon( $name, $price, $max ) {
		echo '<tr class="fc-row">';
		echo '<td><input type="text" name="fc_addon_name[]" value="' . esc_attr( $name ) . '" placeholder="' . esc_attr__( 'e.g. Extra cheese', 'food-customizer' ) . '" /></td>';
		echo '<td><input type="number" step="0.01" min="0" name="fc_addon_price[]" value="' . esc_attr( $price ) . '" /></td>';
		echo '<td><input type="number" step="1" min="0" name="fc_addon_max[]" value="' . esc_attr( $max ) . '" /></td>';
		echo '<td><button type="button" class="button-link fc-remove">&times;</button></td>';
		echo '</tr>';
	}

	private function row_variant( $name, $price, $is_default ) {
		echo '<tr class="fc-row">';
		echo '<td><input type="text" name="fc_variant_name[]" value="' . esc_attr( $name ) . '" placeholder="' . esc_attr__( 'e.g. Standard', 'food-customizer' ) . '" /></td>';
		echo '<td><input type="number" step="0.01" min="0" name="fc_variant_price[]" value="' . esc_attr( $price ) . '" /></td>';
		echo '<td><input type="radio" name="fc_variant_default" class="fc-variant-default" ' . checked( $is_default, true, false ) . ' /></td>';
		echo '<td><button type="button" class="button-link fc-remove">&times;</button></td>';
		echo '</tr>';
	}

	/* --------------------------------------------------------------------- */
	/* Save                                                                  */
	/* --------------------------------------------------------------------- */

	public function save( $post_id, $post ) {
		// Guards.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['fc_food_options_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['fc_food_options_nonce'] ) ), 'fc_save_food_options' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// --- Ingredients + removable -------------------------------------
		$ids   = isset( $_POST['fc_ing_id'] ) ? (array) wp_unslash( $_POST['fc_ing_id'] ) : array();
		$names = isset( $_POST['fc_ing_name'] ) ? (array) wp_unslash( $_POST['fc_ing_name'] ) : array();
		$rm_in = isset( $_POST['fc_ing_removable'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['fc_ing_removable'] ) ) : array();

		$ingredients = array();
		$removable   = array();
		foreach ( $names as $i => $name ) {
			$name = sanitize_text_field( $name );
			if ( '' === $name ) {
				continue;
			}
			$id            = isset( $ids[ $i ] ) && $ids[ $i ] ? sanitize_text_field( $ids[ $i ] ) : uniqid( 'ing_' );
			$ingredients[] = array( 'id' => $id, 'name' => $name );
			if ( in_array( $id, $rm_in, true ) ) {
				$removable[] = $id;
			}
		}
		update_post_meta( $post_id, FC_META_INGREDIENTS, $ingredients );
		update_post_meta( $post_id, FC_META_REMOVABLE, $removable );

		// --- Addons -------------------------------------------------------
		$a_names = isset( $_POST['fc_addon_name'] ) ? (array) wp_unslash( $_POST['fc_addon_name'] ) : array();
		$a_price = isset( $_POST['fc_addon_price'] ) ? (array) wp_unslash( $_POST['fc_addon_price'] ) : array();
		$a_max   = isset( $_POST['fc_addon_max'] ) ? (array) wp_unslash( $_POST['fc_addon_max'] ) : array();
		$addons  = array();
		foreach ( $a_names as $i => $name ) {
			$name = sanitize_text_field( $name );
			if ( '' === $name ) {
				continue;
			}
			$addons[] = array(
				'id'      => uniqid( 'add_' ),
				'name'    => $name,
				'price'   => isset( $a_price[ $i ] ) ? wc_format_decimal( $a_price[ $i ] ) : '0',
				'max_qty' => isset( $a_max[ $i ] ) ? absint( $a_max[ $i ] ) : 0,
			);
		}
		update_post_meta( $post_id, FC_META_ADDONS, $addons );

		// --- Variants -----------------------------------------------------
		$v_names   = isset( $_POST['fc_variant_name'] ) ? (array) wp_unslash( $_POST['fc_variant_name'] ) : array();
		$v_price   = isset( $_POST['fc_variant_price'] ) ? (array) wp_unslash( $_POST['fc_variant_price'] ) : array();
		$v_default = isset( $_POST['fc_variant_default'] ) ? absint( $_POST['fc_variant_default'] ) : 0; // radio => row index
		$variants  = array();
		$row       = 0;
		foreach ( $v_names as $i => $name ) {
			$name = sanitize_text_field( $name );
			if ( '' === $name ) {
				continue;
			}
			$variants[] = array(
				'id'         => uniqid( 'var_' ),
				'name'       => $name,
				'price'      => isset( $v_price[ $i ] ) ? wc_format_decimal( $v_price[ $i ] ) : '0',
				'is_default' => ( $i === $v_default ) ? 1 : 0,
			);
			$row++;
		}
		// Ensure exactly one default if any variants exist.
		if ( $variants && ! array_filter( wp_list_pluck( $variants, 'is_default' ) ) ) {
			$variants[0]['is_default'] = 1;
		}
		update_post_meta( $post_id, FC_META_VARIANTS, $variants );

		// --- Allergens ----------------------------------------------------
		$in        = isset( $_POST['fc_allergens'] ) ? (array) wp_unslash( $_POST['fc_allergens'] ) : array();
		$valid     = FC_Allergens::keys();
		$allergens = array_values( array_intersect( array_map( 'sanitize_key', $in ), $valid ) );
		update_post_meta( $post_id, FC_META_ALLERGENS, $allergens );
	}
}
