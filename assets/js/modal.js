/* Food Customizer — customer customization modal (uses FCCore for state). */
( function ( $ ) {
	'use strict';

	var D = window.FC_DATA || {};
	var C = window.FCCore;
	var product = null;   // loaded product config
	var state = null;     // { v, r:[], a:{}, q }

	function esc( s ) { return $( '<div/>' ).text( s == null ? '' : s ).html(); }

	function persist() { if ( product ) { C.save( product.id, state ); } }

	function refreshPrice() {
		var total = C.unitPrice( product, state ) * state.q;
		$( '.fc-modal .fc-final-price' ).html( C.money( total, D ) );
	}

	/* ---- open / render -------------------------------------------------- */

	function open( p ) {
		product = p;
		state = C.getState( p );
		$( '.fc-modal-root' ).remove();
		$( 'body' ).append( buildHtml( p ) ).addClass( 'fc-modal-open' );
		applyState();
		refreshPrice();
	}

	function applyState() {
		// Reflect restored state into the DOM.
		$( 'input[name="fc_variant"][value="' + state.v + '"]' ).prop( 'checked', true );
		state.r.forEach( function ( id ) { $( '.fc-remove-cb[value="' + id + '"]' ).prop( 'checked', true ); } );
		$( '.fc-addon' ).each( function () {
			var id = $( this ).data( 'addon' );
			var q = state.a[ id ] || 0;
			$( this ).find( '.fc-qty' ).text( q );
		} );
		$( '.fc-main-qty' ).text( state.q );
	}

	function buildHtml( p ) {
		var h = '';
		h += '<div class="fc-modal-root"><div class="fc-modal-overlay"></div>';
		h += '<div class="fc-modal" role="dialog" aria-modal="true">';
		h += '<button class="fc-modal-close" aria-label="Close">&times;</button>';
		h += '<div class="fc-modal-media"><img src="' + esc( p.image ) + '" alt="' + esc( p.name ) + '"></div>';
		h += '<div class="fc-modal-body">';
		h += '<h2 class="fc-modal-title">' + esc( p.name ) + '</h2>';
		if ( p.description ) { h += '<div class="fc-modal-desc">' + p.description + '</div>'; }

		if ( p.variants && p.variants.length ) {
			h += '<div class="fc-group"><h3>' + esc( D.i18n.choose || 'ИЗБОР' ) + '</h3>';
			p.variants.forEach( function ( v ) {
				h += '<label class="fc-opt fc-variant"><span>' + esc( v.name ) + '</span>'
					+ '<span class="fc-opt-right"><span class="fc-opt-price">' + C.money( parseFloat( v.price ), D ) + '</span>'
					+ '<input type="radio" name="fc_variant" value="' + esc( v.id ) + '"></span></label>';
			} );
			h += '</div>';
		}
		if ( p.removable && p.removable.length ) {
			h += '<div class="fc-group"><h3>' + esc( D.i18n.without || D.i18n.remove || 'ОПЦИИ "БЕЗ"' ) + '</h3>';
			p.removable.forEach( function ( ing ) {
				h += '<label class="fc-opt fc-remove"><span>' + esc( ing.name ) + '</span>'
					+ '<input type="checkbox" class="fc-remove-cb" value="' + esc( ing.id ) + '"></label>';
			} );
			h += '</div>';
		}
		if ( p.addons && p.addons.length ) {
			h += '<div class="fc-group"><h3>' + esc( D.i18n.extras || 'ОПЦИИ "ОЩЕ"' ) + '</h3>';
			p.addons.forEach( function ( a ) {
				h += '<div class="fc-opt fc-addon" data-addon="' + esc( a.id ) + '" data-max="' + ( parseInt( a.max_qty, 10 ) || 0 ) + '">'
					+ '<span>' + esc( a.name ) + ' <span class="fc-opt-price">+' + C.money( parseFloat( a.price ), D ) + '</span></span>'
					+ '<span class="fc-stepper"><button type="button" class="fc-minus">&minus;</button>'
					+ '<span class="fc-qty">0</span><button type="button" class="fc-plus">+</button></span></div>';
			} );
			h += '</div>';
		}
		if ( p.allergens && p.allergens.length ) {
			h += '<div class="fc-group fc-allergen-group"><h3>' + esc( D.i18n.allergens || 'Allergens' ) + '</h3><div class="fc-allergen-list">';
			p.allergens.forEach( function ( a ) { h += '<span class="fc-allergen-chip">' + esc( a.label ) + '</span>'; } );
			h += '</div></div>';
		}
		h += '</div>'; // body

		h += '<div class="fc-modal-footer">';
		h += '<span class="fc-final-wrap"><small>' + esc( D.i18n.total || 'Final price' ) + '</small><span class="fc-final-price"></span></span>';
		h += '<span class="fc-qty-stepper"><button type="button" class="fc-qminus">&minus;</button><span class="fc-main-qty">1</span><button type="button" class="fc-qplus">+</button></span>';
		h += '<button type="button" class="fc-add-btn button">' + esc( D.i18n.add_to_cart || 'Add to cart' ) + '</button>';
		h += '</div>';
		h += '</div></div>';
		return h;
	}

	function close() { $( '.fc-modal-root' ).remove(); $( 'body' ).removeClass( 'fc-modal-open' ); }

	/* ---- events --------------------------------------------------------- */

	$( document ).on( 'click', '.fc-customize-btn, .fc-quickview', function ( e ) {
		e.preventDefault();
		var id = $( this ).data( 'product-id' );
		var $btn = $( this ).addClass( 'loading' );
		$.post( D.ajax_url, { action: 'fc_get_options', nonce: D.nonce, product_id: id } )
			.done( function ( res ) {
				if ( res && res.success ) { open( res.data ); }
				else { alert( ( res && res.data && res.data.message ) || D.i18n.error ); }
			} )
			.fail( function () { alert( D.i18n.error ); } )
			.always( function () { $btn.removeClass( 'loading' ); } );
	} );

	$( document ).on( 'click', '.fc-modal-close, .fc-modal-overlay', close );

	$( document ).on( 'change', '.fc-modal input[name="fc_variant"]', function () {
		state.v = $( this ).val(); persist(); refreshPrice();
	} );

	$( document ).on( 'change', '.fc-modal .fc-remove-cb', function () {
		var id = $( this ).val();
		state.r = state.r.filter( function ( x ) { return x !== id; } );
		if ( this.checked ) { state.r.push( id ); }
		persist();
	} );

	$( document ).on( 'click', '.fc-modal .fc-addon .fc-plus, .fc-modal .fc-addon .fc-minus', function () {
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

	$( document ).on( 'click', '.fc-modal .fc-qplus, .fc-modal .fc-qminus', function () {
		state.q += $( this ).hasClass( 'fc-qplus' ) ? 1 : -1;
		if ( state.q < 1 ) { state.q = 1; }
		$( '.fc-main-qty' ).text( state.q );
		persist(); refreshPrice();
	} );

	$( document ).on( 'click', '.fc-modal .fc-add-btn', function () {
		var $btn = $( this ).prop( 'disabled', true ).addClass( 'loading' );
		$.post( D.ajax_url, {
			action: 'fc_add_to_cart', nonce: D.nonce,
			product_id: product.id, qty: state.q, variant_id: state.v || '',
			removed: state.r, addons: state.a
		} ).done( function ( res ) {
			if ( res && res.success ) {
				if ( res.data.fragments ) { $.each( res.data.fragments, function ( sel, html ) { $( sel ).replaceWith( html ); } ); }
				$( document.body ).trigger( 'wc_fragment_refresh' );
				C.clear( product.id ); // consumed → reset saved selection
				close();
			} else { alert( ( res && res.data && res.data.message ) || D.i18n.error ); }
		} ).fail( function () { alert( D.i18n.error ); } )
		  .always( function () { $btn.prop( 'disabled', false ).removeClass( 'loading' ); } );
	} );

	$( document ).on( 'keydown', function ( e ) { if ( e.key === 'Escape' ) { close(); } } );

} )( jQuery );
