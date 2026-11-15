<?php

if ( ! function_exists( 'wp_list_pluck_test' ) ) {
	function wp_list_pluck_test( array $list, $k ) {
		return array_map( function ( $x ) use ( $k ) { return $x[ $k ]; }, $list );
	}
}
/**
 * Decisions of the batch auto repair.
 */

test( 'autofix: settings are off by default and clamped', function () {
	$s = AITP_Autofix_Logic::clean_settings( array(), array( 'de_DE', 'fr_FR' ), 1000000 );
	eq( false, $s['enabled'], 'off by default' );
	eq( array(), $s['languages'] );
	eq( 200, $s['batch'] );
	eq( 500000, $s['cap'], 'default cap = half the TranslatePress limit' );
	$s = AITP_Autofix_Logic::clean_settings( array( 'enabled' => '1', 'languages' => array( 'fr_FR', 'xx', 'fr_FR' ), 'batch' => 9999, 'cap' => '1200' ), array( 'de_DE', 'fr_FR' ), 1000000 );
	eq( true, $s['enabled'] );
	eq( array( 'fr_FR' ), $s['languages'], 'unknown and repeated languages dropped' );
	eq( 500, $s['batch'], 'at most 500 rows' );
	eq( 1200, $s['cap'] );
	eq( 20, AITP_Autofix_Logic::clean_settings( array( 'batch' => 1 ), array(), 0 )['batch'], 'at least 20 rows' );
	eq( 500000, AITP_Autofix_Logic::clean_settings( array( 'cap' => 0 ), array(), 0 )['cap'], 'no TranslatePress limit -> fixed default' );
	eq( 1, AITP_Autofix_Logic::clean_settings( array(), array(), 0 )['max_batches'], 'one batch per start by default (C13)' );
	eq( 0, AITP_Autofix_Logic::clean_settings( array( 'max_batches' => '0' ), array(), 0 )['max_batches'], '0 = no limit, when set' );
	eq( 1, AITP_Autofix_Logic::clean_settings( array( 'max_batches' => '1' ), array(), 0 )['max_batches'], 'one batch per start' );
	eq( 0, AITP_Autofix_Logic::clean_settings( array( 'max_batches' => '-3' ), array(), 0 )['max_batches'], 'negative -> no limit' );
	eq( 1000, AITP_Autofix_Logic::clean_settings( array( 'max_batches' => 99999 ), array(), 0 )['max_batches'], 'at most 1000' );
} );

test( 'autofix: rounds of page visits', function () {
	eq( 'judge', AITP_Autofix_Logic::after_pass( 1, 0, 200 ), 'everything translated' );
	eq( 'again', AITP_Autofix_Logic::after_pass( 1, 30, 200 ), 'some left after the first round' );
	eq( 'again', AITP_Autofix_Logic::after_pass( 2, 10, 30 ), 'progress in round 2' );
	eq( 'again', AITP_Autofix_Logic::after_pass( 2, 30, 30, 0 ), 'one round without progress: one more try (C02)' );
	eq( 'judge', AITP_Autofix_Logic::after_pass( 3, 30, 30, 1 ), 'two rounds in a row without progress' );
	eq( 'again', AITP_Autofix_Logic::after_pass( 4, 20, 30, 1 ), 'progress again: the count starts over' );
	eq( 0, AITP_Autofix_Logic::stalls( 1, 20, 30 ), 'progress resets the stall count' );
	eq( 2, AITP_Autofix_Logic::stalls( 1, 30, 30 ) );
	eq( 'again', AITP_Autofix_Logic::after_pass( 3, 5, 10 ), 'a long article: more than 3 rounds while it makes progress' );
	eq( 'judge', AITP_Autofix_Logic::after_pass( 10, 5, 10 ), 'at most 10 rounds' );
	eq( 'judge', AITP_Autofix_Logic::after_pass( 4, 5, 10, 0, 1800 ), 'at most 30 minutes of visits' );
	eq( 'again', AITP_Autofix_Logic::after_pass( 4, 5, 10, 0, 1799 ) );
} );

test( 'autofix: judging a batch', function () {
	$j = AITP_Autofix_Logic::judge( array( 'reset' => 200, 'ok' => 195, 'wrong' => 3, 'untranslated' => 2 ) );
	eq( 'ok', $j['result'], '5 of 200 = 2.5%' );
	$j = AITP_Autofix_Logic::judge( array( 'reset' => 200, 'ok' => 189, 'wrong' => 6, 'untranslated' => 5 ) );
	eq( 'too_many_failures', $j['result'], '11 of 200 = 5.5%' );
	eq( 11, $j['failed'] );
	$j = AITP_Autofix_Logic::judge( array( 'reset' => 100, 'ok' => 99, 'wrong' => 1, 'english' => 1 ) );
	eq( 'english_saved', $j['result'], 'one English row is enough to stop' );
	$j = AITP_Autofix_Logic::judge( array( 'reset' => 40, 'ok' => 19, 'wrong' => 1, 'manual' => 20 ) );
	eq( 'ok', $j['result'], 'rows a person took over are not counted (1 of 20 = 5%)' );
	eq( 20, $j['base'] );
	$j = AITP_Autofix_Logic::judge( array( 'reset' => 10, 'gone' => 10 ) );
	eq( 'ok', $j['result'], 'nothing left to judge' );
} );

test( 'autofix: judging with refused and not reached rows (C02)', function () {
	// Rows the engine never saw (not reached) are not failures.
	$j = AITP_Autofix_Logic::judge( array( 'reset' => 200, 'ok' => 150, 'wrong' => 2, 'refused' => 3, 'left' => 45 ) );
	eq( 'ok', $j['result'], '5 failures of 155 reached = 3.2%' );
	eq( 155, $j['base'] );
	eq( 45, $j['left'] );
	$j = AITP_Autofix_Logic::judge( array( 'reset' => 200, 'ok' => 150, 'wrong' => 2, 'refused' => 8, 'left' => 40 ) );
	eq( 'too_many_failures', $j['result'], 'the engine refused 8: 10 of 160 = 6.25%' );
	$j = AITP_Autofix_Logic::judge( array( 'reset' => 100, 'ok' => 40, 'refused' => 0, 'left' => 60 ) );
	eq( 'many_left', $j['result'], 'more than half not reached: a person looks' );
	$j = AITP_Autofix_Logic::judge( array( 'reset' => 100, 'ok' => 50, 'refused' => 0, 'left' => 50 ) );
	eq( 'ok', $j['result'], 'exactly half is not more than half' );
	$j = AITP_Autofix_Logic::judge( array( 'reset' => 100, 'ok' => 30, 'manual' => 20, 'refused' => 0, 'left' => 50 ) );
	eq( 'many_left', $j['result'], 'half of the rows still ours (80): 50 > 40' );
	$j = AITP_Autofix_Logic::judge( array( 'reset' => 100, 'ok' => 39, 'wrong' => 1, 'english' => 1, 'refused' => 0, 'left' => 60 ) );
	eq( 'english_saved', $j['result'], 'English first' );
	$j = AITP_Autofix_Logic::judge( array( 'reset' => 10, 'ok' => 10, 'duplicate' => 4, 'refused' => 0, 'left' => 0 ) );
	eq( 'ok', $j['result'], 'duplicates count as ok' );
} );

test( 'autofix: sample pages to read (D40)', function () {
	$items = array();
	foreach ( array( 'pt:page', 'pt:page', 'pt:page', 'pt:post', 'home', 'pt:post', 'pt:post', 'tax:category', 'pt:product' ) as $i => $g ) {
		$items[] = array( 'url' => 'u' . $i, 'group' => $g );
	}
	$got = wp_list_pluck_test( AITP_Autofix_Logic::sample_pages( $items, 2, 24 ), 'url' );
	eq( array( 'u4', 'u0', 'u3', 'u7', 'u8', 'u1', 'u5' ), $got, 'home first, one of each kind, then a second of each' );
	$got = wp_list_pluck_test( AITP_Autofix_Logic::sample_pages( $items, 2, 3 ), 'url' );
	eq( array( 'u4', 'u0', 'u3' ), $got, 'at most the given number' );
	eq( array(), AITP_Autofix_Logic::sample_pages( array(), 2, 24 ), 'no pages' );
	$got = wp_list_pluck_test( AITP_Autofix_Logic::sample_pages( array( array( 'url' => 'a', 'group' => 'pt:page' ) ), 2, 24 ), 'url' );
	eq( array( 'a' ), $got, 'no home page in the list' );
} );

test( 'autofix: engine stop reasons', function () {
	eq( 'budget', AITP_Autofix_Logic::visit_stop( 'quota' ) );
	eq( 'provider', AITP_Autofix_Logic::visit_stop( 'auth' ) );
	eq( 'provider', AITP_Autofix_Logic::visit_stop( 'misaligned' ) );
	eq( 'provider', AITP_Autofix_Logic::visit_stop( 'breaker' ) );
	eq( '', AITP_Autofix_Logic::visit_stop( 'deferred' ), 'time budget of one page: carry on' );
	eq( '', AITP_Autofix_Logic::visit_stop( '' ) );
	eq( true, AITP_Autofix_Logic::is_english( array( 'too_short', 'english_residue' ) ) );
	eq( true, AITP_Autofix_Logic::is_english( array( 'untranslated' ) ) );
	eq( false, AITP_Autofix_Logic::is_english( array( 'number_mismatch' ) ) );
} );

test( 'repair: fewest pages that show every reset row', function () {
	$p = function ( $u ) {
		return array( 'url' => $u, 'lang' => 'de_DE' );
	};
	// Row a: own post page A and the home page; b and c: only on the home page (menu text);
	// d: only on page D.
	$cover = AITP_Audit_Logic::cover_pages(
		array(
			'a' => array( 'A' => $p( 'A' ), 'H' => $p( 'H' ) ),
			'b' => array( 'H' => $p( 'H' ) ),
			'c' => array( 'H' => $p( 'H' ), 'X' => $p( 'X' ) ),
			'd' => array( 'D' => $p( 'D' ) ),
			'e' => array(),
		)
	);
	eq( array( 'H', 'D' ), array_keys( $cover ), 'home covers a, b, c; D covers d; rows without a page add nothing' );
	$cover = AITP_Audit_Logic::cover_pages( array( 'a' => array( 'A' => $p( 'A' ), 'H' => $p( 'H' ) ) ) );
	eq( array( 'A' ), array_keys( $cover ), 'on a tie the first page (the post the string belongs to) wins' );
	eq( array(), AITP_Audit_Logic::cover_pages( array() ) );
} );
