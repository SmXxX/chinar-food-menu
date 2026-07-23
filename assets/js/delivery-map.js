/* Food Customizer — delivery zone map: OpenStreetMap base + zone polygons overlay. */
( function () {
	'use strict';
	function init() {
		if ( typeof L === 'undefined' ) { return; }
		var blocks = document.querySelectorAll( 'script.fc-delivery-map-data' );
		blocks.forEach( function ( s ) {
			var el = document.getElementById( s.getAttribute( 'data-for' ) );
			if ( ! el || el.dataset.fcInit ) { return; }
			el.dataset.fcInit = '1';
			var d;
			try { d = JSON.parse( s.textContent ); } catch ( e ) { return; }

			var map = L.map( el, { scrollWheelZoom: false } );
			L.tileLayer( 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
				maxZoom: 19,
				attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>'
			} ).addTo( map );

			var layers = [];
			( d.features || [] ).forEach( function ( f ) {
				var poly = L.polygon( f.r, { color: '#ffffff', weight: 1.5, lineJoin: 'round', fillColor: f.c, fillOpacity: 0.55 } ).addTo( map );
				if ( f.n ) { poly.bindTooltip( f.n, { sticky: true } ); }
				layers.push( poly );
			} );
			if ( layers.length ) {
				map.fitBounds( L.featureGroup( layers ).getBounds(), { padding: [ 12, 12 ] } );
			}

			if ( d.legend && d.legend.length ) {
				var lg = L.control( { position: 'bottomleft' } );
				lg.onAdd = function () {
					var div = L.DomUtil.create( 'div', 'fc-map-legend' );
					div.innerHTML = d.legend.map( function ( x ) {
						return '<span><i style="background:' + x.color + '"></i>' + x.label + '</span>';
					} ).join( '' );
					return div;
				};
				lg.addTo( map );
			}
			// let the user opt into wheel-zoom by clicking the map first
			map.on( 'focus', function () { map.scrollWheelZoom.enable(); } );
			map.on( 'blur', function () { map.scrollWheelZoom.disable(); } );
		} );
	}
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else { init(); }
} )();
