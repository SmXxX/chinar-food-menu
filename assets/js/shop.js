/* Food Customizer — single-page shop: category pills + AJAX grid. */
( function ( $ ) {
	'use strict';
	var D = window.FC_DATA || {};

	$( document ).on( 'click', '.fc-shop .fc-pill', function ( e ) {
		e.preventDefault();
		var $pill = $( this );
		var $shop = $pill.closest( '.fc-shop' );
		var $grid = $shop.find( '.fc-grid' );
		var cat = $pill.data( 'cat' );

		if ( $pill.hasClass( 'is-active' ) ) { return; }
		$shop.find( '.fc-pill' ).removeClass( 'is-active' );
		$pill.addClass( 'is-active' );
		$grid.addClass( 'fc-loading' );

		$.post( D.ajax_url, {
			action: 'fc_load_products',
			nonce: D.nonce,
			cat: cat,
			limit: $shop.data( 'limit' ) || 100
		} ).done( function ( res ) {
			if ( res && res.success ) {
				$grid.html( res.data.html );
				$( document.body ).trigger( 'fc_products_loaded' );
			}
		} ).always( function () {
			$grid.removeClass( 'fc-loading' );
		} );
	} );

	// Card quantity stepper.
	$( document ).on( 'click', '.fc-card-qty .fc-cq-plus, .fc-card-qty .fc-cq-minus', function () {
		var $val = $( this ).siblings( '.fc-cq-val' );
		var q = parseInt( $val.text(), 10 ) || 1;
		q += $( this ).hasClass( 'fc-cq-plus' ) ? 1 : -1;
		if ( q < 1 ) { q = 1; }
		if ( q > 99 ) { q = 99; }
		$val.text( q );
	} );

	// Card add-to-cart (simple products) with a clear "added" confirmation.
	$( document ).on( 'click', '.fc-card-add', function () {
		var $btn = $( this );
		if ( $btn.hasClass( 'loading' ) ) { return; }
		var id   = $btn.data( 'product-id' );
		var $buy = $btn.closest( '.fc-card-buy' );
		var qty  = parseInt( $buy.find( '.fc-cq-val' ).text(), 10 ) || 1;
		var orig = $btn.text();
		$btn.addClass( 'loading' );
		$.post( D.ajax_url, { action: 'fc_add_simple', nonce: D.nonce, product_id: id, qty: qty } )
			.done( function ( res ) {
				if ( res && res.success ) {
					if ( res.data.fragments ) { $.each( res.data.fragments, function ( sel, html ) { $( sel ).replaceWith( html ); } ); }
					$( document.body ).trigger( 'wc_fragment_refresh' );
					$btn.removeClass( 'loading' ).addClass( 'fc-added' ).text( ( ( D.i18n && D.i18n.added ) || 'Added' ) + ' ✓' );
					setTimeout( function () { $btn.removeClass( 'fc-added' ).text( orig ); $buy.find( '.fc-cq-val' ).text( '1' ); }, 1400 );
				} else {
					alert( ( res && res.data && res.data.message ) || ( D.i18n && D.i18n.error ) );
					$btn.removeClass( 'loading' );
				}
			} )
			.fail( function () { alert( D.i18n && D.i18n.error ); $btn.removeClass( 'loading' ); } );
	} );
} )( jQuery );
