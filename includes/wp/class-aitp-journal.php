<?php
/**
 * Test journal (off by default).
 *
 * While it is on, every translation of this engine that TranslatePress saves is written down
 * together with the exact state the TranslatePress row had just before (translated text,
 * status and original_id). "Undo translations of the test" puts those rows back to that
 * state, but only rows that still hold exactly what was saved. Rows TranslatePress inserted
 * during the test (new strings found on pages) are listed and can be downloaded; they are
 * never deleted.
 *
 * Since 0.6.5 rows are recorded when TranslatePress saves them, by row id and with the value
 * it writes (filter trp_allow_string_saving for page text; the id-backed gettext queue for
 * interface text). Before, they were looked up by their decoded original text, which missed
 * rows whose original holds HTML entities or a plural form.
 *
 * A repair batch resets rows to "not translated"; translations saved after that are recorded
 * with that reset state as "before". Rolling the batch back restores the rows from its backup
 * and marks those entries as handled ("rolled_back"), so an undo afterwards never resets them.
 * An undo is refused while repair batches of the test are not rolled back, and while
 * TranslatePress still translates with this engine (a visit would translate the rows again).
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Journal {

	const STATE = 'aitp_journal_state';

	/** Seconds one undo request may work; the rest is done by running it again. */
	const UNDO_SECONDS = 200;

	/** @var array|null State of this request. */
	private static $cache = null;

	/** @var string Language this engine last translated into in this request (TranslatePress code). */
	private static $lang = '';

	/** @var array<string,array<int,array>> Interface-text rows TranslatePress is about to machine translate: language => row id => row before. */
	private static $gettext = array();

	/** Hooks. */
	public static function boot() {
		add_filter( 'trp_allow_string_saving', array( __CLASS__, 'on_save' ), PHP_INT_MAX, 3 );
		add_filter( 'trp_gettext_allow_machine_translation', array( __CLASS__, 'on_gettext_queue' ), PHP_INT_MAX, 5 );
		add_action( 'shutdown', array( __CLASS__, 'finish_gettext' ), PHP_INT_MAX - 10 );
	}

	/** @return array */
	public static function state() {
		if ( self::$cache === null ) {
			wp_cache_delete( self::STATE, 'options' );
			$s           = get_option( self::STATE, array() );
			self::$cache = array_merge(
				array(
					'on'      => false,
					'session' => 0,
					'label'   => '',
					'started' => 0,
					'stopped' => 0,
					'max'     => array(), // table => highest row id when the journal started.
				),
				is_array( $s ) ? $s : array()
			);
		}
		return self::$cache;
	}

	/** @param array $s State. */
	private static function save( array $s ) {
		update_option( self::STATE, $s, false );
		self::$cache = $s;
	}

	/** @return bool */
	public static function is_on() {
		return (bool) self::state()['on'];
	}

	/**
	 * Switch the journal on (a new session).
	 *
	 * @param string $label Name of the test.
	 * @return string '' or the reason it cannot start.
	 */
	public static function start( $label ) {
		global $wpdb;
		self::$cache = null;
		if ( self::is_on() ) {
			return 'running';
		}
		if ( ! AITP_Audit::tables() ) {
			return 'no_translatepress';
		}
		$max = array();
		foreach ( AITP_Audit::tables() as $t ) {
			$max[ $t['table'] ] = (int) $wpdb->get_var( "SELECT MAX(id) FROM `{$t['table']}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		self::save(
			array(
				'on'      => true,
				'session' => (int) round( microtime( true ) * 1000 ),
				'label'   => sanitize_text_field( (string) $label ),
				'started' => time(),
				'stopped' => 0,
				'max'     => $max,
			)
		);
		( new AITP_WP_Log() )->log( 'notice', 'journal_start', array( 'label' => $label ) );
		return '';
	}

	/** Switch the journal off (what it recorded stays, for undo). */
	public static function stop() {
		self::$cache = null;
		$s           = self::state();
		if ( $s['on'] ) {
			$s['on']      = false;
			$s['stopped'] = time();
			self::save( $s );
			( new AITP_WP_Log() )->log( 'notice', 'journal_stop', array( 'session' => $s['session'] ) );
		}
	}

	/** @return int Highest entry id so far (0: none). */
	public static function max_id() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT MAX(id) FROM ' . AITP_Schema::table( 'journal' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/* -------------------------------------------------------------------------------------
	 * Recording.
	 * ----------------------------------------------------------------------------------- */

	/**
	 * Called by the engine after each call: remembers the language (the page-text save that
	 * follows does not name it).
	 *
	 * @param string $lang Target language.
	 */
	public static function translated( $lang ) {
		self::$lang = (string) $lang;
	}

	/**
	 * TranslatePress is about to machine translate interface text: note the rows and their
	 * state now; what it saved is read back at the end of the request (finish_gettext()).
	 *
	 * @param bool   $allow    Whether TranslatePress translates them.
	 * @param string $source   Source language.
	 * @param string $language Target language.
	 * @param array  $texts    Texts in queue order.
	 * @param array  $queue    Queue rows (with their row id).
	 * @return bool
	 */
	public static function on_gettext_queue( $allow, $source = '', $language = '', $texts = array(), $queue = array() ) {
		if ( ! $allow || ! is_array( $queue ) || ! $queue || ! AITP_Plugin::engine_selected() || ! self::is_on() ) {
			return $allow;
		}
		try {
			$t = self::table_of( 'gettext', (string) $language );
			if ( ! $t ) {
				return $allow;
			}
			$ids = array();
			foreach ( $queue as $q ) {
				if ( is_array( $q ) && ! empty( $q['id'] ) ) {
					$ids[] = (int) $q['id'];
				}
			}
			foreach ( self::read( $t, $ids ) as $id => $r ) {
				if ( (int) $r['status'] !== 2 ) {
					self::$gettext[ (string) $language ][ $id ] = $r;
				}
			}
		} catch ( \Throwable $e ) {
			( new AITP_WP_Log() )->log( 'error', 'journal_record', array( 'error' => $e->getMessage() ) );
		}
		return $allow;
	}

	/**
	 * TranslatePress saves page text: record every machine translation of this engine with
	 * the row's state before, by row id and with the exact value written.
	 *
	 * @param bool  $allow Whether TranslatePress saves.
	 * @param array $new   New strings (unused).
	 * @param array $rows  Rows to save: id (null: new row), original, translated, status.
	 * @return bool
	 */
	public static function on_save( $allow, $new = array(), $rows = array() ) {
		if ( ! $allow || self::$lang === '' || ! is_array( $rows ) || ! $rows || ! self::is_on() ) {
			return $allow;
		}
		try {
			$t = self::table_of( 'dictionary', self::$lang );
			if ( $t ) {
				$by  = array();
				$new = array();
				foreach ( $rows as $r ) {
					if ( ! is_array( $r ) || ! isset( $r['translated'] ) || (int) ( isset( $r['status'] ) ? $r['status'] : 0 ) !== 1 ) {
						continue; // Only machine translations.
					}
					if ( ! empty( $r['id'] ) ) {
						$by[ (int) $r['id'] ] = (string) $r['translated'];
					} else {
						$new[] = $r;
					}
				}
				if ( $by ) {
					self::record( $t, $by );
				}
				foreach ( $new as $r ) {
					self::insert( array( 'kind' => $t['kind'], 'lang' => $t['lang'], 'row_id' => 0, 'original' => (string) ( isset( $r['original'] ) ? $r['original'] : '' ), 'prev_translated' => null, 'prev_status' => 0, 'translated' => (string) $r['translated'], 'exact' => 1 ) );
				}
			}
		} catch ( \Throwable $e ) {
			( new AITP_WP_Log() )->log( 'error', 'journal_record', array( 'error' => $e->getMessage() ) );
		}
		return $allow;
	}

	/**
	 * End of a request in which TranslatePress machine translated interface text: record the
	 * rows it saved (status machine translated, text changed), with the state noted before.
	 */
	public static function finish_gettext() {
		if ( ! self::$gettext ) {
			return;
		}
		try {
			foreach ( self::$gettext as $lang => $before ) {
				$t = self::table_of( 'gettext', $lang );
				if ( ! $t ) {
					continue;
				}
				foreach ( self::read( $t, array_keys( $before ) ) as $id => $r ) {
					$b = $before[ $id ];
					if ( (int) $r['status'] !== 1 || $r['translated'] === null || ( (int) $b['status'] === 1 && $b['translated'] === $r['translated'] ) ) {
						continue; // Not saved by this request.
					}
					self::insert(
						array(
							'kind'             => 'gettext',
							'lang'             => $t['lang'],
							'row_id'           => (int) $id,
							'original'         => (string) $r['original'],
							'prev_translated'  => $b['translated'],
							'prev_status'      => (int) $b['status'],
							'prev_original_id' => $b['original_id'],
							'translated'       => (string) $r['translated'],
							'exact'            => 1,
						)
					);
				}
			}
		} catch ( \Throwable $e ) {
			( new AITP_WP_Log() )->log( 'error', 'journal_record', array( 'error' => $e->getMessage() ) );
		}
		self::$gettext = array();
	}

	/**
	 * @param array $t   Table entry.
	 * @param int[] $ids Row ids.
	 * @return array<int,array> id => id, original, translated, status, original_id
	 */
	private static function read( array $t, array $ids ) {
		global $wpdb;
		$out = array();
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		foreach ( array_chunk( $ids, 200 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $wpdb->get_results( "SELECT id, original, translated, status, original_id FROM `{$t['table']}` WHERE id IN (" . implode( ',', $chunk ) . ')', ARRAY_A ) as $r ) {
				$out[ (int) $r['id'] ] = $r;
			}
		}
		return $out;
	}

	/**
	 * @param array             $t    Table entry.
	 * @param array<int,string> $hits Row id => translation being saved.
	 */
	private static function record( array $t, array $hits ) {
		foreach ( self::read( $t, array_keys( $hits ) ) as $id => $r ) {
			if ( (int) $r['status'] === 2 ) {
				continue; // A manual translation TranslatePress keeps.
			}
			if ( (int) $r['status'] === 1 && $r['translated'] === $hits[ $id ] ) {
				continue; // Saved unchanged: nothing to undo.
			}
			self::insert(
				array(
					'kind'             => $t['kind'],
					'lang'             => $t['lang'],
					'row_id'           => (int) $id,
					'original'         => (string) $r['original'],
					'prev_translated'  => $r['translated'],
					'prev_status'      => (int) $r['status'],
					'prev_original_id' => $r['original_id'],
					'translated'       => $hits[ $id ],
					'exact'            => 1,
				)
			);
		}
	}

	/**
	 * @param array $e Entry.
	 * @return int Journal id.
	 */
	private static function insert( array $e ) {
		global $wpdb;
		$row = array(
			'session'         => (int) self::state()['session'],
			'kind'            => $e['kind'],
			'lang'            => $e['lang'],
			'row_id'          => (int) $e['row_id'],
			'original'        => (string) $e['original'],
			'prev_translated' => $e['prev_translated'],
			'prev_status'     => (int) $e['prev_status'],
			'translated'      => (string) $e['translated'],
			'exact'           => (int) $e['exact'],
			'created_at'      => current_time( 'mysql', true ),
		);
		if ( isset( $e['prev_original_id'] ) && $e['prev_original_id'] !== null ) {
			$row['prev_original_id'] = (int) $e['prev_original_id'];
		}
		$wpdb->insert( AITP_Schema::table( 'journal' ), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->insert_id;
	}

	/**
	 * @param string $kind dictionary | gettext.
	 * @param string $lang Language.
	 * @return array|null Table entry.
	 */
	private static function table_of( $kind, $lang ) {
		foreach ( AITP_Audit::tables() as $t ) {
			if ( $t['kind'] === $kind && strtolower( $t['lang'] ) === strtolower( str_replace( '-', '_', (string) $lang ) ) ) {
				return $t;
			}
		}
		return null;
	}

	/**
	 * A repair batch was rolled back for these rows: entries recorded after the repair started
	 * describe translations of the reset rows and must not be undone any more.
	 *
	 * @param string $kind    Table kind.
	 * @param string $lang    Language.
	 * @param int[]  $row_ids Rows restored from the backup.
	 * @param int    $after   Journal id when the batch started (-1: unknown, use $since).
	 * @param string $since   Time the batch was backed up (UTC, MySQL format).
	 * @return int Entries marked.
	 */
	public static function rolled_back( $kind, $lang, array $row_ids, $after, $since ) {
		global $wpdb;
		$row_ids = array_values( array_filter( array_map( 'intval', $row_ids ) ) );
		if ( ! $row_ids ) {
			return 0;
		}
		$table = AITP_Schema::table( 'journal' );
		$n     = 0;
		foreach ( array_chunk( $row_ids, 500 ) as $chunk ) {
			$when = $after >= 0 ? $wpdb->prepare( 'id > %d', (int) $after ) : $wpdb->prepare( 'created_at >= %s', (string) $since );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$n += (int) $wpdb->query( $wpdb->prepare( "UPDATE $table SET undone = 'rolled_back' WHERE undone = '' AND kind = %s AND lang = %s AND $when AND row_id IN (" . implode( ',', $chunk ) . ')', (string) $kind, (string) $lang ) );
		}
		return $n;
	}

	/**
	 * Batches rolled back before 0.6.5 did not mark the journal: do it now, before an undo.
	 * Every entry for a restored row recorded after the batch backed it up came from that
	 * batch or a later one (a restored row is translated, so only a repair resets it again),
	 * and an undo runs only when all repair batches of the test are rolled back.
	 *
	 * @return int Entries marked.
	 */
	private static function mark_legacy_rollbacks() {
		global $wpdb;
		$s      = self::state();
		$repair = AITP_Schema::table( 'repair' );
		$n      = 0;
		foreach ( AITP_Audit::batches() as $id => $b ) {
			if ( isset( $b['journal'] ) || (int) ( isset( $b['time'] ) ? $b['time'] : 0 ) < (int) $s['started'] - 60 ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT kind, lang, row_id, created_at FROM $repair WHERE batch = %d AND restored = 'yes'", (int) $id ), ARRAY_A );
			$by   = array();
			foreach ( $rows as $r ) {
				$k = $r['kind'] . '|' . $r['lang'];
				if ( ! isset( $by[ $k ] ) ) {
					$by[ $k ] = array( 'since' => $r['created_at'], 'ids' => array() );
				}
				$by[ $k ]['since'] = min( $by[ $k ]['since'], $r['created_at'] );
				$by[ $k ]['ids'][] = (int) $r['row_id'];
			}
			foreach ( $by as $k => $x ) {
				list( $kind, $lang ) = explode( '|', $k );
				$n += self::rolled_back( $kind, $lang, $x['ids'], -1, $x['since'] );
			}
		}
		return $n;
	}

	/* -------------------------------------------------------------------------------------
	 * Undo.
	 * ----------------------------------------------------------------------------------- */

	/**
	 * Why an undo may not run now.
	 *
	 * @return array{0:string,1:array} reason ('' = it may) and details (open batch ids).
	 */
	public static function undo_blocker() {
		self::$cache = null;
		$s = self::state();
		if ( ! $s['session'] ) {
			return array( 'no_session', array() );
		}
		if ( AITP_Plugin::engine_selected() && AITP_Plugin::automatic_translation_on() ) {
			return array( 'translating', array() );
		}
		$open = array();
		foreach ( AITP_Audit::batches() as $id => $b ) {
			if ( ! empty( $b['todo'] ) && (int) ( isset( $b['time'] ) ? $b['time'] : 0 ) >= (int) $s['started'] - 60 ) {
				$open[] = (string) $id;
			}
		}
		return $open ? array( 'open_batches', $open ) : array( '', array() );
	}

	/**
	 * Put the rows our engine translated during the session back to their state before.
	 * Only rows that still hold exactly our translation (status machine translated).
	 *
	 * @return array{restored:int,skipped:array<string,int>,new_rows:int,blocked:string,open:array,busy:bool,more:bool,total:array<string,int>}
	 */
	public static function undo() {
		global $wpdb;
		$out = array( 'restored' => 0, 'skipped' => array(), 'new_rows' => 0, 'blocked' => '', 'open' => array(), 'busy' => false, 'more' => false, 'total' => array() );
		list( $why, $open ) = self::undo_blocker();
		if ( $why !== '' ) {
			$out['blocked'] = $why;
			$out['open']    = $open;
			return $out;
		}
		if ( ! AITP_Audit::write_lock( 'undo' ) ) {
			$out['busy'] = true;
			return $out;
		}
		try {
			ignore_user_abort( true );
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( self::UNDO_SECONDS + 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			self::mark_legacy_rollbacks();
			$s     = self::state();
			$table = AITP_Schema::table( 'journal' );
			$by    = array();
			foreach ( AITP_Audit::tables() as $t ) {
				$by[ $t['kind'] . '|' . $t['lang'] ] = $t;
			}
			$sanitize = function_exists( 'trp_sanitize_string' );
			$last     = PHP_INT_MAX;
			$end      = time() + self::UNDO_SECONDS;
			while ( true ) {
				if ( time() >= $end ) {
					$out['more'] = true; // Run it again to finish; handled entries are skipped.
					break;
				}
				// Newest first: a row translated twice ends up in its state before the first time.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$list = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE session = %d AND undone = '' AND id < %d ORDER BY id DESC LIMIT 500", (int) $s['session'], $last ), ARRAY_A );
				if ( ! $list ) {
					break;
				}
				$last = (int) end( $list )['id'];
				foreach ( $list as $e ) {
					$why = self::undo_one( $e, $by, $sanitize );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$wpdb->query( $wpdb->prepare( "UPDATE $table SET undone = %s WHERE id = %d AND undone = ''", $why === '' ? 'yes' : $why, (int) $e['id'] ) );
					if ( $why === '' ) {
						$out['restored']++;
					} elseif ( $why === 'new_row' ) {
						$out['new_rows']++;
					} else {
						$out['skipped'][ $why ] = ( isset( $out['skipped'][ $why ] ) ? $out['skipped'][ $why ] : 0 ) + 1;
					}
				}
			}
			$out['total'] = self::summary()['entries'];
		} finally {
			AITP_Audit::write_unlock();
		}
		( new AITP_WP_Log() )->log( 'notice', 'journal_undo', array( 'session' => self::state()['session'] ) + $out );
		return $out;
	}

	/**
	 * @param array $e        Journal entry.
	 * @param array $by       kind|lang => table entry.
	 * @param bool  $sanitize Whether trp_sanitize_string exists.
	 * @return string '' when restored, otherwise why not.
	 */
	private static function undo_one( array $e, array $by, $sanitize ) {
		global $wpdb;
		if ( (int) $e['row_id'] === 0 ) {
			return 'new_row';
		}
		$k = $e['kind'] . '|' . $e['lang'];
		if ( ! isset( $by[ $k ] ) ) {
			return 'missing';
		}
		$tbl   = $by[ $k ]['table'];
		$exact = ! empty( $e['exact'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT id, original, translated, status, original_id FROM `$tbl` WHERE id = %d", (int) $e['row_id'] ), ARRAY_A );
		if ( ! $r ) {
			return 'missing';
		}
		if ( ! $exact && (string) $r['original'] !== (string) $e['original'] ) {
			return 'changed';
		}
		if ( (int) $r['status'] === 2 ) {
			return 'manual';
		}
		$ours = array( (string) $e['translated'] );
		if ( ! $exact && $sanitize ) {
			$ours[] = (string) trp_sanitize_string( (string) $e['translated'] );
		}
		if ( (int) $r['status'] !== 1 || $r['translated'] === null || ! in_array( (string) $r['translated'], $ours, true ) ) {
			$same = (int) $r['status'] === (int) $e['prev_status'] && $r['translated'] === $e['prev_translated'];
			return $same ? 'not_saved' : 'changed';
		}
		$set = $e['prev_translated'] === null ? 'translated = NULL' : $wpdb->prepare( 'translated = %s', (string) $e['prev_translated'] );
		if ( $exact && $e['kind'] === 'dictionary' ) {
			// TranslatePress fills original_id when it saves a row that had none: put that back too.
			$set .= $e['prev_original_id'] === null ? ', original_id = NULL' : $wpdb->prepare( ', original_id = %d', (int) $e['prev_original_id'] );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$n = $wpdb->query( $wpdb->prepare( "UPDATE `$tbl` SET $set, status = %d WHERE id = %d AND status = 1 AND BINARY translated = BINARY %s", (int) $e['prev_status'], (int) $r['id'], (string) $r['translated'] ) );
		return $n === 1 ? '' : 'changed';
	}

	/**
	 * What the session recorded, and the rows TranslatePress added since it started.
	 *
	 * @return array{entries:array<string,int>,new_rows:array<int,array{kind:string,lang:string,status:int,rows:int}>}
	 */
	public static function summary() {
		global $wpdb;
		self::$cache = null;
		$s     = self::state();
		$table = AITP_Schema::table( 'journal' );
		$out   = array( 'entries' => array(), 'new_rows' => array() );
		if ( ! $s['session'] ) {
			return $out;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT undone, COUNT(*) n FROM $table WHERE session = %d GROUP BY undone", (int) $s['session'] ), ARRAY_A ) as $r ) {
			$out['entries'][ $r['undone'] === '' ? 'open' : $r['undone'] ] = (int) $r['n'];
		}
		foreach ( AITP_Audit::tables() as $t ) {
			if ( ! isset( $s['max'][ $t['table'] ] ) ) {
				continue;
			}
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT status, COUNT(*) n FROM `{$t['table']}` WHERE id > %d GROUP BY status", (int) $s['max'][ $t['table'] ] ), ARRAY_A ) as $r ) {
				$out['new_rows'][] = array( 'kind' => $t['kind'], 'lang' => $t['lang'], 'status' => (int) $r['status'], 'rows' => (int) $r['n'] );
			}
		}
		// phpcs:enable
		return $out;
	}

	/**
	 * Rows TranslatePress added since the session started (for download; never deleted).
	 * Written straight to the output in chunks (a whole-site test can add many rows).
	 *
	 * @param callable|null $emit Called with each row; null: rows are returned.
	 * @return array<int,array>
	 */
	public static function new_rows( $emit = null ) {
		global $wpdb;
		self::$cache = null;
		$s   = self::state();
		$out = array();
		foreach ( AITP_Audit::tables() as $t ) {
			if ( ! isset( $s['max'][ $t['table'] ] ) ) {
				continue;
			}
			$from = (int) $s['max'][ $t['table'] ];
			while ( true ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, original, translated, status FROM `{$t['table']}` WHERE id > %d ORDER BY id LIMIT 2000", $from ), ARRAY_A );
				if ( ! $rows ) {
					break;
				}
				foreach ( $rows as $r ) {
					$row  = array( 'table' => $t['kind'], 'language' => $t['lang'], 'id' => (int) $r['id'], 'original' => (string) $r['original'], 'translated' => $r['translated'], 'status' => (int) $r['status'] );
					$from = (int) $r['id'];
					if ( $emit ) {
						call_user_func( $emit, $row );
					} else {
						$out[] = $row;
					}
				}
			}
		}
		return $out;
	}
}
