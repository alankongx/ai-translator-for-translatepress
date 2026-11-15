<?php
/**
 * Database tables. The plugin never alters TranslatePress tables.
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Schema {

	const VERSION = '7';

	/**
	 * @param string $name Short name: cache, failures, review, log, queue, audit, repair.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'aitp_' . $name;
	}

	/** Create or update tables when the schema version changed. */
	public static function maybe_upgrade() {
		$from = (string) get_option( 'aitp_db_version' );
		if ( $from !== self::VERSION ) {
			self::install();
			if ( $from !== '' && version_compare( $from, '7', '<' ) ) {
				self::upgrade_to_7();
			}
			// Written last: a request that dies half way runs the (repeatable) steps again.
			update_option( 'aitp_db_version', self::VERSION, false );
		}
	}

	/**
	 * 0.7.0: the page-mapping plan and the cache-purge list move from options (rewritten whole
	 * on every step) to rows; repair pages keep their origin in a column of their own; the
	 * time a visitor may wait for translations drops from 25 to 10 seconds unless it was changed.
	 */
	private static function upgrade_to_7() {
		global $wpdb;
		$map = get_option( 'aitp_map_state' );
		if ( is_array( $map ) && isset( $map['items'] ) && is_array( $map['items'] ) ) {
			$left = array_slice( $map['items'], (int) ( isset( $map['i'] ) ? $map['i'] : 0 ) );
			if ( isset( $map['status'] ) && $map['status'] === 'running' ) {
				AITP_Tasks::add( 'map', $left, array( __CLASS__, 'map_key' ) );
			}
			$map['total'] = count( $map['items'] );
			unset( $map['items'] );
			update_option( 'aitp_map_state', $map, false );
		}
		// The write lock was a serialized array before: rewritten in the new format with its own
		// expiry, so a repair running during the update keeps it (instead of reading as expired).
		$wl = get_option( 'aitp_write_lock' );
		if ( is_array( $wl ) && isset( $wl['until'] ) ) {
			update_option( 'aitp_write_lock', (int) $wl['until'] . '|legacy|' . preg_replace( '~[^a-z0-9_-]~i', '', isset( $wl['who'] ) ? (string) $wl['who'] : '' ) . '|' . ( isset( $wl['since'] ) ? (int) $wl['since'] : 0 ), false );
		}
		$purge = get_option( 'aitp_purge_list' );
		if ( is_array( $purge ) ) {
			if ( ! empty( $purge['urls'] ) && is_array( $purge['urls'] ) ) {
				AITP_Tasks::add( 'purge', array_values( $purge['urls'] ) );
			}
			unset( $purge['urls'] );
			update_option( 'aitp_purge_list', $purge, false );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( 'UPDATE ' . self::table( 'queue' ) . " SET origin = 'repair' WHERE note = 'repair'" );
		$s = get_option( 'aitp_settings' );
		if ( is_array( $s ) && isset( $s['time_budget'] ) && (int) $s['time_budget'] === 25 ) {
			$s['time_budget'] = 10;
			update_option( 'aitp_settings', $s );
		}
	}

	/**
	 * @param array $item Page-mapping item.
	 * @return string
	 */
	public static function map_key( $item ) {
		return $item['lang'] . '|' . $item['url'];
	}

	/** Create / update all tables. */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();

		dbDelta(
			'CREATE TABLE ' . self::table( 'cache' ) . " (
  ckey char(64) NOT NULL,
  target_locale varchar(20) NOT NULL DEFAULT '',
  translation longtext NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (ckey),
  KEY created_at (created_at)
) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'failures' ) . " (
  fkey char(64) NOT NULL,
  source_locale varchar(20) NOT NULL DEFAULT '',
  target_locale varchar(20) NOT NULL DEFAULT '',
  reason varchar(64) NOT NULL DEFAULT '',
  excerpt text NOT NULL,
  attempts int unsigned NOT NULL DEFAULT 0,
  next_retry_at bigint unsigned NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (fkey),
  KEY target_reason (target_locale,reason),
  KEY updated_at (updated_at)
) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'review' ) . " (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  source_locale varchar(20) NOT NULL DEFAULT '',
  target_locale varchar(20) NOT NULL DEFAULT '',
  original longtext NOT NULL,
  original_hash char(40) NOT NULL,
  translation longtext NOT NULL,
  translation_hash char(40) NOT NULL,
  flags varchar(255) NOT NULL DEFAULT '',
  saved tinyint(1) NOT NULL DEFAULT 1,
  status varchar(20) NOT NULL DEFAULT 'open',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY item (original_hash,target_locale,translation_hash),
  KEY status (status,created_at)
) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'log' ) . " (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime NOT NULL,
  level varchar(10) NOT NULL DEFAULT '',
  event varchar(20) NOT NULL DEFAULT '',
  target_locale varchar(20) NOT NULL DEFAULT '',
  data longtext NOT NULL,
  PRIMARY KEY  (id),
  KEY created_at (created_at),
  KEY event (event,created_at)
) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'queue' ) . " (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  ukey char(40) NOT NULL,
  source_url text NOT NULL,
  url text NOT NULL,
  lang varchar(20) NOT NULL DEFAULT '',
  object_type varchar(10) NOT NULL DEFAULT '',
  object_id bigint unsigned NOT NULL DEFAULT 0,
  priority tinyint unsigned NOT NULL DEFAULT 5,
  status varchar(10) NOT NULL DEFAULT 'pending',
  next_at bigint unsigned NOT NULL DEFAULT 0,
  visits smallint unsigned NOT NULL DEFAULT 0,
  attempts smallint unsigned NOT NULL DEFAULT 0,
  passes smallint unsigned NOT NULL DEFAULT 0,
  note varchar(64) NOT NULL DEFAULT '',
  translated int unsigned NOT NULL DEFAULT 0,
  chars bigint unsigned NOT NULL DEFAULT 0,
  last_visit_at bigint unsigned NOT NULL DEFAULT 0,
  seen bigint unsigned NOT NULL DEFAULT 0,
  origin varchar(10) NOT NULL DEFAULT 'site',
  batch bigint unsigned NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY ukey (ukey),
  KEY due (status,next_at),
  KEY object (object_type,object_id),
  KEY origin (origin,status)
) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'audit' ) . " (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(12) NOT NULL DEFAULT '',
  lang varchar(20) NOT NULL DEFAULT '',
  row_id bigint unsigned NOT NULL DEFAULT 0,
  original_hash char(40) NOT NULL DEFAULT '',
  translated_hash char(40) NOT NULL DEFAULT '',
  severity varchar(10) NOT NULL DEFAULT '',
  codes varchar(255) NOT NULL DEFAULT '',
  details text NOT NULL,
  source varchar(10) NOT NULL DEFAULT 'scan',
  scan bigint unsigned NOT NULL DEFAULT 0,
  ai varchar(10) NOT NULL DEFAULT '',
  state varchar(10) NOT NULL DEFAULT 'open',
  batch bigint unsigned NOT NULL DEFAULT 0,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY item (kind,lang,row_id),
  KEY state (state,severity),
  KEY lang (lang)
) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'repair' ) . " (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  batch bigint unsigned NOT NULL DEFAULT 0,
  kind varchar(12) NOT NULL DEFAULT '',
  lang varchar(20) NOT NULL DEFAULT '',
  row_id bigint unsigned NOT NULL DEFAULT 0,
  finding_id bigint unsigned NOT NULL DEFAULT 0,
  original longtext NOT NULL,
  translated longtext,
  status int NOT NULL DEFAULT 0,
  original_id bigint NULL DEFAULT NULL,
  exact tinyint NOT NULL DEFAULT 0,
  restored varchar(20) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY batch (batch),
  KEY lang_row (lang,row_id)
) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'protect' ) . " (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  kind varchar(12) NOT NULL DEFAULT '',
  lang varchar(20) NOT NULL DEFAULT '',
  row_id bigint unsigned NOT NULL DEFAULT 0,
  original_hash char(40) NOT NULL DEFAULT '',
  translated_hash char(40) NOT NULL DEFAULT '',
  label varchar(64) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY row_text (kind,lang,row_id,translated_hash),
  KEY label (label)
) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'pagemap' ) . " (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  ukey char(40) NOT NULL,
  url text NOT NULL,
  source_url text NOT NULL,
  lang varchar(20) NOT NULL DEFAULT '',
  object_type varchar(10) NOT NULL DEFAULT '',
  object_id bigint unsigned NOT NULL DEFAULT 0,
  http int NOT NULL DEFAULT 0,
  strings int NOT NULL DEFAULT 0,
  found int NOT NULL DEFAULT 0,
  unknown int NOT NULL DEFAULT 0,
  note varchar(40) NOT NULL DEFAULT '',
  mapped_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY ukey (ukey),
  KEY lang (lang)
) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'pagestr' ) . " (
  page_id bigint unsigned NOT NULL,
  lang varchar(20) NOT NULL DEFAULT '',
  row_id bigint unsigned NOT NULL,
  PRIMARY KEY  (page_id,row_id),
  KEY lang_row (lang,row_id)
) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'journal' ) . " (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  session bigint unsigned NOT NULL DEFAULT 0,
  kind varchar(12) NOT NULL DEFAULT '',
  lang varchar(20) NOT NULL DEFAULT '',
  row_id bigint unsigned NOT NULL DEFAULT 0,
  original longtext NOT NULL,
  prev_translated longtext NULL,
  prev_status tinyint NOT NULL DEFAULT 0,
  prev_original_id bigint NULL DEFAULT NULL,
  translated longtext NOT NULL,
  exact tinyint NOT NULL DEFAULT 0,
  undone varchar(20) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY session (session),
  KEY lang_row (lang,row_id)
) $c;"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'tasks' ) . " (
  id bigint unsigned NOT NULL AUTO_INCREMENT,
  job varchar(10) NOT NULL DEFAULT '',
  ukey char(40) NOT NULL,
  data text NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY job_ukey (job,ukey)
) $c;"
		);
	}

	/** Remove everything (uninstall). */
	public static function uninstall() {
		global $wpdb;
		foreach ( array( 'cache', 'failures', 'review', 'log', 'queue', 'audit', 'repair', 'protect', 'pagemap', 'pagestr', 'journal', 'tasks' ) as $t ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		foreach ( array( 'aitp_settings', 'aitp_db_version', 'aitp_breaker', 'aitp_caps', 'aitp_notice', 'aitp_queue_settings', 'aitp_queue_state', 'aitp_queue_lock', 'aitp_audit_state', 'aitp_audit_lock', 'aitp_audit_settings', 'aitp_repair_batches', 'aitp_autofix_settings', 'aitp_autofix_state', 'aitp_autofix_lock', 'aitp_map_state', 'aitp_map_lock', 'aitp_journal_state', 'aitp_purge_list', 'aitp_audit_actions', 'aitp_write_lock', 'aitp_usage', 'aitp_queue_sync', 'aitp_queue_sync_lock' ) as $o ) {
			delete_option( $o );
		}
		// Short-lived notices and markers (flash messages, test results, used visit tokens).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_aitp\\_%' OR option_name LIKE '\\_transient\\_timeout\\_aitp\\_%'" );
		delete_site_transient( 'aitp_github_release' );
	}
}
