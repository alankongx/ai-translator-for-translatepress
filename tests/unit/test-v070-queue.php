<?php
/**
 * 0.7.0 batch 2: queue and long texts (one test per review finding).
 */

function long_sentences( $n ) {
	$sent = array();
	for ( $i = 1; $i <= $n; $i++ ) {
		$sent[] = "Sentence number $i describes our sunglasses and optical frames.";
	}
	return implode( ' ', $sent );
}

/* B03 / E02: "done" only when TranslatePress really had nothing to send. */

test( 'B03: progress -> back after the TranslatePress lock (55 s), not at once', function () {
	$d = AITP_Queue_Logic::decide( array(), qv( array( 'calls' => 1, 'translated' => 30, 'failed' => 5 ) ), 1000 );
	eq( 'pending', $d['status'] );
	eq( 1055, $d['next_at'] );
	$d = AITP_Queue_Logic::decide( array(), qv( array( 'calls' => 1, 'stop' => 'deferred' ) ), 1000, 70 );
	eq( 1070, $d['next_at'], 'revisit follows the lock timeout' );
} );

test( 'B03: no engine call because strings were locked -> not done', function () {
	$d = AITP_Queue_Logic::decide( array( 'visits' => 1 ), qv( array( 'calls' => 0, 'locked' => 5 ) ), 1000 );
	eq( 'pending', $d['status'] );
	eq( 'locked', $d['note'] );
	eq( 1055, $d['next_at'] );
	$d = AITP_Queue_Logic::decide( array( 'visits' => AITP_Queue_Logic::MAX_VISITS - 1 ), qv( array( 'calls' => 0, 'locked' => 5 ) ), 1000 );
	eq( 'partial', $d['status'], 'set aside when it stays locked' );
	$d = AITP_Queue_Logic::decide( array( 'visits' => 3 ), qv( array( 'calls' => 0, 'locked' => 0 ) ), 1000 );
	eq( 'done', $d['status'], 'nothing locked, nothing sent -> done' );
} );

test( 'B03: engine without a usable key, TranslatePress limit -> queue waits, page kept', function () {
	$d = AITP_Queue_Logic::decide( array(), qv( array( 'calls' => 1, 'stop' => 'not_configured' ) ), 1000 );
	eq( 'pending', $d['status'] );
	eq( 1800, $d['queue_wait'] );
	$d = AITP_Queue_Logic::decide( array(), qv( array( 'calls' => 0, 'stop' => 'tp_limit' ) ), 1000 );
	eq( 'pending', $d['status'] );
	ok( $d['queue_wait'] > 0 );
	$d = AITP_Queue_Logic::decide( array(), qv( array( 'calls' => 1, 'failed' => 3, 'stop' => 'refused' ) ), 1000 );
	eq( 0, $d['queue_wait'], 'one language refused: the others go on' );
} );

/* A16: long texts keep their finished segments. */

test( 'A16: a long text that needs several calls only resends missing segments', function () {
	$text  = long_sentences( 12 );
	$cache = new AITP_Memory_Cache();
	// Each call: only the first request is answered, the rest are "not sent" (time ran out).
	$answer = function ( $r, $first ) {
		if ( ! $first ) {
			return array( 'status' => 0, 'headers' => array(), 'body' => '', 'error' => 'Not sent', 'error_type' => 'skipped', 'duration_ms' => 0 );
		}
		list( $items ) = req_items( $r );
		$o = array();
		foreach ( $items as $id => $t ) {
			$o[ $id ] = pseudo( $t );
		}
		return gemini_ok( $o );
	};
	$log   = array();
	$calls = 0;
	$tr    = new AITP_Test_Transport( function ( $r, $n ) use ( $answer, &$log, &$calls ) {
		list( $items ) = req_items( $r );
		$first = ! isset( $log[ $calls ] );
		if ( $first ) {
			$log[ $calls ] = array_values( $items );
		}
		return $answer( $r, $first );
	} );
	$t      = make_translator( $tr, array( 'split_bytes' => 300, 'batch_items' => 1 ), array( 'cache' => $cache ) );
	$parts  = 0;
	$result = null;
	for ( $i = 0; $i < 10 && $result === null; $i++ ) {
		$calls = $i;
		$r     = $t->translate( array( 'long' => $text ), 'en_US', 'fr_FR', later() );
		if ( $i === 0 ) {
			eq( 'deferred', $r['failed']['long'] );
			$parts = count( $tr->calls );
		}
		if ( isset( $r['translations']['long'] ) ) {
			$result = $r['translations']['long'];
		}
	}
	ok( $parts >= 3, 'text was split' );
	eq( pseudo( $text ), $result );
	$all = array();
	foreach ( $log as $sent_items ) {
		$all = array_merge( $all, $sent_items );
	}
	eq( count( $all ), count( array_unique( $all ) ), 'no segment was answered twice' );
	eq( $parts, count( $log ), 'one answered request per segment' );
	eq( 1, count( $cache->rows ), 'segments dropped once the whole text is cached' );
} );

test( 'A16: a segment from the cache is checked like a fresh answer', function () {
	$text  = long_sentences( 12 );
	$cache = new AITP_Memory_Cache();
	$tr    = new AITP_Test_Transport( pseudo_responder() );
	$t     = make_translator( $tr, array( 'split_bytes' => 300, 'batch_items' => 1 ), array( 'cache' => $cache ) );
	$t->translate( array( 'long' => $text . ' extra' ), 'en_US', 'fr_FR', later() );
	// Plant a bad segment for a text under way: English left as translation.
	$tr2 = new AITP_Test_Transport( function ( $r, $n ) {
		return $n === 1 ? pseudo_responder()( $r, $n ) : array( 'status' => 0, 'headers' => array(), 'body' => '', 'error' => 'Not sent', 'error_type' => 'skipped', 'duration_ms' => 0 );
	} );
	$t2 = make_translator( $tr2, array( 'split_bytes' => 300, 'batch_items' => 1 ), array( 'cache' => $cache ) );
	$t2->translate( array( 'long' => $text ), 'en_US', 'fr_FR', later() );
	$keys = array_keys( $cache->rows );
	foreach ( $keys as $k ) {
		$cache->rows[ $k ] = 'Sentence number 1 describes our sunglasses and optical frames.';
	}
	$tr3 = new AITP_Test_Transport( pseudo_responder() );
	$r   = make_translator( $tr3, array( 'split_bytes' => 300, 'batch_items' => 1 ), array( 'cache' => $cache ) )->translate( array( 'long' => $text ), 'en_US', 'fr_FR', later() );
	eq( pseudo( $text ), $r['translations']['long'], 'poisoned segment re-requested' );
} );

/* D11 / A22: a short time budget (a visitor waiting). */

test( 'D11: little time left -> smaller requests', function () {
	$items = array();
	for ( $i = 0; $i < 40; $i++ ) {
		$items[] = "Product line number $i with flexible frames";
	}
	$tr = new AITP_Test_Transport( pseudo_responder() );
	make_translator( $tr )->translate( $items, 'en_US', 'fr_FR', microtime( true ) + 10 );
	ok( count( $tr->calls ) >= 3, 'split into smaller requests: ' . count( $tr->calls ) );
	$tr = new AITP_Test_Transport( pseudo_responder() );
	make_translator( $tr )->translate( $items, 'en_US', 'fr_FR', later() );
	eq( 1, count( $tr->calls ), 'plenty of time: one request' );
} );

test( 'A22: a request cut off by the call\'s own deadline is not the provider\'s fault', function () {
	$state = new AITP_Memory_State();
	$f     = new AITP_Memory_Failures();
	$tr    = new AITP_Test_Transport( function () {
		return array( 'status' => 0, 'headers' => array(), 'body' => '', 'error' => 'Operation timed out', 'error_type' => 'timeout', 'duration_ms' => 8000 );
	} );
	$t = make_translator( $tr, array(), array( 'breaker' => new AITP_Circuit_Breaker( $state ), 'failures' => $f ) );
	$r = $t->translate( array( 'Contact our sales team today', 'Request a sample with your logo' ), 'en_US', 'fr_FR', microtime( true ) + 9 );
	eq( 'deferred', $r['failed'][0] );
	eq( 'deferred', $r['stats']['stop_reason'] );
	eq( 1, count( $tr->calls ), 'not split and resent' );
	ok( empty( $state->state['fails'] ), 'breaker not charged' );
	ok( ! $f->held( AITP_Translator::failure_key( 'Contact our sales team today', 'en_US', 'fr_FR' ) ), 'no per-item failure' );
	// A real timeout (full time available) still counts.
	$state2 = new AITP_Memory_State();
	make_translator( $tr, array(), array( 'breaker' => new AITP_Circuit_Breaker( $state2 ) ) )->translate( array( 'Contact our sales team today' ), 'en_US', 'fr_FR', later() );
	ok( ! empty( $state2->state['fails'] ), 'provider timeout counted' );
} );

/* B07: the lock value format (parsed by AITP_Lock, written by older versions as a number). */

test( 'B07: lock values: new format and the plain number of older versions', function () {
	if ( ! class_exists( 'AITP_Lock' ) ) {
		require_once dirname( __DIR__, 2 ) . '/includes/wp/class-aitp-lock.php';
	}
	$p = AITP_Lock::parse( '1700000600|abc|repair|1700000000' );
	eq( 1700000600, $p['until'] );
	eq( 'repair', $p['who'] );
	eq( 1700000000, $p['since'] );
	eq( 1700000600, AITP_Lock::parse( '1700000600' )['until'] );
	eq( 0, AITP_Lock::parse( 'a:3:{s:5:"until";i:1;}' )['until'], 'old serialized value: stale' );
} );

test( 'D11: smaller requests do not multiply what a refused key or a rate limit costs', function () {
	$items = array();
	for ( $i = 0; $i < 40; $i++ ) {
		$items[] = "Product line number $i with flexible frames";
	}
	foreach ( array(
		array( 400, '{"error":{"code":400,"message":"API key not valid. Please pass a valid API key.","status":"INVALID_ARGUMENT"}}', 'auth' ),
		array( 429, '{"error":{"code":429,"message":"Resource has been exhausted","status":"RESOURCE_EXHAUSTED"}}', 'rate_limit' ),
	) as $case ) {
		$tr = new AITP_Test_Transport( function () use ( $case ) {
			return array( 'status' => $case[0], 'headers' => array(), 'body' => $case[1], 'error' => '', 'duration_ms' => 5 );
		} );
		$r = make_translator( $tr, array( 'batch_items' => 4 ) )->translate( $items, 'en_US', 'fr_FR', later() );
		eq( 3, count( $tr->calls ), $case[2] . ': one wave only' );
		eq( $case[2], $r['stats']['stop_reason'] );
		eq( 3, $r['stats']['requests'] );
	}
} );
