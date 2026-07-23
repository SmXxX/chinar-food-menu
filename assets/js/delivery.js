/* Food Customizer — checkout delivery zone / options logic. */
( function ( $ ) {
	'use strict';

	var D     = window.FC_DELIVERY || {};
	var zones = D.zones || [];

	function selectedZone() {
		var v = $( '#fc_delivery_zone' ).val();
		return ( v !== '' && zones[ v ] ) ? zones[ v ] : null;
	}

	function setPlaceOrder( enabled ) {
		$( '#place_order' ).prop( 'disabled', ! enabled ).toggleClass( 'fc-del-blocked', ! enabled );
	}

	// Turn the street <select> into a searchable Select2 (WooCommerce's selectWoo).
	function initStreetSelect() {
		var $s = $( '#fc_delivery_street' );
		if ( ! $s.length || $s.data( 'select2' ) ) { return; }
		var opts = { width: '100%', placeholder: $s.data( 'placeholder' ) || '', allowClear: true };
		try {
			if ( $.fn.selectWoo ) { $s.selectWoo( opts ); }
			else if ( $.fn.select2 ) { $s.select2( opts ); }
		} catch ( e ) {}
	}

	// Resolve the zone from the chosen street and write it to the hidden zone field.
	function resolveZone() {
		var street = $( '#fc_delivery_street' ).val();
		var zi = ( street && D.streetZone && ( street in D.streetZone ) ) ? D.streetZone[ street ] : '';
		$( '#fc_delivery_zone' ).val( zi === undefined || zi === null ? '' : zi );
	}

	function refresh() {
		var z = selectedZone();
		var $info = $( '.fc-del-info' );
		var $busy = $( '.fc-del-busy' );

		if ( ! z ) {
			$info.prop( 'hidden', true );
			$busy.prop( 'hidden', true ).text( '' );
			setPlaceOrder( true );
			return;
		}

		// Resolved zone name + ETA + delivery price.
		$( '.fc-del-zone-val' ).text( z.name || '' );
		$( '.fc-del-eta-val' ).text( z.eta || '' );
		$( '.fc-del-eta' ).toggle( !! z.eta );
		var priceTxt = ( D.prices && D.prices[ $( '#fc_delivery_zone' ).val() ] ) ? D.prices[ $( '#fc_delivery_zone' ).val() ] : '';
		$( '.fc-del-price-val' ).text( priceTxt || D.freeLabel || '' );
		$info.prop( 'hidden', false );
		$( '.fc-del-asap-eta' ).text( z.eta ? '(' + z.eta + ')' : '' );

		// Busy → show message + block ordering.
		if ( z.busy ) {
			$busy.text( z.busy_msg || D.busyDefault || '' ).prop( 'hidden', false );
			setPlaceOrder( false );
		} else {
			$busy.prop( 'hidden', true ).text( '' );
			setPlaceOrder( true );
		}
	}

	function timeMode() {
		var scheduled = $( 'input[name="fc_delivery_time_mode"]:checked' ).val() === 'scheduled';
		$( '.fc-del-time-input' ).prop( 'hidden', ! scheduled );
	}

	// Place the block at the top of the order (right) column when the checkout is
	// laid out in two floated columns; otherwise (single-column / mobile) leave it
	// where it is. This never breaks the theme grid.
	function placeInOrderColumn() {
		var $del  = $( '#fc-delivery' );
		var $rev  = $( '#order_review' );
		var $head = $( '#order_review_heading' );
		if ( ! $del.length || ! $rev.length ) { return; }
		var fl = $rev.css( 'float' );
		if ( fl === 'right' || fl === 'left' ) {
			if ( $head.length ) { $del.insertBefore( $head ); } else { $del.insertBefore( $rev ); }
			$del.css( {
				'float': fl,
				'clear': fl,
				'width': $rev.outerWidth() + 'px',
				'box-sizing': 'border-box',
				'margin-top': '0'
			} );
		} else {
			// Single column: drop the inline float/width so it spans normally.
			$del.css( { 'float': '', 'clear': '', 'width': '', 'margin-top': '' } );
		}
	}

	// On street change: resolve the zone, update the panel, recalc totals (fee).
	$( document ).on( 'change', '#fc_delivery_street', function () {
		resolveZone();
		refresh();
		$( document.body ).trigger( 'update_checkout' );
	} );
	$( document ).on( 'change', 'input[name="fc_delivery_time_mode"]', timeMode );
	$( window ).on( 'resize', placeInOrderColumn );

	// #place_order lives in the payment column, which WooCommerce re-renders on
	// update_checkout — re-apply the busy state afterwards.
	$( document.body ).on( 'updated_checkout', function () { initStreetSelect(); refresh(); placeInOrderColumn(); } );

	$( function () { initStreetSelect(); placeInOrderColumn(); resolveZone(); refresh(); timeMode(); } );

} )( jQuery );
