/* global jQuery, nextsimWoo */
( function ( $ ) {
	'use strict';

	var POLL_MS = 3000;
	var POLL_STALLED_MS = 10000;
	var FINAL_STATES = { idle: 1, done: 1, error: 1, cancelled: 1 };

	function i18n( key, fallback ) {
		return ( nextsimWoo.i18n && nextsimWoo.i18n[ key ] ) || fallback;
	}

	function fmt( template, value ) {
		return template.replace( '%s', value );
	}

	function num( n ) {
		return Number( n || 0 ).toLocaleString();
	}

	function duration( seconds ) {
		seconds = Math.max( 0, parseInt( seconds, 10 ) || 0 );
		var h = Math.floor( seconds / 3600 );
		var m = Math.floor( ( seconds % 3600 ) / 60 );
		var s = seconds % 60;

		if ( h > 0 ) {
			return h + ' h ' + m + ' min';
		}
		if ( m > 0 ) {
			return m + ' min ' + s + ' s';
		}
		return s + ' s';
	}

	$( function () {
		var $progress = $( '#nextsim-sync-progress' );
		var $syncBtn = $( '#nextsim-sync-now' );
		var $cancelBtn = $( '#nextsim-sync-cancel' );
		var $out = $( '#nextsim-sync-result' );
		var pollTimer = null;

		$( '#nextsim-test-connection' ).on( 'click', function () {
			var $btn = $( this );
			var $res = $( '#nextsim-test-result' );

			$btn.prop( 'disabled', true );
			$res.text( nextsimWoo.testingText );

			$.post( nextsimWoo.ajaxUrl, {
				action: 'nextsim_test_connection',
				nonce: nextsimWoo.testNonce,
				host: $( '#nextsim_woo_api_host' ).val() || '',
				token: $( '#nextsim_woo_api_token' ).val() || ''
			} ).done( function ( res ) {
				$res.css( 'color', res.success ? 'green' : 'red' ).text( res.data.message );
			} ).fail( function () {
				$res.css( 'color', 'red' ).text( i18n( 'requestFailed', 'Request failed.' ) );
			} ).always( function () {
				$btn.prop( 'disabled', false );
			} );
		} );

		if ( ! $progress.length ) {
			return;
		}

		/**
		 * Repaint the progress block from a progress object (same shape as the
		 * server-side render, see Settings_Page::render_progress()).
		 */
		function renderProgress( p ) {
			var running = p.status === 'running';
			var percent = ( typeof p.percent === 'number' ) ? p.percent : null;
			var $bar = $progress.find( '.nextsim-progress__bar' );

			$progress
				.attr( 'data-status', p.status )
				.attr( 'class', 'nextsim-progress nextsim-progress--' + p.status +
					( p.stalled ? ' nextsim-progress--stalled' : '' ) +
					( p.status === 'idle' ? ' nextsim-progress--empty' : '' ) );

			if ( running && percent === null ) {
				$bar.attr( 'data-indeterminate', '1' );
			} else {
				$bar.removeAttr( 'data-indeterminate' );
			}
			$bar.find( 'span' ).css( 'width', ( percent === null ? ( p.status === 'done' ? 100 : 0 ) : percent ) + '%' );

			$progress.find( '.nextsim-progress__summary' ).text( p.summary || '' );

			var counters = '';
			if ( p.status !== 'idle' ) {
				counters = [
					fmt( i18n( 'new', '%s new' ), num( p.created ) ),
					fmt( i18n( 'updated', '%s updated' ), num( p.updated_items ) ),
					fmt( i18n( 'unchanged', '%s unchanged' ), num( p.skipped ) )
				];
				if ( p.failed_items > 0 ) {
					counters.push( fmt( i18n( 'failed', '%s failed' ), num( p.failed_items ) ) );
				}
				counters = counters.join( ', ' );
			}
			$progress.find( '.nextsim-progress__counters' ).text( counters );

			// Only the running clock is repainted client-side; the "finished at" line
			// needs the site's date format, so a final state simply reloads it from
			// the server on the next page view.
			if ( running ) {
				$progress.find( '.nextsim-progress__time' ).text( fmt( i18n( 'runningFor', 'Running for %s.' ), duration( p.elapsed ) ) );
			} else {
				$progress.find( '.nextsim-progress__time' ).text( '' );
			}

			$progress.find( '.nextsim-progress__stalled' ).remove();
			if ( p.stalled ) {
				$( '<p class="nextsim-progress__stalled"></p>' ).text( i18n( 'stalled', 'No progress for more than 5 minutes.' ) ).appendTo( $progress );
			}

			$syncBtn.prop( 'disabled', running );
			$cancelBtn.toggle( running );
		}

		function stopPolling() {
			if ( pollTimer ) {
				clearTimeout( pollTimer );
				pollTimer = null;
			}
		}

		function schedulePoll( delay ) {
			stopPolling();
			pollTimer = setTimeout( poll, delay );
		}

		function poll() {
			$.post( nextsimWoo.ajaxUrl, {
				action: 'nextsim_sync_progress',
				nonce: nextsimWoo.progressNonce
			} ).done( function ( res ) {
				if ( ! res.success ) {
					schedulePoll( POLL_STALLED_MS );
					return;
				}

				renderProgress( res.data );

				if ( FINAL_STATES[ res.data.status ] ) {
					stopPolling();
					return;
				}

				schedulePoll( res.data.stalled ? POLL_STALLED_MS : POLL_MS );
			} ).fail( function () {
				schedulePoll( POLL_STALLED_MS );
			} );
		}

		$syncBtn.on( 'click', function () {
			$syncBtn.prop( 'disabled', true );
			$out.css( 'color', '' ).text( nextsimWoo.syncingText );

			$.post( nextsimWoo.ajaxUrl, {
				action: 'nextsim_sync_now',
				nonce: nextsimWoo.syncNonce
			} ).done( function ( res ) {
				$out.css( 'color', res.success ? 'green' : 'red' ).text( res.data.message );

				if ( res.success && res.data.progress ) {
					renderProgress( res.data.progress );
					schedulePoll( POLL_MS );
				} else {
					$syncBtn.prop( 'disabled', false );
				}
			} ).fail( function () {
				$out.css( 'color', 'red' ).text( i18n( 'requestFailed', 'Request failed.' ) );
				$syncBtn.prop( 'disabled', false );
			} );
		} );

		$cancelBtn.on( 'click', function () {
			$cancelBtn.prop( 'disabled', true );
			$out.css( 'color', '' ).text( i18n( 'cancelling', 'Cancelling…' ) );

			$.post( nextsimWoo.ajaxUrl, {
				action: 'nextsim_sync_cancel',
				nonce: nextsimWoo.cancelNonce
			} ).done( function ( res ) {
				$out.css( 'color', res.success ? '' : 'red' ).text( res.data.message );

				if ( res.success && res.data.progress ) {
					stopPolling();
					renderProgress( res.data.progress );
				}
			} ).fail( function () {
				$out.css( 'color', 'red' ).text( i18n( 'requestFailed', 'Request failed.' ) );
			} ).always( function () {
				$cancelBtn.prop( 'disabled', false );
			} );
		} );

		if ( nextsimWoo.progress && nextsimWoo.progress.status === 'running' ) {
			renderProgress( nextsimWoo.progress );
			schedulePoll( POLL_MS );
		}
	} );
}( jQuery ) );
