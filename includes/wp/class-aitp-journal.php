<?php
/**
 * Test journal (off by default).
 *
 * While it is on, every translation this engine hands to TranslatePress is written down
 * together with the exact state the TranslatePress row had just before (translated text and
 * status). "Undo translations of the test" puts those rows back to that state, but only rows
 * that still hold exactly our translation. Rows TranslatePress inserted during the test (new
 * strings found on pages) are listed and can be downloaded; they are never deleted.
 *
 * Meant for a test on a staging site: switch it on before the first page is translated,
 * roll back repair batches first, then undo, then compare with the baseline.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Journal {

	const STATE = 'aitp_journal_state';

	/** @var array|null State of this request. */
	private static $cache = null;

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

	/**
	 * Write down translations about to be saved by TranslatePress.
	 *
	 * @param array  $strings      key => original.
	 * @param array  $translations key => translation.
	 * @param string $lang         Target language.
	 */
	public static function record( array $strings, array $translations, $lang ) {
		if ( ! $translations || ! self::is_on() ) {
			return;
		}
		try {
			self::record_rows( $strings, $translations, (string) $lang );
		} catch ( \Throwable $e ) {
			( new AITP_WP_Log() )->log( 'error', 'journal_record', array( 'error' => $e->getMessage() ) );
		}
	}

	/**
	 * @param array  $strings      key => original.
	 * @param array  $translations key => translation.
	 * @param string $lang         Target language.
	 */
	private static function record_rows( array $strings, array $translations, $lang ) {
		global $wpdb;
		$session = (int) self::state()['session'];
		$pairs   = array(); // original => translation.
		foreach ( $translations as $k => $tr ) {
			if ( isset( $strings[ $k ] ) && (string) $strings[ $k ] !== '' ) {
				$pairs[ (string) $strings[ $k ] ] = (string) $tr;
			}
		}
		if ( ! $pairs ) {
			return;
		}
		$found = array();
		$now   = current_time( 'mysql', true );
		$table = AITP_Schema::table( 'journal' );
		foreach ( AITP_Audit::tables() as $t ) {
			if ( strtolower( $t['lang'] ) !== strtolower( str_replace( '-', '_', $lang ) ) ) {
				continue;
			}
			foreach ( array_chunk( array_keys( $pairs ), 200 ) as $chunk ) {
				$chunk = array_map( 'strval', $chunk );
				$ph    = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, original, translated, status FROM `{$t['table']}` WHERE original IN ($ph)", $chunk ), ARRAY_A );
				foreach ( $rows as $r ) {
					$o = (string) $r['original'];
					if ( ! in_array( $o, $chunk, true ) || (int) $r['status'] === 2 ) {
						continue; // Not an exact match, or a manual translation TranslatePress keeps.
					}
					$found[ $o ] = true;
					$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						$table,
						array(
							'session'         => $session,
							'kind'            => $t['kind'],
							'lang'            => $t['lang'],
							'row_id'          => (int) $r['id'],
							'original'        => $o,
							'prev_translated' => $r['translated'],
							'prev_status'     => (int) $r['status'],
							'translated'      => $pairs[ $o ],
							'created_at'      => $now,
						)
					);
				}
			}
		}
		foreach ( $pairs as $o => $tr ) {
			if ( ! isset( $found[ $o ] ) ) {
				// No row yet: TranslatePress inserts it now (listed under new rows, never deleted).
				$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$table,
					array(
						'session'         => $session,
						'kind'            => '',
						'lang'            => $lang,
						'row_id'          => 0,
						'original'        => (string) $o,
						'prev_translated' => null,
						'prev_status'     => 0,
						'translated'      => $tr,
						'created_at'      => $now,
					)
				);
			}
		}
	}

	/**
	 * Put the rows our engine translated during the session back to their state before.
	 * Only rows that still hold exactly our translation (status machine translated).
	 *
	 * @return array{restored:int,skipped:array<string,int>,new_rows:int}
	 */
	public static function undo() {
		global $wpdb;
		self::$cache = null;
		$s     = self::state();
		$out   = array( 'restored' => 0, 'skipped' => array(), 'new_rows' => 0 );
		$table = AITP_Schema::table( 'journal' );
		$by    = array();
		foreach ( AITP_Audit::tables() as $t ) {
			$by[ $t['kind'] . '|' . $t['lang'] ] = $t;
		}
		$sanitize = function_exists( 'trp_sanitize_string' );
		$last     = PHP_INT_MAX;
		while ( true ) {
			// Newest first: a row translated twice ends up in its state before the first time.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$list = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE session = %d AND undone = '' AND id < %d ORDER BY id DESC LIMIT 500", (int) $s['session'], $last ), ARRAY_A );
			if ( ! $list ) {
				break;
			}
			$last = (int) end( $list )['id'];
			foreach ( $list as $e ) {
				$why = self::undo_one( $e, $by, $sanitize );
				$wpdb->update( $table, array( 'undone' => $why === '' ? 'yes' : $why ), array( 'id' => (int) $e['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( $why === '' ) {
					$out['restored']++;
				} elseif ( $why === 'new_row' ) {
					$out['new_rows']++;
				} else {
					$out['skipped'][ $why ] = ( isset( $out['skipped'][ $why ] ) ? $out['skipped'][ $why ] : 0 ) + 1;
				}
			}
		}
		( new AITP_WP_Log() )->log( 'notice', 'journal_undo', array( 'session' => $s['session'] ) + $out );
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
		$tbl = $by[ $k ]['table'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$r = $wpdb->get_row( $wpdb->prepare( "SELECT id, original, translated, status FROM `$tbl` WHERE id = %d", (int) $e['row_id'] ), ARRAY_A );
		if ( ! $r ) {
			return 'missing';
		}
		if ( (string) $r['original'] !== (string) $e['original'] ) {
			return 'changed';
		}
		if ( (int) $r['status'] === 2 ) {
			return 'manual';
		}
		$ours = array( (string) $e['translated'] );
		if ( $sanitize ) {
			$ours[] = (string) trp_sanitize_string( (string) $e['translated'] );
		}
		if ( (int) $r['status'] !== 1 || $r['translated'] === null || ! in_array( (string) $r['translated'], $ours, true ) ) {
			$same = (int) $r['status'] === (int) $e['prev_status'] && $r['translated'] === $e['prev_translated'];
			return $same ? 'not_saved' : 'changed';
		}
		$set = $e['prev_translated'] === null ? 'translated = NULL' : $wpdb->prepare( 'translated = %s', (string) $e['prev_translated'] );
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
	 *
	 * @return array<int,array>
	 */
	public static function new_rows() {
		global $wpdb;
		self::$cache = null;
		$s   = self::state();
		$out = array();
		foreach ( AITP_Audit::tables() as $t ) {
			if ( ! isset( $s['max'][ $t['table'] ] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, original, translated, status FROM `{$t['table']}` WHERE id > %d ORDER BY id", (int) $s['max'][ $t['table'] ] ), ARRAY_A ) as $r ) {
				$out[] = array( 'table' => $t['kind'], 'language' => $t['lang'], 'id' => (int) $r['id'], 'original' => (string) $r['original'], 'translated' => $r['translated'], 'status' => (int) $r['status'] );
			}
		}
		return $out;
	}
}
