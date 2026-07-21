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
		add_action( 'wp_enqueue_scripts', array( $this, 'fonts' ) );
	}

	/** Optionally load the plugin's fonts from Google (for themes that don't provide them). */
	public function fonts() {
		if ( ! get_option( 'fc_load_fonts', 0 ) ) {
			return;
		}
		wp_enqueue_style(
			'fc-fonts',
			'https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;500;600;700&family=Jost:wght@400;500;600&display=swap',
			array(),
			null
		);
	}

	public function output() {
		$d = function ( $k ) { return FC_Settings::design( $k ); };

		// Spacing tokens with hard fallbacks: if an older settings.php is briefly in
		// opcache (no space_* defaults yet), never emit 0px — keep the theme's values.
		$hp = (int) $d( 'space_header' );
		$fb = (int) $d( 'space_flycart' );
		if ( $hp < 1 ) { $hp = 118; }
		if ( $fb < 1 ) { $fb = 96; }

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
			'--fc-fs-name'      => (int) $d( 'fs_name' ) . 'px',
			'--fc-fs-title'     => (int) $d( 'fs_title' ) . 'px',
			'--fc-fs-price'     => (int) $d( 'fs_price' ) . 'px',
			'--fc-fs-pp-price'  => (int) $d( 'fs_pp_price' ) . 'px',
			'--fc-fs-heading'   => (int) $d( 'fs_heading' ) . 'px',
			'--fc-fs-button'    => (int) $d( 'fs_button' ) . 'px',
			'--fc-fs-body'      => (int) $d( 'fs_body' ) . 'px',
			'--fc-fs-small'     => (int) $d( 'fs_small' ) . 'px',
			'--fc-fs-pill'      => (int) $d( 'fs_pill' ) . 'px',
			'--fc-ff-heading'   => $d( 'ff_heading' ),
			'--fc-ff-body'      => $d( 'ff_body' ), // empty → skipped → inherits theme font.
			// Layout / theme-fit tokens (consumed by product.css / frontend.css / shop.css).
			'--fc-page-bg'         => $d( 'page_bg' ), // empty → skipped → CSS falls back to the theme bg.
			'--fc-header-pad'      => $hp . 'px',
			'--fc-flycart-bottom'  => $fb . 'px',
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
