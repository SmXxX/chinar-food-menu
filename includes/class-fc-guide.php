<?php
/**
 * In-plugin help page: Food Customizer → Guide.
 * A self-contained how-to covering the menu, per-product options, settings,
 * delivery, checkout extras, the ingredient importer, and re-using the plugin
 * on another theme. Plain admin documentation (not front-end, not translated).
 *
 * @package FoodCustomizer
 */

defined( 'ABSPATH' ) || exit;

class FC_Guide {

	public function init() {
		add_action( 'admin_menu', array( $this, 'menu' ), 55 );
	}

	public function menu() {
		add_submenu_page(
			'food-customizer',
			'Food Customizer — Guide',
			__( 'Guide', 'food-customizer' ),
			'manage_woocommerce',
			'fc-guide',
			array( $this, 'render' )
		);
	}

	public function render() {
		$settings = admin_url( 'admin.php?page=food-customizer' );
		$import   = admin_url( 'admin.php?page=fc-import-ingredients' );
		$zones    = admin_url( 'admin.php?page=fc-delivery-zones' );
		$products = admin_url( 'edit.php?post_type=product' );
		?>
		<div class="wrap fc-guide" style="max-width:900px;">
			<h1 style="margin-bottom:4px;">Food Customizer — Guide</h1>
			<p style="font-size:14px;color:#555;margin-top:0;">A single self-contained plugin that turns WooCommerce into a food-ordering menu: a one-page menu with category tabs, a per-product customizer (ingredients, remove/add options, sizes, allergens), delivery zones, and dual-currency (EUR + BGN) display. Everything below is configurable — no code needed.</p>

			<style>
				.fc-guide h2{margin-top:34px;border-bottom:1px solid #dcdcde;padding-bottom:6px;}
				.fc-guide .fc-card{background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px 18px;margin:12px 0;}
				.fc-guide code{background:#f0f0f1;padding:2px 6px;border-radius:3px;}
				.fc-guide ol,.fc-guide ul{margin-left:18px;}
				.fc-guide li{margin:5px 0;}
				.fc-guide .fc-tag{display:inline-block;background:#dd5903;color:#fff;font-size:11px;font-weight:600;padding:1px 8px;border-radius:10px;vertical-align:middle;}
			</style>

			<div class="fc-card">
				<strong>Jump to:</strong>
				&nbsp;<a href="#menu">Menu page</a> ·
				<a href="#product">Per-product options</a> ·
				<a href="#settings">Settings</a> ·
				<a href="#delivery">Delivery</a> ·
				<a href="#checkout">Checkout extras</a> ·
				<a href="#import">Ingredient importer</a> ·
				<a href="#theme">Other themes</a>
			</div>

			<h2 id="menu">1. The menu page</h2>
			<div class="fc-card">
				<p>Create (or pick) a page and add the shortcode:</p>
				<p><code>[fc_shop]</code></p>
				<ul>
					<li>Renders a row of <strong>category tabs</strong> + a product grid that loads by category with no page reload.</li>
					<li>Optional attributes: <code>[fc_shop columns="3" limit="100" cat="salati"]</code>.</li>
					<li>Set this page as your <strong>front page</strong> and/or the WooCommerce <strong>Shop</strong> page (the default WooCommerce product grid is automatically hidden so only your menu shows).</li>
					<li><strong>Simple products</strong> get a quantity stepper + “Add” right on the card. <strong>Products with options or variations</strong> show “Select options”, which opens the product page.</li>
				</ul>
			</div>

			<h2 id="product">2. Per-product options (the customizer)</h2>
			<div class="fc-card">
				<p>Edit any product (<a href="<?php echo esc_url( $products ); ?>">Products</a>) and open the <strong>Food Options</strong> box:</p>
				<ul>
					<li><strong>Ingredients</strong> — the composition list, shown as <em>СЪСТАВКИ</em> on the product page. Tick “removable” to let customers order “<em>без X</em>” (appears under the <em>Remove</em> section).</li>
					<li><strong>Paid additions</strong> — name + price (+ optional max quantity). Customers add them with a stepper; the price updates live and is charged server-side.</li>
					<li><strong>Size / variant radios</strong> — optional per-product price options (separate from WooCommerce variations).</li>
					<li><strong>Allergens</strong> — pick from the fixed EU-14 list; shown as info chips.</li>
				</ul>
				<p><strong>WooCommerce variations</strong> (e.g. pizza dough/sauce) work too — the customer must pick each one before adding; the price appears once all are chosen. The standard WooCommerce detail tabs (Description / Additional info / Reviews) show at the bottom of the page.</p>
				<p>Products with <em>nothing</em> to customize simply use the normal WooCommerce product page.</p>
			</div>

			<h2 id="settings">3. Global settings <span class="fc-tag">Food Customizer</span></h2>
			<div class="fc-card">
				<p>The <strong>Food Customizer → <a href="<?php echo esc_url( $settings ); ?>">Settings</a></strong> page (its own top-level menu in the sidebar) has these tabs:</p>
				<ul>
					<li><strong>Colours</strong> — text, borders, surfaces, accent (buttons/active tabs).</li>
					<li><strong>Borders &amp; shape</strong> — corner radius, border thickness, and desktop layout side (options left/right).</li>
					<li><strong>Layout (theme fit)</strong> — product-page background, header top-padding, floating-cart offset (see “Other themes” below).</li>
					<li><strong>Fonts</strong> — 9 text sizes + heading/body font families, and a toggle to load the bundled fonts from Google.</li>
					<li><strong>Texts</strong> — every visible label (buttons, section titles, delivery, cutlery…). Leave blank to use the translated default. <em>Tip: the “Remove” and “Extras” labels control the two option sections — don’t swap them.</em></li>
					<li><strong>Menu</strong> — show/hide category tabs, default category, an “All” tab, hide specific categories, drag to reorder tabs, and time-based availability (e.g. lunch 11:30–15:30 — outside the window those products can’t be ordered).</li>
					<li><strong>General</strong> — dual-currency display (EUR + BGN), minimum order total (+ optional block of checkout below it), and the cutlery toggle.</li>
				</ul>
			</div>

			<h2 id="delivery">4. Delivery zones <span class="fc-tag">Зони за доставка</span></h2>
			<div class="fc-card">
				<p><a href="<?php echo esc_url( $zones ); ?>">Food Customizer → Delivery zones (Зони за доставка)</a>: add zones, each with the areas/streets it covers, an ETA, and a <strong>Busy</strong> toggle (busy zones show a message and block checkout). On checkout the customer picks their zone, chooses delivery to the door or entrance, and ASAP or a scheduled time. The choice is saved on the order and shown in admin/emails. Enable it with the master toggle on that page.</p>
			</div>

			<h2 id="checkout">5. Checkout extras</h2>
			<div class="fc-card">
				<ul>
					<li><strong>Cutlery &amp; napkins</strong> — a checkbox + quantity on checkout (off by default). Backed by a real hidden product you can rename/price in Products.</li>
					<li><strong>Minimum order</strong> — set an amount in General; the cart/checkout show how much more is needed, and the order is blocked below it.</li>
				</ul>
			</div>

			<h2 id="import">6. Import ingredients from descriptions <span class="fc-tag">Import ingredients</span></h2>
			<div class="fc-card">
				<p><a href="<?php echo esc_url( $import ); ?>">Food Customizer → Import ingredients</a>: if your product descriptions contain a “<em>Състав:</em>” line, this reads them and fills the Ingredients list (marked removable) in one click. It previews first, only fills products that are still empty, and everything stays editable afterwards.</p>
			</div>

			<h2 id="theme">7. Using the plugin on another theme</h2>
			<div class="fc-card">
				<p>The design is token-based, so re-theming is done entirely in <strong>Settings</strong> — no code:</p>
				<ol>
					<li>Set <strong>Colours</strong> / <strong>Fonts</strong> / <strong>Borders</strong> to match the new theme.</li>
					<li>In <strong>Layout (theme fit)</strong>: set the <strong>Product-page background</strong> (leave empty to inherit the theme; pick a light colour for a light theme), the <strong>Content top padding</strong> (often <code>0</code> unless the theme has a fixed/overlapping header), and the <strong>Floating cart offset</strong> (raise it to clear a back-to-top button).</li>
					<li>If the theme doesn’t already load them, turn on <strong>“Load Cormorant Garamond + Jost from Google Fonts”</strong> in Fonts — or set your own font families.</li>
				</ol>
				<p>The menu shortcode, customizer, cart, checkout and delivery all use standard WooCommerce hooks, so they work on any WooCommerce-compatible theme.</p>
			</div>

			<h2>Good to know</h2>
			<div class="fc-card">
				<ul>
					<li>All data lives in WooCommerce (products, categories, orders) and product meta — nothing is locked inside the plugin.</li>
					<li>After changing settings, hard-refresh the front end (Cmd/Ctrl+Shift+R) if a page cache is active.</li>
					<li>Prices for add-ons/variants are always recalculated on the server — client totals are never trusted.</li>
				</ul>
			</div>
		</div>
		<?php
	}
}
