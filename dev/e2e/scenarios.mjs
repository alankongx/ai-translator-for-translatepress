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
		const rep = a( 'repair', '--severity=error', '--yes' );
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
