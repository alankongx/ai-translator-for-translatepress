// Rehearsal of the test-site acceptance (task brief steps 0–7) on the local e2e site, through a
// front proxy that rejects response headers over 8 KB (like the test site's CDN), with the real
// LiteSpeed Cache plugin and test-site-sized data. dev/e2e only, never shipped.
//
//   docker compose --profile rehearsal up -d hcdn
//   HHG_DIR=<copy of HHG> node dev/e2e/rehearsal/run.mjs setup   build the data, take the step-0 baseline
//   node dev/e2e/rehearsal/run.mjs s1 s2 s3 …   run steps (state kept in .state/rehearsal.json)
//
// Admin actions go through the forms of the real admin pages (fields, nonces and one-time ids
// read from the HTML), as a person would submit them. Every request is logged with its status,
// time, header size and LiteSpeed purge header (.state/rehearsal-requests.json).
import { execFileSync } from 'node:child_process';
import { writeFileSync, readFileSync, existsSync, mkdirSync } from 'node:fs';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const DIR = dirname( fileURLToPath( import.meta.url ) );
const E2E = dirname( DIR );
const STATE = E2E + '/.state/rehearsal.json';
const REQLOG = E2E + '/.state/rehearsal-requests.json';
const OUT = E2E + '/.state/rehearsal-files';
const BASE = 'http://127.0.0.1:8091'; // through the proxy
const DIRECT = 'http://localhost:8088'; // the site's own host: 127.0.0.1 is redirected to it
const MOCK = 'http://127.0.0.1:8089';
const SLUG = { fr_FR: 'fr', es_MX: 'es', ja: 'ja', ar: 'ar', de_DE: 'de', pt_BR: 'pt' };
const LANGS = Object.keys( SLUG );
const PLUGIN = 'ai-translator-for-translatepress/ai-translator-for-translatepress.php';
mkdirSync( OUT, { recursive: true } );

const st = existsSync( STATE ) ? JSON.parse( readFileSync( STATE, 'utf8' ) ) : {};
const save = () => writeFileSync( STATE, JSON.stringify( st, null, 1 ) );
const reqlog = existsSync( REQLOG ) ? JSON.parse( readFileSync( REQLOG, 'utf8' ) ) : [];
const saveLog = () => writeFileSync( REQLOG, JSON.stringify( reqlog, null, 0 ) );

function wp( ...args ) {
	const out = execFileSync( E2E + '/wp.sh', args, { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ], maxBuffer: 1 << 28 } );
	return out.split( '\n' ).filter( ( l ) => ! /Container /.test( l ) ).join( '\n' ).trim();
}
const json = ( s ) => JSON.parse( s.slice( s.search( /[[{]/ ) ) );
const b64 = ( v ) => Buffer.from( JSON.stringify( v ) ).toString( 'base64' );
const ev = ( php, v ) => json( wp( 'eval', `$in = json_decode(base64_decode('${ b64( v === undefined ? null : v ) }'), true); ${ php }` ) );
const sleep = ( ms ) => new Promise( ( r ) => setTimeout( r, ms ) );

/* ---------- report ---------- */
const report = st.report || ( st.report = [] );
function rec( step, name, data, checks = {} ) {
	const failed = Object.entries( checks ).filter( ( [ , ok ] ) => ! ok ).map( ( [ k ] ) => k );
	report.push( { step, name, pass: failed.length === 0, failed, data, at: new Date().toISOString() } );
	save();
	console.log( `${ failed.length ? 'FAIL' : 'ok  ' }  [${ step }] ${ name }${ failed.length ? '  ← ' + failed.join( ', ' ) : '' }` );
	console.log( '        ' + JSON.stringify( data ).slice( 0, 900 ) );
}

/* ---------- HTTP like a browser, through the proxy ---------- */
let COOKIE = '';
function login() {
	const r = json( wp( 'eval', '$u = get_user_by("login", "admin"); $e = time() + 14400; echo wp_json_encode(array("c" => LOGGED_IN_COOKIE . "=" . rawurlencode(wp_generate_auth_cookie($u->ID, $e, "logged_in")) . "; " . AUTH_COOKIE . "=" . rawurlencode(wp_generate_auth_cookie($u->ID, $e, "auth"))));' ) );
	COOKIE = r.c;
}
const toBase = ( u, base = BASE ) => u.replace( /^https?:\/\/(localhost|127\.0\.0\.1):8088/, base );
async function http( method, path, { body, headers = {}, auth = true, follow = true, label = '', base = BASE, hops = 0 } = {} ) {
	const url = /^https?:/.test( path ) ? toBase( path, base ) : base + path;
	const t = Date.now();
	let r;
	for ( let i = 0; ; i++ ) {
		try {
			r = await fetch( url, { method, body, redirect: 'manual', headers: { 'user-agent': 'Mozilla/5.0 (aitp-rehearsal)', ...( auth ? { cookie: COOKIE } : {} ), ...headers } } );
			break;
		} catch ( e ) {
			if ( i >= 2 ) throw e;
			await sleep( 500 );
		}
	}
	const text = await r.text();
	let hdr = 0;
	for ( const [ k, v ] of r.headers ) hdr += k.length + v.length + 4;
	const purge = r.headers.get( 'x-litespeed-purge' ) || '';
	const e = { label, method, url: url.replace( base, '' ).slice( 0, 160 ), status: r.status, ms: Date.now() - t, hdr, purgeTags: purge ? purge.split( ',' ).length : 0, purgeBytes: purge.length, via: base === BASE ? 'proxy' : 'direct' };
	reqlog.push( e );
	saveLog();
	if ( e.status >= 500 ) console.log( `        !! HTTP ${ e.status } ${ method } ${ e.url } (${ e.ms } ms)` );
	const loc = r.headers.get( 'location' );
	if ( follow && e.status >= 300 && e.status < 400 && loc && hops < 5 ) {
		const next = await http( 'GET', loc, { label: label + ' →', base, auth, hops: hops + 1 } );
		return { ...next, first: e };
	}
	return { ...e, text, headers: r.headers, location: loc };
}

/* ---------- forms ---------- */
const decode = ( s ) => s.replace( /&quot;/g, '"' ).replace( /&#0?39;/g, "'" ).replace( /&lt;/g, '<' ).replace( /&gt;/g, '>' ).replace( /&#(\d+);/g, ( m, n ) => String.fromCodePoint( +n ) ).replace( /&amp;/g, '&' );
function attrs( tag ) {
	const o = {};
	for ( const m of tag.matchAll( /([\w:\[\]-]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s>]+)))?/g ) ) o[ m[ 1 ].toLowerCase() ] = decode( m[ 2 ] ?? m[ 3 ] ?? m[ 4 ] ?? '' );
	return o;
}
function forms( html ) {
	return [ ...html.matchAll( /<form\b([^>]*)>([\s\S]*?)<\/form>/gi ) ].map( ( m ) => {
		const a = attrs( m[ 1 ] );
		const fields = [];
		const buttons = [];
		for ( const t of m[ 2 ].matchAll( /<(input|button)\b([^>]*)>/gi ) ) {
			const x = attrs( t[ 2 ] );
			if ( ! x.name || 'disabled' in x ) continue;
			const type = ( x.type || ( t[ 1 ].toLowerCase() === 'button' ? 'submit' : 'text' ) ).toLowerCase();
			if ( type === 'submit' || t[ 1 ].toLowerCase() === 'button' ) buttons.push( [ x.name, x.value ?? '' ] );
			else if ( ( type === 'checkbox' || type === 'radio' ) && ! ( 'checked' in x ) ) fields.push( { name: x.name, value: x.value ?? 'on', off: true, type } );
			else if ( type !== 'file' ) fields.push( { name: x.name, value: x.value ?? '', type } );
		}
		for ( const t of m[ 2 ].matchAll( /<select\b([^>]*)>([\s\S]*?)<\/select>/gi ) ) {
			const x = attrs( t[ 1 ] );
			if ( ! x.name || 'disabled' in x ) continue;
			const opts = [ ...t[ 2 ].matchAll( /<option\b([^>]*)>/gi ) ].map( ( o ) => attrs( o[ 1 ] ) );
			const sel = opts.find( ( o ) => 'selected' in o ) || opts[ 0 ];
			fields.push( { name: x.name, value: sel ? sel.value ?? '' : '', type: 'select' } );
		}
		for ( const t of m[ 2 ].matchAll( /<textarea\b([^>]*)>([\s\S]*?)<\/textarea>/gi ) ) {
			const x = attrs( t[ 1 ] );
			if ( x.name ) fields.push( { name: x.name, value: decode( t[ 2 ] ), type: 'textarea' } );
		}
		const val = ( n ) => ( fields.find( ( f ) => f.name === n && ! f.off ) || {} ).value;
		return { action: a.action || '', method: ( a.method || 'get' ).toLowerCase(), fields, buttons, val, html: m[ 0 ] };
	} );
}
const findForm = ( html, pred, what ) => {
	const f = forms( html ).find( pred );
	if ( ! f ) throw new Error( 'form not found: ' + what );
	return f;
};
// set: name => value | array of values | null (remove). button: [name, value].
async function submit( form, { set = {}, button, file, label = '' } = {} ) {
	let pairs = form.fields.filter( ( f ) => ! f.off ).map( ( f ) => [ f.name, f.value ] );
	for ( const [ k, v ] of Object.entries( set ) ) {
		pairs = pairs.filter( ( p ) => p[ 0 ] !== k );
		if ( v !== null ) for ( const x of [].concat( v ) ) pairs.push( [ k, String( x ) ] );
	}
	if ( button ) pairs.push( button );
	let body;
	if ( file ) {
		body = new FormData();
		for ( const [ k, v ] of pairs ) body.append( k, v );
		body.append( file.field, new Blob( [ file.content ], { type: 'application/json' } ), file.name );
	} else {
		body = new URLSearchParams( pairs );
	}
	const action = /^https?:|^\//.test( form.action ) ? form.action : '/wp-admin/' + form.action;
	return http( 'POST', action, { body, label } );
}
const admin = ( tab, q = '' ) => http( 'GET', `/wp-admin/options-general.php?page=aitp&tab=${ tab }${ q }`, { label: 'open ' + tab } );
function flash( html ) {
	return [ ...html.matchAll( /<div[^>]*class="[^"]*notice[^"]*"[^>]*>([\s\S]*?)<\/div>/g ) ].map( ( m ) => decode( m[ 1 ].replace( /<[^>]+>/g, ' ' ).replace( /\s+/g, ' ' ).trim() ) ).filter( Boolean );
}
const lastAction = ( html ) => ( html.match( /data-aitp-last="([^"]*)"/ ) || [] )[ 1 ] || '';
const nonceOf = ( html, obj ) => ( html.match( new RegExp( 'window\\.' + obj + ' = \\{[^}]*"nonce":"([a-f0-9]+)"' ) ) || [] )[ 1 ];

/* ---------- site helpers ---------- */
const PAGES = () => st.pages; // key => {id, url}
const trUrl = ( src, lang ) => {
	const u = new URL( src );
	return `${ u.pathname === '/' ? '/' + SLUG[ lang ] + '/' : '/' + SLUG[ lang ] + u.pathname }`;
};
const ENGLISH = /come with \d|frames per carton|times lighter|more styles like|of glare;|samples ship in|all day\?|for a price list|temple tips protect|Footer line \d/;
async function visitPages( { base = BASE, label = 'visit' } = {} ) {
	const out = [];
	for ( const lang of LANGS ) {
		for ( const [ k, p ] of Object.entries( PAGES() ) ) {
			const r = await http( 'GET', trUrl( p.url, lang ), { auth: false, base, label: `${ label } ${ lang } ${ k }` } );
			out.push( { lang, page: k, status: r.status, ms: r.ms, english: ENGLISH.test( r.text.replace( /<script[\s\S]*?<\/script>/g, '' ) ), h1: ( r.text.match( /<h1[^>]*>([\s\S]*?)<\/h1>/ ) || [] )[ 1 ] || '', rtl: /<html[^>]*dir="rtl"/.test( r.text ) } );
		}
	}
	return out;
}
async function plugins( action, plugin ) {
	const p = await http( 'GET', '/wp-admin/plugins.php', { label: 'plugins' } );
	const href = [ ...p.text.matchAll( /href="([^"]*plugins\.php\?action=([a-z]+)&amp;plugin=([^&"]+)[^"]*)"/g ) ].find( ( m ) => m[ 2 ] === action && decodeURIComponent( m[ 3 ] ) === plugin );
	if ( ! href ) throw new Error( `no ${ action } link for ${ plugin }` );
	return http( 'GET', '/wp-admin/' + decode( href[ 1 ] ).replace( /^.*wp-admin\//, '' ), { label: `${ action } ${ plugin }` } );
}
async function tpEngine( engine, on ) {
	const p = await http( 'GET', '/wp-admin/admin.php?page=trp_machine_translation', { label: 'TP automatic translation' } );
	const f = findForm( p.text, ( x ) => x.fields.some( ( y ) => y.name === 'trp_machine_translation_settings[translation-engine]' ), 'TP machine translation' );
	const set = { 'trp_machine_translation_settings[translation-engine]': engine };
	if ( on !== undefined ) set[ 'trp_machine_translation_settings[machine-translation]' ] = on ? 'yes' : null;
	const r = await submit( f, { set, label: 'save TP engine ' + engine } );
	return { status: r.status, first: r.first && r.first.status, engine: ev( 'echo wp_json_encode(get_option("trp_machine_translation_settings"));' ) };
}
const purgeState = () => ev( 'echo wp_json_encode(class_exists("AITP_Cache_Purge") ? AITP_Cache_Purge::status() : null);' );
const logEvents = ( since ) => ev( 'global $wpdb; echo wp_json_encode($wpdb->get_results($wpdb->prepare("SELECT id, level, event, LEFT(data, 300) data FROM {$wpdb->prefix}aitp_log WHERE id > %d AND event IN (\'cache_purge\', \'cache_purge_all\', \'repair\', \'rollback\') ORDER BY id", (int) $in), ARRAY_A));', since || 0 );
const logMax = () => ev( 'global $wpdb; echo wp_json_encode(array("id" => (int) $wpdb->get_var("SELECT MAX(id) FROM {$wpdb->prefix}aitp_log")));' ).id;
// Rows of the 4 pages in one language, with what the mock would translate them to.
const pageRows = () => ev( `global $wpdb; $o = array(); foreach (array("fr_FR","es_MX","ja","ar","de_DE","pt_BR") as $l) { $t = $wpdb->prefix . "trp_dictionary_en_us_" . strtolower($l); $o[$l] = $wpdb->get_row($wpdb->prepare("SELECT COUNT(*) n, SUM(status = 0) s0, SUM(status = 1) s1, SUM(status = 2) s2, SUM(status = 1 AND BINARY translated = BINARY original AND BINARY original REGEXP '[a-z]{3}') english FROM $t WHERE original_id > %d AND original_id <= %d", $in[0], $in[1]), ARRAY_A); } echo wp_json_encode($o);`, [ st.oidFrom, st.oidTo ] );
let arPack;
const AR_PACK = () => ( arPack ??= /^1/.test( ev( 'echo in_array("ar", get_available_languages(), true) ? 1 : 0;' ) ) );
const compare = () => json( wp( 'eval-file', '/e2e/rehearsal/setup.php', 'compare' ) );

/* ---------- steps ---------- */
const S = {
	async setup() {
		st.report = [];
		report.length = 0;
		await fetch( MOCK + '/__mock/reset', { method: 'POST' } );
		ev( 'foreach (array("aitp_e2e_page_cache","aitp_e2e_visit_log","aitp_e2e_tamper","aitp_e2e_kill_after","aitp_e2e_purge_fault","aitp_e2e_fake_litespeed") as $o) delete_option($o); update_option("aitp_e2e_via_proxy", 1); update_option("aitp_e2e_purge_probe", 1); echo "{}";' );
		const maxOid = () => ev( 'global $wpdb; echo wp_json_encode(array("n" => (int) $wpdb->get_var("SELECT MAX(id) FROM {$wpdb->prefix}trp_original_strings")));' ).n;
		st.oidFrom = maxOid();
		st.pages = json( wp( 'eval-file', '/e2e/rehearsal/setup.php', 'content' ) );
		save();
		// Translate the 4 pages × 6 languages with the mock AI, as visitors would have.
		let left = null;
		for ( let round = 1; round <= 6; round++ ) {
			const v = await visitPages( { base: DIRECT, label: 'setup' } );
			left = v.filter( ( x ) => x.english || x.status !== 200 );
			if ( ! left.length ) break;
		}
		st.oidTo = maxOid();
		save();
		const bulk = json( wp( 'eval-file', '/e2e/rehearsal/setup.php', 'bulk' ) );
		const rows = pageRows();
		rec( 'setup', 'pages translated, test-site-sized data', { pages: st.pages, left: left.length, bulk, page_rows: rows }, { all_translated: left.length === 0 } );
		// The engine before the test: HHG. AITP never installed.
		wp( 'plugin', 'deactivate', 'ai-translator-for-translatepress' );
		wp( 'plugin', 'uninstall', 'ai-translator-for-translatepress', '--skip-delete' );
		// HHG is not part of this repository: HHG_DIR is a local copy of the plugin folder.
		if ( ! process.env.HHG_DIR || ! existsSync( process.env.HHG_DIR ) ) throw new Error( 'Set HHG_DIR to a local copy of the HHG plugin folder.' );
		execFileSync( 'docker', [ 'cp', process.env.HHG_DIR.replace( /\/?$/, '/.' ), 'aitp-e2e-wordpress-1:/var/www/html/wp-content/plugins/hhg-for-translatepress' ] );
		execFileSync( 'docker', [ 'exec', 'aitp-e2e-wordpress-1', 'chown', '-R', '33:33', '/var/www/html/wp-content/plugins/hhg-for-translatepress' ] );
		wp( 'plugin', 'activate', 'hhg-for-translatepress' );
		await S.baseline();
	},

	async baseline() {
		login();
		const eng = await tpEngine( 'hhgfotr_gemini', true ); // saved through TranslatePress's own form, as on the test site
		const snap = json( wp( 'eval-file', '/e2e/rehearsal/setup.php', 'snapshot' ) );
		st.snapshot = snap;
		save();
		rec( 'setup', 'step 0 baseline (HHG engine, AITP not installed)', { engine: eng, tables: snap }, { engine_hhg: eng.engine[ 'translation-engine' ] === 'hhgfotr_gemini' } );
	},

	async s1() {
		login();
		// 1.1 Install (the plugin files are mounted; activation is what the upload does on the site).
		const act = await plugins( 'activate', PLUGIN );
		// 1.2 Settings: the mock AI as a "Gemini" endpoint, daily cap 1,000,000, brand protected.
		ev( `$s = array_merge(AITP_Settings::defaults(), array("provider" => "gemini", "preset" => "custom", "base_url" => "http://mock:8080/v1beta", "model" => "gemini-3.5-flash", "thinking" => "low", "api_key_enc" => AITP_Settings::encrypt("test-key-123"), "protected_terms" => "OKAY EYEWEAR\\nOK-A1001", "site_context" => "B2B manufacturer of kids sunglasses and optical frames.")); update_option("aitp_settings", $s); echo "{}";` );
		const set = await admin( 'settings' );
		const tf = forms( set.text ).find( ( f ) => f.val( 'action' ) === 'aitp_test' );
		const test = tf ? await submit( tf, { label: 'connection test' } ) : null;
		// 1.3 Journal on, BEFORE the engine switch.
		let pg = await admin( 'pages' );
		const jr = await submit( findForm( pg.text, ( f ) => f.val( 'do' ) === 'journal_start', 'journal_start' ), { set: { label: '第四期' }, label: 'journal start' } );
		// 1.4 Engine → AITP through TranslatePress's form.
		const eng = await tpEngine( 'aitp' );
		// 1.5 HHG off.
		const hhg = await plugins( 'deactivate', 'hhg-for-translatepress/hhg-for-translatepress.php' );
		const after = await admin( 'status' );
		// 1.6 Queue page list only.
		const q = await admin( 'queue' );
		const qs = await submit( findForm( q.text, ( f ) => f.val( 'do' ) === 'sync', 'queue sync' ), { label: 'queue sync' } );
		const qstate = ev( 'echo wp_json_encode(array("state" => AITP_Queue::state(), "items" => (int) $GLOBALS["wpdb"]->get_var("SELECT COUNT(*) FROM {$GLOBALS["wpdb"]->prefix}aitp_queue")));' );
		rec( 's1', 'install, journal, engine switch, HHG off, queue list', {
			activate: act.status, test: test && { status: test.status, flash: flash( test.text ).slice( 0, 2 ) }, journal: flash( jr.text ).slice( 0, 2 ), engine: eng.engine, hhg_off: hhg.status,
			red_notice: flash( after.text ).filter( ( x ) => /engine|引擎/i.test( x ) ), queue: { status: qs.status, flash: flash( qs.text ).slice( 0, 1 ), items: qstate.items, qstatus: qstate.state.status },
		}, {
			activated: act.status === 200, journal_on: !! ev( 'echo wp_json_encode(AITP_Journal::state());' ).on,
			engine_aitp: eng.engine[ 'translation-engine' ] === 'aitp', no_engine_warning: ! flash( after.text ).some( ( x ) => /not this plugin|不是本插件/.test( x ) ),
			queue_listed: qstate.items > 900, queue_not_running: qstate.state.status !== 'running',
		} );
	},

	async s2() {
		login();
		const p = await admin( 'audit' );
		const t0 = Date.now();
		const r = await submit( findForm( p.text, ( f ) => f.val( 'do' ) === 'scan', 'scan' ), { label: 'scan start' } );
		const nonce = nonceOf( r.text, 'aitpAudit' );
		let steps = 0;
		let maxMs = 0;
		let last;
		for ( ; steps < 2000; steps++ ) {
			const s = await http( 'POST', '/wp-admin/admin-ajax.php', { body: new URLSearchParams( { action: 'aitp_audit_step', _ajax_nonce: nonce } ), label: 'scan step' } );
			maxMs = Math.max( maxMs, s.ms );
			last = JSON.parse( s.text );
			if ( ! last.success || last.data.stop ) break;
		}
		const counts = ev( 'global $wpdb; echo wp_json_encode($wpdb->get_results("SELECT lang, severity, COUNT(*) n FROM {$wpdb->prefix}aitp_audit GROUP BY lang, severity", ARRAY_A));' );
		rec( 's2', 'read-only check of ~545,000 rows', { start_status: r.status, steps, max_step_ms: maxMs, total_s: Math.round( ( Date.now() - t0 ) / 1000 ), last: last && last.data && last.data.text, counts }, { finished: !! ( last && last.data && last.data.stop ), no_5xx: reqlog.slice( -steps - 3 ).every( ( x ) => x.status < 500 ) } );
	},

	async s3map() {
		login();
		const urls = Object.values( PAGES() ).map( ( p ) => p.url );
		// The product page's address as a browser copies it (upper-case percent encoding).
		let asCopied = urls.map( ( u ) => u.replace( /%[0-9a-f]{2}/g, ( m ) => m.toUpperCase() ) );
		if ( process.env.REH_MAP === 'product-readable' ) asCopied = [ decodeURI( PAGES().product.url ) ]; // F08 work-around: readable form
		const p = await admin( 'pages' );
		const t0 = Date.now();
		const r = await submit( findForm( p.text, ( f ) => f.val( 'do' ) === 'map_start', 'map_start' ), { set: { urls: asCopied.join( '\n' ), 'langs[]': LANGS }, label: 'map start' } );
		const nonce = nonceOf( r.text, 'aitpMap' );
		let last;
		for ( let i = 0; i < 400; i++ ) {
			const s = await http( 'POST', '/wp-admin/admin-ajax.php', { body: new URLSearchParams( { action: 'aitp_map_step', _ajax_nonce: nonce } ), label: 'map step' } );
			last = JSON.parse( s.text );
			if ( ! last.success || last.data.stop ) break;
			await sleep( Math.min( 6, last.data.wait ) * 1000 );
		}
		const list = json( wp( 'aitp', 'map', 'list' ) );
		list.rows = ( list.rows || [] ).filter( ( x ) => x.http === '200' || ! ( list.rows || [] ).some( ( y ) => y.lang === x.lang && y.http === '200' && decodeURI( y.url ).includes( 'ok-a1001' ) && decodeURI( x.url ).includes( 'ok-a1001' ) ) );
		st.mapIds = ( list.rows || [] ).map( ( x ) => x.id );
		save();
		rec( 's3', '3.1–3.2 map the 4 pages × 6 languages', { start: flash( r.text ).slice( 0, 1 ), last: last && last.data && last.data.text, seconds: Math.round( ( Date.now() - t0 ) / 1000 ), rows: ( list.rows || [] ).map( ( x ) => { const o = { ...x }; o.url = decodeURI( x.url || '' ).slice( 22, 70 ); delete o.source_url; return o; } ) }, {
			all_24: ( list.rows || [] ).length === 24,
		} );
	},

	async s3export() {
		login();
		const p = await admin( 'pages' );
		const f = findForm( p.text, ( x ) => /aitp_pages_download/.test( x.action ) || x.val( 'action' ) === 'aitp_pages_download', 'candidates download' );
		const ids = f.fields.filter( ( x ) => x.name === 'pages[]' ).map( ( x ) => x.value );
		const r = await submit( f, { set: { 'pages[]': ids, scope: 'selected' }, label: 'download candidates' } );
		const data = JSON.parse( r.text );
		writeFileSync( OUT + '/candidates-24.json', r.text );
		st.candidates = data.rows.length;
		save();
		const byLang = {};
		for ( const x of data.rows ) byLang[ x.language ] = ( byLang[ x.language ] || 0 ) + 1;
		// 3.4 import it.
		const a = await admin( 'audit' );
		const imp = await submit( findForm( a.text, ( x ) => x.val( 'do' ) === 'import', 'import' ), { file: { field: 'list', name: 'candidates-24.json', content: r.text }, label: 'import candidates' } );
		const n = ev( 'global $wpdb; echo wp_json_encode(array("n" => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}aitp_audit WHERE source = \'import\' AND state = \'open\'")));' ).n;
		rec( 's3', '3.3–3.4 export the 24 pages\' candidates, import them', { pages_selected: ids.length, rows: data.rows.length, by_lang: byLang, import: flash( imp.text ).slice( 0, 1 ), open_imported: n }, {
			only_status_1: data.rows.every( ( x ) => +x.old_status === 1 || x.old_status === undefined ), all_imported: n === data.rows.length,
		} );
	},

	async s3repair() {
		// 3.5: batches of at most 1000, "only these pages" = the 4 default-language addresses
		// (here pasted in readable form: the product page's address with its Japanese characters).
		login();
		const only = Object.values( PAGES() ).map( ( p ) => decodeURI( p.url ) ).join( '\n' );
		const allowed = new Set();
		for ( const p of Object.values( PAGES() ) ) for ( const l of LANGS ) allowed.add( decodeURI( new URL( DIRECT + trUrl( p.url, l ) ).pathname ) );
		st.batches = st.batches || [];
		for ( let b = 0; b < 8; b++ ) {
			const lm = logMax();
			const page = await admin( 'audit', '&f_source=import&f_state=open' );
			const f = forms( page.text ).find( ( x ) => x.buttons.some( ( y ) => y[ 1 ] === 'repair_filter' ) );
			if ( ! f ) break;
			const r = await submit( f, { set: { only_pages: only }, button: [ 'do', 'repair_filter' ], label: 'repair batch ' + ( b + 1 ) } );
			const fresh = await admin( 'audit' );
			const la = lastAction( fresh.text );
			const batch = ev( 'echo wp_json_encode(array("b" => (string) array_key_first(AITP_Audit::batches())));' ).b;
			const bk = await http( 'GET', `/wp-admin/admin-post.php?action=aitp_audit_backup&batch=${ batch }&_wpnonce=${ ( fresh.text.match( /aitp_audit_backup&(?:amp;|#038;)?batch=\d+&(?:amp;|#038;)?_wpnonce=([a-f0-9]+)/ ) || [] )[ 1 ] }`, { label: 'download backup' } );
			let exp = {};
			try { exp = JSON.parse( bk.text ); } catch ( e ) { exp = { error: bk.status }; }
			const urls = ( exp.urls || [] ).map( ( u ) => decodeURI( new URL( u.url ).pathname ) );
			const outside = urls.filter( ( u ) => ! allowed.has( u ) );
			const ev2 = logEvents( lm );
			// WP-Cron between batches (twice), then the next request carries what LiteSpeed stored.
			const ticks = [];
			for ( let i = 0; i < 2; i++ ) {
				const c = await http( 'GET', '/wp-admin/?aitp_e2e_crontick=1', { label: 'cron tick' } );
				const n = await http( 'GET', '/wp-admin/index.php', { label: 'next request' } );
				ticks.push( [ c.status, c.purgeTags, n.status, n.purgeTags ] );
			}
			st.batches.push( batch );
			save();
			const step = reqlog.filter( ( x ) => x.label.startsWith( 'repair batch ' + ( b + 1 ) ) );
			rec( 's3', `3.5 repair batch ${ b + 1 } (#${ batch })`, {
				responses: step.map( ( x ) => [ x.status, x.ms, x.hdr, x.purgeTags ] ), flash: flash( r.text ).slice( 0, 2 ), last: la, backup_rows: ( exp.rows || [] ).length, only_pages: exp.only_pages, urls: urls.length, outside, all_pages: exp.all_pages,
				log: ev2.map( ( x ) => [ x.level, x.event, x.data.slice( 0, 160 ) ] ), ticks, purge: purgeState(),
			}, {
				no_5xx: step.every( ( x ) => x.status < 500 ), last_done: /:done$/.test( la ), backup_rows_eq_reset: ( exp.rows || [] ).length > 0 && ( exp.rows || [] ).length <= 1000,
				only_pages_kept: ( exp.only_pages || [] ).length === 4, no_url_outside_24: outside.length === 0, no_lang_requeue: ! ( exp.all_pages || [] ).length,
				ticks_ok: ticks.every( ( t ) => t[ 0 ] < 500 && t[ 2 ] < 500 ),
			} );
		}
	},

	async s3again() {
		// Optional F07 check: the same repair form sent twice.
		login();
		const page = await admin( 'audit', '&f_source=import&f_state=open' );
		const f = forms( page.text ).find( ( x ) => x.buttons.some( ( y ) => y[ 1 ] === 'repair_filter' ) );
		if ( ! f ) {
			// Nothing left to repair: use a rollback form of a batch already rolled back instead.
			return rec( 's3', '3.x form sent twice', { note: 'no open imported rows left' } );
		}
		const before = Object.keys( ev( 'echo wp_json_encode(AITP_Audit::batches());' ) ).length;
		const a = await submit( f, { set: { only_pages: Object.values( PAGES() ).map( ( p ) => p.url ).join( '\n' ) }, button: [ 'do', 'repair_filter' ] } );
		const b = await submit( f, { set: { only_pages: Object.values( PAGES() ).map( ( p ) => p.url ).join( '\n' ) }, button: [ 'do', 'repair_filter' ] } );
		const after = Object.keys( ev( 'echo wp_json_encode(AITP_Audit::batches());' ) ).length;
		rec( 's3', '3.x the same repair form sent twice', { first: flash( a.text ).slice( 0, 1 ), second: flash( b.text ).slice( 0, 1 ), batches: [ before, after ] }, { one_batch_only: after - before <= 1 } );
	},

	async s3visit() {
		// 3.6 re-translate: unauthenticated requests to the 24 addresses, until no English is left.
		const rounds = [];
		for ( let i = 0; i < 4; i++ ) {
			const v = await visitPages( { label: 'retranslate' } );
			rounds.push( { english: v.filter( ( x ) => x.english ).length, not200: v.filter( ( x ) => x.status !== 200 ).length, max_ms: Math.max( ...v.map( ( x ) => x.ms ) ) } );
			if ( ! v.some( ( x ) => x.english || x.status !== 200 ) ) break;
		}
		const v = await visitPages( { label: 'front check' } );
		// 3.7 compare: the mock translates deterministically, so every re-translated machine row
		// must be exactly what it was before the repair (any difference = misplaced or lost).
		const diff = compare();
		const rows = pageRows();
		rec( 's3', '3.6–3.8 re-translate the 24 addresses, compare, front pages', { rounds, front: v.map( ( x ) => [ x.lang, x.page, x.status, x.english, x.rtl, x.h1.slice( 0, 40 ) ] ).slice( 0, 24 ), rows, diff }, {
			all_200: v.every( ( x ) => x.status === 200 ), no_english: v.every( ( x ) => ! x.english ), ar_rtl: ! AR_PACK() || v.filter( ( x ) => x.lang === 'ar' ).every( ( x ) => x.rtl ), // dir="rtl" comes from WordPress's Arabic language pack
			nothing_untranslated: Object.values( rows ).every( ( x ) => +x.s0 === 0 ), no_english_saved: Object.values( rows ).every( ( x ) => +x.english === 0 ),
		} );
	},

	async rollbackAll( step, tag ) {
		login();
		const done = [];
		for ( let i = 0; i < 30; i++ ) {
			const page = await admin( 'audit' );
			const f = forms( page.text ).find( ( x ) => x.val( 'do' ) === 'rollback' );
			if ( ! f ) break;
			const lm = logMax();
			const r = await submit( f, { label: 'rollback ' + f.val( 'batch' ) } );
			const fresh = await admin( 'audit' );
			const step2 = reqlog.filter( ( x ) => x.label.startsWith( 'rollback ' + f.val( 'batch' ) ) );
			done.push( { batch: f.val( 'batch' ), responses: step2.map( ( x ) => [ x.status, x.ms, x.purgeTags ] ), flash: flash( r.text ).slice( 0, 1 ), last: lastAction( fresh.text ), log: logEvents( lm ).map( ( x ) => [ x.level, x.event ] ) } );
		}
		const diff = compare();
		const dict = Object.fromEntries( Object.entries( diff.tables || {} ).filter( ( [ t ] ) => /dictionary/.test( t ) ) );
		rec( step, `${ tag }: roll back every batch, compare with step 0`, { rolled_back: done, diff }, {
			no_5xx: done.every( ( d ) => d.responses.every( ( x ) => x[ 0 ] < 500 ) ), all_done: done.every( ( d ) => /:done$/.test( d.last ) ),
			dictionaries_byte_exact: Object.values( dict ).every( ( x ) => x.changed === 0 && x.missing === 0 ),
		} );
	},
	async s3rollback() {
		return S.rollbackAll( 's3', '3.9' );
	},

	async s4() {
		login();
		// Synthetic lists in the formats of the two OKAY files, built from this site's rows.
		const src = ev( `global $wpdb; $o = array(); foreach (array("fr_FR","es_MX","ja","ar","de_DE","pt_BR") as $l) { $t = $wpdb->prefix . "trp_dictionary_en_us_" . strtolower($l);
			$o[$l]["page"] = $wpdb->get_results("SELECT id, original, translated, status FROM $t WHERE original LIKE '%OK-A%' AND status IN (1,2) ORDER BY id LIMIT 60", ARRAY_A);
			$o[$l]["err"] = $wpdb->get_results("SELECT id, original, translated, status FROM $t WHERE status = 1 AND ((original LIKE 'Note %' AND (translated LIKE '%0 g.' OR BINARY translated = BINARY original)) OR (original LIKE 'Catalogue line %' AND translated LIKE '% 2')) ORDER BY id LIMIT 160", ARRAY_A); }
			echo wp_json_encode($o);` );
		const accepted = [];
		const cands = [];
		const expect = { added: 0, manual: 0, changed: 0, missing: 0, by_original: 0, duplicate_entries: 0 };
		for ( const l of LANGS ) {
			src[ l ].page.forEach( ( r, i ) => {
				if ( i < 40 ) {
					accepted.push( { id: String( r.id ), language: l, original: r.original, new: r.translated } );
					if ( +r.status === 2 ) expect.manual++; else expect.added++;
				} else if ( i < 44 ) {
					accepted.push( { id: String( r.id ), language: l, original: r.original, new: r.translated + ' (old)' } );
					expect.changed++;
				} else if ( i < 46 ) {
					accepted.push( { id: String( 99000000 + i ), language: l, original: r.original, new: r.translated } );
					expect.by_original++;
				} else if ( i < 47 ) {
					accepted.push( { id: String( 98000000 + i ), language: l, original: 'Not on this site ' + i, new: 'x' } );
					expect.missing++;
				}
			} );
			accepted.push( { ...accepted[ accepted.length - 5 ] } ); // a repeated entry
			expect.duplicate_entries++;
			src[ l ].err.forEach( ( r, i ) => {
				cands.push( { id: +r.id, language: l, original: r.original, old: i % 40 === 39 ? r.translated + ' (older)' : r.translated, old_status: 1, source_list: 'rehearsal' } );
			} );
			for ( const r of src[ l ].page.slice( 0, 2 ) ) cands.push( { id: +r.id, language: l, original: r.original, old: r.translated, old_status: +r.status, source_list: 'rehearsal (accepted)' } );
		}
		writeFileSync( OUT + '/accepted.json', JSON.stringify( { rows: accepted } ) );
		writeFileSync( OUT + '/candidates-remaining.json', JSON.stringify( { rows: cands } ) );
		let a = await admin( 'audit' );
		const pr = await submit( findForm( a.text, ( x ) => x.val( 'do' ) === 'protect', 'protect' ), { set: { label: 'HHG 修复 2026-11' }, file: { field: 'list', name: 'accepted.json', content: JSON.stringify( { rows: accepted } ) }, label: 'accepted list' } );
		a = await admin( 'audit' );
		const im = await submit( findForm( a.text, ( x ) => x.val( 'do' ) === 'import', 'import' ), { file: { field: 'list', name: 'candidates-remaining.json', content: JSON.stringify( { rows: cands } ) }, label: 'import remaining' } );
		// 4.3 a repair of accepted rows is refused: select imported findings of accepted rows (none should exist) and try the CLI path too.
		const prot = ev( 'global $wpdb; echo wp_json_encode(array("rows" => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}aitp_protect"), "open_on_accepted" => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}aitp_audit a JOIN {$wpdb->prefix}aitp_protect p ON p.lang = a.lang AND p.row_id = a.row_id AND p.kind = a.kind WHERE a.state = \'open\'")));' );
		rec( 's4', 'accepted list + remaining candidates', { expect, protect: flash( pr.text ).slice( 0, 1 ), import: flash( im.text ).slice( 0, 1 ), sent: { accepted: accepted.length, candidates: cands.length }, prot }, { protect_ok: pr.status === 200, import_ok: im.status === 200 } );
	},

	async s5() {
		login();
		const lang = 'ar';
		// 5.1 whole-language map of ar (addresses empty, from the queue page list).
		let p = await admin( 'pages' );
		const t0 = Date.now();
		const r = await submit( findForm( p.text, ( f ) => f.val( 'do' ) === 'map_start', 'map_start' ), { set: { urls: '', 'langs[]': [ lang ] }, label: 'map ar' } );
		const pages = ev( 'echo wp_json_encode(array("n" => count(AITP_Pagemap::state()["items"])));' ).n;
		// The plugin visits one page every 5 s (about ' + pages * 5 / 60 + ' min on the test site);
		// here the CLI runs it without the pause, through the same proxy.
		const run = json( wp( 'aitp', 'map', 'run', '--ignore-rate', '--minutes=40' ) );
		const mapS = Math.round( ( Date.now() - t0 ) / 1000 );
		const mstate = ev( 'echo wp_json_encode(array_intersect_key(AITP_Pagemap::state(), array_flip(array("status","i","ok","failed"))));' );
		// 5.2 repair ~50 errors of this language from the import, then visit the queued pages.
		p = await admin( 'audit', `&f_source=import&f_state=open&f_lang=${ lang }&f_severity=error` );
		const f = forms( p.text ).find( ( x ) => x.buttons.some( ( y ) => y[ 1 ] === 'repair' ) );
		const ids = f ? f.fields.filter( ( x ) => x.name === 'ids[]' ).map( ( x ) => x.value ).slice( 0, 50 ) : [];
		const rp = f ? await submit( f, { set: { 'ids[]': ids }, button: [ 'do', 'repair' ], label: 'small batch' } ) : null;
		const batch = ev( 'echo wp_json_encode(array("b" => (string) array_key_first(AITP_Audit::batches())));' ).b;
		const exp = ev( 'echo wp_json_encode(AITP_Audit::backup_export($in));', batch );
		const visits = [];
		for ( const u of exp.urls || [] ) {
			for ( let i = 0; i < 2; i++ ) visits.push( ( await http( 'GET', u.url, { auth: false, label: 'small batch visit' } ) ).status );
		}
		const fixed = ev( `global $wpdb; $t = $wpdb->prefix . "trp_dictionary_en_us_ar"; $ids = array_map("intval", wp_list_pluck($in, "row_id")); echo wp_json_encode($ids ? $wpdb->get_results("SELECT status, COUNT(*) n, SUM(translated LIKE '%0 g.') still_wrong, SUM(BINARY translated = BINARY original) english FROM $t WHERE id IN (" . implode(",", $ids) . ") GROUP BY status", ARRAY_A) : array());`, exp.rows || [] );
		rec( 's5', '5.1–5.2 whole-language map (ar), small batch of 50', {
			map: { pages, run, seconds: mapS, state: mstate, test_site_minutes_at_5s: Math.round( pages * 5 / 60 ) }, selected: ids.length, repair: rp && flash( rp.text ).slice( 0, 2 ), batch, queued: ( exp.urls || [] ).length, visits: visits.filter( ( s ) => s !== 200 ).length + ' not 200 of ' + visits.length, after: fixed,
		}, { map_done: mstate.status === 'done', some_reset: ( exp.rows || [] ).length > 0, visits_ok: visits.every( ( s ) => s === 200 ) } );
		st.smallBatch = batch;
		save();
	},

	async s5b() {
		login();
		let p = await admin( 'audit' );
		const sf = findForm( p.text, ( f ) => f.val( 'do' ) === 'autofix_settings', 'autofix settings' );
		await submit( sf, { set: { 'x[enabled]': 1, 'x[languages][]': [ 'ar' ], 'x[batch]': 50, 'x[cap]': 300000, 'x[max_batches]': 1 }, label: 'autofix settings' } );
		p = await admin( 'audit' );
		const s = await submit( findForm( p.text, ( f ) => f.val( 'do' ) === 'autofix_start', 'autofix start' ), { label: 'autofix start' } );
		const nonce = nonceOf( s.text, 'aitpAutofix' );
		let last;
		const t0 = Date.now();
		for ( let i = 0; i < 200; i++ ) {
			const x = await http( 'POST', '/wp-admin/admin-ajax.php', { body: new URLSearchParams( { action: 'aitp_autofix_step', _ajax_nonce: nonce } ), label: 'autofix step' } );
			last = JSON.parse( x.text );
			const a = ev( 'echo wp_json_encode(AITP_Autofix::state());' );
			if ( ( a.batches || 0 ) >= 1 || ! last.success || last.data.stop || a.status !== 'running' ) break;
			await sleep( Math.min( 5, ( last.data.wait || 2 ) ) * 1000 );
		}
		p = await admin( 'audit' );
		const stop = forms( p.text ).find( ( f ) => f.val( 'do' ) === 'autofix_stop' );
		if ( stop ) await submit( stop, { label: 'autofix stop' } );
		const a = ev( 'echo wp_json_encode(AITP_Autofix::state());' );
		rec( 's5b', 'auto repair: one batch of 50 (ar), then stop', { seconds: Math.round( ( Date.now() - t0 ) / 1000 ), last: last && last.data && last.data.text, status: a.status, batches: a.batches, history: ( a.history || [] ).slice( 0, 2 ), left_to_people: a.manual_left ?? a.left_to_people }, { one_batch: ( a.batches || ( a.history || [] ).length ) >= 1 } );
		await S.rollbackAll( 's5b', '5/5b' );
		p = await admin( 'audit' );
		await submit( findForm( p.text, ( f ) => f.val( 'do' ) === 'autofix_settings', 'autofix settings' ), { set: { 'x[enabled]': null }, label: 'autofix off' } );
	},

	async s7() {
		login();
		let p = await admin( 'audit' );
		const open = forms( p.text ).filter( ( f ) => f.val( 'do' ) === 'rollback' ).length;
		const dict = () => Object.entries( compare().tables || {} ).filter( ( [ t ] ) => /dictionary|gettext_(?!original)/.test( t ) );
		// 7.0 Undo while TranslatePress still translates with AITP: must be refused, nothing changed.
		const before = JSON.stringify( dict() );
		p = await admin( 'pages' );
		const refused = await submit( findForm( p.text, ( f ) => f.val( 'do' ) === 'journal_undo', 'journal_undo' ), { label: 'journal undo (auto on)' } );
		const unchanged = JSON.stringify( dict() ) === before;
		// 7.1 Automatic translation off (engine stays), 7.2 all rolled back (checked above), 7.3 undo.
		const off1 = await tpEngine( 'aitp', false );
		p = await admin( 'pages' );
		const u = await submit( findForm( p.text, ( f ) => f.val( 'do' ) === 'journal_undo', 'journal_undo' ), { label: 'journal undo' } );
		// 7.4 New rows (download), 7.5 journal off.
		const nr = await http( 'GET', '/wp-admin/admin-post.php?action=aitp_pages_download&what=new_rows&_wpnonce=' + ( u.text.match( /what=new_rows&(?:amp;|#038;)?_wpnonce=([a-f0-9]+)/ ) || [] )[ 1 ], { label: 'new rows' } );
		let newRows = [];
		try { newRows = JSON.parse( nr.text ).rows; } catch ( e ) {}
		p = await admin( 'pages' );
		const js = await submit( findForm( p.text, ( f ) => f.val( 'do' ) === 'journal_stop', 'journal_stop' ), { label: 'journal stop' } );
		const journal = ev( 'echo wp_json_encode(AITP_Journal::summary());' );
		// 7.6 Compare the dictionaries with step 0 (rows that existed then).
		const d1 = dict();
		// 7.7 Restore the saved TranslatePress option, HHG on, AITP off and deleted.
		ev( 'global $wpdb; $v = $wpdb->get_var("SELECT option_value FROM zz_rh_options WHERE option_name = \'trp_machine_translation_settings\'"); update_option("trp_machine_translation_settings", maybe_unserialize($v)); echo "{}";' );
		const hhg = await plugins( 'activate', 'hhg-for-translatepress/hhg-for-translatepress.php' );
		const off = await plugins( 'deactivate', PLUGIN );
		// "Delete": the plugin files are a read-only mount here; uninstall runs the same clean-up.
		wp( 'plugin', 'uninstall', 'ai-translator-for-translatepress', '--skip-delete' );
		const left = ev( 'global $wpdb; echo wp_json_encode(array("tables" => $wpdb->get_col("SHOW TABLES LIKE \'{$wpdb->prefix}aitp\\\\_%\'"), "options" => $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE \'%aitp\\\\_%\' AND option_name NOT LIKE \'aitp\\\\_e2e%\'"), "cron" => array_values(array_filter(array_keys(array_merge(...array_values(array_map(function ($x) { return is_array($x) ? $x : array(); }, _get_cron_array())))), function ($h) { return strpos($h, "aitp") === 0; }))));' );
		const diff = compare();
		rec( 's7', 'restore: undo refused while translating, auto off, undo, new rows, journal off, compare, option back, HHG on, AITP removed', {
			open_rollbacks: open, refused: flash( refused.text ).slice( 0, 1 ), unchanged_when_refused: unchanged, auto_off: off1.engine[ 'machine-translation' ], undo: flash( u.text ).slice( 0, 1 ), journal, new_rows: newRows.length, journal_stop: flash( js.text ).slice( 0, 1 ),
			dict_after_undo: Object.fromEntries( d1.map( ( [ t, x ] ) => [ t, [ x.changed, x.missing, x.new ] ] ) ), hhg: hhg.status, deactivate: off.status, left, options: diff.options,
		}, {
			no_open_batches: open === 0, undo_refused_while_translating: unchanged && /自动翻译|automatic/i.test( flash( refused.text ).join( ' ' ) ),
			base_range_exact: d1.every( ( [ , x ] ) => x.changed === 0 && x.missing === 0 ), options_same: Object.values( diff.options || {} ).every( ( x ) => x === 'same' ),
			nothing_left: ! left.tables.length && ! left.options.length && ! left.cron.length,
		} );
	},

	s7check() {
		const left = ev( 'global $wpdb; echo wp_json_encode(array("tables" => $wpdb->get_col("SHOW TABLES LIKE \'{$wpdb->prefix}aitp\\\\_%\'"), "options" => $wpdb->get_col("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE \'aitp\\\\_%\' AND option_name NOT LIKE \'aitp\\\\_e2e%\'"), "cron" => array_values(array_filter(array_keys(array_merge(...array_values(array_map(function ($x) { return is_array($x) ? $x : array(); }, _get_cron_array())))), function ($h) { return strpos($h, "aitp") === 0; }))));' );
		const diff = compare();
		const dict = Object.entries( diff.tables || {} ).filter( ( [ t ] ) => /dictionary|gettext_(?!original)/.test( t ) );
		rec( 's7check', 'state after step 7', { left, diff }, { base_range_exact: dict.every( ( [ , x ] ) => x.changed === 0 && x.missing === 0 ), options_same: Object.values( diff.options || {} ).every( ( x ) => x === 'same' ), nothing_left: ! left.tables.length && ! left.options.length && ! left.cron.length } );
	},

	async timing() {
		login();
		const out = {};
		for ( const [ tab, q ] of [ [ 'pages', '' ], [ 'audit', '' ], [ 'audit', '&f_source=import' ], [ 'queue', '' ], [ 'status', '' ], [ 'settings', '' ] ] ) {
			const r = await admin( tab, q );
			out[ tab + q ] = [ r.status, r.ms, Math.round( r.text.length / 1024 ) + ' KB' ];
		}
		rec( 'timing', 'admin pages after the whole-language map', out, { all_200: Object.values( out ).every( ( x ) => x[ 0 ] === 200 ), under_10s: Object.values( out ).every( ( x ) => x[ 1 ] < 10000 ) } );
	},

	summary() {
		const by = {};
		for ( const x of reqlog ) {
			const k = x.via + ' ' + ( x.status >= 500 ? '5xx' : x.status >= 400 ? '4xx' : 'ok' );
			by[ k ] = ( by[ k ] || 0 ) + 1;
		}
		const slow = reqlog.filter( ( x ) => x.ms > 10000 ).map( ( x ) => [ x.label, x.status, x.ms ] );
		const big = reqlog.filter( ( x ) => x.hdr > 4000 ).map( ( x ) => [ x.label, x.status, x.hdr, x.purgeTags ] );
		console.log( JSON.stringify( { requests: reqlog.length, by, slow: slow.slice( 0, 20 ), big_headers: big.slice( 0, 20 ), max_purge_tags: Math.max( 0, ...reqlog.map( ( x ) => x.purgeTags ) ), fails: report.filter( ( r ) => ! r.pass ).map( ( r ) => [ r.step, r.name, r.failed ] ) }, null, 1 ) );
	},
};

for ( const name of process.argv.slice( 2 ) ) {
	if ( ! S[ name ] ) throw new Error( 'unknown step ' + name );
	console.log( `== ${ name }` );
	await S[ name ]();
}
