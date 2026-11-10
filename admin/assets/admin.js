( function () {
	'use strict';
	var form = document.querySelector( '.aitp-form' );
	if ( ! form ) {
		return;
	}
	var radios = form.querySelectorAll( '.aitp-provider' );
	var preset = form.querySelector( '#aitp-preset' );
	var base = form.querySelector( '#aitp-base' );
	var model = form.querySelector( '#aitp-model' );
	var presets = window.aitpPresets || {};

	function provider() {
		var v = 'gemini';
		radios.forEach( function ( r ) {
			if ( r.checked ) {
				v = r.value;
			}
		} );
		return v;
	}

	function update( fromUser ) {
		var p = provider();
		form.querySelectorAll( '.aitp-openai-only' ).forEach( function ( el ) {
			el.classList.toggle( 'aitp-hidden', p !== 'openai' );
		} );
		if ( p === 'gemini' ) {
			base.placeholder = 'https://generativelanguage.googleapis.com/v1beta';
			if ( fromUser && ( ! model.value || model.value.indexOf( 'gemini' ) === -1 ) ) {
				model.value = 'gemini-3.5-flash';
			}
		} else if ( preset && presets[ preset.value ] ) {
			base.placeholder = presets[ preset.value ].base_url || 'https://';
			if ( fromUser && presets[ preset.value ].model ) {
				model.value = presets[ preset.value ].model;
			}
		}
	}

	radios.forEach( function ( r ) {
		r.addEventListener( 'change', function () {
			update( true );
		} );
	} );
	if ( preset ) {
		preset.addEventListener( 'change', function () {
			update( true );
		} );
	}
	update( false );
} )();

/* Live loops: advance the backfill queue or the audit from the open admin page. */
( function () {
	'use strict';
	function loop( btnId, outId, action, cfg ) {
		var btn = document.getElementById( btnId );
		var out = document.getElementById( outId );
		if ( ! btn || ! out || ! cfg ) {
			return;
		}
		var label = btn.textContent;
		var on = false;
		var timer = null;

		function stop() {
			on = false;
			clearTimeout( timer );
			btn.textContent = label;
		}

		function step() {
			if ( ! on ) {
				return;
			}
			var body = new FormData();
			body.append( 'action', action );
			body.append( '_ajax_nonce', cfg.nonce );
			fetch( cfg.ajax, { method: 'POST', body: body, credentials: 'same-origin' } )
				.then( function ( r ) {
					return r.json();
				} )
				.then( function ( j ) {
					if ( ! j || ! j.success ) {
						throw new Error( 'bad response' );
					}
					out.textContent = j.data.text;
					if ( j.data.stop ) {
						stop();
						return;
					}
					var fast = [ 'pages', 'time', 'running' ].indexOf( j.data.result ) !== -1;
					var wait = fast ? 1 : Math.min( 60, Math.max( 2, j.data.wait || 5 ) );
					timer = setTimeout( step, wait * 1000 );
				} )
				.catch( function () {
					out.textContent = cfg.error;
					timer = setTimeout( step, 15000 );
				} );
		}

		btn.addEventListener( 'click', function () {
			if ( on ) {
				stop();
				return;
			}
			on = true;
			btn.textContent = btn.getAttribute( 'data-label-stop' );
			step();
		} );
	}
	loop( 'aitp-queue-live', 'aitp-queue-live-status', 'aitp_queue_step', window.aitpQueue );
	loop( 'aitp-audit-live', 'aitp-audit-live-status', 'aitp_audit_step', window.aitpAudit );
	loop( 'aitp-autofix-live', 'aitp-autofix-live-status', 'aitp_autofix_step', window.aitpAutofix );
	loop( 'aitp-map-live', 'aitp-map-live-status', 'aitp_map_step', window.aitpMap );
} )();
