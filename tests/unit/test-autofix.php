<?php
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
} );

test( 'autofix: rounds of page visits', function () {
	eq( 'judge', AITP_Autofix_Logic::after_pass( 1, 0, 200 ), 'everything translated' );
	eq( 'again', AITP_Autofix_Logic::after_pass( 1, 30, 200 ), 'some left after the first round' );
	eq( 'again', AITP_Autofix_Logic::after_pass( 2, 10, 30 ), 'progress in round 2' );
	eq( 'judge', AITP_Autofix_Logic::after_pass( 2, 30, 30 ), 'no progress in round 2' );
	eq( 'judge', AITP_Autofix_Logic::after_pass( 3, 5, 10 ), 'at most 3 rounds' );
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
