<?php
/**
 * Minimal test harness (no PHPUnit dependency).
 */
$GLOBALS['aitp_tests']   = array();
$GLOBALS['aitp_results'] = array( 'passed' => 0, 'failed' => 0, 'failures' => array(), 'names' => array() );

function test( $name, $fn ) {
	$GLOBALS['aitp_tests'][] = array( $name, $fn );
}

class AITP_Assert_Failure extends Exception {}

function ok( $cond, $msg = '' ) {
	if ( ! $cond ) {
		throw new AITP_Assert_Failure( $msg !== '' ? $msg : 'assertion failed' );
	}
}

function eq( $expected, $actual, $msg = '' ) {
	if ( $expected !== $actual ) {
		throw new AITP_Assert_Failure( ( $msg !== '' ? $msg . ': ' : '' ) . 'expected ' . json_encode( $expected, JSON_UNESCAPED_UNICODE ) . ' got ' . json_encode( $actual, JSON_UNESCAPED_UNICODE ) );
	}
}

function has( $needle, array $haystack, $msg = '' ) {
	if ( ! in_array( $needle, $haystack, true ) ) {
		throw new AITP_Assert_Failure( ( $msg !== '' ? $msg . ': ' : '' ) . json_encode( $needle, JSON_UNESCAPED_UNICODE ) . ' not in ' . json_encode( $haystack, JSON_UNESCAPED_UNICODE ) );
	}
}

function run_tests( $filter = '' ) {
	foreach ( $GLOBALS['aitp_tests'] as $t ) {
		list( $name, $fn ) = $t;
		if ( $filter !== '' && stripos( $name, $filter ) === false ) {
			continue;
		}
		try {
			$fn();
			$GLOBALS['aitp_results']['passed']++;
			$GLOBALS['aitp_results']['names'][] = $name;
		} catch ( Throwable $e ) {
			$GLOBALS['aitp_results']['failed']++;
			$GLOBALS['aitp_results']['failures'][] = $name . ' :: ' . $e->getMessage() . ( $e instanceof AITP_Assert_Failure ? '' : ' @' . basename( $e->getFile() ) . ':' . $e->getLine() );
		}
	}
	$r = $GLOBALS['aitp_results'];
	foreach ( $r['failures'] as $f ) {
		fwrite( STDOUT, "FAIL  $f\n" );
	}
	fwrite( STDOUT, sprintf( "\n%d passed, %d failed\n", $r['passed'], $r['failed'] ) );
	return $r;
}
