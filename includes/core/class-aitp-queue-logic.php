<?php
/**
 * Pure rules of the backfill queue (no WordPress): what to do with a page after a visit,
 * signing of internal visits, and the character budget.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Queue_Logic {

	/** Visits in a row that may still produce new translations before a page is set aside. */
	const MAX_VISITS = 6;

	/** Failed HTTP visits before a page is marked failed. */
	const MAX_HTTP_ATTEMPTS = 3;

	/** Seconds a signed visit stays valid. */
	const TOKEN_TTL = 300;

	/** Provider-level stop reasons: the whole queue waits, the page is not at fault. */
	const SYSTEMIC = array( 'breaker', 'quota', 'quota_daily', 'rate_limit', 'auth', 'billing', 'not_found', 'bad_request', 'misaligned', 'timeout', 'network', 'server' );

	/**
	 * Decide the next state of a queue item after one visit.
	 *
	 * @param array $item  Current item: visits, attempts, passes.
	 * @param array $visit Visit outcome:
	 *                     http       int    HTTP status (0 = no response)
	 *                     report     bool   whether the page reported back (signed visit processed)
	 *                     calls      int    engine calls during the visit
	 *                     translated int    strings translated
	 *                     failed     int    strings not translated (any reason)
	 *                     stop       string first stop reason reported by the engine ('' if none)
	 * @param int   $now   Unix time.
	 * @return array{status:string,next_at:int,note:string,visits:int,attempts:int,passes:int,queue_wait:int}
	 */
	public static function decide( array $item, array $visit, $now ) {
		$visits   = (int) ( isset( $item['visits'] ) ? $item['visits'] : 0 );
		$attempts = (int) ( isset( $item['attempts'] ) ? $item['attempts'] : 0 );
		$passes   = (int) ( isset( $item['passes'] ) ? $item['passes'] : 0 );
		$http     = (int) ( isset( $visit['http'] ) ? $visit['http'] : 0 );
		$out      = array(
			'status'     => 'pending',
			'next_at'    => $now,
			'note'       => '',
			'visits'     => $visits,
			'attempts'   => $attempts,
			'passes'     => $passes,
			'queue_wait' => 0,
		);

		// The page did not load, or loaded without our signed visit being processed.
		if ( $http < 200 || $http >= 300 || empty( $visit['report'] ) ) {
			$out['attempts'] = $attempts + 1;
			if ( $http >= 300 && $http < 400 ) {
				$out['note'] = 'redirect';
			} elseif ( $http >= 200 && $http < 300 && ! empty( $visit['sig'] ) ) {
				$out['note'] = 'signature'; // The page refused the signed visit; nothing was translated.
			} elseif ( $http >= 200 && $http < 300 ) {
				$out['note'] = 'no_report'; // Usually a page cache answered instead of WordPress.
			} else {
				$out['note'] = $http ? 'http_' . $http : 'unreachable';
			}
			if ( $out['attempts'] >= self::MAX_HTTP_ATTEMPTS || $out['note'] === 'redirect' ) {
				$out['status']  = 'failed';
				$out['next_at'] = $now + 6 * 3600;
			} else {
				$out['next_at'] = $now + 300 * $out['attempts'];
			}
			return $out;
		}
		$out['attempts'] = 0;

		$stop       = (string) ( isset( $visit['stop'] ) ? $visit['stop'] : '' );
		$calls      = (int) ( isset( $visit['calls'] ) ? $visit['calls'] : 0 );
		$translated = (int) ( isset( $visit['translated'] ) ? $visit['translated'] : 0 );

		if ( in_array( $stop, self::SYSTEMIC, true ) ) {
			// Provider trouble: keep the page as it is and let the whole queue wait.
			$out['note']       = $stop;
			$out['queue_wait'] = in_array( $stop, array( 'auth', 'billing', 'not_found', 'bad_request' ), true ) ? 1800 : 300;
			$out['next_at']    = $now + $out['queue_wait'];
			return $out;
		}

		if ( $calls === 0 ) {
			$out['status'] = 'done';
			$out['visits'] = 0;
			$out['passes'] = 0;
			$out['next_at'] = 0;
			return $out;
		}

		$out['visits'] = $visits + 1;
		if ( $translated > 0 || $stop === 'deferred' ) {
			// Progress (or time ran out): come back right away, unless this keeps going on.
			if ( $out['visits'] >= self::MAX_VISITS ) {
				$out['status']  = 'partial';
				$out['note']    = 'max_visits';
				$out['passes']  = $passes + 1;
				$out['visits']  = 0;
				$out['next_at'] = $now + self::partial_delay( $out['passes'] );
			}
			return $out;
		}

		// The engine was called but nothing could be translated (strings failing or waiting).
		$out['status']  = 'partial';
		$out['note']    = 'strings_failing';
		$out['passes']  = $passes + 1;
		$out['visits']  = 0;
		$out['next_at'] = $now + self::partial_delay( $out['passes'] );
		return $out;
	}

	/**
	 * Wait before revisiting a partially translated page: 1 h, 3 h, 12 h, then daily.
	 *
	 * @param int $passes Partial passes so far.
	 * @return int
	 */
	public static function partial_delay( $passes ) {
		$table = array( 1 => 3600, 2 => 3 * 3600, 3 => 12 * 3600 );
		return isset( $table[ $passes ] ) ? $table[ $passes ] : 24 * 3600;
	}

	/**
	 * One spelling of a request path (with query), so the signing side and the receiving
	 * side agree. HTTP libraries rewrite equivalent forms before sending (WordPress' Requests
	 * library writes %e3 as %E3, %7e as ~ and percent-encodes raw non-ASCII text), and the site
	 * may receive either form. Normalisation as in RFC 3986 6.2.2: percent-encodings in
	 * upper case, encoded unreserved characters (A-Z a-z 0-9 - . _ ~) decoded, bytes that may
	 * not appear raw encoded, a "%" without two hex digits encoded as %25. Reserved encodings
	 * (%2F, %3F, %25, %26 …), the case of the path and the order of the query stay as they are.
	 *
	 * @param string $path Path with query.
	 * @return string
	 */
	public static function canonical_path( $path ) {
		$path = preg_replace_callback(
			'~%(?![0-9A-Fa-f]{2})|[^\x21-\x7E]|["<>\\\\^`{|}]~',
			function ( $m ) {
				return '%' . strtoupper( bin2hex( $m[0] ) );
			},
			(string) $path
		);
		return preg_replace_callback(
			'~%([0-9A-Fa-f]{2})~',
			function ( $m ) {
				$c = chr( hexdec( $m[1] ) );
				return preg_match( '~^[A-Za-z0-9._\~-]$~', $c ) ? $c : '%' . strtoupper( $m[1] );
			},
			$path
		);
	}

	/**
	 * Signature header value for an internal visit of $path.
	 *
	 * @param string $secret Site secret.
	 * @param string $path   Request path (with query).
	 * @param int    $ts     Unix time.
	 * @param string $nonce  Random token (hex).
	 * @return string
	 */
	public static function sign( $secret, $path, $ts, $nonce ) {
		return $ts . '.' . $nonce . '.' . hash_hmac( 'sha256', $ts . '|' . $nonce . '|' . self::canonical_path( $path ), $secret );
	}

	/**
	 * Verify a signature header. Returns the nonce, or '' when invalid or expired.
	 *
	 * @param string $secret Site secret.
	 * @param string $header Header value.
	 * @param string $path   Request path (with query).
	 * @param int    $now    Unix time.
	 * @return string
	 */
	public static function verify( $secret, $header, $path, $now ) {
		return self::check( $secret, $header, $path, $now )['nonce'];
	}

	/**
	 * Verify a signature header and say why it failed.
	 *
	 * @param string $secret Site secret.
	 * @param string $header Header value.
	 * @param string $path   Request path (with query), as received.
	 * @param int    $now    Unix time.
	 * @return array{nonce:string,reason:string} reason: '' | format | expired | signature.
	 */
	public static function check( $secret, $header, $path, $now ) {
		if ( ! is_string( $header ) || ! preg_match( '~^(\d{9,11})\.([a-f0-9]{16,64})\.([a-f0-9]{64})$~', $header, $m ) ) {
			return array( 'nonce' => '', 'reason' => 'format' );
		}
		$ts = (int) $m[1];
		if ( abs( $now - $ts ) > self::TOKEN_TTL ) {
			return array( 'nonce' => '', 'reason' => 'expired' );
		}
		$expected = hash_hmac( 'sha256', $ts . '|' . $m[2] . '|' . self::canonical_path( $path ), $secret );
		return hash_equals( $expected, $m[3] ) ? array( 'nonce' => $m[2], 'reason' => '' ) : array( 'nonce' => '', 'reason' => 'signature' );
	}

	/**
	 * Whether the queue may spend more characters now.
	 *
	 * @param int   $queue_today Characters the queue sent today.
	 * @param int   $queue_cap   Queue's own daily cap (0 = no own cap).
	 * @param int   $tp_counter  TranslatePress counter today (all sources).
	 * @param int   $tp_limit    TranslatePress daily limit.
	 * @param float $reserve     Share of the TranslatePress limit kept for visitors.
	 * @return string '' when allowed, otherwise the reason.
	 */
	public static function budget_block( $queue_today, $queue_cap, $tp_counter, $tp_limit, $reserve ) {
		if ( $queue_cap > 0 && $queue_today >= $queue_cap ) {
			return 'queue_cap';
		}
		if ( $tp_limit > 0 && $tp_counter >= (int) floor( $tp_limit * ( 1 - $reserve ) ) ) {
			return 'tp_limit';
		}
		return '';
	}
}
