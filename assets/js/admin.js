/* Food Customizer — product admin: repeatable rows. */
( function ( $ ) {
	'use strict';

	function reindexVariants() {
		$( 'table[data-repeat="variants"] tbody tr.fc-row' ).each( function ( i ) {
			$( this ).find( 'input.fc-variant-default' ).val( i );
		} );
		// Ensure at least one default is selected.
		var $radios = $( 'table[data-repeat="variants"] input.fc-variant-default' );
		if ( $radios.length && ! $radios.filter( ':checked' ).length ) {
			$radios.first().prop( 'checked', true );
		}
	}

	// Add a new row by cloning the last row of the target table body.
	$( document ).on( 'click', '.fc-add', function () {
		var target = $( this ).data( 'target' );
		var $tbody = $( 'table[data-repeat="' + target + '"] tbody' );
		var $last  = $tbody.find( 'tr.fc-row' ).last();
		var $row   = $last.length ? $last.clone() : null;
		if ( ! $row ) {
			return;
		}
		// Clear values in the clone.
		$row.find( 'input[type="text"], input[type="number"]' ).val( '' );
		$row.find( 'input[type="checkbox"]' ).prop( 'checked', false );
		$row.find( 'input[type="radio"]' ).prop( 'checked', false );
		// Give ingredient rows a fresh unique id linking hidden id + removable checkbox.
		if ( 'ingredients' === target ) {
			var newId = 'ing_' + Date.now() + '_' + Math.floor( Math.random() * 1000 );
			$row.find( 'input[name="fc_ing_id[]"]' ).val( newId );
			$row.find( 'input[name="fc_ing_removable[]"]' ).val( newId );
		}
		$tbody.append( $row );
		reindexVariants();
	} );

	// Remove a row (keep at least one).
	$( document ).on( 'click', '.fc-remove', function () {
		var $tbody = $( this ).closest( 'tbody' );
		if ( $tbody.find( 'tr.fc-row' ).length > 1 ) {
			$( this ).closest( 'tr.fc-row' ).remove();
		} else {
			// Just clear the last remaining row.
			var $row = $( this ).closest( 'tr.fc-row' );
			$row.find( 'input[type="text"], input[type="number"]' ).val( '' );
			$row.find( 'input[type="checkbox"], input[type="radio"]' ).prop( 'checked', false );
		}
		reindexVariants();
	} );

	// Keep ingredient removable checkbox value in sync with its row id.
	$( document ).on( 'input', 'input[name="fc_ing_id[]"]', function () {
		$( this ).closest( 'tr' ).find( 'input[name="fc_ing_removable[]"]' ).val( $( this ).val() );
	} );

	$( function () {
		reindexVariants();
	} );
} )( jQuery );
