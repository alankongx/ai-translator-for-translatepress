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
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Cache_Purge {

	const OPTION   = 'aitp_purge_list';
	const CRON     = 'aitp_purge_tick';
	const SYNC_MAX = 5;     // Addresses cleared at once; more go to the list.
	const STEP     = 50;    // Addresses per step of the list.
	const MAX      = 20000; // Longest list; beyond it clearing the whole cache is the better way.

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
		$out  = array( 'now' => 0, 'later' => 0, 'waiting' => 0, 'dropped' => 0 );
		if ( ! $urls ) {
			return $out;
		}
		if ( count( $urls ) <= self::SYNC_MAX ) {
			self::purge_now( $urls );
			$out['now'] = count( $urls );
			return $out;
		}
		$s    = self::state();
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
			$chunk     = array_slice( $s['urls'], 0, self::STEP );
			$s['urls'] = array_slice( $s['urls'], self::STEP );
			self::save( $s ); // Taken off first: a failing cache plugin cannot stop the list.
			self::purge_now( $chunk );
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
	 * @param string[] $urls Page addresses.
	 */
	private static function purge_now( array $urls ) {
		foreach ( $urls as $url ) {
			try {
				if ( class_exists( '\LiteSpeed\Purge' ) && is_callable( array( '\LiteSpeed\Purge', 'cls' ) ) && method_exists( '\LiteSpeed\Purge', 'purge_url' ) ) {
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

	/** @param \Throwable $e Error of a cache plugin. */
	private static function failed( $e ) {
		( new AITP_WP_Log() )->log( 'warning', 'cache_purge', array( 'error' => substr( get_class( $e ) . ': ' . $e->getMessage(), 0, 300 ) ) );
	}

	/** @return array{urls:string[],dropped:int} */
	private static function state() {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'urls'    => isset( $s['urls'] ) && is_array( $s['urls'] ) ? array_values( $s['urls'] ) : array(),
			'dropped' => isset( $s['dropped'] ) ? (int) $s['dropped'] : 0,
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
