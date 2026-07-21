<?php
/**
 * One-time tool: parse each product's "Състав:" description line into the
 * plugin's ingredient list (_food_ingredients) + mark them removable
 * (_food_removable). Only fills products whose ingredient list is still empty,
 * so it never clobbers ingredients configured by hand. Everything it writes is
 * fully editable/deletable afterwards in each product's Food Options box.
 *
 * @package FoodCustomizer
 */

defined( 'ABSPATH' ) || exit;

class FC_Ingredient_Importer {

	public function init() {
		add_action( 'admin_menu', array( $this, 'menu' ), 60 );
		add_action( 'admin_post_fc_import_ingredients', array( $this, 'handle' ) );
	}

	public function menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Import ingredients', 'food-customizer' ),
			__( 'Import ingredients', 'food-customizer' ),
			'manage_woocommerce',
			'fc-import-ingredients',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Parse the "Състав: a, b и c" composition line out of a description.
	 *
	 * @param string $desc Raw product description (may contain HTML).
	 * @return string[] Ingredient names (possibly empty).
	 */
	public static function parse( $desc ) {
		if ( '' === trim( (string) $desc ) ) {
			return array();
		}
		$text = html_entity_decode( wp_strip_all_tags( str_replace( array( "\r\n", "\r" ), "\n", $desc ) ), ENT_QUOTES, 'UTF-8' );
		if ( ! preg_match( '/Състав\s*:\s*(.+)/u', $text, $m ) ) {
			return array();
		}
		$line  = trim( explode( "\n", $m[1] )[0] );
		$line  = trim( $line, " .\t" );
		$parts = preg_split( '/,|\s+и\s+/u', $line );
		$out   = array();
		foreach ( (array) $parts as $p ) {
			$p = trim( $p, " .\t" );
			if ( '' !== $p ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/** All parent products with a parseable Състав line + whether they're already set. */
	private function candidates() {
		$products = wc_get_products( array( 'limit' => -1, 'status' => 'publish', 'return' => 'objects' ) );
		$rows     = array();
		foreach ( $products as $product ) {
			$names = self::parse( $product->get_description() );
			if ( empty( $names ) ) {
				continue;
			}
			// NOTE: (array) '' === array('') (non-empty!), so check for a real array.
			$existing = get_post_meta( $product->get_id(), FC_META_INGREDIENTS, true );
			$rows[]   = array(
				'id'       => $product->get_id(),
				'name'     => $product->get_name(),
				'ings'     => $names,
				'has_prev' => is_array( $existing ) && ! empty( $existing ),
			);
		}
		return $rows;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$rows    = $this->candidates();
		$to_fill = array_filter( $rows, function ( $r ) { return ! $r['has_prev']; } );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import ingredients from descriptions', 'food-customizer' ); ?></h1>
			<?php if ( isset( $_GET['done'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success"><p><?php
					/* translators: 1: filled, 2: skipped (already had ingredients). */
					printf( esc_html__( 'Done. Filled %1$d products; skipped %2$d that already had ingredients.', 'food-customizer' ), (int) $_GET['done'], (int) ( $_GET['skip'] ?? 0 ) ); // phpcs:ignore
				?></p></div>
			<?php endif; ?>
			<p><?php
				/* translators: 1: total with Състав, 2: will be filled. */
				printf( esc_html__( '%1$d products have a "Състав:" line; %2$d of them are still empty and will be filled (ingredients marked removable). The rest already have ingredients and are left untouched. Everything stays editable per product afterwards.', 'food-customizer' ), count( $rows ), count( $to_fill ) );
			?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:14px 0;">
				<input type="hidden" name="action" value="fc_import_ingredients">
				<?php wp_nonce_field( 'fc_import_ingredients' ); ?>
				<button type="submit" class="button button-primary"<?php disabled( empty( $to_fill ) ); ?>><?php esc_html_e( 'Import now', 'food-customizer' ); ?></button>
			</form>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'Product', 'food-customizer' ); ?></th>
					<th><?php esc_html_e( 'Parsed ingredients', 'food-customizer' ); ?></th>
					<th><?php esc_html_e( 'Status', 'food-customizer' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $r['name'] ); ?></strong></td>
						<td><?php echo esc_html( implode( ', ', $r['ings'] ) ); ?></td>
						<td><?php echo $r['has_prev']
							? '<em>' . esc_html__( 'already set — skipped', 'food-customizer' ) . '</em>'
							: '<span style="color:#227122">' . esc_html__( 'will import', 'food-customizer' ) . '</span>'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function handle() {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'fc_import_ingredients' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'food-customizer' ) );
		}
		$filled = 0;
		$skip   = 0;
		$fail   = 0;
		foreach ( $this->candidates() as $r ) {
			if ( $r['has_prev'] ) {
				$skip++;
				continue;
			}
			$ingredients = array();
			$removable   = array();
			foreach ( $r['ings'] as $i => $name ) {
				$id            = 'ing_' . $r['id'] . '_' . $i;
				$ingredients[] = array( 'id' => $id, 'name' => sanitize_text_field( $name ) );
				$removable[]   = $id;
			}
			update_post_meta( $r['id'], FC_META_INGREDIENTS, $ingredients );
			update_post_meta( $r['id'], FC_META_REMOVABLE, $removable );
			// Verify it actually landed (disk-full can make DB writes silently fail).
			$check = (array) get_post_meta( $r['id'], FC_META_INGREDIENTS, true );
			if ( count( $check ) === count( $ingredients ) ) {
				$filled++;
			} else {
				$fail++;
			}
		}
		wp_safe_redirect( add_query_arg(
			array( 'page' => 'fc-import-ingredients', 'done' => $filled, 'skip' => $skip, 'fail' => $fail ),
			admin_url( 'admin.php' )
		) );
		exit;
	}
}
