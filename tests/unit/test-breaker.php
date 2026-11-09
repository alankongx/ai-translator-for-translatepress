<?php
test( 'breaker: auth pauses, one probe every 30 min, success closes', function () {
	$t = 1000;
	$b = new AITP_Circuit_Breaker( new AITP_Memory_State(), function () use ( &$t ) {
		return $t;
	} );
	ok( $b->allow() );
	$b->failure( 'auth' );
	eq( 'paused', $b->state()['status'] );
	ok( ! $b->allow() );
	$t += 1801;
	ok( $b->allow(), 'probe' );
	ok( ! $b->allow(), 'only one probe' );
	$b->success();
	eq( 'closed', $b->state()['status'] );
} );

test( 'breaker: rate limit honours retry-after and grows', function () {
	$t = 0;
	$b = new AITP_Circuit_Breaker( new AITP_Memory_State(), function () use ( &$t ) {
		return $t;
	} );
	$b->failure( 'rate_limit', 40 );
	eq( 40, $b->state()['until'] );
	$t = 41;
	ok( $b->allow() );
	$b->failure( 'rate_limit', null );
	eq( 41 + 30, $b->state()['until'] );
} );

test( 'breaker: transient errors open after 3', function () {
	$b = new AITP_Circuit_Breaker( new AITP_Memory_State(), function () {
		return 0;
	} );
	$b->failure( 'server' );
	$b->failure( 'timeout' );
	ok( $b->allow() );
	$b->failure( 'server' );
	ok( ! $b->allow() );
	$b->reset();
	ok( $b->allow() );
} );

test( 'breaker: daily quota waits at least an hour', function () {
	$b = new AITP_Circuit_Breaker( new AITP_Memory_State(), function () {
		return 0;
	} );
	$b->failure( 'quota_daily', 10 );
	eq( 3600, $b->state()['until'] );
} );
