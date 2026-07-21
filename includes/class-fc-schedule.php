<?php
/**
 * Delivery date + time-slot scheduling.
 *
 * Admin (Food Customizer → Delivery schedule): which weekdays deliver, a lead
 * time (earliest bookable day), a booking horizon, the list of time slots, and a
 * block-list for busy dates/slots. Checkout: a calendar (jQuery UI datepicker,
 * bundled with WordPress) that greys out non-delivery weekdays / fully-blocked
 * dates, plus a slot dropdown that hides slots blocked for the chosen date.
 * Saved to the order and shown in admin + emails.
 *
 * @package FoodCustomizer
 */

defined( 'ABSPATH' ) || exit;

class FC_Schedule {

	const GROUP = 'fc_schedule_group';
	const PAGE  = 'fc-delivery-schedule';

	/** Calendar colour settings (empty = inherit the shop's design tokens). */
	const CAL_COLORS = array(
		'fc_cal_bg'          => '--fc-cal-bg',
		'fc_cal_text'        => '--fc-cal-text',
		'fc_cal_accent'      => '--fc-cal-accent',
		'fc_cal_accent_text' => '--fc-cal-accent-text',
		'fc_cal_border'      => '--fc-cal-border',
	);

	public function init() {
		add_action( 'admin_menu', array( $this, 'menu' ), 58 );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue' ) );

		if ( ! self::enabled() ) {
			return;
		}
		add_action( 'woocommerce_before_order_notes', array( $this, 'checkout_field' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		add_action( 'woocommerce_checkout_process', array( $this, 'validate' ) );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'save' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( $this, 'admin_show' ) );
		add_filter( 'woocommerce_email_order_meta_fields', array( $this, 'email_show' ), 10, 3 );
	}

	/* ------------------------------------------------------------------ */
	/* Config                                                             */
	/* ------------------------------------------------------------------ */

	public static function enabled() { return (bool) get_option( 'fc_sched_enabled', 0 ); }
	public static function lead()    { return max( 0, (int) get_option( 'fc_sched_lead', 1 ) ); }
	public static function horizon() { return max( 1, (int) get_option( 'fc_sched_horizon', 30 ) ); }

	/** Enabled weekdays as ints (0 = Sunday … 6 = Saturday). Default Mon–Sat. */
	public static function days() {
		$d = get_option( 'fc_sched_days', array( 1, 2, 3, 4, 5, 6 ) );
		return array_map( 'intval', (array) $d );
	}

	private static function lines( $raw ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $raw ) as $line ) {
			$line = trim( $line );
			if ( '' !== $line ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	/** Time slots, e.g. ["11:00 - 12:00", …]. */
	public static function slots() {
		$raw = get_option( 'fc_sched_slots', "11:00 - 12:00\n12:00 - 13:00\n13:00 - 14:00\n14:00 - 15:00\n15:00 - 16:00\n16:00 - 17:00\n17:00 - 18:00" );
		return self::lines( $raw );
	}

	/** Blocked map: date "Y-m-d" => [slot, …] ; a bare date blocks the whole day. */
	public static function blocked() {
		$map = array();
		foreach ( self::lines( get_option( 'fc_sched_blocked', '' ) ) as $line ) {
			$parts = array_map( 'trim', explode( '|', $line, 2 ) );
			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $parts[0] ) ) {
				continue;
			}
			if ( isset( $parts[1] ) && '' !== $parts[1] ) {
				$map[ $parts[0] ][] = $parts[1];
			} else {
				$map[ $parts[0] ] = array( '*' ); // whole day off
			}
		}
		return $map;
	}

	/** Is a given date (Y-m-d) a valid delivery day? */
	private static function date_ok( $date ) {
		$ts = strtotime( $date );
		if ( ! $ts ) {
			return false;
		}
		$today = strtotime( 'today', current_time( 'timestamp' ) );
		$min   = strtotime( '+' . self::lead() . ' days', $today );
		$max   = strtotime( '+' . self::horizon() . ' days', $today );
		if ( $ts < $min || $ts > $max ) {
			return false;
		}
		if ( ! in_array( (int) gmdate( 'w', $ts ), self::days(), true ) ) {
			return false;
		}
		$blocked = self::blocked();
		$b       = $blocked[ $date ] ?? array();
		if ( in_array( '*', $b, true ) || count( $b ) >= count( self::slots() ) ) {
			return false; // whole day off / every slot blocked
		}
		return true;
	}

	/* ------------------------------------------------------------------ */
	/* Admin settings page                                                */
	/* ------------------------------------------------------------------ */

	public function menu() {
		add_submenu_page(
			'food-customizer',
			__( 'Delivery schedule', 'food-customizer' ),
			__( 'Delivery schedule', 'food-customizer' ),
			'manage_woocommerce',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	public function register() {
		register_setting( self::GROUP, 'fc_sched_enabled', array( 'type' => 'boolean', 'sanitize_callback' => array( $this, 's_bool' ), 'default' => 0 ) );
		register_setting( self::GROUP, 'fc_sched_days', array( 'type' => 'array', 'sanitize_callback' => array( $this, 's_days' ), 'default' => array( 1, 2, 3, 4, 5, 6 ) ) );
		register_setting( self::GROUP, 'fc_sched_lead', array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 1 ) );
		register_setting( self::GROUP, 'fc_sched_horizon', array( 'type' => 'integer', 'sanitize_callback' => 'absint', 'default' => 30 ) );
		register_setting( self::GROUP, 'fc_sched_slots', array( 'type' => 'string', 'sanitize_callback' => array( $this, 's_text' ), 'default' => '' ) );
		register_setting( self::GROUP, 'fc_sched_blocked', array( 'type' => 'string', 'sanitize_callback' => array( $this, 's_text' ), 'default' => '' ) );
		foreach ( array_keys( self::CAL_COLORS ) as $k ) {
			register_setting( self::GROUP, $k, array( 'type' => 'string', 'sanitize_callback' => 'sanitize_hex_color', 'default' => '' ) );
		}
	}

	/** Colour-picker assets on the schedule settings page. */
	public function admin_enqueue( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_script( 'wp-color-picker', 'jQuery(function($){$(".fc-color").wpColorPicker();});' );
	}

	public function s_bool( $v ) { return $v ? 1 : 0; }
	public function s_days( $v ) { return array_values( array_unique( array_map( 'intval', (array) $v ) ) ); }
	public function s_text( $v ) { return sanitize_textarea_field( (string) $v ); }

	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$days   = self::days();
		$labels = array( 0 => __( 'Sun', 'food-customizer' ), 1 => __( 'Mon', 'food-customizer' ), 2 => __( 'Tue', 'food-customizer' ), 3 => __( 'Wed', 'food-customizer' ), 4 => __( 'Thu', 'food-customizer' ), 5 => __( 'Fri', 'food-customizer' ), 6 => __( 'Sat', 'food-customizer' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Delivery schedule', 'food-customizer' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><?php esc_html_e( 'Enable', 'food-customizer' ); ?></th>
						<td><label><input type="checkbox" name="fc_sched_enabled" value="1" <?php checked( self::enabled() ); ?>> <?php esc_html_e( 'Ask for a delivery date & time slot at checkout', 'food-customizer' ); ?></label></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Delivery days', 'food-customizer' ); ?></th>
						<td><?php foreach ( $labels as $i => $lbl ) : ?>
							<label style="display:inline-block;margin:0 14px 6px 0;"><input type="checkbox" name="fc_sched_days[]" value="<?php echo (int) $i; ?>" <?php checked( in_array( $i, $days, true ) ); ?>> <?php echo esc_html( $lbl ); ?></label>
						<?php endforeach; ?>
						<p class="description"><?php esc_html_e( 'Untick a day to stop deliveries on it (e.g. Sunday).', 'food-customizer' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Lead time (days)', 'food-customizer' ); ?></th>
						<td><input type="number" min="0" name="fc_sched_lead" value="<?php echo esc_attr( self::lead() ); ?>" class="small-text">
						<p class="description"><?php esc_html_e( 'Earliest bookable day = today + this many days (prep time). e.g. 1 = from tomorrow.', 'food-customizer' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Booking horizon (days)', 'food-customizer' ); ?></th>
						<td><input type="number" min="1" name="fc_sched_horizon" value="<?php echo esc_attr( self::horizon() ); ?>" class="small-text">
						<p class="description"><?php esc_html_e( 'How far ahead customers can book.', 'food-customizer' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Time slots', 'food-customizer' ); ?></th>
						<td><textarea name="fc_sched_slots" rows="7" class="large-text code" placeholder="11:00 - 12:00"><?php echo esc_textarea( implode( "\n", self::slots() ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One slot per line.', 'food-customizer' ); ?></p></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Block busy dates / slots', 'food-customizer' ); ?></th>
						<td><textarea name="fc_sched_blocked" rows="5" class="large-text code" placeholder="2026-07-27 | 11:00 - 12:00"><?php echo esc_textarea( (string) get_option( 'fc_sched_blocked', '' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One per line. "YYYY-MM-DD | 11:00 - 12:00" blocks that slot; a bare "YYYY-MM-DD" blocks the whole day.', 'food-customizer' ); ?></p></td></tr>
				</table>
				<h2><?php esc_html_e( 'Calendar style', 'food-customizer' ); ?></h2>
				<p class="description" style="margin-top:0;"><?php esc_html_e( 'Colours for the checkout calendar. Leave a field empty to inherit the shop colours.', 'food-customizer' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					$cal_labels = array(
						'fc_cal_bg'          => __( 'Calendar background', 'food-customizer' ),
						'fc_cal_text'        => __( 'Text', 'food-customizer' ),
						'fc_cal_accent'      => __( 'Selected day', 'food-customizer' ),
						'fc_cal_accent_text' => __( 'Text on selected day', 'food-customizer' ),
						'fc_cal_border'      => __( 'Border', 'food-customizer' ),
					);
					foreach ( $cal_labels as $opt => $lbl ) {
						printf(
							'<tr><th scope="row">%s</th><td><input type="text" class="fc-color" name="%s" value="%s" data-default-color="" /></td></tr>',
							esc_html( $lbl ), esc_attr( $opt ), esc_attr( get_option( $opt, '' ) )
						);
					}
					?>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Checkout                                                           */
	/* ------------------------------------------------------------------ */

	public function checkout_field() {
		?>
		<div id="fc-sched" class="fc-sched">
			<h3 class="fc-sched-title"><?php esc_html_e( 'Delivery date & time', 'food-customizer' ); ?></h3>
			<p class="form-row form-row-wide">
				<label for="fc_sched_date_display"><?php esc_html_e( 'Delivery date', 'food-customizer' ); ?> <abbr class="required">*</abbr></label>
				<input type="text" id="fc_sched_date_display" class="input-text" readonly placeholder="<?php esc_attr_e( 'Choose a date', 'food-customizer' ); ?>" autocomplete="off">
				<input type="hidden" id="fc_sched_date" name="fc_sched_date" value="">
			</p>
			<p class="form-row form-row-wide">
				<label for="fc_sched_slot"><?php esc_html_e( 'Delivery time', 'food-customizer' ); ?> <abbr class="required">*</abbr></label>
				<select id="fc_sched_slot" name="fc_sched_slot" class="fc-sched-slot"><option value=""><?php esc_html_e( 'Please choose a time', 'food-customizer' ); ?></option></select>
			</p>
		</div>
		<?php
	}

	public function enqueue() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
			return;
		}
		wp_enqueue_script( 'jquery-ui-datepicker' );
		wp_enqueue_style( 'fc-schedule', FC_URL . 'assets/css/schedule.css', array(), FC_VERSION );
		// Admin-set calendar colours → CSS variables (empty ones inherit shop tokens).
		$decl = '';
		foreach ( self::CAL_COLORS as $opt => $var ) {
			$v = get_option( $opt, '' );
			if ( $v ) {
				$decl .= $var . ':' . $v . ';';
			}
		}
		if ( '' !== $decl ) {
			wp_add_inline_style( 'fc-schedule', ':root{' . $decl . '}' );
		}

		$data = array(
			'days'     => array_values( self::days() ),
			'lead'     => self::lead(),
			'horizon'  => self::horizon(),
			'slots'    => array_values( self::slots() ),
			'blocked'  => (object) self::blocked(),
			'i18n'     => array(
				'choose_slot' => __( 'Please choose a time', 'food-customizer' ),
				'first_day'   => (int) get_option( 'start_of_week', 1 ),
			),
		);
		wp_add_inline_script( 'jquery-ui-datepicker', 'window.FC_SCHED=' . wp_json_encode( $data ) . ';' . self::inline_js() );
	}

	private static function inline_js() {
		return <<<'JS'
jQuery(function($){
	var cfg = window.FC_SCHED || {}; if(!cfg.slots){ return; }
	var $disp = $('#fc_sched_date_display'), $date = $('#fc_sched_date'), $slot = $('#fc_sched_slot');
	if(!$disp.length){ return; }
	function fmt(d){ return $.datepicker.formatDate('yy-mm-dd', d); }
	function fillSlots(ds){
		var bl = (cfg.blocked && cfg.blocked[ds]) || [];
		var cur = $slot.val();
		$slot.empty().append($('<option>').val('').text(cfg.i18n.choose_slot));
		(cfg.slots||[]).forEach(function(s){ if(bl.indexOf(s)===-1 && bl.indexOf('*')===-1){ $slot.append($('<option>').val(s).text(s)); } });
		if(cur){ $slot.val(cur); }
	}
	$disp.datepicker({
		dateFormat:'dd/mm/yy', altField:'#fc_sched_date', altFormat:'yy-mm-dd',
		minDate: cfg.lead, maxDate: cfg.horizon, firstDay: (cfg.i18n.first_day||1),
		beforeShowDay: function(d){
			if((cfg.days||[]).indexOf(d.getDay())===-1){ return [false,'']; }
			var ds = fmt(d), bl = (cfg.blocked && cfg.blocked[ds]) || [];
			if(bl.indexOf('*')!==-1 || (bl.length && bl.length >= (cfg.slots||[]).length)){ return [false,'']; }
			return [true,''];
		},
		onSelect: function(){ fillSlots($date.val()); }
	});
	if($date.val()){ fillSlots($date.val()); }
});
JS;
	}

	/* ------------------------------------------------------------------ */
	/* Validate / save / show                                             */
	/* ------------------------------------------------------------------ */

	public function validate() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$date = isset( $_POST['fc_sched_date'] ) ? sanitize_text_field( wp_unslash( $_POST['fc_sched_date'] ) ) : '';
		$slot = isset( $_POST['fc_sched_slot'] ) ? sanitize_text_field( wp_unslash( $_POST['fc_sched_slot'] ) ) : '';
		// phpcs:enable
		if ( '' === $date || '' === $slot ) {
			wc_add_notice( __( 'Please choose a delivery date and time.', 'food-customizer' ), 'error' );
			return;
		}
		if ( ! self::date_ok( $date ) || ! in_array( $slot, self::slots(), true ) ) {
			wc_add_notice( __( 'That delivery date or time is not available — please pick another.', 'food-customizer' ), 'error' );
			return;
		}
		$blocked = self::blocked();
		if ( in_array( $slot, ( $blocked[ $date ] ?? array() ), true ) ) {
			wc_add_notice( __( 'That delivery date or time is not available — please pick another.', 'food-customizer' ), 'error' );
		}
	}

	public function save( $order, $data ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$date = isset( $_POST['fc_sched_date'] ) ? sanitize_text_field( wp_unslash( $_POST['fc_sched_date'] ) ) : '';
		$slot = isset( $_POST['fc_sched_slot'] ) ? sanitize_text_field( wp_unslash( $_POST['fc_sched_slot'] ) ) : '';
		// phpcs:enable
		if ( $date && $slot ) {
			$order->update_meta_data( '_fc_sched_date', $date );
			$order->update_meta_data( '_fc_sched_slot', $slot );
		}
	}

	public function admin_show( $order ) {
		$date = $order->get_meta( '_fc_sched_date' );
		$slot = $order->get_meta( '_fc_sched_slot' );
		if ( $date && $slot ) {
			echo '<p><strong>' . esc_html__( 'Delivery date & time', 'food-customizer' ) . ':</strong> ' . esc_html( $date . ' · ' . $slot ) . '</p>';
		}
	}

	public function email_show( $fields, $sent_to_admin, $order ) {
		$date = is_object( $order ) ? $order->get_meta( '_fc_sched_date' ) : '';
		$slot = is_object( $order ) ? $order->get_meta( '_fc_sched_slot' ) : '';
		if ( $date && $slot ) {
			$fields['fc_sched'] = array(
				'label' => __( 'Delivery date & time', 'food-customizer' ),
				'value' => $date . ' · ' . $slot,
			);
		}
		return $fields;
	}
}
