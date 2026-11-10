// End-to-end scenarios: node dev/e2e/scenarios.mjs [name …]
import { execFileSync, execFile } from 'node:child_process';
import { writeFileSync, mkdirSync, readFileSync } from 'node:fs';
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
// The mock server (and Apache) sometimes close a kept-alive connection: retry the control calls.
async function fetchRetry( url, opts ) {
	for ( let i = 0; ; i++ ) {
		try {
			return await fetch( url, opts );
		} catch ( e ) {
			if ( i >= 3 ) throw e;
			await new Promise( ( r ) => setTimeout( r, 500 ) );
		}
	}
}
const reset = () => { wp( 'eval-file', '/e2e/reset.php' ); return fetchRetry( MOCK + '/__mock/reset', { method: 'POST' } ); };
const mock = ( cfg ) => fetchRetry( MOCK + '/__mock/config', { method: 'POST', body: JSON.stringify( cfg ) } );
const mockStats = async () => ( await fetchRetry( MOCK + '/__mock/stats' ) ).json();
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

	async queue() {
		const q = ( ...a ) => wp( 'aitp', 'queue', ...a );
		const qset = ( obj ) => wp( 'eval', `update_option('aitp_queue_settings', array_merge(AITP_Queue::defaults(), json_decode('${ JSON.stringify( obj ) }', true)), false); echo 'ok';` );
		const qstatus = () => json( q( 'status' ) );
		const run = ( ...extra ) => json( q( 'run', '--ignore-rate', '--minutes=8', ...extra ) );
		const fresh = async () => {
			await reset();
			wp( 'eval', 'global $wpdb; $wpdb->query("TRUNCATE {$wpdb->prefix}aitp_queue"); delete_option("aitp_queue_state"); delete_option("aitp_queue_lock"); delete_option("aitp_e2e_page_cache"); exec("rm -rf " . WP_CONTENT_DIR . "/e2e-cache"); echo 1;' );
			qset( {} );
			q( 'sync' );
			q( 'start' );
		};
		const totals = ( p ) => Object.values( p ).reduce( ( a, by ) => { for ( const [ k, n ] of Object.entries( by ) ) a[ k ] = ( a[ k ] || 0 ) + n; return a; }, {} );

		// 1. Whole site translated with no visitor at all.
		await fresh();
		let t0 = Date.now();
		let r = run();
		let st = qstatus();
		let v = verify( LANGS );
		let s = sum( v );
		const ms = await mockStats();
		record( 'queue: whole site from scratch, no visitors', { run: r, secs: Math.round( ( Date.now() - t0 ) / 1000 ), progress: totals( st.progress ), ...s, requests: ms.requests, day_bytes: st.state.day_bytes, tp_counter: state().tp_counter }, {
			all_pages_done: ( totals( st.progress ).done || 0 ) === 54,
			all_strings_translated: s.status1 === s.rows && s.rows > 2000,
			no_mismatch: s.mismatch === 0,
			no_english_saved: s.english_saved === 0,
			queue_chars_match_tp_counter: st.state.day_bytes === state().tp_counter,
		} );

		// 2. Interrupted and resumed; a stale lock from a crashed run is taken over.
		await fresh();
		const r1 = json( q( 'run', '--ignore-rate', '--pages=7' ) );
		const mid = totals( qstatus().progress );
		wp( 'option', 'update', 'aitp_queue_lock', String( Math.floor( Date.now() / 1000 ) + 600 ) );
		const busy = json( q( 'run', '--ignore-rate', '--pages=1' ) );
		wp( 'option', 'update', 'aitp_queue_lock', String( Math.floor( Date.now() / 1000 ) - 10 ) );
		r = run();
		st = qstatus();
		v = verify( LANGS );
		s = sum( v );
		record( 'queue: interrupted, locked, resumed', { first: r1, mid, busy: busy.result, end: totals( st.progress ), mismatch: s.mismatch }, {
			stopped_after_7_visits: r1.visited === 7,
			second_run_blocked_by_lock: busy.result === 'busy',
			stale_lock_taken_over: ( totals( st.progress ).done || 0 ) === 54,
			no_mismatch: s.mismatch === 0,
		} );

		// 3. Queue's own daily cap.
		await fresh();
		qset( { queue_cap: 20000 } );
		r = run();
		st = qstatus();
		record( 'queue: own daily character cap 20000', { run: r, day_bytes: st.state.day_bytes, wait: st.state.wait_reason, progress: totals( st.progress ) }, {
			waits_for_cap: r.result === 'waiting' && st.state.wait_reason === 'queue_cap',
			overshoot_at_most_one_page: st.state.day_bytes >= 20000 && st.state.day_bytes < 20000 + 15000,
			not_all_done: ( totals( st.progress ).done || 0 ) < 54,
		} );

		// 4. TranslatePress limit with reserve for visitors (limit 50000, reserve 20% -> stop at 40000).
		await fresh();
		setLimit( 50000 );
		r = run();
		st = qstatus();
		const counter = state().tp_counter;
		setLimit( 1000000 );
		record( 'queue: keeps 20% of the TranslatePress limit for visitors', { run: r, tp_counter: counter, wait: st.state.wait_reason }, {
			waits_for_tp_limit: st.state.wait_reason === 'tp_limit',
			reserve_left: counter < 50000 && counter >= 40000,
		} );

		// 5. Provider rejects the key: the queue stops after one page.
		await fresh();
		await mock( { mode: 'e401' } );
		r = run();
		st = qstatus();
		const m5 = await mockStats();
		await mock( { mode: 'ok' } );
		wp( 'eval', 'delete_option("aitp_breaker"); echo 1;' );
		record( 'queue: invalid key stops the queue', { run: r, wait: st.state.wait_reason, requests: m5.requests, progress: totals( st.progress ) }, {
			queue_waits: r.result === 'waiting' && [ 'auth', 'breaker' ].includes( st.state.wait_reason ),
			one_page_tried: r.visited === 1,
			at_most_one_wave_sent: m5.requests <= 3,
			nothing_marked_done: ! totals( st.progress ).done,
		} );

		// 6. Forged, expired, foreign-path and replayed signatures.
		await fresh();
		const probes = Object.fromEntries( [ 'valid', 'forged', 'expired', 'other_path' ].map( ( m ) => [ m, json( wp( 'eval-file', '/e2e/warm-probe.php', m ) ) ] ) );
		record( 'queue: only a valid, unused signature is accepted', probes, {
			valid_accepted: probes.valid.first.report === true,
			replay_rejected: probes.valid.replay.report === false,
			forged_rejected: probes.forged.first.report === false,
			expired_rejected: probes.expired.first.report === false,
			other_path_rejected: probes.other_path.first.report === false,
			pages_still_served: [ probes.forged, probes.expired, probes.other_path ].every( ( p ) => p.first.http === 200 ),
		} );

		// 7. A visitor browses while the queue runs.
		await fresh();
		await mock( { mode: 'ok', delay_ms: 600 } );
		const runner = new Promise( ( res ) => { execFile( DIR + '/wp.sh', [ 'aitp', 'queue', 'run', '--ignore-rate', '--minutes=8' ], () => res() ); } );
		const views = [];
		for ( let i = 0; i < 3; i++ ) for ( const l of [ 'fr_FR', 'ja', 'ar' ] ) for ( const p of PAGES ) views.push( await visit( l, p ) );
		await runner;
		await mock( { mode: 'ok' } );
		const m7 = await mockStats();
		v = verify( LANGS );
		s = sum( v );
		const items = m7.log.reduce( ( a, x ) => a + x.items, 0 );
		record( 'queue: visitor browsing at the same time', { visitor_views: views.length, items_sent: items, rows: s.rows, ...s, max_view_ms: Math.max( ...views.map( ( x ) => x.ms ) ) }, {
			no_mismatch: s.mismatch === 0,
			all_translated: s.status1 === s.rows,
			no_double_translation: items <= s.rows * 1.05,
			views_ok: views.every( ( x ) => x.status === 200 ),
		} );

		// 8. Page caches.
		await fresh();
		wp( 'option', 'update', 'aitp_e2e_page_cache', 'query' );
		r = run();
		st = qstatus();
		const cached = wp( 'eval', 'echo count(glob(WP_CONTENT_DIR . "/e2e-cache/*.html") ?: array());' ).trim();
		record( 'queue: cache that keys on the query string', { run: r, progress: totals( st.progress ), cache_files: cached }, {
			all_done: ( totals( st.progress ).done || 0 ) === 54,
			queue_pages_not_stored_in_cache: cached === '0',
		} );
		await fresh();
		wp( 'option', 'update', 'aitp_e2e_page_cache', 'ignore_query' );
		await visit( 'fr_FR', 'okay-home' ); // A visitor fills the cache.
		wp( 'eval', 'global $wpdb; $wpdb->query("UPDATE {$wpdb->prefix}aitp_queue SET priority = 9"); $wpdb->query("UPDATE {$wpdb->prefix}aitp_queue SET priority = 0 WHERE url LIKE \'%/fr/okay-home/\'"); echo 1;' );
		const r8 = json( q( 'run', '--ignore-rate', '--pages=1' ) );
		const row = JSON.parse( wp( 'eval', 'global $wpdb; echo json_encode($wpdb->get_row("SELECT status, note FROM {$wpdb->prefix}aitp_queue WHERE url LIKE \'%/fr/okay-home/\'", ARRAY_A));' ).replace( /^[^{]*/, '' ) );
		wp( 'option', 'delete', 'aitp_e2e_page_cache' );
		record( 'queue: cache that ignores query strings is detected', { run: r8, row }, {
			not_marked_done: row.status !== 'done',
			reason_page_cache: row.note === 'no_report',
		} );

		// 9. Edited content goes back into the queue.
		await fresh();
		run();
		const pid = wp( 'post', 'list', '--post_type=page', '--name=okay-product', '--field=ID' ).trim();
		wp( 'post', 'update', pid, '--post_content=<p>Updated product text with 2 new sentences. Second sentence here.</p>' );
		st = qstatus();
		const again = totals( st.progress );
		r = run();
		v = verify( LANGS );
		s = sum( v );
		record( 'queue: edited page queued again and translated', { after_edit: again, run: r, mismatch: s.mismatch, untranslated: s.status0 }, {
			six_rows_requeued: again.pending === 6,
			retranslated: s.status0 === 0 && s.mismatch === 0,
		} );
	},

	async guard() {
		// The queue must not drive another engine (e.g. HHG) or run with automatic translation off.
		const q = ( ...a ) => wp( 'aitp', 'queue', ...a );
		const qs = () => json( wp( 'eval', 'echo wp_json_encode(AITP_Queue::state());' ) );
		const eng = ( v ) => wp( 'eval', `$m = get_option('trp_machine_translation_settings'); $m['translation-engine'] = '${ v }'; update_option('trp_machine_translation_settings', $m); echo 1;` );
		const auto = ( v ) => wp( 'eval', `$m = get_option('trp_machine_translation_settings'); $m['machine-translation'] = '${ v }'; update_option('trp_machine_translation_settings', $m); echo 1;` );
		const before = wp( 'eval', '$m = get_option("trp_machine_translation_settings"); echo $m["translation-engine"] . "|" . $m["machine-translation"];' ).trim().split( '|' );
		q( 'sync' );
		q( 'start' );
		const tpBefore = state().tp_counter;
		eng( 'hhgfotr_gemini' );
		const r1 = json( q( 'run', '--ignore-rate', '--pages=3' ) );
		const s1 = qs();
		const rep = json( wp( 'aitp', 'audit', 'repair', '--ids=1,2,3' ) );
		eng( before[ 0 ] );
		wp( 'eval', '$s = get_option("aitp_queue_state"); $s["wait_until"] = 0; $s["wait_reason"] = ""; update_option("aitp_queue_state", $s); echo 1;' );
		auto( 'no' );
		const r2 = json( q( 'run', '--ignore-rate', '--pages=3' ) );
		const s2 = qs();
		auto( before[ 1 ] );
		wp( 'eval', '$s = get_option("aitp_queue_state"); $s["wait_until"] = 0; $s["wait_reason"] = ""; update_option("aitp_queue_state", $s); echo 1;' );
		q( 'pause' );
		record( 'guard: queue waits when another engine is selected or automatic translation is off', { other_engine: { run: r1, reason: s1.wait_reason, repair: rep }, automatic_off: { run: r2, reason: s2.wait_reason }, tp_chars: state().tp_counter - tpBefore }, {
			no_visit_other_engine: r1.visited === 0 && s1.wait_reason === 'engine_not_selected',
			repair_refused_other_engine: rep.reset === 0 && rep.skipped.engine_not_selected === 3,
			no_visit_automatic_off: r2.visited === 0 && s2.wait_reason === 'automatic_off',
			nothing_sent: state().tp_counter === tpBefore,
		} );
	},

	async audit() {
		const q = ( ...a ) => wp( 'aitp', 'queue', ...a );
		const a = ( ...x ) => json( wp( 'aitp', 'audit', ...x ) );
		const seedc = ( ...x ) => json( wp( 'eval-file', '/e2e/audit-seed.php', ...x ) );
		const key = ( k, l, id ) => `${ k }|${ l }|${ id }`;
		const list = ( ...x ) => a( 'list', '--limit=5000', ...x ).rows;

		// Fully translated site (backfill queue), then plant the HHG failure types.
		await reset();
		wp( 'eval', 'global $wpdb; foreach (array("queue","audit","repair") as $t) $wpdb->query("TRUNCATE {$wpdb->prefix}aitp_$t"); foreach (array("aitp_queue_state","aitp_queue_lock","aitp_audit_state","aitp_audit_lock","aitp_repair_batches","aitp_audit_settings") as $o) delete_option($o); echo 1;' );
		q( 'sync' );
		q( 'start' );
		json( q( 'run', '--ignore-rate', '--minutes=8' ) );
		const before = sum( verify( LANGS ) );
		const seeded = seedc( 'seed' );
		const seed = seedc( 'dump' );
		const bad = seed.rows.filter( ( r ) => r.type !== 'manual' );
		const manualRows = seed.rows.filter( ( r ) => r.type === 'manual' );
		const isBad = new Set( bad.map( ( r ) => key( r.kind, r.lang, r.id ) ) );

		// 1. Check.
		let t0 = Date.now();
		const scan = a( 'scan', '--minutes=5' );
		const secs = ( Date.now() - t0 ) / 1000;
		let found = list();
		const byKey = Object.fromEntries( found.map( ( f ) => [ key( f.kind, f.lang, f.row_id ), f ] ) );
		const missed = bad.filter( ( r ) => ! byKey[ key( r.kind, r.lang, r.id ) ] );
		const missedErr = bad.filter( ( r ) => ( byKey[ key( r.kind, r.lang, r.id ) ] || {} ).severity !== 'error' );
		const falseErr = found.filter( ( f ) => f.severity === 'error' && ! isBad.has( key( f.kind, f.lang, f.row_id ) ) );
		const falseSus = found.filter( ( f ) => f.severity !== 'error' && ! isBad.has( key( f.kind, f.lang, f.row_id ) ) );
		record( 'audit: check finds planted bad translations', {
			translated_before: before.status1, seeded: seeded.seeded, scanned: scan.scanned, secs,
			findings: found.length, errors: found.filter( ( f ) => f.severity === 'error' ).length,
			missed: missed.map( ( r ) => r.type + ':' + r.lang ), not_error_yet: missedErr.map( ( r ) => r.type + ':' + r.lang + ( r.long ? ':long' : ':short' ) ),
			false_errors: falseErr.length, other_suspects: falseSus.length,
			false_error_rows: falseErr.slice( 0, 5 ).map( ( f ) => [ f.kind, f.lang, f.row_id, f.codes, f.original.slice( 0, 80 ), f.translated.slice( 0, 80 ) ] ),
		}, {
			every_planted_row_found: missed.length === 0,
			truncated_structured_placeholder_are_errors: bad.filter( ( r ) => [ 'truncated', 'structured', 'placeholder' ].includes( r.type ) ).every( ( r ) => ( byKey[ key( r.kind, r.lang, r.id ) ] || {} ).severity === 'error' ),
			no_false_errors: falseErr.length === 0,
			manual_rows_not_listed: manualRows.every( ( r ) => ! byKey[ key( r.kind, r.lang, r.id ) ] ),
			nothing_changed_by_check: seedc( 'check' ).manual_sum === seed.manual_sum && seedc( 'check' ).rows.every( ( r ) => r.type === 'manual' ? r.status === 2 : r.is_bad ),
		} );

		// 2. AI check of the suspects (short shifted rows the rules cannot prove).
		wp( 'eval', 'update_option("aitp_audit_settings", array("ai_check" => true, "gettext" => true)); echo 1;' );
		const tpBefore = state().tp_counter;
		const ai = a( 'ai-check', '--severity=suspect' );
		const tpAfter = state().tp_counter;
		found = list();
		const byKey2 = Object.fromEntries( found.map( ( f ) => [ key( f.kind, f.lang, f.row_id ), f ] ) );
		const shiftNotErr = bad.filter( ( r ) => ( byKey2[ key( r.kind, r.lang, r.id ) ] || {} ).severity !== 'error' );
		const goodMarkedBad = found.filter( ( f ) => f.ai === 'mismatch' && ! isBad.has( key( f.kind, f.lang, f.row_id ) ) );
		record( 'audit: AI check confirms the short misplaced rows', { ai, tp_chars: tpAfter - tpBefore, still_not_error: shiftNotErr.length, good_rows_flagged: goodMarkedBad.length, good_flagged_rows: goodMarkedBad.slice( 0, 8 ).map( ( f ) => [ f.lang, f.row_id, f.original.slice( 0, 100 ), f.translated.slice( 0, 100 ) ] ) }, {
			every_planted_row_now_error: shiftNotErr.length === 0,
			good_neighbours_cleared: goodMarkedBad.length === 0,
			ai_counted_in_tp_limit: tpAfter > tpBefore,
		} );

		// 3. Repair: one planted row is fixed by someone else after the check -> skipped.
		const other = bad.find( ( r ) => r.type === 'english' && r.lang === 'fr_FR' );
		wp( 'eval-file', '/e2e/audit-seed.php', 'edit', other.lang, String( other.id ), other.good );
		// Planted rows include interface text and rows on no known page: opt in to reset them too
		// (the default, known pages only, is covered by the pagemap scenario).
		const rep = a( 'repair', '--severity=error', '--yes', '--include-unlinked' );
		let chk = seedc( 'check' );
		const resetRows = chk.rows.filter( ( r ) => r.type !== 'manual' && r.status === 0 && r.empty );
		record( 'audit: repair backs up and resets only unchanged machine rows', { repair: rep, reset_rows: resetRows.length }, {
			all_planted_reset_but_edited_one: rep.reset === bad.length - 1 && resetRows.length === bad.length - 1,
			edited_row_skipped: ( rep.skipped.changed || 0 ) === 1 && chk.rows.find( ( r ) => r.id === other.id && r.lang === other.lang ).is_good,
			manual_untouched: chk.manual_sum === seed.manual_sum,
			pages_queued: rep.pages > 0,
		} );

		// 4. Backfill queue translates them again.
		json( q( 'run', '--ignore-rate', '--minutes=5' ) );
		let v = sum( verify( LANGS ) );
		chk = seedc( 'check' );
		// The broken JSON-LD row is reset to untranslated; no page renders it, so nothing retranslates it.
		const notGood = chk.rows.filter( ( r ) => ! [ 'manual', 'structured' ].includes( r.type ) && r.kind === 'dictionary' && ! r.is_good );
		const structuredRow = chk.rows.find( ( r ) => r.type === 'structured' );
		record( 'audit: repaired rows translated again correctly', { verify: v, not_good: notGood.map( ( r ) => r.type + ':' + r.lang ) }, {
			no_mismatch_left: v.mismatch === 0,
			planted_rows_now_correct: notGood.length === 0,
			structured_left_untranslated: structuredRow.status === 0 && structuredRow.empty,
			manual_untouched: chk.manual_sum === seed.manual_sum,
		} );

		// 5. Rollback; one repaired row was corrected by hand in between and must be kept.
		const hand = bad.find( ( r ) => r.type === 'truncated' && r.lang === 'ja' );
		wp( 'eval-file', '/e2e/audit-seed.php', 'manual', hand.lang, String( hand.id ) );
		const rb = a( 'rollback', String( rep.batch ) );
		chk = seedc( 'check' );
		const restored = chk.rows.filter( ( r ) => r.is_bad && r.status === 1 && r.type !== 'manual' );
		record( 'audit: rollback restores the old text exactly, keeps manual work', { rollback: rb, restored_exact: restored.length }, {
			restored_all_but_manual: rb.restored === rep.reset - 1 && restored.length === rep.reset - 1,
			manual_kept: ( rb.skipped.manual || 0 ) === 1 && chk.rows.find( ( r ) => r.id === hand.id && r.lang === hand.lang ).status === 2,
			original_manual_rows_untouched: chk.rows.filter( ( r ) => r.type === 'manual' ).every( ( r ) => r.status === 2 && r.is_bad ),
		} );

		// 5b. Accepted list: rows people reviewed are listed but never repaired.
		const accRows = restored.filter( ( r ) => r.kind === 'dictionary' ).slice( 0, 4 ).map( ( r ) => seed.rows.find( ( x ) => x.id === r.id && x.lang === r.lang && x.kind === r.kind ) );
		// The last entry has a wrong id (copied database): it must be found by its original text.
		const accB64 = Buffer.from( JSON.stringify( { rows: accRows.map( ( r, i ) => ( { language: i === 3 ? r.lang.toLowerCase() : r.lang, id: i === 3 ? r.id + 900000 : r.id, original: r.original, new: r.bad, table: r.kind } ) ) } ) ).toString( 'base64' );
		wp( 'eval', `file_put_contents(WP_CONTENT_DIR . "/e2e-state/accepted.json", base64_decode("${ accB64 }")); echo 1;` );
		const prot = a( 'protect', '/var/www/html/wp-content/e2e-state/accepted.json', '--label=e2e reviewed' );
		a( 'scan', '--minutes=5' );
		const accKeys = new Set( accRows.map( ( r ) => key( r.kind, r.lang, r.id ) ) );
		const accFound = list( '--state=accepted' ).filter( ( f ) => accKeys.has( key( f.kind, f.lang, f.row_id ) ) );
		const openAcc = list().filter( ( f ) => accKeys.has( key( f.kind, f.lang, f.row_id ) ) );
		const accIds = list( '--state=all' ).filter( ( f ) => accKeys.has( key( f.kind, f.lang, f.row_id ) ) ).map( ( f ) => f.id );
		const accRep = a( 'repair', `--ids=${ accIds.join( ',' ) }` );
		chk = seedc( 'check' );
		const stillBad = chk.rows.filter( ( r ) => accKeys.has( key( r.kind, r.lang, r.id ) ) && r.is_bad && r.status === 1 );
		wp( 'aitp', 'audit', 'unprotect', '--label=e2e reviewed' );
		const reopened = list().filter( ( f ) => accKeys.has( key( f.kind, f.lang, f.row_id ) ) );
		record( 'audit: accepted list is listed but never repaired', { protect: prot, accepted_found: accFound.length, open_while_accepted: openAcc.length, repair: accRep, unchanged: stillBad.length, reopened: reopened.length }, {
			all_added: prot.added === 4 && prot.by_original === 1,
			listed_as_accepted: accFound.length === 4 && openAcc.length === 0,
			repair_refused: accRep.reset === 0 && ( accRep.skipped.accepted || 0 ) === 4,
			text_unchanged: stillBad.length === 4,
			reopened_after_removal: reopened.length === 4,
		} );

		// 6. Import of a candidate list.
		seedc( 'import-file' );
		wp( 'eval', 'global $wpdb; $wpdb->query("TRUNCATE {$wpdb->prefix}aitp_audit"); echo 1;' );
		const imp = a( 'import', '/var/www/html/wp-content/e2e-state/import.json' );
		record( 'audit: import keeps only candidates that still match', imp, {
			planted_rows_added: imp.added === bad.length - 2, // minus the hand-corrected one and the one edited by someone else
			manual_refused: imp.manual === manualRows.length + 1,
			changed_refused: imp.changed + imp.untranslated >= 2,
			unknown_language_refused: imp.missing === 1,
		} );

		// 7. Scale: 50,000 more rows.
		seedc( 'big', '50000' );
		t0 = Date.now();
		const big = a( 'scan', '--minutes=10' );
		const bigSecs = ( Date.now() - t0 ) / 1000;
		const bigErr = list( '--lang=es_MX', '--severity=error' ).length;
		seedc( 'unbig' );
		record( 'audit: 50,000 extra rows checked', { scanned: big.scanned, secs: bigSecs, es_errors: bigErr }, {
			finished: big.result === 'done',
			under_3_minutes: bigSecs < 180,
			no_false_errors: bigErr === 0,
		} );
		seedc( 'restore-gettext' );
		wp( 'eval', 'delete_option("aitp_audit_settings"); echo 1;' );
	},

	async autofix() {
		// Batch auto repair: off by default, fixes "wrong" page text one batch at a time by
		// visiting only those pages (queue stays paused), leaves everything else alone,
		// stops by itself on failures or engine switches, batches roll back exactly.
		const q = ( ...a ) => wp( 'aitp', 'queue', ...a );
		const a = ( ...x ) => json( wp( 'aitp', 'audit', ...x ) );
		const af = ( ...x ) => json( wp( 'aitp', 'autofix', ...x ) );
		const afTry = ( ...x ) => { try { return wp( 'aitp', 'autofix', ...x ); } catch ( e ) { return 'ERR ' + String( e.stdout || '' ) + String( e.stderr || '' ).split( '\n' ).filter( ( l ) => ! /Container /.test( l ) ).join( ' ' ); } };
		const seedc = ( ...x ) => json( wp( 'eval-file', '/e2e/audit-seed.php', ...x ) );
		const eng = ( v ) => wp( 'eval', `$m = get_option('trp_machine_translation_settings'); $m['translation-engine'] = '${ v }'; update_option('trp_machine_translation_settings', $m); echo 1;` );
		const engine0 = wp( 'eval', '$m = get_option("trp_machine_translation_settings"); echo $m["translation-engine"];' ).trim();
		const key = ( k, l, id ) => `${ k }|${ l }|${ id }`;
		const qstate = () => json( wp( 'eval', 'echo wp_json_encode(AITP_Queue::state());' ) );
		const batches = () => json( wp( 'eval', 'echo wp_json_encode((object) AITP_Audit::batches());' ) );

		await reset();
		wp( 'eval', 'global $wpdb; foreach (array("queue","audit","repair","protect") as $t) $wpdb->query("TRUNCATE {$wpdb->prefix}aitp_$t"); foreach (array("aitp_queue_state","aitp_queue_lock","aitp_audit_state","aitp_audit_lock","aitp_repair_batches","aitp_audit_settings","aitp_autofix_state","aitp_autofix_settings","aitp_autofix_lock") as $o) delete_option($o); echo 1;' );
		q( 'sync' );
		q( 'start' );
		json( q( 'run', '--ignore-rate', '--minutes=8' ) );
		q( 'pause' );
		seedc( 'seed' );
		const seed = seedc( 'dump' );
		a( 'scan', '--minutes=5' );
		const found = a( 'list', '--limit=5000', '--state=all' ).rows;
		const sev = Object.fromEntries( found.map( ( f ) => [ key( f.kind, f.lang, f.row_id ), f.severity ] ) );
		const errRows = seed.rows.filter( ( r ) => r.kind === 'dictionary' && r.type !== 'manual' && r.type !== 'structured' && sev[ key( r.kind, r.lang, r.id ) ] === 'error' );
		const otherRows = seed.rows.filter( ( r ) => r.type !== 'manual' && ! errRows.includes( r ) );
		const batchesBefore = Object.keys( batches() ).length;

		// 1. Off by default.
		const refused = afTry( 'start' );
		af( 'settings', '--enabled=1', '--batch=20' );

		// 2. Run until there is nothing left it may repair.
		const started = afTry( 'start' );
		const tp0 = state().tp_counter;
		const t0 = Date.now();
		const run = af( 'run', '--ignore-rate', '--minutes=15' );
		const secs = ( Date.now() - t0 ) / 1000;
		let st = run.state;
		let chk = seedc( 'check' );
		const byKey = ( rows ) => Object.fromEntries( rows.map( ( r ) => [ key( r.kind, r.lang, r.id ), r ] ) );
		let cur = byKey( chk.rows );
		const fixed = errRows.filter( ( r ) => cur[ key( r.kind, r.lang, r.id ) ].is_good );
		const others = otherRows.filter( ( r ) => ! ( cur[ key( r.kind, r.lang, r.id ) ].is_bad && cur[ key( r.kind, r.lang, r.id ) ].status === 1 ) );
		const bAll = batches();
		const autoIds = Object.keys( bAll ).filter( ( id ) => bAll[ id ].auto );
		record( 'autofix: repairs wrong page text batch by batch, leaves the rest', {
			refused_before_switch: refused.slice( 0, 120 ), started: started.slice( 0, 120 ), result: run.result, status: st.status, reason: st.reason, secs,
			wrong_rows: errRows.length, fixed: fixed.length, left: st.left, batches: st.batches, history: st.history.map( ( h ) => [ h.lang, h.reset, h.ok, h.wrong, h.untranslated, h.result ] ),
			other_rows_touched: others.map( ( r ) => r.type + ':' + r.lang ), tp_chars: state().tp_counter - tp0, queue: qstate().status,
		}, {
			off_by_default: /disabled/.test( refused ),
			finished: st.status === 'done',
			every_batch_ok: st.history.length > 0 && st.history.every( ( h ) => h.result === 'ok' ),
			wrong_rows_fixed_or_reported: fixed.length + st.left.no_page >= errRows.length && fixed.length > 0,
			suspects_gettext_structured_untouched: others.length === 0,
			manual_untouched: chk.manual_sum === seed.manual_sum,
			batches_marked_auto: autoIds.length === st.history.length && Object.keys( bAll ).length === batchesBefore + st.history.length,
			structured_left_for_a_person: st.left.structured >= 1,
			queue_stayed_paused: qstate().status === 'stopped',
		} );

		// 3. Roll back one auto batch: exact.
		const frEntry = st.history.find( ( h ) => h.lang === 'fr_FR' ) || st.history[ 0 ];
		if ( ! frEntry ) return;
		const rb = a( 'rollback', String( frEntry.batch ) );
		chk = seedc( 'check' );
		cur = byKey( chk.rows );
		const frErr = errRows.filter( ( r ) => r.lang === frEntry.lang );
		const backBad = frErr.filter( ( r ) => cur[ key( r.kind, r.lang, r.id ) ].is_bad && cur[ key( r.kind, r.lang, r.id ) ].status === 1 );
		record( 'autofix: a batch rolls back exactly', { batch: frEntry.batch, rollback: rb, rows: frErr.length, back_to_old_text: backBad.length }, {
			restored_all: rb.restored === frEntry.reset && frErr.filter( ( r ) => fixed.includes( r ) ).every( ( r ) => backBad.includes( r ) ),
		} );

		// 4. Engine switched to another plugin: refuses to start, and a running repair stops.
		eng( 'hhgfotr_gemini' );
		const refused2 = afTry( 'start' );
		eng( engine0 );
		af( 'settings', '--enabled=1', '--batch=20', `--languages=${ frEntry.lang }` );
		afTry( 'start' );
		eng( 'hhgfotr_gemini' );
		const nb = Object.keys( batches() ).length;
		const run2 = af( 'run', '--ignore-rate', '--minutes=1' );
		const nb2 = Object.keys( batches() ).length;
		eng( engine0 );
		record( 'autofix: stops when TranslatePress uses another engine', { refused: refused2.slice( 0, 120 ), status: run2.state.status, reason: run2.state.reason, new_batches: nb2 - nb }, {
			start_refused: /engine_not_selected/.test( refused2 ),
			running_stopped: run2.state.status === 'stopped' && run2.state.reason === 'engine_not_selected',
			nothing_reset: nb2 === nb,
		} );

		// 5. The provider answers in English: the batch fails and the auto repair stops by itself.
		await mock( { mode: 'english', target: '' } );
		wp( 'eval', 'global $wpdb; $wpdb->query("TRUNCATE {$wpdb->prefix}aitp_cache"); $wpdb->query("TRUNCATE {$wpdb->prefix}aitp_failures"); echo 1;' );
		const tp1 = state().tp_counter;
		afTry( 'start' );
		const run3 = af( 'run', '--ignore-rate', '--minutes=8' );
		await mock( { mode: 'ok' } );
		st = run3.state;
		const b3 = batches();
		const newAuto = Object.keys( b3 ).filter( ( id ) => b3[ id ].auto && ! autoIds.includes( id ) );
		chk = seedc( 'check' );
		cur = byKey( chk.rows );
		const englishSaved = seed.rows.filter( ( r ) => r.lang === frEntry.lang && r.type !== 'manual' && cur[ key( r.kind, r.lang, r.id ) ].status === 1 && ! cur[ key( r.kind, r.lang, r.id ) ].is_bad && ! cur[ key( r.kind, r.lang, r.id ) ].is_good );
		const rb3 = newAuto.length ? a( 'rollback', newAuto[ 0 ] ) : { restored: 0 };
		chk = seedc( 'check' );
		cur = byKey( chk.rows );
		const restored3 = frErr.filter( ( r ) => fixed.includes( r ) && cur[ key( r.kind, r.lang, r.id ) ].is_bad && cur[ key( r.kind, r.lang, r.id ) ].status === 1 );
		record( 'autofix: stops by itself when a batch fails', { status: st.status, reason: st.reason, history: st.history.slice( 0, 1 ).map( ( h ) => [ h.reset, h.ok, h.wrong, h.untranslated, h.result, h.rate ] ), new_batches: newAuto.length, other_saved: englishSaved.length, rollback: rb3, tp_chars: state().tp_counter - tp1 }, {
			stopped_by_itself: st.status === 'stopped' && ( st.reason === 'too_many_failures' || st.reason.startsWith( 'provider_' ) ),
			only_one_batch: newAuto.length === 1,
			no_english_saved: englishSaved.length === 0,
			rolled_back_exactly: rb3.restored > 0 && restored3.length === frErr.filter( ( r ) => fixed.includes( r ) ).length,
		} );

		af( 'settings', '--enabled=0' );
		wp( 'eval', 'foreach (array("aitp_autofix_state","aitp_autofix_settings","aitp_audit_settings") as $o) delete_option($o); echo 1;' );
		seedc( 'restore-gettext' );
	},

	async pagemap() {
		// 0.6.0: page mapping (read-only visits), repairs limited to known pages, test journal.
		const q = ( ...a ) => wp( 'aitp', 'queue', ...a );
		const a = ( ...x ) => json( wp( 'aitp', 'audit', ...x ) );
		const m = ( ...x ) => json( wp( 'aitp', 'map', ...x ) );
		const j = ( ...x ) => json( wp( 'aitp', 'journal', ...x ) );
		const af = ( ...x ) => json( wp( 'aitp', 'autofix', ...x ) );
		const h = ( ...x ) => json( wp( 'eval-file', '/e2e/pagemap-help.php', ...x.map( String ) ) );
		const opt = ( k, v ) => wp( 'eval', `update_option('${ k }', json_decode(base64_decode('${ Buffer.from( JSON.stringify( v ) ).toString( 'base64' ) }'), true)); echo 1;` );
		const batches = () => json( wp( 'eval', 'echo wp_json_encode((object) AITP_Audit::batches());' ) );
		const L = 'fr_FR';
		const FA = 'Free shipping on every order over ninety nine dollars';
		const FB = 'Only on the home page: ask for a free sample today';
		const FC = 'A sentence that nobody has translated before this test';
		const FD = 'Another new footer line that appears during the journal test';

		await reset();
		wp( 'eval', 'global $wpdb; foreach (array("queue","audit","repair","protect","pagemap","pagestr","journal") as $t) $wpdb->query("TRUNCATE {$wpdb->prefix}aitp_$t"); foreach (array("aitp_queue_state","aitp_queue_lock","aitp_audit_state","aitp_audit_lock","aitp_repair_batches","aitp_audit_settings","aitp_autofix_state","aitp_autofix_settings","aitp_autofix_lock","aitp_map_state","aitp_map_lock","aitp_journal_state") as $o) delete_option($o); echo 1;' );
		opt( 'aitp_e2e_footer', [ [ FA, '' ], [ FB, 'okay-home' ] ] );
		wp( 'eval', `update_option('aitp_queue_settings', array_merge(AITP_Queue::defaults(), array('languages' => array('${ L }'))), false); echo 1;` );
		q( 'sync' );
		q( 'start' );
		json( q( 'run', '--ignore-rate', '--minutes=6' ) );
		q( 'pause' );
		const rowA = h( 'find', L, FA );
		const rowB = h( 'find', L, FB );
		const product = h( 'permalink', 'okay-product' ).url;

		// 1. Map one page: nothing translated or saved, footer text found although TranslatePress links it to no post.
		opt( 'aitp_e2e_footer', [ [ FA, '' ], [ FB, 'okay-home' ], [ FC, 'okay-product' ] ] );
		const metaBefore = h( 'meta', 'okay-product' ).links;
		h( 'meta', 'okay-product', 'clear' );
		const d0 = h( 'dict', L );
		const o0 = h( 'originals' );
		const req0 = ( await mockStats() ).requests;
		const started = wp( 'aitp', 'map', 'start', `--urls=${ product }`, `--languages=${ L }` );
		const run = m( 'run', '--ignore-rate', '--minutes=2' );
		const d1 = h( 'dict', L );
		const o1 = h( 'originals' );
		const req1 = ( await mockStats() ).requests;
		const metaAfter = h( 'meta', 'okay-product' );
		const pages = m( 'list' ).rows;
		const pg = pages[ 0 ] || {};
		const exp = json( wp( 'aitp', 'map', 'export', `--pages=${ pg.id }` ) );
		const expIds = exp.rows.map( ( r ) => Number( r.id ) );
		record( 'pagemap: read-only visit finds the rows a page shows', {
			started: started.slice( 0, 80 ), run, rowA: [ rowA.id, rowA.status, rowA.meta ], page: { found: pg.found, meta: pg.meta, meta_found: pg.meta_found, s1: pg.s1, s2: pg.s2, s0: pg.s0, unknown: pg.unknown, http: pg.http },
			dict_same: d0.sum === d1.sum, originals: [ o0.rows, o1.rows ], tp_links: [ metaBefore, metaAfter.links, metaAfter.markers ], mock_requests: req1 - req0, export_rows: exp.rows.length,
		}, {
			footer_not_linked_by_tp: rowA.status === '1' && rowA.meta === 0,
			one_page_mapped: run.status === 'done' && pages.length === 1 && pg.http === '200',
			found_rows: Number( pg.found ) > 0,
			tp_links_not_written: metaBefore > 0 && metaAfter.links === 0 && metaAfter.markers === 0,
			new_text_counted_not_saved: Number( pg.unknown ) >= 1 && d0.rows === d1.rows && o0.rows === o1.rows,
			nothing_changed: d0.sum === d1.sum,
			no_ai_request: req1 === req0,
			export_has_footer: expIds.includes( Number( rowA.id ) ) && ! expIds.includes( Number( rowB.id ) ),
			export_is_machine_rows: exp.rows.length === Number( pg.s1 ) && exp.rows.every( ( r ) => typeof r.old === 'string' && r.old !== '' ),
		} );
		opt( 'aitp_e2e_footer', [ [ FA, '' ], [ FB, 'okay-home' ] ] );

		// 2. Repair a mixed set: the row on a mapped page is reset and only that page is queued and visited;
		//    the row on no known page stays as it is.
		h( 'set', L, rowA.id, 1, FA );
		h( 'set', L, rowB.id, 1, FB );
		const cand = h( 'cand', L, `${ rowA.id },${ rowB.id }` );
		const imp = a( 'import', cand.file );
		const fnd = a( 'list', '--limit=50', '--source=import' ).rows;
		const fid = ( row ) => ( fnd.find( ( f ) => Number( f.row_id ) === Number( row.id ) ) || {} ).id;
		opt( 'aitp_e2e_visit_log', 1 );
		h( 'visits', 'clear' );
		// TranslatePress keeps a ~50 s "recently translated" lock per string; A was translated moments ago.
		wp( 'eval', 'global $wpdb; $wpdb->query("TRUNCATE {$wpdb->prefix}trp_machine_translation_locks"); echo 1;' );
		const r1 = a( 'repair', `--ids=${ fid( rowA ) },${ fid( rowB ) }` );
		const qrows = h( 'queue' ).rows;
		const pending = qrows.filter( ( r ) => r.status === 'pending' );
		q( 'start' );
		json( q( 'run', '--ignore-rate', '--minutes=2' ) );
		q( 'pause' );
		const visits = h( 'visits' ).visits;
		const nowA = h( 'find', L, FA );
		const nowB = h( 'find', L, FB );
		const prodPath = new URL( r1.urls[ 0 ] ? r1.urls[ 0 ].url : 'http://x/none' ).pathname;
		record( 'pagemap: repair resets only rows on a known page and visits only that page', {
			import: imp, repair: r1, pending: pending.map( ( r ) => r.url ), visits, A: [ nowA.status, nowA.translated ], B: [ nowB.status, nowB.translated ],
		}, {
			imported_both: imp.added === 2,
			reset_linked_only: r1.reset === 1 && r1.skipped.no_page === 1 && r1.no_page.length === 1,
			no_whole_language: r1.all_pages.length === 0,
			one_page_queued: pending.length === 1 && r1.urls.length === 1 && pending[ 0 ].url === r1.urls[ 0 ].url,
			only_whitelisted_visits: visits.length >= 1 && visits.every( ( v ) => v.startsWith( prodPath ) ),
			a_translated_again: nowA.status === '1' && nowA.translated !== FA,
			b_untouched: nowB.status === '1' && nowB.translated === FB,
		} );

		// 3. Opt in: the unlinked row is reset and every page of the language is queued; roll both back exactly.
		const r2 = a( 'repair', `--ids=${ fid( rowB ) }`, '--include-unlinked' );
		const pending2 = h( 'queue' ).rows.filter( ( r ) => r.status === 'pending' );
		const rb2 = a( 'rollback', String( r2.batch ) );
		const rb1 = a( 'rollback', String( r1.batch ) );
		const backA = h( 'find', L, FA );
		const backB = h( 'find', L, FB );
		record( 'pagemap: whole language only when asked; rollbacks exact', { repair: { reset: r2.reset, all_pages: r2.all_pages, pages: r2.pages }, pending: pending2.length, rb1, rb2 }, {
			opt_in_resets: r2.reset === 1 && r2.all_pages.includes( L ),
			whole_language_queued: pending2.length === qrows.length,
			rolled_back: rb1.restored === 1 && rb2.restored === 1 && backA.translated === FA && backB.translated === FB && backA.status === '1' && backB.status === '1',
		} );
		wp( 'eval', 'global $wpdb; $wpdb->query("UPDATE {$wpdb->prefix}aitp_queue SET status = \'done\', note = \'\'"); echo 1;' );

		// 4. Auto repair on the same rows: picks the row on a known page, leaves the other one, visits only batch pages.
		a( 'scan', '--minutes=3' );
		af( 'settings', '--enabled=1', `--languages=${ L }`, '--batch=20' );
		h( 'visits', 'clear' );
		wp( 'aitp', 'autofix', 'start' );
		const afr = af( 'run', '--ignore-rate', '--minutes=4' );
		const bAll = batches();
		const auto = Object.entries( bAll ).filter( ( [ , b ] ) => b.auto );
		const allowed = auto.flatMap( ( [ , b ] ) => ( b.urls || [] ).map( ( u ) => new URL( u.url ).pathname ) );
		const visits2 = h( 'visits' ).visits;
		const afA = h( 'find', L, FA );
		const afB = h( 'find', L, FB );
		record( 'pagemap: auto repair stays on known pages', { result: afr.result, status: afr.state.status, left: afr.state.left, batches: auto.length, allowed, visits: visits2 }, {
			finished: afr.state.status === 'done',
			a_fixed: afA.translated !== FA && afA.status === '1',
			b_left_for_a_person: afB.translated === FB && afr.state.left.no_page >= 1,
			only_batch_pages_visited: visits2.length > 0 && visits2.every( ( v ) => allowed.some( ( p ) => v.startsWith( p ) ) ),
		} );
		for ( const [ id ] of auto ) a( 'rollback', id );
		af( 'settings', '--enabled=0' );

		// 5. Test journal: rows translated during the test go back to their exact earlier state; new rows are listed.
		const coll = h( 'dict', L );
		const pick = json( wp( 'eval', `global $wpdb; echo wp_json_encode($wpdb->get_col("SELECT d.id FROM {$wpdb->prefix}trp_dictionary_en_us_${ L.toLowerCase() } d JOIN {$wpdb->prefix}trp_original_meta m ON m.original_id = d.original_id AND m.meta_key = 'post_parent_id' JOIN {$wpdb->posts} p ON p.ID = m.meta_value AND p.post_name = 'okay-collection' WHERE d.status = 1 ORDER BY d.id LIMIT 3"));` ).replace( /^\[/, '{"ids":[' ).replace( /\]$/, ']}' ) ).ids.map( Number );
		h( 'set', L, pick[ 0 ], 0, '__NULL__' );
		h( 'set', L, pick[ 1 ], 0, '' );
		h( 'set', L, pick[ 2 ], 0, '__NULL__' );
		opt( 'aitp_e2e_footer', [ [ FA, '' ], [ FB, 'okay-home' ], [ FD, 'okay-collection' ] ] );
		const base = h( 'dict', L );
		const js = wp( 'aitp', 'journal', 'start', '--label=e2e' );
		await visit( L, 'okay-collection' );
		await visit( L, 'okay-collection' );
		wp( 'aitp', 'journal', 'stop' );
		const mid = h( 'dict', L );
		const sumBefore = j( 'status' );
		const undo = j( 'undo' );
		const after = h( 'dict', L, base.max );
		const rowsNow = pick.map( ( id ) => json( wp( 'eval', `global $wpdb; echo wp_json_encode($wpdb->get_row("SELECT translated, status FROM {$wpdb->prefix}trp_dictionary_en_us_${ L.toLowerCase() } WHERE id = ${ id }", ARRAY_A));` ) ) );
		const rowD = h( 'find', L, FD );
		record( 'pagemap: test journal undoes translations exactly, lists new rows', { started: js.slice( 0, 60 ), status: sumBefore, undo, rows: rowsNow, D: [ rowD.id, rowD.status ], sums: [ base.sum, mid.sum, after.sum ], max: [ base.max, mid.max ] }, {
			translated_during_test: mid.sum !== base.sum && rowsNow.length === 3,
			undone: undo.restored >= 3,
			exact_baseline: after.sum === base.sum && after.rows === base.rows,
			null_and_empty_kept_apart: rowsNow[ 0 ].translated === null && rowsNow[ 1 ].translated === '' && rowsNow.every( ( r ) => r.status === '0' ),
			new_row_listed_not_deleted: Number( rowD.id ) > base.max && undo.new_rows >= 1 && sumBefore.new_rows.some( ( n ) => n.lang === L && n.rows >= 1 ),
		} );

		wp( 'eval', 'foreach (array("aitp_e2e_footer","aitp_e2e_visit_log","aitp_queue_settings","aitp_autofix_settings","aitp_autofix_state","aitp_journal_state") as $o) delete_option($o); echo 1;' );
		h( 'visits', 'clear' );
	},

	async signature() {
		// 0.6.1: pages with non-ASCII addresses (the HTTP library rewrites percent-encodings before
		// sending) and internal visits whose signature the site refuses.
		const q = ( ...a ) => wp( 'aitp', 'queue', ...a );
		const m = ( ...x ) => json( wp( 'aitp', 'map', ...x ) );
		const h = ( ...x ) => json( wp( 'eval-file', '/e2e/pagemap-help.php', ...x.map( String ) ) );
		const b64 = ( v ) => Buffer.from( JSON.stringify( v ) ).toString( 'base64' );
		const opt = ( k, v ) => wp( 'eval', `update_option('${ k }', json_decode(base64_decode('${ b64( v ) }'), true)); echo 1;` );
		const ev = ( php, v ) => json( wp( 'eval', `$in = json_decode(base64_decode('${ b64( v === undefined ? null : v ) }'), true); ${ php }` ) );
		const SJ = 'Soft TR90 frames for small faces, on a page with a Japanese address';
		const SA = 'Flexible kids glasses, on a page with an Arabic address';

		await reset();
		wp( 'eval', 'global $wpdb; foreach (array("queue","audit","repair","protect","pagemap","pagestr","journal") as $t) $wpdb->query("TRUNCATE {$wpdb->prefix}aitp_$t"); foreach (array("aitp_queue_state","aitp_queue_lock","aitp_map_state","aitp_map_lock","aitp_journal_state") as $o) delete_option($o); echo 1;' );
		opt( 'aitp_e2e_footer', [] );
		opt( 'aitp_e2e_tamper', '' );
		opt( 'aitp_e2e_visit_log', 1 );
		h( 'visits', 'clear' );
		const pg = ev( `global $wpdb; $o = array();
			foreach ( $in as $k => $d ) {
				$slug = sanitize_title( $d[0] );
				$id   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_name = %s AND post_type = 'page' AND post_status = 'publish'", $slug ) );
				if ( ! $id ) { $id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => $d[1], 'post_name' => $d[0], 'post_content' => '<p>' . $d[2] . '</p>' ) ); }
				$src = get_permalink( $id );
				$o[ $k ] = array( 'id' => $id, 'src' => $src, 'url' => AITP_Queue::translated_url( $src, $k ), 'lang' => $k );
			}
			echo wp_json_encode( $o );`, { ja: [ 'キッズ-tr90', 'Kids TR90 (Japanese address)', SJ ], ar: [ 'نظارات-tr90', 'Kids glasses (Arabic address)', SA ] } );
		const enqueue = ( items ) => ev( 'echo wp_json_encode(array("n" => AITP_Queue::requeue_urls(array_map(function ($p) { return array("url" => $p["url"], "source_url" => $p["src"], "lang" => $p["lang"], "object_type" => "post", "object_id" => $p["id"]); }, $in))));', items );
		wp( 'eval', `update_option('aitp_queue_settings', array_merge(AITP_Queue::defaults(), array('languages' => array('ja','ar'))), false); echo 1;` );

		// 1. Queue visits through the real wp_remote_get chain.
		const req0 = ( await mockStats() ).requests;
		enqueue( [ pg.ja, pg.ar ] );
		q( 'start' );
		const qrun = json( q( 'run', '--ignore-rate', '--minutes=3' ) );
		q( 'pause' );
		const qrows = h( 'queue' ).rows;
		const rj = h( 'find', 'ja', SJ );
		const ra = h( 'find', 'ar', SA );
		const req1 = ( await mockStats() ).requests;
		const visits1 = h( 'visits' ).visits;
		record( 'signature: queue translates pages with Japanese and Arabic addresses', {
			urls: [ pg.ja.url, pg.ar.url ], run: qrun, queue: qrows, ja: [ rj.id, rj.status ], ar: [ ra.id, ra.status ], ai_requests: req1 - req0, visits: visits1.slice( 0, 4 ),
		}, {
			addresses_have_lower_case_hex: /%[0-9a-f]*[a-f]/.test( pg.ja.url ) && /%[0-9a-f]*[a-f]/.test( pg.ar.url ),
			library_sends_upper_case: visits1.length > 0 && visits1.every( ( v ) => ! /%[0-9A-F]?[a-f]/.test( v ) ),
			both_pages_done: qrows.length === 2 && qrows.every( ( r ) => r.status === 'done' && r.note === '' ),
			strings_translated: rj.status === '1' && ra.status === '1',
			ai_used: req1 > req0,
		} );

		// 2. Map visits of the same pages: read, nothing translated or saved.
		const dj0 = h( 'dict', 'ja' );
		const da0 = h( 'dict', 'ar' );
		wp( 'aitp', 'map', 'start', `--urls=${ pg.ja.src },${ pg.ar.src }`, '--languages=ja,ar' );
		const mrun = m( 'run', '--ignore-rate', '--minutes=2' );
		const mrows = m( 'list' ).rows;
		const exJ = json( wp( 'aitp', 'map', 'export', `--pages=${ ( mrows.find( ( r ) => r.url === pg.ja.url ) || {} ).id }` ) ).rows.map( ( r ) => Number( r.id ) );
		const exA = json( wp( 'aitp', 'map', 'export', `--pages=${ ( mrows.find( ( r ) => r.url === pg.ar.url ) || {} ).id }` ) ).rows.map( ( r ) => Number( r.id ) );
		const req2 = ( await mockStats() ).requests;
		record( 'signature: map visits read pages with non-ASCII addresses', {
			run: mrun, rows: mrows.map( ( r ) => [ r.lang, r.http, r.found, r.note ] ), ai_requests: req2 - req1,
		}, {
			all_read: mrun.status === 'done' && mrows.length === 4 && mrows.every( ( r ) => r.http === '200' && r.note === '' && Number( r.found ) > 0 ),
			page_rows_found: exJ.includes( Number( rj.id ) ) && exA.includes( Number( ra.id ) ),
			nothing_changed: h( 'dict', 'ja' ).sum === dj0.sum && h( 'dict', 'ar' ).sum === da0.sum,
			no_ai_request: req2 === req1,
		} );

		// 3. Refused signatures: the page is served without translating or saving, and says why.
		h( 'set', 'ja', rj.id, 0, '__NULL__' );
		wp( 'eval', 'global $wpdb; $wpdb->query("TRUNCATE {$wpdb->prefix}trp_machine_translation_locks"); echo 1;' );
		const dj1 = h( 'dict', 'ja' );
		const o1 = h( 'originals' );
		const req3 = ( await mockStats() ).requests;
		h( 'visits', 'clear' );
		const direct = {};
		for ( const t of [ 'signature', 'format', 'expired', 'replay' ] ) {
			opt( 'aitp_e2e_tamper', t );
			const v = ev( 'echo wp_json_encode(AITP_Queue::visit($in, "ja", 20));', pg.ja.url );
			direct[ t ] = { http: v.http, report: v.report, sig: v.sig, translated: v.translated, calls: v.calls };
		}
		opt( 'aitp_e2e_tamper', 'signature' );
		enqueue( [ pg.ja ] );
		q( 'start' );
		json( q( 'run', '--ignore-rate', '--pages=1', '--minutes=1' ) );
		q( 'pause' );
		const qbad = h( 'queue' ).rows.find( ( r ) => r.url === pg.ja.url ) || {};
		wp( 'aitp', 'map', 'start', `--urls=${ pg.ja.src }`, '--languages=ja' );
		m( 'run', '--ignore-rate', '--minutes=1' );
		const mbad = m( 'list' ).rows.find( ( r ) => r.url === pg.ja.url && r.lang === 'ja' ) || {};
		const dj2 = h( 'dict', 'ja' );
		const o2 = h( 'originals' );
		const req4 = ( await mockStats() ).requests;
		const rj2 = h( 'find', 'ja', SJ );
		const reached = h( 'visits' ).visits.length;
		record( 'signature: refused visits translate and save nothing, and say why', {
			direct, queue: [ qbad.status, qbad.note ], map: [ mbad.http, mbad.note ], reached, row: [ rj2.status, rj2.is_null ], ai_requests: req4 - req3,
		}, {
			reasons_reported: Object.entries( direct ).every( ( [ t, v ] ) => v.sig === t && v.http === 200 && v.report === false && v.translated === 0 ),
			requests_reached_the_site: reached >= 6,
			queue_note: qbad.note === 'signature' && qbad.status === 'pending',
			map_note: mbad.note === 'signature',
			dictionary_unchanged: dj2.sum === dj1.sum && rj2.status === '0' && rj2.is_null === true,
			originals_unchanged: o2.rows === o1.rows,
			no_ai_request: req4 === req3,
		} );

		// 4. With a good signature again, the same page is translated.
		opt( 'aitp_e2e_tamper', '' );
		enqueue( [ pg.ja ] );
		q( 'start' );
		json( q( 'run', '--ignore-rate', '--minutes=2' ) );
		q( 'pause' );
		const rj3 = h( 'find', 'ja', SJ );
		const qok = h( 'queue' ).rows.find( ( r ) => r.url === pg.ja.url ) || {};
		opt( 'aitp_e2e_visit_log', 0 );
		// The other scenarios count the pages of the site: remove the two test pages again.
		ev( 'foreach ($in as $id) wp_delete_post((int) $id, true); echo wp_json_encode(array("ok" => 1));', [ pg.ja.id, pg.ar.id ] );
		record( 'signature: valid visits work again afterwards', { row: [ rj3.status ], queue: [ qok.status, qok.note ] }, {
			translated_again: rj3.status === '1',
			queue_done: qok.status === 'done' && qok.note === '',
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
