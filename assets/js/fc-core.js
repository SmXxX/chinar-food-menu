/* Food Customizer — shared core: selection state, pricing, persistence.
 * Used by BOTH the modal and the product-page customizer so a customer's
 * choices stay in sync between them (localStorage, per product).
 *
 * State schema:  { v: variantId|null, r: [removedIds], a: { addonId: qty }, q: qty }
 */
( function () {
	'use strict';

	var TTL = 24 * 60 * 60 * 1000; // keep a customer's choices for 24h.

	window.FCCore = {

		key: function ( id ) { return 'fc_sel_' + id; },

		/** Default selection for a product (respects the default variant). */
		defaultState: function ( product ) {
			var v = null;
			if ( product.variants && product.variants.length ) {
				v = product.variants[ 0 ].id;
				product.variants.forEach( function ( x ) { if ( x.is_default ) { v = x.id; } } );
			}
			// No WooCommerce-variation preselection — the customer must actively pick
			// each option before the item can be added (the server enforces this too).
			return { v: v, r: [], a: {}, q: 1, w: {} };
		},

		/** Match a WooCommerce variation to the chosen attributes.
		 * Matched on VALUES, not attribute keys: the stored variation meta keys can
		 * be transliterated (Latin) while the parent attribute keys stay Cyrillic, so
		 * key comparison is unreliable. Option values are identical on both sides and
		 * unique per attribute in our menu, so value matching is correct and robust. */
		findWc: function ( wc, w ) {
			var chosen = [];
			for ( var k in w ) { if ( w.hasOwnProperty( k ) && w[ k ] ) { chosen.push( String( w[ k ] ) ); } }
			return ( wc.variations || [] ).filter( function ( v ) {
				var attrs = v.attrs || {};
				return Object.keys( attrs ).every( function ( ak ) {
					var sv = attrs[ ak ];
					return sv === '' || sv === undefined || chosen.indexOf( String( sv ) ) !== -1;
				} );
			} ).sort( function ( x, y ) { return x.price - y.price; } )[ 0 ];
		},

		/** Read persisted selection (validated against the product) or the default. */
		getState: function ( product ) {
			var def = this.defaultState( product );
			var saved = null;
			try {
				var raw = localStorage.getItem( this.key( product.id ) );
				if ( raw ) {
					var o = JSON.parse( raw );
					if ( o && o.t && ( Date.now() - o.t ) < TTL ) { saved = o.s; }
				}
			} catch ( e ) { saved = null; }
			if ( ! saved ) { return def; }

			// Validate saved choices against current product config.
			var state = { v: def.v, r: [], a: {}, q: 1, w: def.w };
			if ( product.wc && product.wc.attributes && saved.w ) {
				var w = {};
				product.wc.attributes.forEach( function ( a ) {
					if ( saved.w[ a.key ] && a.options.indexOf( saved.w[ a.key ] ) !== -1 ) { w[ a.key ] = saved.w[ a.key ]; }
					else if ( def.w[ a.key ] ) { w[ a.key ] = def.w[ a.key ]; }
				} );
				state.w = w;
			}
			if ( product.variants && product.variants.length ) {
				product.variants.forEach( function ( x ) { if ( x.id === saved.v ) { state.v = saved.v; } } );
			}
			var removableIds = ( product.removable || [] ).map( function ( i ) { return i.id; } );
			( saved.r || [] ).forEach( function ( id ) { if ( removableIds.indexOf( id ) !== -1 ) { state.r.push( id ); } } );
			( product.addons || [] ).forEach( function ( a ) {
				var q = saved.a && saved.a[ a.id ] ? parseInt( saved.a[ a.id ], 10 ) : 0;
				if ( q > 0 ) {
					var max = parseInt( a.max_qty, 10 ) || 0;
					if ( max > 0 && q > max ) { q = max; }
					state.a[ a.id ] = q;
				}
			} );
			state.q = Math.max( 1, parseInt( saved.q, 10 ) || 1 );
			return state;
		},

		save: function ( id, state ) {
			try { localStorage.setItem( this.key( id ), JSON.stringify( { t: Date.now(), s: state } ) ); } catch ( e ) {}
		},

		clear: function ( id ) {
			try { localStorage.removeItem( this.key( id ) ); } catch ( e ) {}
		},

		/** Server is authoritative; this is for live display only. */
		unitPrice: function ( product, state ) {
			var base = parseFloat( product.base_price ) || 0;
			if ( product.variants && product.variants.length ) {
				product.variants.forEach( function ( v ) { if ( v.id === state.v ) { base = parseFloat( v.price ); } } );
			}
			if ( product.wc && product.wc.variations && product.wc.variations.length ) {
				var m = this.findWc( product.wc, state.w || {} );
				if ( m ) { base = parseFloat( m.price ); }
			}
			var add = 0;
			( product.addons || [] ).forEach( function ( a ) {
				var q = ( state.a && state.a[ a.id ] ) || 0;
				add += parseFloat( a.price ) * q;
			} );
			return base + add;
		},

		money: function ( eur, D ) {
			D = D || window.FC_DATA || {};
			var e = Math.round( eur * 100 ) / 100;
			var s = e.toFixed( 2 ) + ' ' + ( D.currency || '€' );
			if ( ! D.dual ) { return s; }
			var bgn = ( e * ( parseFloat( D.rate ) || 1.95583 ) ).toFixed( 2 ).replace( '.', ',' );
			var lv = ( D.i18n && D.i18n.lv ) || 'лв';
			return s + ' <span class="fc-price-bgn">(' + bgn + ' ' + lv + ')</span>';
		}
	};
} )();
