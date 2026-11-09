// End-to-end scenarios: node dev/e2e/scenarios.mjs [name …]
import { execFileSync } from 'node:child_process';
import { writeFileSync, mkdirSync } from 'node:fs';
import { dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const DIR = dirname( fileURLToPath( import.meta.url ) );
const WP = 'http://127.0.0.1:8088';
const MOCK = 'http://127.0.0.1:8089';
const SLUG = { fr_FR: 'fr', es_MX: 'es', ja: 'ja', ar: 'ar', de_DE: 'de', pt_BR: 'pt' };
const LANGS = Object.keys( SLUG );
const PAGES = [ 'okay-home', 'okay-collection', 'okay-product', 'okay-article', 'edge-cases' ];

function wp( ...args ) {
	const out = execFileSync( DIR + '/wp.sh', args, { encoding: 'utf8', stdio: [ 'ignore', 'pipe', 'pipe' ] } );
	return out.split( '\n' ).filter( ( l ) => ! /Container /.test( l ) ).join( '\n' ).trim();
}
const json = ( s ) => JSON.parse( s.slice( s.indexOf( '{' ) ) );
const reset = () => { wp( 'eval-file', '/e2e/reset.php' ); return fetch( MOCK + '/__mock/reset', { method: 'POST' } ); };
const mock = ( cfg ) => fetch( MOCK + '/__mock/config', { method: 'POST', body: JSON.stringify( cfg ) } );
const mockStats = async () => ( await fetch( MOCK + '/__mock/stats' ) ).json();
const verify = ( langs ) => json( wp( 'eval-file', '/e2e/verify.php', langs.join( ',' ) ) );
const state = () => json( wp( 'eval-file', '/e2e/state.php' ) );
const setOpt = ( obj ) => wp( 'eval', `$s = get_option('aitp_settings'); foreach (json_decode('${ JSON.stringify( obj ) }', true) as $k => $v) $s[$k] = $v; update_option('aitp_settings', $s); echo 'ok';` );
const setLimit = ( n ) => wp( 'eval', `$m = get_option('trp_machine_translation_settings'); $m['machine_translation_limit'] = ${ n }; update_option('trp_machine_translation_settings', $m); echo 'ok';` );

async function visit( lang, page ) {
	const t = Date.now();
	const r = await fetch( `${ WP }/${ SLUG[ lang ] }/${ page }/`, { headers: { 'user-agent': 'aitp-e2e' } } );
	await r.text();
	return { lang, page, status: r.status, ms: Date.now() - t };
}

function sum( v ) {
	const o = { rows: 0, status0: 0, status1: 0, mismatch: 0, english_saved: 0 };
	for ( const l of Object.values( v ) ) for ( const k of Object.keys( o ) ) o[ k ] += l[ k ] || 0;
	return o;
}

const results = [];
function record( name, data, checks ) {
	const failed = Object.entries( checks ).filter( ( [ , ok ] ) => ! ok ).map( ( [ k ] ) => k );
	results.push( { name, pass: failed.length === 0, failed, ...data } );
	console.log( `${ failed.length ? 'FAIL' : 'PASS' }  ${ name }  ${ failed.join( ', ' ) }` );
	console.log( '      ' + JSON.stringify( data ).slice( 0, 600 ) );
}

const S = {
	async baseline() {
		await reset();
		const visits = [];
		for ( const l of LANGS ) for ( const p of PAGES ) visits.push( await visit( l, p ) );
		const v = verify( LANGS );
		const s = sum( v );
		const st = state();
		record( 'baseline: 5 pages × 6 languages', { visits: visits.length, max_ms: Math.max( ...visits.map( ( x ) => x.ms ) ), ...s, requests: st.requests, tp_counter: st.tp_counter, sent_bytes: st.sent_bytes, fail_reasons: st.fail_reasons, per_lang: Object.fromEntries( Object.entries( v ).map( ( [ k, x ] ) => [ k, `${ x.status1 }/${ x.rows }` ] ) ) }, {
			all_http_200: visits.every( ( x ) => x.status === 200 ),
			no_mismatch: s.mismatch === 0,
			no_english_saved: s.english_saved === 0,
			nearly_all_translated: s.status1 >= s.rows * 0.99,
			quota_counter_equals_sent: st.tp_counter === st.sent_bytes,
		} );
	},

	async faults() {
		const cases = [
			{ mode: 'shuffle', lang: 'fr_FR', expect: 'all' },
			{ mode: 'drop', lang: 'es_MX', expect: 'all', times: 1 },
			{ mode: 'drop', lang: 'pt_BR', expect: 'safe', page: 'okay-product' },
			{ mode: 'dup', lang: 'de_DE', expect: 'all', times: 2 },
			{ mode: 'extra', lang: 'pt_BR', expect: 'all', times: 2 },
			{ mode: 'bad_json', lang: 'ja', expect: 'all', times: 2 },
			{ mode: 'e503', lang: 'ar', expect: 'all', times: 2 },
			{ mode: 'schema_unsupported', lang: 'fr_FR', expect: 'all', times: 1, page: 'okay-collection' },
			{ mode: 'shift', lang: 'ja', expect: 'none' },
			{ mode: 'shift', lang: 'fr_FR', expect: 'none', page: 'okay-article' },
			{ mode: 'shift', lang: 'de_DE', expect: 'all', times: 1, page: 'okay-product' },
			{ mode: 'truncate', lang: 'ar', expect: 'none' },
			{ mode: 'english', lang: 'fr_FR', expect: 'none' },
			{ mode: 'short', lang: 'de_DE', expect: 'none', page: 'okay-article' },
			{ mode: 'e429', lang: 'pt_BR', expect: 'none' },
			{ mode: 'e401', lang: 'es_MX', expect: 'none' },
		];
		for ( const c of cases ) {
			await reset();
			const page = c.page || 'okay-home';
			await mock( { mode: c.mode, times: c.times || 0, target: '' } );
			const v1 = await visit( c.lang, page );
			const ver = verify( [ c.lang ] )[ c.lang ];
			const st = state();
			const ms = await mockStats();
			// While the breaker is open/paused another page view sends nothing.
			let blocked = null;
			if ( st.breaker.status !== 'closed' ) {
				await visit( c.lang, 'okay-collection' );
				blocked = ( await mockStats() ).requests === ms.requests;
			}
			// Recovery: provider healthy again, admin presses "retry now" (or the backoff expires).
			await mock( { mode: 'ok' } );
			wp( 'eval', 'global $wpdb; delete_option("aitp_breaker"); $wpdb->query("DELETE FROM {$wpdb->prefix}aitp_failures"); echo 1;' );
			const v2 = await visit( c.lang, page );
			if ( blocked !== null ) await visit( c.lang, 'okay-collection' );
			const after = verify( [ c.lang ] )[ c.lang ];
			const data = { lang: c.lang, page, ms: v1.ms, blocked, first: `${ ver.status1 }/${ ver.rows }`, mismatch: ver.mismatch, english: ver.english_saved, requests: ms.requests, breaker: st.breaker, reasons: st.fail_reasons, counter: st.tp_counter, sent: st.sent_bytes, after: `${ after.status1 }/${ after.rows }`, after_mismatch: after.mismatch, samples: ver.samples.slice( 0, 2 ) };
			const checks = {
				http_200: v1.status === 200 && v2.status === 200,
				no_mismatch: ver.mismatch === 0 && after.mismatch === 0,
				no_english_saved: ver.english_saved === 0,
				quota_counter_equals_sent: st.tp_counter === st.sent_bytes,
				recovered: after.status1 >= after.rows * 0.95,
			};
			if ( c.expect === 'all' ) checks.all_translated_first_visit = ver.status1 >= ver.rows * 0.95;
			if ( c.expect === 'none' ) checks.nothing_saved = ver.status1 === 0;
			if ( c.mode === 'e429' ) checks.breaker_open = st.breaker.status === 'open';
			if ( c.mode === 'e401' ) checks.breaker_paused = st.breaker.status === 'paused';
			if ( c.mode === 'e429' || c.mode === 'e401' ) checks.at_most_one_wave = ms.requests <= 3;
			if ( blocked !== null ) checks.breaker_blocks_next_view = blocked;
			if ( c.mode === 'schema_unsupported' ) checks.schema_downgraded = JSON.stringify( st.caps ).includes( '"schema":false' );
			record( `fault ${ c.mode } (${ c.lang }, ${ page })`, data, checks );
		}
		await mock( { mode: 'ok' } );
	},

	async timeout() {
		await reset();
		setOpt( { http_timeout: 10, time_budget: 20 } );
		await mock( { mode: 'timeout' } );
		const v = await visit( 'fr_FR', 'okay-product' );
		const ver = verify( [ 'fr_FR' ] ).fr_FR;
		const st = state();
		await mock( { mode: 'ok' } );
		setOpt( { http_timeout: 30, time_budget: 25 } );
		record( 'timeout: provider hangs', { ms: v.ms, plugin_translated: st.translated, rows: ver.rows, reasons: st.fail_reasons, breaker: st.breaker, counter: st.tp_counter, sent: st.sent_bytes }, {
			page_finishes_within_budget: v.ms < 25000,
			plugin_saved_nothing: st.translated === 0,
			no_mismatch: ver.mismatch === 0,
			timed_out_bytes_counted: st.tp_counter === st.sent_bytes && st.sent_bytes > 0,
		} );
	},

	async quota() {
		await reset();
		setLimit( 3000 );
		await visit( 'es_MX', 'okay-article' );
		const ver = verify( [ 'es_MX' ] ).es_MX;
		const st = state();
		setLimit( 1000000 );
		record( 'quota: TranslatePress daily limit 3000', { saved: ver.status1, rows: ver.rows, counter: st.tp_counter, reasons: st.fail_reasons }, {
			counter_within_limit: st.tp_counter <= 3000,
			partial: ver.status1 > 0 && ver.status1 < ver.rows,
			no_mismatch: ver.mismatch === 0,
		} );
	},

	async concurrent() {
		await reset();
		await mock( { mode: 'ok', delay_ms: 1500 } );
		const vs = await Promise.all( [ visit( 'de_DE', 'okay-article' ), visit( 'de_DE', 'okay-article' ), visit( 'de_DE', 'okay-article' ) ] );
		const ms = await mockStats();
		const ver = verify( [ 'de_DE' ] ).de_DE;
		await mock( { mode: 'ok' } );
		const items = ms.log.filter( ( x ) => /German/.test( x.target ) ).reduce( ( a, x ) => a + x.items, 0 );
		record( 'concurrent: 3 simultaneous views of one page', { items_sent: items, rows: ver.rows, saved: ver.status1, ms: vs.map( ( x ) => x.ms ) }, {
			no_double_translation: items <= ver.rows * 1.1,
			no_mismatch: ver.mismatch === 0,
		} );
	},

	async manual() {
		await reset();
		await visit( 'es_MX', 'okay-home' );
		// A human edits two translations (status 2) and two strings are reset to untranslated.
		const out = wp( 'eval', `global $wpdb; $t = $wpdb->prefix . 'trp_dictionary_en_us_es_mx';
			$ids = $wpdb->get_col("SELECT id FROM $t WHERE status = 1 AND id > 10 ORDER BY id LIMIT 4");
			$wpdb->query("UPDATE $t SET translated = 'TRADUCCION MANUAL', status = 2 WHERE id IN ({$ids[0]}, {$ids[1]})");
			$wpdb->query("UPDATE $t SET translated = '', status = 0 WHERE id IN ({$ids[2]}, {$ids[3]})");
			$wpdb->query("TRUNCATE {$wpdb->prefix}aitp_cache"); $wpdb->query("TRUNCATE {$wpdb->prefix}trp_machine_translation_locks");
			echo json_encode($ids);` );
		const ids = JSON.parse( out.slice( out.indexOf( '[' ) ) );
		await mock( { mode: 'ok' } );
		const before = ( await mockStats() ).log.length;
		await visit( 'es_MX', 'okay-home' );
		const log = ( await mockStats() ).log.slice( before );
		const rows = JSON.parse( wp( 'eval', `global $wpdb; echo json_encode($wpdb->get_results("SELECT id, status, translated FROM {$wpdb->prefix}trp_dictionary_en_us_es_mx WHERE id IN (${ ids.join( ',' ) })", ARRAY_A));` ).replace( /^[^\[]*/, '' ) );
		const byId = Object.fromEntries( rows.map( ( r ) => [ r.id, r ] ) );
		record( 'manual translations (status 2) untouched', { items_sent: log.reduce( ( a, x ) => a + x.items, 0 ), rows }, {
			manual_kept: [ ids[ 0 ], ids[ 1 ] ].every( ( i ) => byId[ i ].status === '2' && byId[ i ].translated === 'TRADUCCION MANUAL' ),
			reset_rows_filled: [ ids[ 2 ], ids[ 3 ] ].every( ( i ) => byId[ i ].status === '1' && byId[ i ].translated !== '' ),
			only_untranslated_sent: log.reduce( ( a, x ) => a + x.items, 0 ) === 2,
		} );
	},

	async openai() {
		await reset();
		wp( 'eval-file', '/e2e/configure.php', 'openai' );
		const vs = [];
		for ( const l of [ 'fr_FR', 'ja', 'ar' ] ) vs.push( await visit( l, 'okay-home' ) );
		await mock( { mode: 'drop', times: 1 } );
		vs.push( await visit( 'es_MX', 'okay-home' ) );
		const v = verify( [ 'fr_FR', 'ja', 'ar', 'es_MX' ] );
		const s = sum( v );
		const ms = await mockStats();
		wp( 'eval-file', '/e2e/configure.php', 'gemini' );
		record( 'OpenAI-compatible API', { ...s, apis: [ ...new Set( ms.log.map( ( x ) => x.api ) ) ], schema: ms.log[ 0 ] && ms.log[ 0 ].schema }, {
			used_openai_api: ms.log.every( ( x ) => x.api === 'openai' ),
			key_never_in_url: ms.log.every( ( x ) => ! x.key_in_url ),
			no_mismatch: s.mismatch === 0,
			all_translated: s.status1 >= s.rows * 0.99,
		} );
	},
};

const only = process.argv.slice( 2 );
for ( const [ name, fn ] of Object.entries( S ) ) {
	if ( only.length && ! only.includes( name ) ) continue;
	await fn();
}
mkdirSync( DIR + '/.state', { recursive: true } );
writeFileSync( DIR + '/.state/report.json', JSON.stringify( results, null, 2 ) );
const failed = results.filter( ( r ) => ! r.pass ).length;
console.log( `\n${ results.length - failed } passed, ${ failed } failed` );
process.exit( failed ? 1 : 0 );
