<?php
/**
 * Backfill queue: visits the site's own translated pages in the background so TranslatePress
 * translates them before a visitor does.
 *
 * The queue never writes translations itself. It requests a translated URL (for example
 * /fr/about/) the way a visitor would; TranslatePress renders the page and hands untranslated
 * strings to the engine, with every check of the normal flow. A signed header marks the visit
 * as internal so it may use a longer time budget and reports back what happened. Without a
 * valid signature a request is an ordinary page view.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Queue {

	const HEADER       = 'X-AITP-Warm';
	const SETTINGS     = 'aitp_queue_settings';
	const STATE        = 'aitp_queue_state';
	const LOCK         = 'aitp_queue_lock';
	const CRON         = 'aitp_queue_tick';
	const SCHEDULE     = 'aitp_minute';
	const QUERY_ARG    = 'aitp_nc';
	const MAP_ARG      = 'aitp_map';
	const STATUSES     = array( 'pending', 'partial', 'done', 'failed' );

	/** @var AITP_Queue|null */
	private static $instance = null;

	/** @var string Nonce of the signed visit being served by this request ('' if none). */
	private $warm = '';

	/** @var bool Whether the signed visit only maps the page (nothing translated or saved). */
	private $map = false;

	/** @var array Report of the signed visit being served. */
	private $report = array();

	/** @return AITP_Queue */
	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** Hooks. */
	public function boot() {
		add_filter( 'cron_schedules', array( $this, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval
		add_action( self::CRON, array( $this, 'tick' ) );
		add_action( 'plugins_loaded', array( $this, 'maybe_warm' ), 1 );
		add_action( 'save_post', array( $this, 'on_save_post' ), 20, 2 );
		add_action( 'edited_term', array( $this, 'on_edited_term' ), 20, 3 );
	}

	/* -------------------------------------------------------------------------------------
	 * Settings and state.
	 * ----------------------------------------------------------------------------------- */

	/** @return array */
	public static function defaults() {
		return array(
			'languages'    => array(),          // Empty = all TranslatePress languages.
			'types'        => array( 'home', 'pt:page', 'pt:post', 'pt:product', 'tax:category', 'tax:product_cat' ),
			'extra_urls'   => '',
			'per_minute'   => 4,
			'queue_cap'    => 300000,           // Characters per day the queue may use (0 = no own cap).
			'reserve'      => 20,               // % of the TranslatePress daily limit kept for visitors.
			'page_seconds' => 30,               // Time budget of one signed page visit.
		);
	}

	/** @return array */
	public static function settings() {
		$s = get_option( self::SETTINGS, array() );
		return array_merge( self::defaults(), is_array( $s ) ? $s : array() );
	}

	/**
	 * @param array $in Raw input.
	 * @return array Clean settings (saved).
	 */
	public static function save_settings( array $in ) {
		$d   = self::defaults();
		$out = array(
			'languages'    => array(),
			'types'        => array(),
			'extra_urls'   => '',
			'per_minute'   => max( 1, min( 30, (int) ( isset( $in['per_minute'] ) ? $in['per_minute'] : $d['per_minute'] ) ) ),
			'queue_cap'    => max( 0, min( 100000000, (int) ( isset( $in['queue_cap'] ) ? $in['queue_cap'] : $d['queue_cap'] ) ) ),
			'reserve'      => max( 0, min( 90, (int) ( isset( $in['reserve'] ) ? $in['reserve'] : $d['reserve'] ) ) ),
			'page_seconds' => max( 10, min( 40, (int) ( isset( $in['page_seconds'] ) ? $in['page_seconds'] : $d['page_seconds'] ) ) ),
		);
		$langs = self::tp_languages();
		foreach ( isset( $in['languages'] ) ? (array) $in['languages'] : array() as $l ) {
			if ( in_array( (string) $l, $langs, true ) ) {
				$out['languages'][] = (string) $l;
			}
		}
		$known = array_keys( self::content_types() );
		foreach ( isset( $in['types'] ) ? (array) $in['types'] : array() as $t ) {
			if ( in_array( (string) $t, $known, true ) ) {
				$out['types'][] = (string) $t;
			}
		}
		$urls = array();
		foreach ( preg_split( '~\R~', isset( $in['extra_urls'] ) ? (string) $in['extra_urls'] : '' ) as $u ) {
			$u = esc_url_raw( trim( $u ) );
			if ( $u !== '' && self::same_site( $u ) ) {
				$urls[] = $u;
			}
		}
		$out['extra_urls'] = implode( "\n", array_slice( array_unique( $urls ), 0, 500 ) );
		update_option( self::SETTINGS, $out, false );
		return $out;
	}

	/** @return array */
	public static function state() {
		$s = get_option( self::STATE, array() );
		$s = array_merge(
			array(
				'status'      => 'stopped', // stopped | running.
				'wait_until'  => 0,
				'wait_reason' => '',
				'day'         => '',
				'day_bytes'   => 0,
				'last_visit'  => 0,
				'last_sync'   => 0,
			),
			is_array( $s ) ? $s : array()
		);
		if ( $s['day'] !== gmdate( 'Y-m-d' ) ) {
			$s['day']       = gmdate( 'Y-m-d' );
			$s['day_bytes'] = 0;
			if ( in_array( $s['wait_reason'], array( 'queue_cap', 'tp_limit' ), true ) ) {
				$s['wait_until']  = 0;
				$s['wait_reason'] = '';
			}
		}
		return $s;
	}

	/** @param array $s State. */
	private static function set_state( array $s ) {
		wp_cache_delete( self::STATE, 'options' );
		update_option( self::STATE, $s, false );
	}

	/**
	 * Merge changes into the stored state (re-read first: a visit may take a while).
	 *
	 * @param array $changes Changes.
	 * @return array New state.
	 */
	private static function update_state( array $changes ) {
		wp_cache_delete( self::STATE, 'options' );
		$s = array_merge( self::state(), $changes );
		self::set_state( $s );
		return $s;
	}

	/** Start (or resume) the queue. */
	public static function start() {
		self::update_state( array( 'status' => 'running', 'wait_until' => 0, 'wait_reason' => '' ) );
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 5, self::SCHEDULE, self::CRON );
		}
	}

	/** Pause the queue. */
	public static function pause() {
		self::update_state( array( 'status' => 'stopped' ) );
		wp_clear_scheduled_hook( self::CRON );
	}

	/**
	 * @param array $schedules Cron schedules.
	 * @return array
	 */
	public function schedules( $schedules ) {
		$schedules[ self::SCHEDULE ] = array(
			'interval' => 60,
			'display'  => 'AI Translator queue (every minute)',
		);
		return $schedules;
	}

	/* -------------------------------------------------------------------------------------
	 * Page list.
	 * ----------------------------------------------------------------------------------- */

	/** @return string[] TranslatePress target languages. */
	public static function tp_languages() {
		$s = get_option( 'trp_settings', array() );
		if ( ! is_array( $s ) || empty( $s['translation-languages'] ) ) {
			return array();
		}
		$default = isset( $s['default-language'] ) ? $s['default-language'] : '';
		return array_values( array_diff( (array) $s['translation-languages'], array( $default ) ) );
	}

	/** @return string[] Languages the queue works on. */
	public static function languages() {
		$all = self::tp_languages();
		$sel = self::settings()['languages'];
		return $sel ? array_values( array_intersect( $all, $sel ) ) : $all;
	}

	/**
	 * Selectable content: key => label.
	 *
	 * @return array
	 */
	public static function content_types() {
		$out = array( 'home' => __( 'Home page', 'ai-translator-for-translatepress' ) );
		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
			if ( $pt->name !== 'attachment' ) {
				$out[ 'pt:' . $pt->name ] = $pt->labels->name;
			}
		}
		foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $tx ) {
			if ( $tx->name !== 'post_format' && ! empty( $tx->publicly_queryable ) ) {
				$out[ 'tax:' . $tx->name ] = $tx->labels->name;
			}
		}
		return $out;
	}

	/**
	 * Source pages (default language): list of [url, type, id, priority].
	 *
	 * @return array
	 */
	public static function source_pages() {
		$s     = self::settings();
		$known = self::content_types();
		$pages = array();
		foreach ( $s['types'] as $type ) {
			if ( ! isset( $known[ $type ] ) ) {
				continue;
			}
			if ( $type === 'home' ) {
				$pages[] = array( home_url( '/' ), 'home', 0, 0 );
			} elseif ( strpos( $type, 'pt:' ) === 0 ) {
				$pt  = substr( $type, 3 );
				$pri = $pt === 'page' ? 1 : ( $pt === 'product' ? 2 : 4 );
				$ids = get_posts(
					array(
						'post_type'        => $pt,
						'post_status'      => 'publish',
						'posts_per_page'   => -1,
						'fields'           => 'ids',
						'orderby'          => 'menu_order date',
						'order'            => 'ASC',
						'has_password'     => false,
						'suppress_filters' => true,
						'no_found_rows'    => true,
					)
				);
				foreach ( $ids as $id ) {
					$url = get_permalink( $id );
					if ( $url ) {
						$pages[] = array( $url, 'post', (int) $id, $pri );
					}
				}
			} elseif ( strpos( $type, 'tax:' ) === 0 ) {
				$terms = get_terms(
					array(
						'taxonomy'   => substr( $type, 4 ),
						'hide_empty' => true,
					)
				);
				if ( is_wp_error( $terms ) ) {
					continue;
				}
				foreach ( $terms as $t ) {
					$url = get_term_link( $t );
					if ( ! is_wp_error( $url ) ) {
						$pages[] = array( $url, 'term', (int) $t->term_id, 3 );
					}
				}
			}
		}
		foreach ( preg_split( '~\R~', (string) $s['extra_urls'] ) as $u ) {
			$u = trim( $u );
			if ( $u !== '' ) {
				$pages[] = array( $u, 'url', 0, 1 );
			}
		}
		$seen = array();
		$out  = array();
		foreach ( $pages as $p ) {
			if ( ! isset( $seen[ $p[0] ] ) ) {
				$seen[ $p[0] ] = true;
				$out[]         = $p;
			}
		}
		return $out;
	}

	/**
	 * URL of a page in a TranslatePress language.
	 *
	 * @param string $url  Default-language URL.
	 * @param string $lang Language code.
	 * @return string
	 */
	public static function translated_url( $url, $lang ) {
		if ( ! class_exists( 'TRP_Translate_Press' ) ) {
			return '';
		}
		$conv = TRP_Translate_Press::get_trp_instance()->get_component( 'url_converter' );
		if ( ! $conv || ! method_exists( $conv, 'get_url_for_language' ) ) {
			return '';
		}
		return str_replace( '#TRPLINKPROCESSED', '', (string) $conv->get_url_for_language( $lang, $url, '' ) );
	}

	/**
	 * @param string $url URL.
	 * @return bool Whether the URL belongs to this site.
	 */
	public static function same_site( $url ) {
		$h = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$u = wp_parse_url( $url, PHP_URL_HOST );
		return $h && $u && strtolower( $h ) === strtolower( $u );
	}

	/**
	 * Rebuild the page list: add new pages, keep the progress of existing ones, remove pages
	 * that are no longer selected or published.
	 *
	 * @return array{pages:int,rows:int,removed:int}
	 */
	public static function sync() {
		global $wpdb;
		$table = AITP_Schema::table( 'queue' );
		$langs = self::languages();
		$pages = self::source_pages();
		$sync  = time();
		$rows  = array();
		foreach ( $pages as $p ) {
			foreach ( $langs as $lang ) {
				$turl = self::translated_url( $p[0], $lang );
				if ( $turl === '' || $turl === $p[0] ) {
					continue;
				}
				$rows[] = $wpdb->prepare( '(%s,%s,%s,%s,%s,%d,%d,%s,%d,%d,%s)', sha1( $p[0] . '|' . $lang ), $p[0], $turl, $lang, $p[1], $p[2], $p[3], 'pending', 0, $sync, current_time( 'mysql', true ) );
			}
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( array_chunk( $rows, 200 ) as $chunk ) {
			$wpdb->query(
				"INSERT INTO $table (ukey, source_url, url, lang, object_type, object_id, priority, status, next_at, seen, updated_at) VALUES " . implode( ',', $chunk ) .
				' ON DUPLICATE KEY UPDATE url = VALUES(url), object_type = VALUES(object_type), object_id = VALUES(object_id), priority = VALUES(priority), seen = VALUES(seen)'
			);
		}
		// Pages a repair queued stay until they were visited, even when not selected.
		$removed = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE seen <> %d AND NOT ( note = 'repair' AND status IN ('pending','partial') )", $sync ) );
		$total   = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		// phpcs:enable
		self::update_state( array( 'last_sync' => $sync ) );
		return array(
			'pages'   => count( $pages ),
			'rows'    => $total,
			'removed' => $removed,
		);
	}

	/**
	 * Put pages back in the queue.
	 *
	 * @param string $where  'failed', 'all', or 'object'.
	 * @param string $type   Object type (for 'object').
	 * @param int    $id     Object ID (for 'object').
	 * @return int Rows changed.
	 */
	public static function requeue( $where, $type = '', $id = 0 ) {
		global $wpdb;
		$table = AITP_Schema::table( 'queue' );
		$set   = "status = 'pending', visits = 0, attempts = 0, passes = 0, next_at = 0, note = ''";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $where === 'failed' ) {
			return (int) $wpdb->query( "UPDATE $table SET $set WHERE status IN ('failed','partial')" );
		}
		if ( $where === 'all' ) {
			return (int) $wpdb->query( "UPDATE $table SET $set" );
		}
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE $table SET $set WHERE object_type = %s AND object_id = %d AND status <> 'pending'", $type, $id ) );
		// phpcs:enable
	}

	/**
	 * Put pages of one language back in the queue.
	 *
	 * @param string     $lang     Language.
	 * @param int[]|null $post_ids Only these posts, or null for every page of the language.
	 * @return int Rows changed.
	 */
	public static function requeue_lang( $lang, $post_ids ) {
		global $wpdb;
		$table = AITP_Schema::table( 'queue' );
		$set   = "status = 'pending', visits = 0, attempts = 0, passes = 0, next_at = 0, note = ''";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		if ( $post_ids === null ) {
			return (int) $wpdb->query( $wpdb->prepare( "UPDATE $table SET $set WHERE lang = %s", $lang ) );
		}
		$ids = array_values( array_filter( array_map( 'intval', $post_ids ) ) );
		if ( ! $ids ) {
			return 0;
		}
		return (int) $wpdb->query( $wpdb->prepare( "UPDATE $table SET $set WHERE lang = %s AND object_type = 'post' AND object_id IN (" . implode( ',', $ids ) . ')', $lang ) );
		// phpcs:enable
	}

	/**
	 * Put some pages back in the queue (added when missing). Used by repairs: only these
	 * pages are visited again, not every page of the language.
	 *
	 * @param array $pages Each url, source_url, lang, object_type, object_id.
	 * @return int Pages queued.
	 */
	public static function requeue_urls( array $pages ) {
		global $wpdb;
		$table = AITP_Schema::table( 'queue' );
		$rows  = array();
		$state = self::state();
		foreach ( $pages as $p ) {
			if ( empty( $p['url'] ) || empty( $p['source_url'] ) || empty( $p['lang'] ) ) {
				continue;
			}
			$rows[] = $wpdb->prepare( '(%s,%s,%s,%s,%s,%d,%d,%s,%d,%s,%d,%s)', sha1( $p['source_url'] . '|' . $p['lang'] ), $p['source_url'], $p['url'], $p['lang'], (string) $p['object_type'], (int) $p['object_id'], 1, 'pending', 0, 'repair', (int) $state['last_sync'], current_time( 'mysql', true ) );
		}
		foreach ( array_chunk( $rows, 200 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				"INSERT INTO $table (ukey, source_url, url, lang, object_type, object_id, priority, status, next_at, note, seen, updated_at) VALUES " . implode( ',', $chunk ) .
				" ON DUPLICATE KEY UPDATE status = 'pending', visits = 0, attempts = 0, passes = 0, next_at = 0, note = 'repair'"
			);
		}
		return count( $rows );
	}

	/**
	 * Translated addresses of posts in one language (from the queue).
	 *
	 * @param string $lang     Language.
	 * @param int[]  $post_ids Posts.
	 * @return string[]
	 */
	public static function urls_for( $lang, array $post_ids ) {
		global $wpdb;
		$ids = array_values( array_filter( array_map( 'intval', $post_ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return (array) $wpdb->get_col( $wpdb->prepare( 'SELECT url FROM ' . AITP_Schema::table( 'queue' ) . " WHERE lang = %s AND object_type = 'post' AND object_id IN (" . implode( ',', $ids ) . ')', $lang ) );
	}

	/** Remove every page from the queue. */
	public static function clear() {
		global $wpdb;
		$wpdb->query( 'TRUNCATE TABLE ' . AITP_Schema::table( 'queue' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Edited content is translated again where it changed.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post.
	 */
	public function on_save_post( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! $post || $post->post_status !== 'publish' ) {
			return;
		}
		self::requeue( 'object', 'post', (int) $post_id );
	}

	/**
	 * @param int    $term_id  Term ID.
	 * @param int    $tt_id    Term taxonomy ID.
	 * @param string $taxonomy Taxonomy.
	 */
	public function on_edited_term( $term_id, $tt_id = 0, $taxonomy = '' ) {
		self::requeue( 'object', 'term', (int) $term_id );
	}

	/**
	 * Progress per language.
	 *
	 * @return array lang => [status => count]
	 */
	public static function progress() {
		global $wpdb;
		$out = array();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( (array) $wpdb->get_results( 'SELECT lang, status, COUNT(*) n FROM ' . AITP_Schema::table( 'queue' ) . ' GROUP BY lang, status', ARRAY_A ) as $r ) {
			$out[ $r['lang'] ][ $r['status'] ] = (int) $r['n'];
		}
		return $out;
	}

	/* -------------------------------------------------------------------------------------
	 * Worker.
	 * ----------------------------------------------------------------------------------- */

	/** WP-Cron tick. */
	public function tick() {
		$limit = (int) ini_get( 'max_execution_time' );
		$secs  = $limit > 0 ? max( 20, min( 55, $limit - 10 ) ) : 55;
		self::run( $secs, 0, true );
	}

	/**
	 * Visit due pages until time, page count or budget runs out.
	 *
	 * @param int  $max_seconds Wall time for this run.
	 * @param int  $max_pages   Page limit (0 = no limit).
	 * @param bool $may_sleep   Whether to wait for the rate limit inside this run.
	 * @return array{result:string,visited:int,wait:int}
	 */
	public static function run( $max_seconds, $max_pages = 0, $may_sleep = false ) {
		$start = time();
		if ( ! self::lock( (int) $max_seconds + 120 ) ) {
			return array( 'result' => 'busy', 'visited' => 0, 'wait' => 30 );
		}
		$visited = 0;
		$result  = 'time';
		$wait    = 0;
		try {
			while ( true ) {
				$s   = self::state();
				$set = self::settings();
				$now = time();
				if ( $s['status'] !== 'running' ) {
					$result = 'stopped';
					break;
				}
				if ( $now < (int) $s['wait_until'] ) {
					$result = 'waiting';
					$wait   = (int) $s['wait_until'] - $now;
					break;
				}
				if ( $max_pages && $visited >= $max_pages ) {
					$result = 'pages';
					break;
				}
				// Visits make TranslatePress translate with whatever engine it has selected:
				// never drive another engine (for example HHG) or a switched-off automatic translation.
				$engine = ! AITP_Plugin::engine_selected() ? 'engine_not_selected' : ( ! AITP_Plugin::automatic_translation_on() ? 'automatic_off' : '' );
				if ( $engine !== '' ) {
					self::update_state( array( 'wait_until' => $now + 300, 'wait_reason' => $engine ) );
					$result = 'waiting';
					$wait   = 300;
					break;
				}
				$block = self::budget_block( $s, $set );
				if ( $block !== '' ) {
					$until = strtotime( 'tomorrow', $now ) + 60;
					self::update_state( array( 'wait_until' => $until, 'wait_reason' => $block ) );
					$result = 'waiting';
					$wait   = $until - $now;
					break;
				}
				$b = AITP_Plugin::breaker()->state();
				if ( $b['status'] !== 'closed' && $now < (int) $b['until'] ) {
					self::update_state( array( 'wait_until' => (int) $b['until'], 'wait_reason' => 'breaker' ) );
					$result = 'waiting';
					$wait   = (int) $b['until'] - $now;
					break;
				}
				$gap = (int) ceil( 60 / max( 1, (int) $set['per_minute'] ) ) - ( $now - (int) $s['last_visit'] );
				if ( $gap > 0 ) {
					if ( ! $may_sleep || time() + $gap + (int) $set['page_seconds'] + 10 > $start + $max_seconds ) {
						$result = 'rate';
						$wait   = $gap;
						break;
					}
					sleep( $gap );
					continue;
				}
				if ( $visited > 0 && time() + (int) $set['page_seconds'] + 10 > $start + $max_seconds ) {
					break;
				}
				$item = self::next_item();
				if ( ! $item ) {
					$result = 'idle';
					$wait   = self::seconds_to_next_due();
					break;
				}
				self::process( $item, $set );
				$visited++;
			}
		} finally {
			self::unlock();
		}
		return array( 'result' => $result, 'visited' => $visited, 'wait' => $wait );
	}

	/**
	 * @param array $s   State.
	 * @param array $set Settings.
	 * @return string '' or block reason.
	 */
	private static function budget_block( array $s, array $set ) {
		return AITP_Queue_Logic::budget_block( (int) $s['day_bytes'], (int) $set['queue_cap'], self::tp_counter(), AITP_TP_Quota::limit(), (int) $set['reserve'] / 100 );
	}

	/** @return int Characters TranslatePress counted today. */
	public static function tp_counter() {
		if ( class_exists( 'TRP_Translate_Press' ) ) {
			$logger = TRP_Translate_Press::get_trp_instance()->get_component( 'machine_translator_logger' );
			if ( $logger && method_exists( $logger, 'get_current_counter' ) ) {
				return (int) $logger->get_current_counter();
			}
		}
		return 0;
	}

	/** @return array|null Next due item. */
	private static function next_item() {
		global $wpdb;
		$t = AITP_Schema::table( 'queue' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE status IN ('pending','partial') AND next_at <= %d ORDER BY next_at = 0 DESC, priority ASC, id ASC LIMIT 1", time() ), ARRAY_A );
		return $row ? $row : null;
	}

	/** @return int Seconds until the next page is due (0 if none is scheduled). */
	private static function seconds_to_next_due() {
		global $wpdb;
		$t = AITP_Schema::table( 'queue' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$next = (int) $wpdb->get_var( "SELECT MIN(next_at) FROM $t WHERE status IN ('pending','partial')" );
		return $next ? max( 0, $next - time() ) : 0;
	}

	/**
	 * Visit one page and record the outcome.
	 *
	 * @param array $item Queue row.
	 * @param array $set  Settings.
	 */
	private static function process( array $item, array $set ) {
		global $wpdb;
		self::update_state( array( 'last_visit' => time() ) );
		$visit = self::visit( $item['url'], $item['lang'], (int) $set['page_seconds'] );
		$now   = time();
		$d     = AITP_Queue_Logic::decide( $item, $visit, $now );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			AITP_Schema::table( 'queue' ),
			array(
				'status'        => $d['status'],
				'next_at'       => $d['next_at'],
				'visits'        => $d['visits'],
				'attempts'      => $d['attempts'],
				'passes'        => $d['passes'],
				'note'          => $d['note'],
				'translated'    => (int) $item['translated'] + (int) $visit['translated'],
				'chars'         => (int) $item['chars'] + (int) $visit['sent'],
				'last_visit_at' => $now,
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $item['id'] )
		);

		if ( (int) $visit['translated'] > 0 ) {
			// New translations on this page: cached copies for visitors are out of date.
			AITP_Cache_Purge::urls( array( $item['url'] ) );
		}

		$changes = array();
		if ( $visit['sent'] ) {
			$s                  = self::state();
			$changes['day_bytes'] = (int) $s['day_bytes'] + (int) $visit['sent'];
		}
		if ( $d['queue_wait'] ) {
			$changes['wait_until']  = $now + $d['queue_wait'];
			$changes['wait_reason'] = $d['note'];
		}
		if ( $changes ) {
			self::update_state( $changes );
		}

		( new AITP_WP_Log() )->log(
			$d['status'] === 'failed' ? 'warning' : 'info',
			'queue',
			array(
				'target'     => $item['lang'],
				'url'        => $item['url'],
				'status'     => $d['status'],
				'note'       => $d['note'],
				'http'       => $visit['http'],
				'calls'      => $visit['calls'],
				'translated' => $visit['translated'],
				'failed'     => $visit['failed'],
				'sent_bytes' => $visit['sent'],
				'stop'       => $visit['stop'],
				'ms'         => $visit['ms'],
			)
		);
	}

	/**
	 * Signed request of a translated page.
	 *
	 * @param string $url     Translated URL.
	 * @param string $lang    Language.
	 * @param int    $seconds Time budget of the visit.
	 * @param bool   $map     Only map the page: record the page-text rows it shows, with machine
	 *                        translation and string saving switched off for that request.
	 * @return array{http:int,report:bool,calls:int,translated:int,failed:int,sent:int,stop:string,ms:int,rows:int[],strings:int,unknown:int}
	 */
	public static function visit( $url, $lang, $seconds, $map = false ) {
		$nonce = bin2hex( random_bytes( 16 ) );
		$url   = add_query_arg( self::QUERY_ARG, substr( $nonce, 0, 8 ), $url );
		if ( $map ) {
			$url = add_query_arg( self::MAP_ARG, '1', $url ); // Part of the signed path: cannot be added by anyone else.
		}
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$path  = ( $path === '' ? '/' : $path ) . ( $query !== '' ? '?' . $query : '' );
		$req   = array(
			'url'  => $url,
			'args' => array(
				'timeout'     => $seconds + 40,
				'redirection' => 0,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook for loopbacks.
				'user-agent'  => 'AITP-Queue/' . AITP_VERSION . '; ' . home_url( '/' ),
				'headers'     => array(
					self::HEADER    => AITP_Queue_Logic::sign( self::secret(), $path, time(), $nonce ),
					'Cache-Control' => 'no-cache',
					'Pragma'        => 'no-cache',
				),
			),
		);
		/**
		 * Adjust the internal page request, e.g. when the site cannot reach its own public
		 * address from the server (containers, proxies). The signed path must not change.
		 *
		 * @param array  $req  ['url' => string, 'args' => array].
		 * @param string $lang Language.
		 */
		$req = apply_filters( 'aitp_queue_request', $req, $lang );

		$t0   = microtime( true );
		$resp = wp_remote_get( $req['url'], $req['args'] );
		$ms   = (int) round( ( microtime( true ) - $t0 ) * 1000 );
		$out  = array(
			'http'       => is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp ),
			'report'     => false,
			'calls'      => 0,
			'translated' => 0,
			'failed'     => 0,
			'sent'       => 0,
			'stop'       => '',
			'ms'         => $ms,
			'rows'       => array(),
			'strings'    => 0,
			'unknown'    => 0,
		);
		// The page writes its report while shutting down; give it a moment if needed.
		$key = 'aitp_warm_' . $nonce;
		for ( $i = 0; $i < 6; $i++ ) {
			wp_cache_delete( $key, 'options' );
			wp_cache_delete( '_transient_' . $key, 'options' );
			$rep = get_transient( $key );
			if ( is_array( $rep ) || $out['http'] === 0 ) {
				break;
			}
			usleep( 500000 );
		}
		if ( isset( $rep ) && is_array( $rep ) ) {
			delete_transient( $key );
			$out = array_merge( $out, array_intersect_key( $rep, $out ), array( 'report' => true ) );
		}
		return $out;
	}

	/** @return string Secret for signing internal visits. */
	private static function secret() {
		return hash_hmac( 'sha256', 'aitp-queue', wp_salt( 'auth' ) );
	}

	/**
	 * Mutex across requests (cron, admin, WP-CLI).
	 *
	 * @param int $ttl Seconds before a stale lock is taken over.
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

	/** Release the mutex. */
	private static function unlock() {
		delete_option( self::LOCK );
	}

	/* -------------------------------------------------------------------------------------
	 * Serving a signed visit.
	 * ----------------------------------------------------------------------------------- */

	/** @return bool Whether this request is a signed internal visit. */
	public static function is_warm() {
		return self::$instance !== null && self::$instance->warm !== '';
	}

	/** @return int Time budget for a signed visit. */
	public static function page_seconds() {
		return (int) self::settings()['page_seconds'];
	}

	/** Recognise a signed visit early in the request. */
	public function maybe_warm() {
		$hdr = isset( $_SERVER['HTTP_X_AITP_WARM'] ) ? (string) $_SERVER['HTTP_X_AITP_WARM'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( $hdr === '' || is_admin() || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}
		$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$nonce = AITP_Queue_Logic::verify( self::secret(), $hdr, $uri, time() );
		if ( $nonce === '' || get_transient( 'aitp_used_' . $nonce ) !== false ) {
			return; // Invalid, expired or replayed: an ordinary page view.
		}
		set_transient( 'aitp_used_' . $nonce, 1, AITP_Queue_Logic::TOKEN_TTL * 2 );

		$this->warm   = $nonce;
		$this->map    = (bool) preg_match( '~[?&]' . self::MAP_ARG . '=1(&|$)~', $uri );
		$this->report = array(
			'calls'      => 0,
			'translated' => 0,
			'failed'     => 0,
			'sent'       => 0,
			'stop'       => '',
			'rows'       => array(),
			'strings'    => 0,
			'unknown'    => 0,
		);
		if ( $this->map ) {
			// Read only: whatever engine is selected (also another plugin's), nothing is machine
			// translated and TranslatePress saves no strings during this request.
			add_filter( 'trp_machine_translator_is_available', '__return_false', PHP_INT_MAX );
			add_filter( 'trp_allow_string_saving', '__return_false', PHP_INT_MAX );
			add_action( 'trp_translateable_information', array( $this, 'map_strings' ), 10, 3 );
		}
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- convention honoured by page caches.
		}
		$secs = self::page_seconds();
		add_filter(
			'trp_machine_translation_time_budget',
			function () use ( $secs ) {
				return $secs;
			},
			99
		);
		add_action( 'send_headers', 'nocache_headers' );
		add_action( 'aitp_translated_batch', array( $this, 'collect' ), 10, 2 );
		add_action( 'shutdown', array( $this, 'save_report' ), PHP_INT_MAX - 1 );
	}

	/**
	 * @param array  $result Engine result.
	 * @param string $target Language.
	 */
	public function collect( $result, $target ) {
		$r = &$this->report;
		$r['calls']++;
		$r['translated'] += isset( $result['translations'] ) ? count( $result['translations'] ) : 0;
		$r['failed']     += isset( $result['failed'] ) ? count( $result['failed'] ) : 0;
		$r['sent']       += isset( $result['stats']['sent_bytes'] ) ? (int) $result['stats']['sent_bytes'] : 0;
		$stop             = isset( $result['stats']['stop_reason'] ) ? (string) $result['stats']['stop_reason'] : '';
		if ( $r['stop'] === '' && $stop !== '' ) {
			$r['stop'] = $stop;
		}
	}

	/**
	 * Map visit: page-text rows of this language that the rendered page shows.
	 *
	 * @param array  $info       TranslatePress: translateable_strings, nodes.
	 * @param array  $translated Translations served.
	 * @param string $lang       Language code.
	 */
	public function map_strings( $info, $translated, $lang ) {
		global $wpdb;
		$strings = array_values( array_unique( array_filter( array_map( 'strval', isset( $info['translateable_strings'] ) ? (array) $info['translateable_strings'] : array() ), 'strlen' ) ) );
		if ( ! $strings || ! class_exists( 'TRP_Translate_Press' ) ) {
			return;
		}
		// TranslatePress writes its post_parent_id links right after this action, once per
		// address per day: report "already done today" so this visit writes nothing there either.
		if ( function_exists( 'get_permalink' ) && in_array( 'md4', hash_algos(), true ) ) {
			add_filter( 'pre_transient_processed_original_string_meta_post_id_for_' . hash( 'md4', (string) get_permalink() ), array( __CLASS__, 'already_done' ) );
		}
		$q     = TRP_Translate_Press::get_trp_instance()->get_component( 'query' );
		$table = $q ? (string) $q->get_table_name( $lang ) : '';
		if ( $table === '' ) {
			return;
		}
		$seen = array();
		foreach ( array_chunk( $strings, 200 ) as $chunk ) {
			$ph = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, original FROM `$table` WHERE original IN ($ph)", $chunk ), ARRAY_A ) as $r ) {
				if ( in_array( (string) $r['original'], $chunk, true ) ) { // Exact (binary) match only.
					$this->report['rows'][] = (int) $r['id'];
					$seen[ (string) $r['original'] ] = true;
				}
			}
		}
		$this->report['strings'] += count( $strings );
		$this->report['unknown'] += count( $strings ) - count( $seen );
		$this->report['rows']     = array_values( array_unique( $this->report['rows'] ) );
	}

	/** @return string Value of a transient that is already set. */
	public static function already_done() {
		return 'done';
	}

	/** Store the report for the worker that requested this page. */
	public function save_report() {
		if ( $this->warm !== '' ) {
			set_transient( 'aitp_warm_' . $this->warm, $this->report, 600 );
		}
	}
}
