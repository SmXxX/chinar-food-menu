<?php
/**
 * Admin settings: appearance (colors/borders/radius), editable labels, and the
 * dual-currency toggle. Labels fall back to translatable (__) defaults so they
 * follow the WordPress site language when left blank.
 *
 * @package FoodCustomizer
 */

defined( 'ABSPATH' ) || exit;

class FC_Settings {

	const GROUP = 'fc_settings_group';
	const PAGE  = 'food-customizer';

	/** Design defaults (used when a setting is empty). */
	public static function design_defaults() {
		return array(
			'c_text'        => '#f2ede4',
			'c_muted'       => '#a49e93',
			'c_border'      => '#4a453d',
			'c_border_hov'  => '#b8b2a6',
			'c_surface'     => '#141210',
			'c_panel'       => '#16130f',
			'c_accent'      => '#f2ede4',
			'c_accent_text' => '#14110d',
			'radius'        => 3,
			'radius_box'    => 6,
			'border_width'  => 1,
		);
	}

	/** One design value (setting or default). */
	public static function design( $key ) {
		$d   = self::design_defaults();
		$opt = get_option( 'fc_design', array() );
		$v   = isset( $opt[ $key ] ) && '' !== $opt[ $key ] ? $opt[ $key ] : ( $d[ $key ] ?? '' );
		return $v;
	}

	/** Translatable label defaults. */
	public static function label_defaults() {
		return array(
			'add_to_cart'    => __( 'Add to cart', 'food-customizer' ),
			'select_options' => __( 'Select options', 'food-customizer' ),
			'final_price'    => __( 'Final price', 'food-customizer' ),
			'allergens'      => __( 'Allergens', 'food-customizer' ),
			'ingredients'    => _x( 'Ingredients', 'ingredients heading', 'food-customizer' ),
			'choose'         => _x( 'Choose', 'variant heading', 'food-customizer' ),
			'without'        => _x( 'Remove', 'removable heading', 'food-customizer' ),
			'extras'         => _x( 'Extras', 'addons heading', 'food-customizer' ),
			'combine'        => _x( 'Can be combined with', 'cross-sell heading', 'food-customizer' ),
			'from'           => _x( 'from', 'price prefix', 'food-customizer' ),
			'added'          => __( 'Added', 'food-customizer' ),
			'cutlery'        => _x( 'I want cutlery and napkins', 'checkout cutlery toggle', 'food-customizer' ),
			'del_zone'       => __( 'Delivery zone', 'food-customizer' ),
			'del_zone_choose'=> __( 'Select your zone', 'food-customizer' ),
			'del_covers'     => __( 'Covers', 'food-customizer' ),
			'del_busy'       => _x( 'We are very busy right now and cannot deliver to this area.', 'zone busy message', 'food-customizer' ),
			'del_eta'        => __( 'Estimated delivery', 'food-customizer' ),
			'del_type'       => __( 'Delivery option', 'food-customizer' ),
			'del_door'       => _x( 'Delivery to the door', 'delivery type', 'food-customizer' ),
			'del_entrance'   => _x( 'Delivery to the entrance (courier will call)', 'delivery type', 'food-customizer' ),
			'del_time'       => __( 'Delivery time', 'food-customizer' ),
			'del_asap'       => _x( 'As soon as possible', 'delivery timing', 'food-customizer' ),
			'del_scheduled'  => _x( 'Choose a time', 'delivery timing', 'food-customizer' ),
			'min_order_msg'  => _x( 'The minimum order is %min%. Please add %remaining% more to place your order.', 'minimum order message (%min% and %remaining% are replaced with amounts)', 'food-customizer' ),
			'unavailable_time' => _x( 'This item is available only from %from% to %to%.', 'off-hours message (%from% / %to% are times)', 'food-customizer' ),
		);
	}

	/** One label (admin override, else translatable default). */
	public static function label( $key ) {
		$opt = get_option( 'fc_labels', array() );
		if ( ! empty( $opt[ $key ] ) ) {
			return $opt[ $key ];
		}
		$d = self::label_defaults();
		return $d[ $key ] ?? $key;
	}

	/** All labels resolved (for JS localization). */
	public static function labels() {
		$out = array();
		foreach ( array_keys( self::label_defaults() ) as $k ) {
			$out[ $k ] = self::label( $k );
		}
		return $out;
	}

	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue( $hook ) {
		if ( false === strpos( (string) $hook, self::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		// Group each settings <h2> section into a tab (single form, single Save).
		$js = <<<'JS'
jQuery(function($){
	var $form = $('.wrap form[action="options.php"]');
	if(!$form.length){ $('.fc-color').wpColorPicker(); return; }
	var h2s = $form.children('h2').get();
	if(h2s.length < 2){ $('.fc-color').wpColorPicker(); return; }
	var $nav = $('<h2 class="nav-tab-wrapper" style="margin-bottom:18px;"></h2>');
	$(h2s[0]).before($nav);
	h2s.forEach(function(el,i){
		var $h2 = $(el);
		var id = 'fc-tab-panel-'+i;
		var $group = $h2.add($h2.nextUntil('h2, p.submit'));
		var $panel = $('<div class="fc-tab-panel"></div>').attr('id', id);
		$h2.before($panel);
		$panel.append($group);
		$panel.children('h2').first().hide();
		$nav.append($('<a href="#" class="nav-tab" data-tab="'+id+'"></a>').text($h2.text()));
	});
	function show(id){
		$('.fc-tab-panel').hide();
		$('#'+id).show();
		$nav.find('.nav-tab').removeClass('nav-tab-active');
		$nav.find('[data-tab="'+id+'"]').addClass('nav-tab-active');
	}
	$nav.on('click','.nav-tab',function(e){ e.preventDefault(); show($(this).data('tab')); });
	show('fc-tab-panel-0');
	$('.fc-color').wpColorPicker();
});
JS;
		wp_add_inline_script( 'wp-color-picker', $js );
	}

	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Food Customizer', 'food-customizer' ),
			__( 'Food Customizer', 'food-customizer' ),
			'manage_woocommerce',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	public function register_settings() {
		register_setting( self::GROUP, FC_OPT_DUAL_CURRENCY, array( 'type' => 'boolean', 'sanitize_callback' => array( $this, 'sanitize_bool' ), 'default' => 1 ) );
		register_setting( self::GROUP, 'fc_design', array( 'type' => 'array', 'sanitize_callback' => array( $this, 'sanitize_design' ), 'default' => array() ) );
		register_setting( self::GROUP, 'fc_labels', array( 'type' => 'array', 'sanitize_callback' => array( $this, 'sanitize_labels' ), 'default' => array() ) );
		register_setting( self::GROUP, FC_Cutlery::OPT_ENABLED, array( 'type' => 'boolean', 'sanitize_callback' => array( $this, 'sanitize_bool' ), 'default' => 0 ) );
		register_setting( self::GROUP, FC_Min_Order::OPT, array( 'type' => 'number', 'sanitize_callback' => array( $this, 'sanitize_price' ), 'default' => 0 ) );
		register_setting( self::GROUP, FC_Min_Order::OPT_GATE, array( 'type' => 'boolean', 'sanitize_callback' => array( $this, 'sanitize_bool' ), 'default' => 0 ) );
		register_setting( self::GROUP, 'fc_show_category_tabs', array( 'type' => 'boolean', 'sanitize_callback' => array( $this, 'sanitize_bool' ), 'default' => 1 ) );
		register_setting( self::GROUP, 'fc_show_all_tab', array( 'type' => 'boolean', 'sanitize_callback' => array( $this, 'sanitize_bool' ), 'default' => 1 ) );
		register_setting( self::GROUP, 'fc_default_category', array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => 'all' ) );
		register_setting( self::GROUP, 'fc_hidden_categories', array( 'type' => 'array', 'sanitize_callback' => array( $this, 'sanitize_slug_list' ), 'default' => array( 'uncategorized' ) ) );
		register_setting( self::GROUP, 'fc_category_schedules', array( 'type' => 'array', 'sanitize_callback' => array( $this, 'sanitize_schedules' ), 'default' => array() ) );
		register_setting( self::GROUP, 'fc_layout_direction', array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_direction' ), 'default' => 'ltr' ) );
	}

	public function sanitize_direction( $v ) {
		return ( 'rtl' === $v ) ? 'rtl' : 'ltr';
	}

	/** Product-page layout direction: 'ltr' (options left) or 'rtl' (options right). */
	public static function layout_direction() {
		return ( 'rtl' === get_option( 'fc_layout_direction', 'ltr' ) ) ? 'rtl' : 'ltr';
	}

	public function sanitize_slug_list( $in ) {
		if ( ! is_array( $in ) ) {
			return array();
		}
		return array_values( array_filter( array_map( 'sanitize_title', $in ) ) );
	}

	public function sanitize_schedules( $in ) {
		$out = array();
		if ( ! is_array( $in ) ) {
			return $out;
		}
		foreach ( $in as $slug => $win ) {
			$slug = sanitize_title( $slug );
			if ( '' === $slug || ! is_array( $win ) ) {
				continue;
			}
			$from = $this->sanitize_time( isset( $win['from'] ) ? $win['from'] : '' );
			$to   = $this->sanitize_time( isset( $win['to'] ) ? $win['to'] : '' );
			if ( '' !== $from || '' !== $to ) {
				$out[ $slug ] = array( 'from' => $from, 'to' => $to );
			}
		}
		return $out;
	}

	private function sanitize_time( $v ) {
		$v = trim( (string) $v );
		return preg_match( '/^([01]?\d|2[0-3]):[0-5]\d$/', $v ) ? $v : '';
	}

	public function sanitize_price( $v ) {
		$v = str_replace( ',', '.', (string) $v );
		return max( 0, round( (float) $v, 2 ) );
	}

	public function sanitize_bool( $v ) {
		return empty( $v ) ? 0 : 1;
	}

	public function sanitize_design( $in ) {
		$out = array();
		$in  = (array) $in;
		foreach ( self::design_defaults() as $k => $def ) {
			if ( ! isset( $in[ $k ] ) || '' === $in[ $k ] ) {
				continue;
			}
			if ( in_array( $k, array( 'radius', 'radius_box', 'border_width' ), true ) ) {
				$out[ $k ] = max( 0, min( 60, absint( $in[ $k ] ) ) );
			} else {
				$out[ $k ] = sanitize_hex_color( $in[ $k ] );
			}
		}
		return $out;
	}

	public function sanitize_labels( $in ) {
		$out = array();
		$in  = (array) $in;
		foreach ( array_keys( self::label_defaults() ) as $k ) {
			if ( isset( $in[ $k ] ) && '' !== trim( $in[ $k ] ) ) {
				$out[ $k ] = sanitize_text_field( $in[ $k ] );
			}
		}
		return $out;
	}

	/* --------------------------------------------------------------------- */

	private function color_row( $key, $label ) {
		$val = self::design( $key );
		printf(
			'<tr><th scope="row">%s</th><td><input type="text" class="fc-color" name="fc_design[%s]" value="%s" data-default-color="%s" /></td></tr>',
			esc_html( $label ), esc_attr( $key ), esc_attr( $val ), esc_attr( self::design_defaults()[ $key ] )
		);
	}

	private function num_row( $key, $label ) {
		printf(
			'<tr><th scope="row">%s</th><td><input type="number" min="0" max="60" name="fc_design[%s]" value="%s" class="small-text" /> px</td></tr>',
			esc_html( $label ), esc_attr( $key ), esc_attr( self::design( $key ) )
		);
	}

	private function label_row( $key, $desc ) {
		$opt = get_option( 'fc_labels', array() );
		printf(
			'<tr><th scope="row">%s</th><td><input type="text" class="regular-text" name="fc_labels[%s]" value="%s" placeholder="%s" /><p class="description">%s</p></td></tr>',
			esc_html( ucwords( str_replace( '_', ' ', $key ) ) ),
			esc_attr( $key ),
			esc_attr( $opt[ $key ] ?? '' ),
			esc_attr( self::label_defaults()[ $key ] ?? '' ),
			esc_html( $desc )
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$dual = (bool) get_option( FC_OPT_DUAL_CURRENCY, 1 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Food Customizer', 'food-customizer' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>

				<h2><?php esc_html_e( 'Colours', 'food-customizer' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->color_row( 'c_text', __( 'Text', 'food-customizer' ) );
					$this->color_row( 'c_muted', __( 'Muted text', 'food-customizer' ) );
					$this->color_row( 'c_border', __( 'Border', 'food-customizer' ) );
					$this->color_row( 'c_border_hov', __( 'Border (hover)', 'food-customizer' ) );
					$this->color_row( 'c_surface', __( 'Card background', 'food-customizer' ) );
					$this->color_row( 'c_panel', __( 'Popup background', 'food-customizer' ) );
					$this->color_row( 'c_accent', __( 'Accent (selected)', 'food-customizer' ) );
					$this->color_row( 'c_accent_text', __( 'Text on accent', 'food-customizer' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Borders & shape', 'food-customizer' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					$this->num_row( 'radius', __( 'Corner radius (buttons/pills)', 'food-customizer' ) );
					$this->num_row( 'radius_box', __( 'Corner radius (cards/boxes)', 'food-customizer' ) );
					$this->num_row( 'border_width', __( 'Border thickness', 'food-customizer' ) );
					?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Product page layout', 'food-customizer' ); ?></th>
							<td>
								<select name="fc_layout_direction">
									<option value="ltr" <?php selected( 'ltr', self::layout_direction() ); ?>><?php esc_html_e( 'Options left, image right (default)', 'food-customizer' ); ?></option>
									<option value="rtl" <?php selected( 'rtl', self::layout_direction() ); ?>><?php esc_html_e( 'Options right, image left', 'food-customizer' ); ?></option>
								</select>
								<p class="description"><?php esc_html_e( 'Which side the option cards appear on (desktop only \u2014 on mobile it is always a single column).', 'food-customizer' ); ?></p>
							</td>
						</tr>
				</table>

				<h2><?php esc_html_e( 'Texts', 'food-customizer' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Leave a field blank to use the default, which follows your WordPress site language (translatable via WPML / Loco Translate).', 'food-customizer' ); ?></p>
				<table class="form-table" role="presentation">
					<?php
					foreach ( array_keys( self::label_defaults() ) as $k ) {
						$this->label_row( $k, '' );
					}
					?>
				</table>

				<h2><?php esc_html_e( 'Menu', 'food-customizer' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Category tabs', 'food-customizer' ); ?></th>
						<td>
							<label><input type="checkbox" name="fc_show_category_tabs" value="1" <?php checked( (bool) get_option( 'fc_show_category_tabs', 1 ), true ); ?> /> <?php esc_html_e( 'Show the category tabs on the shop', 'food-customizer' ); ?></label><br />
							<label><input type="checkbox" name="fc_show_all_tab" value="1" <?php checked( (bool) get_option( 'fc_show_all_tab', 1 ), true ); ?> /> <?php esc_html_e( 'Show the "All" tab', 'food-customizer' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Default category', 'food-customizer' ); ?></th>
						<td>
							<?php
							$def_cat = (string) get_option( 'fc_default_category', 'all' );
							$terms   = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => true ) );
							?>
							<select name="fc_default_category">
								<option value="all" <?php selected( 'all', $def_cat ); ?>><?php esc_html_e( 'All', 'food-customizer' ); ?></option>
								<?php if ( ! is_wp_error( $terms ) ) : foreach ( $terms as $t ) : ?>
									<option value="<?php echo esc_attr( $t->slug ); ?>" <?php selected( $t->slug, $def_cat ); ?>><?php echo esc_html( $t->name ); ?></option>
								<?php endforeach; endif; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Which category is shown first when the shop loads.', 'food-customizer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Hide categories', 'food-customizer' ); ?></th>
						<td>
							<?php
							$hidden_cats = (array) get_option( 'fc_hidden_categories', array( 'uncategorized' ) );
							if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) :
								foreach ( $terms as $t ) :
									?>
									<label style="display:inline-block;min-width:200px;margin:2px 14px 2px 0;"><input type="checkbox" name="fc_hidden_categories[]" value="<?php echo esc_attr( $t->slug ); ?>" <?php checked( in_array( $t->slug, $hidden_cats, true ), true ); ?> /> <?php echo esc_html( $t->name ); ?></label>
									<?php
								endforeach;
							endif;
							?>
							<p class="description"><?php esc_html_e( 'Ticked categories are removed from the shop — no tab, and their products are excluded from "All". "Uncategorized" is hidden by default.', 'food-customizer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Available by time', 'food-customizer' ); ?></th>
						<td>
							<p class="description" style="margin-top:0;"><?php esc_html_e( 'Optional daily window per category (e.g. lunch 11:30–15:30). Outside the window the tab and its products are hidden. Leave blank = always available. Uses your WordPress timezone.', 'food-customizer' ); ?></p>
							<?php
							$schedules = (array) get_option( 'fc_category_schedules', array() );
							if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) :
								foreach ( $terms as $t ) :
									$s_from = isset( $schedules[ $t->slug ]['from'] ) ? $schedules[ $t->slug ]['from'] : '';
									$s_to   = isset( $schedules[ $t->slug ]['to'] ) ? $schedules[ $t->slug ]['to'] : '';
									?>
									<div style="margin:4px 0;">
										<span style="display:inline-block;min-width:180px;"><?php echo esc_html( $t->name ); ?></span>
										<?php esc_html_e( 'From', 'food-customizer' ); ?>
										<input type="time" name="fc_category_schedules[<?php echo esc_attr( $t->slug ); ?>][from]" value="<?php echo esc_attr( $s_from ); ?>" />
										<?php esc_html_e( 'to', 'food-customizer' ); ?>
										<input type="time" name="fc_category_schedules[<?php echo esc_attr( $t->slug ); ?>][to]" value="<?php echo esc_attr( $s_to ); ?>" />
									</div>
									<?php
								endforeach;
							endif;
							?>
						</td>
					</tr>
				</table>

				<h2><?php esc_html_e( 'General', 'food-customizer' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Dual currency', 'food-customizer' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( FC_OPT_DUAL_CURRENCY ); ?>" value="1" <?php checked( $dual, true ); ?> /> <?php esc_html_e( 'Show EUR with BGN in parentheses', 'food-customizer' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Minimum order total', 'food-customizer' ); ?></th>
						<td>
							<input type="number" step="0.01" min="0" name="<?php echo esc_attr( FC_Min_Order::OPT ); ?>" value="<?php echo esc_attr( FC_Min_Order::minimum() ); ?>" class="small-text" /> &euro;
							<p class="description"><?php esc_html_e( 'Orders below this amount (EUR) show a message and cannot be completed. 0 = no minimum. Edit the message ("Min order msg") in the Texts section — %min% and %remaining% are replaced with the amounts.', 'food-customizer' ); ?></p>
							<p style="margin-top:10px;"><label><input type="checkbox" name="<?php echo esc_attr( FC_Min_Order::OPT_GATE ); ?>" value="1" <?php checked( FC_Min_Order::gate_enabled(), true ); ?> /> <?php esc_html_e( 'Block access to checkout below the minimum', 'food-customizer' ); ?></label>
							<span class="description"> — <?php esc_html_e( 'hides the "Proceed to checkout" button until the minimum is met. When off, checkout stays open and only the final order is blocked.', 'food-customizer' ); ?></span></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Cutlery on checkout', 'food-customizer' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( FC_Cutlery::OPT_ENABLED ); ?>" value="1" <?php checked( FC_Cutlery::is_enabled(), true ); ?> /> <?php esc_html_e( 'Show a "cutlery & napkins" checkbox with a quantity stepper on the checkout page', 'food-customizer' ); ?></label>
							<?php
							if ( FC_Cutlery::is_enabled() ) {
								$cid = FC_Cutlery::get_product_id();
								if ( $cid ) {
									printf(
										'<p class="description">%s <a href="%s" target="_blank">%s</a></p>',
										esc_html__( 'Edit the cutlery product (name / price):', 'food-customizer' ),
										esc_url( get_edit_post_link( $cid ) ),
										esc_html( get_the_title( $cid ) )
									);
								}
							} else {
								printf( '<p class="description">%s</p>', esc_html__( 'A hidden product is created automatically the first time you enable this; edit its name/price in Products afterwards. The label text is editable in the Texts section above.', 'food-customizer' ) );
							}
							?>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
