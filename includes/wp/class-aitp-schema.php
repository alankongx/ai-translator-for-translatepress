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

	const VERSION = '1';

	/**
	 * @param string $name Short name: cache, failures, review, log.
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
		update_option( 'aitp_db_version', self::VERSION, false );
	}

	/** Remove everything (uninstall). */
	public static function uninstall() {
		global $wpdb;
		foreach ( array( 'cache', 'failures', 'review', 'log' ) as $t ) {
			$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table( $t ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		}
		foreach ( array( 'aitp_settings', 'aitp_db_version', 'aitp_breaker', 'aitp_caps', 'aitp_notice' ) as $o ) {
			delete_option( $o );
		}
		delete_site_transient( 'aitp_github_release' );
	}
}
