<?php
/**
 * Plugin Name:       Food Customizer
 * Plugin URI:        https://delta.unbelievable.digital/delivery-stariachinar/
 * Description:        Custom food-ordering logic for Staria Chinar: per-product ingredients, removables, paid additions, size/variant radios, EU-14 allergens, and dual-currency (EUR + BGN) display. Extends WooCommerce.
 * Version:           0.9.33
 * Author:            Staria Chinar
 * Text Domain:       food-customizer
 * Requires PHP:      7.4
 * Requires at least: 6.0
 * WC requires at least: 7.0
 *
 * @package FoodCustomizer
 */

defined( 'ABSPATH' ) || exit;

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------
define( 'FC_VERSION', '0.9.33' );
define( 'FC_FILE', __FILE__ );
define( 'FC_DIR', plugin_dir_path( __FILE__ ) );
define( 'FC_URL', plugin_dir_url( __FILE__ ) );

// Fixed statutory conversion rate (Bulgaria): 1 EUR = 1.95583 BGN.
define( 'FC_EUR_BGN_RATE', 1.95583 );

// Product meta keys (see CLAUDE.md §6).
define( 'FC_META_INGREDIENTS', '_food_ingredients' );
define( 'FC_META_REMOVABLE', '_food_removable_ingredient_ids' );
define( 'FC_META_ADDONS', '_food_addons' );
define( 'FC_META_VARIANTS', '_food_variants' );
define( 'FC_META_ALLERGENS', '_food_allergens' );

// Global option keys.
define( 'FC_OPT_DUAL_CURRENCY', 'fc_dual_currency_enabled' );

// ---------------------------------------------------------------------------
// Includes
// ---------------------------------------------------------------------------
require_once FC_DIR . 'includes/class-fc-allergens.php';
require_once FC_DIR . 'includes/class-fc-currency.php';
require_once FC_DIR . 'includes/class-fc-settings.php';
require_once FC_DIR . 'includes/class-fc-product-meta.php';
require_once FC_DIR . 'includes/class-fc-price-display.php';
require_once FC_DIR . 'includes/class-fc-options.php';
require_once FC_DIR . 'includes/class-fc-shop.php';
require_once FC_DIR . 'includes/class-fc-cart.php';
require_once FC_DIR . 'includes/class-fc-menu.php';
require_once FC_DIR . 'includes/class-fc-single.php';
require_once FC_DIR . 'includes/class-fc-style.php';
require_once FC_DIR . 'includes/class-fc-cutlery.php';
require_once FC_DIR . 'includes/class-fc-delivery.php';
require_once FC_DIR . 'includes/class-fc-min-order.php';
require_once FC_DIR . 'includes/class-fc-ingredient-importer.php';
require_once FC_DIR . 'includes/class-fc-guide.php';
require_once FC_DIR . 'includes/class-fc-catering.php';

// Load translations so labels follow the WordPress site language.
add_action( 'init', function () {
	load_plugin_textdomain( 'food-customizer', false, dirname( plugin_basename( FC_FILE ) ) . '/languages' );
} );

/**
 * Guard: this plugin needs WooCommerce. If it's not active, show a notice and
 * do not boot the rest of the plugin (prevents fatals on a live site).
 */
function fc_woocommerce_active() {
	return class_exists( 'WooCommerce' );
}

/**
 * Boot the plugin after all plugins are loaded (so WooCommerce is available).
 */
function fc_bootstrap() {
	if ( ! fc_woocommerce_active() ) {
		add_action( 'admin_notices', function () {
			echo '<div class="notice notice-error"><p><strong>Food Customizer</strong> requires WooCommerce to be installed and active.</p></div>';
		} );
		return;
	}

	// Wrapped defensively so any unexpected error is logged, never white-screening the site.
	try {
		// Front-end (and AJAX): dual-currency price display, shop modal, cart integration.
		( new FC_Price_Display() )->init();
		( new FC_Shop() )->init();
		( new FC_Cart() )->init();
		( new FC_Menu() )->init();
		( new FC_Single() )->init();
		( new FC_Style() )->init();
		( new FC_Cutlery() )->init(); // checkout cutlery toggle (also registers its AJAX).
		( new FC_Delivery() )->init(); // delivery zones + checkout options (admin menu registers itself).
		( new FC_Min_Order() )->init(); // minimum order total enforcement.
			( new FC_Catering() )->init(); // catering module: courier payment + quantity minimums.

		// Admin: settings page + product meta boxes.
		if ( is_admin() ) {
			( new FC_Settings() )->init();
			( new FC_Product_Meta() )->init();
			( new FC_Ingredient_Importer() )->init(); // WooCommerce → Import ingredients tool.
			( new FC_Guide() )->init(); // WooCommerce → Food Customizer Guide (how-to page).
		}
	} catch ( \Throwable $e ) {
		error_log( 'Food Customizer bootstrap error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
	}

	// Cart integration is added in a later phase.
}
add_action( 'plugins_loaded', 'fc_bootstrap' );

/**
 * Activation: set sane defaults. Keep light — no schema changes needed (uses post meta).
 */
function fc_activate() {
	if ( false === get_option( FC_OPT_DUAL_CURRENCY, false ) ) {
		add_option( FC_OPT_DUAL_CURRENCY, 1 ); // dual-currency ON by default (legal display).
	}
}
register_activation_hook( __FILE__, 'fc_activate' );
