<?php
/**
 * Page mapping: which page-text rows each translated page shows.
 *
 * TranslatePress links a string to a post only when it sits inside the post content
 * (`post_parent_id` in its original meta table), so headings, menus, theme and page-builder
 * text are linked to no page at all. Page mapping visits a page once with a signed internal
 * request in read-only mode (no machine translation, TranslatePress saves no strings) and
 * records the rows of that language's dictionary table that the rendered page shows, matched
 * by their complete original text.
 *
 * Repairs then know exactly which pages to visit again, and selected pages can be exported
 * as a candidate list for a page-by-page comparison.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Pagemap {

	const STATE     = 'aitp_map_state';
	const LOCK      = 'aitp_map_lock';
	const CRON      = 'aitp_map_tick';
	const GAP       = 5;    // Seconds between two map visits.
	const MAX_ITEMS = 3000; // Pages x languages of one run.

	/** @var int Seconds between two map visits (WP-CLI --ignore-rate sets 0). */
	public static $gap = self::GAP;

	/** Hooks. */
	public static function boot() {
		add_action( self::CRON, array( __CLASS__, 'tick' ) );
	}

	/* -------------------------------------------------------------------------------------
	 * State.
	 * ----------------------------------------------------------------------------------- */

	/** @return array */
	public static function state() {
		wp_cache_delete( self::STATE, 'options' );
		$s = get_option( self::STATE, array() );
		return array_merge(
			array(
				'status'  => 'idle', // idle | running | stopped | done.
				'reason'  => '',
				'items'   => array(),
				'i'       => 0,
				'ok'      => 0,
				'failed'  => 0,
				'next_at' => 0,
				'started' => 0,
				'ended'   => 0,
			),
			is_array( $s ) ? $s : array()
		);
	}

	/** @param array $s State. */
	private static function save( array $s ) {
		update_option( self::STATE, $s, false );
	}

	/**
	 * Pages to map.
	 *
	 * @param string[] $urls  Default-language addresses of this site (empty = every page in
	 *                        the queue's page list).
	 * @param string[] $langs Languages (empty = all TranslatePress languages).
	 * @return array{items:array,bad:string[]}
	 */
	public static function plan( array $urls, array $langs ) {
		global $wpdb;
		$all   = AITP_Queue::tp_languages();
		$langs = $langs ? array_values( array_intersect( $all, $langs ) ) : $all;
		$out   = array( 'items' => array(), 'bad' => array() );
		$seen  = array();
		$add   = function ( array $it ) use ( &$out, &$seen ) {
			$k = $it['lang'] . '|' . $it['url'];
			if ( ! isset( $seen[ $k ] ) ) {
				$seen[ $k ]     = true;
				$out['items'][] = $it;
			}
		};
		$urls = array_values( array_filter( array_map( 'trim', $urls ), 'strlen' ) );
		if ( ! $urls ) {
			if ( ! $langs ) {
				return $out;
			}
			$ph = implode( ',', array_fill( 0, count( $langs ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$rows = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT source_url, url, lang, object_type, object_id FROM ' . AITP_Schema::table( 'queue' ) . " WHERE lang IN ($ph) ORDER BY priority, id", $langs ), ARRAY_A );
			foreach ( $rows as $r ) {
				$add(
					array(
						'url'         => (string) $r['url'],
						'source_url'  => (string) $r['source_url'],
						'lang'        => (string) $r['lang'],
						'object_type' => (string) $r['object_type'],
						'object_id'   => (int) $r['object_id'],
					)
				);
			}
			return $out;
		}
		foreach ( $urls as $u ) {
			if ( ! wp_http_validate_url( $u ) || ! AITP_Queue::same_site( $u ) ) {
				$out['bad'][] = $u;
				continue;
			}
			$obj = self::object_of( $u );
			foreach ( $langs as $lang ) {
				$turl = AITP_Queue::translated_url( $u, $lang );
				if ( $turl === '' || $turl === $u ) {
					$out['bad'][] = $u;
					continue 2;
				}
				$add(
					array(
						'url'         => $turl,
						'source_url'  => $u,
						'lang'        => $lang,
						'object_type' => $obj[0],
						'object_id'   => $obj[1],
					)
				);
			}
		}
		return $out;
	}

	/**
	 * @param string $url Default-language address.
	 * @return array{0:string,1:int} Object type and id.
	 */
	public static function object_of( $url ) {
		if ( untrailingslashit( strtok( $url, '?#' ) ) === untrailingslashit( home_url( '/' ) ) ) {
			return array( 'home', 0 );
		}
		$id = (int) url_to_postid( $url );
		return $id ? array( 'post', $id ) : array( 'url', 0 );
	}

	/**
	 * Start mapping.
	 *
	 * @param string[] $urls  See plan().
	 * @param string[] $langs See plan().
	 * @return array{error:string,pages:int,bad:string[]}
	 */
	public static function start( array $urls, array $langs ) {
		$s = self::state();
		if ( $s['status'] === 'running' ) {
			return array( 'error' => 'running', 'pages' => 0, 'bad' => array() );
		}
		if ( ! class_exists( 'TRP_Translate_Press' ) ) {
			return array( 'error' => 'no_translatepress', 'pages' => 0, 'bad' => array() );
		}
		$p = self::plan( $urls, $langs );
		if ( ! $p['items'] ) {
			return array( 'error' => 'no_pages', 'pages' => 0, 'bad' => $p['bad'] );
		}
		if ( count( $p['items'] ) > self::MAX_ITEMS ) {
			return array( 'error' => 'too_many', 'pages' => count( $p['items'] ), 'bad' => $p['bad'] );
		}
		self::save(
			array(
				'status'  => 'running',
				'reason'  => '',
				'items'   => $p['items'],
				'i'       => 0,
				'ok'      => 0,
				'failed'  => 0,
				'next_at' => 0,
				'started' => time(),
				'ended'   => 0,
			)
		);
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 5, AITP_Queue::SCHEDULE, self::CRON );
		}
		( new AITP_WP_Log() )->log( 'notice', 'map_start', array( 'pages' => count( $p['items'] ), 'bad' => count( $p['bad'] ) ) );
		return array( 'error' => '', 'pages' => count( $p['items'] ), 'bad' => $p['bad'] );
	}

	/** @param string $reason Why. */
	public static function stop( $reason = 'manual' ) {
		$s = self::state();
		if ( $s['status'] === 'running' ) {
			$s['status'] = 'stopped';
			$s['reason'] = (string) $reason;
			$s['ended']  = time();
			self::save( $s );
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

	/**
	 * Map pages for a while.
	 *
	 * @param int $seconds Wall time.
	 * @return array{result:string,wait:int,state:array} result: idle, busy, running, done, stopped.
	 */
	public static function step( $seconds ) {
		$s = self::state();
		if ( $s['status'] !== 'running' ) {
			return array( 'result' => 'idle', 'wait' => 0, 'state' => $s );
		}
		if ( ! self::lock( (int) $seconds + 120 ) ) {
			return array( 'result' => 'busy', 'wait' => 10, 'state' => $s );
		}
		$end    = time() + (int) $seconds;
		$result = 'running';
		$wait   = 0;
		$visits = 0;
		try {
			while ( true ) {
				$s = self::state();
				if ( $s['status'] !== 'running' ) {
					$result = $s['status'];
					break;
				}
				if ( (int) $s['i'] >= count( $s['items'] ) ) {
					$s['status'] = 'done';
					$s['ended']  = time();
					self::save( $s );
					wp_clear_scheduled_hook( self::CRON );
					( new AITP_WP_Log() )->log( 'notice', 'map_done', array( 'ok' => $s['ok'], 'failed' => $s['failed'] ) );
					$result = 'done';
					break;
				}
				$now = time();
				if ( $now < (int) $s['next_at'] ) {
					$w = (int) $s['next_at'] - $now;
					if ( $now + $w >= $end ) {
						$wait = $w;
						break;
					}
					sleep( $w );
					continue;
				}
				if ( $visits > 0 && time() + AITP_Queue::page_seconds() + 15 > $end ) {
					break; // Not enough time left for one more page in this request.
				}
				$visits++;
				$item = $s['items'][ (int) $s['i'] ];
				$ok   = self::map_one( $item );
				$s    = self::state();
				if ( $s['status'] !== 'running' ) {
					$result = $s['status'];
					break;
				}
				$s['i']       = (int) $s['i'] + 1;
				$s['next_at'] = time() + self::$gap;
				$s[ $ok ? 'ok' : 'failed' ]++;
				self::save( $s );
			}
		} finally {
			self::unlock();
		}
		return array( 'result' => $result, 'wait' => $wait, 'state' => self::state() );
	}

	/**
	 * Visit one page in read-only mode and store what it shows.
	 *
	 * @param array $item url, source_url, lang, object_type, object_id.
	 * @return bool Whether the page was read.
	 */
	public static function map_one( array $item ) {
		global $wpdb;
		$v     = AITP_Queue::visit( $item['url'], $item['lang'], AITP_Queue::page_seconds(), true );
		$ok    = $v['http'] >= 200 && $v['http'] < 300 && ! empty( $v['report'] );
		$rows  = $ok ? array_values( array_unique( array_map( 'intval', (array) $v['rows'] ) ) ) : array();
		$table = AITP_Schema::table( 'pagemap' );
		$ukey  = sha1( $item['lang'] . '|' . $item['url'] );
		$data  = array(
			'ukey'        => $ukey,
			'url'         => (string) $item['url'],
			'source_url'  => (string) $item['source_url'],
			'lang'        => (string) $item['lang'],
			'object_type' => (string) $item['object_type'],
			'object_id'   => (int) $item['object_id'],
			'http'        => (int) $v['http'],
			'note'        => $ok ? '' : ( $v['sig'] !== '' ? 'signature' : ( empty( $v['report'] ) && $v['http'] >= 200 && $v['http'] < 300 ? 'no_report' : 'http' ) ),
			'mapped_at'   => current_time( 'mysql', true ),
		);
		if ( $ok ) {
			$data['strings'] = (int) $v['strings'];
			$data['found']   = count( $rows );
			$data['unknown'] = (int) $v['unknown'];
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE ukey = %s", $ukey ) );
		if ( $id ) {
			$wpdb->update( $table, $data, array( 'id' => $id ) );
		} else {
			$wpdb->insert( $table, $data );
			$id = (int) $wpdb->insert_id;
		}
		if ( $ok && $id ) {
			// A failed visit keeps what an earlier visit found.
			$ps = AITP_Schema::table( 'pagestr' );
			$wpdb->delete( $ps, array( 'page_id' => $id ) );
			foreach ( array_chunk( $rows, 500 ) as $chunk ) {
				$vals = array();
				foreach ( $chunk as $r ) {
					$vals[] = $wpdb->prepare( '(%d,%s,%d)', $id, $item['lang'], $r );
				}
				$wpdb->query( "INSERT IGNORE INTO $ps (page_id, lang, row_id) VALUES " . implode( ',', $vals ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
		// phpcs:enable
		return $ok;
	}

	/* -------------------------------------------------------------------------------------
	 * Reading the map.
	 * ----------------------------------------------------------------------------------- */

	/**
	 * Mapped pages with counts.
	 *
	 * meta    = rows TranslatePress links to the post (post_parent_id), whatever renders;
	 * found   = rows the rendered page shows; s1/s2/s0 = of those, machine / manual / not
	 * translated; unknown = page strings with no row in the dictionary table.
	 *
	 * @param array $f lang, q (address contains), ids (page ids), page, per_page.
	 * @return array{total:int,rows:array}
	 */
	public static function pages( array $f = array() ) {
		global $wpdb;
		$table = AITP_Schema::table( 'pagemap' );
		$where = array( '1=1' );
		if ( ! empty( $f['lang'] ) ) {
			$where[] = $wpdb->prepare( 'lang = %s', (string) $f['lang'] );
		}
		if ( ! empty( $f['q'] ) ) {
			$where[] = $wpdb->prepare( '(url LIKE %s OR source_url LIKE %s)', '%' . $wpdb->esc_like( (string) $f['q'] ) . '%', '%' . $wpdb->esc_like( (string) $f['q'] ) . '%' );
		}
		if ( isset( $f['ids'] ) ) {
			$ids     = array_values( array_filter( array_map( 'intval', (array) $f['ids'] ) ) );
			$where[] = $ids ? 'id IN (' . implode( ',', $ids ) . ')' : '0=1';
		}
		$w        = implode( ' AND ', $where );
		$per_page = isset( $f['per_page'] ) ? max( 1, (int) $f['per_page'] ) : 50;
		$page     = isset( $f['page'] ) ? max( 1, (int) $f['page'] ) : 1;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $w" );
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE $w ORDER BY source_url, lang LIMIT %d OFFSET %d", $per_page, ( $page - 1 ) * $per_page ), ARRAY_A );
		// phpcs:enable
		$meta = self::meta_table();
		foreach ( $rows as &$r ) {
			$r      += array( 'meta' => 0, 'meta_found' => 0, 's1' => 0, 's2' => 0, 's0' => 0 );
			$dict    = self::dict_table( $r['lang'] );
			if ( $dict === '' ) {
				continue;
			}
			$ps = AITP_Schema::table( 'pagestr' );
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$c = $wpdb->get_row( $wpdb->prepare( "SELECT SUM(d.status = 1) s1, SUM(d.status = 2) s2, SUM(d.status NOT IN (1,2)) s0 FROM $ps p JOIN `$dict` d ON d.id = p.row_id WHERE p.page_id = %d", (int) $r['id'] ), ARRAY_A );
			if ( $c ) {
				$r['s1'] = (int) $c['s1'];
				$r['s2'] = (int) $c['s2'];
				$r['s0'] = (int) $c['s0'];
			}
			if ( $meta !== '' && $r['object_type'] === 'post' && (int) $r['object_id'] ) {
				$r['meta']       = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT d.id) FROM `$dict` d JOIN `$meta` m ON m.original_id = d.original_id WHERE m.meta_key = 'post_parent_id' AND m.meta_value = %s", (string) (int) $r['object_id'] ) );
				$r['meta_found'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT d.id) FROM `$dict` d JOIN `$meta` m ON m.original_id = d.original_id JOIN $ps p ON p.row_id = d.id AND p.page_id = %d WHERE m.meta_key = 'post_parent_id' AND m.meta_value = %s", (int) $r['id'], (string) (int) $r['object_id'] ) );
			}
			// phpcs:enable
		}
		unset( $r );
		return array( 'total' => $total, 'rows' => $rows );
	}

	/**
	 * Candidate list of the machine-translated rows that some pages show (manual translations
	 * and untranslated rows are left out), in the format of "Import candidate list".
	 *
	 * @param int[] $page_ids Mapped page ids (empty = every mapped page).
	 * @return array{rows:array,pages:array}
	 */
	public static function export( array $page_ids ) {
		global $wpdb;
		$table = AITP_Schema::table( 'pagemap' );
		$ps    = AITP_Schema::table( 'pagestr' );
		$ids   = array_values( array_filter( array_map( 'intval', $page_ids ) ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$pages = (array) $wpdb->get_results( "SELECT id, url, source_url, lang, found, http, note, mapped_at FROM $table" . ( $ids ? ' WHERE id IN (' . implode( ',', $ids ) . ')' : '' ) . ' ORDER BY source_url, lang', ARRAY_A );
		$rows  = array();
		foreach ( $pages as $p ) {
			$dict = self::dict_table( $p['lang'] );
			if ( $dict === '' ) {
				continue;
			}
			$list = (array) $wpdb->get_results( $wpdb->prepare( "SELECT d.id, d.original, d.translated FROM $ps p JOIN `$dict` d ON d.id = p.row_id WHERE p.page_id = %d AND d.status = 1 ORDER BY d.id", (int) $p['id'] ), ARRAY_A );
			foreach ( $list as $r ) {
				$k = $p['lang'] . '|' . $r['id'];
				if ( ! isset( $rows[ $k ] ) ) {
					$rows[ $k ] = array(
						'language' => $p['lang'],
						'table'    => 'dictionary',
						'id'       => (string) $r['id'],
						'original' => (string) $r['original'],
						'old'      => (string) $r['translated'],
						'pages'    => array(),
					);
				}
				$rows[ $k ]['pages'][] = $p['url'];
			}
		}
		// phpcs:enable
		return array( 'rows' => array_values( $rows ), 'pages' => $pages );
	}

	/**
	 * Mapped pages that show some rows of one language.
	 *
	 * @param string $lang    Language.
	 * @param int[]  $row_ids Dictionary row ids.
	 * @return array<int,array<int,array{url:string,source_url:string,lang:string,object_type:string,object_id:int}>> row id => pages
	 */
	public static function pages_of_rows( $lang, array $row_ids ) {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $row_ids ) ) ) );
		$out = array();
		if ( ! $ids ) {
			return $out;
		}
		$table = AITP_Schema::table( 'pagemap' );
		$ps    = AITP_Schema::table( 'pagestr' );
		foreach ( array_chunk( $ids, 500 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT p.row_id, m.url, m.source_url, m.lang, m.object_type, m.object_id FROM $ps p JOIN $table m ON m.id = p.page_id WHERE p.lang = %s AND p.row_id IN (" . implode( ',', $chunk ) . ')', $lang ), ARRAY_A );
			foreach ( $rows as $r ) {
				$out[ (int) $r['row_id'] ][] = array(
					'url'         => (string) $r['url'],
					'source_url'  => (string) $r['source_url'],
					'lang'        => (string) $r['lang'],
					'object_type' => (string) $r['object_type'],
					'object_id'   => (int) $r['object_id'],
				);
			}
		}
		return $out;
	}

	/** Forget every mapped page (TranslatePress data is not touched). */
	public static function clear() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'TRUNCATE TABLE ' . AITP_Schema::table( 'pagestr' ) );
		$wpdb->query( 'TRUNCATE TABLE ' . AITP_Schema::table( 'pagemap' ) );
		// phpcs:enable
	}

	/* -------------------------------------------------------------------------------------
	 * TranslatePress tables.
	 * ----------------------------------------------------------------------------------- */

	/**
	 * @param string $lang Language.
	 * @return string Dictionary table ('' if none).
	 */
	private static function dict_table( $lang ) {
		if ( ! class_exists( 'TRP_Translate_Press' ) ) {
			return '';
		}
		$q = TRP_Translate_Press::get_trp_instance()->get_component( 'query' );
		return $q ? (string) $q->get_table_name( $lang ) : '';
	}

	/** @return string TranslatePress original meta table ('' if none). */
	private static function meta_table() {
		global $wpdb;
		if ( ! class_exists( 'TRP_Translate_Press' ) ) {
			return '';
		}
		$q    = TRP_Translate_Press::get_trp_instance()->get_component( 'query' );
		$meta = $q && method_exists( $q, 'get_table_name_for_original_meta' ) ? (string) $q->get_table_name_for_original_meta() : '';
		return $meta !== '' && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $meta ) ) ) === $meta ? $meta : ''; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
