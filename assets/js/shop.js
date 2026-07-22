/* Food Customizer — single-page shop: category pills + AJAX grid. */
( function ( $ ) {
	'use strict';
	var D = window.FC_DATA || {};
	var pillDrag = false; // true right after a drag, to swallow the click.

	$( document ).on( 'click', '.fc-shop .fc-pill', function ( e ) {
		e.preventDefault();
		if ( pillDrag ) { return; }
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
		var $buy = $( this ).closest( '.fc-card-buy' );
		var stock = parseInt( $buy.data( 'stock' ), 10 ) || 0;
		var minq = parseInt( $buy.data( 'minqty' ), 10 ) || 1; // catering per-category minimum
		if ( minq < 1 ) { minq = 1; }
		var max = stock > 0 ? stock : 99; // cap at remaining stock for rental/limited items
		var q = parseInt( $val.text(), 10 ) || minq;
		q += $( this ).hasClass( 'fc-cq-plus' ) ? 1 : -1;
		if ( q < minq ) { q = minq; }
		if ( q > max ) { q = max; }
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
					var rq = parseInt( $buy.data( 'minqty' ), 10 ) || 1; if ( rq < 1 ) { rq = 1; }
					setTimeout( function () { $btn.removeClass( 'fc-added' ).text( orig ); $buy.find( '.fc-cq-val' ).text( rq ); }, 1400 );
				} else {
					alert( ( res && res.data && res.data.message ) || ( D.i18n && D.i18n.error ) );
					$btn.removeClass( 'loading' );
				}
			} )
			.fail( function () { alert( D.i18n && D.i18n.error ); $btn.removeClass( 'loading' ); } );
	} );

	// Category pills: drag-to-scroll (grab cursor) + a fade hint when the row overflows.
	function initPills() {
		$( '.fc-pills-wrap' ).each( function () {
			var $wrap = $( this ), $pills = $wrap.find( '.fc-pills' ), el = $pills[ 0 ];
			if ( ! el || $wrap.data( 'fcInit' ) ) { return; }
			$wrap.data( 'fcInit', true );
			function fade() {
				var over = el.scrollWidth - el.clientWidth > 4;
				$wrap.toggleClass( 'fc-scrollable', over )
					.toggleClass( 'fc-at-end', over && el.scrollLeft >= el.scrollWidth - el.clientWidth - 4 );
			}
			fade();
			$pills.on( 'scroll', fade );
			$( window ).on( 'resize', fade );
			var down = false, sx = 0, sl = 0, moved = 0;
			$pills.on( 'mousedown', function ( e ) { down = true; moved = 0; sx = e.pageX; sl = el.scrollLeft; } );
			$( document ).on( 'mousemove', function ( e ) {
				if ( ! down ) { return; }
				var dx = e.pageX - sx; moved += Math.abs( dx );
				if ( moved > 6 ) { $pills.addClass( 'fc-dragging' ); pillDrag = true; }
				el.scrollLeft = sl - dx;
			} );
			$( document ).on( 'mouseup', function () {
				if ( ! down ) { return; }
				down = false; $pills.removeClass( 'fc-dragging' );
				setTimeout( function () { pillDrag = false; }, 30 );
			} );
		} );
	}
	$( initPills );
	$( document.body ).on( 'fc_products_loaded', initPills );
} )( jQuery );
