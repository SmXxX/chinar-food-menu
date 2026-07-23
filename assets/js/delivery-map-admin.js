/* Food Customizer — admin: edit delivery-zone boundaries on an OpenStreetMap, and
   edit/search the streets inside each zone. A zone may hold several polygons. */
( function ( $ ) {
	'use strict';
	$( function () {
		var el = document.getElementById( 'fc-zone-map-editor' );
		if ( ! el || typeof L === 'undefined' || ! window.FC_ZONEMAP ) { return; }
		var C = window.FC_ZONEMAP, I = C.i18n || {};

		function esc( s ) { return $( '<div/>' ).text( s == null ? '' : s ).html(); }
		function zoneById( i ) { return C.zones.filter( function ( z ) { return z.i === i; } )[ 0 ]; }
		function styleFor( z ) { return { color: z.color, fillColor: z.color, fillOpacity: 0.35, weight: 2 }; }

		/* ---------- editable per-zone street lists ---------- */
		var streetsModel = {};
		C.zones.forEach( function ( z ) {
			streetsModel[ z.i ] = ( ( C.streets && ( C.streets[ z.i ] || C.streets[ String( z.i ) ] ) ) || [] ).slice();
		} );
		( function fillDatalist() {
			var dl = document.getElementById( 'fc-all-streets' );
			if ( dl && C.allStreets ) {
				dl.innerHTML = C.allStreets.map( function ( s ) { return '<option value="' + esc( s ) + '"></option>'; } ).join( '' );
			}
		} )();

		function renderChips( $body, zi ) {
			var q = ( $body.data( 'filter' ) || '' ).toLowerCase();
			var list = streetsModel[ zi ] || [];
			var shown = q ? list.filter( function ( s ) { return s.toLowerCase().indexOf( q ) !== -1; } ) : list;
			var $chips = $body.find( '.fc-zs-chips' ).empty();
			if ( ! shown.length ) {
				$chips.html( '<em style="color:#787c82;">' + esc( q ? '—' : ( I.noneIn || '—' ) ) + '</em>' );
				return;
			}
			shown.forEach( function ( s ) {
				var $chip = $( '<span style="display:inline-block;background:#f0f0f1;border:1px solid #dcdcde;border-radius:12px;padding:2px 6px 2px 9px;margin:2px;font-size:12px;"></span>' ).text( s );
				$chip.append( $( '<button type="button" class="fc-zs-del" title="remove" style="border:0;background:none;cursor:pointer;color:#b32d2e;margin-left:2px;font-size:14px;line-height:1;">&times;</button>' ).data( 'street', s ) );
				$chips.append( $chip );
			} );
		}
		function updateCount( zi ) {
			$( '#fc-zone-streets .fc-zs-body' ).each( function () {
				if ( $( this ).data( 'zi' ) === zi ) { $( this ).prev().find( '.fc-zs-count' ).text( ( streetsModel[ zi ] || [] ).length ); }
			} );
		}
		function renderZoneStreets() {
			var $c = $( '#fc-zone-streets' ).empty();
			C.zones.forEach( function ( z ) {
				if ( z.busy ) { return; }
				var zi = z.i;
				var $box = $( '<div style="margin:8px 0;border:1px solid #dcdcde;border-radius:6px;overflow:hidden;"></div>' );
				var $head = $( '<button type="button" style="width:100%;text-align:left;background:#f6f7f7;border:0;border-bottom:1px solid #dcdcde;padding:8px 12px;cursor:pointer;font-weight:600;"></button>' );
				$head.html( '<span style="display:inline-block;width:12px;height:12px;border-radius:2px;background:' + esc( z.color ) + ';margin-right:8px;vertical-align:-1px;"></span>'
					+ esc( z.name ) + ' — <span class="fc-zs-count">' + ( streetsModel[ zi ] || [] ).length + '</span> ' + esc( I.streets || 'streets' )
					+ ' <span style="color:#787c82;font-weight:400;">(' + esc( I.showHide || '' ) + ')</span>' );
				var $body = $( '<div class="fc-zs-body" style="display:none;padding:10px 12px;"></div>' ).data( 'zi', zi );
				$body.append( '<input type="search" class="fc-zs-filter regular-text" placeholder="' + esc( I.filter || '' ) + '" style="margin-bottom:8px;width:100%;max-width:360px;">' );
				$body.append( '<div class="fc-zs-chips" style="max-height:220px;overflow:auto;"></div>' );
				$body.append( '<div style="margin-top:8px;"><input type="text" list="fc-all-streets" class="fc-zs-add regular-text" placeholder="' + esc( I.addPlace || '' ) + '" style="max-width:280px;"> <button type="button" class="button fc-zs-addbtn">' + esc( I.add || 'Add' ) + '</button></div>' );
				$head.on( 'click', function () { $body.toggle(); } );
				$box.append( $head ).append( $body );
				$c.append( $box );
				renderChips( $body, zi );
			} );
		}
		$( '#fc-zone-streets' ).on( 'input', '.fc-zs-filter', function () {
			var $body = $( this ).closest( '.fc-zs-body' );
			$body.data( 'filter', $( this ).val() );
			renderChips( $body, $body.data( 'zi' ) );
		} );
		$( '#fc-zone-streets' ).on( 'click', '.fc-zs-del', function () {
			var $body = $( this ).closest( '.fc-zs-body' ), zi = $body.data( 'zi' ), s = $( this ).data( 'street' );
			streetsModel[ zi ] = ( streetsModel[ zi ] || [] ).filter( function ( x ) { return x !== s; } );
			renderChips( $body, zi ); updateCount( zi );
		} );
		$( '#fc-zone-streets' ).on( 'click', '.fc-zs-addbtn', function () {
			var $body = $( this ).closest( '.fc-zs-body' ), zi = $body.data( 'zi' ), $inp = $body.find( '.fc-zs-add' ), s = $.trim( $inp.val() );
			if ( ! s ) { return; }
			if ( ( streetsModel[ zi ] || [] ).indexOf( s ) === -1 ) { ( streetsModel[ zi ] = streetsModel[ zi ] || [] ).push( s ); streetsModel[ zi ].sort(); }
			$inp.val( '' ); renderChips( $body, zi ); updateCount( zi );
		} );
		$( '#fc-zonestreets-save' ).on( 'click', function () {
			var $m = $( '#fc-zonestreets-msg' ).css( 'color', '#646970' ).text( I.saving || '…' );
			$.post( C.ajax, { action: 'fc_save_zone_streets', nonce: C.nonce, streets: JSON.stringify( streetsModel ) } )
				.done( function ( r ) {
					if ( r && r.success ) { $m.css( 'color', '#1a7f37' ).text( I.savedStr || 'Saved' ); }
					else { $m.css( 'color', '#b32d2e' ).text( I.error || 'Error' ); }
				} )
				.fail( function () { $m.css( 'color', '#b32d2e' ).text( I.error || 'Error' ); } );
		} );

		/* ---------- the map + boundary editor ---------- */
		var map = L.map( el ).setView( [ 43.21, 27.91 ], 12 );
		L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' } ).addTo( map );
		setTimeout( function () { map.invalidateSize(); }, 200 );

		var layers = {}; // zone index -> array of polygon layers
		function toPolys( shape ) {
			if ( ! shape || ! shape.length ) { return []; }
			if ( typeof shape[ 0 ][ 0 ] === 'number' ) { return [ [ shape ] ]; }
			if ( typeof shape[ 0 ][ 0 ][ 0 ] === 'number' ) { return [ shape ]; }
			return shape;
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
		renderZoneStreets();

		if ( map.pm ) {
			map.pm.addControls( {
				position: 'topleft',
				drawMarker: false, drawCircle: false, drawCircleMarker: false,
				drawPolyline: false, drawRectangle: false, drawText: false,
				cutPolygon: false, rotateMode: false,
				drawPolygon: true, editMode: true, dragMode: true, removalMode: true
			} );
			map.pm.setGlobalOptions( { snappable: true, allowSelfIntersection: false } );
			map.on( 'pm:create', function ( e ) {
				var target = parseInt( $( '#fc-zonemap-target' ).val(), 10 );
				var z = zoneById( target ), layer = e.layer;
				layer._fcZone = target;
				if ( z ) { layer.setStyle( styleFor( z ) ); layer.bindTooltip( z.name, { sticky: true } ); }
				( layers[ target ] = layers[ target ] || [] ).push( layer );
			} );
			map.on( 'pm:remove', function ( e ) {
				var zi = e.layer && e.layer._fcZone;
				if ( zi !== undefined && layers[ zi ] ) { layers[ zi ] = layers[ zi ].filter( function ( l ) { return l !== e.layer; } ); }
			} );
		}

		function llToArr( ll ) {
			if ( ll && ll.lat !== undefined ) { return [ +ll.lat.toFixed( 6 ), +ll.lng.toFixed( 6 ) ]; }
			return ( ll || [] ).map( llToArr );
		}
		$( '#fc-zonemap-save' ).on( 'click', function () {
			var shapes = {};
			C.zones.forEach( function ( z ) {
				shapes[ z.i ] = ( layers[ z.i ] || [] ).map( function ( l ) { return llToArr( l.getLatLngs() ); } );
			} );
			var $m = $( '#fc-zonemap-msg' ).css( 'color', '#646970' ).text( I.saving || '…' );
			$.post( C.ajax, { action: 'fc_save_zone_shapes', nonce: C.nonce, shapes: JSON.stringify( shapes ) } )
				.done( function ( r ) {
					if ( r && r.success ) {
						var byZone = ( r.data && r.data.streets ) || {};
						C.zones.forEach( function ( z ) {
							if ( byZone[ z.i ] !== undefined || byZone[ String( z.i ) ] !== undefined ) {
								streetsModel[ z.i ] = ( byZone[ z.i ] || byZone[ String( z.i ) ] || [] ).slice();
							}
						} );
						renderZoneStreets();
						var parts = C.zones.filter( function ( z ) { return ! z.busy; } ).map( function ( z ) { return z.name + ': ' + ( streetsModel[ z.i ] || [] ).length; } );
						$m.css( 'color', '#1a7f37' ).text( ( I.saved || 'Saved' ) + ( parts.length ? ' — ' + parts.join( ', ' ) : '' ) );
					} else { $m.css( 'color', '#b32d2e' ).text( I.error || 'Error' ); }
				} )
				.fail( function () { $m.css( 'color', '#b32d2e' ).text( I.error || 'Error' ); } );
		} );
	} );
} )( jQuery );
