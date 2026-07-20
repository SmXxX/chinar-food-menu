/* Food Customizer — variable products: "Select options" popup to choose the
 * attributes (dough, sauce, …), see the exact price, and add to cart.
 * Reuses the .fc-modal shell (close handlers live in modal.js). */
( function ( $ ) {
	'use strict';
	var D = window.FC_DATA || {};
	var C = window.FCCore;
	var P = null;     // loaded product data
	var state = {};   // { attributeKey: value }

	function esc( s ) { return $( '<div/>' ).text( s == null ? '' : s ).html(); }

	function findVariation( sel ) {
		return ( P.variations || [] ).filter( function ( v ) {
			return ( P.attributes || [] ).every( function ( a ) {
				var sv = v.attrs[ a.key ];
				return sv === '' || sv === undefined || String( sv ) === String( sel[ a.key ] );
			} );
		} ).sort( function ( x, y ) { return x.price - y.price; } )[ 0 ];
	}

	function refresh() {
		var v = findVariation( state );
		var $add = $( '.fc-modal .fc-varmodal-add' );
		if ( v ) {
			$( '.fc-modal .fc-final-price' ).html( C.money( v.price, D ) );
			$add.attr( 'data-variation-id', v.id ).removeClass( 'is-disabled' );
		} else {
			$( '.fc-modal .fc-final-price' ).html( '&mdash;' );
			$add.attr( 'data-variation-id', '' ).addClass( 'is-disabled' );
		}
	}

	function open( p ) {
		P = p;
		state = $.extend( {}, p.default || {} );
		var h = '<div class="fc-modal-root"><div class="fc-modal-overlay"></div>';
		h += '<div class="fc-modal" role="dialog" aria-modal="true">';
		h += '<button class="fc-modal-close" aria-label="Close">&times;</button>';
		h += '<div class="fc-modal-media"><img src="' + esc( p.image ) + '" alt="' + esc( p.name ) + '"></div>';
		h += '<div class="fc-modal-body"><h2 class="fc-modal-title">' + esc( p.name ) + '</h2>';
		if ( p.description ) { h += '<div class="fc-modal-desc">' + p.description + '</div>'; }
		( p.attributes || [] ).forEach( function ( a ) {
			h += '<div class="fc-group fc-var-group" data-key="' + esc( a.key ) + '"><h3>' + esc( a.name ) + '</h3><div class="fc-var-pills">';
			a.options.forEach( function ( o ) {
				var act = ( String( o ) === String( state[ a.key ] ) ) ? ' is-active' : '';
				h += '<button type="button" class="fc-var-pill' + act + '" data-value="' + esc( o ) + '">' + esc( o ) + '</button>';
			} );
			h += '</div></div>';
		} );
		h += '</div>';
		h += '<div class="fc-modal-footer">';
		h += '<span class="fc-final-wrap"><small>' + esc( D.i18n.total || 'Final price' ) + '</small><span class="fc-final-price"></span></span>';
		h += '<button type="button" class="fc-varmodal-add button">' + esc( D.i18n.add_to_cart || 'Add to cart' ) + '</button>';
		h += '</div></div></div>';
		$( '.fc-modal-root' ).remove();
		$( 'body' ).append( h ).addClass( 'fc-modal-open' );
		refresh();
	}

	// Open the popup
	$( document ).on( 'click', '.fc-var-options-btn', function ( e ) {
		e.preventDefault();
		var id = $( this ).data( 'product-id' );
		var $b = $( this ).addClass( 'loading' );
		$.post( D.ajax_url, { action: 'fc_get_variations', nonce: D.nonce, product_id: id } )
			.done( function ( res ) {
				if ( res && res.success ) { open( res.data ); }
				else { alert( ( res && res.data && res.data.message ) || D.i18n.error ); }
			} )
			.fail( function () { alert( D.i18n.error ); } )
			.always( function () { $b.removeClass( 'loading' ); } );
	} );

	// Choose an option
	$( document ).on( 'click', '.fc-modal .fc-var-group .fc-var-pill', function () {
		var $g = $( this ).closest( '.fc-var-group' );
		$g.find( '.fc-var-pill' ).removeClass( 'is-active' );
		$( this ).addClass( 'is-active' );
		state[ $g.data( 'key' ) ] = $( this ).data( 'value' );
		refresh();
	} );

	// Add selected variation to cart
	$( document ).on( 'click', '.fc-varmodal-add', function () {
		var $btn = $( this );
		var vid = $btn.attr( 'data-variation-id' );
		if ( ! vid ) { return; }
		$btn.addClass( 'loading is-disabled' );
		$.post( D.ajax_url, { action: 'fc_add_variation', nonce: D.nonce, product_id: P.id, variation_id: vid, qty: 1 } )
			.done( function ( res ) {
				if ( res && res.success ) {
					if ( res.data.fragments ) { $.each( res.data.fragments, function ( s, hh ) { $( s ).replaceWith( hh ); } ); }
					$( document.body ).trigger( 'wc_fragment_refresh' );
					$( '.fc-modal-root' ).remove();
					$( 'body' ).removeClass( 'fc-modal-open' );
				} else {
					alert( ( res && res.data && res.data.message ) || D.i18n.error );
					$btn.removeClass( 'loading is-disabled' );
				}
			} )
			.fail( function () { alert( D.i18n.error ); $btn.removeClass( 'loading is-disabled' ); } );
	} );
} )( jQuery );
