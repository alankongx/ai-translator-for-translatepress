<?php
/**
 * Locks across requests (WP-Cron, admin pages, WP-CLI) that two processes cannot both take.
 *
 * add_option() is not atomic: it looks the name up first (the shared "notoptions" cache under
 * Redis or Memcached may say it does not exist) and then writes with ON DUPLICATE KEY UPDATE,
 * so two requests can both "get" the lock. Here the row is written with INSERT IGNORE and the
 * lock is ours only when the database says one row was inserted; a stale lock is taken over
 * with an UPDATE that only matches while it is still stale. Both bypass every cache.
 *
 * The row (in the options table, not autoloaded) holds "until|token|who|since". A plain
 * number, as older versions wrote, is read as "until".
 *
 * @package AI_Translator_For_TranslatePress
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AITP_Lock {

	/** @var array<string,string> Values of the locks this request holds, by name. */
	private static $held = array();

	/**
	 * Take a lock.
	 *
	 * @param string $name Option name.
	 * @param int    $ttl  Seconds before a lock left by a request that died may be taken over.
	 * @param string $who  What holds it (shown to the user).
	 * @return bool
	 */
	public static function acquire( $name, $ttl, $who = '' ) {
		global $wpdb;
		$now   = time();
		$value = ( $now + max( 1, (int) $ttl ) ) . '|' . bin2hex( random_bytes( 8 ) ) . '|' . preg_replace( '~[^a-z0-9_-]~i', '', (string) $who ) . '|' . $now;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, $value ) );
		$ok = (int) $wpdb->rows_affected === 1;
		if ( ! $ok ) {
			// Stale: the first field (until) is in the past. Only one UPDATE can match it.
			$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) < %d", $value, $name, $now ) );
			$ok = (int) $wpdb->rows_affected === 1;
		}
		// phpcs:enable
		self::forget_cache( $name );
		if ( $ok ) {
			self::$held[ $name ] = $value;
		}
		return $ok;
	}

	/**
	 * Release a lock this request holds (a lock taken over by another request is left alone).
	 *
	 * @param string $name Option name.
	 */
	public static function release( $name ) {
		global $wpdb;
		if ( ! isset( self::$held[ $name ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s", $name, self::$held[ $name ] ) );
		unset( self::$held[ $name ] );
		self::forget_cache( $name );
	}

	/**
	 * @param string $name Option name.
	 * @return bool Whether this request holds the lock.
	 */
	public static function holds( $name ) {
		return isset( self::$held[ $name ] );
	}

	/**
	 * The current, unexpired holder.
	 *
	 * @param string $name Option name.
	 * @return array{who:string,since:int,until:int} who '' and until 0 when free.
	 */
	public static function holder( $name ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$v = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) );
		$p = self::parse( $v );
		if ( $p['until'] < time() ) {
			return array( 'who' => '', 'since' => 0, 'until' => 0 );
		}
		return $p;
	}

	/**
	 * @param mixed $v Stored value.
	 * @return array{who:string,since:int,until:int}
	 */
	public static function parse( $v ) {
		$parts = explode( '|', is_string( $v ) ? $v : '' );
		return array(
			'until' => (int) $parts[0],
			'who'   => isset( $parts[2] ) ? (string) $parts[2] : '',
			'since' => isset( $parts[3] ) ? (int) $parts[3] : 0,
		);
	}

	/** @param string $name Option name. */
	private static function forget_cache( $name ) {
		wp_cache_delete( $name, 'options' );
	}
}
