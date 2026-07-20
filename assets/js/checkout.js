/* Food Customizer — checkout cutlery toggle (adds/removes a real product line). */
( function ( $ ) {
	'use strict';

	var C    = window.FC_CUTLERY || {};
	var max  = parseInt( C.max, 10 ) || 20;
	var busy = false;

	function ctrl() { return $( '.fc-cutlery' ); }
	function qtyVal() { return parseInt( $( '.fc-cutlery-qty' ).first().text(), 10 ) || 1; }

	// Enable/disable the control while a request is in flight. Never leaves it stuck:
	// we always clear this in .always(), independent of WooCommerce's re-render.
	function setBusy( on ) {
		busy = on;
		ctrl().toggleClass( 'fc-cutlery--busy', on );
		ctrl().find( 'input, button' ).prop( 'disabled', on );
	}

	function sync( checked, qty ) {
		if ( busy ) { return; }
		setBusy( true );
		$.post( C.ajax_url, {
			action: 'fc_set_cutlery',
			nonce: C.nonce,
			checked: checked ? 1 : 0,
			qty: qty
		} ).always( function () {
			setBusy( false );
			// Refresh the order totals (matters when the cutlery product has a price).
			$( document.body ).trigger( 'update_checkout' );
		} );
	}

	$( document ).on( 'change', '.fc-cutlery-cb', function () {
		var checked = $( this ).is( ':checked' );
		$( '.fc-cutlery-stepper' ).prop( 'hidden', ! checked );
		sync( checked, qtyVal() );
	} );

	$( document ).on( 'click', '.fc-cutlery-plus, .fc-cutlery-minus', function ( e ) {
		e.preventDefault();
		if ( busy ) { return; }
		var q = qtyVal();
		q += $( this ).hasClass( 'fc-cutlery-plus' ) ? 1 : -1;
		if ( q < 1 ) { q = 1; }
		if ( q > max ) { q = max; }
		$( '.fc-cutlery-qty' ).text( q );
		if ( $( '.fc-cutlery-cb' ).is( ':checked' ) ) {
			sync( true, q );
		}
	} );

	// Safety net: whenever WooCommerce finishes re-rendering the review, make sure
	// the control is never left disabled.
	$( document.body ).on( 'updated_checkout', function () {
		busy = false;
		ctrl().removeClass( 'fc-cutlery--busy' );
		ctrl().find( 'input, button' ).prop( 'disabled', false );
	} );

} )( jQuery );
