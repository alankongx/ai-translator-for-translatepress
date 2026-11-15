<?php
/**
 * Batch auto repair (off by default).
 *
 * Works through the "wrong" findings of the check one batch at a time:
 *   0. when most wrong rows of a language are on no known page, read a few sample pages of
 *      it first (read only), once per language and run;
 *   1. back up and reset up to N rows of one language (AITP_Audit::repair, same as by hand);
 *   2. visit only the pages that show them, round after round while they make progress;
 *   3. check the new translations with the audit rules;
 *   4. go on with the next batch only when the batch came out clean enough.
 * A batch reset by a request that ended before the auto repair took it on is taken on by the
 * next run (never left behind unjudged). The whole page cache is cleared once at the end of
 * a run when some reset row shows on very many pages (menus, footers).
 * It stops by itself when more than 5% of a batch is wrong or refused by the engine, when
 * English is stored as a translation, on provider errors, failed page loads, when
 * TranslatePress no longer uses this engine or automatic translation is off, when a batch
 * is rolled back, or when a person stops it. It never touches manual translations, rows on
 * the accepted list, "probably misplaced" or "check meaning" findings, structured data or
 * interface text. Every batch can be rolled back like a manual repair.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Autofix {

	const SETTINGS = 'aitp_autofix_settings';
	const STATE    = 'aitp_autofix_state';
	const LOCK     = 'aitp_autofix_lock';
	const CRON     = 'aitp_autofix_tick';
	const HISTORY  = 50;

	/** Hooks. */
	public static function boot() {
		add_action( self::CRON, array( __CLASS__, 'tick' ) );
	}

	/* -------------------------------------------------------------------------------------
	 * Settings and state.
	 * ----------------------------------------------------------------------------------- */

	/** @return array{enabled:bool,languages:string[],batch:int,cap:int} */
	public static function settings() {
		$s = get_option( self::SETTINGS, array() );
		return AITP_Autofix_Logic::clean_settings( is_array( $s ) ? $s : array(), AITP_Queue::tp_languages(), AITP_TP_Quota::limit() );
	}

	/**
	 * @param array $in Raw input.
	 * @return array Clean settings (saved).
	 */
	public static function save_settings( array $in ) {
		$out = AITP_Autofix_Logic::clean_settings( $in, AITP_Queue::tp_languages(), AITP_TP_Quota::limit() );
		update_option( self::SETTINGS, $out, false );
		if ( ! $out['enabled'] ) {
			self::stop( 'disabled' );
		}
		return $out;
	}

	/** @return array */
	public static function state() {
		wp_cache_delete( self::STATE, 'options' );
		$s = get_option( self::STATE, array() );
		$s = array_merge(
			array(
				'status'      => 'idle', // idle | running | stopped | done.
				'reason'      => '',
				'started'     => 0,
				'ended'       => 0,
				'wait_until'  => 0,
				'wait_reason' => '',
				'day'         => '',
				'day_chars'   => 0,
				'batch'       => null,
				'batches'     => 0,
				'run_batches' => 0, // Batches finished since the last start.
				'rows_ok'     => 0,
				'left'        => array( 'structured' => 0, 'no_page' => 0 ),
				'history'     => array(),
				'mapping'     => null,    // Sample pages being read: lang, items, i, next_at, ok.
				'mapped'      => array(), // Languages sampled in this run.
				'run_wide'    => false,   // A batch of this run had rows on very many pages: clear the whole cache at the end.
			),
			is_array( $s ) ? $s : array()
		);
		if ( $s['day'] !== gmdate( 'Y-m-d' ) ) {
			$s['day']       = gmdate( 'Y-m-d' );
			$s['day_chars'] = 0;
			if ( in_array( $s['wait_reason'], array( 'cap', 'tp_limit', 'quota' ), true ) ) {
				$s['wait_until']  = 0;
				$s['wait_reason'] = '';
			}
		}
		return $s;
	}

	/**
	 * @param array $s     State.
	 * @param bool  $force Write the status as given (start); otherwise a stop saved meanwhile
	 *                     (a person, or another request) is kept.
	 */
	private static function save( array $s, $force = false ) {
		if ( ! $force && $s['status'] === 'running' ) {
			wp_cache_delete( self::STATE, 'options' );
			$cur = get_option( self::STATE, array() );
			if ( is_array( $cur ) && isset( $cur['status'] ) && $cur['status'] !== 'running' ) {
				foreach ( array( 'status', 'reason', 'ended' ) as $k ) {
					if ( isset( $cur[ $k ] ) ) {
						$s[ $k ] = $cur[ $k ];
					}
				}
			}
		}
		update_option( self::STATE, $s, false );
	}

	/**
	 * Why the auto repair may not run now ('' = it may).
	 *
	 * @return string
	 */
	public static function blocker() {
		if ( ! self::settings()['enabled'] ) {
			return 'disabled';
		}
		if ( ! AITP_Plugin::engine_selected() ) {
			return 'engine_not_selected';
		}
		if ( ! AITP_Plugin::automatic_translation_on() ) {
			return 'automatic_off';
		}
		if ( ! AITP_Settings::provider() ) {
			return 'not_configured';
		}
		return '';
	}

	/**
	 * Start (or resume) the auto repair.
	 *
	 * @return string '' when started, otherwise the reason it cannot start.
	 */
	public static function start() {
		$why = self::blocker();
		if ( $why !== '' ) {
			return $why;
		}
		$audit = AITP_Audit::state();
		if ( $audit['status'] === 'running' ) {
			return 'scan_running';
		}
		if ( $audit['status'] !== 'done' ) {
			return 'no_scan';
		}
		$s                = self::state();
		$s['status']      = 'running';
		$s['reason']      = '';
		$s['started']     = $s['batch'] ? $s['started'] : time();
		$s['ended']       = 0;
		$s['wait_until']  = 0;
		$s['wait_reason'] = '';
		$s['run_batches'] = 0;
		$s['mapped']      = array();
		$s['mapping']     = null;
		self::save( $s, true );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 5, AITP_Queue::SCHEDULE, self::CRON );
		}
		( new AITP_WP_Log() )->log( 'notice', 'autofix_start', array( 'settings' => self::settings() ) );
		return '';
	}

	/**
	 * Stop. A batch in progress stays as it is (its rows are reset and can be rolled back,
	 * or the queue and visitors translate them later).
	 *
	 * @param string $reason Why.
	 */
	public static function stop( $reason ) {
		$s = self::state();
		if ( $s['status'] === 'running' ) {
			$s['status']  = 'stopped';
			$s['reason']  = (string) $reason;
			$s['ended']   = time();
			$s['mapping'] = null;
			self::save( $s );
			( new AITP_WP_Log() )->log( $reason === 'manual' ? 'notice' : 'warning', 'autofix_stop', array( 'reason' => $reason, 'batch' => $s['batch'] ? $s['batch']['id'] : 0 ) );
		}
		self::end_run();
		wp_clear_scheduled_hook( self::CRON );
	}

	/** End of a run: clear the whole page cache once if a batch needed it. */
	private static function end_run() {
		$s = self::state();
		if ( ! empty( $s['run_wide'] ) ) {
			$s['run_wide'] = false;
			update_option( self::STATE, $s, false );
			AITP_Cache_Purge::site( 'autofix' );
		}
	}

	/** WP-Cron tick. */
	public static function tick() {
		if ( self::state()['status'] !== 'running' ) {
			wp_clear_scheduled_hook( self::CRON );
			return;
		}
		$limit = (int) ini_get( 'max_execution_time' );
		self::step( $limit > 0 ? max( 20, min( 55, $limit - 10 ) ) : 55 );
	}

	/* -------------------------------------------------------------------------------------
	 * Worker.
	 * ----------------------------------------------------------------------------------- */

	/**
	 * Advance the auto repair.
	 *
	 * @param int $seconds Wall time.
	 * @return array{result:string,wait:int,state:array} result: idle, busy, running, waiting, stopped, done.
	 */
	public static function step( $seconds ) {
		$s = self::state();
		if ( $s['status'] !== 'running' ) {
			return array( 'result' => 'idle', 'wait' => 0, 'state' => $s );
		}
		if ( ! self::lock( (int) $seconds + 120 ) ) {
			return array( 'result' => 'busy', 'wait' => 15, 'state' => $s );
		}
		$end    = time() + (int) $seconds;
		$result = 'running';
		$wait   = 0;
		$empty  = 0;
		$visits = 0;
		try {
			while ( time() < $end ) {
				$s   = self::state();
				$now = time();
				if ( $s['status'] !== 'running' ) {
					$result = $s['status'];
					break;
				}
				$why = self::blocker();
				if ( $why === '' ) {
					$b = AITP_Plugin::breaker()->state();
					if ( $b['status'] !== 'closed' && $now < (int) $b['until'] ) {
						$why = 'provider_breaker';
					}
				}
				if ( $why !== '' ) {
					self::stop( $why );
					$result = 'stopped';
					break;
				}
				if ( $now < (int) $s['wait_until'] ) {
					$result = 'waiting';
					$wait   = (int) $s['wait_until'] - $now;
					break;
				}

				if ( ! empty( $s['mapping'] ) ) {
					$m = $s['mapping'];
					if ( $now < (int) $m['next_at'] ) {
						if ( $now + ( (int) $m['next_at'] - $now ) >= $end ) {
							$result = 'rate';
							$wait   = (int) $m['next_at'] - $now;
							break;
						}
						sleep( (int) $m['next_at'] - $now );
						continue;
					}
					if ( $visits > 0 && time() + AITP_Queue::page_seconds() + 15 > $end ) {
						$result = 'time';
						break;
					}
					$visits++;
					self::map_next( $s );
					continue;
				}

				if ( ! $s['batch'] ) {
					$budget = self::budget_block( $s );
					if ( $budget !== '' ) {
						$wait   = self::wait_for_tomorrow( $s, $budget );
						$result = 'waiting';
						break;
					}
					$r = self::begin_batch( $s );
					if ( $r === 'done' || $r === 'stopped' ) {
						$result = $r;
						break;
					}
					if ( $r === 'busy' ) {
						$result = 'waiting'; // A repair, rollback or undo is running: next tick.
						$wait   = 60;
						break;
					}
					if ( $r === 'empty' && ++$empty >= 5 ) {
						break; // Picked rows had all changed; try again next tick.
					}
					continue;
				}

				$batch = $s['batch'];
				$info  = AITP_Batches::get( (int) $batch['id'] );
				if ( ! empty( $info['rolled_back'] ) ) {
					$s['batch'] = null;
					self::save( $s );
					self::stop( 'rolled_back' );
					$result = 'stopped';
					break;
				}
				if ( $now < (int) $batch['next_at'] ) {
					$wait = (int) $batch['next_at'] - $now;
					if ( $now + $wait >= $end ) {
						$result = 'rate';
						break;
					}
					sleep( $wait );
					continue;
				}
				if ( (int) $batch['ui'] < count( $batch['urls'] ) ) {
					$budget = self::budget_block( $s );
					if ( $budget !== '' ) {
						$wait   = self::wait_for_tomorrow( $s, $budget );
						$result = 'waiting';
						break;
					}
					if ( $visits > 0 && time() + AITP_Queue::page_seconds() + 15 > $end ) {
						$result = 'time';
						break;
					}
					$visits++;
					$r = self::visit_next( $s );
					if ( $r !== '' ) {
						$result = $r;
						break;
					}
					continue;
				}
				$r = self::end_pass( $s );
				if ( $r === 'batch_done' ) {
					$result = 'running'; // The next batch starts in a new request (next tick, or the page).
					break;
				}
				if ( $r !== '' ) {
					$result = $r;
					break;
				}
			}
		} finally {
			self::unlock();
		}
		return array( 'result' => $result, 'wait' => $wait, 'state' => self::state() );
	}

	/**
	 * @param array $s State.
	 * @return string '' or cap | tp_limit.
	 */
	private static function budget_block( array $s ) {
		if ( (int) $s['day_chars'] >= (int) self::settings()['cap'] ) {
			return 'cap';
		}
		// Keep the share of the TranslatePress limit that the queue keeps for visitors.
		return AITP_Queue_Logic::budget_block( 0, 0, AITP_Queue::tp_counter(), AITP_TP_Quota::limit(), (int) AITP_Queue::settings()['reserve'] / 100 );
	}

	/**
	 * Pause until tomorrow (UTC, like the TranslatePress counter).
	 *
	 * @param array  $s      State.
	 * @param string $reason Why.
	 * @return int Seconds.
	 */
	private static function wait_for_tomorrow( array $s, $reason ) {
		$until            = strtotime( 'tomorrow', time() ) + 120;
		$s['wait_until']  = $until;
		$s['wait_reason'] = $reason;
		self::save( $s );
		return $until - time();
	}

	/**
	 * Pick and reset the next batch.
	 *
	 * @param array $s State.
	 * @return string started | empty | done | stopped | busy.
	 */
	private static function begin_batch( array $s ) {
		$set = self::settings();
		// A batch reset earlier but never judged (its request ended first): take it on now.
		foreach ( AITP_Batches::auto_unjudged( $s['batch'] ? (int) $s['batch']['id'] : 0 ) as $id => $info ) {
			( new AITP_WP_Log() )->log( 'notice', 'autofix_adopt', array( 'batch' => (int) $id ) );
			return self::take_on( $s, (int) $id, $info ) ? 'started' : 'stopped';
		}
		if ( $set['max_batches'] > 0 && (int) $s['run_batches'] >= $set['max_batches'] ) {
			self::save( $s );
			self::stop( 'max_batches' );
			return 'stopped';
		}
		$pick      = AITP_Audit::autofix_pick( $set['languages'], $set['batch'] );
		$s['left'] = array( 'structured' => $pick['structured'], 'no_page' => $pick['no_page'] );
		// Most wrong rows of a language on no known page: read a few of its pages first.
		$todo = array_values( array_diff( $pick['unmapped'], (array) $s['mapped'] ) );
		if ( $todo ) {
			$lang          = $todo[0];
			$s['mapped'][] = $lang; // Once per language and run, whatever it finds.
			$items         = AITP_Pagemap::sample_plan( $lang );
			if ( $items ) {
				$s['mapping'] = array( 'lang' => $lang, 'items' => $items, 'i' => 0, 'ok' => 0, 'next_at' => 0 );
				self::save( $s );
				( new AITP_WP_Log() )->log( 'info', 'autofix_map', array( 'lang' => $lang, 'pages' => count( $items ) ) );
				return 'mapping';
			}
		}
		if ( ! $pick['ids'] ) {
			self::save( $s );
			if ( $pick['no_page'] > 0 ) {
				self::stop( 'no_page' ); // Not "finished": these rows are left, on no known page.
				return 'stopped';
			}
			$s           = self::state();
			$s['status'] = 'done';
			$s['reason'] = '';
			$s['ended']  = time();
			self::save( $s );
			wp_clear_scheduled_hook( self::CRON );
			self::end_run();
			( new AITP_WP_Log() )->log( 'notice', 'autofix_done', array( 'batches' => $s['batches'], 'rows_ok' => $s['rows_ok'], 'left' => $s['left'] ) );
			return 'done';
		}
		// Load one page of the language first (read only: nothing is translated or saved). When the
		// site cannot load its own pages now, stop before anything is reset.
		$check = self::visit_check( $pick['lang'] );
		if ( $check !== '' ) {
			self::save( $s );
			self::stop( $check );
			return 'stopped';
		}
		$r = AITP_Audit::repair( $pick['ids'], array( 'auto' => true, 'site_purge_later' => true ) );
		if ( ! empty( $r['busy'] ) ) {
			self::save( $s );
			return 'busy';
		}
		if ( ! $r['reset'] ) {
			self::save( $s );
			if ( ! empty( $r['blocked'] ) ) {
				self::stop( $r['blocked'] ); // Checked before anything was reset.
				return 'stopped';
			}
			if ( isset( $r['skipped']['backup_failed'] ) ) {
				self::stop( 'backup_failed' );
				return 'stopped';
			}
			if ( isset( $r['skipped']['language_paused'] ) ) {
				self::stop( 'language_paused' ); // Would pick the same rows again and again.
				return 'stopped';
			}
			return 'empty';
		}
		if ( ! empty( $r['wide'] ) ) {
			$s['run_wide'] = true;
		}
		return self::take_on( $s, (int) $r['batch'], array( 'lang' => $pick['lang'], 'reset' => (int) $r['reset'] ) ) ? 'started' : 'stopped';
	}

	/**
	 * Work on a batch that was reset: visit its pages, then judge it.
	 *
	 * @param array $s     State.
	 * @param int   $id    Batch id.
	 * @param array $info  Batch record (lang, reset, wide).
	 * @return bool False when it has no page to visit (then it was rolled back).
	 */
	private static function take_on( array $s, $id, array $info ) {
		$urls = AITP_Audit::batch_urls( $id );
		if ( ! $urls ) {
			// Should not happen (rows were picked because their pages are known): undo at once.
			AITP_Audit::rollback( $id );
			AITP_Audit::tag_batch( $id, array( 'auto_result' => 'no_pages' ) );
			self::save( $s );
			self::stop( 'no_pages' );
			return false;
		}
		$reset = isset( $info['reset'] ) ? (int) $info['reset'] : 0;
		if ( ! $reset ) {
			$reset = (int) AITP_Audit::batch_check( $id, false )['reset']; // A cut-off batch: its record says 0.
		}
		if ( ! empty( $info['wide'] ) ) {
			$s['run_wide'] = true;
		}
		$s['batch'] = array(
			'id'      => (int) $id,
			'lang'    => isset( $info['lang'] ) && $info['lang'] !== '' ? (string) $info['lang'] : (string) $urls[0]['lang'],
			'reset'   => $reset,
			'urls'    => $urls,
			'ui'      => 0,
			'pass'    => 1,
			'before'  => $reset,
			'stalls'  => 0,
			'secs'    => 0,
			'next_at' => 0,
			'started' => time(),
			'chars'   => 0,
			'fails'   => 0,
			'wide'    => ! empty( $info['wide'] ), // Set again when the batch ends (a stop in between clears the cache already).
		);
		self::save( $s );
		return true;
	}

	/**
	 * Read the next sample page (read only: nothing is translated or saved).
	 *
	 * @param array $s State.
	 */
	private static function map_next( array $s ) {
		$m    = $s['mapping'];
		$item = $m['items'][ (int) $m['i'] ];
		$ok   = AITP_Pagemap::map_one( $item );
		$s    = self::state(); // A person may have stopped it during the visit.
		if ( empty( $s['mapping'] ) ) {
			return;
		}
		$s['mapping']['i']       = (int) $m['i'] + 1;
		$s['mapping']['ok']      = (int) $m['ok'] + ( $ok ? 1 : 0 );
		$s['mapping']['next_at'] = time() + AITP_Pagemap::GAP;
		if ( $s['mapping']['i'] >= count( $m['items'] ) ) {
			( new AITP_WP_Log() )->log( 'info', 'autofix_mapped', array( 'lang' => $m['lang'], 'pages' => count( $m['items'] ), 'read' => $s['mapping']['ok'] ) );
			$s['mapping'] = null;
		}
		self::save( $s );
	}

	/**
	 * Read-only test load of one known page of a language.
	 *
	 * @param string $lang Language.
	 * @return string '' when it loaded, otherwise visit_signature | visit_check.
	 */
	private static function visit_check( $lang ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$url = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT url FROM ' . AITP_Schema::table( 'pagemap' ) . ' WHERE lang = %s AND http BETWEEN 200 AND 299 ORDER BY mapped_at DESC LIMIT 1', $lang ) );
		if ( $url === '' ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$url = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT url FROM ' . AITP_Schema::table( 'queue' ) . ' WHERE lang = %s ORDER BY id LIMIT 1', $lang ) );
		}
		if ( $url === '' ) {
			return ''; // Nothing known to load; the batch's own visits are checked as before.
		}
		$v = AITP_Queue::visit( $url, $lang, AITP_Queue::page_seconds(), true );
		if ( $v['sig'] !== '' ) {
			return 'visit_signature';
		}
		if ( $v['http'] < 200 || $v['http'] >= 300 || empty( $v['report'] ) ) {
			( new AITP_WP_Log() )->log( 'warning', 'autofix_check', array( 'url' => $url, 'http' => $v['http'], 'ms' => $v['ms'] ) );
			return 'visit_check';
		}
		return '';
	}

	/**
	 * Visit the next page of the batch.
	 *
	 * @param array $s State.
	 * @return string '' to continue, or stopped | waiting.
	 */
	private static function visit_next( array $s ) {
		$batch = $s['batch'];
		$item  = $batch['urls'][ (int) $batch['ui'] ];
		$v     = AITP_Queue::visit( $item['url'], $item['lang'], AITP_Queue::page_seconds() );
		$s     = self::state(); // A person may have stopped it during the visit.
		if ( ! $s['batch'] || (int) $s['batch']['id'] !== (int) $batch['id'] ) {
			return 'stopped';
		}
		$gap                  = (int) ceil( 60 / max( 1, (int) AITP_Queue::settings()['per_minute'] ) );
		$s['batch']['ui']     = (int) $batch['ui'] + 1;
		$s['batch']['chars'] += (int) $v['sent'];
		$s['batch']['secs']   = ( isset( $batch['secs'] ) ? (int) $batch['secs'] : 0 ) + (int) ceil( (int) $v['ms'] / 1000 );
		$s['batch']['next_at'] = time() + $gap;
		$s['day_chars']      += (int) $v['sent'];
		if ( $v['sig'] !== '' ) {
			// The site refused the signed visit (nothing was translated): every page would.
			self::save( $s );
			self::stop( 'visit_signature' );
			return 'stopped';
		}
		$ok_page               = $v['http'] >= 200 && $v['http'] < 300 && ! empty( $v['report'] );
		if ( ! $ok_page ) {
			$s['batch']['fails']++;
		}
		if ( (int) $v['translated'] > 0 ) {
			AITP_Cache_Purge::urls( array( $item['url'] ) );
		}
		$stop = AITP_Autofix_Logic::visit_stop( (string) $v['stop'] );
		if ( $stop === 'budget' ) {
			$s['batch']['ui'] = (int) $batch['ui']; // Visit this page again tomorrow.
			self::save( $s );
			self::wait_for_tomorrow( self::state(), 'quota' );
			return 'waiting';
		}
		self::save( $s );
		if ( $stop === 'provider' ) {
			self::stop( 'provider_' . $v['stop'] );
			return 'stopped';
		}
		if ( $s['batch']['fails'] >= AITP_Autofix_Logic::MAX_VISIT_FAILURES ) {
			self::stop( 'visit_failed' );
			return 'stopped';
		}
		return '';
	}

	/**
	 * All pages of the batch visited once more: another round, or judge the batch.
	 *
	 * @param array $s State.
	 * @return string '' to continue, or stopped.
	 */
	private static function end_pass( array $s ) {
		$batch = $s['batch'];
		$now_c  = AITP_Audit::batch_check( $batch['id'], false );
		$stalls = isset( $batch['stalls'] ) ? (int) $batch['stalls'] : 0;
		$secs   = isset( $batch['secs'] ) ? (int) $batch['secs'] : 0;
		if ( AITP_Autofix_Logic::after_pass( (int) $batch['pass'], $now_c['untranslated'], (int) $batch['before'], $stalls, $secs ) === 'again' ) {
			$s['batch']['pass']    = (int) $batch['pass'] + 1;
			$s['batch']['ui']      = 0;
			$s['batch']['stalls']  = AITP_Autofix_Logic::stalls( $stalls, $now_c['untranslated'], (int) $batch['before'] );
			$s['batch']['before']  = $now_c['untranslated'];
			$s['batch']['next_at'] = time() + AITP_Autofix_Logic::PASS_GAP;
			self::save( $s );
			return '';
		}
		$c = AITP_Audit::batch_check( $batch['id'], true );
		$j = AITP_Autofix_Logic::judge( $c );
		AITP_Cache_Purge::urls( wp_list_pluck( $batch['urls'], 'url' ) );
		$entry = array(
			'batch'  => (int) $batch['id'],
			'lang'   => $batch['lang'],
			'time'   => time(),
			'pages'  => count( $batch['urls'] ),
			'passes' => (int) $batch['pass'],
			'chars'  => (int) $batch['chars'],
			'result' => $j['result'],
			'rate'   => $j['rate'],
		) + $c;
		AITP_Audit::tag_batch( $batch['id'], array( 'auto' => 1, 'auto_result' => $j['result'], 'auto_rate' => $j['rate'] ) );
		array_unshift( $s['history'], $entry );
		$s['history'] = array_slice( $s['history'], 0, self::HISTORY );
		if ( ! empty( $batch['wide'] ) ) {
			$s['run_wide'] = true; // A stop during the batch cleared the cache before the rows were translated.
		}
		$s['batch']   = null;
		$s['batches']++;
		$s['run_batches'] = (int) $s['run_batches'] + 1;
		$s['rows_ok']    += (int) $c['ok'];
		self::save( $s );
		( new AITP_WP_Log() )->log( $j['result'] === 'ok' ? 'info' : 'warning', 'autofix_batch', $entry );
		if ( $j['result'] !== 'ok' ) {
			self::stop( $j['result'] );
			return 'stopped';
		}
		$max = (int) self::settings()['max_batches'];
		if ( $max > 0 && (int) $s['run_batches'] >= $max ) {
			self::stop( 'max_batches' );
			return 'stopped';
		}
		return 'batch_done';
	}

	/* -------------------------------------------------------------------------------------
	 * Lock.
	 * ----------------------------------------------------------------------------------- */

	/**
	 * @param int $ttl Seconds.
	 * @return bool
	 */
	private static function lock( $ttl ) {
		return AITP_Lock::acquire( self::LOCK, $ttl );
	}

	/** Release. */
	private static function unlock() {
		AITP_Lock::release( self::LOCK );
	}
}
