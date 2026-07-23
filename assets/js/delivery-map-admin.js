/* Food Customizer — admin: edit delivery-zone boundaries on an OpenStreetMap.
   Each zone can hold SEVERAL separate polygons (draw one, then draw more). */
( function ( $ ) {
	'use strict';
	$( function () {
		var el = document.getElementById( 'fc-zone-map-editor' );
		if ( ! el || typeof L === 'undefined' || ! window.FC_ZONEMAP ) { return; }
		var C = window.FC_ZONEMAP, I = C.i18n || {};

		var map = L.map( el ).setView( [ 43.21, 27.91 ], 12 );
		L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
			maxZoom: 19, attribution: '&copy; OpenStreetMap'
		} ).addTo( map );
		setTimeout( function () { map.invalidateSize(); }, 200 );

		var layers = {}; // zone index -> array of polygon layers
		function styleFor( z ) { return { color: z.color, fillColor: z.color, fillOpacity: 0.35, weight: 2 }; }
		function zoneById( i ) { return C.zones.filter( function ( z ) { return z.i === i; } )[ 0 ]; }

		// Normalise any stored shape to an array of polygons (each = array of rings).
		function toPolys( shape ) {
			if ( ! shape || ! shape.length ) { return []; }
			if ( typeof shape[ 0 ][ 0 ] === 'number' ) { return [ [ shape ] ]; }        // a ring
			if ( typeof shape[ 0 ][ 0 ][ 0 ] === 'number' ) { return [ shape ]; }        // one polygon (rings)
			return shape;                                                                // multipolygon
		}

		var group = [];
		C.zones.forEach( function ( z ) {
			layers[ z.i ] = [];
			toPolys( z.shape ).forEach( function ( poly ) {
				var layer = L.polygon( poly, styleFor( z ) ).addTo( map );
				layer._fcZone = z.i;
				layer.bindTooltip( z.name, { sticky: true } );
				layers[ z.i ].push( layer );
				group.push( layer );
			} );
			$( '#fc-zonemap-target' ).append( $( '<option>' ).val( z.i ).text( z.name ) );
		} );
		if ( group.length ) { map.fitBounds( L.featureGroup( group ).getBounds(), { padding: [ 20, 20 ] } ); }

		if ( map.pm ) {
			map.pm.addControls( {
				position: 'topleft',
				drawMarker: false, drawCircle: false, drawCircleMarker: false,
				drawPolyline: false, drawRectangle: false, drawText: false,
				cutPolygon: false, rotateMode: false,
				drawPolygon: true, editMode: true, dragMode: true, removalMode: true
			} );
			map.pm.setGlobalOptions( { snappable: true, allowSelfIntersection: false } );

			// A freshly drawn polygon is ADDED to the zone chosen in the dropdown.
			map.on( 'pm:create', function ( e ) {
				var target = parseInt( $( '#fc-zonemap-target' ).val(), 10 );
				var z = zoneById( target );
				var layer = e.layer;
				layer._fcZone = target;
				if ( z ) { layer.setStyle( styleFor( z ) ); layer.bindTooltip( z.name, { sticky: true } ); }
				( layers[ target ] = layers[ target ] || [] ).push( layer );
			} );

			map.on( 'pm:remove', function ( e ) {
				var zi = e.layer && e.layer._fcZone;
				if ( zi !== undefined && layers[ zi ] ) {
					layers[ zi ] = layers[ zi ].filter( function ( l ) { return l !== e.layer; } );
				}
			} );
		}

		function llToArr( ll ) {
			if ( ll && ll.lat !== undefined ) { return [ +ll.lat.toFixed( 6 ), +ll.lng.toFixed( 6 ) ]; }
			return ( ll || [] ).map( llToArr );
		}

		$( '#fc-zonemap-save' ).on( 'click', function () {
			var shapes = {};
			C.zones.forEach( function ( z ) {
				// array of polygons, each polygon = array of rings
				shapes[ z.i ] = ( layers[ z.i ] || [] ).map( function ( l ) { return llToArr( l.getLatLngs() ); } );
			} );
			var $m = $( '#fc-zonemap-msg' ).css( 'color', '#646970' ).text( I.saving || '…' );
			$.post( C.ajax, { action: 'fc_save_zone_shapes', nonce: C.nonce, shapes: JSON.stringify( shapes ) } )
				.done( function ( r ) {
					if ( r && r.success ) {
						var counts = ( r.data && r.data.streets ) || {};
						var parts = Object.keys( counts ).map( function ( k ) {
							var z = zoneById( parseInt( k, 10 ) );
							return ( z ? z.name : k ) + ': ' + counts[ k ];
						} );
						$m.css( 'color', '#1a7f37' ).text( ( I.saved || 'Saved' ) + ( parts.length ? ' — ' + parts.join( ', ' ) : '' ) );
					} else { $m.css( 'color', '#b32d2e' ).text( I.error || 'Error' ); }
				} )
				.fail( function () { $m.css( 'color', '#b32d2e' ).text( I.error || 'Error' ); } );
		} );
	} );
} )( jQuery );
