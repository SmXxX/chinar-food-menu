<?php
/**
 * Content part for a food product's single page (product.png layout).
 * Rendered inside WooCommerce's single-product.php, so the theme header/footer
 * and dark styling wrap it. The interactive UI is built by product.js from
 * window.FC_PRODUCT.
 *
 * @package FoodCustomizer
 */

defined( 'ABSPATH' ) || exit;

global $product;
if ( ! $product instanceof WC_Product ) {
	$product = wc_get_product( get_queried_object_id() );
}
if ( ! $product ) {
	return;
}
$payload = FC_Options::payload( $product->get_id() );
?>
<div id="product-<?php the_ID(); ?>" <?php wc_product_class( 'fc-product-wrap', $product ); ?>>
	<div class="fc-product-page">
		<div id="fc-product-app" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">
			<div class="fc-pp-loading"><span class="fc-spinner" aria-hidden="true"></span></div>
		</div>
	</div>
	<div class="fc-pp-wc-details">
		<?php
		// Keep WooCommerce's standard product detail tabs (Description /
		// Additional information / Reviews) at the bottom of the page.
		if ( function_exists( 'woocommerce_output_product_data_tabs' ) ) {
			woocommerce_output_product_data_tabs();
		}
		?>
	</div>
</div>
<script>window.FC_PRODUCT = <?php echo wp_json_encode( $payload ); // phpcs:ignore WordPress.Security.EscapeOutput ?>;</script>
