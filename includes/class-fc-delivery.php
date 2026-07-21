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

	const OPT_ENABLED = 'fc_delivery_enabled';
	const OPT_ZONES   = 'fc_delivery_zones';
	const GROUP       = 'fc_delivery_group';
	const PAGE        = 'fc-delivery-zones';

	public function init() {
		// Admin: zones management page. Priority 20 so the Food Customizer top-level
		// menu (registered by FC_Settings at 10) exists before this sub-item attaches.
		add_action( 'admin_menu', array( $this, 'add_menu' ), 20 );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue' ) );

		if ( ! self::is_enabled() ) {
			return;
		}

		// Checkout.
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
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
				'busy'     => ! empty( $z['busy'] ),
				'busy_msg' => isset( $z['busy_msg'] ) ? (string) $z['busy_msg'] : '',
				'hoods'    => ( isset( $z['hoods'] ) && is_array( $z['hoods'] ) ) ? array_values( array_map( 'strval', $z['hoods'] ) ) : array(),
			);
		}
		return $out;
	}

	/** Bundled Varna dataset: [ neighbourhood => [streets…] ] (from OpenStreetMap). */
	public static function neighbourhoods() {
		static $data = null;
		if ( null !== $data ) {
			return $data;
		}
		$file = FC_DIR . 'assets/data/varna-neighbourhoods.json';
		$data = file_exists( $file ) ? (array) json_decode( (string) file_get_contents( $file ), true ) : array();
		return $data;
	}

	/** Per-zone street lists (union of the zone's neighbourhoods) for the checkout. */
	public static function zone_streets() {
		$hoods = self::neighbourhoods();
		$out   = array();
		foreach ( self::zones() as $i => $z ) {
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

				<div class="fc-street-search" style="margin:16px 0;max-width:1100px;">
					<label for="fc-street-search" style="font-weight:600;"><?php esc_html_e( 'Find which neighbourhood a street is in', 'food-customizer' ); ?></label><br>
					<input type="search" id="fc-street-search" class="regular-text" placeholder="<?php esc_attr_e( 'Type a street name…', 'food-customizer' ); ?>" autocomplete="off" style="margin-top:6px;">
					<div id="fc-street-results" data-empty="<?php esc_attr_e( 'No matching streets.', 'food-customizer' ); ?>" style="margin-top:8px;"></div>
				</div>

				<table class="widefat striped" id="fc-zones-table" style="max-width:1100px;margin-top:10px;">
					<thead>
						<tr>
							<th style="width:13%"><?php esc_html_e( 'Zone name', 'food-customizer' ); ?></th>
							<th><?php esc_html_e( 'Covers (streets / areas)', 'food-customizer' ); ?></th>
							<th style="width:22%"><?php esc_html_e( 'Neighbourhoods (streets auto-load at checkout)', 'food-customizer' ); ?></th>
							<th style="width:9%"><?php esc_html_e( 'ETA', 'food-customizer' ); ?></th>
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
		wp_localize_script( 'fc-delivery', 'FC_DELIVERY', array(
			'zones'       => self::zones(),
			'busyDefault' => FC_Settings::label( 'del_busy' ),
			'etaLabel'    => FC_Settings::label( 'del_eta' ),
			'streets'     => self::zone_streets(),
			'streetLabel' => __( 'Choose your street', 'food-customizer' ),
		) );
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
