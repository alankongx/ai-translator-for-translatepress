<?php
/**
 * Backfill queue rules.
 */
function qv( array $v ) {
	return array_merge( array( 'http' => 200, 'report' => true, 'calls' => 0, 'translated' => 0, 'failed' => 0, 'stop' => '' ), $v );
}

test( 'queue: no engine call -> page done', function () {
	$d = AITP_Queue_Logic::decide( array( 'visits' => 2 ), qv( array() ), 1000 );
	eq( 'done', $d['status'] );
	eq( 0, $d['visits'] );
} );

test( 'queue: new translations -> visit again right away', function () {
	$d = AITP_Queue_Logic::decide( array( 'visits' => 0 ), qv( array( 'calls' => 2, 'translated' => 30 ) ), 1000 );
	eq( 'pending', $d['status'] );
	eq( 1000, $d['next_at'] );
	eq( 1, $d['visits'] );
} );

test( 'queue: time ran out -> continue', function () {
	$d = AITP_Queue_Logic::decide( array(), qv( array( 'calls' => 1, 'translated' => 0, 'failed' => 50, 'stop' => 'deferred' ) ), 1000 );
	eq( 'pending', $d['status'] );
} );

test( 'queue: progress that never ends -> set aside after max visits', function () {
	$d = AITP_Queue_Logic::decide( array( 'visits' => AITP_Queue_Logic::MAX_VISITS - 1 ), qv( array( 'calls' => 1, 'translated' => 3 ) ), 1000 );
	eq( 'partial', $d['status'] );
	eq( 'max_visits', $d['note'] );
	eq( 1000 + 3600, $d['next_at'] );
} );

test( 'queue: strings keep failing -> partial, later passes wait longer', function () {
	$d1 = AITP_Queue_Logic::decide( array(), qv( array( 'calls' => 1, 'failed' => 4 ) ), 1000 );
	eq( 'partial', $d1['status'] );
	eq( 'strings_failing', $d1['note'] );
	eq( 1000 + 3600, $d1['next_at'] );
	$d4 = AITP_Queue_Logic::decide( array( 'passes' => 3 ), qv( array( 'calls' => 1, 'failed' => 4 ) ), 1000 );
	eq( 1000 + 86400, $d4['next_at'] );
} );

test( 'queue: provider trouble -> page kept, whole queue waits', function () {
	$d = AITP_Queue_Logic::decide( array( 'visits' => 1 ), qv( array( 'calls' => 1, 'failed' => 20, 'stop' => 'rate_limit' ) ), 1000 );
	eq( 'pending', $d['status'] );
	eq( 300, $d['queue_wait'] );
	eq( 1, $d['visits'], 'visit not counted' );
	$a = AITP_Queue_Logic::decide( array(), qv( array( 'calls' => 1, 'failed' => 20, 'stop' => 'auth' ) ), 1000 );
	eq( 1800, $a['queue_wait'] );
	$m = AITP_Queue_Logic::decide( array(), qv( array( 'calls' => 1, 'failed' => 20, 'stop' => 'misaligned' ) ), 1000 );
	ok( $m['queue_wait'] > 0, 'misaligned answers pause the queue' );
} );

test( 'queue: page unreachable -> retried, then failed', function () {
	$d = AITP_Queue_Logic::decide( array(), qv( array( 'http' => 0, 'report' => false ) ), 1000 );
	eq( 'pending', $d['status'] );
	eq( 'unreachable', $d['note'] );
	eq( 1300, $d['next_at'] );
	$d = AITP_Queue_Logic::decide( array( 'attempts' => 2 ), qv( array( 'http' => 500, 'report' => false ) ), 1000 );
	eq( 'failed', $d['status'] );
	eq( 'http_500', $d['note'] );
} );

test( 'queue: redirect -> failed at once; answer without report -> page cache suspected', function () {
	$d = AITP_Queue_Logic::decide( array(), qv( array( 'http' => 301, 'report' => false ) ), 1000 );
	eq( 'failed', $d['status'] );
	eq( 'redirect', $d['note'] );
	$c = AITP_Queue_Logic::decide( array(), qv( array( 'http' => 200, 'report' => false ) ), 1000 );
	eq( 'no_report', $c['note'] );
	eq( 'pending', $c['status'] );
	$c3 = AITP_Queue_Logic::decide( array( 'attempts' => 2 ), qv( array( 'http' => 200, 'report' => false ) ), 1000 );
	eq( 'failed', $c3['status'] );
} );

test( 'queue: signature valid only for its path, time window and secret', function () {
	$h = AITP_Queue_Logic::sign( 'secret', '/fr/about/?aitp_nc=1', 1000000000, 'abcdef0123456789' );
	eq( 'abcdef0123456789', AITP_Queue_Logic::verify( 'secret', $h, '/fr/about/?aitp_nc=1', 1000000100 ) );
	eq( '', AITP_Queue_Logic::verify( 'secret', $h, '/fr/other/?aitp_nc=1', 1000000100 ), 'other path' );
	eq( '', AITP_Queue_Logic::verify( 'other', $h, '/fr/about/?aitp_nc=1', 1000000100 ), 'other secret' );
	eq( '', AITP_Queue_Logic::verify( 'secret', $h, '/fr/about/?aitp_nc=1', 1000000400 ), 'expired' );
	eq( '', AITP_Queue_Logic::verify( 'secret', 'garbage', '/fr/about/', 1000000100 ) );
	$t = substr( $h, 0, -1 ) . ( substr( $h, -1 ) === 'a' ? 'b' : 'a' );
	eq( '', AITP_Queue_Logic::verify( 'secret', $t, '/fr/about/?aitp_nc=1', 1000000100 ), 'tampered' );
} );

test( 'queue: budget keeps a reserve of the TranslatePress limit for visitors', function () {
	eq( '', AITP_Queue_Logic::budget_block( 1000, 300000, 100000, 1000000, 0.2 ) );
	eq( 'queue_cap', AITP_Queue_Logic::budget_block( 300000, 300000, 100000, 1000000, 0.2 ) );
	eq( 'tp_limit', AITP_Queue_Logic::budget_block( 1000, 300000, 800000, 1000000, 0.2 ) );
	eq( '', AITP_Queue_Logic::budget_block( 9999999, 0, 100, 1000000, 0.2 ), 'no own cap' );
} );
