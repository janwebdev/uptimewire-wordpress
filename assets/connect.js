/**
 * UptimeWire: connect buttons and the waiting screen.
 *
 * Opens the UptimeWire page in a new tab and asks this WordPress site, never
 * UptimeWire directly, whether the person approved. The site decides when
 * UptimeWire may be asked; the device code never reaches the browser.
 * Without this script the same screens work through plain forms.
 */
( function () {
	'use strict';

	var config = window.uptimewireConnect;

	if ( ! config || ! window.fetch ) {
		return;
	}

	function post( url, data ) {
		return window.fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': config.nonce },
			body: JSON.stringify( data || {} )
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				var error = new Error( 'HTTP ' + response.status );
				error.status = response.status;
				throw error;
			}
			return response.json();
		} );
	}

	// Connect buttons: the tab is opened inside the click, so browsers do not block it.
	var form = document.getElementById( 'uptimewire-connect-form' );

	if ( form ) {
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			if ( form.getAttribute( 'data-busy' ) ) {
				return;
			}
			form.setAttribute( 'data-busy', '1' );
			Array.prototype.forEach.call( form.querySelectorAll( 'button' ), function ( button ) {
				button.disabled = true;
			} );

			var understand = form.querySelector( '[name="uptimewire_understand"]' );
			var tab = window.open( '', '_blank' );

			post( config.startUrl, { understand: !! ( understand && understand.checked ) } ).then( function ( data ) {
				var next = data.redirect;

				if ( tab ) {
					if ( data.ok && data.open ) {
						tab.opener = null;
						tab.location.replace( data.open );
					} else {
						tab.close();
					}
				} else if ( data.ok ) {
					// A blocked pop-up: the waiting screen says so and offers the button.
					next += ( next.indexOf( '?' ) === -1 ? '?' : '&' ) + 'uptimewire_notice=tab_blocked';
				}
				window.location.assign( next );
			} ).catch( function () {
				if ( tab ) {
					tab.close();
				}
				// The REST API is blocked or the site did not answer: the plain form does the same.
				form.submit();
			} );
		} );
	}

	// Waiting screen.
	var waiting = document.getElementById( 'uptimewire-waiting' );

	if ( ! waiting ) {
		return;
	}

	var statusText = document.getElementById( 'uptimewire-status-text' );
	var countdown = document.getElementById( 'uptimewire-countdown' );
	var checkForm = document.getElementById( 'uptimewire-check-form' );
	var deadline = Date.now() + ( parseInt( waiting.getAttribute( 'data-seconds-left' ), 10 ) || 0 ) * 1000;
	var delay = parseInt( waiting.getAttribute( 'data-retry-after' ), 10 ) || 5;
	var timer = null;
	var asking = false;
	var lastText = '';

	if ( checkForm ) {
		checkForm.hidden = true;
	}

	function say( text ) {
		// Only changes are announced, so a screen reader is not repeated every few seconds.
		if ( text && text !== lastText ) {
			statusText.textContent = text;
			lastText = text;
		}
	}

	function tick() {
		var left = Math.max( 0, Math.round( ( deadline - Date.now() ) / 1000 ) );
		countdown.textContent = Math.floor( left / 60 ) + ':' + ( '0' + ( left % 60 ) ).slice( -2 );
		if ( 0 === left ) {
			say( config.text.expired );
		}
	}

	function schedule( seconds ) {
		window.clearTimeout( timer );
		timer = window.setTimeout( ask, Math.max( 1, seconds ) * 1000 );
	}

	function ask() {
		if ( asking ) {
			return;
		}
		asking = true;

		post( config.statusUrl ).then( function ( data ) {
			asking = false;

			if ( data.done ) {
				window.location.assign( data.redirect );
				return;
			}

			deadline = Date.now() + data.seconds_left * 1000;
			say( 'unreachable' === data.notice ? config.text.unreachable : ( 'rate_limited' === data.notice ? config.text.rateLimited : config.text.waiting ) );
			schedule( data.retry_after );
		} ).catch( function ( error ) {
			asking = false;

			if ( error.status === 401 || error.status === 403 || error.status === 404 ) {
				// Signed out, an old nonce, or the REST API is blocked: the plain form still works.
				say( config.text.blocked );
				if ( checkForm ) {
					checkForm.hidden = false;
				}
				return;
			}

			say( config.text.offline );
			delay = Math.min( 60, delay * 2 );
			schedule( delay );
		} );
	}

	tick();
	window.setInterval( tick, 1000 );
	schedule( delay );
}() );
