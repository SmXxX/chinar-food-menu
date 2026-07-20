<?php
/**
 * Currency helper: EUR base with optional BGN informational display.
 *
 * BGN is display-only. Cart/checkout math stays 100% WooCommerce (EUR).
 *
 * @package FoodCustomizer
 */

defined( 'ABSPATH' ) || exit;

class FC_Currency {

	/** Is the dual-currency display enabled globally? */
	public static function dual_enabled() {
		return (bool) get_option( FC_OPT_DUAL_CURRENCY, 1 );
	}

	/** Convert an EUR amount to BGN using the fixed statutory rate. */
	public static function to_bgn( $eur ) {
		return (float) $eur * FC_EUR_BGN_RATE;
	}

	/**
	 * Format a raw EUR amount for display.
	 *
	 * @param float $eur    Amount in EUR.
	 * @param bool  $with_bgn Whether to append the BGN part (defaults to the global toggle).
	 * @return string HTML-safe formatted price, e.g. "9,71 € (18,99 лв)".
	 */
	public static function format( $eur, $with_bgn = null ) {
		$eur = (float) $eur;

		// EUR part — use WooCommerce's own formatter so it matches store settings.
		$eur_html = function_exists( 'wc_price' )
			? wc_price( $eur, array( 'currency' => 'EUR' ) )
			: number_format_i18n( $eur, 2 ) . ' &euro;';

		if ( null === $with_bgn ) {
			$with_bgn = self::dual_enabled();
		}

		if ( ! $with_bgn ) {
			return $eur_html;
		}

		$bgn        = self::to_bgn( $eur );
		$bgn_string = number_format( $bgn, 2, ',', ' ' ) . ' ' . _x( 'лв', 'BGN currency suffix', 'food-customizer' );

		return $eur_html . ' <span class="fc-price-bgn">(' . esc_html( $bgn_string ) . ')</span>';
	}

	/**
	 * Just the BGN part as an HTML span, e.g. "<span ...>(18,99 лв)</span>".
	 * Used to append to WooCommerce's already-rendered EUR price HTML.
	 */
	public static function bgn_only( $eur ) {
		$bgn = self::to_bgn( $eur );
		$str = number_format( $bgn, 2, ',', ' ' ) . ' ' . _x( 'лв', 'BGN currency suffix', 'food-customizer' );
		return '<span class="fc-price-bgn">(' . esc_html( $str ) . ')</span>';
	}

	/** Plain-text (no HTML) variant, handy for JS data attributes / order meta. */
	public static function format_plain( $eur, $with_bgn = null ) {
		$eur    = (float) $eur;
		$string = number_format( $eur, 2, ',', ' ' ) . ' €';

		if ( null === $with_bgn ) {
			$with_bgn = self::dual_enabled();
		}
		if ( $with_bgn ) {
			$string .= ' (' . number_format( self::to_bgn( $eur ), 2, ',', ' ' ) . ' лв)';
		}
		return $string;
	}
}
