/* global wpPcloudBackup */
( function () {
	'use strict';

	var cfg = window.wpPcloudBackup || {};
	var i18n = cfg.i18n || {};
	var POLL_MS = 3000;
	var MAX_ERRORS = 5;

	function text( key, fallback ) {
		return i18n[ key ] || fallback || '';
	}

	function $( id ) {
		return document.getElementById( id );
	}

	// --- backup progress -------------------------------------------------

	function request( action ) {
		var body = new URLSearchParams();
		body.set( 'action', 'wp_pcloud_backup_' + action );
		body.set( 'nonce', cfg.nonce || '' );

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'HTTP ' + response.status );
			}
			return response.json();
		} ).then( function ( json ) {
			// wp_send_json_error() wraps the payload; a plain status object has "running".
			if ( ! json || typeof json.running === 'undefined' ) {
				throw new Error( 'Unexpected response' );
			}
			return json;
		} );
	}

	function setMessage( message ) {
		var el = $( 'wppb-message' );
		if ( el ) {
			el.textContent = message || '';
		}
	}

	function render( status ) {
		var bar = document.querySelector( '#wppb-status .wppb-progress' );
		var percent = Math.round( 100 * ( Number( status.progress ) || 0 ) );
		var abort = $( 'wppb-abort-form' );
		var hint = $( 'wppb-hint' );

		if ( bar ) {
			bar.firstElementChild.style.width = percent + '%';
			bar.setAttribute( 'aria-valuenow', String( percent ) );
		}
		setMessage( status.message );
		if ( abort ) {
			abort.hidden = ! status.running;
		}
		if ( hint ) {
			hint.hidden = ! status.running;
		}
	}

	function sleep( ms ) {
		return new Promise( function ( resolve ) {
			window.setTimeout( resolve, ms );
		} );
	}

	/**
	 * The browser drives the backup: check the status, then run one slice while a job is active.
	 */
	function poll() {
		var errors = 0;

		function cycle() {
			return request( 'status' ).then( function ( status ) {
				render( status );
				if ( ! status.running ) {
					window.location.reload();
					return null;
				}
				return request( 'run' ).then( function ( ran ) {
					render( ran );
					errors = 0;
					if ( ! ran.running ) {
						window.location.reload();
						return null;
					}
					return sleep( POLL_MS ).then( cycle );
				} );
			} ).catch( function () {
				errors += 1;
				if ( errors >= MAX_ERRORS ) {
					setMessage( text( 'gaveUp', 'Connection lost. Please reload the page.' ) );
					return null;
				}
				setMessage( text( 'requestFailed', 'Request failed. Retrying...' ) );
				return sleep( POLL_MS ).then( cycle );
			} );
		}

		return cycle();
	}

	function start() {
		var button = $( 'wppb-start' );
		button.disabled = true;
		setMessage( text( 'starting', 'Starting...' ) );

		request( 'start' ).then( function ( status ) {
			render( status );
			if ( status.running ) {
				return sleep( POLL_MS ).then( poll );
			}
			// Finished within the first slice: show the new history row.
			window.location.reload();
			return null;
		} ).catch( function () {
			setMessage( text( 'gaveUp', 'Request failed. Please reload the page.' ) );
			button.disabled = false;
		} );
	}

	// --- helpers ---------------------------------------------------------

	function copyText( input, button ) {
		var done = function () {
			var original = button.textContent;
			button.textContent = text( 'copied', 'Copied' );
			window.setTimeout( function () {
				button.textContent = original;
			}, 1500 );
		};
		var fallback = function () {
			input.focus();
			input.select();
			try {
				document.execCommand( 'copy' );
				done();
			} catch ( e ) {
				// Selected text can still be copied by hand.
			}
		};

		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( input.value ).then( done, fallback );
		} else {
			fallback();
		}
	}

	function init() {
		var startButton = $( 'wppb-start' );
		var status = $( 'wppb-status' );
		var authorize = $( 'wppb-open-authorize' );

		if ( startButton ) {
			startButton.addEventListener( 'click', start );
		}
		if ( status && status.getAttribute( 'data-running' ) === '1' ) {
			poll();
		}

		Array.prototype.forEach.call( document.querySelectorAll( '[data-wppb-copy]' ), function ( button ) {
			button.addEventListener( 'click', function () {
				var input = $( button.getAttribute( 'data-wppb-copy' ) );
				if ( input ) {
					copyText( input, button );
				}
			} );
		} );

		Array.prototype.forEach.call( document.querySelectorAll( 'form[data-wppb-confirm]' ), function ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				if ( ! window.confirm( text( form.getAttribute( 'data-wppb-confirm' ), '' ) ) ) {
					event.preventDefault();
				}
			} );
		} );

		if ( authorize ) {
			authorize.addEventListener( 'click', function () {
				var id = ( $( 'wppb-client-id' ).value || '' ).trim();
				if ( ! id ) {
					window.alert( text( 'needClientId', 'Enter the Client ID first.' ) );
					return;
				}
				window.open(
					'https://my.pcloud.com/oauth2/authorize?client_id=' + encodeURIComponent( id ) + '&response_type=code',
					'_blank',
					'noopener'
				);
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
