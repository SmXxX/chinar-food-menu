/* Food Customizer — product-page customizer (product.png layout).
 * Renders from window.FC_PRODUCT, shares selection state with the modal (FCCore). */
( function ( $ ) {
	'use strict';

	var D = window.FC_DATA || {};
	var C = window.FCCore;
	var p = window.FC_PRODUCT || null;
	var state = null;

	function esc( s ) { return $( '<div/>' ).text( s == null ? '' : s ).html(); }
	function persist() { C.save( p.id, state ); }

	/** Has the customer picked every WooCommerce variation option? */
	function wcComplete() {
		if ( ! p.wc || ! p.wc.attributes || ! p.wc.attributes.length ) { return true; }
		return p.wc.attributes.every( function ( a ) { return state.w && state.w[ a.key ]; } );
	}

	function refreshPrice() {
		var $add   = $( '#fc-product-app .fc-pp-add' );
		var $price = $( '#fc-product-app .fc-pp-price' );
		if ( p.available === false ) { return; } // blocked → stays disabled, unavailable msg shown.
		if ( ! wcComplete() ) {
			// Not all options chosen yet: guide the customer one step at a time — show
			// only the NEXT unchosen variation name; as each is picked from the sidebar
			// cards the prompt advances to the next. The price appears once all are set.
			var pending = ( ( p.wc && p.wc.attributes ) || [] ).filter( function ( a ) {
				return ! ( state.w && state.w[ a.key ] );
			} ).map( function ( a ) { return a.name; } );
			$price.removeClass( 'fc-pp-price--num' ).text( pending[ 0 ] || ( ( D.i18n && D.i18n.select_options ) || 'Избери опции' ) );
			$add.prop( 'disabled', true );
			return;
		}
		$add.prop( 'disabled', false );
		var total = C.unitPrice( p, state ) * state.q;
		$price.addClass( 'fc-pp-price--num' ).html( C.money( total, D ) );
	}

	function build() {
		/* LEFT column — built separately so we can detect when it's empty. */
		var left = '';
		if ( p.ingredients && p.ingredients.length ) {
			left += card( D.i18n.ingredients || 'СЪСТАВКИ', '<div class="fc-pp-desc">' + p.ingredients.map( esc ).join( ', ' ) + '</div>', 'fc-pp-ingredients-card' );
		}
		if ( p.variants && p.variants.length ) {
			var vh = '';
			p.variants.forEach( function ( v ) {
				vh += '<label class="fc-opt fc-variant"><span>' + esc( v.name ) + '</span>'
					+ '<span class="fc-opt-right"><span class="fc-opt-price">' + C.money( parseFloat( v.price ), D ) + '</span>'
					+ '<input type="radio" name="fc_pp_variant" value="' + esc( v.id ) + '"></span></label>';
			} );
			left += card( D.i18n.choose || 'ИЗБОР', vh, 'fc-pp-var-card' );
		}
		if ( p.wc && p.wc.attributes && p.wc.attributes.length ) {
			p.wc.attributes.forEach( function ( a ) {
				var wh = '<div class="fc-var-pills">';
				a.options.forEach( function ( o ) {
					var act = ( String( o ) === String( state.w[ a.key ] ) ) ? ' is-active' : '';
					wh += '<button type="button" class="fc-var-pill fc-wc-pill' + act + '" data-key="' + esc( a.key ) + '" data-value="' + esc( o ) + '">' + esc( o ) + '</button>';
				} );
				wh += '</div>';
				left += card( a.name, wh, 'fc-pp-var-card' );
			} );
		}
		if ( p.removable && p.removable.length ) {
			var rh = '';
			p.removable.forEach( function ( ing ) {
				rh += '<label class="fc-opt fc-remove"><span>' + esc( ing.name ) + '</span>'
					+ '<input type="checkbox" class="fc-remove-cb" value="' + esc( ing.id ) + '"></label>';
			} );
			left += collapsible( D.i18n.without || 'ОПЦИИ "БЕЗ"', rh );
		}
		if ( p.addons && p.addons.length ) {
			var ah = '';
			p.addons.forEach( function ( a ) {
				ah += '<div class="fc-opt fc-addon" data-addon="' + esc( a.id ) + '" data-max="' + ( parseInt( a.max_qty, 10 ) || 0 ) + '">'
					+ '<span>' + esc( a.name ) + ' <span class="fc-opt-price">+' + C.money( parseFloat( a.price ), D ) + '</span></span>'
					+ '<span class="fc-stepper"><button type="button" class="fc-minus">&minus;</button>'
					+ '<span class="fc-qty">0</span><button type="button" class="fc-plus">+</button></span></div>';
			} );
			left += collapsible( D.i18n.extras || 'ОПЦИИ "ОЩЕ"', ah );
		}
		if ( p.allergens && p.allergens.length ) {
			var alh = '<div class="fc-allergen-list">';
			p.allergens.forEach( function ( a ) { alh += '<span class="fc-allergen-chip">' + esc( a.label ) + '</span>'; } );
			alh += '</div>';
			left += '<div class="fc-pp-allergens"><h3>' + esc( D.i18n.allergens || 'Allergens' ) + '</h3>' + alh + '</div>';
		}

		var opts = left; // the stacked option cards.

		/* Combine-with (placed at the very bottom). */
		var combine = '';
		if ( p.combine && p.combine.length ) {
			combine += '<div class="fc-combine"><h3 class="fc-combine-title">' + esc( ( D.i18n && D.i18n.combine ) || 'Може да се комбинира с' ) + '</h3>';
			combine += '<div class="fc-combine-slider">';
			p.combine.forEach( function ( c ) {
				var btn = ( c.type === 'variable' )
					? '<button type="button" class="fc-combine-btn fc-var-options-btn" data-product-id="' + esc( c.id ) + '">' + esc( D.i18n.select_options || 'Select options' ) + '</button>'
					: '<button type="button" class="fc-combine-btn fc-combine-add" data-id="' + esc( c.id ) + '">' + esc( D.i18n.add_to_cart || 'Add to cart' ) + '</button>';
				combine += '<div class="fc-combine-card">'
					+ '<a class="fc-combine-link" href="' + esc( c.link ) + '"><span class="fc-combine-img"><img src="' + esc( c.image ) + '" alt="' + esc( c.name ) + '"></span>'
					+ '<span class="fc-combine-name">' + esc( c.name ) + '</span></a>'
					+ '<div class="fc-combine-foot"><span class="fc-combine-price">' + c.price + '</span>' + btn + '</div>'
					+ '</div>';
			} );
			combine += '</div></div>';
		}

		/* Single-column stacked layout: image → buy bar → option cards → combine. */
		var h = '<div class="fc-pp fc-pp--stack">';
		h += '<div class="fc-pp-image"><img src="' + esc( p.image ) + '" alt="' + esc( p.name ) + '"></div>';
		h += '<h1 class="fc-pp-title">' + esc( p.name ) + '</h1>';
		if ( p.weight ) { h += '<div class="fc-pp-weight">' + esc( p.weight ) + ' ' + esc( p.weight_unit ) + '</div>'; }

		var blocked = ( p.available === false );
		h += '<div class="fc-pp-buybar">';
		h += '<div class="fc-pp-final"><small>' + esc( D.i18n.total || 'Final price' ) + '</small><span class="fc-pp-price"></span></div>';
		h += '<div class="fc-qty-stepper"><button type="button" class="fc-qminus">&minus;</button><span class="fc-main-qty">1</span><button type="button" class="fc-qplus">+</button></div>';
		h += '<button type="button" class="fc-pp-add button"' + ( blocked ? ' disabled' : '' ) + '>' + esc( D.i18n.add_to_cart || 'Add to cart' ) + '</button>';
		h += '</div>';
		if ( blocked && p.unavailable_msg ) {
			h += '<div class="fc-pp-unavailable" role="alert">' + esc( p.unavailable_msg ) + '</div>';
		}
		if ( p.stock_note ) {
			h += '<div class="fc-pp-stock">' + esc( p.stock_note ) + '</div>';
		}

		if ( opts ) { h += '<div class="fc-pp-stack-body">' + opts + '</div>'; }
		h += combine;
		h += '</div>';
		return h;
	}

	function card( title, inner, cls ) {
		return '<div class="fc-card-box' + ( cls ? ' ' + cls : '' ) + '"><h3>' + esc( title ) + '</h3>' + inner + '</div>';
	}
	function collapsible( title, inner ) {
		return '<div class="fc-card-box fc-collapsible">'
			+ '<button type="button" class="fc-collapse-head"><span>' + esc( title ) + '</span><span class="fc-chev">&#9662;</span></button>'
			+ '<div class="fc-collapse-body">' + inner + '</div></div>';
	}

	function applyState() {
		var $app = $( '#fc-product-app' );
		$app.find( 'input[name="fc_pp_variant"][value="' + state.v + '"]' ).prop( 'checked', true );
		state.r.forEach( function ( id ) { $app.find( '.fc-remove-cb[value="' + id + '"]' ).prop( 'checked', true ); } );
		$app.find( '.fc-addon' ).each( function () {
			var id = $( this ).data( 'addon' );
			$( this ).find( '.fc-qty' ).text( state.a[ id ] || 0 );
		} );
		$app.find( '.fc-main-qty' ).text( state.q );
	}

	function render() {
		if ( ! p ) { return; }
		state = C.getState( p );
		$( '#fc-product-app' ).html( build() );
		applyState();
		refreshPrice();
	}

	/* ---- events (scoped to #fc-product-app) ----------------------------- */

	$( document ).on( 'change', '#fc-product-app input[name="fc_pp_variant"]', function () {
		state.v = $( this ).val(); persist(); refreshPrice();
	} );
	$( document ).on( 'click', '#fc-product-app .fc-wc-pill', function () {
		var $pills = $( this ).closest( '.fc-var-pills' );
		$pills.find( '.fc-wc-pill' ).removeClass( 'is-active' );
		$( this ).addClass( 'is-active' );
		state.w[ $( this ).data( 'key' ) ] = $( this ).data( 'value' );
		persist(); refreshPrice();
	} );
	$( document ).on( 'change', '#fc-product-app .fc-remove-cb', function () {
		var id = $( this ).val();
		state.r = state.r.filter( function ( x ) { return x !== id; } );
		if ( this.checked ) { state.r.push( id ); }
		persist();
	} );
	$( document ).on( 'click', '#fc-product-app .fc-addon .fc-plus, #fc-product-app .fc-addon .fc-minus', function () {
		var $row = $( this ).closest( '.fc-addon' );
		var id = $row.data( 'addon' );
		var max = parseInt( $row.data( 'max' ), 10 ) || 0;
		var q = state.a[ id ] || 0;
		q += $( this ).hasClass( 'fc-plus' ) ? 1 : -1;
		if ( q < 0 ) { q = 0; }
		if ( max > 0 && q > max ) { q = max; }
		if ( q === 0 ) { delete state.a[ id ]; } else { state.a[ id ] = q; }
		$row.find( '.fc-qty' ).text( q );
		persist(); refreshPrice();
	} );
	$( document ).on( 'click', '#fc-product-app .fc-qplus, #fc-product-app .fc-qminus', function () {
		var mq = ( p.min_qty && p.min_qty > 0 ) ? p.min_qty : 1; // catering per-category minimum
		state.q += $( this ).hasClass( 'fc-qplus' ) ? 1 : -1;
		if ( state.q < mq ) { state.q = mq; }
		if ( p.stock && p.stock > 0 && state.q > p.stock ) { state.q = p.stock; } // cap at remaining stock
		$( '#fc-product-app .fc-main-qty' ).text( state.q );
		persist(); refreshPrice();
	} );
	$( document ).on( 'click', '#fc-product-app .fc-combine-add', function () {
		var $btn = $( this );
		var id = $btn.data( 'id' );
		var orig = $btn.text();
		$btn.addClass( 'loading' );
		$.post( D.ajax_url, { action: 'fc_add_simple', nonce: D.nonce, product_id: id, qty: 1 } )
			.done( function ( res ) {
				if ( res && res.success ) {
					if ( res.data.fragments ) { $.each( res.data.fragments, function ( sel, hh ) { $( sel ).replaceWith( hh ); } ); }
					$( document.body ).trigger( 'wc_fragment_refresh' );
					$btn.removeClass( 'loading' ).addClass( 'fc-added' ).text( ( D.i18n.added || 'Added' ) + ' \u2713' );
					setTimeout( function () { $btn.removeClass( 'fc-added' ).text( orig ); }, 1400 );
				} else {
					alert( ( res && res.data && res.data.message ) || D.i18n.error );
					$btn.removeClass( 'loading' );
				}
			} )
			.fail( function () { alert( D.i18n.error ); $btn.removeClass( 'loading' ); } );
	} );
	$( document ).on( 'click', '#fc-product-app .fc-collapse-head', function () {
		$( this ).closest( '.fc-collapsible' ).toggleClass( 'is-open' );
	} );

	$( document ).on( 'click', '#fc-product-app .fc-pp-add', function () {
		if ( p.available === false || ! wcComplete() ) { return; } // options required.
		var $btn = $( this );
		var orig = $btn.text();
		$btn.prop( 'disabled', true ).addClass( 'loading' );
		$.post( D.ajax_url, {
			action: 'fc_add_to_cart', nonce: D.nonce,
			product_id: p.id, qty: state.q, variant_id: state.v || '',
			removed: state.r, addons: state.a, wc: state.w
		} ).done( function ( res ) {
			if ( res && res.success ) {
				// Stay on the page (no redirect). Refresh the fragments so the floating
				// mini-cart updates, confirm on the button, then restore it so the
				// customer can keep adding.
				if ( res.data && res.data.fragments ) { $.each( res.data.fragments, function ( sel, html ) { $( sel ).replaceWith( html ); } ); }
				$( document.body ).trigger( 'wc_fragment_refresh' );
				$btn.removeClass( 'loading' ).addClass( 'fc-added' ).text( ( D.i18n.added || 'Added' ) + ' ✓' );
				setTimeout( function () { $btn.removeClass( 'fc-added' ).prop( 'disabled', false ).text( orig ); }, 1600 );
			} else {
				alert( ( res && res.data && res.data.message ) || D.i18n.error );
				$btn.prop( 'disabled', false ).removeClass( 'loading' );
			}
		} ).fail( function () {
			alert( D.i18n.error );
			$btn.prop( 'disabled', false ).removeClass( 'loading' );
		} );
	} );

	$( render );

} )( jQuery );
