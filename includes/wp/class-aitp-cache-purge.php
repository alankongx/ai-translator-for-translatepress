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
 * LiteSpeed sends a header stored by an earlier request at the start of the next one; a purge
 * of ours in that request would replace it (0.6.5). So a request that already carries such a
 * header clears nothing per address through LiteSpeed. Under WP-CLI LiteSpeed only stores the
 * header for later: the list waits for a web request. In WP-Cron the stored header is sent at
 * once with a request to the site (LiteSpeed's own "kick"), not by the next visitor.
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
		if ( ! self::litespeed() && ! self::others() ) {
			// No cache that takes addresses: nothing to keep a list for (hooks still hear about it).
			do_action( 'aitp_purge_urls', $urls ); // phpcs:ignore WordPress.NamingConventions.ValidHookName -- documented in purge_now().
			$out['now'] = count( $urls );
			return $out;
		}
		if ( count( $urls ) <= self::SYNC_MAX && self::room() >= count( self::spellings( $urls ) ) ) {
			self::purge_now( $urls );
			$out['now'] = count( $urls );
			return $out;
		}
		$s       = self::state();
		$waiting = AITP_Tasks::count( 'purge' );
		if ( self::litespeed() && ! self::cli() && count( $urls ) + $waiting > self::ALL_OVER ) {
			// Many pages: one "clear everything" for LiteSpeed covers the list and what is waiting.
			self::litespeed_all( count( $urls ) );
			$out['all'] = true;
			if ( ! self::others() ) {
				$out['now'] = count( $urls );
				AITP_Tasks::clear( 'purge' );
				self::save( array( 'dropped' => 0, 'ls_done' => false ) );
				return $out;
			}
			$s['ls_done'] = true; // The list is left for the other caches only.
		} elseif ( ! $waiting ) {
			$s['ls_done'] = false;
		}
		$room = max( 0, self::MAX - $waiting );
		$take = array_slice( $urls, 0, $room );
		$out['dropped'] = count( $urls ) - count( $take );
		$out['later']   = AITP_Tasks::add( 'purge', $take );
		$s['dropped']  += $out['dropped'];
		self::save( $s );
		$out['waiting'] = AITP_Tasks::count( 'purge' );
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
		$s   = self::state();
		$n   = AITP_Tasks::count( 'purge' );
		// A long list (for example left by WP-CLI): one "clear everything" for LiteSpeed. So is any
		// list in WP-Cron run by WP-CLI (a server cron job): there LiteSpeed stores one header for
		// the next visitor, which stays a few bytes for "everything" but grows with every address.
		$whole = self::cli() ? wp_doing_cron() : $n > self::ALL_OVER;
		if ( $n && empty( $s['ls_done'] ) && self::litespeed() && $whole ) {
			self::litespeed_all( $n );
			if ( ! self::others() ) {
				AITP_Tasks::clear( 'purge' );
				self::save( array( 'dropped' => 0, 'ls_done' => false ) );
				return 0;
			}
			$s['ls_done'] = true;
			self::save( $s );
		}
		do {
			$s    = self::state();
			$take = empty( $s['ls_done'] ) ? min( self::STEP, self::room() ) : self::STEP;
			if ( $take < 1 ) {
				break; // This request has cleared as many as LiteSpeed takes; the next run goes on.
			}
			$chunk = AITP_Tasks::first( 'purge', $take );
			if ( $chunk && empty( $s['ls_done'] ) && self::litespeed() ) {
				// Each spelling of an address is one LiteSpeed tag: keep the chunk within room().
				$fit = array();
				$w   = 0;
				foreach ( $chunk as $id => $u ) {
					$w += count( self::spellings( array( $u ) ) );
					if ( $w > $take ) {
						break;
					}
					$fit[ $id ] = $u;
				}
				if ( ! $fit ) {
					break; // Not enough room for one address in both spellings; the next run goes on.
				}
				$chunk = $fit;
			}
			if ( ! $chunk ) {
				self::save( array( 'dropped' => 0, 'ls_done' => false ) ); // Clears a stale count of left-out addresses.
				break;
			}
			AITP_Tasks::remove( array_keys( $chunk ) ); // Taken off first: a failing cache plugin cannot stop the list.
			self::purge_now( array_values( $chunk ), ! empty( $s['ls_done'] ) );
		} while ( microtime( true ) < $end );
		$left = AITP_Tasks::count( 'purge' );
		if ( $left && ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_single_event( time() + 30, self::CRON );
		}
		return $left;
	}

	/** @return array{waiting:int,dropped:int} */
	public static function status() {
		$s = self::state();
		return array( 'waiting' => AITP_Tasks::count( 'purge' ), 'dropped' => (int) $s['dropped'] );
	}

	/** Forget the list (the whole cache was cleared some other way). */
	public static function clear() {
		AITP_Tasks::clear( 'purge' );
		delete_option( self::OPTION );
		wp_clear_scheduled_hook( self::CRON );
	}

	/**
	 * @param string[] $urls    Page addresses.
	 * @param bool     $skip_ls LiteSpeed's whole cache was cleared for them already.
	 */
	private static function purge_now( array $urls, $skip_ls = false ) {
		$ls = ! $skip_ls && self::litespeed();
		// Caches store the address in the spelling the browser sent (percent-encoded, upper case);
		// TranslatePress may give non-ASCII paths raw. Both spellings are cleared.
		$urls = self::spellings( $urls );
		foreach ( $urls as $url ) {
			try {
				if ( $ls ) {
					++self::$done;
					self::kick_later();
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
				self::purge_others( $url );
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

	/**
	 * Other caches with a per-address purge (calls checked against each plugin's source, 2026-11).
	 *
	 * @param string $url Page address.
	 */
	private static function purge_others( $url ) {
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache( $url ); // SiteGround Speed Optimizer.
		}
		if ( class_exists( 'Cache_Enabler' ) ) {
			do_action( 'cache_enabler_clear_page_cache_by_url', $url ); // Cache Enabler.
		}
		if ( isset( $GLOBALS['nginx_purger'] ) && is_object( $GLOBALS['nginx_purger'] ) && method_exists( $GLOBALS['nginx_purger'], 'purge_url' ) ) {
			$GLOBALS['nginx_purger']->purge_url( $url ); // Nginx Helper.
		}
		if ( class_exists( 'VarnishPurger' ) && is_callable( array( 'VarnishPurger', 'purge_url' ) ) ) {
			VarnishPurger::purge_url( $url ); // Proxy Cache Purge (Varnish).
		}
		if ( class_exists( 'WpFastestCache' ) ) {
			do_action( 'wpfc_clear_cache_by_url', $url ); // WP Fastest Cache.
		}
		if ( class_exists( 'WPO_Page_Cache' ) && is_callable( array( 'WPO_Page_Cache', 'delete_cache_by_url' ) ) ) {
			WPO_Page_Cache::delete_cache_by_url( $url ); // WP-Optimize.
		}
		if ( class_exists( '\Hummingbird\Core\Utils' ) && is_callable( array( '\Hummingbird\Core\Utils', 'get_module' ) ) ) {
			$path = (string) wp_parse_url( $url, PHP_URL_PATH );
			$hb   = \Hummingbird\Core\Utils::get_module( 'page_cache' );
			if ( $path !== '' && is_object( $hb ) && method_exists( $hb, 'clear_cache' ) ) {
				$hb->clear_cache( trailingslashit( $path ) ); // Hummingbird (an empty path would clear everything).
			}
		}
	}

	/**
	 * Page caches found on this site.
	 *
	 * @return array{per_url:string[],whole_only:string[]} per_url: cleared page by page by this
	 *         plugin; whole_only: found, but they only clear by post or as a whole (clear by hand).
	 */
	public static function detected() {
		$per = array();
		$all = array();
		$map = array(
			'LiteSpeed Cache'        => self::litespeed(),
			'W3 Total Cache'         => function_exists( 'w3tc_flush_url' ),
			'WP Super Cache'         => function_exists( 'wpsc_delete_url_cache' ),
			'WP Rocket'              => function_exists( 'rocket_clean_files' ),
			'SiteGround Optimizer'   => function_exists( 'sg_cachepress_purge_cache' ),
			'Cache Enabler'          => class_exists( 'Cache_Enabler' ),
			'Nginx Helper'           => isset( $GLOBALS['nginx_purger'] ) && is_object( $GLOBALS['nginx_purger'] ),
			'Proxy Cache Purge'      => class_exists( 'VarnishPurger' ),
			'WP Fastest Cache'       => class_exists( 'WpFastestCache' ),
			'WP-Optimize'            => class_exists( 'WPO_Page_Cache' ),
			'Hummingbird'            => class_exists( '\Hummingbird\Core\Utils' ),
		);
		foreach ( $map as $name => $on ) {
			if ( $on ) {
				$per[] = $name;
			}
		}
		$whole = array(
			'Breeze'     => defined( 'BREEZE_VERSION' ),
			'Cloudflare' => defined( 'CLOUDFLARE_PLUGIN_DIR' ),
			'Kinsta'     => defined( 'KINSTA_CACHE_ZONE' ) || class_exists( 'Kinsta\Cache' ),
			'WP Engine'  => class_exists( 'WpeCommon' ),
		);
		foreach ( $whole as $name => $on ) {
			if ( $on ) {
				$all[] = $name;
			}
		}
		return array( 'per_url' => $per, 'whole_only' => $all );
	}

	/**
	 * @param string $url Address.
	 * @return string The same address with its path in the browser's spelling (%XX upper case).
	 */
	public static function encoded( $url ) {
		$p = wp_parse_url( $url );
		if ( empty( $p['path'] ) ) {
			return $url;
		}
		$path = AITP_Queue_Logic::canonical_path( $p['path'] );
		return str_replace( $p['path'], $path, $url );
	}

	/**
	 * Addresses in every spelling a cache may have stored them under (raw and %XX upper case).
	 *
	 * @param string[] $urls Addresses.
	 * @return string[]
	 */
	private static function spellings( array $urls ) {
		$all = array();
		foreach ( $urls as $url ) {
			$all[] = $url;
			$alt   = self::encoded( $url );
			if ( $alt !== $url ) {
				$all[] = $alt;
			}
		}
		return array_values( array_unique( $all ) );
	}

	/** @return int Addresses this request may still clear (LiteSpeed: PER_REQUEST in all; others: no limit). */
	private static function room() {
		if ( ! self::litespeed() ) {
			return PHP_INT_MAX;
		}
		if ( self::cli() || ( self::$done === 0 && self::foreign_header() ) ) {
			return 0;
		}
		return max( 0, self::PER_REQUEST - self::$done );
	}

	/** @return bool Running under WP-CLI (LiteSpeed only stores its header there). */
	private static function cli() {
		return defined( 'LITESPEED_CLI' ) || ( defined( 'WP_CLI' ) && WP_CLI );
	}

	/** @return bool This request already carries a LiteSpeed purge header that is not ours. */
	private static function foreign_header() {
		if ( ! function_exists( 'headers_list' ) ) {
			return false;
		}
		foreach ( headers_list() as $h ) {
			if ( stripos( $h, 'x-litespeed-purge' ) === 0 ) {
				return true;
			}
		}
		return false;
	}

	/** In WP-Cron: have LiteSpeed send the header it stored at the end of this request. */
	private static function kick_later() {
		static $hooked = false;
		if ( $hooked || ! wp_doing_cron() || ! class_exists( '\LiteSpeed\Core' ) || ! is_callable( array( '\LiteSpeed\Core', 'kick_purge_queue' ) ) ) {
			return;
		}
		$hooked = true;
		add_action(
			'shutdown',
			function () {
				try {
					\LiteSpeed\Core::kick_purge_queue();
				} catch ( \Throwable $e ) {
					self::failed( $e );
				}
			},
			PHP_INT_MAX
		);
	}

	/** @return bool LiteSpeed Cache is active. */
	private static function litespeed() {
		return class_exists( '\LiteSpeed\Purge' ) || has_action( 'litespeed_purge_url' );
	}

	/** @return bool A cache other than LiteSpeed takes addresses. */
	private static function others() {
		$d = self::detected();
		return count( $d['per_url'] ) > ( self::litespeed() ? 1 : 0 ) || has_action( 'aitp_purge_urls' );
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
			self::kick_later();
			( new AITP_WP_Log() )->log( 'notice', 'cache_purge_all', array( 'addresses' => (int) $n ) );
		} catch ( \Throwable $e ) {
			self::failed( $e );
		}
	}

	/** @param \Throwable $e Error of a cache plugin. */
	private static function failed( $e ) {
		( new AITP_WP_Log() )->log( 'warning', 'cache_purge', array( 'error' => substr( get_class( $e ) . ': ' . $e->getMessage(), 0, 300 ) ) );
	}

	/** @return array{dropped:int,ls_done:bool} The list itself is in AITP_Tasks ('purge'). */
	private static function state() {
		$s = get_option( self::OPTION, array() );
		$s = is_array( $s ) ? $s : array();
		return array(
			'dropped' => isset( $s['dropped'] ) ? (int) $s['dropped'] : 0,
			'ls_done' => ! empty( $s['ls_done'] ),
		);
	}

	/** @param array $s State. */
	private static function save( array $s ) {
		if ( empty( $s['dropped'] ) && empty( $s['ls_done'] ) ) {
			delete_option( self::OPTION );
			return;
		}
		update_option( self::OPTION, array( 'dropped' => (int) $s['dropped'], 'ls_done' => ! empty( $s['ls_done'] ) ), false );
	}
}
