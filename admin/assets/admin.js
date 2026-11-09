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
