/**
 * UptimeWire Overview dashboard and Dashboard widget: renews the status after
 * the page is shown, while the tab and the box are visible, and on Refresh.
 * Without this script the Refresh button submits a normal form and the screen
 * still works.
 *
 * The server renders the markup (escaped there); this script only swaps it in
 * and announces the new status to screen readers.
 */
( function () {
	'use strict';

	var settings = window.uptimewireDashboard;
	var box = settings ? document.getElementById( settings.container || 'uptimewire-dashboard' ) : null;
	var live = box ? box.previousElementSibling : null;
	var view = settings && 'widget' === settings.view ? 'widget' : 'overview';

	if ( ! settings || ! box || ! window.fetch ) {
		return;
	}

	if ( live && 'status' !== live.getAttribute( 'role' ) ) {
		live = null;
	}

	var ttl = Math.max( 60, parseInt( box.getAttribute( 'data-ttl' ), 10 ) || 300 ) * 1000;
	var busy = false;
	var lastRun = Date.now();
	// The widget does not speak on its first update: the Dashboard is not about it.
	var lastAnnounce = 'widget' === view ? null : '';
	var timer = null;

	function say( text ) {
		if ( ! live ) {
			return;
		}
		// Clearing first makes screen readers announce the same words again.
		live.textContent = '';
		window.setTimeout( function () {
			live.textContent = text;
		}, 100 );
	}

	// A widget hidden in Screen Options or collapsed is not read: nobody sees it.
	function shown() {
		return 'visible' === document.visibilityState && null !== box.offsetParent;
	}

	function refreshButton() {
		return box.querySelector( '.uptimewire-refresh' );
	}

	function setBusy( on ) {
		var button = refreshButton();
		if ( ! button ) {
			return;
		}
		button.setAttribute( 'aria-disabled', on ? 'true' : 'false' );
		button.classList.toggle( 'is-busy', on );
		var label = button.querySelector( '.uptimewire-refresh-label' );
		if ( label ) {
			if ( on ) {
				label.setAttribute( 'data-text', label.textContent );
				label.textContent = settings.text.refreshing;
			} else if ( label.getAttribute( 'data-text' ) ) {
				label.textContent = label.getAttribute( 'data-text' );
			}
		}
	}

	function schedule( ms ) {
		window.clearTimeout( timer );
		timer = window.setTimeout( function () {
			if ( shown() ) {
				run( false );
			} else {
				schedule( ttl );
			}
		}, ms );
	}

	function run( force ) {
		if ( busy ) {
			return;
		}
		busy = true;
		lastRun = Date.now();
		var hadFocus = document.activeElement === refreshButton();
		setBusy( true );

		window.fetch( settings.url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': settings.nonce,
			},
			body: JSON.stringify( { force: force, view: view } ),
		} )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'HTTP ' + response.status );
				}
				return response.json();
			} )
			.then( function ( answer ) {
				busy = false;
				if ( answer.redirect ) {
					window.location.assign( answer.redirect );
					return;
				}
				var focused = document.activeElement;
				// Never pull the page from under a keyboard user: an automatic update waits while they are inside it.
				if ( ! force && focused && focused !== document.body && box.contains( focused ) && focused !== refreshButton() ) {
					setBusy( false );
					schedule( 30000 );
					return;
				}
				if ( 'string' === typeof answer.html ) {
					box.innerHTML = answer.html;
				}
				setBusy( false );
				if ( hadFocus && refreshButton() ) {
					refreshButton().focus();
				}
				if ( force || ( null !== lastAnnounce && answer.announce !== lastAnnounce ) ) {
					say( answer.announce );
				}
				lastAnnounce = answer.announce;
				ttl = Math.max( 60, parseInt( answer.ttl, 10 ) || 300 ) * 1000;
				// Another tab was reading: look again shortly instead of reading twice.
				schedule( 'busy' === answer.result ? 5000 : ttl );
			} )
			.catch( function () {
				busy = false;
				setBusy( false );
				say( settings.text.failed );
				schedule( ttl );
			} );
	}

	box.addEventListener( 'submit', function ( event ) {
		if ( event.target.classList.contains( 'uptimewire-refresh-form' ) ) {
			event.preventDefault();
			run( true );
		}
	} );

	document.addEventListener( 'visibilitychange', function () {
		if ( shown() && Date.now() - lastRun >= ttl ) {
			run( false );
		}
	} );

	if ( '1' === box.getAttribute( 'data-due' ) && shown() ) {
		run( false );
	} else {
		schedule( ttl );
	}
}() );
