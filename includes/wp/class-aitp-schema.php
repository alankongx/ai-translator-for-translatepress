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

	const VERSION = '6';

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
		if ( get_option( 'aitp_db_version' ) !== self::VERSION ) {
			self::install();
		}
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
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY ukey (ukey),
  KEY due (status,next_at),
  KEY object (object_type,object_id)
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
		update_option( 'aitp_db_version', self::VERSION, false );
	}

	/** Remove everything (uninstall). */
	public static function uninstall() {
		global $wpdb;
		foreach ( array( 'cache', 'failures', 'review', 'log', 'queue', 'audit', 'repair', 'protect', 'pagemap', 'pagestr', 'journal' ) as $t ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		foreach ( array( 'aitp_settings', 'aitp_db_version', 'aitp_breaker', 'aitp_caps', 'aitp_notice', 'aitp_queue_settings', 'aitp_queue_state', 'aitp_queue_lock', 'aitp_audit_state', 'aitp_audit_lock', 'aitp_audit_settings', 'aitp_repair_batches', 'aitp_autofix_settings', 'aitp_autofix_state', 'aitp_autofix_lock', 'aitp_map_state', 'aitp_map_lock', 'aitp_journal_state', 'aitp_purge_list', 'aitp_audit_actions', 'aitp_write_lock' ) as $o ) {
			delete_option( $o );
		}
		// Short-lived notices and markers (flash messages, test results, used visit tokens).
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_aitp\\_%' OR option_name LIKE '\\_transient\\_timeout\\_aitp\\_%'" );
		delete_site_transient( 'aitp_github_release' );
	}
}
