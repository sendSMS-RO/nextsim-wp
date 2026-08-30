/* global jQuery, nextsimWooFront */
( function ( $ ) {
	'use strict';

	// Render a value as text, keeping a legitimate numeric 0 (which `|| ''` would hide).
	// Providers send either numbers (e.g. 0) or unit strings (e.g. "5 GB"); both pass through.
	function cell( value ) {
		return ( value === 0 || value ) ? String( value ) : '';
	}

	function formatUsage( rows ) {
		if ( ! rows || ! rows.length ) {
			return $( '<p>' ).text( nextsimWooFront.error );
		}

		// API values are rendered as text nodes only — never as HTML.
		var $table = $( '<table class="nextsim-usage"><tbody></tbody></table>' );
		rows.forEach( function ( row ) {
			var $tr = $( '<tr>' );
			$tr.append( $( '<th>' ).text( cell( row.name ) ) );
			$tr.append( $( '<td>' ).text( cell( row.used_data ) + ' / ' + cell( row.data ) ) );
			$tr.append( $( '<td>' ).text( cell( row.expiration_date ) ) );
			$table.children( 'tbody' ).append( $tr );
		} );

		return $table;
	}

	$( document ).on( 'click', '.nextsim-check-usage', function () {
		var $btn = $( this );
		var code = $btn.data( 'code' );
		var $out = $btn.closest( '.nextsim-esim' ).find( '.nextsim-usage-result' );

		$btn.prop( 'disabled', true );
		$out.text( nextsimWooFront.loading );

		$.post( nextsimWooFront.ajaxUrl, {
			action: 'nextsim_check_consumption',
			nonce: nextsimWooFront.nonce,
			code: code
		} ).done( function ( res ) {
			if ( res.success ) {
				$out.empty().append( formatUsage( res.data.usage ) );
			} else {
				$out.text( res.data && res.data.message ? res.data.message : nextsimWooFront.error );
			}
		} ).fail( function () {
			$out.text( nextsimWooFront.error );
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );
}( jQuery ) );
