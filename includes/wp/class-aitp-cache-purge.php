<?php
/**
 * Clears cached copies of pages whose translations changed, for the page cache plugins
 * that offer a per-address purge. CDN caches outside WordPress are not reached; the
 * admin pages remind to clear them.
 *
 * A few addresses are cleared at once. Longer lists (a repair or rollback can touch
 * thousands of pages) go to a list that WP-Cron works through in small steps, so the
 * request that changed the translations is never held up by cache plugins. LiteSpeed
 * is asked not to add an admin notice per address.
 *
 * LiteSpeed puts one tag per cleared address into a response header of the request
 * that clears it, and in WP-Cron it also stores that header for the next request of
 * any visitor (0.6.3 F07: thousands of tags in one header, answered with HTTP 500 by
 * the host). So one request clears at most PER_REQUEST addresses through LiteSpeed,
 * and with LiteSpeed active a list of more than ALL_OVER addresses clears its whole
 * cache once instead (a header of a few bytes).
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Cache_Purge {

	const OPTION   = 'aitp_purge_list';
	const CRON     = 'aitp_purge_tick';
	const SYNC_MAX    = 5;     // Addresses cleared at once; more go to the list.
	const STEP        = 50;    // Addresses per step of the list.
	const MAX         = 20000; // Longest list; beyond it clearing the whole cache is the better way.
	const PER_REQUEST = 30;    // With LiteSpeed: addresses cleared in one HTTP request (one header tag each).
	const ALL_OVER    = 200;   // With LiteSpeed: more addresses than this clear its whole cache once.

	/** @var int Addresses cleared through LiteSpeed in this request. */
	private static $done = 0;

	/** Register the background step. */
	public static function boot() {
		add_action( self::CRON, array( __CLASS__, 'tick' ) );
	}

	/**
	 * @param string[] $urls Page addresses.
	 * @return array{now:int,later:int,waiting:int,dropped:int}
	 */
	public static function urls( array $urls ) {
		$urls = array_values( array_unique( array_filter( array_map( 'strval', $urls ) ) ) );
		$out  = array( 'now' => 0, 'later' => 0, 'waiting' => 0, 'dropped' => 0, 'all' => false );
		if ( ! $urls ) {
			return $out;
		}
		if ( count( $urls ) <= self::SYNC_MAX && self::room() >= count( $urls ) ) {
			self::purge_now( $urls );
			$out['now'] = count( $urls );
			return $out;
		}
		$s = self::state();
		if ( self::litespeed() && count( $urls ) + count( $s['urls'] ) > self::ALL_OVER ) {
			// Many pages: one "clear everything" for LiteSpeed covers the list and what is waiting.
			self::litespeed_all( count( $urls ) );
			$out['all'] = true;
			if ( ! self::others() ) {
				$out['now'] = count( $urls );
				self::save( array( 'urls' => array(), 'dropped' => 0, 'ls_done' => false ) );
				$out['waiting'] = 0;
				return $out;
			}
			$s['ls_done'] = true; // The list is left for the other caches only.
		} else {
			$s['ls_done'] = false;
		}
		$have = array_fill_keys( $s['urls'], true );
		foreach ( $urls as $u ) {
			if ( isset( $have[ $u ] ) ) {
				continue;
			}
			if ( count( $s['urls'] ) >= self::MAX ) {
				$out['dropped']++;
				continue;
			}
			$s['urls'][] = $u;
			$have[ $u ]  = true;
			$out['later']++;
		}
		$s['dropped'] += $out['dropped'];
		self::save( $s );
		$out['waiting'] = count( $s['urls'] );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + 5, self::CRON );
		}
		return $out;
	}

	/**
	 * Work through the list for a few seconds.
	 *
	 * @param int $seconds Time budget.
	 * @return int Addresses left.
	 */
	public static function tick( $seconds = 10 ) {
		$end = microtime( true ) + max( 1, (int) $seconds );
		do {
			$s = self::state();
			if ( ! $s['urls'] ) {
				self::save( $s ); // Clears a stale count of left-out addresses.
				break;
			}
			$n = empty( $s['ls_done'] ) ? min( self::STEP, self::room() ) : self::STEP;
			if ( $n < 1 ) {
				break; // This request has cleared as many as LiteSpeed takes; the next run goes on.
			}
			$chunk     = array_slice( $s['urls'], 0, $n );
			$s['urls'] = array_slice( $s['urls'], $n );
			self::save( $s ); // Taken off first: a failing cache plugin cannot stop the list.
			self::purge_now( $chunk, ! empty( $s['ls_done'] ) );
		} while ( microtime( true ) < $end );
		$left = count( self::state()['urls'] );
		if ( $left && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + 30, self::CRON );
		}
		return $left;
	}

	/** @return array{waiting:int,dropped:int} */
	public static function status() {
		$s = self::state();
		return array( 'waiting' => count( $s['urls'] ), 'dropped' => (int) $s['dropped'] );
	}

	/** Forget the list (the whole cache was cleared some other way). */
	public static function clear() {
		delete_option( self::OPTION );
		wp_clear_scheduled_hook( self::CRON );
	}

	/**
	 * @param string[] $urls    Page addresses.
	 * @param bool     $skip_ls LiteSpeed's whole cache was cleared for them already.
	 */
	private static function purge_now( array $urls, $skip_ls = false ) {
		$ls = ! $skip_ls && self::litespeed();
		foreach ( $urls as $url ) {
			try {
				if ( $ls ) {
					++self::$done;
				}
				if ( ! $ls ) {
					// LiteSpeed absent, or already cleared as a whole.
				} elseif ( class_exists( '\LiteSpeed\Purge' ) && is_callable( array( '\LiteSpeed\Purge', 'cls' ) ) && method_exists( '\LiteSpeed\Purge', 'purge_url' ) ) {
					\LiteSpeed\Purge::cls()->purge_url( $url, false, true ); // Third argument: no admin notice.
				} else {
					do_action( 'litespeed_purge_url', $url ); // LiteSpeed Cache (older versions).
				}
				if ( function_exists( 'w3tc_flush_url' ) ) {
					w3tc_flush_url( $url ); // W3 Total Cache.
				}
				if ( function_exists( 'wpsc_delete_url_cache' ) ) {
					wpsc_delete_url_cache( $url ); // WP Super Cache.
				}
			} catch ( \Throwable $e ) {
				self::failed( $e );
			}
		}
		try {
			if ( function_exists( 'rocket_clean_files' ) ) {
				rocket_clean_files( $urls ); // WP Rocket.
			}
			/**
			 * Pages whose translations changed. Hook here to purge other caches (CDN APIs).
			 * Long lists arrive in several calls of at most 50 addresses.
			 *
			 * @param string[] $urls Page addresses.
			 */
			do_action( 'aitp_purge_urls', $urls );
		} catch ( \Throwable $e ) {
			self::failed( $e );
		}
	}

	/** @return int Addresses this request may still clear (LiteSpeed: PER_REQUEST in all; others: no limit). */
	private static function room() {
		return self::litespeed() ? max( 0, self::PER_REQUEST - self::$done ) : PHP_INT_MAX;
	}

	/** @return bool LiteSpeed Cache is active. */
	private static function litespeed() {
		return class_exists( '\LiteSpeed\Purge' ) || has_action( 'litespeed_purge_url' );
	}

	/** @return bool A cache other than LiteSpeed takes addresses. */
	private static function others() {
		return function_exists( 'w3tc_flush_url' ) || function_exists( 'wpsc_delete_url_cache' ) || function_exists( 'rocket_clean_files' ) || has_action( 'aitp_purge_urls' );
	}

	/**
	 * Clear the whole LiteSpeed page cache once (LSCache only: CSS/JS, object cache and OPcache are left alone).
	 *
	 * @param int $n Addresses it stands for (logged).
	 */
	private static function litespeed_all( $n ) {
		try {
			if ( class_exists( '\LiteSpeed\Purge' ) && is_callable( array( '\LiteSpeed\Purge', 'purge_all_lscache' ) ) ) {
				\LiteSpeed\Purge::purge_all_lscache( 'AI Translator: translations of many pages changed' );
			} else {
				do_action( 'litespeed_purge_all' ); // Older versions.
			}
			( new AITP_WP_Log() )->log( 'notice', 'cache_purge_all', array( 'addresses' => (int) $n ) );
		} catch ( \Throwable $e ) {
			self::failed( $e );
		}
	}

	/** @param \Throwable $e Error of a cache plugin. */
	private static function failed( $e ) {
		( new AITP_WP_Log() )->log( 'warning', 'cache_purge', array( 'error' => substr( get_class( $e ) . ': ' . $e->getMessage(), 0, 300 ) ) );
	}

	/** @return array{urls:string[],dropped:int,ls_done:bool} */
	private static function state() {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'urls'    => isset( $s['urls'] ) && is_array( $s['urls'] ) ? array_values( $s['urls'] ) : array(),
			'dropped' => isset( $s['dropped'] ) ? (int) $s['dropped'] : 0,
			'ls_done' => ! empty( $s['ls_done'] ),
		);
	}

	/** @param array $s State. */
	private static function save( array $s ) {
		if ( ! $s['urls'] ) {
			delete_option( self::OPTION ); // Done: the count of addresses left out goes with it.
			return;
		}
		update_option( self::OPTION, $s, false );
	}
}
