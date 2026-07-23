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

	/* Street → neighbourhood lookup + editor: search a street to see (and correct)
	   which neighbourhood(s) it belongs to. Corrections are saved via AJAX and win
	   over the bundled auto-generated data. */
	function esc( s ) { return $( '<div/>' ).text( s == null ? '' : s ).html(); }
	var HOODS = window.FC_HOODS || {};
	var ED    = window.FC_HOODEDIT || { quarters: [], i18n: {} };
	var I     = ED.i18n || {};

	// street (lowercased original) -> [neighbourhoods]
	var rev = {};
	function buildRev() {
		rev = {};
		Object.keys( HOODS ).forEach( function ( hood ) {
			( HOODS[ hood ] || [] ).forEach( function ( street ) {
				( rev[ street ] = rev[ street ] || [] ).push( hood );
			} );
		} );
	}
	buildRev();

	// Reflect a saved street->quarters change in the local model so the UI updates
	// without a page reload.
	function applyLocal( street, quarters ) {
		Object.keys( HOODS ).forEach( function ( q ) {
			HOODS[ q ] = ( HOODS[ q ] || [] ).filter( function ( s ) { return s !== street; } );
		} );
		quarters.forEach( function ( q ) { ( HOODS[ q ] = HOODS[ q ] || [] ).push( street ); } );
		rev[ street ] = quarters.slice();
	}

	function quarterSelect( selected, id ) {
		selected = selected || [];
		var opts = ( ED.quarters || [] ).map( function ( q ) {
			var sel = selected.indexOf( q ) !== -1 ? ' selected' : '';
			return '<option value="' + esc( q ) + '"' + sel + '>' + esc( q ) + '</option>';
		} ).join( '' );
		return '<select multiple size="6"' + ( id ? ' id="' + id + '"' : ' class="fc-hood-select"' ) + ' style="min-width:240px;">' + opts + '</select>';
	}

	function rowView( street ) {
		var qs = rev[ street ] || [];
		var label = qs.length ? qs.map( esc ).join( ', ' ) : '<em>' + esc( I.none || '' ) + '</em>';
		return '<tr data-street="' + esc( street ) + '">'
			+ '<td style="width:42%"><strong>' + esc( street ) + '</strong></td>'
			+ '<td class="fc-hood-cell">' + label + '</td>'
			+ '<td style="width:70px"><button type="button" class="button-link fc-hood-edit">' + esc( I.edit || 'Edit' ) + '</button></td></tr>';
	}

	function renderResults( streets, $target ) {
		var $res = $target || $( '#fc-street-results' );
		if ( ! streets.length ) { $res.html( '<em>' + esc( $res.data( 'empty' ) || '—' ) + '</em>' ); return; }
		var html = '<table class="widefat striped" style="max-width:720px;"><tbody>';
		streets.forEach( function ( s ) { html += rowView( s ); } );
		html += '</tbody></table>';
		$res.html( html );
	}

	$( '#fc-street-search' ).on( 'input', function () {
		var q = $( this ).val().trim().toLowerCase();
		var $res = $( '#fc-street-results' );
		if ( q.length < 2 ) { $res.empty(); return; }
		var names = Object.keys( rev ).filter( function ( s ) { return s.toLowerCase().indexOf( q ) !== -1; } ).sort().slice( 0, 40 );
		renderResults( names );
	} );

	// Browse by neighbourhood: pick a quarter -> list all its streets (editable).
	function fillQuarterPicker() {
		var $sel = $( '#fc-quarter-pick' );
		if ( ! $sel.length ) { return; }
		var cur = $sel.val();
		var qs = ( ED.quarters && ED.quarters.length ) ? ED.quarters.slice() : Object.keys( HOODS );
		qs.sort();
		var opts = '<option value="">' + esc( $sel.data( 'placeholder' ) || '' ) + '</option>';
		qs.forEach( function ( q ) {
			opts += '<option value="' + esc( q ) + '">' + esc( q ) + ' (' + ( HOODS[ q ] || [] ).length + ')</option>';
		} );
		$sel.html( opts );
		if ( cur ) { $sel.val( cur ); }
	}
	function renderQuarter( q ) {
		var $res = $( '#fc-quarter-results' );
		if ( ! q ) { $res.empty(); return; }
		var streets = ( HOODS[ q ] || [] ).slice().sort();
		renderResults( streets, $res );
	}
	fillQuarterPicker();
	$( document ).on( 'change', '#fc-quarter-pick', function () { renderQuarter( $( this ).val() ); } );

	// Enter edit mode for a row.
	$( document ).on( 'click', '.fc-hood-edit', function () {
		var $tr = $( this ).closest( 'tr' );
		var editor = quarterSelect( rev[ $tr.data( 'street' ) ] || [] )
			+ '<div style="margin-top:6px;">'
			+ '<button type="button" class="button button-primary fc-hood-save">' + esc( I.save || 'Save' ) + '</button> '
			+ '<button type="button" class="button fc-hood-cancel">' + esc( I.cancel || 'Cancel' ) + '</button> '
			+ '<button type="button" class="button-link fc-hood-reset" style="color:#b32d2e;margin-left:4px;">' + esc( I.reset || 'Reset' ) + '</button>'
			+ '<span class="fc-hood-msg" style="margin-left:8px;"></span></div>';
		$tr.find( '.fc-hood-cell' ).html( editor );
		$( this ).hide();
	} );

	$( document ).on( 'click', '.fc-hood-cancel', function () {
		var $tr = $( this ).closest( 'tr' );
		$tr.replaceWith( rowView( $tr.data( 'street' ) ) );
	} );

	function saveStreet( $tr, quarters, reset ) {
		var street = $tr.data( 'street' );
		var $msg = $tr.find( '.fc-hood-msg' ).css( 'color', '#646970' ).text( '…' );
		$.post( ED.ajax, { action: 'fc_save_street_hood', nonce: ED.nonce, street: street, quarters: quarters, reset: reset ? 1 : 0 } )
			.done( function ( r ) {
				if ( r && r.success ) {
					applyLocal( street, r.data.quarters || [] );
					$tr.replaceWith( rowView( street ) );
					if ( typeof fillQuarterPicker === 'function' ) { fillQuarterPicker(); }
				} else {
					$msg.css( 'color', '#b32d2e' ).text( I.error || 'Error' );
				}
			} )
			.fail( function () { $msg.css( 'color', '#b32d2e' ).text( I.error || 'Error' ); } );
	}

	$( document ).on( 'click', '.fc-hood-save', function () {
		saveStreet( $( this ).closest( 'tr' ), $( this ).closest( 'tr' ).find( '.fc-hood-select' ).val() || [], false );
	} );
	$( document ).on( 'click', '.fc-hood-reset', function () {
		saveStreet( $( this ).closest( 'tr' ), [], true );
	} );

	// Add a street that the auto-data is missing.
	$( document ).on( 'click', '#fc-add-street', function () {
		var $box = $( '#fc-add-street-box' );
		if ( $box.length ) { $box.toggle(); return; }
		var html = '<div id="fc-add-street-box" style="margin-top:10px;padding:12px;border:1px solid #ccd0d4;background:#fff;max-width:520px;">'
			+ '<input type="text" id="fc-new-street" class="regular-text" placeholder="' + esc( I.streetname || 'Street name' ) + '"><br>'
			+ '<div style="margin:8px 0 4px;">' + esc( I.addhint || '' ) + '</div>'
			+ quarterSelect( [], 'fc-new-street-q' )
			+ '<br><button type="button" class="button button-primary" id="fc-new-street-save" style="margin-top:8px;">' + esc( I.save || 'Save' ) + '</button>'
			+ '<span id="fc-new-street-msg" style="margin-left:8px;"></span></div>';
		$( this ).after( html );
	} );

	$( document ).on( 'click', '#fc-new-street-save', function () {
		var street = $.trim( $( '#fc-new-street' ).val() );
		var quarters = $( '#fc-new-street-q' ).val() || [];
		if ( ! street ) { return; }
		var $msg = $( '#fc-new-street-msg' ).css( 'color', '#646970' ).text( '…' );
		$.post( ED.ajax, { action: 'fc_save_street_hood', nonce: ED.nonce, street: street, quarters: quarters, reset: 0 } )
			.done( function ( r ) {
				if ( r && r.success ) {
					applyLocal( street, r.data.quarters || [] );
					if ( typeof fillQuarterPicker === 'function' ) { fillQuarterPicker(); }
					$msg.css( 'color', '#1a7f37' ).text( I.saved || 'Saved' );
					$( '#fc-new-street' ).val( '' ); $( '#fc-new-street-q' ).val( [] );
				} else {
					$msg.css( 'color', '#b32d2e' ).text( I.error || 'Error' );
				}
			} )
			.fail( function () { $msg.css( 'color', '#b32d2e' ).text( I.error || 'Error' ); } );
	} );

	// One-click Varna 2-zone preset.
	$( '#fc-zone-preset' ).on( 'click', function () {
		var Z = window.FC_ZONEPRESET || {};
		if ( ! window.confirm( Z.confirm || '' ) ) { return; }
		var $m = $( '#fc-zone-preset-msg' ).css( 'color', '#646970' ).text( Z.applying || '…' );
		$.post( Z.ajax, { action: 'fc_apply_zone_preset', nonce: Z.nonce } )
			.done( function ( r ) {
				if ( r && r.success ) { $m.css( 'color', '#1a7f37' ).text( Z.done || '' ); location.reload(); }
				else { $m.css( 'color', '#b32d2e' ).text( Z.error || 'Error' ); }
			} )
			.fail( function () { $m.css( 'color', '#b32d2e' ).text( Z.error || 'Error' ); } );
	} );

} )( jQuery );
