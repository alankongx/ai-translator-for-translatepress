<?php
/**
 * Batch auto repair (off by default).
 *
 * Works through the "wrong" findings of the check one batch at a time:
 *   1. back up and reset up to N rows of one language (AITP_Audit::repair, same as by hand);
 *   2. visit only the pages that show them, until they are translated again;
 *   3. check the new translations with the audit rules;
 *   4. go on with the next batch only when the batch came out clean enough.
 * It stops by itself when more than 5% of a batch is still wrong or untranslated, when
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
				'rows_ok'     => 0,
				'left'        => array( 'structured' => 0, 'no_page' => 0 ),
				'history'     => array(),
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

	/** @param array $s State. */
	private static function save( array $s ) {
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
		self::save( $s );
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
			$s['status'] = 'stopped';
			$s['reason'] = (string) $reason;
			$s['ended']  = time();
			self::save( $s );
			( new AITP_WP_Log() )->log( $reason === 'manual' ? 'notice' : 'warning', 'autofix_stop', array( 'reason' => $reason, 'batch' => $s['batch'] ? $s['batch']['id'] : 0 ) );
		}
		wp_clear_scheduled_hook( self::CRON );
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
					if ( $r === 'empty' && ++$empty >= 5 ) {
						break; // Picked rows had all changed; try again next tick.
					}
					continue;
				}

				$batch = $s['batch'];
				$info  = AITP_Audit::batches();
				if ( ! empty( $info[ $batch['id'] ]['rolled_back'] ) ) {
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
	 * @return string started | empty | done | stopped.
	 */
	private static function begin_batch( array $s ) {
		$set  = self::settings();
		$pick = AITP_Audit::autofix_pick( $set['languages'], $set['batch'] );
		$s['left'] = array( 'structured' => $pick['structured'], 'no_page' => $pick['no_page'] );
		if ( ! $pick['ids'] ) {
			$s['status'] = 'done';
			$s['reason'] = '';
			$s['ended']  = time();
			self::save( $s );
			wp_clear_scheduled_hook( self::CRON );
			( new AITP_WP_Log() )->log( 'notice', 'autofix_done', array( 'batches' => $s['batches'], 'rows_ok' => $s['rows_ok'], 'left' => $s['left'] ) );
			return 'done';
		}
		$r = AITP_Audit::repair( $pick['ids'] );
		if ( ! $r['reset'] ) {
			self::save( $s );
			if ( isset( $r['skipped']['engine_not_selected'] ) || isset( $r['skipped']['backup_failed'] ) ) {
				self::stop( isset( $r['skipped']['backup_failed'] ) ? 'backup_failed' : 'engine_not_selected' );
				return 'stopped';
			}
			return 'empty';
		}
		$urls = AITP_Audit::batch_urls( $r['batch'] );
		AITP_Audit::tag_batch( $r['batch'], array( 'auto' => 1 ) );
		if ( ! $urls ) {
			// Should not happen (rows were picked because their pages are known): undo at once.
			AITP_Audit::rollback( $r['batch'] );
			self::save( $s );
			self::stop( 'no_pages' );
			return 'stopped';
		}
		$s['batch'] = array(
			'id'      => (int) $r['batch'],
			'lang'    => $pick['lang'],
			'reset'   => (int) $r['reset'],
			'urls'    => $urls,
			'ui'      => 0,
			'pass'    => 1,
			'before'  => (int) $r['reset'],
			'next_at' => 0,
			'started' => time(),
			'chars'   => 0,
			'fails'   => 0,
		);
		self::save( $s );
		return 'started';
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
		$s['batch']['next_at'] = time() + $gap;
		$s['day_chars']      += (int) $v['sent'];
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
		$now_c = AITP_Audit::batch_check( $batch['id'], false );
		if ( AITP_Autofix_Logic::after_pass( (int) $batch['pass'], $now_c['untranslated'], (int) $batch['before'] ) === 'again' ) {
			$s['batch']['pass']    = (int) $batch['pass'] + 1;
			$s['batch']['ui']      = 0;
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
		$s['batch']   = null;
		$s['batches']++;
		$s['rows_ok'] += (int) $c['ok'];
		self::save( $s );
		( new AITP_WP_Log() )->log( $j['result'] === 'ok' ? 'info' : 'warning', 'autofix_batch', $entry );
		if ( $j['result'] !== 'ok' ) {
			self::stop( $j['result'] );
			return 'stopped';
		}
		return '';
	}

	/* -------------------------------------------------------------------------------------
	 * Lock.
	 * ----------------------------------------------------------------------------------- */

	/**
	 * @param int $ttl Seconds.
	 * @return bool
	 */
	private static function lock( $ttl ) {
		if ( add_option( self::LOCK, time() + $ttl, '', 'no' ) ) {
			return true;
		}
		wp_cache_delete( self::LOCK, 'options' );
		$until = (int) get_option( self::LOCK );
		if ( $until && $until < time() ) {
			delete_option( self::LOCK );
			return add_option( self::LOCK, time() + $ttl, '', 'no' );
		}
		return false;
	}

	/** Release. */
	private static function unlock() {
		delete_option( self::LOCK );
	}
}
