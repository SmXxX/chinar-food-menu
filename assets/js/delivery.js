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

	/* ---------- clickable zone map ---------- */
	var map = null, zoneLayers = {}, suppressStreet = false;

	function highlightZone( zi ) {
		Object.keys( zoneLayers ).forEach( function ( k ) {
			var on = ( String( k ) === String( zi ) );
			zoneLayers[ k ].setStyle( { fillOpacity: on ? 0.72 : 0.32, weight: on ? 3 : 1.5, color: on ? '#111' : '#fff' } );
		} );
	}

	// Load the chosen zone's streets into the searchable dropdown.
	function fillStreets( zi ) {
		var streets = ( D.streets && D.streets[ zi ] ) ? D.streets[ zi ] : [];
		var $s = $( '#fc_delivery_street' );
		if ( ! $s.length ) { return; }
		var cur = $s.val();
		suppressStreet = true;
		$s.empty().append( '<option value=""></option>' );
		streets.forEach( function ( st ) { $s.append( $( '<option>' ).val( st ).text( st ) ); } );
		$s.val( cur && streets.indexOf( cur ) !== -1 ? cur : '' );
		$s.trigger( 'change.select2' ); // refresh the Select2 display without firing our handler
		suppressStreet = false;
	}

	function pickZone( zi ) {
		$( '#fc_delivery_zone' ).val( zi );
		fillStreets( zi );
		highlightZone( zi );
		refresh();
		$( document.body ).trigger( 'update_checkout' );
	}

	function initMap() {
		var el = document.getElementById( 'fc-del-map' );
		if ( ! el || map || typeof L === 'undefined' || ! D.mapFeatures || ! D.mapFeatures.length ) { return; }
		map = L.map( el, { scrollWheelZoom: false } );
		L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' } ).addTo( map );
		var group = [];
		D.mapFeatures.forEach( function ( f ) {
			var layer = L.polygon( f.r, { color: '#fff', weight: 1.5, fillColor: f.color, fillOpacity: 0.4, lineJoin: 'round' } ).addTo( map );
			layer.bindTooltip( f.name, { sticky: true } );
			layer.on( 'click', function () { pickZone( f.zi ); } );
			zoneLayers[ f.zi ] = layer;
			group.push( layer );
		} );
		if ( group.length ) { map.fitBounds( L.featureGroup( group ).getBounds(), { padding: [ 10, 10 ] } ); }
		setTimeout( function () { map.invalidateSize(); }, 200 );
		map.on( 'focus', function () { map.scrollWheelZoom.enable(); } );
		map.on( 'blur', function () { map.scrollWheelZoom.disable(); } );
		var cur = $( '#fc_delivery_zone' ).val();
		if ( cur !== '' ) { highlightZone( cur ); }
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

	// On street change: recalc totals (fee). Zone is already set by the map click.
	$( document ).on( 'change', '#fc_delivery_street', function () {
		if ( suppressStreet ) { return; }
		resolveZone(); // keep the hidden zone in sync with the chosen street
		refresh();
		$( document.body ).trigger( 'update_checkout' );
	} );
	$( document ).on( 'change', 'input[name="fc_delivery_time_mode"]', timeMode );
	$( window ).on( 'resize', placeInOrderColumn );

	// #place_order lives in the payment column, which WooCommerce re-renders on
	// update_checkout — re-apply the busy state afterwards.
	$( document.body ).on( 'updated_checkout', function () { initStreetSelect(); initMap(); refresh(); placeInOrderColumn(); } );

	$( function () {
		initStreetSelect();
		initMap();
		placeInOrderColumn();
		// Restore a previously chosen zone (its streets + highlight).
		var z = $( '#fc_delivery_zone' ).val();
		if ( z !== '' ) { fillStreets( z ); highlightZone( z ); }
		refresh();
		timeMode();
	} );

} )( jQuery );
