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
	const SYNC         = 'aitp_queue_sync';
	const SYNC_LOCK    = 'aitp_queue_sync_lock';
	const SYNC_CRON    = 'aitp_queue_sync_tick';
	const SYNC_CHUNK   = 200;  // Posts or terms per step of building the page list.
	const TARGET_PASSES = 5;   // Extra visits of a chosen page while the page still shows untranslated rows.
	const STATUSES     = array( 'pending', 'partial', 'done', 'failed' );

	/** @var string[] Extra addresses left out by the last save_settings() (other site). */
	public static $dropped_urls = array();

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
		add_action( self::SYNC_CRON, array( __CLASS__, 'sync_tick' ) );
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
		$urls               = array();
		self::$dropped_urls = array();
		foreach ( preg_split( '~\R~', isset( $in['extra_urls'] ) ? (string) $in['extra_urls'] : '' ) as $u ) {
			$u = trim( $u );
			if ( $u === '' ) {
				continue;
			}
			$n = self::site_form( esc_url_raw( $u ) );
			if ( $n === '' ) {
				self::$dropped_urls[] = $u;
			} else {
				$urls[] = $n;
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
				'scope'       => 'all',  // all | target (only the pages chosen under "Translate these pages").
				'scope_resume' => false, // Whether the whole-site queue was running before (resumes after the chosen pages).
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

	/**
	 * Start (or resume) the queue.
	 *
	 * @param bool $whole Started for the whole site (the Start button, WP-CLI): while chosen pages
	 *                    are being worked on, the rest of the site follows when they are done.
	 */
	public static function start( $whole = true ) {
		$c = array( 'status' => 'running', 'wait_until' => 0, 'wait_reason' => '' );
		if ( $whole && self::state()['scope'] === 'target' ) {
			$c['scope_resume'] = true;
		}
		self::update_state( $c );
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

	/**
	 * Languages visitors can open. TranslatePress only recognises the language of an address
	 * for published languages (or for logged-in translators); an internal visit is anonymous,
	 * so an unpublished language answers with 404 or a redirect and can never be translated.
	 *
	 * @return string[]
	 */
	public static function published_languages() {
		$all = self::tp_languages();
		$s   = get_option( 'trp_settings', array() );
		if ( ! is_array( $s ) || ! isset( $s['publish-languages'] ) || ! is_array( $s['publish-languages'] ) ) {
			return $all;
		}
		return array_values( array_intersect( $all, $s['publish-languages'] ) );
	}

	/** @return string[] Languages the queue works on. */
	public static function languages() {
		$all = self::published_languages();
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
	 * Pages of the shop that are not content (cart, checkout, account): empty carts redirect,
	 * and they only show interface text.
	 *
	 * @return int[]
	 */
	private static function skipped_ids() {
		$ids = array();
		if ( function_exists( 'wc_get_page_id' ) ) {
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $p ) {
				$id = (int) wc_get_page_id( $p );
				if ( $id > 0 ) {
					$ids[] = $id;
				}
			}
		}
		return $ids;
	}

	/**
	 * One step of the page list: source pages of one kind after a cursor.
	 *
	 * @param string $stage home | extra | pt:<type> | tax:<taxonomy>.
	 * @param int    $after Cursor (post or term id).
	 * @return array{pages:array,after:int,finished:bool} pages: [url, type, id, priority].
	 */
	private static function stage_pages( $stage, $after ) {
		global $wpdb;
		$out = array( 'pages' => array(), 'after' => $after, 'finished' => true );
		if ( $stage === 'home' ) {
			$out['pages'][] = array( home_url( '/' ), 'home', 0, 0 );
		} elseif ( $stage === 'extra' ) {
			foreach ( preg_split( '~\R~', (string) self::settings()['extra_urls'] ) as $u ) {
				$u = trim( $u );
				if ( $u !== '' ) {
					$out['pages'][] = array( $u, 'url', 0, 1 );
				}
			}
		} elseif ( strpos( $stage, 'pt:' ) === 0 ) {
			$pt  = substr( $stage, 3 );
			$pri = $pt === 'page' ? 1 : ( $pt === 'product' ? 2 : 4 );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_password = '' AND ID > %d ORDER BY ID LIMIT %d", $pt, (int) $after, self::SYNC_CHUNK ) ) );
			if ( $ids ) {
				_prime_post_caches( $ids, false, false );
			}
			$skip = self::skipped_ids();
			foreach ( $ids as $id ) {
				$url = in_array( $id, $skip, true ) ? '' : get_permalink( $id );
				if ( $url ) {
					$out['pages'][] = array( $url, 'post', $id, $pri );
				}
			}
			$out['after']    = $ids ? (int) end( $ids ) : (int) $after;
			$out['finished'] = count( $ids ) < self::SYNC_CHUNK;
		} elseif ( strpos( $stage, 'tax:' ) === 0 ) {
			// Cursor on the term id (as for posts): an offset skips terms when others become
			// empty or not between two steps, and a skipped term's rows would be removed.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$ids   = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND term_id > %d ORDER BY term_id LIMIT %d", substr( $stage, 4 ), (int) $after, self::SYNC_CHUNK ) ) );
			$terms = $ids ? get_terms(
				array(
					'taxonomy'   => substr( $stage, 4 ),
					'hide_empty' => true,
					'include'    => $ids,
					'number'     => 0,
				)
			) : array();
			$terms = is_wp_error( $terms ) ? array() : (array) $terms;
			foreach ( $terms as $t ) {
				$url = get_term_link( $t );
				if ( ! is_wp_error( $url ) ) {
					$out['pages'][] = array( $url, 'term', (int) $t->term_id, 3 );
				}
			}
			$out['after']    = $ids ? (int) end( $ids ) : (int) $after;
			$out['finished'] = count( $ids ) < self::SYNC_CHUNK;
		}
		return $out;
	}

	/**
	 * Source pages (default language), all at once: list of [url, type, id, priority]. Small
	 * sites and tests; the page list itself is built in steps (sync_step()).
	 *
	 * @return array
	 */
	public static function source_pages() {
		$out  = array();
		$seen = array();
		foreach ( self::stages() as $stage ) {
			$after = 0;
			do {
				$r     = self::stage_pages( $stage, $after );
				$after = $r['after'];
				foreach ( $r['pages'] as $p ) {
					if ( ! isset( $seen[ $p[0] ] ) ) {
						$seen[ $p[0] ] = true;
						$out[]         = $p;
					}
				}
			} while ( ! $r['finished'] );
		}
		return $out;
	}

	/** @return string[] Kinds of pages selected, in the order they are listed. */
	private static function stages() {
		$known  = self::content_types();
		$stages = array();
		foreach ( self::settings()['types'] as $type ) {
			if ( isset( $known[ $type ] ) ) {
				$stages[] = $type;
			}
		}
		$stages[] = 'extra';
		return $stages;
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
	 * An address of this site written the way the site writes it (scheme and host of the home
	 * address; "www." or not, http or https as typed). '' for another site.
	 *
	 * @param string $url Address.
	 * @return string
	 */
	public static function site_form( $url ) {
		$home = wp_parse_url( home_url( '/' ) );
		$p    = wp_parse_url( (string) $url );
		if ( empty( $home['host'] ) || empty( $p['host'] ) ) {
			return '';
		}
		$bare = function ( $h ) {
			return preg_replace( '~^www\.~', '', strtolower( (string) $h ) );
		};
		if ( $bare( $p['host'] ) !== $bare( $home['host'] ) ) {
			return '';
		}
		$base = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
		return $base . ( isset( $p['path'] ) ? $p['path'] : '/' ) . ( isset( $p['query'] ) ? '?' . $p['query'] : '' );
	}

	/**
	 * Rebuild the page list at once (WP-CLI, tests). See sync_start().
	 *
	 * @return array{pages:int,rows:int,removed:int}
	 */
	public static function sync() {
		self::sync_start( false );
		do {
			$r = self::sync_step( 300 );
		} while ( ! $r['done'] && ! $r['busy'] );
		return array( 'pages' => $r['pages'], 'rows' => $r['rows'], 'removed' => $r['removed'] );
	}

	/**
	 * Start rebuilding the page list: new pages are added, existing ones keep their progress,
	 * pages no longer selected or published are removed at the end. Built in steps of a few
	 * hundred posts (in this request while time allows, then by WP-Cron), so a shop with tens of
	 * thousands of products neither times out nor runs out of memory, and every step is saved.
	 *
	 * @param bool $background Continue by WP-Cron.
	 * @return array Sync state.
	 */
	public static function sync_start( $background = true ) {
		$total = 0;
		foreach ( self::stages() as $stage ) {
			if ( $stage === 'home' ) {
				$total++;
			} elseif ( $stage === 'extra' ) {
				$total += count( array_filter( array_map( 'trim', preg_split( '~\R~', (string) self::settings()['extra_urls'] ) ), 'strlen' ) );
			} elseif ( strpos( $stage, 'pt:' ) === 0 ) {
				$c      = wp_count_posts( substr( $stage, 3 ) );
				$total += isset( $c->publish ) ? (int) $c->publish : 0;
			} elseif ( strpos( $stage, 'tax:' ) === 0 ) {
				$c      = wp_count_terms( array( 'taxonomy' => substr( $stage, 4 ), 'hide_empty' => true ) );
				$total += is_wp_error( $c ) ? 0 : (int) $c;
			}
		}
		$old = self::sync_state();
		$st  = array(
			'run'     => $old ? max( time(), (int) $old['run'] + 1 ) : time(), // A restart is a new run.
			'stages'  => self::stages(),
			'stage'   => 0,
			'after'   => 0,
			'pages'   => 0,
			'rows'    => 0,
			'total'   => $total,
			'langs'   => self::languages(),
			'started' => time(),
		);
		update_option( self::SYNC, $st, false );
		if ( $background && ! wp_next_scheduled( self::SYNC_CRON ) ) {
			wp_schedule_event( time() + 30, self::SCHEDULE, self::SYNC_CRON );
		}
		return $st;
	}

	/** @return array|null Page list being built, or null. */
	public static function sync_state() {
		wp_cache_delete( self::SYNC, 'options' );
		$st = get_option( self::SYNC );
		return is_array( $st ) && isset( $st['run'] ) ? $st : null;
	}

	/** WP-Cron: continue building the page list. */
	public static function sync_tick() {
		if ( ! self::sync_state() ) {
			wp_clear_scheduled_hook( self::SYNC_CRON );
			return;
		}
		self::sync_step( 40 );
	}

	/**
	 * Build the page list for a while.
	 *
	 * @param int $seconds Wall time.
	 * @return array{done:bool,busy:bool,pages:int,rows:int,total:int,removed:int}
	 */
	public static function sync_step( $seconds ) {
		global $wpdb;
		$out = array( 'done' => true, 'busy' => false, 'pages' => 0, 'rows' => 0, 'total' => 0, 'removed' => 0 );
		$st  = self::sync_state();
		if ( ! $st ) {
			return $out;
		}
		if ( ! AITP_Lock::acquire( self::SYNC_LOCK, (int) $seconds + 120 ) ) {
			return array_merge( $out, array( 'done' => false, 'busy' => true, 'pages' => (int) $st['pages'], 'total' => (int) $st['total'] ) );
		}
		$table = AITP_Schema::table( 'queue' );
		$end   = microtime( true ) + max( 1, (int) $seconds );
		try {
			while ( $st['stage'] < count( $st['stages'] ) && microtime( true ) < $end ) {
				$r = self::stage_pages( $st['stages'][ $st['stage'] ], (int) $st['after'] );
				$st['rows']  += self::insert_pages( $r['pages'], (array) $st['langs'], (int) $st['run'] );
				$st['pages'] += count( $r['pages'] );
				$st['after']  = $r['after'];
				if ( $r['finished'] ) {
					$st['stage']++;
					$st['after'] = 0;
				}
				update_option( self::SYNC, $st, false ); // Every step is kept.
				if ( function_exists( 'wp_cache_flush_runtime' ) ) {
					wp_cache_flush_runtime(); // Posts and permalinks of this step are not needed again.
				}
			}
			$out = array_merge( $out, array( 'pages' => (int) $st['pages'], 'total' => (int) $st['total'] ) );
			if ( $st['stage'] < count( $st['stages'] ) ) {
				$out['done'] = false;
				return $out;
			}
			// Pages no longer selected or published. Pages queued by a repair or chosen under
			// "Translate these pages" stay until they are done.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out['removed'] = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE seen <> %d AND ( origin = 'site' OR ( origin = 'repair' AND status IN ('done','failed') ) )", (int) $st['run'] ) );
			$out['rows']    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
			// phpcs:enable
			delete_option( self::SYNC );
			wp_clear_scheduled_hook( self::SYNC_CRON );
			self::update_state( array( 'last_sync' => (int) $st['run'] ) );
			( new AITP_WP_Log() )->log( 'notice', 'queue_sync', array( 'pages' => $out['pages'], 'rows' => $out['rows'], 'removed' => $out['removed'] ) );
		} finally {
			AITP_Lock::release( self::SYNC_LOCK );
		}
		return $out;
	}

	/**
	 * Insert or refresh page/language rows.
	 *
	 * @param array    $pages [url, type, id, priority].
	 * @param string[] $langs Languages.
	 * @param int      $run   Sync run (seen).
	 * @return int Rows written.
	 */
	private static function insert_pages( array $pages, array $langs, $run ) {
		global $wpdb;
		$table = AITP_Schema::table( 'queue' );
		$rows  = array();
		foreach ( $pages as $p ) {
			foreach ( $langs as $lang ) {
				$turl = self::translated_url( $p[0], $lang );
				if ( $turl === '' || $turl === $p[0] ) {
					continue;
				}
				$rows[] = $wpdb->prepare( '(%s,%s,%s,%s,%s,%d,%d,%s,%d,%d,%s)', sha1( $p[0] . '|' . $lang ), $p[0], $turl, $lang, $p[1], $p[2], $p[3], 'pending', 0, $run, current_time( 'mysql', true ) );
			}
		}
		foreach ( array_chunk( $rows, 200 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				"INSERT INTO $table (ukey, source_url, url, lang, object_type, object_id, priority, status, next_at, seen, updated_at) VALUES " . implode( ',', $chunk ) .
				" ON DUPLICATE KEY UPDATE url = VALUES(url), object_type = VALUES(object_type), object_id = VALUES(object_id), priority = IF(origin = 'target', priority, VALUES(priority)), seen = VALUES(seen)"
			);
		}
		return count( $rows );
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
	 * Put some pages back in the queue (added when missing). Used by repairs (only these pages
	 * are visited again, not every page of the language) and by "Translate these pages".
	 *
	 * @param array  $pages  Each url, source_url, lang, object_type, object_id.
	 * @param string $origin repair | target.
	 * @param int    $batch  Which request queued them (target: time of the request).
	 * @return int Pages queued.
	 */
	public static function requeue_urls( array $pages, $origin = 'repair', $batch = 0 ) {
		global $wpdb;
		$table  = AITP_Schema::table( 'queue' );
		$rows   = array();
		$state  = self::state();
		$origin = $origin === 'target' ? 'target' : 'repair';
		$pri    = $origin === 'target' ? 0 : 1;
		foreach ( $pages as $p ) {
			if ( empty( $p['url'] ) || empty( $p['source_url'] ) || empty( $p['lang'] ) ) {
				continue;
			}
			$rows[] = $wpdb->prepare( '(%s,%s,%s,%s,%s,%d,%d,%s,%d,%s,%d,%s,%d,%s)', sha1( $p['source_url'] . '|' . $p['lang'] ), $p['source_url'], $p['url'], $p['lang'], (string) $p['object_type'], (int) $p['object_id'], $pri, 'pending', 0, $origin, (int) $batch, $origin, (int) $state['last_sync'], current_time( 'mysql', true ) );
		}
		foreach ( array_chunk( $rows, 200 ) as $chunk ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query(
				"INSERT INTO $table (ukey, source_url, url, lang, object_type, object_id, priority, status, next_at, origin, batch, note, seen, updated_at) VALUES " . implode( ',', $chunk ) .
				" ON DUPLICATE KEY UPDATE status = 'pending', visits = 0, attempts = 0, passes = 0, next_at = 0, note = VALUES(note), priority = LEAST(priority, VALUES(priority)), origin = IF(origin = 'target', origin, VALUES(origin)), batch = IF(VALUES(origin) = 'target', VALUES(batch), batch)"
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

	/**
	 * Translate chosen pages now: only they are visited (the rest of the queue waits) until each
	 * shows no untranslated rows, or has been tried several times. Then the queue goes back to
	 * the whole site if it was running, or pauses again.
	 *
	 * @param string[] $urls  Default-language addresses of this site.
	 * @param string[] $langs Languages (empty = all published).
	 * @return array{queued:int,bad:string[]}
	 */
	public static function start_target( array $urls, array $langs ) {
		if ( ! array_filter( array_map( 'trim', $urls ), 'strlen' ) ) {
			return array( 'queued' => 0, 'bad' => array() ); // plan() reads "no address" as the whole site.
		}
		$p = AITP_Pagemap::plan( $urls, $langs );
		if ( ! $p['items'] ) {
			return array( 'queued' => 0, 'bad' => $p['bad'] );
		}
		return array( 'queued' => self::start_pages( $p['items'] ), 'bad' => $p['bad'] );
	}

	/**
	 * Visit only these pages now (see start_target()); the rest of the queue waits.
	 *
	 * @param array $pages Each url, source_url, lang, object_type, object_id.
	 * @return int Pages queued (0: nothing started).
	 */
	public static function start_pages( array $pages ) {
		$n = self::requeue_urls( $pages, 'target', time() );
		if ( ! $n ) {
			return 0;
		}
		$s = self::state();
		self::update_state(
			array(
				'scope'        => 'target',
				'scope_resume' => $s['scope'] === 'target' ? (bool) $s['scope_resume'] : $s['status'] === 'running',
			)
		);
		self::start( false );
		return $n;
	}

	/**
	 * Queue entries of some translated addresses (to visit them again).
	 *
	 * @param array $urls Each url, lang.
	 * @return array Each url, source_url, lang, object_type, object_id.
	 */
	public static function entries_for( array $urls ) {
		global $wpdb;
		$out = array();
		foreach ( array_chunk( $urls, 100 ) as $chunk ) {
			$or = array();
			foreach ( $chunk as $u ) {
				if ( ! empty( $u['url'] ) && ! empty( $u['lang'] ) ) {
					$or[] = $wpdb->prepare( '(url = %s AND lang = %s)', (string) $u['url'], (string) $u['lang'] );
				}
			}
			if ( ! $or ) {
				continue;
			}
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			foreach ( (array) $wpdb->get_results( 'SELECT url, source_url, lang, object_type, object_id FROM ' . AITP_Schema::table( 'queue' ) . ' WHERE ' . implode( ' OR ', $or ), ARRAY_A ) as $r ) {
				$out[] = $r;
			}
		}
		return $out;
	}

	/**
	 * Chosen pages of the latest request(s).
	 *
	 * @param int $limit Rows.
	 * @return array
	 */
	public static function targets( $limit = 50 ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, url, lang, status, note, passes, translated, next_at, last_visit_at FROM ' . AITP_Schema::table( 'queue' ) . " WHERE origin = 'target' ORDER BY batch DESC, id LIMIT %d", (int) $limit ), ARRAY_A );
	}

	/**
	 * Forget the chosen pages: they become ordinary pages of the list (the next rebuild of the
	 * page list removes those that are not part of the site's selection).
	 */
	/**
	 * Chosen pages done or forgotten: back to the whole site if it was running, else paused.
	 *
	 * @param array $s Queue state.
	 */
	private static function leave_target( array $s ) {
		self::update_state( array( 'scope' => 'all', 'scope_resume' => false ) );
		if ( $s['scope_resume'] ) {
			self::update_state( array( 'status' => 'running' ) );
		} else {
			self::pause();
		}
	}

	/** @return int Chosen pages still waiting (shown while only they are worked on). */
	public static function targets_waiting() {
		return self::targets_left();
	}

	public static function clear_targets() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'UPDATE ' . AITP_Schema::table( 'queue' ) . " SET origin = 'site', priority = 1 WHERE origin = 'target'" );
		$s = self::state();
		if ( $s['scope'] === 'target' ) {
			self::leave_target( $s );
		}
	}

	/**
	 * Can the site open its own translated pages? One read-only signed visit of the home page in
	 * the first published language (nothing is translated or saved).
	 *
	 * @return array{ok:bool,code:string,http:int,location:string,url:string}
	 *         code: '' | no_language | no_translatepress | unreachable | http_401 | http_403 | http_503 | http_NNN | redirect | signature | no_report.
	 */
	public static function loopback_test() {
		$langs = self::languages();
		if ( ! class_exists( 'TRP_Translate_Press' ) ) {
			return array( 'ok' => false, 'code' => 'no_translatepress', 'http' => 0, 'location' => '', 'url' => '' );
		}
		if ( ! $langs ) {
			return array( 'ok' => false, 'code' => 'no_language', 'http' => 0, 'location' => '', 'url' => '' );
		}
		$url = self::translated_url( home_url( '/' ), $langs[0] );
		$v   = self::visit( $url, $langs[0], 10, true );
		$d   = AITP_Queue_Logic::decide( array(), $v, time() );
		$ok  = $v['report'] && $v['http'] >= 200 && $v['http'] < 300;
		$out = array( 'ok' => $ok, 'code' => $ok ? '' : $d['note'], 'http' => (int) $v['http'], 'location' => (string) $v['location'], 'url' => $url );
		set_transient( 'aitp_loopback', $out + array( 'at' => time() ), DAY_IN_SECONDS );
		return $out;
	}

	/**
	 * How long the rest of the queue will take at the current settings, and what limits it.
	 *
	 * @return array{left:int,hours:float,days:float,limit:string,avg:int} limit: rate | queue_cap | tp_limit | '' (unknown yet).
	 */
	public static function estimate() {
		global $wpdb;
		$t    = AITP_Schema::table( 'queue' );
		$set  = self::settings();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$left = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE status IN ('pending','partial')" );
		$avg  = (int) $wpdb->get_var( "SELECT AVG(chars) FROM $t WHERE status = 'done' AND chars > 0" );
		// phpcs:enable
		$out  = array( 'left' => $left, 'hours' => 0.0, 'days' => 0.0, 'limit' => '', 'avg' => $avg );
		if ( ! $left ) {
			return $out;
		}
		$by_rate = $left / max( 1, (int) $set['per_minute'] ) / 60; // Hours, one visit per page.
		$per_day = array();
		if ( (int) $set['queue_cap'] > 0 ) {
			$per_day['queue_cap'] = (int) $set['queue_cap'];
		}
		$tp = AITP_TP_Quota::limit();
		if ( $tp > 0 ) {
			$per_day['tp_limit'] = (int) floor( $tp * ( 1 - (int) $set['reserve'] / 100 ) );
		}
		$out['hours'] = $by_rate;
		$out['limit'] = 'rate';
		if ( $avg > 0 && $per_day ) {
			$cap  = min( $per_day );
			$days = $left * $avg / max( 1, $cap );
			if ( $days * 24 > $by_rate ) {
				$out['hours'] = $days * 24;
				$out['limit'] = (string) array_search( $cap, $per_day, true );
			}
		}
		$out['days'] = $out['hours'] / 24;
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
				$engine = ! AITP_Plugin::engine_selected() ? 'engine_not_selected' : ( ! AITP_Plugin::automatic_translation_on() ? 'automatic_off' : ( ! AITP_Settings::provider() ? 'not_configured' : '' ) );
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
				$item = self::next_item( $s['scope'] );
				if ( ! $item && $s['scope'] === 'target' && ! self::targets_left() ) {
					// The chosen pages are done: back to the whole site (or paused, as before).
					self::leave_target( $s );
					( new AITP_WP_Log() )->log( 'notice', 'target_done', array() );
					continue;
				}
				if ( ! $item ) {
					$result = 'idle';
					$wait   = self::seconds_to_next_due( $s['scope'] );
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

	/**
	 * @param string $scope all | target.
	 * @return array|null Next due item.
	 */
	private static function next_item( $scope = 'all' ) {
		global $wpdb;
		$t    = AITP_Schema::table( 'queue' );
		$only = $scope === 'target' ? " AND origin = 'target'" : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $t WHERE status IN ('pending','partial') AND next_at <= %d$only ORDER BY next_at = 0 DESC, priority ASC, id ASC LIMIT 1", time() ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * @param string $scope all | target.
	 * @return int Seconds until the next page is due (0 if none is scheduled).
	 */
	private static function seconds_to_next_due( $scope = 'all' ) {
		global $wpdb;
		$t    = AITP_Schema::table( 'queue' );
		$only = $scope === 'target' ? " AND origin = 'target'" : '';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$next = (int) $wpdb->get_var( "SELECT MIN(next_at) FROM $t WHERE status IN ('pending','partial')$only" );
		return $next ? max( 0, $next - time() ) : 0;
	}

	/** @return int Chosen pages still waiting for a visit (not set aside for later). */
	private static function targets_left() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . AITP_Schema::table( 'queue' ) . " WHERE origin = 'target' AND status = 'pending'" );
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
		if ( $visit['report'] && (int) $visit['calls'] === 0 && $visit['stop'] === '' && AITP_TP_Quota::limit() > 0 && self::tp_counter() >= AITP_TP_Quota::limit() ) {
			$visit['stop'] = 'tp_limit'; // TranslatePress stopped sending strings: its daily limit is used up.
		}
		$d = AITP_Queue_Logic::decide( $item, $visit, $now, self::revisit() );
		if ( $d['status'] === 'done' && isset( $item['origin'] ) && $item['origin'] === 'target' ) {
			// A chosen page counts as done only when it shows no untranslated rows any more.
			$left = self::untranslated_left( $item );
			if ( $left > 0 ) {
				$d['passes'] = (int) $item['passes'] + 1;
				$d['note']   = 'left';
				if ( $d['passes'] <= self::TARGET_PASSES ) {
					$d['status']  = 'pending';
					$d['next_at'] = $now + self::revisit() + 5;
				} else {
					// Text TranslatePress never sends (excluded, skipped) keeps the page "not done":
					// growing waits instead of a visit every hour for ever.
					$d['status']  = 'partial';
					$d['next_at'] = $now + AITP_Queue_Logic::partial_delay( $d['passes'] - self::TARGET_PASSES );
				}
			}
		}

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
				'locked'     => $visit['locked'],
				'location'   => $visit['location'],
				'ms'         => $visit['ms'],
			)
		);
	}

	/** @return int Seconds before a page with work left is visited again (TranslatePress lock + 5). */
	public static function revisit() {
		$lock = 50;
		if ( class_exists( 'TRP_Translate_Press' ) ) {
			$mt = TRP_Translate_Press::get_trp_instance()->get_component( 'machine_translator' );
			if ( $mt && method_exists( $mt, 'get_machine_translation_lock_timeout' ) ) {
				$lock = (int) $mt->get_machine_translation_lock_timeout();
			}
		}
		return max( 10, min( 600, $lock + 5 ) );
	}

	/**
	 * Untranslated page-text rows a page still shows (read-only visit).
	 *
	 * @param array $item Queue row.
	 * @return int
	 */
	private static function untranslated_left( array $item ) {
		$c = AITP_Pagemap::check( $item );
		return $c['ok'] ? (int) $c['s0'] : 0;
	}

	/**
	 * Signed request of a translated page.
	 *
	 * @param string $url     Translated URL.
	 * @param string $lang    Language.
	 * @param int    $seconds Time budget of the visit.
	 * @param bool   $map     Only map the page: record the page-text rows it shows, with machine
	 *                        translation and string saving switched off for that request.
	 * @return array{http:int,report:bool,calls:int,translated:int,failed:int,sent:int,stop:string,ms:int,rows:int[],strings:int,unknown:int,sig:string}
	 *         sig: why the page refused the signature (format, expired, signature, replay), or ''.
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
		$auth      = '';
		$forwarded = false;
		if ( defined( 'AITP_LOOPBACK_AUTH' ) && is_string( AITP_LOOPBACK_AUTH ) && AITP_LOOPBACK_AUTH !== '' ) {
			$auth = 'Basic ' . base64_encode( AITP_LOOPBACK_AUTH ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP Basic credentials, set by the site owner.
		} elseif ( isset( $_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'] ) ) {
			// As WordPress' own loopback check: a site behind a password asks the same password.
			$auth      = 'Basic ' . base64_encode( wp_unslash( $_SERVER['PHP_AUTH_USER'] ) . ':' . wp_unslash( $_SERVER['PHP_AUTH_PW'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			$forwarded = true;
		}
		$req   = array(
			'url'  => $url,
			'args' => array(
				'timeout'     => $seconds + 80, // The page translates for up to $seconds, then saves and renders.
				'redirection' => 0,
				'sslverify'   => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook for loopbacks.
				'user-agent'  => 'AITP-Queue/' . AITP_VERSION,
				'headers'     => array(
					self::HEADER    => AITP_Queue_Logic::sign( self::secret(), $path, time(), $nonce ),
					'Cache-Control' => 'no-cache',
					'Pragma'        => 'no-cache',
				),
			),
		);
		if ( $auth !== '' ) {
			$req['args']['headers']['Authorization'] = $auth;
		}
		/**
		 * Adjust the internal page request, e.g. when the site cannot reach its own public
		 * address from the server (containers, proxies). The signed path must not change.
		 *
		 * @param array  $req  ['url' => string, 'args' => array].
		 * @param string $lang Language.
		 */
		$req = apply_filters( 'aitp_queue_request', $req, $lang );
		if ( $forwarded && strtolower( (string) wp_parse_url( $req['url'], PHP_URL_HOST ) ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			// The password this request came with is only passed on to this site's own address.
			unset( $req['args']['headers']['Authorization'] );
		}

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
			'locked'     => 0,
			'sig'        => '',
			'location'   => '',
		);
		if ( $out['http'] >= 300 && $out['http'] < 400 ) {
			$out['location'] = substr( (string) wp_remote_retrieve_header( $resp, 'location' ), 0, 300 );
		}
		// The page says when it did not accept the signature (format, expired, signature, replay).
		$said = is_wp_error( $resp ) ? '' : (string) wp_remote_retrieve_header( $resp, self::HEADER );
		if ( preg_match( '~^invalid:([a-z]{1,20})$~', $said, $m ) ) {
			$out['sig'] = $m[1];
			return $out;
		}
		// The page writes its report while shutting down; give it a moment if needed.
		$key = 'aitp_warm_' . $nonce;
		for ( $i = 0; $i < 6; $i++ ) {
			wp_cache_delete( $key, 'options' );
			wp_cache_delete( '_transient_' . $key, 'options' );
			wp_cache_delete( 'notoptions', 'options' ); // Else a first miss is remembered for the request.
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
		return AITP_Lock::acquire( self::LOCK, $ttl );
	}

	/** Release the mutex. */
	private static function unlock() {
		AITP_Lock::release( self::LOCK );
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
		$check = AITP_Queue_Logic::check( self::secret(), $hdr, $uri, time() );
		$nonce = $check['nonce'];
		if ( $nonce !== '' && get_transient( 'aitp_used_' . $nonce ) !== false ) {
			$check['reason'] = 'replay';
		}
		if ( $check['reason'] !== '' ) {
			// Marked as an internal visit but not verified (bad format, expired, wrong signature,
			// replayed). Never an ordinary page view: no machine translation by any engine, no
			// string saving, no page cache, and the reason goes back to the sender.
			add_filter( 'trp_machine_translator_is_available', '__return_false', PHP_INT_MAX );
			add_filter( 'trp_allow_string_saving', '__return_false', PHP_INT_MAX );
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- convention honoured by page caches.
			}
			$reason = $check['reason'];
			add_action(
				'send_headers',
				function () use ( $reason ) {
					nocache_headers();
					header( self::HEADER . ': invalid:' . $reason );
				}
			);
			return;
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
			'locked'     => 0,
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
		add_action(
			'send_headers',
			function () {
				nocache_headers();
				header( self::HEADER . ': ok' );
			}
		);
		add_action( 'aitp_translated_batch', array( $this, 'collect' ), 10, 2 );
		add_action( 'aitp_engine_unavailable', array( $this, 'unavailable' ) );
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

	/** @param string $reason Why the engine could not translate. */
	public function unavailable( $reason ) {
		$this->report['calls']++;
		if ( $this->report['stop'] === '' ) {
			$this->report['stop'] = (string) $reason;
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
			// Strings TranslatePress did not send because another request (or a call of the last
			// 50 s) holds them: the page is not finished even if the engine was never called.
			if ( class_exists( 'TRP_Translate_Press' ) ) {
				$mt = TRP_Translate_Press::get_trp_instance()->get_component( 'machine_translator' );
				if ( $mt && method_exists( $mt, 'get_lock_skipped_strings' ) ) {
					$this->report['locked'] = count( (array) $mt->get_lock_skipped_strings() );
				}
			}
			set_transient( 'aitp_warm_' . $this->warm, $this->report, 600 );
		}
	}
}
