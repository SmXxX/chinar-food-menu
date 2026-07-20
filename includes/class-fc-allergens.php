<?php
/**
 * EU 14 standard allergens.
 *
 * @package FoodCustomizer
 */

defined( 'ABSPATH' ) || exit;

class FC_Allergens {

	/**
	 * The fixed EU 14 allergens list. key => label.
	 * Keys are stable slugs used in meta; labels are translatable.
	 *
	 * @return array<string,string>
	 */
	public static function all() {
		return array(
			'gluten'     => __( 'Cereals containing gluten', 'food-customizer' ),
			'crustaceans'=> __( 'Crustaceans', 'food-customizer' ),
			'eggs'       => __( 'Eggs', 'food-customizer' ),
			'fish'       => __( 'Fish', 'food-customizer' ),
			'peanuts'    => __( 'Peanuts', 'food-customizer' ),
			'soybeans'   => __( 'Soybeans', 'food-customizer' ),
			'milk'       => __( 'Milk', 'food-customizer' ),
			'nuts'       => __( 'Nuts', 'food-customizer' ),
			'celery'     => __( 'Celery', 'food-customizer' ),
			'mustard'    => __( 'Mustard', 'food-customizer' ),
			'sesame'     => __( 'Sesame seeds', 'food-customizer' ),
			'sulphites'  => __( 'Sulphur dioxide / sulphites', 'food-customizer' ),
			'lupin'      => __( 'Lupin', 'food-customizer' ),
			'molluscs'   => __( 'Molluscs', 'food-customizer' ),
		);
	}

	/**
	 * Valid allergen keys.
	 *
	 * @return string[]
	 */
	public static function keys() {
		return array_keys( self::all() );
	}

	/**
	 * Label for a given key (or the key itself if unknown).
	 */
	public static function label( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : $key;
	}
}
