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

test( 'queue: one spelling of a path for signing and checking', function () {
	$c = function ( $p ) {
		return AITP_Queue_Logic::canonical_path( $p );
	};
	eq( '/ja/tr90-%E3%82%AD/', $c( '/ja/tr90-%e3%82%ad/' ), 'hex in upper case' );
	eq( '/ja/tr90-%E3%82%AD/', $c( '/ja/tr90-%E3%82%AD/' ), 'already canonical' );
	eq( '/fr/~user/ABC-_./', $c( '/fr/%7euser/%41BC%2d%5F%2e/' ), 'unreserved decoded' );
	eq( '/ja/%E6%97%A5%E6%9C%AC/?q=%E6%97%A5', $c( '/ja/日本/?q=日' ), 'raw Japanese' );
	eq( '/ar/%D9%86%D8%B8%D8%A7%D8%B1%D8%A7%D8%AA/', $c( '/ar/نظارات/' ), 'raw Arabic' );
	eq( '/fr/%D9%86%D8%B8/', $c( '/fr/%d9%86%D8%b8/' ), 'mixed case' );
	eq( '/fr/a%2Fb%3Fc%25d%26e/?x=%2F&y=a%26b&z=2&z=1', $c( '/fr/a%2fb%3fc%25d%26e/?x=%2f&y=a%26b&z=2&z=1' ), 'reserved kept, query order kept' );
	eq( '/fr/About-Us/?B=2&a=1', $c( '/fr/About-Us/?B=2&a=1' ), 'case of path and query kept' );
	eq( '/fr/a%20b/?q=a+b%20c', $c( '/fr/a b/?q=a+b c' ), 'space encoded, plus kept' );
	eq( '/fr/x%25zz/?q=100%25', $c( '/fr/x%zz/?q=100%' ), 'stray percent' );
	eq( '/fr/x%22%3C%3E%5C%5E%60%7B%7C%7D/', $c( '/fr/x"<>\\^`{|}/' ), 'characters that may not appear raw' );
	eq( "/fr/a%0A%7F/", $c( "/fr/a\n\x7f/" ), 'control bytes' );
	foreach ( array( '/ja/日本/?a=%7e', '/fr/%e3%82%ad/?b=%2f' ) as $p ) {
		eq( $c( $p ), $c( $c( $p ) ), 'idempotent' );
	}
} );

test( 'queue: signature survives the HTTP library rewriting the address', function () {
	$sent = '/ja/tr90-%e3%82%ad%e3%83%83%e3%82%ba/?aitp_nc=1a2b3c4d&aitp_map=1';
	$h    = AITP_Queue_Logic::sign( 'secret', $sent, 1000000000, 'abcdef0123456789' );
	eq( 'abcdef0123456789', AITP_Queue_Logic::verify( 'secret', $h, '/ja/tr90-%E3%82%AD%E3%83%83%E3%82%BA/?aitp_nc=1a2b3c4d&aitp_map=1', 1000000100 ), 'upper-case hex received' );
	eq( 'abcdef0123456789', AITP_Queue_Logic::verify( 'secret', $h, '/ja/tr90-キッズ/?aitp_nc=1a2b3c4d&aitp_map=1', 1000000100 ), 'decoded by a proxy' );
	$h2 = AITP_Queue_Logic::sign( 'secret', '/ar/نظارات/?aitp_nc=1', 1000000000, 'abcdef0123456789' );
	eq( 'abcdef0123456789', AITP_Queue_Logic::verify( 'secret', $h2, '/ar/%D9%86%D8%B8%D8%A7%D8%B1%D8%A7%D8%AA/?aitp_nc=1', 1000000100 ), 'raw Arabic signed' );
	eq( '', AITP_Queue_Logic::verify( 'secret', $h, '/ja/tr90-%E3%82%AD%E3%83%83%E3%82%BB/?aitp_nc=1a2b3c4d&aitp_map=1', 1000000100 ), 'other page' );
	eq( '', AITP_Queue_Logic::verify( 'secret', $h, '/ja/tr90-%E3%82%AD%E3%83%83%E3%82%BA/?aitp_nc=1a2b3c4d', 1000000100 ), 'map flag removed' );
	$h3 = AITP_Queue_Logic::sign( 'secret', '/fr/a%2Fb/', 1000000000, 'abcdef0123456789' );
	eq( '', AITP_Queue_Logic::verify( 'secret', $h3, '/fr/a/b/', 1000000100 ), 'encoded slash is not a slash' );
	$t = substr( $h, 0, -1 ) . ( substr( $h, -1 ) === 'a' ? 'b' : 'a' );
	eq( array( 'nonce' => '', 'reason' => 'signature' ), AITP_Queue_Logic::check( 'secret', $t, $sent, 1000000100 ), 'tampered' );
	eq( array( 'nonce' => '', 'reason' => 'expired' ), AITP_Queue_Logic::check( 'secret', $h, $sent, 1000000400 ), 'expired' );
	eq( array( 'nonce' => '', 'reason' => 'format' ), AITP_Queue_Logic::check( 'secret', 'x', $sent, 1000000100 ), 'format' );
	eq( array( 'nonce' => 'abcdef0123456789', 'reason' => '' ), AITP_Queue_Logic::check( 'secret', $h, $sent, 1000000100 ) );
} );

test( 'queue: refused signature is reported as such, not as a page cache', function () {
	$d = AITP_Queue_Logic::decide( array(), qv( array( 'report' => false, 'sig' => 'signature' ) ), 1000 );
	eq( 'signature', $d['note'] );
	eq( 'pending', $d['status'] );
	eq( 1, $d['attempts'] );
} );

test( 'queue: budget keeps a reserve of the TranslatePress limit for visitors', function () {
	eq( '', AITP_Queue_Logic::budget_block( 1000, 300000, 100000, 1000000, 0.2 ) );
	eq( 'queue_cap', AITP_Queue_Logic::budget_block( 300000, 300000, 100000, 1000000, 0.2 ) );
	eq( 'tp_limit', AITP_Queue_Logic::budget_block( 1000, 300000, 800000, 1000000, 0.2 ) );
	eq( '', AITP_Queue_Logic::budget_block( 9999999, 0, 100, 1000000, 0.2 ), 'no own cap' );
} );
