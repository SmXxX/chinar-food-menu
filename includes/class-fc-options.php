<?php
/**
 * Food options data + server-side pricing. Single source of truth for reading
 * a product's configured options and computing a customized unit price.
 * NEVER trust client-supplied prices — always recompute here.
 *
 * @package FoodCustomizer
 */

defined( 'ABSPATH' ) || exit;

class FC_Options {

	/**
	 * The last WooCommerce cart error (e.g. the out-of-stock notice that
	 * add_to_cart raises), stripped and cleared so it won't leak to the next
	 * page. Lets our AJAX add-to-cart show the real "only N left" message
	 * instead of a generic error. Falls back to $fallback.
	 */
	public static function cart_error( $fallback ) {
		$notices = function_exists( 'wc_get_notices' ) ? wc_get_notices( 'error' ) : array();
		if ( ! empty( $notices ) ) {
			wc_clear_notices();
			$last = end( $notices );
			$msg  = is_array( $last ) ? ( $last['notice'] ?? '' ) : $last;
			$msg  = trim( wp_strip_all_tags( (string) $msg ) );
			if ( '' !== $msg ) {
				return $msg;
			}
		}
		return $fallback;
	}

	/**
	 * Read a product's full food-options config as a normalized array.
	 *
	 * @param int $product_id
	 * @return array{has_options:bool,variants:array,ingredients:array,removable:array,addons:array,allergens:array}
	 */
	public static function get_config( $product_id ) {
		$product_id = (int) $product_id;

		$ingredients = (array) get_post_meta( $product_id, FC_META_INGREDIENTS, true );
		$removable   = array_map( 'strval', (array) get_post_meta( $product_id, FC_META_REMOVABLE, true ) );
		$addons      = (array) get_post_meta( $product_id, FC_META_ADDONS, true );
		$variants    = (array) get_post_meta( $product_id, FC_META_VARIANTS, true );
		$allergens   = (array) get_post_meta( $product_id, FC_META_ALLERGENS, true );

		// Only the removable subset is offered to customers for removal.
		$removable_ings = array();
		foreach ( $ingredients as $ing ) {
			if ( isset( $ing['id'] ) && in_array( (string) $ing['id'], $removable, true ) ) {
				$removable_ings[] = array( 'id' => (string) $ing['id'], 'name' => (string) $ing['name'] );
			}
		}

		$clean_addons = array();
		foreach ( $addons as $a ) {
			if ( empty( $a['id'] ) || '' === ( $a['name'] ?? '' ) ) {
				continue;
			}
			$clean_addons[] = array(
				'id'      => (string) $a['id'],
				'name'    => (string) $a['name'],
				'price'   => (float) ( $a['price'] ?? 0 ),
				'max_qty' => (int) ( $a['max_qty'] ?? 0 ),
			);
		}

		$clean_variants = array();
		foreach ( $variants as $v ) {
			if ( empty( $v['id'] ) || '' === ( $v['name'] ?? '' ) ) {
				continue;
			}
			$clean_variants[] = array(
				'id'         => (string) $v['id'],
				'name'       => (string) $v['name'],
				'price'      => (float) ( $v['price'] ?? 0 ),
				'is_default' => ! empty( $v['is_default'] ) ? 1 : 0,
			);
		}

		$has_options = ! empty( $removable_ings ) || ! empty( $clean_addons ) || ! empty( $clean_variants );

		return array(
			'has_options' => $has_options,
			'variants'    => $clean_variants,
			'ingredients' => array_map( function ( $i ) {
				return array( 'id' => (string) ( $i['id'] ?? '' ), 'name' => (string) ( $i['name'] ?? '' ) );
			}, $ingredients ),
			'removable'   => $removable_ings,
			'addons'      => $clean_addons,
			'allergens'   => array_values( array_intersect( array_map( 'strval', (array) $allergens ), FC_Allergens::keys() ) ),
		);
	}

	/** Does this product have any customer-facing options? */
	public static function has_options( $product_id ) {
		$cfg = self::get_config( $product_id );
		return $cfg['has_options'];
	}

	/**
	 * Full product payload for the customizer (modal + product page). Shared so
	 * the two UIs are always fed identical data.
	 */
	public static function payload( $product_id ) {
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return null;
		}
		$cfg = self::get_config( $product_id );

		$allergens = array();
		foreach ( $cfg['allergens'] as $key ) {
			$allergens[] = array( 'key' => $key, 'label' => FC_Allergens::label( $key ) );
		}

		$weight  = $product->get_weight();
		$blocked = FC_Menu::is_product_time_blocked( $product_id );

		// "Combine with" = WooCommerce Cross-sells (set on the product edit page,
		// Product data → Linked Products → Cross-sells). Shown under the image.
		$combine = array();
		foreach ( $product->get_cross_sell_ids() as $cid ) {
			$cp = wc_get_product( $cid );
			if ( ! $cp || ! $cp->is_visible() ) {
				continue;
			}
			$combine[] = array(
				'id'    => (int) $cid,
				'name'  => $cp->get_name(),
				'image' => wp_get_attachment_image_url( $cp->get_image_id(), 'woocommerce_thumbnail' ) ?: wc_placeholder_img_src(),
				'price' => wp_kses_post( $cp->get_price_html() ),
				'link'  => get_permalink( $cid ),
				'type'  => $cp->is_type( 'variable' ) ? 'variable' : 'simple',
			);
		}

		// WooCommerce variations (e.g. pizza dough/sauce) so the customizer can
		// offer them and resolve the correct variation on add-to-cart.
		$wc = null;
		if ( $product->is_type( 'variable' ) ) {
			$wc_attrs = array();
			foreach ( $product->get_variation_attributes() as $attr_name => $options ) {
				$wc_attrs[] = array(
					'name'    => wc_attribute_label( $attr_name ),
					'key'     => urldecode( 'attribute_' . sanitize_title( $attr_name ) ),
					'options' => array_values( array_filter( (array) $options, 'strlen' ) ),
				);
			}
			$wc_vars = array();
			foreach ( $product->get_available_variations() as $vd ) {
				if ( empty( $vd['variation_id'] ) || false === $vd['display_price'] ) {
					continue;
				}
				$a = array();
				foreach ( (array) $vd['attributes'] as $k => $v ) {
					$a[ urldecode( $k ) ] = $v;
				}
				$wc_vars[] = array( 'id' => (int) $vd['variation_id'], 'price' => (float) $vd['display_price'], 'attrs' => $a );
			}
			usort( $wc_vars, function ( $x, $y ) {
				return $x['price'] <=> $y['price'];
			} );
			$wc = array(
				'attributes' => $wc_attrs,
				'variations' => $wc_vars,
				'default'    => $wc_vars ? $wc_vars[0]['attrs'] : new stdClass(),
			);
		}

		// Remaining stock for rental/limited items (Manage stock ON); 0 = unlimited.
		$stock = ( $product->managing_stock() && null !== $product->get_stock_quantity() ) ? max( 0, (int) $product->get_stock_quantity() ) : 0;

		return array(
			'wc'          => $wc,
			'combine'     => $combine,
			'id'          => (int) $product_id,
			'name'        => $product->get_name(),
			'image'       => wp_get_attachment_image_url( $product->get_image_id(), 'woocommerce_single' ) ?: wc_placeholder_img_src(),
			'description' => wp_kses_post( $product->get_short_description() ?: $product->get_description() ),
			'base_price'  => (float) $product->get_price(),
			'weight'      => $weight ? wc_format_localized_decimal( $weight ) : '',
			'weight_unit' => $weight ? get_option( 'woocommerce_weight_unit' ) : '',
			'ingredients' => array_values( array_filter( array_map( function ( $i ) {
				return (string) ( $i['name'] ?? '' );
			}, $cfg['ingredients'] ) ) ),
			'variants'    => $cfg['variants'],
			'removable'   => $cfg['removable'],
			'addons'      => $cfg['addons'],
			'allergens'   => $allergens,
			'available'   => ! $blocked,
			'unavailable_msg' => $blocked ? FC_Menu::unavailable_message( $product_id ) : '',
			'stock'       => $stock,
			'stock_note'  => $stock > 0 ? sprintf( __( 'only %d left', 'food-customizer' ), $stock ) : '',
		);
	}

	/**
	 * Validate a raw customer selection against the product config and return a
	 * clean, server-authoritative selection with computed unit price.
	 *
	 * @param int   $product_id
	 * @param array $selection  Raw: [ 'variant_id'=>, 'removed'=>[ids], 'addons'=>[ id=>qty ] ]
	 * @return array{unit_price:float,variant:?array,removed:array,addons:array}
	 */
	public static function build_selection( $product_id, $selection ) {
		$product = wc_get_product( $product_id );
		$cfg     = self::get_config( $product_id );

		$base            = $product ? (float) $product->get_price() : 0.0;
		$variation_id    = 0;
		$variation_attrs = array();

		// WooCommerce variation: resolve from the chosen attributes; sets base price.
		// Match on VALUES, not attribute keys — the stored variation meta keys can be
		// transliterated (Latin) while the parent attribute keys stay Cyrillic, so key
		// comparison silently matches the wrong variation. Option values are identical
		// on both sides and unique per attribute in our menu, so value matching is
		// correct. A variation only resolves when EVERY one of its required options is
		// among the customer's choices — an incomplete selection leaves variation_id 0.
		if ( $product && $product->is_type( 'variable' ) ) {
			$want_vals = array();
			if ( isset( $selection['wc'] ) && is_array( $selection['wc'] ) ) {
				foreach ( $selection['wc'] as $v ) {
					$v = (string) $v;
					if ( '' !== $v ) { $want_vals[] = $v; }
				}
			}
			foreach ( $product->get_available_variations() as $vd ) {
				if ( empty( $vd['variation_id'] ) || false === $vd['display_price'] ) {
					continue;
				}
				$attrs = array();
				$need  = array();
				foreach ( (array) $vd['attributes'] as $k => $v ) {
					$attrs[ $k ] = $v; // keep WC's original (encoded) key for add_to_cart.
					if ( '' !== $v ) { $need[] = (string) $v; } // '' means "any".
				}
				$ok = true;
				foreach ( $need as $nv ) {
					if ( ! in_array( $nv, $want_vals, true ) ) { $ok = false; break; }
				}
				if ( $ok && count( $want_vals ) >= count( $need ) && ! empty( $need ) ) {
					$variation_id    = (int) $vd['variation_id'];
					$variation_attrs = $attrs;
					$base            = (float) $vd['display_price'];
					break;
				}
			}
		}

		// Variant: must match a configured variant; sets the base price.
		$chosen_variant = null;
		if ( ! empty( $cfg['variants'] ) ) {
			$want = isset( $selection['variant_id'] ) ? (string) $selection['variant_id'] : '';
			foreach ( $cfg['variants'] as $v ) {
				if ( $v['id'] === $want ) {
					$chosen_variant = $v;
					break;
				}
			}
			if ( null === $chosen_variant ) {
				// Fall back to the default (or first) variant.
				foreach ( $cfg['variants'] as $v ) {
					if ( $v['is_default'] ) { $chosen_variant = $v; break; }
				}
				if ( null === $chosen_variant ) {
					$chosen_variant = $cfg['variants'][0];
				}
			}
			$base = (float) $chosen_variant['price'];
		}

		// Removed ingredients: only those that are actually removable.
		$removed      = array();
		$want_removed = isset( $selection['removed'] ) ? array_map( 'strval', (array) $selection['removed'] ) : array();
		foreach ( $cfg['removable'] as $ing ) {
			if ( in_array( $ing['id'], $want_removed, true ) ) {
				$removed[] = $ing;
			}
		}

		// Addons: validate id + clamp qty to [1, max_qty].
		$addons     = array();
		$addon_cost = 0.0;
		$want_addons = isset( $selection['addons'] ) && is_array( $selection['addons'] ) ? $selection['addons'] : array();
		foreach ( $cfg['addons'] as $a ) {
			if ( ! isset( $want_addons[ $a['id'] ] ) ) {
				continue;
			}
			$qty = (int) $want_addons[ $a['id'] ];
			if ( $qty < 1 ) {
				continue;
			}
			if ( $a['max_qty'] > 0 && $qty > $a['max_qty'] ) {
				$qty = $a['max_qty'];
			}
			$line       = (float) $a['price'] * $qty;
			$addon_cost += $line;
			$addons[]   = array( 'id' => $a['id'], 'name' => $a['name'], 'price' => (float) $a['price'], 'qty' => $qty, 'line' => $line );
		}

		$unit_price = round( $base + $addon_cost, wc_get_price_decimals() );

		return array(
			'unit_price'      => $unit_price,
			'variant'         => $chosen_variant,
			'removed'         => $removed,
			'addons'          => $addons,
			'variation_id'    => $variation_id,
			'variation_attrs' => $variation_attrs,
		);
	}
}
