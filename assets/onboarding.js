/**
 * GenReady admin page without a connection: "Check this page" and
 * "Create my free account" (spec 4.1 to 4.6).
 *
 * admin.js loads first and shares its helpers as window.genreadyAdmin.ui.
 * Server text is set with textContent only (ui.say). The forms submit on Enter.
 */
( function () {
	'use strict';

	var wp = window.wp || {};
	var data = window.genreadyAdmin || {};
	var ui = data.ui;
	var root = document.querySelector( '.genready-admin' );
	var __ = wp.i18n && wp.i18n.__ ? wp.i18n.__ : function ( text ) {
		return text;
	};

	if ( ! ui || ! root ) {
		return;
	}

	var panel = document.getElementById( 'genready-connect-panel' );
	var scanForm = document.getElementById( 'genready-scan-form' );
	var scanResult = document.getElementById( 'genready-scan-result' );
	var accountForm = document.getElementById( 'genready-account-form' );
	var accountResult = document.getElementById( 'genready-account-result' );

	function connectLinks() {
		return Array.prototype.slice.call( root.querySelectorAll( '[data-genready-action="show-connect"]' ) );
	}

	function showConnect( open ) {
		if ( ! panel ) {
			return;
		}
		panel.hidden = ! open;
		connectLinks().forEach( function ( link ) {
			link.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
		} );
	}

	/** After 503 WP_ONBOARDING_OFF: hide both forms, keep the messages, show only Connect. */
	function pause() {
		root.querySelectorAll( '[data-genready-onboarding]' ).forEach( function ( node ) {
			node.hidden = true;
		} );
		connectLinks().forEach( function ( link ) {
			link.hidden = true;
		} );
		showConnect( true );
	}

	/**
	 * "Sign in to connect": today's OAuth Connect. The admin email is not sent,
	 * because the box "Send my admin email…" is not ticked on this path.
	 *
	 * @param {HTMLElement} region Where an error goes.
	 * @return {HTMLButtonElement} The button.
	 */
	function signInButton( region ) {
		var button = ui.makeButton( __( 'Sign in to connect', 'genready' ), 'button button-primary' );
		button.addEventListener( 'click', function () {
			ui.busy( button, async function () {
				try {
					var result = await ui.call( 'POST', '/connect/oauth', { send_email: false } );
					if ( result && typeof result.url === 'string' && /^https?:\/\//.test( result.url ) ) {
						window.location.assign( result.url );
						return;
					}
					ui.say( region, __( 'GenReady could not do this. Try again.', 'genready' ), 'error' );
				} catch ( error ) {
					ui.showError( region, error );
				}
			}, ui.connectionButtons );
		} );
		return button;
	}

	/**
	 * Show a refusal and the next step (spec 4.6).
	 *
	 * @param {HTMLElement} region The status region of the form.
	 * @param {Object}      error  The apiFetch error.
	 */
	function fail( region, error ) {
		var code = ui.errorCode( error );
		if ( code === 'ACCOUNT_EXISTS' || code === 'VERIFIED_ELSEWHERE' ) {
			ui.say( region, ui.errorText( error ), 'error', signInButton( region ) );
			return;
		}
		ui.say( region, ui.errorText( error ), 'error' );
		if ( code === 'PROOF_FAILED' ) {
			showConnect( true );
		} else if ( code === 'WP_ONBOARDING_OFF' ) {
			pause();
		}
	}

	connectLinks().forEach( function ( link ) {
		link.addEventListener( 'click', function () {
			var open = panel ? panel.hidden : false;
			showConnect( open );
			var first = open && panel ? panel.querySelector( 'button, input' ) : null;
			if ( first ) {
				first.focus();
			}
		} );
	} );

	if ( scanForm && scanResult ) {
		scanForm.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var input = scanForm.querySelector( '[data-genready-input="url"]' );
			var submit = scanForm.querySelector( '[data-genready-action="check"]' );
			ui.busy( submit, async function () {
				ui.say( scanResult, __( 'Scanning this page. This takes about 30 seconds.', 'genready' ), 'info' );
				try {
					var result = await ui.call( 'POST', '/scan', { url: input ? input.value : '' } );
					ui.poll( scanResult, result && typeof result.scan_id === 'string' ? result.scan_id : '' );
				} catch ( error ) {
					fail( scanResult, error );
				}
			}, ui.connectionButtons );
		} );
	}

	if ( accountForm && accountResult ) {
		accountForm.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			var input = accountForm.querySelector( '[data-genready-input="email"]' );
			var submit = accountForm.querySelector( '[data-genready-action="create-account"]' );
			ui.busy( submit, async function () {
				ui.say( accountResult, __( 'Creating your account.', 'genready' ), 'info' );
				try {
					await ui.call( 'POST', '/account', { email: input ? input.value : '' } );
					window.location.assign( data.overviewUrl );
				} catch ( error ) {
					fail( accountResult, error );
				}
			}, ui.connectionButtons );
		} );
	}
}() );
