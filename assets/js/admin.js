/* global jQuery, nextsimWoo */
( function ( $ ) {
	'use strict';

	$( function () {
		$( '#nextsim-test-connection' ).on( 'click', function () {
			var $btn = $( this );
			var $out = $( '#nextsim-test-result' );

			$btn.prop( 'disabled', true );
			$out.text( nextsimWoo.testingText );

			$.post( nextsimWoo.ajaxUrl, {
				action: 'nextsim_test_connection',
				nonce: nextsimWoo.testNonce,
				host: $( '#nextsim_woo_api_host' ).val() || '',
				token: $( '#nextsim_woo_api_token' ).val() || ''
			} ).done( function ( res ) {
				$out.css( 'color', res.success ? 'green' : 'red' ).text( res.data.message );
			} ).fail( function () {
				$out.css( 'color', 'red' ).text( 'Request failed.' );
			} ).always( function () {
				$btn.prop( 'disabled', false );
			} );
		} );

		$( '#nextsim-sync-now' ).on( 'click', function () {
			var $btn = $( this );
			var $out = $( '#nextsim-sync-result' );

			$btn.prop( 'disabled', true );
			$out.text( nextsimWoo.syncingText );

			$.post( nextsimWoo.ajaxUrl, {
				action: 'nextsim_sync_now',
				nonce: nextsimWoo.syncNonce
			} ).done( function ( res ) {
				$out.css( 'color', res.success ? 'green' : 'red' ).text( res.data.message );
			} ).fail( function () {
				$out.css( 'color', 'red' ).text( 'Request failed.' );
			} ).always( function () {
				$btn.prop( 'disabled', false );
			} );
		} );
	} );
}( jQuery ) );
