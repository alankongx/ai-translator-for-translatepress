/*
 * Mock LLM for end-to-end tests. Speaks the Gemini generateContent API and the OpenAI
 * Chat Completions API, translates deterministically (pseudo-translation that every
 * validator accepts) and injects faults on demand.
 *
 *   POST /__mock/config  {"mode":"shuffle","times":2,"target":"Japanese"}
 *   GET  /__mock/stats
 *   POST /__mock/reset
 *
 * Modes: ok shuffle drop dup extra shift truncate english bad_json short
 *        e429 e401 e500 e503 timeout schema_unsupported
 */
'use strict';
const http = require( 'http' );

const KEY = process.env.MOCK_KEY || 'test-key-123';
const PORT = +( process.env.PORT || 8080 );
const KATA = Array.from( 'アイウエオカキクケコサシスセソタチツテトナニヌネノハヒフヘホマミムメモヤユヨラリルレロワヲン' );
const ARAB = Array.from( 'ابتثجحخدذرزسشصضطظعغفقكلمنهوي' );
const HANZ = Array.from( '的一是不了人我在有他这为之大来以个中上们到说国和地也子时道出而要于就下得可你年生' );

const KEEP_RE = /(?:https?:\/\/|www\.)[^\s<>"']*[^\s<>"'.,;:!?)]|[\w.+-]+@[\w-]+(?:\.[\w-]+)+/g;

let config = { mode: 'ok', times: 0, target: '' };
let stats = { requests: 0, log: [] };

function scriptFor( targetName ) {
	const t = String( targetName || '' );
	if ( /Japanese/i.test( t ) ) return KATA;
	if ( /Arabic/i.test( t ) ) return ARAB;
	if ( /Chinese/i.test( t ) ) return HANZ;
	return null;
}

function pseudoWord( w, alphabet ) {
	const chars = Array.from( w );
	if ( chars.length < 3 || /^\p{Lu}+$/u.test( w ) ) return w;
	if ( alphabet ) return chars.map( ( c ) => alphabet[ c.codePointAt( 0 ) % alphabet.length ] ).join( '' );
	const r = chars.slice().reverse().join( '' );
	return r === w ? w + 'x' : r;
}

function pseudo( text, targetName, terms ) {
	const alphabet = scriptFor( targetName );
	const sorted = ( terms || [] ).slice().sort( ( a, b ) => b.length - a.length );
	return text.split( /(<[^>]*>)/ ).map( ( part ) => {
		if ( part.startsWith( '<' ) && part.endsWith( '>' ) ) return part;
		// Like a real model: URLs, e-mail addresses and protected terms stay as they are.
		const keep = [];
		let p = part.replace( KEEP_RE, ( m ) => '\u0002' + ( keep.push( m ) - 1 ) + '\u0002' );
		sorted.forEach( ( t, i ) => {
			p = p.split( t ).join( '\u0001' + i + '\u0001' );
		} );
		p = p.replace( /\p{L}+/gu, ( w ) => pseudoWord( w, alphabet ) );
		p = p.replace( /\u0001(\d+)\u0001/g, ( m, i ) => sorted[ +i ] );
		return p.replace( /\u0002(\d+)\u0002/g, ( m, i ) => keep[ +i ] );
	} ).join( '' );
}

function currentMode( targetName ) {
	if ( config.mode === 'ok' ) return 'ok';
	if ( config.target && ! new RegExp( config.target, 'i' ).test( targetName ) ) return 'ok';
	if ( config.times > 0 ) {
		config.times--;
		const m = config.mode;
		if ( config.times === 0 ) config.mode = 'ok';
		return m;
	}
	return config.times === 0 && config.mode !== 'ok' && config.sticky ? config.mode : config.mode;
}

function buildTranslations( payload, mode ) {
	const terms = payload.protected_terms || [];
	let list = payload.items.map( ( it ) => ( { id: it.id, text: pseudo( it.text, payload.target_language, terms ) } ) );
	switch ( mode ) {
		case 'shuffle': list.reverse(); break;
		case 'drop': if ( list.length > 1 ) list.pop(); break;
		case 'dup': list.push( { id: list[ 0 ].id, text: list[ 0 ].text } ); break;
		case 'extra': list.push( { id: 't999', text: 'extra' } ); break;
		case 'shift': list = list.slice( 1 ).map( ( x, i ) => ( { id: list[ i ].id, text: x.text } ) ); break;
		case 'english': list = payload.items.map( ( it ) => ( { id: it.id, text: it.text } ) ); break;
		case 'short': list = list.map( ( x ) => ( { id: x.id, text: ( x.text.match( /\S+/ ) || [ 'x' ] )[ 0 ] } ) ); break;
	}
	return list;
}

function send( res, status, obj, headers ) {
	const body = typeof obj === 'string' ? obj : JSON.stringify( obj );
	res.writeHead( status, Object.assign( { 'content-type': 'application/json' }, headers || {} ) );
	res.end( body );
}

function handle( req, res, raw ) {
	const url = new URL( req.url, 'http://x' );
	if ( url.pathname === '/__mock/config' ) {
		config = Object.assign( { mode: 'ok', times: 0, target: '' }, JSON.parse( raw || '{}' ) );
		return send( res, 200, config );
	}
	if ( url.pathname === '/__mock/reset' ) {
		config = { mode: 'ok', times: 0, target: '' };
		stats = { requests: 0, log: [] };
		return send( res, 200, { ok: true } );
	}
	if ( url.pathname === '/__mock/stats' ) return send( res, 200, stats );

	const gemini = /\/models\/[^/]+:generateContent$/.test( url.pathname );
	const openai = /\/chat\/completions$/.test( url.pathname );
	if ( ! gemini && ! openai ) return send( res, 404, { error: { message: 'not found' } } );

	const key = gemini ? req.headers[ 'x-goog-api-key' ] : String( req.headers.authorization || '' ).replace( /^Bearer /, '' );
	const body = JSON.parse( raw );
	const userText = gemini ? body.contents[ 0 ].parts[ 0 ].text : body.messages[ body.messages.length - 1 ].content;
	const payload = JSON.parse( userText );
	const mode = currentMode( payload.target_language );
	stats.requests++;
	stats.log.push( {
		api: gemini ? 'gemini' : 'openai',
		mode,
		target: payload.target_language,
		items: payload.items.length,
		notes: ( payload.notes || [] ).length,
		key_in_url: /key=/.test( req.url ),
		key_ok: key === KEY,
		schema: gemini ? !! ( body.generationConfig && body.generationConfig.responseSchema ) : !! ( body.response_format && body.response_format.type === 'json_schema' ),
		thinking: gemini ? ( body.generationConfig && body.generationConfig.thinkingConfig ) || null : body.reasoning_effort || null,
		max_out: gemini ? body.generationConfig.maxOutputTokens : ( body.max_completion_tokens || body.max_tokens ),
		at: Date.now(),
	} );
	if ( stats.log.length > 500 ) stats.log.shift();

	if ( key !== KEY || mode === 'e401' ) {
		return gemini
			? send( res, 400, { error: { code: 400, status: 'INVALID_ARGUMENT', message: 'API key not valid. Please pass a valid API key.' } } )
			: send( res, 401, { error: { message: 'Incorrect API key provided', type: 'invalid_request_error' } } );
	}
	if ( mode === 'e429' ) {
		return gemini
			? send( res, 429, { error: { code: 429, status: 'RESOURCE_EXHAUSTED', message: 'Quota exceeded for metric generate_content_requests per minute', details: [ { '@type': 'type.googleapis.com/google.rpc.RetryInfo', retryDelay: '20s' } ] } } )
			: send( res, 429, { error: { message: 'Rate limit reached' } }, { 'retry-after': '20' } );
	}
	if ( mode === 'e500' ) return send( res, 500, { error: { code: 500, message: 'Internal error' } } );
	if ( mode === 'e503' ) return send( res, 503, { error: { code: 503, status: 'UNAVAILABLE', message: 'The model is overloaded.' } } );
	if ( mode === 'schema_unsupported' && stats.log[ stats.log.length - 1 ].schema ) {
		return gemini
			? send( res, 400, { error: { code: 400, status: 'INVALID_ARGUMENT', message: 'Invalid JSON payload received. Unknown name "responseSchema" at \'generation_config\': Cannot find field.' } } )
			: send( res, 400, { error: { message: 'response_format type json_schema is not supported by this model' } } );
	}

	let text = JSON.stringify( { translations: buildTranslations( payload, mode ) } );
	let finish = 'STOP';
	if ( mode === 'truncate' ) {
		text = text.slice( 0, Math.floor( text.length / 2 ) );
		finish = 'MAX_TOKENS';
	}
	if ( mode === 'bad_json' ) text = 'Here are the translations you asked for: ' + text.slice( 0, 40 );

	const reply = () => {
		if ( gemini ) {
			return send( res, 200, {
				candidates: [ { content: { role: 'model', parts: [ { text } ] }, finishReason: finish } ],
				usageMetadata: { promptTokenCount: userText.length >> 2, candidatesTokenCount: text.length >> 2 },
				responseId: 'mock-' + stats.requests,
			} );
		}
		return send( res, 200, {
			id: 'chatcmpl-mock-' + stats.requests,
			choices: [ { index: 0, message: { role: 'assistant', content: text }, finish_reason: finish === 'STOP' ? 'stop' : 'length' } ],
			usage: { prompt_tokens: userText.length >> 2, completion_tokens: text.length >> 2 },
		} );
	};
	if ( mode === 'timeout' ) return setTimeout( reply, 70000 );
	const delay = +( config.delay_ms || 150 );
	setTimeout( reply, delay );
}

module.exports = { pseudo };

if ( require.main === module ) {
	http.createServer( ( req, res ) => {
		let raw = '';
		req.on( 'data', ( c ) => ( raw += c ) );
		req.on( 'end', () => {
			try {
				handle( req, res, raw );
			} catch ( e ) {
				send( res, 500, { error: { message: 'mock error: ' + e.message } } );
			}
		} );
	} ).listen( PORT, () => console.log( 'mock-llm on ' + PORT ) );
}
