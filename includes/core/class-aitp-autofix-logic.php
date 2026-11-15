<?php
/**
 * Decisions of the batch auto repair (pure logic, no WordPress).
 *
 * One batch: back up and reset up to N wrong rows of one language, visit the pages that
 * show them until they are translated again (rounds go on while they make progress, see
 * after_pass()), check the new translations, and go on only when the batch came out clean
 * enough.
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

	/**
	 * Rounds of page visits per batch: more rounds while they make progress (a long article
	 * is translated a part per visit). It stops after MAX_STALLS rounds in a row without
	 * progress, MAX_PASSES rounds, or MAX_SECONDS of page visits. PASS_GAP: pause between
	 * rounds (TranslatePress locks fresh strings briefly).
	 */
	const MAX_PASSES  = 10;
	const MAX_STALLS  = 2;
	const MAX_SECONDS = 1800;
	const PASS_GAP    = 60;

	/** Default batches per start (try one batch, look, then go on). */
	const DEFAULT_MAX_BATCHES = 1;

	/** Pages read to find where wrong rows are shown, when most of them are on no known page. */
	const SAMPLE_PAGES    = 24;
	const SAMPLE_PER_TYPE = 2;

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
			'max_batches' => self::DEFAULT_MAX_BATCHES, // Batches per start (0: no limit).
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
	 * Rounds in a row without progress, after one more round.
	 *
	 * @param int $stalls       Rounds in a row without progress before this one.
	 * @param int $untranslated Rows still untranslated.
	 * @param int $before       Rows untranslated before this round.
	 * @return int
	 */
	public static function stalls( $stalls, $untranslated, $before ) {
		return $untranslated >= $before ? (int) $stalls + 1 : 0;
	}

	/**
	 * After a round of page visits: another round, or judge the batch.
	 *
	 * @param int $pass         Rounds done.
	 * @param int $untranslated Rows still untranslated (refused or not reached yet).
	 * @param int $before       Rows untranslated before this round.
	 * @param int $stalls       Rounds in a row without progress before this one.
	 * @param int $seconds      Seconds of page visits so far in this batch.
	 * @return string again|judge
	 */
	public static function after_pass( $pass, $untranslated, $before, $stalls = 0, $seconds = 0 ) {
		if ( $untranslated <= 0 || $pass >= self::MAX_PASSES || $seconds >= self::MAX_SECONDS ) {
			return 'judge';
		}
		if ( self::stalls( $stalls, $untranslated, $before ) >= self::MAX_STALLS ) {
			return 'judge'; // Rounds without progress: more visits will not help.
		}
		return 'again';
	}

	/**
	 * Judge a finished batch.
	 *
	 * Only rows the engine refused, and new translations that fail the checks, count as
	 * failures. Rows not translated without a refusal on record ("left": no visit asked for
	 * them, e.g. not shown on the pages visited) are counted apart; when they are more than
	 * half of the batch, a person should look (the pages are probably not the right ones).
	 *
	 * @param array $c reset, ok, wrong, english, refused, left, manual, gone (or untranslated
	 *                 instead of refused and left, counted as refused).
	 * @return array{result:string,rate:float,failed:int,base:int,left:int} result: ok |
	 *         english_saved | too_many_failures | many_left.
	 */
	public static function judge( array $c ) {
		$n       = function ( $k ) use ( $c ) {
			return isset( $c[ $k ] ) ? max( 0, (int) $c[ $k ] ) : 0;
		};
		$refused = isset( $c['refused'] ) ? $n( 'refused' ) : $n( 'untranslated' );
		$left    = isset( $c['refused'] ) ? $n( 'left' ) : 0;
		$known   = max( 0, $n( 'reset' ) - $n( 'manual' ) - $n( 'gone' ) );
		$base    = max( 0, $known - $left );
		$fail    = $n( 'wrong' ) + $refused;
		$rate    = $base > 0 ? $fail / $base : 0.0;
		if ( $n( 'english' ) > 0 ) {
			$result = 'english_saved'; // English stored as a translation: the very error this plugin exists to stop.
		} elseif ( $rate > self::THRESHOLD ) {
			$result = 'too_many_failures';
		} elseif ( $known > 0 && $left * 2 > $known ) {
			$result = 'many_left';
		} else {
			$result = 'ok';
		}
		return array( 'result' => $result, 'rate' => round( $rate, 4 ), 'failed' => $fail, 'base' => $base, 'left' => $left );
	}

	/**
	 * Sample of pages to read when most wrong rows of a language are on no known page: the
	 * home page and a few pages of each kind (post type, taxonomy), in the queue's order.
	 *
	 * @param array $items Each with 'group' (home, pt:page, tax:category, ...), in order.
	 * @param int   $per   Pages per group (the home page is one).
	 * @param int   $max   Pages in all.
	 * @return array The chosen items, in order.
	 */
	public static function sample_pages( array $items, $per = self::SAMPLE_PER_TYPE, $max = self::SAMPLE_PAGES ) {
		$out  = array();
		$took = array();
		// Home first, then one page of each kind before a second one of any kind.
		$rounds = array( 1, max( 1, (int) $per ) );
		foreach ( $items as $it ) {
			if ( isset( $it['group'] ) && $it['group'] === 'home' && count( $out ) < $max ) {
				$out['home']  = $it;
				$took['home'] = 1;
				break;
			}
		}
		foreach ( $rounds as $limit ) {
			foreach ( $items as $i => $it ) {
				$g = isset( $it['group'] ) ? (string) $it['group'] : '';
				if ( $g === 'home' || count( $out ) >= $max ) {
					continue;
				}
				$have = isset( $took[ $g ] ) ? $took[ $g ] : 0;
				if ( $have >= $limit || isset( $out[ 'i' . $i ] ) ) {
					continue;
				}
				$out[ 'i' . $i ] = $it;
				$took[ $g ]      = $have + 1;
			}
		}
		return array_values( $out );
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
