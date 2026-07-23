<?php
/**
 * Delivery zones + options on checkout.
 *
 * - Admin defines zones (name, covered areas text, ETA, "busy" toggle + message).
 * - Checkout: the customer fills the normal WooCommerce address fields, then picks
 *   a zone (which shows the areas it covers and its ETA). A busy zone blocks the order.
 * - Delivery option: to the door / to the entrance (courier calls).
 * - Timing: as soon as possible (shows the zone ETA) or a chosen time.
 * All choices are saved on the order (admin, emails, kitchen ticket).
 *
 * @package FoodCustomizer
 */

defined( 'ABSPATH' ) || exit;

class FC_Delivery {

	const OPT_ENABLED   = 'fc_delivery_enabled';
	const OPT_ZONES     = 'fc_delivery_zones';
	const OPT_SHAPES    = 'fc_zone_shapes';
	const OPT_ZSTREETS  = 'fc_zone_streets_geo';
	const OPT_OVERRIDES = 'fc_hood_overrides';
	const GROUP         = 'fc_delivery_group';
	const PAGE          = 'fc-delivery-zones';

	public function init() {
		// Admin: zones management page. Priority 20 so the Food Customizer top-level
		// menu (registered by FC_Settings at 10) exists before this sub-item attaches.
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue' ) );
		// Editor for correcting a street's neighbourhood(s).
		add_action( 'wp_ajax_fc_save_street_hood', array( $this, 'ajax_save_street_hood' ) );
		// One-click Varna 2-zone preset + [fc_delivery_map] shortcode.
		add_action( 'wp_ajax_fc_apply_zone_preset', array( $this, 'ajax_apply_zone_preset' ) );
		add_action( 'wp_ajax_fc_save_zone_shapes', array( $this, 'ajax_save_zone_shapes' ) );
		add_shortcode( 'fc_delivery_map', array( $this, 'render_map' ) );

		if ( ! self::is_enabled() ) {
			return;
		}

		// Checkout.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		// Per-zone delivery price -> cart fee (recalculated when the zone changes).
		add_action( 'woocommerce_cart_calculate_fees', array( $this, 'add_delivery_fee' ) );
		// Render at the very top of the checkout form — prominent, outside every box
		// and before the column layout (so it can't disrupt the theme's grid), yet
		// still inside <form> so the fields submit.
		add_action( 'woocommerce_checkout_before_customer_details', array( $this, 'render_fields' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save_to_order' ), 20, 2 );

		// Show on order (admin + customer + emails).
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'admin_display' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'customer_display' ) );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'email_display' ), 15, 1 );
	}

	public static function is_enabled() {
		return (bool) get_option( self::OPT_ENABLED, 0 );
	}

	/** Sanitised list of zones (reindexed). */
	public static function zones() {
		$raw = get_option( self::OPT_ZONES, array() );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$out = array();
		foreach ( $raw as $z ) {
			if ( empty( $z['name'] ) ) {
				continue;
			}
			$out[] = array(
				'name'     => (string) $z['name'],
				'areas'    => isset( $z['areas'] ) ? (string) $z['areas'] : '',
				'eta'      => isset( $z['eta'] ) ? (string) $z['eta'] : '',
				'price'    => isset( $z['price'] ) ? (float) $z['price'] : 0.0,
				'color'    => isset( $z['color'] ) ? (string) $z['color'] : '',
				'busy'     => ! empty( $z['busy'] ),
				'busy_msg' => isset( $z['busy_msg'] ) ? (string) $z['busy_msg'] : '',
				'hoods'    => ( isset( $z['hoods'] ) && is_array( $z['hoods'] ) ) ? array_values( array_map( 'strval', $z['hoods'] ) ) : array(),
			);
		}
		return $out;
	}

	/** Bundled Varna dataset as shipped (unmodified): [ neighbourhood => [streets…] ]. */
	public static function base_neighbourhoods() {
		static $data = null;
		if ( null !== $data ) {
			return $data;
		}
		$file = FC_DIR . 'assets/data/varna-neighbourhoods.json';
		$data = file_exists( $file ) ? (array) json_decode( (string) file_get_contents( $file ), true ) : array();
		return $data;
	}

	/** Admin corrections: [ street => [neighbourhoods…] ]. Overrides the bundled data. */
	public static function overrides() {
		$o = get_option( self::OPT_OVERRIDES, array() );
		return is_array( $o ) ? $o : array();
	}

	/**
	 * Effective dataset: bundled data with the owner's corrections applied.
	 * [ neighbourhood => [streets…] ]. An override replaces that street's
	 * neighbourhood list entirely (an empty list removes the street).
	 */
	public static function neighbourhoods() {
		static $eff = null;
		if ( null !== $eff ) {
			return $eff;
		}
		// Invert the bundled data to street => set of neighbourhoods.
		$by_street = array();
		foreach ( self::base_neighbourhoods() as $q => $streets ) {
			foreach ( (array) $streets as $s ) {
				$by_street[ (string) $s ][ (string) $q ] = true;
			}
		}
		// Apply corrections.
		foreach ( self::overrides() as $s => $qs ) {
			$s              = (string) $s;
			$by_street[ $s ] = array();
			foreach ( (array) $qs as $q ) {
				$q = (string) $q;
				if ( '' !== $q ) {
					$by_street[ $s ][ $q ] = true;
				}
			}
		}
		// Re-invert to neighbourhood => [streets].
		$out = array();
		foreach ( $by_street as $s => $qset ) {
			foreach ( array_keys( $qset ) as $q ) {
				$out[ $q ][] = $s;
			}
		}
		foreach ( $out as $q => $streets ) {
			$streets = array_values( array_unique( $streets ) );
			sort( $streets, SORT_LOCALE_STRING );
			$out[ $q ] = $streets;
		}
		ksort( $out, SORT_LOCALE_STRING );
		$eff = $out;
		return $eff;
	}

	/** All known neighbourhood names (from the effective dataset), sorted. */
	public static function all_quarters() {
		return array_keys( self::neighbourhoods() );
	}

	/** Save/clear a street's neighbourhood correction (AJAX). */
	public function ajax_save_street_hood() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'msg' => 'forbidden' ), 403 );
		}
		check_ajax_referer( 'fc_hood_edit', 'nonce' );
		$street = isset( $_POST['street'] ) ? sanitize_text_field( wp_unslash( $_POST['street'] ) ) : '';
		if ( '' === $street ) {
			wp_send_json_error( array( 'msg' => 'no-street' ) );
		}
		$ov = self::overrides();
		if ( ! empty( $_POST['reset'] ) ) {
			// Remove the correction — the street reverts to the bundled assignment.
			unset( $ov[ $street ] );
			update_option( self::OPT_OVERRIDES, $ov, false );
			// Return the bundled assignment for this street.
			$base = array();
			foreach ( self::base_neighbourhoods() as $q => $streets ) {
				if ( in_array( $street, (array) $streets, true ) ) {
					$base[] = (string) $q;
				}
			}
			wp_send_json_success( array( 'street' => $street, 'quarters' => array_values( array_unique( $base ) ) ) );
		}
		$qs_in    = isset( $_POST['quarters'] ) ? (array) wp_unslash( $_POST['quarters'] ) : array();
		$quarters = array();
		foreach ( $qs_in as $q ) {
			$q = sanitize_text_field( $q );
			if ( '' !== $q ) {
				$quarters[] = $q;
			}
		}
		$quarters       = array_values( array_unique( $quarters ) );
		$ov[ $street ]  = $quarters;
		update_option( self::OPT_OVERRIDES, $ov, false );
		wp_send_json_success( array( 'street' => $street, 'quarters' => $quarters ) );
	}

	/* --------------------------------------------------------------------- */
	/* Varna 2-zone preset + delivery map                                    */
	/* --------------------------------------------------------------------- */

	/** Normalise a neighbourhood/area name for matching (strip prefixes, optional trailing number). */
	private static function norm_hood( $s, $strip_num = false ) {
		$s = mb_strtolower( (string) $s );
		$s = preg_replace( '/\b(кв|ж\.?к|с\.?о|м-т|мт|к\.?к|в\.?з|со)\.?\s*/u', '', $s );
		if ( $strip_num ) {
			$s = preg_replace( '/\s*\d+\s*$/u', '', $s );
		}
		return trim( preg_replace( '/[^\p{L}\p{N}]/u', '', $s ) );
	}

	/** Which preset zone an area name belongs to: 0 = central, 1 = outer, 2 = no delivery. */
	public static function preset_zone_index( $name ) {
		$name = (string) $name;
		foreach ( array( 'Златни', 'Слънчев' ) as $k ) {
			if ( false !== mb_stripos( $name, $k ) ) {
				return 2;
			}
		}
		$outer = array( 'Виница', 'Аспарухово', 'Галата', 'Владиславово', 'Евксиноград', 'Боровец', 'Трака', 'Зеленика', 'Прибой', 'Рибарско', 'Ален', 'Добрева', 'Телевизионна', 'Лозите', 'Сотира', 'Ракитника', 'Фичоза', 'Кочмар', 'Планова', 'Сълзица', 'Акчелар', 'Пчелина', 'Перчемлията', 'Салтанат', 'Франга', 'Тарла', 'Балам', 'Боклук', 'Казашко', 'Каменар', 'Звездица', 'Константин', 'Тополи', 'Летище', 'Западна промишлена', 'Крушките', 'Лазур', 'Ментешето', 'Орехчето', 'Припек', 'Кантара', 'Погреби', 'Малка Чайка', 'Елена' );
		foreach ( $outer as $k ) {
			if ( false !== mb_stripos( $name, $k ) ) {
				return 1;
			}
		}
		return 0;
	}

	/** Recursively sanitise a drawn polygon shape (nested arrays of [lat,lng] floats). */
	private function sanitize_shape( $v, $depth = 0 ) {
		if ( $depth > 6 || ! is_array( $v ) ) {
			return array();
		}
		// Leaf: a [lat, lng] coordinate pair.
		if ( 2 === count( $v ) && isset( $v[0] ) && ! is_array( $v[0] ) && is_numeric( $v[0] ) && is_numeric( $v[1] ) ) {
			return array( round( (float) $v[0], 6 ), round( (float) $v[1], 6 ) );
		}
		$out = array();
		foreach ( $v as $item ) {
			$s = $this->sanitize_shape( $item, $depth + 1 );
			if ( ! empty( $s ) ) {
				$out[] = $s;
			}
		}
		return $out;
	}

	/** Save hand-drawn zone boundaries from the admin map editor (AJAX). */
	public function ajax_save_zone_shapes() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'msg' => 'forbidden' ), 403 );
		}
		check_ajax_referer( 'fc_zone_map', 'nonce' );
		$raw = isset( $_POST['shapes'] ) ? json_decode( wp_unslash( $_POST['shapes'] ), true ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		// Stored in a SEPARATE option so saving the zones table never wipes the shapes.
		$shapes = array();
		foreach ( $raw as $i => $shape ) {
			$clean = $this->sanitize_shape( $shape );
			if ( ! empty( $clean ) ) {
				$shapes[ (int) $i ] = $clean;
			}
		}
		update_option( self::OPT_SHAPES, $shapes, false );

		// Compute the streets inside each drawn shape (point-in-polygon) and cache them,
		// so the checkout loads the streets that are geographically inside the zone.
		$geo    = array();
		$counts = array();
		foreach ( $shapes as $i => $shape ) {
			$geo[ (int) $i ] = $this->streets_in_shape( $shape );
			$counts[ (int) $i ] = count( $geo[ (int) $i ] );
		}
		update_option( self::OPT_ZSTREETS, $geo, false );
		wp_send_json_success( array( 'saved' => count( $shapes ), 'streets' => $counts ) );
	}

	/** The Varna 2-zone production configuration, built from the current neighbourhoods. */
	public static function preset_zones() {
		$z1 = array();
		$z2 = array();
		foreach ( array_keys( self::neighbourhoods() ) as $n ) {
			$zi = self::preset_zone_index( $n );
			if ( 1 === $zi ) {
				$z2[] = $n;
			} elseif ( 0 === $zi ) {
				$z1[] = $n;
			}
		}
		return array(
			array( 'name' => 'Зона 1', 'areas' => 'Централна Варна', 'eta' => 'до 60 мин', 'price' => 2.04, 'color' => '#e0553a', 'busy' => 0, 'busy_msg' => '', 'hoods' => $z1 ),
			array( 'name' => 'Зона 2', 'areas' => 'Виница, Св.св. Константин и Елена, Аспарухово, Галата, Владиславово, ЗПЗ, Летище Варна', 'eta' => 'до 60 мин', 'price' => 2.56, 'color' => '#f0b23e', 'busy' => 0, 'busy_msg' => '', 'hoods' => $z2 ),
			array( 'name' => 'КК Златни Пясъци', 'areas' => '', 'eta' => '', 'price' => 0, 'color' => '#b8b2a6', 'busy' => 1, 'busy_msg' => 'Не се извършва разнос до КК Златни Пясъци', 'hoods' => array() ),
		);
	}

	/** One-click: configure Зона 1 (central) / Зона 2 (outer) / no-delivery like production. */
	public function ajax_apply_zone_preset() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'msg' => 'forbidden' ), 403 );
		}
		check_ajax_referer( 'fc_zone_preset', 'nonce' );
		update_option( self::OPT_ZONES, $this->sanitize_zones( self::preset_zones() ) );
		update_option( self::OPT_ENABLED, 1 );
		wp_send_json_success( array( 'zones' => 3 ) );
	}

	/** Zone colour + neighbourhood→zone lookup, shared by both map renderers. */
	private function map_zone_helpers( $zones ) {
		$defcol = array( '#e0553a', '#f0b23e', '#b8b2a6', '#5a8f4e', '#4a78c0', '#9b59b6' );
		$color  = function ( $zi ) use ( $zones, $defcol ) {
			if ( isset( $zones[ $zi ]['color'] ) && $zones[ $zi ]['color'] ) {
				return $zones[ $zi ]['color'];
			}
			return isset( $defcol[ $zi ] ) ? $defcol[ $zi ] : '#c9c4ba';
		};
		$lookup = array();
		foreach ( $zones as $zi => $z ) {
			foreach ( (array) $z['hoods'] as $h ) {
				$lookup[ self::norm_hood( $h ) ] = $zi;
				$k2 = self::norm_hood( $h, true );
				if ( ! isset( $lookup[ $k2 ] ) ) {
					$lookup[ $k2 ] = $zi;
				}
			}
		}
		// Returns the zone index a polygon's neighbourhood is assigned to, or null when
		// that neighbourhood is not in any configured zone (so the map can skip it).
		$zone_of = function ( $name ) use ( $lookup ) {
			$k  = self::norm_hood( $name );
			$k2 = self::norm_hood( $name, true );
			if ( isset( $lookup[ $k ] ) ) {
				return $lookup[ $k ];
			}
			if ( isset( $lookup[ $k2 ] ) ) {
				return $lookup[ $k2 ];
			}
			return null;
		};
		return array( $color, $zone_of );
	}

	/** [fc_delivery_map] — OpenStreetMap with the delivery zones overlaid (default),
	 *  or a flat coloured SVG map with style="flat". */
	public function render_map( $atts = array() ) {
		$atts = shortcode_atts( array( 'style' => 'osm', 'height' => 460 ), (array) $atts, 'fc_delivery_map' );
		return ( 'flat' === $atts['style'] ) ? $this->render_map_svg() : $this->render_map_osm( $atts );
	}

	/**
	 * Per-zone map boundary to render/edit: the admin's hand-drawn shape if set,
	 * otherwise the pre-dissolved default blob for that zone index. Keyed by zone index.
	 */
	public static function zone_shapes() {
		$custom = get_option( self::OPT_SHAPES, array() );
		$custom = is_array( $custom ) ? $custom : array();
		$byidx  = array();
		$sf     = FC_DIR . 'assets/data/varna-zone-shapes.json';
		$pre    = file_exists( $sf ) ? json_decode( (string) file_get_contents( $sf ), true ) : array();
		foreach ( (array) $pre as $p ) {
			$byidx[ (int) $p['zone'] ] = $p['latlngs'];
		}
		$out = array();
		foreach ( array_keys( self::zones() ) as $i ) {
			if ( ! empty( $custom[ $i ] ) ) {
				$out[ $i ] = $custom[ $i ];
			} elseif ( isset( $byidx[ $i ] ) ) {
				$out[ $i ] = $byidx[ $i ];
			}
		}
		return $out;
	}

	/** OpenStreetMap (Leaflet) with zone polygons overlaid in real GPS coordinates. */
	private function render_map_osm( $atts ) {
		$zones = self::zones();
		list( $color, $zone_of ) = $this->map_zone_helpers( $zones );
		$features = array();

		$shapes = self::zone_shapes();
		foreach ( $shapes as $i => $shape ) {
			if ( empty( $shape ) ) {
				continue;
			}
			$features[] = array(
				'n' => isset( $zones[ $i ]['name'] ) ? $zones[ $i ]['name'] : '',
				'c' => $color( $i ),
				'r' => $shape,
			);
		}
		if ( empty( $features ) ) {
			// Fallback: individual neighbourhood polygons.
			$pf    = FC_DIR . 'assets/data/varna-zones-latlon.json';
			$polys = file_exists( $pf ) ? json_decode( (string) file_get_contents( $pf ), true ) : array();
			foreach ( (array) $polys as $name => $ring ) {
				$zi = $zone_of( $name );
				if ( null !== $zi ) {
					$features[] = array( 'n' => $name, 'c' => $color( $zi ), 'r' => $ring );
				}
			}
		}
		if ( empty( $features ) ) {
			return $this->render_map_svg();
		}

		$fmt = function ( $p ) { return FC_Currency::format_plain( (float) $p ); };
		$legend = array();
		foreach ( $zones as $zi => $z ) {
			$label = ! empty( $z['busy'] )
				? ( $z['busy_msg'] ? $z['busy_msg'] : $z['name'] )
				: $z['name'] . ( $z['price'] > 0 ? ' — ' . $fmt( $z['price'] ) : '' );
			$legend[] = array( 'color' => $color( $zi ), 'label' => $label );
		}

		wp_enqueue_style( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4' );
		wp_enqueue_script( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true );
		wp_enqueue_script( 'fc-delivery-map', FC_URL . 'assets/js/delivery-map.js', array( 'leaflet' ), FC_VERSION, true );

		static $n = 0;
		$n++;
		$id  = 'fc-delivery-map-' . $n;
		$h   = max( 240, (int) $atts['height'] );
		$css = '';
		static $printed = false;
		if ( ! $printed ) {
			$printed = true;
			$css = '<style>.fc-delivery-leaflet{width:100%;border-radius:12px;overflow:hidden;z-index:0}.fc-map-legend{background:#fff;padding:8px 11px;border-radius:8px;box-shadow:0 1px 6px rgba(0,0,0,.25);font-size:13px;line-height:1.75}.fc-map-legend span{display:block;white-space:nowrap}.fc-map-legend i{display:inline-block;width:14px;height:14px;border-radius:3px;margin-right:7px;vertical-align:-2px}</style>';
		}
		$data = wp_json_encode( array( 'features' => $features, 'legend' => $legend ) );
		return $css
			. '<div id="' . esc_attr( $id ) . '" class="fc-delivery-leaflet" style="height:' . $h . 'px"></div>'
			. '<script type="application/json" class="fc-delivery-map-data" data-for="' . esc_attr( $id ) . '">' . $data . '</script>';
	}

	/** Flat coloured SVG map (no external tiles) — [fc_delivery_map style="flat"]. */
	private function render_map_svg() {
		$file = FC_DIR . 'assets/data/varna-polygons.json';
		if ( ! file_exists( $file ) ) {
			return '';
		}
		$data = json_decode( (string) file_get_contents( $file ), true );
		if ( empty( $data['polys'] ) ) {
			return '';
		}
		$zones = self::zones();
		list( $zone_color, $zone_of ) = $this->map_zone_helpers( $zones );
		$vb = isset( $data['viewBox'] ) ? $data['viewBox'] : '0 0 1195.8 1080';

		$svg = '<svg class="fc-delivery-map" viewBox="' . esc_attr( $vb ) . '" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="xMidYMid meet" role="img" aria-label="' . esc_attr__( 'Delivery zones map', 'food-customizer' ) . '">';
		foreach ( $data['polys'] as $name => $d ) {
			$zi = $zone_of( $name );
			if ( null === $zi ) {
				continue; // only draw neighbourhoods assigned to a zone in the plugin
			}
			$svg .= '<path d="' . esc_attr( $d ) . '" fill="' . esc_attr( $zone_color( $zi ) ) . '" fill-opacity="0.82" stroke="#fff" stroke-width="0.8"><title>' . esc_html( $name ) . '</title></path>';
		}
		$svg .= '</svg>';

		$fmt = function ( $p ) { return FC_Currency::format_plain( (float) $p ); };
		$leg = '<ul class="fc-delivery-map-legend">';
		foreach ( $zones as $zi => $z ) {
			$sw = '<span class="fc-dm-swatch" style="background:' . esc_attr( $zone_color( $zi ) ) . '"></span> ';
			if ( ! empty( $z['busy'] ) ) {
				$leg .= '<li>' . $sw . esc_html( $z['busy_msg'] ? $z['busy_msg'] : $z['name'] ) . '</li>';
			} else {
				$leg .= '<li>' . $sw . '<strong>' . esc_html( $z['name'] ) . '</strong>' . ( $z['price'] > 0 ? ' — ' . esc_html( $fmt( $z['price'] ) ) : '' ) . '</li>';
			}
		}
		$leg .= '</ul>';

		$css = '';
		static $printed = false;
		if ( ! $printed ) {
			$printed = true;
			$css = '<style>.fc-delivery-map-wrap{max-width:720px;margin:0 auto}.fc-delivery-map{width:100%;height:auto;display:block;background:#e9f2f6;border-radius:12px}.fc-delivery-map-legend{list-style:none;padding:0;margin:14px 0 0;display:flex;flex-wrap:wrap;gap:16px}.fc-delivery-map-legend li{display:flex;align-items:center;gap:8px;font-size:14px}.fc-dm-swatch{width:16px;height:16px;border-radius:3px;display:inline-block;flex:0 0 auto}</style>';
		}
		return $css . '<div class="fc-delivery-map-wrap">' . $svg . $leg . '</div>';
	}

	/**
	 * Per-zone street lists for the checkout. If a zone has a hand-drawn map shape,
	 * its streets come from point-in-polygon (streets geographically inside the shape,
	 * cached at save time); otherwise from the union of the zone's neighbourhoods.
	 */
	public static function zone_streets() {
		$geo   = get_option( self::OPT_ZSTREETS, array() );
		$geo   = is_array( $geo ) ? $geo : array();
		$hoods = self::neighbourhoods();
		$out   = array();
		foreach ( self::zones() as $i => $z ) {
			if ( ! empty( $geo[ $i ] ) ) {
				$out[ $i ] = $geo[ $i ];
				continue;
			}
			$streets = array();
			foreach ( (array) $z['hoods'] as $h ) {
				if ( isset( $hoods[ $h ] ) ) {
					$streets = array_merge( $streets, (array) $hoods[ $h ] );
				}
			}
			$streets = array_values( array_unique( $streets ) );
			sort( $streets, SORT_LOCALE_STRING );
			$out[ $i ] = $streets;
		}
		return $out;
	}

	/** Bundled street → sample coordinate points, for point-in-polygon zone assignment. */
	public static function street_points() {
		static $data = null;
		if ( null !== $data ) {
			return $data;
		}
		$file = FC_DIR . 'assets/data/varna-street-points.json';
		$data = file_exists( $file ) ? (array) json_decode( (string) file_get_contents( $file ), true ) : array();
		return $data;
	}

	/** Flatten a drawn shape (nested latlngs) to a list of rings [[[lat,lng],…],…]. */
	private function shape_rings( $shape ) {
		$rings = array();
		$walk  = function ( $v ) use ( &$walk, &$rings ) {
			if ( ! is_array( $v ) || empty( $v ) ) {
				return;
			}
			// A ring is an array whose first element is a [lat,lng] number pair.
			if ( isset( $v[0][0] ) && ! is_array( $v[0][0] ) && is_numeric( $v[0][0] ) ) {
				$rings[] = $v;
				return;
			}
			foreach ( $v as $item ) {
				$walk( $item );
			}
		};
		$walk( $shape );
		return $rings;
	}

	/** Even-odd ray-casting point-in-polygon across all rings of a shape. */
	private function point_in_rings( $lat, $lng, $rings ) {
		$inside = false;
		foreach ( $rings as $ring ) {
			$n = count( $ring );
			$j = $n - 1;
			for ( $i = 0; $i < $n; $i++ ) {
				$yi = $ring[ $i ][0]; $xi = $ring[ $i ][1];
				$yj = $ring[ $j ][0]; $xj = $ring[ $j ][1];
				$dy = ( $yj - $yi );
				if ( ( ( $yi > $lat ) !== ( $yj > $lat ) ) && ( $lng < ( $xj - $xi ) * ( $lat - $yi ) / ( 0.0 !== $dy ? $dy : 1e-12 ) + $xi ) ) {
					$inside = ! $inside;
				}
				$j = $i;
			}
		}
		return $inside;
	}

	/** Streets whose coordinates fall inside a drawn shape (sorted, unique). */
	private function streets_in_shape( $shape ) {
		$rings = $this->shape_rings( $shape );
		if ( empty( $rings ) ) {
			return array();
		}
		$out = array();
		foreach ( self::street_points() as $name => $coords ) {
			foreach ( (array) $coords as $c ) {
				if ( isset( $c[0], $c[1] ) && $this->point_in_rings( (float) $c[0], (float) $c[1], $rings ) ) {
					$out[] = $name;
					break;
				}
			}
		}
		$out = array_values( array_unique( $out ) );
		sort( $out, SORT_LOCALE_STRING );
		return $out;
	}

	/* --------------------------------------------------------------------- */
	/* Admin                                                                 */
	/* --------------------------------------------------------------------- */

	public function add_menu() {
		add_submenu_page(
			'food-customizer',
			__( 'Delivery zones', 'food-customizer' ),
			__( 'Delivery zones', 'food-customizer' ),
			'manage_woocommerce',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting( self::GROUP, self::OPT_ENABLED, array( 'type' => 'boolean', 'sanitize_callback' => array( $this, 'sanitize_bool' ), 'default' => 0 ) );
		register_setting( self::GROUP, self::OPT_ZONES, array( 'type' => 'array', 'sanitize_callback' => array( $this, 'sanitize_zones' ), 'default' => array() ) );
	}

	public function sanitize_bool( $v ) {
		return empty( $v ) ? 0 : 1;
	}

	public function sanitize_zones( $in ) {
		$out = array();
		if ( ! is_array( $in ) ) {
			return $out;
		}
		foreach ( $in as $z ) {
			$name = isset( $z['name'] ) ? sanitize_text_field( $z['name'] ) : '';
			if ( '' === $name ) {
				continue; // skip empty rows.
			}
			$valid_hoods = array_keys( self::neighbourhoods() );
			$hoods       = ( isset( $z['hoods'] ) && is_array( $z['hoods'] ) )
				? array_values( array_intersect( array_map( 'sanitize_text_field', $z['hoods'] ), $valid_hoods ) )
				: array();
			$out[] = array(
				'name'     => $name,
				'areas'    => isset( $z['areas'] ) ? sanitize_textarea_field( $z['areas'] ) : '',
				'eta'      => isset( $z['eta'] ) ? sanitize_text_field( $z['eta'] ) : '',
				'price'    => isset( $z['price'] ) ? max( 0, (float) $z['price'] ) : 0,
				'color'    => isset( $z['color'] ) ? (string) sanitize_hex_color( $z['color'] ) : '',
				'busy'     => empty( $z['busy'] ) ? 0 : 1,
				'busy_msg' => isset( $z['busy_msg'] ) ? sanitize_text_field( $z['busy_msg'] ) : '',
				'hoods'    => $hoods,
			);
		}
		return $out;
	}

	public function admin_enqueue( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_script( 'fc-delivery-admin', FC_URL . 'assets/js/delivery-admin.js', array( 'jquery' ), FC_VERSION, true );
		wp_localize_script( 'fc-delivery-admin', 'FC_HOODS', self::neighbourhoods() );

		// On-map boundary editor: Leaflet + Leaflet-Geoman.
		wp_enqueue_style( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4' );
		wp_enqueue_script( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true );
		wp_enqueue_style( 'leaflet-geoman', 'https://unpkg.com/@geoman-io/leaflet-geoman-free@2.15.0/dist/leaflet-geoman.css', array( 'leaflet' ), '2.15.0' );
		wp_enqueue_script( 'leaflet-geoman', 'https://unpkg.com/@geoman-io/leaflet-geoman-free@2.15.0/dist/leaflet-geoman.min.js', array( 'leaflet' ), '2.15.0', true );
		wp_enqueue_script( 'fc-zone-map-admin', FC_URL . 'assets/js/delivery-map-admin.js', array( 'jquery', 'leaflet', 'leaflet-geoman' ), FC_VERSION, true );
		$zdata = array();
		$shapes = self::zone_shapes();
		foreach ( self::zones() as $i => $z ) {
			$zdata[] = array(
				'i'     => $i,
				'name'  => $z['name'],
				'color' => $z['color'] ? $z['color'] : '#3388ff',
				'busy'  => (bool) $z['busy'],
				'shape' => isset( $shapes[ $i ] ) ? $shapes[ $i ] : array(),
			);
		}
		wp_localize_script( 'fc-zone-map-admin', 'FC_ZONEMAP', array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( 'fc_zone_map' ),
			'zones' => $zdata,
			'i18n'  => array(
				'saved'    => __( 'Boundaries saved.', 'food-customizer' ),
				'saving'   => __( 'Saving…', 'food-customizer' ),
				'error'    => __( 'Error, try again', 'food-customizer' ),
				'drawnFor' => __( 'Drawing for', 'food-customizer' ),
			),
		) );
		wp_localize_script( 'fc-delivery-admin', 'FC_ZONEPRESET', array(
			'ajax'     => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'fc_zone_preset' ),
			'confirm'  => __( 'This replaces your current zones with the Varna 2-zone setup: Зона 1 (central), Зона 2 (outer), and no delivery to Златни Пясъци. Prices are the EUR equivalent of 4 / 5 лв — adjust after. Continue?', 'food-customizer' ),
			'applying' => __( 'Applying…', 'food-customizer' ),
			'done'     => __( 'Done — reloading…', 'food-customizer' ),
			'error'    => __( 'Error, try again', 'food-customizer' ),
		) );
		wp_localize_script( 'fc-delivery-admin', 'FC_HOODEDIT', array(
			'ajax'     => admin_url( 'admin-ajax.php' ),
			'nonce'    => wp_create_nonce( 'fc_hood_edit' ),
			'quarters' => self::all_quarters(),
			'i18n'     => array(
				'edit'       => __( 'Edit', 'food-customizer' ),
				'save'       => __( 'Save', 'food-customizer' ),
				'cancel'     => __( 'Cancel', 'food-customizer' ),
				'reset'      => __( 'Reset to auto', 'food-customizer' ),
				'saved'      => __( 'Saved', 'food-customizer' ),
				'error'      => __( 'Error, try again', 'food-customizer' ),
				'none'       => __( '(not assigned)', 'food-customizer' ),
				'streetname' => __( 'Street name', 'food-customizer' ),
				'addhint'    => __( 'Assign to one or more neighbourhoods (Ctrl/Cmd-click for several):', 'food-customizer' ),
			),
		) );
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$zones = get_option( self::OPT_ZONES, array() );
		$zones = is_array( $zones ) ? array_values( $zones ) : array();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Delivery zones', 'food-customizer' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable delivery zones', 'food-customizer' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( self::OPT_ENABLED ); ?>" value="1" <?php checked( self::is_enabled(), true ); ?> /> <?php esc_html_e( 'Show the zone / delivery options on the checkout page', 'food-customizer' ); ?></label></td>
					</tr>
				</table>

				<p class="description"><?php esc_html_e( 'Each zone the customer can choose on checkout. "Covers" is shown to help them pick the right zone. Toggle "Busy" to stop deliveries to that zone (the customer sees the message and cannot order for it).', 'food-customizer' ); ?></p>

				<p><button type="button" class="button button-secondary" id="fc-zone-preset">⚡ <?php esc_html_e( 'Apply Varna 2-zone preset (Зона 1 / Зона 2)', 'food-customizer' ); ?></button> <span id="fc-zone-preset-msg" style="margin-left:8px;"></span></p>
				<p class="description" style="margin-top:-6px;"><?php esc_html_e( 'Sets up Зона 1 (central, ~4 лв) and Зона 2 (outer, ~5 лв) with the neighbourhoods grouped, plus no delivery to Златни Пясъци. Show the coloured map on any page with the [fc_delivery_map] shortcode.', 'food-customizer' ); ?></p>

				<div class="fc-street-search" style="margin:16px 0;max-width:1100px;">
					<label for="fc-street-search" style="font-weight:600;"><?php esc_html_e( 'Find which neighbourhood a street is in', 'food-customizer' ); ?></label><br>
					<input type="search" id="fc-street-search" class="regular-text" placeholder="<?php esc_attr_e( 'Type a street name…', 'food-customizer' ); ?>" autocomplete="off" style="margin-top:6px;">
					<p class="description" style="margin:4px 0 8px;"><?php esc_html_e( 'Search a street, then click Edit to correct its neighbourhood(s). The mapping is auto-generated, so fix any that are wrong — your corrections are kept and used at checkout.', 'food-customizer' ); ?></p>
					<div id="fc-street-results" data-empty="<?php esc_attr_e( 'No matching streets.', 'food-customizer' ); ?>" style="margin-top:8px;"></div>
					<p style="margin-top:8px;"><button type="button" class="button" id="fc-add-street">+ <?php esc_html_e( 'Add a street', 'food-customizer' ); ?></button></p>
				</div>

				<div class="fc-quarter-browse" style="margin:16px 0;max-width:1100px;">
					<label for="fc-quarter-pick" style="font-weight:600;"><?php esc_html_e( 'Show all streets in a neighbourhood', 'food-customizer' ); ?></label><br>
					<select id="fc-quarter-pick" class="regular-text" data-placeholder="<?php esc_attr_e( '— choose a neighbourhood —', 'food-customizer' ); ?>" style="margin-top:6px;"></select>
					<div id="fc-quarter-results" style="margin-top:8px;"></div>
				</div>

				<div class="fc-zone-map-edit" style="margin:20px 0;max-width:1100px;">
					<h2 style="margin-bottom:4px;"><?php esc_html_e( 'Edit zone boundaries on the map', 'food-customizer' ); ?></h2>
					<p class="description" style="margin-top:0;"><?php esc_html_e( 'Click "Edit" in the map toolbar to drag the boundary points. To add an area to a zone, pick the zone below, click the polygon tool, and draw — a zone can have several separate polygons (draw as many as you like). Click "Save boundaries" when done; the checkout then loads the streets inside your shapes.', 'food-customizer' ); ?></p>
					<p style="margin:8px 0;">
						<label><?php esc_html_e( 'Draw new shape for:', 'food-customizer' ); ?> <select id="fc-zonemap-target"></select></label>
						&nbsp; <button type="button" class="button button-primary" id="fc-zonemap-save"><?php esc_html_e( 'Save boundaries', 'food-customizer' ); ?></button>
						<span id="fc-zonemap-msg" style="margin-left:8px;"></span>
					</p>
					<div id="fc-zone-map-editor" style="height:520px;border:1px solid #ccd0d4;border-radius:8px;"></div>
				</div>

				<table class="widefat striped" id="fc-zones-table" style="max-width:1100px;margin-top:10px;">
					<thead>
						<tr>
							<th style="width:13%"><?php esc_html_e( 'Zone name', 'food-customizer' ); ?></th>
							<th><?php esc_html_e( 'Covers (streets / areas)', 'food-customizer' ); ?></th>
							<th style="width:20%"><?php esc_html_e( 'Neighbourhoods (streets auto-load at checkout)', 'food-customizer' ); ?></th>
							<th style="width:9%"><?php esc_html_e( 'ETA', 'food-customizer' ); ?></th>
							<th style="width:9%"><?php /* translators: %s = currency symbol */ printf( esc_html__( 'Delivery price (%s)', 'food-customizer' ), esc_html( get_woocommerce_currency_symbol() ) ); ?></th>
							<th style="width:5%"><?php esc_html_e( 'Map colour', 'food-customizer' ); ?></th>
							<th style="width:6%"><?php esc_html_e( 'Busy', 'food-customizer' ); ?></th>
							<th style="width:15%"><?php esc_html_e( 'Busy message (optional)', 'food-customizer' ); ?></th>
							<th style="width:4%"></th>
						</tr>
					</thead>
					<tbody id="fc-zones-body">
						<?php
						if ( empty( $zones ) ) {
							echo $this->zone_row( 0, array() ); // one empty starter row. // phpcs:ignore
						} else {
							foreach ( $zones as $i => $z ) {
								echo $this->zone_row( (int) $i, (array) $z ); // phpcs:ignore
							}
						}
						?>
					</tbody>
				</table>
				<p><button type="button" class="button" id="fc-add-zone">+ <?php esc_html_e( 'Add zone', 'food-customizer' ); ?></button></p>

				<script type="text/template" id="fc-zone-template"><?php echo $this->zone_row( '__i__', array() ); // phpcs:ignore ?></script>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/** One editable zone row. $i may be an int index or the "__i__" JS placeholder. */
	private function zone_row( $i, $z ) {
		$name      = isset( $z['name'] ) ? $z['name'] : '';
		$area      = isset( $z['areas'] ) ? $z['areas'] : '';
		$eta       = isset( $z['eta'] ) ? $z['eta'] : '';
		$price     = ( isset( $z['price'] ) && $z['price'] > 0 ) ? (float) $z['price'] : '';
		$color     = ( isset( $z['color'] ) && $z['color'] ) ? $z['color'] : '#dd5903';
		$busy      = ! empty( $z['busy'] );
		$msg       = isset( $z['busy_msg'] ) ? $z['busy_msg'] : '';
		$hoods_sel = ( isset( $z['hoods'] ) && is_array( $z['hoods'] ) ) ? $z['hoods'] : array();
		$b         = self::OPT_ZONES . '[' . $i . ']';
		ob_start();
		?>
		<tr class="fc-zone-row">
			<td><input type="text" class="widefat" name="<?php echo esc_attr( $b ); ?>[name]" value="<?php echo esc_attr( $name ); ?>" placeholder="<?php esc_attr_e( 'e.g. Center', 'food-customizer' ); ?>" /></td>
			<td><textarea class="widefat" rows="2" name="<?php echo esc_attr( $b ); ?>[areas]" placeholder="<?php esc_attr_e( 'e.g. ul. Vitosha, ul. Graf Ignatiev, bul. Vasil Levski…', 'food-customizer' ); ?>"><?php echo esc_textarea( $area ); ?></textarea></td>
			<td><select multiple size="5" class="widefat" name="<?php echo esc_attr( $b ); ?>[hoods][]"><?php foreach ( self::neighbourhoods() as $hn => $streets ) : ?><option value="<?php echo esc_attr( $hn ); ?>"<?php selected( in_array( (string) $hn, array_map( 'strval', $hoods_sel ), true ) ); ?>><?php echo esc_html( $hn ); ?> (<?php echo count( (array) $streets ); ?>)</option><?php endforeach; ?></select></td>
			<td><input type="text" class="widefat" name="<?php echo esc_attr( $b ); ?>[eta]" value="<?php echo esc_attr( $eta ); ?>" placeholder="<?php esc_attr_e( 'e.g. 45 min', 'food-customizer' ); ?>" /></td>
			<td><input type="number" step="0.01" min="0" class="widefat" name="<?php echo esc_attr( $b ); ?>[price]" value="<?php echo esc_attr( $price ); ?>" placeholder="0.00" /></td>
			<td style="text-align:center"><input type="color" name="<?php echo esc_attr( $b ); ?>[color]" value="<?php echo esc_attr( $color ); ?>" title="<?php esc_attr_e( 'Colour on the delivery map', 'food-customizer' ); ?>" /></td>
			<td style="text-align:center"><input type="checkbox" name="<?php echo esc_attr( $b ); ?>[busy]" value="1" <?php checked( $busy, true ); ?> /></td>
			<td><input type="text" class="widefat" name="<?php echo esc_attr( $b ); ?>[busy_msg]" value="<?php echo esc_attr( $msg ); ?>" placeholder="<?php echo esc_attr( FC_Settings::label( 'del_busy' ) ); ?>" /></td>
			<td style="text-align:center"><button type="button" class="button-link fc-del-zone" title="<?php esc_attr_e( 'Remove', 'food-customizer' ); ?>">&times;</button></td>
		</tr>
		<?php
		return ob_get_clean();
	}

	/* --------------------------------------------------------------------- */
	/* Checkout                                                              */
	/* --------------------------------------------------------------------- */

	public function enqueue() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		if ( empty( self::zones() ) ) {
			return;
		}
		wp_enqueue_style( 'fc-delivery', FC_URL . 'assets/css/delivery.css', array(), FC_VERSION );
		wp_enqueue_script( 'fc-delivery', FC_URL . 'assets/js/delivery.js', array( 'jquery' ), FC_VERSION, true );
		$prices = array();
		foreach ( self::zones() as $i => $z ) {
			$prices[ $i ] = ( $z['price'] > 0 ) ? FC_Currency::format_plain( $z['price'] ) : '';
		}
		wp_localize_script( 'fc-delivery', 'FC_DELIVERY', array(
			'zones'       => self::zones(),
			'busyDefault' => FC_Settings::label( 'del_busy' ),
			'etaLabel'    => FC_Settings::label( 'del_eta' ),
			'streets'     => self::zone_streets(),
			'streetLabel' => __( 'Choose your street', 'food-customizer' ),
			'prices'      => $prices,
			'freeLabel'   => __( 'Free', 'food-customizer' ),
		) );
	}

	/** Index of the zone the customer has selected (from the checkout form / session). */
	private function selected_zone_index() {
		$zi = null;
		// During the update_order_review AJAX the form is serialised in post_data.
		if ( isset( $_POST['post_data'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			parse_str( wp_unslash( $_POST['post_data'] ), $pd ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			if ( isset( $pd['fc_delivery_zone'] ) ) {
				$zi = sanitize_text_field( $pd['fc_delivery_zone'] );
			}
		}
		// During the final submit the field is posted directly.
		if ( null === $zi && isset( $_POST['fc_delivery_zone'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$zi = sanitize_text_field( wp_unslash( $_POST['fc_delivery_zone'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		if ( null !== $zi ) {
			if ( WC()->session ) {
				WC()->session->set( 'fc_delivery_zone', $zi );
			}
			return $zi;
		}
		$s = ( WC()->session ) ? WC()->session->get( 'fc_delivery_zone' ) : '';
		return ( null === $s ) ? '' : (string) $s;
	}

	/** Add the selected zone's delivery price as a cart fee. */
	public function add_delivery_fee( $cart ) {
		if ( ! is_object( $cart ) ) {
			return;
		}
		if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return;
		}
		$zones = self::zones();
		if ( empty( $zones ) ) {
			return;
		}
		$zi = $this->selected_zone_index();
		if ( '' === $zi || ! isset( $zones[ $zi ] ) ) {
			return;
		}
		$price = (float) $zones[ $zi ]['price'];
		if ( $price > 0 ) {
			$cart->add_fee( __( 'Delivery', 'food-customizer' ), $price, false );
		}
	}

	public function render_fields( $checkout = null ) {
		$zones = self::zones();
		if ( empty( $zones ) ) {
			return;
		}
		$L = function ( $k ) { return FC_Settings::label( $k ); };
		echo '<div id="fc-delivery" class="fc-delivery">';
		echo '<h3 class="fc-del-heading">' . esc_html__( 'Delivery', 'food-customizer' ) . '</h3>';

		// Zone selector.
		echo '<p class="form-row form-row-wide validate-required" id="fc_delivery_zone_field">';
		echo '<label for="fc_delivery_zone">' . esc_html( $L( 'del_zone' ) ) . '&nbsp;<abbr class="required" title="required">*</abbr></label>';
		echo '<span class="woocommerce-input-wrapper"><select name="fc_delivery_zone" id="fc_delivery_zone" class="select" required>';
		echo '<option value="">' . esc_html( $L( 'del_zone_choose' ) ) . '</option>';
		foreach ( $zones as $i => $z ) {
			echo '<option value="' . esc_attr( $i ) . '">' . esc_html( $z['name'] ) . '</option>';
		}
		echo '</select></span></p>';

		// Street selector — populated by JS from the chosen zone's neighbourhoods
		// (OpenStreetMap data). Hidden until a zone with streets is picked.
		echo '<p class="form-row form-row-wide fc-del-street-field" id="fc_delivery_street_field" hidden>';
		echo '<label for="fc_delivery_street">' . esc_html__( 'Street', 'food-customizer' ) . '</label>';
		echo '<span class="woocommerce-input-wrapper"><select name="fc_delivery_street" id="fc_delivery_street" class="select">';
		echo '<option value="">' . esc_html__( 'Choose your street', 'food-customizer' ) . '</option>';
		echo '</select></span></p>';

		// Info panel (filled by JS from the selected zone).
		echo '<div class="fc-del-info" hidden>';
		echo '<div class="fc-del-covers"><strong>' . esc_html( $L( 'del_covers' ) ) . ':</strong> <span class="fc-del-covers-val"></span></div>';
		echo '<div class="fc-del-eta">' . esc_html( $L( 'del_eta' ) ) . ': <span class="fc-del-eta-val"></span></div>';
		echo '<div class="fc-del-price"><strong>' . esc_html__( 'Delivery', 'food-customizer' ) . ':</strong> <span class="fc-del-price-val"></span></div>';
		echo '</div>';
		echo '<div class="fc-del-busy" role="alert" hidden></div>';

		// Delivery option.
		echo '<p class="form-row form-row-wide" id="fc_delivery_type_field">';
		echo '<label>' . esc_html( $L( 'del_type' ) ) . '</label>';
		echo '<span class="woocommerce-input-wrapper fc-del-radios">';
		echo '<label class="fc-del-radio"><input type="radio" name="fc_delivery_type" value="door" checked> ' . esc_html( $L( 'del_door' ) ) . '</label>';
		echo '<label class="fc-del-radio"><input type="radio" name="fc_delivery_type" value="entrance"> ' . esc_html( $L( 'del_entrance' ) ) . '</label>';
		echo '</span></p>';

		// Timing.
		echo '<p class="form-row form-row-wide" id="fc_delivery_time_field">';
		echo '<label>' . esc_html( $L( 'del_time' ) ) . '</label>';
		echo '<span class="woocommerce-input-wrapper fc-del-radios">';
		echo '<label class="fc-del-radio"><input type="radio" name="fc_delivery_time_mode" value="asap" checked> ' . esc_html( $L( 'del_asap' ) ) . ' <span class="fc-del-asap-eta"></span></label>';
		echo '<label class="fc-del-radio"><input type="radio" name="fc_delivery_time_mode" value="scheduled"> ' . esc_html( $L( 'del_scheduled' ) ) . '</label>';
		echo '<input type="time" name="fc_delivery_time" class="fc-del-time-input" hidden>';
		echo '</span></p>';

		echo '</div>';
	}

	public function validate() {
		$zones = self::zones();
		if ( empty( $zones ) ) {
			return;
		}
		$zi = isset( $_POST['fc_delivery_zone'] ) ? sanitize_text_field( wp_unslash( $_POST['fc_delivery_zone'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' === $zi || ! isset( $zones[ $zi ] ) ) {
			wc_add_notice( __( 'Please select your delivery zone.', 'food-customizer' ), 'error' );
			return;
		}
		if ( ! empty( $zones[ $zi ]['busy'] ) ) {
			$msg = $zones[ $zi ]['busy_msg'] ? $zones[ $zi ]['busy_msg'] : FC_Settings::label( 'del_busy' );
			wc_add_notice( $msg, 'error' );
		}
		$mode = isset( $_POST['fc_delivery_time_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['fc_delivery_time_mode'] ) ) : 'asap'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'scheduled' === $mode && empty( $_POST['fc_delivery_time'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			wc_add_notice( __( 'Please choose a delivery time, or select "as soon as possible".', 'food-customizer' ), 'error' );
		}
	}

	public function save_to_order( $order, $data ) {
		$zones = self::zones();
		if ( empty( $zones ) ) {
			return;
		}
		$zi = isset( $_POST['fc_delivery_zone'] ) ? sanitize_text_field( wp_unslash( $_POST['fc_delivery_zone'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $zi && isset( $zones[ $zi ] ) ) {
			$order->update_meta_data( '_fc_delivery_zone', $zones[ $zi ]['name'] );
			if ( $zones[ $zi ]['eta'] ) {
				$order->update_meta_data( '_fc_delivery_eta', $zones[ $zi ]['eta'] );
			}
		}

		$street = isset( $_POST['fc_delivery_street'] ) ? sanitize_text_field( wp_unslash( $_POST['fc_delivery_street'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $street ) {
			$order->update_meta_data( '_fc_delivery_street', $street );
		}

		$type = isset( $_POST['fc_delivery_type'] ) ? sanitize_text_field( wp_unslash( $_POST['fc_delivery_type'] ) ) : 'door'; // phpcs:ignore WordPress.Security.NonceVerification
		$order->update_meta_data( '_fc_delivery_type', 'entrance' === $type ? FC_Settings::label( 'del_entrance' ) : FC_Settings::label( 'del_door' ) );

		$mode = isset( $_POST['fc_delivery_time_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['fc_delivery_time_mode'] ) ) : 'asap'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'scheduled' === $mode && ! empty( $_POST['fc_delivery_time'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$order->update_meta_data( '_fc_delivery_time', sanitize_text_field( wp_unslash( $_POST['fc_delivery_time'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		} else {
			$eta = ( '' !== $zi && isset( $zones[ $zi ] ) ) ? $zones[ $zi ]['eta'] : '';
			$order->update_meta_data( '_fc_delivery_time', FC_Settings::label( 'del_asap' ) . ( $eta ? ' (' . $eta . ')' : '' ) );
		}
	}

	/* --------------------------------------------------------------------- */
	/* Order display                                                         */
	/* --------------------------------------------------------------------- */

	/** key => translated label, for whichever meta are present on the order. */
	private function order_lines( $order ) {
		$map = array(
			'_fc_delivery_zone'   => FC_Settings::label( 'del_zone' ),
			'_fc_delivery_street' => __( 'Street', 'food-customizer' ),
			'_fc_delivery_type'   => FC_Settings::label( 'del_type' ),
			'_fc_delivery_time'   => FC_Settings::label( 'del_time' ),
		);
		$out = array();
		foreach ( $map as $key => $label ) {
			$val = $order->get_meta( $key );
			if ( '' !== $val && null !== $val ) {
				$out[ $label ] = $val;
			}
		}
		return $out;
	}

	public function admin_display( $order ) {
		$lines = $this->order_lines( $order );
		if ( empty( $lines ) ) {
			return;
		}
		echo '<div class="fc-del-admin"><h3>' . esc_html__( 'Delivery', 'food-customizer' ) . '</h3><p>';
		foreach ( $lines as $label => $val ) {
			echo '<strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $val ) . '<br>';
		}
		echo '</p></div>';
	}

	public function customer_display( $order ) {
		$lines = $this->order_lines( $order );
		if ( empty( $lines ) ) {
			return;
		}
		echo '<section class="fc-del-customer"><h2>' . esc_html__( 'Delivery', 'food-customizer' ) . '</h2><table class="woocommerce-table shop_table"><tbody>';
		foreach ( $lines as $label => $val ) {
			echo '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $val ) . '</td></tr>';
		}
		echo '</tbody></table></section>';
	}

	public function email_display( $order ) {
		$lines = $this->order_lines( $order );
		if ( empty( $lines ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Delivery', 'food-customizer' ) . '</h2><table cellpadding="6" style="width:100%;border:1px solid #eee;border-collapse:collapse;margin-bottom:20px;">';
		foreach ( $lines as $label => $val ) {
			echo '<tr><th align="left" style="border:1px solid #eee;">' . esc_html( $label ) . '</th><td style="border:1px solid #eee;">' . esc_html( $val ) . '</td></tr>';
		}
		echo '</table>';
	}
}
