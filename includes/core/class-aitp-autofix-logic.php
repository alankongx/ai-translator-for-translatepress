<?php
/**
 * Decisions of the batch auto repair (pure logic, no WordPress).
 *
 * One batch: back up and reset up to N wrong rows of one language, visit the pages that
 * show them until they are translated again (at most MAX_PASSES rounds), check the new
 * translations, and go on only when the batch came out clean enough.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Autofix_Logic {

	/** Rows per batch: default, smallest, largest. */
	const DEFAULT_BATCH = 200;
	const MIN_BATCH     = 20;
	const MAX_BATCH     = 500;

	/** Share of a batch that may still be wrong or untranslated afterwards. */
	const THRESHOLD = 0.05;

	/** Rounds of page visits per batch, and the pause between rounds (TranslatePress locks fresh strings briefly). */
	const MAX_PASSES = 3;
	const PASS_GAP   = 60;

	/** Failed page loads in one batch before the auto repair stops. */
	const MAX_VISIT_FAILURES = 3;

	/** Engine stop reasons that only mean "no budget left today". */
	const BUDGET_STOPS = array( 'quota', 'quota_daily' );

	/** Engine stop reasons that mean the provider is in trouble. */
	const PROVIDER_STOPS = array( 'breaker', 'rate_limit', 'auth', 'billing', 'not_found', 'bad_request', 'misaligned', 'timeout', 'network', 'server' );

	/**
	 * Daily character cap of the auto repair when none is set: half the TranslatePress limit.
	 *
	 * @param int $tp_limit TranslatePress daily limit (0 = none).
	 * @return int
	 */
	public static function default_cap( $tp_limit ) {
		return $tp_limit > 0 ? (int) floor( $tp_limit / 2 ) : 500000;
	}

	/**
	 * @param array    $in       Raw input.
	 * @param string[] $langs    Known languages.
	 * @param int      $tp_limit TranslatePress daily limit.
	 * @return array{enabled:bool,languages:string[],batch:int,cap:int,max_batches:int}
	 */
	public static function clean_settings( array $in, array $langs, $tp_limit ) {
		$out = array(
			'enabled'     => ! empty( $in['enabled'] ),
			'languages'   => array(),
			'batch'       => self::DEFAULT_BATCH,
			'cap'         => self::default_cap( $tp_limit ),
			'max_batches' => 0, // Batches per start (0: no limit).
		);
		foreach ( isset( $in['languages'] ) ? (array) $in['languages'] : array() as $l ) {
			if ( in_array( (string) $l, $langs, true ) && ! in_array( (string) $l, $out['languages'], true ) ) {
				$out['languages'][] = (string) $l;
			}
		}
		if ( isset( $in['batch'] ) && is_numeric( $in['batch'] ) ) {
			$out['batch'] = max( self::MIN_BATCH, min( self::MAX_BATCH, (int) $in['batch'] ) );
		}
		if ( isset( $in['cap'] ) && is_numeric( $in['cap'] ) && (int) $in['cap'] > 0 ) {
			$out['cap'] = min( 100000000, (int) $in['cap'] );
		}
		if ( isset( $in['max_batches'] ) && is_numeric( $in['max_batches'] ) ) {
			$out['max_batches'] = max( 0, min( 1000, (int) $in['max_batches'] ) );
		}
		return $out;
	}

	/**
	 * After a round of page visits: another round, or judge the batch.
	 *
	 * @param int $pass         Rounds done.
	 * @param int $untranslated Rows still untranslated.
	 * @param int $before       Rows untranslated before this round.
	 * @return string again|judge
	 */
	public static function after_pass( $pass, $untranslated, $before ) {
		if ( $untranslated <= 0 || $pass >= self::MAX_PASSES ) {
			return 'judge';
		}
		if ( $pass >= 2 && $untranslated >= $before ) {
			return 'judge'; // A round without progress: more visits will not help.
		}
		return 'again';
	}

	/**
	 * Judge a finished batch.
	 *
	 * @param array $c reset, ok, wrong, english, untranslated, manual, gone.
	 * @return array{result:string,rate:float,failed:int,base:int} result: ok | english_saved | too_many_failures.
	 */
	public static function judge( array $c ) {
		$n    = function ( $k ) use ( $c ) {
			return isset( $c[ $k ] ) ? max( 0, (int) $c[ $k ] ) : 0;
		};
		$base = max( 0, $n( 'reset' ) - $n( 'manual' ) - $n( 'gone' ) );
		$fail = $n( 'wrong' ) + $n( 'untranslated' );
		$rate = $base > 0 ? $fail / $base : 0.0;
		if ( $n( 'english' ) > 0 ) {
			$result = 'english_saved'; // English stored as a translation: the very error this plugin exists to stop.
		} elseif ( $rate > self::THRESHOLD ) {
			$result = 'too_many_failures';
		} else {
			$result = 'ok';
		}
		return array( 'result' => $result, 'rate' => round( $rate, 4 ), 'failed' => $fail, 'base' => $base );
	}

	/**
	 * What an engine stop reason seen during a page visit means for the auto repair.
	 *
	 * @param string $stop Stop reason.
	 * @return string '' (carry on), budget (wait for tomorrow) or provider (stop).
	 */
	public static function visit_stop( $stop ) {
		if ( in_array( $stop, self::BUDGET_STOPS, true ) ) {
			return 'budget';
		}
		if ( in_array( $stop, self::PROVIDER_STOPS, true ) ) {
			return 'provider';
		}
		return '';
	}

	/**
	 * Codes that mean English (or other source text) was stored as the translation.
	 *
	 * @param string[] $codes Problem codes of a row.
	 * @return bool
	 */
	public static function is_english( array $codes ) {
		return (bool) array_intersect( $codes, array( 'untranslated', 'english_residue', 'empty' ) );
	}
}
