/* Food Customizer — delivery zones admin (add / remove rows). */
( function ( $ ) {
	'use strict';

	// Add a new zone row, renumbering the template placeholder to a unique index.
	$( '#fc-add-zone' ).on( 'click', function () {
		var tpl = $( '#fc-zone-template' ).html();
		// Use a high, always-unique index so rows never collide (server reindexes on save).
		var idx = Date.now();
		$( '#fc-zones-body' ).append( tpl.replace( /__i__/g, idx ) );
	} );

	$( document ).on( 'click', '.fc-del-zone', function () {
		var $rows = $( '#fc-zones-body .fc-zone-row' );
		if ( $rows.length <= 1 ) {
			// keep at least one empty row to edit.
			$( this ).closest( '.fc-zone-row' ).find( 'input, textarea' ).val( '' );
			$( this ).closest( '.fc-zone-row' ).find( 'input[type="checkbox"]' ).prop( 'checked', false );
		} else {
			$( this ).closest( '.fc-zone-row' ).remove();
		}
	} );

	/* Street → neighbourhood lookup: type a street to see which neighbourhood(s)
	   it belongs to, so you know which to add to a zone. */
	function esc( s ) { return $( '<div/>' ).text( s == null ? '' : s ).html(); }
	var HOODS = window.FC_HOODS || {};
	var streetIdx = [];
	Object.keys( HOODS ).forEach( function ( hood ) {
		( HOODS[ hood ] || [] ).forEach( function ( street ) {
			streetIdx.push( { s: street, sl: street.toLowerCase(), h: hood } );
		} );
	} );
	$( '#fc-street-search' ).on( 'input', function () {
		var q = $( this ).val().trim().toLowerCase();
		var $res = $( '#fc-street-results' );
		if ( q.length < 2 ) { $res.empty(); return; }
		var byStreet = {};
		streetIdx.forEach( function ( x ) {
			if ( x.sl.indexOf( q ) !== -1 ) { ( byStreet[ x.s ] = byStreet[ x.s ] || [] ).push( x.h ); }
		} );
		var names = Object.keys( byStreet ).sort().slice( 0, 40 );
		if ( ! names.length ) { $res.html( '<em>' + esc( $res.data( 'empty' ) ) + '</em>' ); return; }
		var html = '<table class="widefat striped" style="max-width:640px;"><tbody>';
		names.forEach( function ( s ) {
			html += '<tr><td style="width:45%"><strong>' + esc( s ) + '</strong></td><td>' + byStreet[ s ].map( esc ).join( ', ' ) + '</td></tr>';
		} );
		html += '</tbody></table>';
		$res.html( html );
	} );

} )( jQuery );
