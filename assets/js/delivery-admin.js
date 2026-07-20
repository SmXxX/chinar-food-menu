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

} )( jQuery );
