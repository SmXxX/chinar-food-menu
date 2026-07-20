<?php
/**
 * Prints the admin-controlled design tokens as CSS custom properties on the
 * front end, overriding the stylesheet defaults.
 *
 * @package FoodCustomizer
 */

defined( 'ABSPATH' ) || exit;

class FC_Style {

	public function init() {
		add_action( 'wp_head', array( $this, 'output' ), 100 );
	}

	public function output() {
		$d = function ( $k ) { return FC_Settings::design( $k ); };

		$vars = array(
			'--fc-ink'          => $d( 'c_text' ),
			'--fc-muted'        => $d( 'c_muted' ),
			'--fc-line'         => $d( 'c_border' ),
			'--fc-line-strong'  => $d( 'c_border_hov' ),
			'--fc-surface'      => $d( 'c_surface' ),
			'--fc-panel'        => $d( 'c_panel' ),
			'--fc-panel-2'      => $d( 'c_panel' ),
			'--fc-accent'       => $d( 'c_accent' ),
			'--fc-accent-text'  => $d( 'c_accent_text' ),
			'--fc-radius'       => (int) $d( 'radius' ) . 'px',
			'--fc-radius-box'   => (int) $d( 'radius_box' ) . 'px',
			'--fc-bw'           => (int) $d( 'border_width' ) . 'px',
		);

		$decl = '';
		foreach ( $vars as $k => $v ) {
			if ( '' === $v || null === $v ) {
				continue;
			}
			$decl .= $k . ':' . $v . ';';
		}
		if ( '' === $decl ) {
			return;
		}
		// Target every scope where the tokens are used so this wins the cascade.
		echo '<style id="fc-custom-vars">:root,.fc-shop,.fc-modal,.fc-modal-root,.fc-product-wrap{' . $decl . '}</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput
	}
}
