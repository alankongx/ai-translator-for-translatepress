<?php
/**
 * Audit and repair of translations already stored by TranslatePress.
 *
 * - Scan: reads machine translations (status 1) of every TranslatePress language table
 *   (regular strings and gettext) in small chunks and records findings in aitp_audit.
 *   Nothing in TranslatePress is changed by a scan.
 * - Repair: rows a person selected are backed up (aitp_repair) and set back to
 *   "not translated" (status 0) - only when still machine translated and unchanged since
 *   the scan. Their pages go back into the backfill queue, which has TranslatePress
 *   translate them again through the normal checks.
 * - Rollback: a repair batch is restored from its backup, except rows a person has
 *   translated manually in the meantime.
 *
 * Manual translations (status 2) are never read for changes, reset or restored over.
 * Rows are never deleted and tables are never emptied.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Audit {

	const STATE    = 'aitp_audit_state';
	const LOCK     = 'aitp_audit_lock';
	const BATCHES  = 'aitp_repair_batches';
	const SETTINGS = 'aitp_audit_settings';
	const CRON     = 'aitp_audit_tick';
	const CHUNK    = 1000;

	/** Hooks. */
	public static function boot() {
		add_action( self::CRON, array( __CLASS__, 'tick' ) );
	}

	/* -------------------------------------------------------------------------------------
	 * TranslatePress tables.
	 * ----------------------------------------------------------------------------------- */

	/** @return object|null TranslatePress query component. */
	private static function tp_query() {
		if ( ! class_exists( 'TRP_Translate_Press' ) ) {
			return null;
		}
		$trp = TRP_Translate_Press::get_trp_instance();
		return $trp ? $trp->get_component( 'query' ) : null;
	}

	/** @return string TranslatePress default language. */
	public static function default_language() {
		$s = get_option( 'trp_settings', array() );
		return is_array( $s ) && ! empty( $s['default-language'] ) ? (string) $s['default-language'] : 'en_US';
	}

	/**
	 * Existing TranslatePress tables to audit.
	 *
	 * @return array<int,array{kind:string,lang:string,table:string,originals:string}>
	 */
	public static function tables() {
		static $cache = null;
		if ( $cache !== null ) {
			return $cache;
		}
		$q = self::tp_query();
		if ( ! $q ) {
			return array();
		}
		$out = array();
		foreach ( AITP_Queue::tp_languages() as $lang ) {
			$dict = (string) $q->get_table_name( $lang );
			if ( $dict !== '' && self::exists( $dict ) ) {
				$out[] = array( 'kind' => 'dictionary', 'lang' => $lang, 'table' => $dict, 'originals' => '' );
			}
			if ( method_exists( $q, 'get_gettext_table_name' ) ) {
				$gt = (string) $q->get_gettext_table_name( $lang );
				if ( $gt !== '' && self::exists( $gt ) ) {
					$ot    = method_exists( $q, 'get_table_name_for_gettext_original_strings' ) ? (string) $q->get_table_name_for_gettext_original_strings() : '';
					$out[] = array( 'kind' => 'gettext', 'lang' => $lang, 'table' => $gt, 'originals' => $ot !== '' && self::exists( $ot ) ? $ot : '' );
				}
			}
		}
		$cache = $out;
		return $out;
	}

	/**
	 * @param string $table Table name.
	 * @return bool
	 */
	private static function exists( $table ) {
		global $wpdb;
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * @param string $kind dictionary|gettext.
	 * @param string $lang Language.
	 * @return array|null Table entry.
	 */
	private static function table_for( $kind, $lang ) {
		$norm = strtolower( str_replace( '-', '_', (string) $lang ) );
		foreach ( self::tables() as $t ) {
			if ( $t['kind'] === $kind && strtolower( $t['lang'] ) === $norm ) {
				return $t;
			}
		}
		return null;
	}

	/**
	 * SELECT part returning id, original, translated, status (and original_id) for a table.
	 *
	 * @param array $t Table entry.
	 * @return string
	 */
	private static function select_rows( array $t ) {
		if ( $t['kind'] === 'gettext' && $t['originals'] !== '' ) {
			return "SELECT tt.id, COALESCE(NULLIF(ot.original, ''), tt.original) AS original, tt.translated, tt.status, tt.original_id FROM `{$t['table']}` tt LEFT JOIN `{$t['originals']}` ot ON tt.original_id = ot.id";
		}
		return "SELECT tt.id, tt.original, tt.translated, tt.status, tt.original_id FROM `{$t['table']}` tt";
	}

	/**
	 * Current TranslatePress rows.
	 *
	 * @param array $t   Table entry.
	 * @param int[] $ids Row ids.
	 * @return array<int,array> id => row
	 */
	private static function rows( array $t, array $ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		$out = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		foreach ( (array) $wpdb->get_results( self::select_rows( $t ) . ' WHERE tt.id IN (' . implode( ',', $ids ) . ')', ARRAY_A ) as $r ) {
			$out[ (int) $r['id'] ] = $r;
		}
		return $out;
	}

	/**
	 * @param string $kind Table kind.
	 * @return array Source language info.
	 */
	private static function source_info( $kind ) {
		return AITP_Languages::info( $kind === 'gettext' ? 'en_US' : self::default_language() );
	}

	/* -------------------------------------------------------------------------------------
	 * Settings.
	 * ----------------------------------------------------------------------------------- */

	/** @return array */
	public static function settings() {
		$s = get_option( self::SETTINGS, array() );
		return array_merge( array( 'ai_check' => false, 'gettext' => true ), is_array( $s ) ? $s : array() );
	}

	/** @param array $in Raw input. */
	public static function save_settings( array $in ) {
		update_option(
			self::SETTINGS,
			array(
				'ai_check' => ! empty( $in['ai_check'] ),
				'gettext'  => ! empty( $in['gettext'] ),
			),
			false
		);
	}

	/* -------------------------------------------------------------------------------------
	 * Scan.
	 * ----------------------------------------------------------------------------------- */

	/** @return array */
	public static function state() {
		$s = get_option( self::STATE, array() );
		return array_merge(
			array(
				'status'   => 'idle',
				'scan'     => 0,
				'tables'   => array(),
				'ti'       => 0,
				'phase'    => 'shared',
				'last_id'  => 0,
				'scanned'  => 0,
				'started'  => 0,
				'finished' => 0,
				'rows'     => array(),
			),
			is_array( $s ) ? $s : array()
		);
	}

	/** @param array $s State. */
	private static function set_state( array $s ) {
		update_option( self::STATE, $s, false );
	}

	/**
	 * Start a new scan of all tables.
	 *
	 * @return array State.
	 */
	public static function start_scan() {
		$set    = self::settings();
		$tables = array();
		foreach ( self::tables() as $t ) {
			if ( $t['kind'] === 'gettext' && ! $set['gettext'] ) {
				continue;
			}
			$tables[] = array( $t['kind'], $t['lang'] );
		}
		$s = array(
			'status'   => 'running',
			'scan'     => time(),
			'tables'   => $tables,
			'ti'       => 0,
			'phase'    => 'shared',
			'last_id'  => 0,
			'scanned'  => 0,
			'started'  => time(),
			'finished' => 0,
			'rows'     => array(),
		);
		self::set_state( $s );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 30, AITP_Queue::SCHEDULE, self::CRON );
		}
		return $s;
	}

	/** Stop a running scan (findings so far are kept). */
	public static function stop_scan() {
		$s = self::state();
		if ( $s['status'] === 'running' ) {
			$s['status'] = 'stopped';
			self::set_state( $s );
		}
		wp_clear_scheduled_hook( self::CRON );
	}

	/** WP-Cron tick. */
	public static function tick() {
		$s = self::state();
		if ( $s['status'] !== 'running' ) {
			wp_clear_scheduled_hook( self::CRON );
			return;
		}
		self::step( 40 );
	}

	/**
	 * Advance the scan.
	 *
	 * @param int $seconds Wall time.
	 * @return array{result:string,state:array} result: running, done, idle, busy.
	 */
	public static function step( $seconds ) {
		$s = self::state();
		if ( $s['status'] !== 'running' ) {
			return array( 'result' => 'idle', 'state' => $s );
		}
		if ( ! self::lock( $seconds + 60 ) ) {
			return array( 'result' => 'busy', 'state' => $s );
		}
		$end = microtime( true ) + $seconds;
		$v   = new AITP_Validator( AITP_Settings::rules() );
		try {
			while ( microtime( true ) < $end && $s['ti'] < count( $s['tables'] ) ) {
				list( $kind, $lang ) = $s['tables'][ $s['ti'] ];
				$t                   = self::table_for( $kind, $lang );
				if ( ! $t ) {
					$s = self::next_table( $s );
					continue;
				}
				if ( $s['phase'] === 'shared' ) {
					self::scan_shared( $t, (int) $s['scan'] );
					$s['phase']   = 'rows';
					$s['last_id'] = 0;
					self::set_state( $s );
					continue;
				}
				$n = self::scan_chunk( $t, $v, $s );
				if ( $n < self::CHUNK ) {
					self::scan_neighbors( $t, (int) $s['scan'] );
					$s = self::next_table( $s );
				}
				self::set_state( $s );
			}
			if ( $s['ti'] >= count( $s['tables'] ) ) {
				self::finish( $s );
				$s = self::state();
			}
		} finally {
			self::unlock();
		}
		return array( 'result' => $s['status'] === 'running' ? 'running' : 'done', 'state' => $s );
	}

	/**
	 * @param array $s State.
	 * @return array
	 */
	private static function next_table( array $s ) {
		$s['ti']++;
		$s['phase']   = 'shared';
		$s['last_id'] = 0;
		return $s;
	}

	/**
	 * Check the next chunk of machine translations of one table.
	 *
	 * @param array          $t Table entry.
	 * @param AITP_Validator $v Validator.
	 * @param array          $s State (updated).
	 * @return int Rows read.
	 */
	private static function scan_chunk( array $t, AITP_Validator $v, array &$s ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( self::select_rows( $t ) . ' WHERE tt.status = 1 AND tt.id > %d ORDER BY tt.id LIMIT %d', (int) $s['last_id'], self::CHUNK ), ARRAY_A );
		$si   = self::source_info( $t['kind'] );
		$ti   = AITP_Languages::info( $t['lang'] );
		$found = array();
		$same  = array(); // Originals stored unchanged and called untranslated.
		foreach ( $rows as $i => $r ) {
			$s['last_id'] = (int) $r['id'];
			$f            = AITP_Audit_Logic::check_row( $v, (string) $r['original'], $r['translated'], $si, $ti );
			if ( $f ) {
				$found[ $i ] = $f;
				if ( $f['codes'] === array( 'untranslated' ) && is_string( $r['translated'] ) && trim( $r['translated'] ) === trim( (string) $r['original'] ) ) {
					$same[ (string) $r['original'] ] = true;
				}
			}
		}
		$elsewhere = $same && $t['kind'] === 'dictionary' ? self::originals_elsewhere( $t, array_keys( $same ) ) : array();
		foreach ( $found as $i => $f ) {
			$r = $rows[ $i ];
			if ( $t['kind'] === 'dictionary' && isset( $same[ (string) $r['original'] ] ) ) {
				$f = AITP_Audit_Logic::native_identical( $f, (string) $r['original'], $r['translated'], isset( $elsewhere[ (string) $r['original'] ] ) );
			}
			self::record( $t, $r, $f, 'scan', (int) $s['scan'] );
		}
		$key               = $t['kind'] . ':' . $t['lang'];
		$s['rows'][ $key ] = ( isset( $s['rows'][ $key ] ) ? (int) $s['rows'][ $key ] : 0 ) + count( $rows );
		$s['scanned']     += count( $rows );
		return count( $rows );
	}

	/**
	 * Originals that also exist in another language's dictionary (so they are source text).
	 *
	 * @param array    $t         Dictionary table entry.
	 * @param string[] $originals Originals.
	 * @return array<string,bool>
	 */
	private static function originals_elsewhere( array $t, array $originals ) {
		global $wpdb;
		$out = array();
		foreach ( self::tables() as $o ) {
			if ( $o['kind'] !== 'dictionary' || $o['table'] === $t['table'] || ! $originals ) {
				continue;
			}
			foreach ( array_chunk( $originals, 100 ) as $chunk ) {
				$ph = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
				foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT original FROM `{$o['table']}` WHERE original IN ($ph)", $chunk ) ) as $hit ) {
					if ( in_array( $hit, $chunk, true ) ) {
						$out[ $hit ] = true; // Byte for byte (the IN above ignores case).
					}
				}
			}
			$originals = array_values( array_diff( $originals, array_keys( $out ) ) );
		}
		return $out;
	}

	/**
	 * Find translations shared by unrelated originals in one table.
	 *
	 * @param array $t    Table entry.
	 * @param int   $scan Scan id.
	 */
	private static function scan_shared( array $t, $scan ) {
		global $wpdb;
		$wpdb->query( 'SET SESSION group_concat_max_len = 1000000' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$orig = $t['kind'] === 'gettext' && $t['originals'] !== '' ? "COALESCE(NULLIF(ot.original, ''), tt.original)" : 'tt.original';
		$from = $t['kind'] === 'gettext' && $t['originals'] !== '' ? "`{$t['table']}` tt LEFT JOIN `{$t['originals']}` ot ON tt.original_id = ot.id" : "`{$t['table']}` tt";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$groups = (array) $wpdb->get_col( "SELECT GROUP_CONCAT(tt.id ORDER BY tt.id) FROM $from WHERE tt.status = 1 AND CHAR_LENGTH(tt.translated) >= 12 GROUP BY MD5(tt.translated) HAVING COUNT(DISTINCT MD5($orig)) > 1 LIMIT 20000" );
		foreach ( $groups as $g ) {
			$ids  = array_slice( array_map( 'intval', explode( ',', (string) $g ) ), 0, 50 );
			$rows = self::rows( $t, $ids );
			if ( count( $rows ) < 2 ) {
				continue;
			}
			$first     = reset( $rows );
			$originals = array();
			foreach ( $rows as $id => $r ) {
				$originals[ $id ] = (string) $r['original'];
			}
			$flag = AITP_Audit_Logic::shared_suspects( (string) $first['translated'], $originals );
			foreach ( $flag as $id ) {
				$others = array_values( array_diff( array_keys( $rows ), array( $id ) ) );
				self::record(
					$t,
					$rows[ $id ],
					array(
						'severity' => AITP_Audit_Logic::SUSPECT,
						'codes'    => array( 'shared_translation' ),
						'details'  => array( 'same_as' => array_slice( $others, 0, 10 ) ),
					),
					'scan',
					$scan
				);
			}
		}
	}

	/**
	 * Rows around runs of misplaced-looking rows become suspects.
	 *
	 * @param array $t    Table entry.
	 * @param int   $scan Scan id.
	 */
	private static function scan_neighbors( array $t, $scan ) {
		global $wpdb;
		$audit = AITP_Schema::table( 'audit' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT row_id, codes FROM $audit WHERE kind = %s AND lang = %s AND scan = %d AND severity = 'error'", $t['kind'], $t['lang'], $scan ), ARRAY_A );
		$ids  = array();
		foreach ( $rows as $r ) {
			if ( AITP_Audit_Logic::shift_like( explode( ',', $r['codes'] ) ) ) {
				$ids[] = (int) $r['row_id'];
			}
		}
		foreach ( AITP_Audit_Logic::neighbor_ranges( $ids ) as $range ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
			$near = (array) $wpdb->get_results( $wpdb->prepare( self::select_rows( $t ) . ' WHERE tt.status = 1 AND tt.id BETWEEN %d AND %d LIMIT 200', $range[0], $range[1] ), ARRAY_A );
			foreach ( $near as $r ) {
				self::record(
					$t,
					$r,
					array(
						'severity' => AITP_Audit_Logic::SUSPECT,
						'codes'    => array( 'near_misaligned' ),
						'details'  => array( 'range' => $range ),
					),
					'scan',
					$scan
				);
			}
		}
	}

	/**
	 * Store or merge a finding.
	 *
	 * @param array  $t      Table entry.
	 * @param array  $r      TranslatePress row.
	 * @param array  $f      Finding: severity, codes, details.
	 * @param string $source scan|import.
	 * @param int    $scan   Scan id.
	 */
	private static function record( array $t, array $r, array $f, $source, $scan ) {
		global $wpdb;
		$table = AITP_Schema::table( 'audit' );
		$oh    = sha1( (string) $r['original'] );
		$th    = sha1( (string) $r['translated'] );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$old  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE kind = %s AND lang = %s AND row_id = %d", $t['kind'], $t['lang'], (int) $r['id'] ), ARRAY_A );
		$same = $old && $old['original_hash'] === $oh && $old['translated_hash'] === $th;
		$sev  = $f['severity'];
		$cod  = $f['codes'];
		$det  = $f['details'];
		if ( $same && ( (int) $old['scan'] === (int) $scan || $old['source'] === 'import' ) ) {
			// Same row seen twice in this scan (shared + row checks), or imported earlier: merge.
			$rank = array( 'review' => 1, 'suspect' => 2, 'error' => 3 );
			$sev  = $rank[ $old['severity'] ] >= $rank[ $sev ] ? $old['severity'] : $sev;
			$cod  = array_values( array_unique( array_merge( array_filter( explode( ',', $old['codes'] ) ), $cod ) ) );
			$det  = array_merge( (array) json_decode( (string) $old['details'], true ), $det );
		}
		$data = array(
			'kind'            => $t['kind'],
			'lang'            => $t['lang'],
			'row_id'          => (int) $r['id'],
			'original_hash'   => $oh,
			'translated_hash' => $th,
			'severity'        => $sev,
			'codes'           => substr( implode( ',', $cod ), 0, 255 ),
			'details'         => (string) wp_json_encode( $det ),
			'source'          => $old && $same && $old['source'] === 'import' ? 'import' : $source,
			'scan'            => (int) $scan,
			'updated_at'      => current_time( 'mysql', true ),
		);
		$fresh = AITP_Audit_Logic::is_accepted( $r, self::accepted( $t['kind'], $t['lang'], array( (int) $r['id'] ) )[ (int) $r['id'] ] ?? array() ) ? 'accepted' : 'open';
		if ( ! $old ) {
			$data['state'] = $fresh;
			$data['ai']    = '';
			$wpdb->insert( $table, $data ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}
		if ( ! $same ) {
			// The row changed since it was last recorded: a new finding.
			$data['state'] = $fresh;
			$data['ai']    = '';
			$data['batch'] = 0;
		} elseif ( in_array( $old['state'], array( 'fixed', 'open', 'accepted' ), true ) ) {
			$data['state'] = $fresh;
		}
		$wpdb->update( $table, $data, array( 'id' => (int) $old['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * End of a scan: open findings of earlier scans that were not seen again are gone.
	 *
	 * @param array $s State.
	 */
	private static function finish( array $s ) {
		global $wpdb;
		$table = AITP_Schema::table( 'audit' );
		$kinds = array();
		foreach ( $s['tables'] as $tl ) {
			$kinds[] = $wpdb->prepare( '(kind = %s AND lang = %s)', $tl[0], $tl[1] );
		}
		if ( $kinds ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE source = 'scan' AND state IN ('open','dismissed') AND scan <> %d AND (" . implode( ' OR ', $kinds ) . ')', (int) $s['scan'] ) );
		}
		// Imported findings are kept until handled; those whose row no longer matches are moved aside.
		foreach ( $s['tables'] as $tl ) {
			$t = self::table_for( $tl[0], $tl[1] );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$imp = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, row_id, original_hash, translated_hash FROM $table WHERE source = 'import' AND state = 'open' AND kind = %s AND lang = %s", $tl[0], $tl[1] ), ARRAY_A );
			foreach ( array_chunk( $imp, 500 ) as $chunk ) {
				$cur = $t ? self::rows( $t, wp_list_pluck( $chunk, 'row_id' ) ) : array();
				foreach ( $chunk as $f ) {
					$r = isset( $cur[ (int) $f['row_id'] ] ) ? $cur[ (int) $f['row_id'] ] : null;
					if ( AITP_Audit_Logic::reset_block( $f, $r ) !== '' ) {
						$wpdb->update( $table, array( 'state' => 'changed' ), array( 'id' => (int) $f['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					}
				}
			}
		}
		$s['status']   = 'done';
		$s['finished'] = time();
		self::set_state( $s );
		wp_clear_scheduled_hook( self::CRON );
		( new AITP_WP_Log() )->log( 'info', 'audit', array( 'scanned' => (int) $s['scanned'], 'open' => self::counts()['open'] ) );
	}

	/* -------------------------------------------------------------------------------------
	 * Findings.
	 * ----------------------------------------------------------------------------------- */

	/**
	 * @return array{open:int,by:array} by[lang][severity] for open findings.
	 */
	public static function counts() {
		global $wpdb;
		$table = AITP_Schema::table( 'audit' );
		$by    = array();
		$open  = 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_results( "SELECT lang, severity, COUNT(*) n FROM $table WHERE state = 'open' GROUP BY lang, severity", ARRAY_A ) as $r ) {
			$by[ $r['lang'] ][ $r['severity'] ] = (int) $r['n'];
			$open                              += (int) $r['n'];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$accepted = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE state = 'accepted'" );
		return array( 'open' => $open, 'by' => $by, 'accepted' => $accepted );
	}

	/**
	 * Build a WHERE clause from list filters.
	 *
	 * @param array $f lang, severity, code, state, ai, source.
	 * @return string
	 */
	private static function where( array $f ) {
		global $wpdb;
		$w = array( '1=1' );
		$state = isset( $f['state'] ) && $f['state'] !== '' ? $f['state'] : 'open';
		if ( $state !== 'all' ) {
			$w[] = $wpdb->prepare( 'state = %s', $state );
		}
		foreach ( array( 'lang', 'severity', 'ai', 'source', 'kind' ) as $k ) {
			if ( isset( $f[ $k ] ) && $f[ $k ] !== '' ) {
				$w[] = $wpdb->prepare( "$k = %s", $f[ $k ] ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
		}
		if ( ! empty( $f['code'] ) ) {
			$w[] = $wpdb->prepare( 'FIND_IN_SET(%s, codes)', $f['code'] );
		}
		if ( ! empty( $f['ids'] ) ) {
			$w[] = 'id IN (' . implode( ',', array_map( 'intval', (array) $f['ids'] ) ) . ')';
		}
		return implode( ' AND ', $w );
	}

	/**
	 * Findings with the current TranslatePress text.
	 *
	 * @param array $f        Filters.
	 * @param int   $page     Page (1-based).
	 * @param int   $per_page Rows per page.
	 * @return array{rows:array,total:int}
	 */
	public static function find( array $f, $page = 1, $per_page = 50 ) {
		global $wpdb;
		$table = AITP_Schema::table( 'audit' );
		$where = self::where( $f );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where" );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE $where ORDER BY FIELD(severity,'error','suspect','review'), lang, row_id LIMIT %d OFFSET %d", $per_page, max( 0, ( $page - 1 ) * $per_page ) ), ARRAY_A );
		// phpcs:enable
		return array( 'rows' => self::with_text( $rows ), 'total' => $total );
	}

	/**
	 * @param array $rows Findings.
	 * @return array Findings with original, translated, status of the current row.
	 */
	private static function with_text( array $rows ) {
		$need = array();
		foreach ( $rows as $r ) {
			$need[ $r['kind'] . '|' . $r['lang'] ][] = (int) $r['row_id'];
		}
		$cur = array();
		foreach ( $need as $k => $ids ) {
			list( $kind, $lang ) = explode( '|', $k );
			$t                   = self::table_for( $kind, $lang );
			$cur[ $k ]           = $t ? self::rows( $t, $ids ) : array();
		}
		foreach ( $rows as &$r ) {
			$c              = isset( $cur[ $r['kind'] . '|' . $r['lang'] ][ (int) $r['row_id'] ] ) ? $cur[ $r['kind'] . '|' . $r['lang'] ][ (int) $r['row_id'] ] : null;
			$r['original']  = $c ? (string) $c['original'] : '';
			$r['translated'] = $c ? (string) $c['translated'] : '';
			$r['status']    = $c ? (int) $c['status'] : -1;
			$r['changed']   = ! $c || ( $r['state'] === 'open' && AITP_Audit_Logic::reset_block( $r, $c ) !== '' );
		}
		unset( $r );
		return $rows;
	}

	/**
	 * Finding ids for an action: explicit ids, or every match of the filters (capped).
	 *
	 * @param array $f Filters (may hold ids).
	 * @return int[]
	 */
	public static function ids_for( array $f ) {
		global $wpdb;
		$table = AITP_Schema::table( 'audit' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return array_map( 'intval', (array) $wpdb->get_col( "SELECT id FROM $table WHERE " . self::where( $f ) . ' ORDER BY id LIMIT ' . AITP_Audit_Logic::MAX_ACTION_ROWS ) );
	}

	/**
	 * @param int[] $ids Finding ids.
	 * @return array Findings.
	 */
	private static function findings( array $ids ) {
		global $wpdb;
		$ids = array_slice( array_values( array_filter( array_map( 'intval', $ids ) ) ), 0, AITP_Audit_Logic::MAX_ACTION_ROWS );
		if ( ! $ids ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results( 'SELECT * FROM ' . AITP_Schema::table( 'audit' ) . ' WHERE id IN (' . implode( ',', $ids ) . ')', ARRAY_A );
	}

	/**
	 * Mark findings as not a problem (kept until the row changes).
	 *
	 * @param int[]  $ids   Finding ids.
	 * @param string $state dismissed|open.
	 * @return int
	 */
	public static function set_finding_state( array $ids, $state ) {
		global $wpdb;
		$ids = array_slice( array_values( array_filter( array_map( 'intval', $ids ) ) ), 0, AITP_Audit_Logic::MAX_ACTION_ROWS );
		if ( ! $ids || ! in_array( $state, array( 'dismissed', 'open' ), true ) ) {
			return 0;
		}
		$from = $state === 'open' ? 'dismissed' : 'open';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . AITP_Schema::table( 'audit' ) . " SET state = %s WHERE state = %s AND id IN (" . implode( ',', $ids ) . ')', $state, $from ) );
	}

	/* -------------------------------------------------------------------------------------
	 * Import of an external candidate list.
	 * ----------------------------------------------------------------------------------- */

	/**
	 * Match a candidate list against TranslatePress and record what still applies.
	 * A candidate counts only when id, original and old translation all match the
	 * current row and the row is still machine translated.
	 *
	 * @param string $content File content (JSON or CSV).
	 * @return array{read:int,bad:int,added:int,manual:int,changed:int,missing:int,untranslated:int}
	 */
	public static function import( $content ) {
		$p   = AITP_Audit_Logic::parse_import( $content );
		$out = array( 'read' => count( $p['rows'] ) + $p['bad'], 'bad' => $p['bad'], 'added' => 0, 'manual' => 0, 'changed' => 0, 'missing' => 0, 'untranslated' => 0, 'accepted' => 0 );
		$by  = array();
		foreach ( $p['rows'] as $c ) {
			$by[ $c['table'] . '|' . $c['lang'] ][] = $c;
		}
		$v    = new AITP_Validator( AITP_Settings::rules() );
		$scan = (int) self::state()['scan'];
		foreach ( $by as $k => $list ) {
			list( $kind, $lang ) = explode( '|', $k );
			$t                   = self::table_for( $kind, $lang );
			if ( ! $t ) {
				$out['missing'] += count( $list );
				continue;
			}
			foreach ( array_chunk( $list, 500 ) as $chunk ) {
				$cur = self::rows( $t, wp_list_pluck( $chunk, 'id' ) );
				$acc = self::accepted( $t['kind'], $t['lang'], wp_list_pluck( $chunk, 'id' ) );
				foreach ( $chunk as $c ) {
					$r     = isset( $cur[ $c['id'] ] ) ? $cur[ $c['id'] ] : null;
					$block = AITP_Audit_Logic::reset_block( array( 'original_hash' => sha1( $c['original'] ), 'translated_hash' => sha1( $c['old'] ) ), $r, isset( $acc[ $c['id'] ] ) ? $acc[ $c['id'] ] : array() );
					if ( $block !== '' ) {
						$out[ $block ]++;
						continue;
					}
					$f = AITP_Audit_Logic::check_row( $v, (string) $r['original'], $r['translated'], self::source_info( $kind ), AITP_Languages::info( $lang ) );
					if ( ! $f ) {
						$f = array( 'severity' => AITP_Audit_Logic::SUSPECT, 'codes' => array(), 'details' => array() );
					}
					$f['codes'][] = 'imported';
					self::record( $t, $r, $f, 'import', $scan );
					$out['added']++;
				}
			}
		}
		( new AITP_WP_Log() )->log( 'info', 'audit_import', $out );
		return $out;
	}

	/* -------------------------------------------------------------------------------------
	 * Accepted list: translations people have reviewed. Listed by the check, never repaired.
	 * ----------------------------------------------------------------------------------- */

	/**
	 * Accepted entries of some rows.
	 *
	 * @param string $kind dictionary|gettext.
	 * @param string $lang Language.
	 * @param int[]  $ids  Row ids.
	 * @return array<int,array<int,array{0:string,1:string}>> row id => [[original_hash, translated_hash]...]
	 */
	private static function accepted( $kind, $lang, array $ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$out = array();
		if ( ! $ids ) {
			return $out;
		}
		$table = AITP_Schema::table( 'protect' );
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT row_id, original_hash, translated_hash FROM $table WHERE kind = %s AND lang = %s AND row_id IN (" . implode( ',', $chunk ) . ')', $kind, $lang ), ARRAY_A );
			foreach ( $rows as $a ) {
				$out[ (int) $a['row_id'] ][] = array( $a['original_hash'], $a['translated_hash'] );
			}
		}
		return $out;
	}

	/**
	 * Add reviewed translations to the accepted list. Only entries whose id, original and
	 * text still match the current row are kept; manual translations are protected anyway.
	 *
	 * @param string $content File content (JSON or CSV): language, id, original, translated (or new).
	 * @param string $label   Name of the list (shown in the admin page).
	 * Rows are found by id; when the id points elsewhere (copied site, other database), a
	 * page-text row is found by its exact original instead — only when exactly one row has it.
	 *
	 * @return array{read:int,bad:int,added:int,by_original:int,already:int,manual:int,changed:int,missing:int,untranslated:int,duplicate:int}
	 */
	public static function protect( $content, $label ) {
		global $wpdb;
		$label = substr( sanitize_text_field( (string) $label ), 0, 64 );
		$label = $label !== '' ? $label : gmdate( 'Y-m-d H:i' );
		$p     = AITP_Audit_Logic::parse_import( $content, array( 'new', 'translated', 'translation', 'text', 'old' ) );
		$out   = array( 'read' => count( $p['rows'] ) + $p['bad'], 'bad' => $p['bad'], 'added' => 0, 'by_original' => 0, 'already' => 0, 'manual' => 0, 'changed' => 0, 'missing' => 0, 'untranslated' => 0, 'duplicate' => 0 );
		$table = AITP_Schema::table( 'protect' );
		$by    = array();
		foreach ( $p['rows'] as $c ) {
			$by[ $c['table'] . '|' . $c['lang'] ][] = $c;
		}
		foreach ( $by as $k => $list ) {
			list( $kind, $lang ) = explode( '|', $k );
			$t                   = self::table_for( $kind, $lang );
			if ( ! $t ) {
				$out['missing'] += count( $list );
				continue;
			}
			foreach ( array_chunk( $list, 500 ) as $chunk ) {
				$cur = self::rows( $t, wp_list_pluck( $chunk, 'id' ) );
				foreach ( $chunk as $c ) {
					$r  = isset( $cur[ $c['id'] ] ) ? $cur[ $c['id'] ] : null;
					$by = 'id';
					if ( ( ! $r || (string) $r['original'] !== $c['original'] ) && $t['kind'] === 'dictionary' ) {
						$m = self::rows_by_original( $t, $c['original'] );
						if ( count( $m ) > 1 ) {
							$out['duplicate']++;
							continue;
						}
						$r  = $m ? $m[0] : null;
						$by = 'original';
					}
					if ( ! $r || (string) $r['original'] !== $c['original'] ) {
						$out['missing']++;
						continue;
					}
					if ( (string) $r['translated'] !== $c['old'] ) {
						$out['changed']++;
						continue;
					}
					if ( (int) $r['status'] === 2 ) {
						$out['manual']++;
						continue;
					}
					if ( (int) $r['status'] !== 1 ) {
						$out['untranslated']++;
						continue;
					}
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$n = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO $table (kind, lang, row_id, original_hash, translated_hash, label, created_at) VALUES (%s, %s, %d, %s, %s, %s, %s)", $t['kind'], $t['lang'], (int) $r['id'], sha1( (string) $r['original'] ), sha1( (string) $r['translated'] ), $label, current_time( 'mysql', true ) ) );
					$out[ $n ? 'added' : 'already' ]++;
					if ( $n && $by === 'original' ) {
						$out['by_original']++;
					}
				}
			}
		}
		self::sync_accepted();
		( new AITP_WP_Log() )->log( 'info', 'audit_protect', array( 'label' => $label ) + $out );
		return $out;
	}

	/**
	 * Page-text rows with exactly this original (binary compare).
	 *
	 * @param array  $t        Table entry.
	 * @param string $original Original text.
	 * @return array[] Up to 2 rows (enough to tell unique from duplicate).
	 */
	private static function rows_by_original( array $t, $original ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( self::select_rows( $t ) . ' WHERE tt.original = %s AND BINARY tt.original = BINARY %s LIMIT 2', $original, $original ), ARRAY_A );
	}

	/**
	 * Remove one accepted list.
	 *
	 * @param string $label List name.
	 * @return int Entries removed.
	 */
	public static function unprotect( $label ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$n = (int) $wpdb->delete( AITP_Schema::table( 'protect' ), array( 'label' => (string) $label ) );
		self::sync_accepted();
		( new AITP_WP_Log() )->log( 'notice', 'audit_unprotect', array( 'label' => $label, 'removed' => $n ) );
		return $n;
	}

	/** @return array<int,array{label:string,n:int,created:string}> */
	public static function protected_lists() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( 'SELECT label, COUNT(*) n, MIN(created_at) created FROM ' . AITP_Schema::table( 'protect' ) . ' GROUP BY label ORDER BY created', ARRAY_A );
	}

	/** Bring finding states in line with the accepted list. */
	private static function sync_accepted() {
		global $wpdb;
		$a = AITP_Schema::table( 'audit' );
		$p = AITP_Schema::table( 'protect' );
		$j = "$p p ON p.kind = a.kind AND p.lang = a.lang AND p.row_id = a.row_id AND p.original_hash = a.original_hash AND p.translated_hash = a.translated_hash";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "UPDATE $a a JOIN $j SET a.state = 'accepted' WHERE a.state = 'open'" );
		$wpdb->query( "UPDATE $a a LEFT JOIN $j SET a.state = 'open' WHERE a.state = 'accepted' AND p.id IS NULL" );
		// phpcs:enable
	}

	/* -------------------------------------------------------------------------------------
	 * Repair and rollback.
	 * ----------------------------------------------------------------------------------- */

	/**
	 * Repair batches, newest first: the stored batch records, plus every batch that has backup
	 * rows but no complete record (a repair cut off before it finished, or a record lost).
	 * Such batches carry 'incomplete' => true; download and rollback work from the backup rows.
	 *
	 * @return array<int,array> batch id => batch info.
	 */
	public static function batches() {
		global $wpdb;
		$b = get_option( self::BATCHES, array() );
		$b = is_array( $b ) ? $b : array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( 'SELECT batch, COUNT(*) AS n, MIN(created_at) AS t, SUM(restored = \'yes\') AS yes, SUM(restored = \'\') AS todo, GROUP_CONCAT(DISTINCT lang ORDER BY lang) AS langs FROM ' . AITP_Schema::table( 'repair' ) . ' GROUP BY batch', ARRAY_A );
		foreach ( $rows as $r ) {
			$id = (int) $r['batch'];
			if ( isset( $b[ $id ] ) && ( ! isset( $b[ $id ]['state'] ) || $b[ $id ]['state'] === 'done' ) ) {
				continue;
			}
			$info = isset( $b[ $id ] ) ? $b[ $id ] : array(
				'time'    => (int) strtotime( $r['t'] . ' UTC' ),
				'user'    => 0,
				'skipped' => array(),
				'pages'   => 0,
			);
			$info['incomplete'] = true;
			$info['reset']      = (int) $r['n'];
			$info['langs']      = (string) $r['langs'];
			if ( (int) $r['todo'] === 0 && empty( $info['rolled_back'] ) ) {
				$info['rolled_back'] = (int) strtotime( $r['t'] . ' UTC' ); // Every row handled by a rollback whose record is missing.
				$info['restored']    = (int) $r['yes'];
			}
			$b[ $id ] = $info;
		}
		krsort( $b );
		return $b;
	}

	/**
	 * @param int   $id   Batch id.
	 * @param array $info Batch info.
	 */
	private static function save_batch( $id, array $info ) {
		$b        = get_option( self::BATCHES, array() );
		$b        = is_array( $b ) ? $b : array();
		$b[ $id ] = $info;
		if ( count( $b ) > 200 ) {
			ksort( $b );
			$b = array_slice( $b, -200, null, true );
		}
		update_option( self::BATCHES, $b, false );
	}

	/**
	 * Back up and reset findings to "not translated", then queue their pages.
	 *
	 * Only rows on a known page are reset: a page TranslatePress links the string to
	 * (post content) or a mapped page that shows it. Other rows are skipped as "no_page"
	 * (the finding stays open) unless $opts['unlinked'] is set; then every page of that
	 * language is queued again.
	 *
	 * @param int[] $ids  Finding ids (at most MAX_ACTION_ROWS are used).
	 * @param array $opts unlinked: also reset rows on no known page.
	 * @return array{batch:int,reset:int,skipped:array<string,int>,pages:int,all_pages:string[],urls:array,no_page:int[]}
	 */
	public static function repair( array $ids, array $opts = array() ) {
		global $wpdb;
		$batch    = (int) round( microtime( true ) * 1000 );
		$unlinked = ! empty( $opts['unlinked'] );
		$out      = array( 'batch' => $batch, 'reset' => 0, 'skipped' => array(), 'pages' => 0, 'all_pages' => array(), 'urls' => array(), 'no_page' => array() );
		if ( ! AITP_Plugin::engine_selected() ) {
			// Reset rows would be translated again by whatever engine TranslatePress uses.
			$out['skipped']['engine_not_selected'] = count( $ids );
			return $out;
		}
		// Finish even when the browser goes away; the batch record is written before any change.
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$allowed  = isset( $opts['pages'] ) && is_array( $opts['pages'] ) && $opts['pages'] ? AITP_Audit_Logic::page_keys( $opts['pages'] ) : null;
		$audit    = AITP_Schema::table( 'audit' );
		$repair   = AITP_Schema::table( 'repair' );
		$started  = false;
		$pages_of = array(); // row key => pages to visit for the row.
		$purge_of = array(); // row key => every known page showing the row.
		$all_lang = array(); // Languages with reset rows on no known page.
		$failures = new AITP_WP_Failures();
		$skip     = function ( $why, $fid = 0 ) use ( &$out, $wpdb, $audit ) {
			$out['skipped'][ $why ] = ( isset( $out['skipped'][ $why ] ) ? $out['skipped'][ $why ] : 0 ) + 1;
			if ( $fid && in_array( $why, array( 'changed', 'manual', 'missing', 'untranslated' ), true ) ) {
				$wpdb->update( $audit, array( 'state' => 'changed' ), array( 'id' => $fid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
		};
		$by = array();
		foreach ( self::findings( $ids ) as $f ) {
			if ( $f['state'] !== 'open' ) {
				$skip( $f['state'] === 'accepted' ? 'accepted' : 'not_open' );
				continue;
			}
			$by[ $f['kind'] . '|' . $f['lang'] ][] = $f;
		}
		foreach ( $by as $k => $list ) {
			list( $kind, $lang ) = explode( '|', $k );
			$t                   = self::table_for( $kind, $lang );
			if ( ! $t ) {
				foreach ( $list as $f ) {
					$skip( 'missing', (int) $f['id'] );
				}
				continue;
			}
			$cur = self::rows( $t, wp_list_pluck( $list, 'row_id' ) );
			$acc = self::accepted( $kind, $lang, wp_list_pluck( $list, 'row_id' ) );
			$src   = $kind === 'gettext' ? 'en_US' : self::default_language();
			$pages = self::row_pages( $t, $cur );
			foreach ( $list as $f ) {
				$r     = isset( $cur[ (int) $f['row_id'] ] ) ? $cur[ (int) $f['row_id'] ] : null;
				$block = AITP_Audit_Logic::reset_block( $f, $r, isset( $acc[ (int) $f['row_id'] ] ) ? $acc[ (int) $f['row_id'] ] : array() );
				if ( $block === 'accepted' ) {
					$skip( 'accepted' );
					$wpdb->update( $audit, array( 'state' => 'accepted' ), array( 'id' => (int) $f['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					continue;
				}
				if ( $block !== '' ) {
					$skip( $block, (int) $f['id'] );
					continue;
				}
				$known = isset( $pages[ (int) $r['id'] ] ) ? $pages[ (int) $r['id'] ] : array();
				$rp    = AITP_Audit_Logic::visit_pages( $known, $allowed );
				if ( ! $rp && $allowed !== null && $known ) {
					$skip( 'outside_pages' ); // Shown only on pages outside the given list: left open.
					continue;
				}
				if ( ! $rp && ( ! $unlinked || $allowed !== null ) ) {
					$skip( 'no_page' ); // The finding stays open: map its page first, or opt in.
					$out['no_page'][] = (int) $f['id'];
					continue;
				}
				if ( ! $started ) {
					// Recorded before the first row changes: a cut-off repair can still be rolled back.
					self::save_batch(
						$batch,
						array(
							'time'      => time(),
							'user'      => get_current_user_id(),
							'state'     => 'running',
							'reset'     => 0,
							'skipped'   => array(),
							'pages'     => 0,
							'urls'      => array(),
							'all_pages' => array(),
						)
					);
					$started = true;
				}
				// phpcs:disable WordPress.DB.DirectDatabaseQuery
				$wpdb->insert(
					$repair,
					array(
						'batch'      => $batch,
						'kind'       => $kind,
						'lang'       => $lang,
						'row_id'     => (int) $r['id'],
						'finding_id' => (int) $f['id'],
						'original'   => (string) $r['original'],
						'translated' => (string) $r['translated'],
						'status'     => (int) $r['status'],
						'created_at' => current_time( 'mysql', true ),
					)
				);
				$backup_id = (int) $wpdb->insert_id;
				if ( ! $backup_id ) {
					$skip( 'backup_failed' );
					continue;
				}
				$empty = $kind === 'gettext' ? "''" : 'NULL';
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$n = $wpdb->query( $wpdb->prepare( "UPDATE `{$t['table']}` SET translated = $empty, status = 0 WHERE id = %d AND status = 1 AND BINARY translated = BINARY %s", (int) $r['id'], (string) $r['translated'] ) );
				if ( $n !== 1 ) {
					$wpdb->delete( $repair, array( 'id' => $backup_id ) );
					$skip( 'changed', (int) $f['id'] );
					continue;
				}
				$wpdb->update( $audit, array( 'state' => 'fixed', 'batch' => $batch ), array( 'id' => (int) $f['id'] ) );
				// phpcs:enable
				$failures->clear( AITP_Translator::failure_key( (string) $r['original'], $src, $lang ) );
				if ( $rp ) {
					$pages_of[ $kind . '|' . $lang . '|' . (int) $r['id'] ] = $rp;
					$purge_of[ $kind . '|' . $lang . '|' . (int) $r['id'] ] = $known;
				} else {
					$all_lang[ $lang ] = true;
				}
				$out['reset']++;
			}
		}
		if ( $out['reset'] ) {
			$visit        = AITP_Audit_Logic::cover_pages( $pages_of );
			$out['urls']  = array_values( array_map( function ( $p ) { return array( 'url' => $p['url'], 'lang' => $p['lang'] ); }, $visit ) );
			$out['pages'] = AITP_Queue::requeue_urls( array_values( $visit ) );
			foreach ( array_keys( $all_lang ) as $lang ) {
				$out['all_pages'][] = $lang;
				$out['pages']      += AITP_Queue::requeue_lang( $lang, null );
			}
			self::save_batch(
				$batch,
				array(
					'time'      => time(),
					'user'      => get_current_user_id(),
					'state'     => 'done',
					'reset'     => $out['reset'],
					'skipped'   => $out['skipped'],
					'pages'     => $out['pages'],
					'urls'      => $out['urls'],
					'all_pages' => $out['all_pages'],
				)
			);
			// Cached copies of every page showing the rows: cleared in the background, in small steps.
			$out['purge'] = AITP_Cache_Purge::urls( self::all_urls( $purge_of ) );
		}
		( new AITP_WP_Log() )->log( 'notice', 'repair', $out );
		return $out;
	}

	/**
	 * @param array $pages_of row key => pages.
	 * @return string[] Every address showing one of the rows.
	 */
	private static function all_urls( array $pages_of ) {
		$urls = array();
		foreach ( $pages_of as $list ) {
			foreach ( $list as $p ) {
				$urls[ $p['url'] ] = true;
			}
		}
		return array_keys( $urls );
	}

	/**
	 * Known pages that show some rows: pages TranslatePress links the string to (post
	 * content, via post_parent_id) and mapped pages (see AITP_Pagemap). Interface text
	 * (gettext) has no known page.
	 *
	 * @param array $t    Table entry.
	 * @param array $rows id => row (with original_id).
	 * @return array<int,array<string,array{url:string,source_url:string,lang:string,object_type:string,object_id:int}>> row id => key => page
	 */
	public static function row_pages( array $t, array $rows ) {
		$out = array();
		if ( $t['kind'] !== 'dictionary' || ! $rows ) {
			return $out;
		}
		$lang = $t['lang'];
		$add  = function ( $row_id, array $p, $from ) use ( &$out ) {
			$k = sha1( $p['source_url'] . '|' . $p['lang'] );
			$p['from'] = isset( $out[ (int) $row_id ][ $k ]['from'] ) && $out[ (int) $row_id ][ $k ]['from'] === 'map' ? 'map' : $from;
			$out[ (int) $row_id ][ $k ] = $p;
		};
		$by_original = array();
		foreach ( $rows as $id => $r ) {
			$by_original[ (int) $r['original_id'] ][] = (int) $id;
		}
		foreach ( self::meta_posts( array_keys( $by_original ) ) as $oid => $posts ) {
			foreach ( $posts as $post_id ) {
				$p = self::post_page( $lang, $post_id );
				if ( $p ) {
					foreach ( $by_original[ $oid ] as $row_id ) {
						$add( $row_id, $p, 'meta' );
					}
				}
			}
		}
		foreach ( AITP_Pagemap::pages_of_rows( $lang, array_keys( $rows ) ) as $row_id => $list ) {
			foreach ( $list as $p ) {
				$add( $row_id, $p, 'map' );
			}
		}
		return $out;
	}

	/**
	 * Posts TranslatePress links some strings to.
	 *
	 * @param int[] $original_ids TranslatePress original ids.
	 * @return array<int,int[]> original id => post ids
	 */
	private static function meta_posts( array $original_ids ) {
		global $wpdb;
		$meta = self::meta_table();
		$ids  = array_values( array_unique( array_filter( array_map( 'intval', $original_ids ) ) ) );
		$out  = array();
		if ( $meta === '' || ! $ids ) {
			return $out;
		}
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$rows = (array) $wpdb->get_results( "SELECT DISTINCT original_id, meta_value FROM `$meta` WHERE meta_key = 'post_parent_id' AND original_id IN (" . implode( ',', $chunk ) . ')', ARRAY_A );
			foreach ( $rows as $r ) {
				if ( (int) $r['meta_value'] > 0 ) {
					$out[ (int) $r['original_id'] ][] = (int) $r['meta_value'];
				}
			}
		}
		return $out;
	}

	/**
	 * Translated page of a published post (from the queue's page list, else its permalink).
	 *
	 * @param string $lang    Language.
	 * @param int    $post_id Post.
	 * @return array|null
	 */
	private static function post_page( $lang, $post_id ) {
		global $wpdb;
		static $cache = array();
		$k = $lang . '|' . $post_id;
		if ( array_key_exists( $k, $cache ) ) {
			return $cache[ $k ];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$q = $wpdb->get_row( $wpdb->prepare( 'SELECT source_url, url FROM ' . AITP_Schema::table( 'queue' ) . " WHERE lang = %s AND object_type = 'post' AND object_id = %d LIMIT 1", $lang, (int) $post_id ), ARRAY_A );
		$p = null;
		if ( $q ) {
			$p = array( 'url' => (string) $q['url'], 'source_url' => (string) $q['source_url'], 'lang' => $lang, 'object_type' => 'post', 'object_id' => (int) $post_id );
		} elseif ( get_post_status( $post_id ) === 'publish' && ! post_password_required( $post_id ) ) {
			$src  = (string) get_permalink( $post_id );
			$turl = $src !== '' ? AITP_Queue::translated_url( $src, $lang ) : '';
			if ( $turl !== '' && $turl !== $src ) {
				$p = array( 'url' => $turl, 'source_url' => $src, 'lang' => $lang, 'object_type' => 'post', 'object_id' => (int) $post_id );
			}
		}
		$cache[ $k ] = $p;
		return $p;
	}

	/**
	 * Restore a repair batch from its backup.
	 *
	 * @param int $batch Batch id.
	 * @return array{restored:int,skipped:array<string,int>}
	 */
	public static function rollback( $batch ) {
		global $wpdb;
		$repair = AITP_Schema::table( 'repair' );
		$audit  = AITP_Schema::table( 'audit' );
		$out    = array( 'restored' => 0, 'skipped' => array() );
		ignore_user_abort( true );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		// Rows already handled by an earlier (perhaps cut-off) rollback are skipped: running it again finishes it.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $repair WHERE batch = %d AND restored = ''", (int) $batch ), ARRAY_A );
		$by   = array();
		foreach ( $rows as $b ) {
			$by[ $b['kind'] . '|' . $b['lang'] ][] = $b;
		}
		$restored = array();
		foreach ( $by as $k => $list ) {
			list( $kind, $lang ) = explode( '|', $k );
			$t                   = self::table_for( $kind, $lang );
			$cur                 = $t ? self::rows( $t, wp_list_pluck( $list, 'row_id' ) ) : array();
			foreach ( $list as $b ) {
				$r     = isset( $cur[ (int) $b['row_id'] ] ) ? $cur[ (int) $b['row_id'] ] : null;
				$block = $t ? AITP_Audit_Logic::restore_block( $b, $r ) : 'missing';
				if ( $block === '' ) {
					$cond = $r['translated'] === null ? 'translated IS NULL' : $wpdb->prepare( 'BINARY translated = BINARY %s', (string) $r['translated'] );
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
					$n = $wpdb->query( $wpdb->prepare( "UPDATE `{$t['table']}` SET translated = %s, status = %d WHERE id = %d AND status = %d AND $cond", (string) $b['translated'], (int) $b['status'], (int) $b['row_id'], (int) $r['status'] ) );
					if ( $n !== 1 && (string) $r['translated'] === (string) $b['translated'] && (int) $r['status'] === (int) $b['status'] ) {
						$n = 1; // Already identical to the backup (MySQL reports 0 affected rows): nothing to undo.
					}
					$block = $n === 1 ? '' : 'changed';
				}
				if ( $block !== '' ) {
					$out['skipped'][ $block ] = ( isset( $out['skipped'][ $block ] ) ? $out['skipped'][ $block ] : 0 ) + 1;
					$wpdb->update( $repair, array( 'restored' => 'skip:' . $block ), array( 'id' => (int) $b['id'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					continue;
				}
				// phpcs:disable WordPress.DB.DirectDatabaseQuery
				$wpdb->update( $repair, array( 'restored' => 'yes' ), array( 'id' => (int) $b['id'] ) );
				$wpdb->update( $audit, array( 'state' => 'open', 'batch' => 0, 'original_hash' => sha1( (string) $b['original'] ), 'translated_hash' => sha1( (string) $b['translated'] ) ), array( 'id' => (int) $b['finding_id'] ) );
				// phpcs:enable
				if ( $r && $t ) {
					$restored[ $kind . '|' . $lang ][ (int) $r['id'] ] = $r;
				}
				$out['restored']++;
			}
		}
		$info = self::batches();
		if ( isset( $info[ $batch ] ) ) {
			$info[ $batch ]['rolled_back']      = time();
			$info[ $batch ]['restored']         = ( isset( $info[ $batch ]['restored'] ) ? (int) $info[ $batch ]['restored'] : 0 ) + $out['restored'];
			$info[ $batch ]['restore_skipped'] = $out['skipped'];
			self::save_batch( $batch, $info[ $batch ] );
		}
		if ( $restored ) {
			// Restored rows are translated: nothing to queue, only cached copies to refresh (in the background).
			$pages_of = array();
			foreach ( $restored as $k => $rows ) {
				list( $kind, $lang ) = explode( '|', $k );
				foreach ( self::row_pages( self::table_for( $kind, $lang ), $rows ) as $id => $list ) {
					$pages_of[ $k . '|' . $id ] = $list;
				}
			}
			$out['purge'] = AITP_Cache_Purge::urls( self::all_urls( $pages_of ) );
		}
		( new AITP_WP_Log() )->log( 'notice', 'rollback', array( 'batch' => $batch ) + $out );
		return $out;
	}

	/**
	 * Backup rows of a batch (for download).
	 *
	 * @param int $batch Batch id.
	 * @return array
	 */
	public static function backup_rows( $batch ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT kind, lang, row_id, original, translated, status, restored, created_at FROM ' . AITP_Schema::table( 'repair' ) . ' WHERE batch = %d ORDER BY id', (int) $batch ), ARRAY_A );
	}

	/* -------------------------------------------------------------------------------------
	 * Helpers of the batch auto repair (AITP_Autofix).
	 * ----------------------------------------------------------------------------------- */

	/**
	 * Add fields to the stored info of a repair batch.
	 *
	 * @param int   $batch Batch id.
	 * @param array $extra Fields.
	 */
	public static function tag_batch( $batch, array $extra ) {
		$b = self::batches();
		if ( isset( $b[ $batch ] ) ) {
			self::save_batch( $batch, array_merge( $b[ $batch ], $extra ) );
		}
	}

	/**
	 * @return string TranslatePress table linking strings to the posts they appear on ('' if none).
	 */
	private static function meta_table() {
		$q    = self::tp_query();
		$meta = $q && method_exists( $q, 'get_table_name_for_original_meta' ) ? (string) $q->get_table_name_for_original_meta() : '';
		return $meta !== '' && self::exists( $meta ) ? $meta : '';
	}

	/**
	 * The next batch for the auto repair: open "wrong" findings of page text in one
	 * language (the first of $langs that has any), whose rows are on a known page (see
	 * row_pages()), so the batch can be translated again by visiting just those pages.
	 * Structured data and strings without such a page are left for a person.
	 *
	 * @param string[] $langs Languages in order (empty = all).
	 * @param int      $limit Rows.
	 * @return array{lang:string,ids:int[],structured:int,no_page:int} ids: finding ids.
	 */
	public static function autofix_pick( array $langs, $limit ) {
		global $wpdb;
		$audit = AITP_Schema::table( 'audit' );
		$out   = array( 'lang' => '', 'ids' => array(), 'structured' => 0, 'no_page' => 0 );
		if ( ! $langs ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$langs = (array) $wpdb->get_col( "SELECT DISTINCT lang FROM $audit WHERE state = 'open' AND severity = 'error' AND kind = 'dictionary' AND NOT FIND_IN_SET('auto_retried', codes) ORDER BY lang" );
		}
		foreach ( $langs as $lang ) {
			$t = self::table_for( 'dictionary', $lang );
			if ( ! $t ) {
				continue;
			}
			$last = 0;
			$skip = array( 'structured' => 0, 'no_page' => 0 );
			while ( count( $out['ids'] ) < $limit ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$list = (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, row_id FROM $audit WHERE state = 'open' AND severity = 'error' AND kind = 'dictionary' AND lang = %s AND id > %d AND NOT FIND_IN_SET('auto_retried', codes) ORDER BY id LIMIT 1000", $t['lang'], $last ), ARRAY_A );
				if ( ! $list ) {
					break;
				}
				$last  = (int) end( $list )['id'];
				$cur   = self::rows( $t, wp_list_pluck( $list, 'row_id' ) );
				$pages = self::row_pages( $t, $cur );
				foreach ( $list as $f ) {
					$r = isset( $cur[ (int) $f['row_id'] ] ) ? $cur[ (int) $f['row_id'] ] : null;
					if ( ! $r ) {
						continue; // Gone: repair() will mark it.
					}
					if ( AITP_Text::is_structured_data( (string) $r['original'] ) ) {
						$skip['structured']++;
						continue;
					}
					if ( empty( $pages[ (int) $r['id'] ] ) ) {
						$skip['no_page']++;
						continue;
					}
					if ( count( $out['ids'] ) < $limit ) {
						$out['ids'][] = (int) $f['id'];
					}
				}
			}
			if ( $out['ids'] ) {
				$out['lang']       = $t['lang'];
				$out['structured'] = $skip['structured'];
				$out['no_page']    = $skip['no_page'];
				return $out;
			}
			$out['structured'] += $skip['structured'];
			$out['no_page']    += $skip['no_page'];
		}
		return $out;
	}

	/**
	 * Translated addresses of the pages that show the rows of a repair batch.
	 *
	 * @param int $batch Batch id.
	 * @return array<int,array{url:string,lang:string}>
	 */
	public static function batch_urls( $batch ) {
		global $wpdb;
		$info = self::batches();
		if ( ! empty( $info[ $batch ]['urls'] ) ) {
			return (array) $info[ $batch ]['urls']; // Chosen when the batch was reset.
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT kind, lang, row_id FROM ' . AITP_Schema::table( 'repair' ) . ' WHERE batch = %d', (int) $batch ), ARRAY_A );
		$by   = array();
		foreach ( $rows as $b ) {
			$by[ $b['kind'] . '|' . $b['lang'] ][] = (int) $b['row_id'];
		}
		$pages_of = array();
		foreach ( $by as $k => $ids ) {
			list( $kind, $lang ) = explode( '|', $k );
			$t                   = self::table_for( $kind, $lang );
			if ( ! $t ) {
				continue;
			}
			foreach ( self::row_pages( $t, self::rows( $t, $ids ) ) as $id => $list ) {
				$pages_of[ $k . '|' . $id ] = AITP_Audit_Logic::visit_pages( $list, null );
			}
		}
		$out = array();
		foreach ( AITP_Audit_Logic::cover_pages( $pages_of ) as $p ) {
			$out[] = array( 'url' => $p['url'], 'lang' => $p['lang'] );
		}
		return $out;
	}

	/**
	 * State of the rows of a repair batch now.
	 *
	 * With $judge the new translations are checked with the audit rules and recorded as
	 * findings again when still wrong (so they show up in the list and are never picked
	 * by the auto repair twice without a new check).
	 *
	 * @param int  $batch Batch id.
	 * @param bool $judge Check new translations.
	 * @return array{reset:int,ok:int,wrong:int,english:int,untranslated:int,manual:int,gone:int}
	 */
	public static function batch_check( $batch, $judge ) {
		global $wpdb;
		$out = array( 'reset' => 0, 'ok' => 0, 'wrong' => 0, 'english' => 0, 'untranslated' => 0, 'manual' => 0, 'gone' => 0 );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT kind, lang, row_id, original FROM ' . AITP_Schema::table( 'repair' ) . ' WHERE batch = %d', (int) $batch ), ARRAY_A );
		$by   = array();
		foreach ( $rows as $b ) {
			$by[ $b['kind'] . '|' . $b['lang'] ][] = $b;
		}
		$v    = $judge ? new AITP_Validator( AITP_Settings::rules() ) : null;
		$scan = (int) self::state()['scan'];
		foreach ( $by as $k => $list ) {
			list( $kind, $lang ) = explode( '|', $k );
			$t                   = self::table_for( $kind, $lang );
			$cur                 = $t ? self::rows( $t, wp_list_pluck( $list, 'row_id' ) ) : array();
			foreach ( $list as $b ) {
				$out['reset']++;
				$r = isset( $cur[ (int) $b['row_id'] ] ) ? $cur[ (int) $b['row_id'] ] : null;
				if ( ! $r || (string) $r['original'] !== (string) $b['original'] ) {
					$out['gone']++;
					continue;
				}
				if ( (int) $r['status'] === 2 ) {
					$out['manual']++;
					continue;
				}
				if ( (int) $r['status'] !== 1 || $r['translated'] === null || (string) $r['translated'] === '' ) {
					$out['untranslated']++;
					continue;
				}
				if ( ! $judge ) {
					$out['ok']++;
					continue;
				}
				$f = AITP_Audit_Logic::check_row( $v, (string) $r['original'], $r['translated'], self::source_info( $kind ), AITP_Languages::info( $lang ) );
				if ( $f && $f['severity'] === AITP_Audit_Logic::ERROR ) {
					$out['wrong']++;
					if ( AITP_Autofix_Logic::is_english( $f['codes'] ) ) {
						$out['english']++;
					}
					$f['codes'][] = 'auto_retried'; // Not picked by the auto repair again; a person decides.
					self::record( $t, $r, $f, 'scan', $scan );
					continue;
				}
				$out['ok']++;
			}
		}
		return $out;
	}

	/* -------------------------------------------------------------------------------------
	 * Optional AI check.
	 * ----------------------------------------------------------------------------------- */

	/**
	 * Ask the AI whether each selected translation belongs to its original.
	 * Mismatches become errors; nothing in TranslatePress is changed.
	 *
	 * @param int[] $ids     Finding ids.
	 * @param int   $seconds Time budget.
	 * @return array{checked:int,match:int,mismatch:int,unsure:int,skipped:int,stop:string,left:int[]}
	 */
	public static function ai_check( array $ids, $seconds = 40 ) {
		global $wpdb;
		$out = array( 'checked' => 0, 'match' => 0, 'mismatch' => 0, 'unsure' => 0, 'skipped' => 0, 'stop' => '', 'left' => array() );
		if ( ! self::settings()['ai_check'] ) {
			$out['stop'] = 'disabled';
			return $out;
		}
		$provider = AITP_Settings::provider();
		$tr       = AITP_Plugin::instance()->translator();
		if ( ! $provider || ! $tr ) {
			$out['stop'] = 'not_configured';
			return $out;
		}
		$logger = null;
		if ( class_exists( 'TRP_Translate_Press' ) ) {
			$logger = TRP_Translate_Press::get_trp_instance()->get_component( 'machine_translator_logger' );
		}
		$checker = new AITP_Pair_Checker(
			$provider,
			$tr->caps(),
			array(
				'transport' => new AITP_WP_Transport(),
				'quota'     => $logger && method_exists( $logger, 'increase_counter_with_value' ) ? new AITP_TP_Quota( $logger ) : new AITP_Memory_Quota(),
				'breaker'   => AITP_Plugin::breaker(),
				'log'       => new AITP_WP_Log(),
			)
		);
		$audit    = AITP_Schema::table( 'audit' );
		$deadline = microtime( true ) + $seconds;
		$by       = array();
		foreach ( self::findings( $ids ) as $f ) {
			if ( $f['state'] === 'open' ) {
				$by[ $f['kind'] . '|' . $f['lang'] ][] = $f;
			}
		}
		foreach ( $by as $k => $list ) {
			list( $kind, $lang ) = explode( '|', $k );
			$t                   = self::table_for( $kind, $lang );
			if ( ! $t ) {
				continue;
			}
			foreach ( array_chunk( $list, 60 ) as $chunk ) {
				if ( $out['stop'] !== '' || microtime( true ) > $deadline - 10 ) {
					$out['left'] = array_merge( $out['left'], array_map( 'intval', wp_list_pluck( $chunk, 'id' ) ) );
					continue;
				}
				$cur   = self::rows( $t, wp_list_pluck( $chunk, 'row_id' ) );
				$pairs = array();
				$fmap  = array();
				foreach ( $chunk as $f ) {
					$r = isset( $cur[ (int) $f['row_id'] ] ) ? $cur[ (int) $f['row_id'] ] : null;
					if ( AITP_Audit_Logic::reset_block( $f, $r ) !== '' ) {
						$out['skipped']++;
						continue;
					}
					$pairs[ (string) $f['id'] ] = array( (string) $r['original'], (string) $r['translated'] );
					$fmap[ (string) $f['id'] ]  = $f;
				}
				$res = $checker->check( $pairs, self::source_info( $kind ), AITP_Languages::info( $lang ), $deadline );
				foreach ( $res['verdicts'] as $fid => $verdict ) {
					$f    = $fmap[ $fid ];
					$data = array( 'ai' => $verdict );
					if ( $verdict === 'mismatch' ) {
						$data['severity'] = AITP_Audit_Logic::ERROR;
						$data['codes']    = substr( implode( ',', array_unique( array_merge( array_filter( explode( ',', $f['codes'] ) ), array( 'ai_mismatch' ) ) ) ), 0, 255 );
					}
					$wpdb->update( $audit, $data, array( 'id' => (int) $fid ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$out[ $verdict ]++;
					$out['checked']++;
				}
				foreach ( array_diff( array_keys( $pairs ), array_map( 'strval', array_keys( $res['verdicts'] ) ) ) as $fid ) {
					$out['left'][] = (int) $fid;
				}
				if ( $res['stop'] !== '' && $res['stop'] !== 'deferred' ) {
					$out['stop'] = $res['stop'];
				}
			}
		}
		return $out;
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
