/**
 * GenReady admin page.
 *
 * Every call goes through wp.apiFetch, which sends the wp_rest nonce. Text
 * from the server is set with textContent only. While a call of a fix card
 * runs, every action of that card is disabled; while a connect, verify or
 * scan call runs, every connection action is disabled. The result message
 * gets the focus.
 */
( function () {
	'use strict';

	var wp = window.wp || {};
	var data = window.genreadyAdmin || {};
	var apiFetch = wp.apiFetch;
	var __ = wp.i18n && wp.i18n.__ ? wp.i18n.__ : function ( text ) {
		return text;
	};
	var sprintf = wp.i18n && wp.i18n.sprintf ? wp.i18n.sprintf : function ( format, value ) {
		return format.replace( '%d', value );
	};
	var root = document.querySelector( '.genready-admin' );

	if ( ! apiFetch || ! data.ns || ! root ) {
		return;
	}

	var PRICING_URL = 'https://genready.ai/pricing';
	var POLL_MS = 5000;
	var POLL_LIMIT_MS = 180000;
	var SHOT_POLL_MS = 10000;
	var SHOT_LIMIT_MS = 180000;
	var PLAN_CODES = [ 'PLAN_REQUIRED', 'DOMAIN_LIMIT_REACHED', 'CREDITS_EXHAUSTED' ];
	var WRITE_CODES = [ 'CANNOT_WRITE', 'WRITE_FAILED' ];
	var FILE_NAMES = { robots: 'robots.txt', llms: 'llms.txt' };
	var CONNECTION_ACTIONS = [ 'connect', 'verify', 'scan', 'disconnect', 'remove-tag', 'check', 'create-account' ];
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

	/**
	 * "Analyzing the page…" with a spinner and the seconds since the start.
	 * Screen readers hear the text once; the seconds are hidden from them.
	 * The count stops when the next say() replaces the message.
	 *
	 * @param {HTMLElement} region Element with role="status".
	 */
	function progress( region ) {
		say( region, __( 'Analyzing the page…', 'genready' ), 'info' );
		var line = region.querySelector( 'p' );
		var spinner = make( 'span', 'spinner is-active' );
		var seconds = make( 'span', 'genready-elapsed' );
		spinner.setAttribute( 'aria-hidden', 'true' );
		seconds.setAttribute( 'aria-hidden', 'true' );
		line.classList.add( 'genready-wait' );
		line.insertBefore( spinner, line.firstChild );
		line.appendChild( seconds );
		region.appendChild( make( 'p', 'description', __( 'This usually takes 5 to 30 seconds.', 'genready' ) ) );
		var started = Date.now();
		var timer = window.setInterval( function () {
			if ( ! region.contains( seconds ) ) {
				window.clearInterval( timer );
				return;
			}
			/* translators: %d: seconds since the scan started. */
			seconds.textContent = sprintf( __( '%d s', 'genready' ), Math.round( ( Date.now() - started ) / 1000 ) );
		}, 1000 );
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
			}, connectionButtons );
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
	 * Disable buttons while a call runs. Locks nest: a button comes back only
	 * when its last lock ends, and then in the state it had before, or the
	 * state setDisabled() asked for during the call.
	 *
	 * @param {HTMLButtonElement[]} buttons Buttons to disable.
	 */
	function lock( buttons ) {
		buttons.forEach( function ( button ) {
			var depth = Number( button.getAttribute( 'data-genready-locks' ) || '0' );
			if ( depth === 0 ) {
				button.setAttribute( 'data-genready-was-disabled', button.disabled ? '1' : '0' );
				button.disabled = true;
			}
			button.setAttribute( 'data-genready-locks', String( depth + 1 ) );
		} );
	}

	/**
	 * End one lock on each button.
	 *
	 * @param {HTMLButtonElement[]} buttons Buttons that lock() disabled.
	 */
	function unlock( buttons ) {
		buttons.forEach( function ( button ) {
			var depth = Number( button.getAttribute( 'data-genready-locks' ) || '0' ) - 1;
			if ( depth > 0 ) {
				button.setAttribute( 'data-genready-locks', String( depth ) );
				return;
			}
			button.disabled = button.getAttribute( 'data-genready-was-disabled' ) === '1';
			button.removeAttribute( 'data-genready-locks' );
			button.removeAttribute( 'data-genready-was-disabled' );
		} );
	}

	/**
	 * Enable or disable a button. While the button is locked, the state is
	 * kept and set when the lock ends.
	 *
	 * @param {HTMLButtonElement} button The button.
	 * @param {boolean}           value  True to disable.
	 */
	function setDisabled( button, value ) {
		if ( button.hasAttribute( 'data-genready-locks' ) ) {
			button.setAttribute( 'data-genready-was-disabled', value ? '1' : '0' );
			return;
		}
		button.disabled = value;
	}

	/** Every action button of a fix card. */
	function cardButtons( card ) {
		return Array.prototype.slice.call( card.querySelectorAll( '[data-genready-action]' ) );
	}

	/** Every connect, verify, scan and connection button on the page. */
	function connectionButtons() {
		var selector = CONNECTION_ACTIONS.map( function ( action ) {
			return '[data-genready-action="' + action + '"]';
		} ).join( ',' ) + ',#genready-key-form button[type="submit"]';
		return Array.prototype.slice.call( root.querySelectorAll( selector ) );
	}

	/**
	 * Run a call with its button and the button's group disabled.
	 *
	 * @param {HTMLButtonElement} button  The button that started the call.
	 * @param {Function}          task    Returns a promise.
	 * @param {Function}          [group] Returns the other buttons to disable.
	 */
	async function busy( button, task, group ) {
		var buttons = group ? group() : [];
		if ( buttons.indexOf( button ) === -1 ) {
			buttons.push( button );
		}
		lock( buttons );
		button.setAttribute( 'aria-busy', 'true' );
		try {
			return await task();
		} finally {
			unlock( buttons );
			button.removeAttribute( 'aria-busy' );
		}
	}

	function onAction( scope, action, handler, group ) {
		scope.querySelectorAll( '[data-genready-action="' + action + '"]' ).forEach( function ( button ) {
			button.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				busy( button, function () {
					return handler( button );
				}, group );
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
				}, connectionButtons );
			} );
			var detail = result && typeof result.detail === 'string' && result.detail ? result.detail : __( 'GenReady did not find the verification tag on your home page.', 'genready' );
			say( region, detail, 'warning', retry );
			return false;
		} catch ( error ) {
			showError( region, error );
			return false;
		}
	}

	// Poll every 5 seconds; resolves when polling stops. With a scan ID (no account) it reads that stored scan and opens it when done.
	function poll( region, scanId ) {
		var started = Date.now();
		var path = '/report' + ( scanId ? '?scan=' + encodeURIComponent( scanId ) : '' );
		var done = data.overviewUrl + ( scanId ? '&scan=' + encodeURIComponent( scanId ) : '' );
		return new Promise( function ( stop ) {
			var tick = async function () {
				if ( Date.now() - started > POLL_LIMIT_MS ) {
					say( region, __( 'The scan is taking longer than usual. Reload the page later.', 'genready' ), 'warning' );
					return stop();
				}
				var state;
				try {
					state = await call( 'GET', path );
				} catch ( error ) {
					showError( region, error );
					return stop();
				}
				if ( state && state.state === 'complete' ) {
					say( region, __( 'The scan is done. Loading the report…', 'genready' ), 'success' );
					window.location.assign( done );
					return stop();
				}
				if ( state && ( state.state === 'failed' || state.state === 'gone' ) ) {
					say( region, typeof state.message === 'string' && state.message ? state.message : __( 'The scan did not finish. Scan again.', 'genready' ), 'error' );
					return stop();
				}
				if ( state && state.state === 'none' ) {
					say( region, __( 'GenReady lost this scan. Scan again.', 'genready' ), 'error' );
					return stop();
				}
				window.setTimeout( tick, POLL_MS );
			};
			window.setTimeout( tick, POLL_MS );
		} );
	}

	// Scan a page ('' = the home page) and poll it.
	async function scan( region, url ) {
		progress( region );
		try {
			var result = await call( 'POST', '/scan', url ? { url: url } : null );
			await poll( region, result && typeof result.scan_id === 'string' ? result.scan_id : '' );
		} catch ( error ) {
			showError( region, error );
		}
	}

	/** After a connection: verify, then scan (a scan needs no verification). */
	async function afterConnect() {
		await verify( setupLine( 'verify' ) );
		await scan( setupLine( 'scan' ), '' );
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
		}, connectionButtons );

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
				}, connectionButtons );
			} );
		}
	}

	/* ---------- Overview ---------- */

	/**
	 * GenReady makes the screenshot after the report. Ask for the report every
	 * 10 seconds for up to 3 minutes and show the screenshot when it is there.
	 *
	 * @param {HTMLElement} wait The "Making the screenshot…" line.
	 */
	function waitForScreenshot( wait ) {
		var scanId = wait.getAttribute( 'data-genready-scan' ) || '';
		var path = '/report' + ( scanId ? '?scan=' + encodeURIComponent( scanId ) : '' );
		var started = Date.now();
		var tick = async function () {
			var view = null;
			try {
				var state = await call( 'GET', path );
				view = state && state.report && state.report.ai_view ? state.report.ai_view : null;
			} catch ( error ) {
				view = { screenshot_pending: true };
			}
			if ( view && typeof view.screenshot === 'string' && /^https?:\/\//.test( view.screenshot ) ) {
				var image = make( 'img', 'genready-shot' );
				image.src = view.screenshot;
				image.alt = wait.getAttribute( 'data-genready-alt' ) || '';
				image.width = 1280;
				image.height = 800;
				wait.replaceWith( image );
				return;
			}
			if ( ! view || ! view.screenshot_pending || Date.now() - started > SHOT_LIMIT_MS ) {
				wait.replaceWith( make( 'p', null, __( 'This scan has no screenshot.', 'genready' ) ) );
				return;
			}
			window.setTimeout( tick, SHOT_POLL_MS );
		};
		window.setTimeout( tick, SHOT_POLL_MS );
	}

	function initOverview() {
		onAction( root, 'scan', function ( button ) {
			return scan( setupLine( 'scan' ), button.getAttribute( 'data-genready-url' ) || '' );
		}, connectionButtons );

		var wait = root.querySelector( '[data-genready-screenshot-wait]' );
		if ( wait ) {
			waitForScreenshot( wait );
		}

		var rawToggle = root.querySelector( '[data-genready-toggle="raw-text"]' );
		if ( rawToggle ) {
			rawToggle.addEventListener( 'click', function () {
				var formatted = root.querySelector( '[data-genready-text="formatted"]' );
				var raw = root.querySelector( '[data-genready-text="raw"]' );
				var label = rawToggle.textContent;
				formatted.hidden = ! formatted.hidden;
				raw.hidden = ! raw.hidden;
				rawToggle.textContent = rawToggle.getAttribute( 'data-genready-other' );
				rawToggle.setAttribute( 'data-genready-other', label );
			} );
		}

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

		setDisabled( cardAction( card, 'undo' ), ! info.applied );
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

	/*
	 * Card actions. Each gets the card context: card, kind, result (status
	 * region), apply (the Apply button) and path (the REST path of the kind).
	 */

	async function cardPreview( ctx ) {
		ctx.apply.hidden = true;
		try {
			var preview = await call( 'POST', ctx.path + 'preview', previewBody( ctx.card, ctx.kind ) );
			var ready = renderPreview( ctx.card, ctx.kind, preview );
			ctx.apply.hidden = ! ready;
			say( ctx.result, ready ? __( 'Check the preview, then click Apply.', 'genready' ) : __( 'GenReady cannot apply this preview yet. Read the preview for the reason.', 'genready' ), ready ? 'info' : 'warning' );
		} catch ( error ) {
			field( ctx.card, 'preview' ).hidden = true;
			showError( ctx.result, error );
		}
	}

	async function cardApply( ctx ) {
		try {
			var answer = await call( 'POST', ctx.path + 'apply', ctx.kind === 'jsonld' ? { values: fillValues( ctx.card ) } : {} );
			updateCard( ctx.card, answer.card );
			ctx.apply.hidden = true;
			field( ctx.card, 'preview' ).hidden = true;
			say( ctx.result, ctx.kind === 'jsonld' ? __( 'Applied.', 'genready' ) : __( 'Applied. Check the live file in a minute.', 'genready' ), 'success' );
		} catch ( error ) {
			var download = cardAction( ctx.card, 'download' );
			if ( download && WRITE_CODES.indexOf( errorCode( error ) ) !== -1 ) {
				download.hidden = false;
			}
			showError( ctx.result, error );
		}
	}

	async function cardDownload( ctx ) {
		try {
			var file = await call( 'POST', ctx.path + 'download' );
			if ( ! file || typeof file.content !== 'string' ) {
				say( ctx.result, __( 'GenReady could not do this. Try again.', 'genready' ), 'error' );
				return;
			}
			saveFile( FILE_NAMES[ ctx.kind ], file.content );
			say( ctx.result, __( "The file is downloaded. Upload it to your site's root folder.", 'genready' ), 'success' );
		} catch ( error ) {
			showError( ctx.result, error );
		}
	}

	async function cardUndo( ctx ) {
		try {
			var answer = await call( 'POST', ctx.path + 'undo' );
			updateCard( ctx.card, answer.card );
			ctx.apply.hidden = true;
			field( ctx.card, 'preview' ).hidden = true;
			say( ctx.result, __( 'Undone.', 'genready' ), 'success' );
		} catch ( error ) {
			showError( ctx.result, error );
		}
	}

	async function cardLiveCheck( ctx ) {
		try {
			var answer = await call( 'POST', ctx.path + 'live-check' );
			updateCard( ctx.card, answer.card );
			var live = answer.card && answer.card.live ? answer.card.live : null;
			say( ctx.result, live ? String( live.message || '' ) : __( 'GenReady could not reach your site.', 'genready' ), live && live.state === 'live' ? 'success' : 'warning' );
		} catch ( error ) {
			showError( ctx.result, error );
		}
	}

	async function cardAsFile( ctx ) {
		try {
			await call( 'POST', ctx.path + 'preview', previewBody( ctx.card, ctx.kind ) );
			var answer = await call( 'POST', ctx.path + 'apply', { as_file: true } );
			updateCard( ctx.card, answer.card );
			say( ctx.result, __( 'GenReady wrote llms.txt as a file. Check the live file in a minute.', 'genready' ), 'success' );
		} catch ( error ) {
			showError( ctx.result, error );
		}
	}

	var CARD_ACTIONS = {
		preview: cardPreview,
		apply: cardApply,
		download: cardDownload,
		undo: cardUndo,
		'live-check': cardLiveCheck,
		'as-file': cardAsFile,
	};

	function initCard( card ) {
		var kind = card.getAttribute( 'data-kind' );
		var ctx = {
			card: card,
			kind: kind,
			result: field( card, 'result' ),
			apply: cardAction( card, 'apply' ),
			path: '/fixes/' + kind + '/',
		};
		var group = function () {
			return cardButtons( card );
		};
		Object.keys( CARD_ACTIONS ).forEach( function ( name ) {
			onAction( card, name, function () {
				return CARD_ACTIONS[ name ]( ctx );
			}, group );
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
		}, connectionButtons );

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
		}, connectionButtons );

		onAction( section, 'remove-tag', async function ( button ) {
			try {
				await call( 'POST', '/verification-tag/remove' );
				button.hidden = true;
				say( region, __( 'The verification tag is removed.', 'genready' ), 'success' );
			} catch ( error ) {
				showError( region, error );
			}
		}, connectionButtons );
	}

	/* ---------- Shared with onboarding.js ---------- */

	data.ui = {
		call: call,
		make: make,
		makeButton: makeButton,
		say: say,
		showError: showError,
		errorCode: errorCode,
		errorText: errorText,
		busy: busy,
		poll: poll,
		progress: progress,
		connectionButtons: connectionButtons,
	};

	/* ---------- Start ---------- */

	initConnect();
	initOverview();
	root.querySelectorAll( '.genready-fix' ).forEach( initCard );
	initConnection();

	if ( data.justConnected ) {
		var starting = connectionButtons();
		lock( starting );
		afterConnect().finally( function () {
			unlock( starting );
		} );
	} else if ( data.reportState === 'pending' ) {
		poll( setupLine( 'scan' ), typeof data.scanId === 'string' ? data.scanId : '' );
	}
}() );
