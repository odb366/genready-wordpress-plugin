/**
 * GenReady admin page.
 *
 * Every call goes through wp.apiFetch, which sends the wp_rest nonce. Text
 * from the server is set with textContent only. Buttons are disabled while
 * their call runs, and the result message gets the focus.
 */
( function () {
	'use strict';

	var wp = window.wp || {};
	var data = window.genreadyAdmin || {};
	var apiFetch = wp.apiFetch;
	var __ = wp.i18n && wp.i18n.__ ? wp.i18n.__ : function ( text ) {
		return text;
	};
	var root = document.querySelector( '.genready-admin' );

	if ( ! apiFetch || ! data.ns || ! root ) {
		return;
	}

	var PRICING_URL = 'https://genready.ai/pricing';
	var POLL_MS = 5000;
	var POLL_LIMIT_MS = 180000;
	var PLAN_CODES = [ 'PLAN_REQUIRED', 'DOMAIN_LIMIT_REACHED', 'CREDITS_EXHAUSTED' ];
	var WRITE_CODES = [ 'CANNOT_WRITE', 'WRITE_FAILED' ];
	var FILE_NAMES = { robots: 'robots.txt', llms: 'llms.txt' };
	var FIELD_LABELS = {
		headline: __( 'Headline', 'genready' ),
		name: __( 'Name', 'genready' ),
		author: __( 'Author name', 'genready' ),
		description: __( 'Description', 'genready' ),
		datePublished: __( 'Date published (for example 2026-10-01T10:00:00+00:00)', 'genready' ),
		dateModified: __( 'Date modified (for example 2026-10-01T10:00:00+00:00)', 'genready' ),
		logo: __( 'Logo URL', 'genready' ),
	};

	/* ---------- Small helpers ---------- */

	function call( method, path, body ) {
		var options = { path: '/' + data.ns + path, method: method };
		if ( body ) {
			options.data = body;
		}
		return apiFetch( options );
	}

	function make( tag, className, text ) {
		var node = document.createElement( tag );
		if ( className ) {
			node.className = className;
		}
		if ( text !== undefined && text !== null ) {
			node.textContent = String( text );
		}
		return node;
	}

	function makeButton( text, className ) {
		var button = make( 'button', className || 'button', text );
		button.type = 'button';
		return button;
	}

	function clear( node ) {
		while ( node.firstChild ) {
			node.removeChild( node.firstChild );
		}
	}

	function strings( value ) {
		return Array.isArray( value ) ? value.filter( function ( item ) {
			return typeof item === 'string';
		} ) : [];
	}

	function errorText( error ) {
		if ( error && typeof error.message === 'string' && error.message ) {
			return error.message;
		}
		return __( 'GenReady could not do this. Try again.', 'genready' );
	}

	function errorCode( error ) {
		return error && error.data && typeof error.data.genready_code === 'string' ? error.data.genready_code : '';
	}

	/**
	 * Show a message in a status region and move the focus to it.
	 *
	 * @param {HTMLElement} region  Element with role="status".
	 * @param {string}      text    Plain text.
	 * @param {string}      tone    info, success, warning or error.
	 * @param {HTMLElement} [extra] A link or button shown after the text.
	 */
	function say( region, text, tone, extra ) {
		if ( ! region ) {
			return;
		}
		clear( region );
		region.hidden = false;
		region.className = region.className.replace( /\bgenready-result--\w+/g, '' ).trim() + ' genready-result--' + ( tone || 'info' );
		region.appendChild( make( 'p', null, text ) );
		if ( extra ) {
			var holder = make( 'p' );
			holder.appendChild( extra );
			region.appendChild( holder );
		}
		region.focus();
	}

	function plansLink() {
		var link = make( 'a', null, __( 'See plans', 'genready' ) );
		link.href = PRICING_URL;
		link.target = '_blank';
		link.rel = 'noopener noreferrer';
		return link;
	}

	function verifyButton( region ) {
		var button = makeButton( __( 'Verify now', 'genready' ) );
		button.addEventListener( 'click', function () {
			busy( button, function () {
				return verify( region );
			} );
		} );
		return button;
	}

	function showError( region, error ) {
		var code = errorCode( error );
		var extra = null;
		if ( PLAN_CODES.indexOf( code ) !== -1 ) {
			extra = plansLink();
		} else if ( code === 'NOT_VERIFIED' ) {
			extra = verifyButton( region );
		}
		say( region, errorText( error ), 'error', extra );
	}

	/**
	 * Run a call with the button disabled.
	 *
	 * @param {HTMLButtonElement} button The button that started the call.
	 * @param {Function}          task   Returns a promise.
	 */
	async function busy( button, task ) {
		button.disabled = true;
		button.setAttribute( 'aria-busy', 'true' );
		try {
			return await task();
		} finally {
			button.disabled = false;
			button.removeAttribute( 'aria-busy' );
		}
	}

	function onAction( scope, action, handler ) {
		scope.querySelectorAll( '[data-genready-action="' + action + '"]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				busy( button, function () {
					return handler( button );
				} );
			} );
		} );
	}

	/**
	 * Save text as a file through a temporary download link.
	 *
	 * @param {string} name    File name.
	 * @param {string} content File content.
	 */
	function saveFile( name, content ) {
		var blob = new window.Blob( [ content ], { type: 'text/plain;charset=utf-8' } );
		var url = window.URL.createObjectURL( blob );
		var link = document.createElement( 'a' );
		link.href = url;
		link.download = name;
		link.hidden = true;
		document.body.appendChild( link );
		link.click();
		link.remove();
		window.setTimeout( function () {
			window.URL.revokeObjectURL( url );
		}, 0 );
	}

	/* ---------- Verify, scan and poll ---------- */

	var setup = document.getElementById( 'genready-setup' );

	function setupLine( name ) {
		var line = setup.querySelector( '[data-genready-step="' + name + '"]' );
		if ( ! line ) {
			line = make( 'div', 'genready-result' );
			line.setAttribute( 'data-genready-step', name );
			line.tabIndex = -1;
			setup.appendChild( line );
		}
		setup.hidden = false;
		return line;
	}

	/**
	 * Ask GenReady to check the verification tag.
	 *
	 * @param {HTMLElement} region Where the result goes.
	 * @return {Promise<boolean>} True when verified.
	 */
	async function verify( region ) {
		say( region, __( 'Checking the verification tag on your home page…', 'genready' ), 'info' );
		try {
			var result = await call( 'POST', '/verify' );
			if ( result && result.verified === true ) {
				say( region, __( 'Your site is verified.', 'genready' ), 'success' );
				return true;
			}
			var retry = makeButton( __( 'Try again', 'genready' ) );
			retry.addEventListener( 'click', function () {
				busy( retry, function () {
					return verify( region );
				} );
			} );
			var detail = result && typeof result.detail === 'string' && result.detail ? result.detail : __( 'GenReady did not find the verification tag on your home page.', 'genready' );
			say( region, detail, 'warning', retry );
			return false;
		} catch ( error ) {
			showError( region, error );
			return false;
		}
	}

	function poll( region ) {
		var started = Date.now();
		var tick = async function () {
			if ( Date.now() - started > POLL_LIMIT_MS ) {
				say( region, __( 'The scan is taking longer than usual. Reload the page later.', 'genready' ), 'warning' );
				return;
			}
			var state;
			try {
				state = await call( 'GET', '/report' );
			} catch ( error ) {
				showError( region, error );
				return;
			}
			if ( state && state.state === 'complete' ) {
				say( region, __( 'The scan is done. Loading the report…', 'genready' ), 'success' );
				window.location.assign( data.overviewUrl );
				return;
			}
			if ( state && state.state === 'failed' ) {
				say( region, typeof state.message === 'string' && state.message ? state.message : __( 'The scan did not finish. Scan again.', 'genready' ), 'error' );
				return;
			}
			if ( state && state.state === 'none' ) {
				say( region, __( 'GenReady lost this scan. Scan again.', 'genready' ), 'error' );
				return;
			}
			window.setTimeout( tick, POLL_MS );
		};
		window.setTimeout( tick, POLL_MS );
	}

	async function scan( region ) {
		say( region, __( 'Scanning your home page. This takes about a minute.', 'genready' ), 'info' );
		try {
			await call( 'POST', '/scan' );
		} catch ( error ) {
			showError( region, error );
			return;
		}
		poll( region );
	}

	/** After a connection: verify, then scan (a scan needs no verification). */
	async function afterConnect() {
		await verify( setupLine( 'verify' ) );
		await scan( setupLine( 'scan' ) );
	}

	/* ---------- Connect ---------- */

	function initConnect() {
		var region = root.querySelector( '.genready-connect [data-genready-field="result"]' );
		var form = document.getElementById( 'genready-key-form' );

		onAction( root, 'connect', async function () {
			var box = root.querySelector( '[data-genready-input="send_email"]' );
			try {
				var result = await call( 'POST', '/connect/oauth', { send_email: !! ( box && box.checked ) } );
				if ( result && typeof result.url === 'string' && /^https?:\/\//.test( result.url ) ) {
					window.location.assign( result.url );
					return;
				}
				say( region, __( 'GenReady could not do this. Try again.', 'genready' ), 'error' );
			} catch ( error ) {
				showError( region, error );
			}
		} );

		root.querySelectorAll( '[data-genready-action="show-key"]' ).forEach( function ( toggle ) {
			toggle.addEventListener( 'click', function () {
				if ( ! form ) {
					return;
				}
				form.hidden = ! form.hidden;
				toggle.setAttribute( 'aria-expanded', form.hidden ? 'false' : 'true' );
				if ( ! form.hidden ) {
					form.querySelector( 'input' ).focus();
				}
			} );
		} );

		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				var input = form.querySelector( '[data-genready-input="key"]' );
				var submit = form.querySelector( 'button[type="submit"]' );
				busy( submit, async function () {
					try {
						await call( 'POST', '/connect/key', { key: input.value } );
						input.value = '';
						say( region, __( 'GenReady is connected.', 'genready' ), 'success' );
						await afterConnect();
					} catch ( error ) {
						showError( region, error );
					}
				} );
			} );
		}
	}

	/* ---------- Overview ---------- */

	function initOverview() {
		onAction( root, 'scan', function () {
			return scan( setupLine( 'scan' ) );
		} );

		var toggles = root.querySelectorAll( '[data-genready-view]' );
		toggles.forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var view = button.getAttribute( 'data-genready-view' );
				toggles.forEach( function ( other ) {
					other.setAttribute( 'aria-pressed', other === button ? 'true' : 'false' );
				} );
				root.querySelectorAll( '[data-genready-panel]' ).forEach( function ( panel ) {
					panel.hidden = panel.getAttribute( 'data-genready-panel' ) !== view;
				} );
			} );
		} );
	}

	/* ---------- Fix cards ---------- */

	function field( card, name ) {
		return card.querySelector( '[data-genready-field="' + name + '"]' );
	}

	function cardAction( card, name ) {
		return card.querySelector( '[data-genready-action="' + name + '"]' );
	}

	function addList( parent, title, items ) {
		if ( ! items.length ) {
			return;
		}
		parent.appendChild( make( 'h3', null, title ) );
		var list = make( 'ul', 'genready-list' );
		items.forEach( function ( item ) {
			list.appendChild( make( 'li', null, item ) );
		} );
		parent.appendChild( list );
	}

	function addDiff( parent, diff ) {
		var pre = make( 'pre', 'genready-diff' );
		pre.tabIndex = 0;
		diff.split( '\n' ).forEach( function ( line ) {
			var tone = 'genready-diff__line';
			if ( line.indexOf( '+++' ) === 0 || line.indexOf( '---' ) === 0 ) {
				tone += ' genready-diff__line--meta';
			} else if ( line.charAt( 0 ) === '+' ) {
				tone += ' genready-diff__line--add';
			} else if ( line.charAt( 0 ) === '-' ) {
				tone += ' genready-diff__line--del';
			}
			pre.appendChild( make( 'span', tone, line + '\n' ) );
		} );
		parent.appendChild( pre );
	}

	function addFillForm( parent, kind, fillable ) {
		if ( ! fillable.length ) {
			return;
		}
		var group = make( 'fieldset', 'genready-fill' );
		group.appendChild( make( 'legend', null, __( 'Fill in the missing fields', 'genready' ) ) );
		fillable.forEach( function ( prop ) {
			if ( ! Object.prototype.hasOwnProperty.call( FIELD_LABELS, prop ) ) {
				return;
			}
			var id = 'genready-' + kind + '-' + prop;
			var row = make( 'p' );
			var label = make( 'label', null, FIELD_LABELS[ prop ] );
			label.htmlFor = id;
			var input = prop === 'description' ? make( 'textarea', 'large-text' ) : make( 'input', 'regular-text' );
			if ( input.tagName === 'INPUT' ) {
				input.type = prop === 'logo' ? 'url' : 'text';
			}
			input.id = id;
			input.maxLength = prop === 'description' ? 500 : 300;
			input.setAttribute( 'data-genready-prop', prop );
			row.appendChild( label );
			row.appendChild( make( 'br' ) );
			row.appendChild( input );
			group.appendChild( row );
		} );
		parent.appendChild( group );
	}

	/**
	 * Show a preview. Only display fields reach the browser.
	 *
	 * @param {HTMLElement} card    The fix card.
	 * @param {string}      kind    robots, llms or jsonld.
	 * @param {Object}      preview Preview from the server.
	 * @return {boolean} True when Apply makes sense.
	 */
	function renderPreview( card, kind, preview ) {
		var box = field( card, 'preview' );
		clear( box );
		box.hidden = false;
		addList( box, __( 'What changes', 'genready' ), strings( preview.summary ) );
		addList( box, __( 'Notes', 'genready' ), strings( preview.notes ) );
		if ( kind === 'jsonld' ) {
			if ( typeof preview.code === 'string' && preview.code ) {
				box.appendChild( make( 'h3', null, __( 'Structured data to add', 'genready' ) ) );
				var code = make( 'pre', 'genready-code', preview.code );
				code.tabIndex = 0;
				box.appendChild( code );
			}
			addFillForm( box, kind, strings( preview.fillable ) );
			var blocked = strings( preview.blocked_props );
			if ( blocked.length ) {
				box.appendChild( make( 'p', 'genready-warning', __( 'GenReady cannot fill in these fields from a form. Add them with your SEO plugin or theme:', 'genready' ) + ' ' + blocked.join( ', ' ) ) );
				return false;
			}
		} else if ( typeof preview.diff === 'string' && preview.diff ) {
			box.appendChild( make( 'h3', null, __( 'Changes to the file', 'genready' ) ) );
			addDiff( box, preview.diff );
		}
		if ( preview.external ) {
			box.appendChild( make( 'p', 'genready-warning', __( 'Your host or CDN serves its own robots.txt, so changes in WordPress do not reach visitors. Change it where your host or CDN manages it.', 'genready' ) ) );
			return false;
		}
		if ( strings( preview.missing ).indexOf( 'summary' ) !== -1 ) {
			box.appendChild( make( 'p', 'genready-warning', __( 'Add a site summary above, then preview again.', 'genready' ) ) );
			return false;
		}
		if ( preview.changed === false ) {
			box.appendChild( make( 'p', null, __( 'Nothing to change.', 'genready' ) ) );
			return false;
		}
		return true;
	}

	/**
	 * Show a card as the server describes it after a change.
	 *
	 * @param {HTMLElement} card The fix card.
	 * @param {Object}      info Card from the server.
	 */
	function updateCard( card, info ) {
		if ( ! info || typeof info !== 'object' ) {
			return;
		}
		var state = field( card, 'state' );
		state.textContent = String( info.state_text || '' );
		state.className = 'genready-state genready-state--' + String( info.state || 'not_applied' ).replace( /[^a-z_]/g, '' );

		var message = field( card, 'message' );
		message.textContent = String( info.message || '' );
		message.hidden = ! info.message;

		var live = field( card, 'live' );
		var liveInfo = info.live && typeof info.live === 'object' ? info.live : null;
		live.textContent = liveInfo ? String( liveInfo.message || '' ) : '';
		live.hidden = ! liveInfo;

		var versions = field( card, 'versions' );
		var list = versions.querySelector( 'ol' );
		clear( list );
		strings( info.versions ).forEach( function ( text ) {
			list.appendChild( make( 'li', null, text ) );
		} );
		versions.hidden = ! list.firstChild;

		cardAction( card, 'undo' ).disabled = ! info.applied;
		var check = cardAction( card, 'live-check' );
		if ( check ) {
			check.hidden = ! info.live_check;
		}
		var download = cardAction( card, 'download' );
		if ( download ) {
			download.hidden = info.can_write !== false;
		}
		var asFile = cardAction( card, 'as-file' );
		if ( asFile ) {
			asFile.hidden = ! ( liveInfo && liveInfo.state === 'no_route' && info.as_file === true );
		}
	}

	function previewBody( card, kind ) {
		if ( kind === 'robots' ) {
			var signal = card.querySelector( '[data-genready-input="content_signal"]' );
			return { content_signal: !! ( signal && signal.checked ) };
		}
		if ( kind === 'llms' ) {
			var tagline = card.querySelector( '[data-genready-input="tagline"]' );
			return tagline ? { tagline: tagline.value } : {};
		}
		return { values: fillValues( card ) };
	}

	function fillValues( card ) {
		var values = {};
		card.querySelectorAll( '[data-genready-prop]' ).forEach( function ( input ) {
			if ( input.value !== '' ) {
				values[ input.getAttribute( 'data-genready-prop' ) ] = input.value;
			}
		} );
		return values;
	}

	function initCard( card ) {
		var kind = card.getAttribute( 'data-kind' );
		var result = field( card, 'result' );
		var apply = cardAction( card, 'apply' );
		var path = '/fixes/' + kind + '/';

		onAction( card, 'preview', async function () {
			apply.hidden = true;
			try {
				var preview = await call( 'POST', path + 'preview', previewBody( card, kind ) );
				var ready = renderPreview( card, kind, preview );
				apply.hidden = ! ready;
				say( result, ready ? __( 'Check the preview, then click Apply.', 'genready' ) : __( 'GenReady cannot apply this preview yet. Read the preview for the reason.', 'genready' ), ready ? 'info' : 'warning' );
			} catch ( error ) {
				field( card, 'preview' ).hidden = true;
				showError( result, error );
			}
		} );

		onAction( card, 'apply', async function () {
			try {
				var answer = await call( 'POST', path + 'apply', kind === 'jsonld' ? { values: fillValues( card ) } : {} );
				updateCard( card, answer.card );
				apply.hidden = true;
				field( card, 'preview' ).hidden = true;
				say( result, kind === 'jsonld' ? __( 'Applied.', 'genready' ) : __( 'Applied. Check the live file in a minute.', 'genready' ), 'success' );
			} catch ( error ) {
				var download = cardAction( card, 'download' );
				if ( download && WRITE_CODES.indexOf( errorCode( error ) ) !== -1 ) {
					download.hidden = false;
				}
				showError( result, error );
			}
		} );

		onAction( card, 'download', async function () {
			try {
				var file = await call( 'POST', path + 'download' );
				if ( ! file || typeof file.content !== 'string' ) {
					say( result, __( 'GenReady could not do this. Try again.', 'genready' ), 'error' );
					return;
				}
				saveFile( FILE_NAMES[ kind ], file.content );
				say( result, __( "The file is downloaded. Upload it to your site's root folder.", 'genready' ), 'success' );
			} catch ( error ) {
				showError( result, error );
			}
		} );

		onAction( card, 'undo', async function () {
			try {
				var answer = await call( 'POST', path + 'undo' );
				updateCard( card, answer.card );
				apply.hidden = true;
				field( card, 'preview' ).hidden = true;
				say( result, __( 'Undone.', 'genready' ), 'success' );
			} catch ( error ) {
				showError( result, error );
			}
		} );

		onAction( card, 'live-check', async function () {
			try {
				var answer = await call( 'POST', path + 'live-check' );
				updateCard( card, answer.card );
				var live = answer.card && answer.card.live ? answer.card.live : null;
				say( result, live ? String( live.message || '' ) : __( 'GenReady could not reach your site.', 'genready' ), live && live.state === 'live' ? 'success' : 'warning' );
			} catch ( error ) {
				showError( result, error );
			}
		} );

		onAction( card, 'as-file', async function () {
			try {
				await call( 'POST', path + 'preview', previewBody( card, kind ) );
				var answer = await call( 'POST', path + 'apply', { as_file: true } );
				updateCard( card, answer.card );
				say( result, __( 'GenReady wrote llms.txt as a file. Check the live file in a minute.', 'genready' ), 'success' );
			} catch ( error ) {
				showError( result, error );
			}
		} );
	}

	/* ---------- Connection tab ---------- */

	function initConnection() {
		var section = root.querySelector( '.genready-connection' );
		if ( ! section ) {
			return;
		}
		var region = field( section, 'result' );

		onAction( section, 'verify', function () {
			return verify( region );
		} );

		onAction( section, 'disconnect', async function () {
			// eslint-disable-next-line no-alert
			if ( ! window.confirm( __( 'Disconnect GenReady? Applied fixes stay.', 'genready' ) ) ) {
				return;
			}
			try {
				await call( 'POST', '/disconnect' );
				say( region, __( 'GenReady is disconnected.', 'genready' ), 'success' );
				window.location.reload();
			} catch ( error ) {
				showError( region, error );
			}
		} );

		onAction( section, 'remove-tag', async function ( button ) {
			try {
				await call( 'POST', '/verification-tag/remove' );
				button.hidden = true;
				say( region, __( 'The verification tag is removed.', 'genready' ), 'success' );
			} catch ( error ) {
				showError( region, error );
			}
		} );
	}

	/* ---------- Start ---------- */

	initConnect();
	initOverview();
	root.querySelectorAll( '.genready-fix' ).forEach( initCard );
	initConnection();

	if ( data.justConnected ) {
		afterConnect();
	} else if ( data.reportState === 'pending' ) {
		poll( setupLine( 'scan' ) );
	}
}() );
