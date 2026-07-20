<?php
/**
 * Custom single-product template for food products (product.png layout).
 * The interactive customizer is rendered by product.js from window.FC_PRODUCT.
 *
 * @package FoodCustomizer
 */

defined( 'ABSPATH' ) || exit;

get_header( 'shop' );

global $product;
if ( ! $product instanceof WC_Product ) {
	$product = wc_get_product( get_queried_object_id() );
}
$payload = FC_Options::payload( $product->get_id() );
?>
<div class="fc-product-wrap">
	<div class="fc-product-page">
		<div id="fc-product-app" data-product-id="<?php echo esc_attr( $product->get_id() ); ?>">
			<div class="fc-pp-loading"><span class="fc-spinner" aria-hidden="true"></span></div>
		</div>
	</div>
</div>
<script>window.FC_PRODUCT = <?php echo wp_json_encode( $payload ); // phpcs:ignore WordPress.Security.EscapeOutput ?>;</script>
<?php
get_footer( 'shop' );
